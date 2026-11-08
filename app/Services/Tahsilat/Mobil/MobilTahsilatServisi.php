<?php

namespace App\Services\Tahsilat\Mobil;

use App\Domain\Tahsilat\AdisyonHesap;
use App\Domain\Tahsilat\MobilTahsilatHesabi as Hesap;
use App\Models\AdisyonHizmetler;
use App\Models\Adisyonlar;
use App\Models\AdisyonPaketler;
use App\Models\AdisyonUrunler;
use App\Models\TahsilatHizmetler;
use App\Models\TahsilatPaketler;
use App\Models\TahsilatUrunler;
use App\Services\Tahsilat\TahsilatYazServisi;
use App\Support\AktiviteLog;
use App\Support\Idempotency;
use Illuminate\Support\Facades\Log;

/**
 * Eski mobil tahsilatekle — ayni istek ve cevap. Odeme, harici indirim ve vade dagitimi web ile AYNI KOD
 * (TahsilatYazServisi::vadeDagitimi, kalemDagitimi, planiYaz; kullanici karari 2026-11-08): kalem paylari kalan borca oranli,
 * kalanla sinirli ve kurus-kesin; vade odemesinde komisyon dusulur, her vadeye en cok kendi tutari duser, kismi odemede vade
 * bolunur. Eskiden farkli olarak tek transaction ve idempotency: yarida kalan kayit ve cift tiklamada ikinci tahsilat olusmaz.
 */
class MobilTahsilatServisi
{
    /** kalem turu → [model, tahsilat modeli, yabanci anahtar] */
    private const TURLER = [
        'hizmet' => [AdisyonHizmetler::class, TahsilatHizmetler::class, 'adisyon_hizmet_id'],
        'urun'   => [AdisyonUrunler::class, TahsilatUrunler::class, 'adisyon_urun_id'],
        'paket'  => [AdisyonPaketler::class, TahsilatPaketler::class, 'adisyon_paket_id'],
    ];

    public function __construct(private MobilKapsam $kapsam, private MobilTahsilatYazici $yazici, private TahsilatYazServisi $tahsilat) {}

    public function ekle(int $salonId, int $yetkiliId, array $g): array
    {
        $idler = [];
        foreach (self::TURLER as $tur => [$_, $__, $fk]) {
            $idler[$tur] = (array) ($g[$fk] ?? []);
            $this->kapsam->kalemlerOlmali($salonId, $tur, $idler[$tur]);
        }
        $taksitVadeleri = (array) ($g['taksit_vade_id'] ?? []);
        $senetVadeleri = (array) ($g['senet_vade_id'] ?? []);
        $this->kapsam->taksitVadeleriOlmali($salonId, $taksitVadeleri);
        $this->kapsam->senetVadeleriOlmali($salonId, $senetVadeleri);
        $this->kapsam->adisyonOlmali($salonId, $g['adisyon_id'] ?? null);
        if (! empty($g['ad_soyad'])) {
            $this->kapsam->musteriOlmali($salonId, $g['ad_soyad']);
        }

        $sonuc = Idempotency::calistir('mobil_tahsilat', (string) ($g['idempotency_key'] ?? ''), $yetkiliId, $salonId,
            fn () => $this->yaz($salonId, $yetkiliId, $g, $idler, $taksitVadeleri, $senetVadeleri));

        if (! $sonuc['replay']) {
            AktiviteLog::yaz($salonId, $yetkiliId, 'tahsilat_ekle', 'tahsilat', $sonuc['tahsilat_id'], 'Tutar: '.($g['indirimli_toplam_tahsilat_tutari'] ?? ''), 'Tahsilat alındı', ['adisyon_id' => $sonuc['adisyon_id']]);
        }

        return $sonuc;
    }

    private function yaz(int $salonId, int $yetkiliId, array $g, array $idler, array $taksitVadeleri, array $senetVadeleri): array
    {
        [$odeme, $komisyon] = Hesap::odemeAyir($g['indirimli_toplam_tahsilat_tutari'] ?? '0', $g['komisyon_tutari'] ?? '0');

        // Istemci adisyon_id gondermezse kalemden turetilir; yoksa adisyon silinince kasada kalinti kalir.
        $adisyonId = $g['adisyon_id'] ?? null;
        foreach (self::TURLER as $tur => [$model]) {
            if (empty($adisyonId) && $idler[$tur]) {
                $adisyonId = $model::whereIn('id', $idler[$tur])->value('adisyon_id');
            }
        }

        $satir = [
            'salon_id' => $salonId, 'yetkili_id' => $yetkiliId, 'adisyon_id' => $adisyonId, 'musteri_id' => $g['ad_soyad'] ?? null,
            'tutar' => $odeme, 'komisyon' => $komisyon, 'odeme_tarihi' => $g['tahsilat_tarihi'] ?? null,
            'odeme_yontemi' => $g['odeme_yontemi'] ?? null, 'notlar' => $g['tahsilat_notlari'] ?? null,
        ];
        $tahsilat = $this->yazici->tahsilatYaz($satir);
        $this->yazici->komisyonYaz($tahsilat, $satir);

        $kalemler = $this->plansiz($idler);
        $this->musteriIndirimiYaz($g, $kalemler, (int) $tahsilat->id);
        self::satisTarihi($g, $idler);

        $this->tahsilat->planiYaz($this->plan($g, $kalemler, $taksitVadeleri, $senetVadeleri, AdisyonHesap::tlToKurus($odeme), (int) $adisyonId), (int) $tahsilat->id);

        return ['sonuc' => 'Başarılı', 'tahsilat_id' => (int) $tahsilat->id, 'adisyon_id' => $adisyonId ? (int) $adisyonId : null];
    }

    /**
     * Web ile ayni kural ve ayni kod: once secili taksit/senet vadeleri (TahsilatYazServisi::vadeDagitimi), vadelere dusmeyen
     * kalan secili plansiz kalemlere, harici indirimle birlikte (TahsilatYazServisi::kalemDagitimi). Web formu ikisini ayni
     * istekte gondermez; mobil ekran ikisini birlikte secebildigi icin sira vade once.
     *
     * @param  array{hizmet:\Illuminate\Support\Collection, urun:\Illuminate\Support\Collection, paket:\Illuminate\Support\Collection}  $kalemler
     */
    private function plan(array $g, array $kalemler, array $taksitVadeleri, array $senetVadeleri, int $odemeKurus, int $adisyonId): array
    {
        $plan = ['kalem_tahsilatlari' => [], 'vade_guncellemeleri' => [], 'indirim_uygulamalari' => [], 'kupon_dusulecek_ids' => []];
        $kalan = $odemeKurus;

        if ($taksitVadeleri || $senetVadeleri) {
            $v = $this->tahsilat->vadeDagitimi(array_map('intval', $taksitVadeleri), array_map('intval', $senetVadeleri), $odemeKurus,
                ($g['odeme_yontemi'] ?? null) !== null && $g['odeme_yontemi'] !== '' ? (int) $g['odeme_yontemi'] : null);
            $plan['kalem_tahsilatlari'] = $v['kalem_tahsilatlari'];
            $plan['vade_guncellemeleri'] = $v['vade_guncellemeleri'];
            // Plan kalemlerine gercekten yazilan dusulur: plan kalani vade tutarindan azsa artan tutar plansiz kalemlere kalir
            // (paid_tutar dusulseydi aradaki fark hicbir kaleme yazilmazdi).
            $kalan -= (int) $v['ozet']['dagitilan_kurus'];
        }

        if ($kalemler['hizmet']->isNotEmpty() || $kalemler['urun']->isNotEmpty() || $kalemler['paket']->isNotEmpty()) {
            $k = $this->tahsilat->kalemDagitimi($kalemler['hizmet'], $kalemler['urun'], $kalemler['paket'], max(0, $kalan),
                Hesap::paraKurus($g['indirim_tutari'] ?? '0'), $adisyonId);
            $plan['kalem_tahsilatlari'] = [...$plan['kalem_tahsilatlari'], ...$k['kalem_tahsilatlari']];
            $plan['indirim_uygulamalari'] = $k['indirim_uygulamalari'];
            $plan['kupon_dusulecek_ids'] = $k['kupon_dusulecek_ids'];
        }

        return $plan;
    }

    /**
     * Secili kalemlerin planli (taksit/senet) olmayanlari: odeme, musteri ve harici indirim yalniz bunlara yazilir.
     *
     * @return array{hizmet:\Illuminate\Support\Collection, urun:\Illuminate\Support\Collection, paket:\Illuminate\Support\Collection}
     */
    private function plansiz(array $idler): array
    {
        $liste = [];
        foreach (self::TURLER as $tur => [$model]) {
            $liste[$tur] = $idler[$tur] ? $model::whereIn('id', $idler[$tur])->whereNull('senet_id')->whereNull('taksitli_tahsilat_id')->get() : collect();
        }

        return $liste;
    }

    /** Musteri indirimi (harici disi) indirimi bos plansiz kalemlere yazilir; kalemler kalan hesabi icin ayni nesnelerde guncellenir. */
    private function musteriIndirimiYaz(array $g, array $kalemler, int $tahsilatId): void
    {
        $indirim = Hesap::musteriIndirimKurus($g['musteri_indirimi'] ?? '0', $g['indirim_tutari'] ?? '0');
        if ($indirim <= 0) {
            return;
        }
        try {
            $sira = [];
            $fiyat = [];
            $uygun = [];
            $kapasite = [];
            foreach (self::TURLER as $tur => [, $odemeModeli, $fk]) {
                foreach ($kalemler[$tur] as $k) {
                    $sira[] = $k;
                    $fiyat[] = AdisyonHesap::tlToKurus($k->fiyat ?? 0);
                    $uygun[] = (float) ($k->indirim_tutari ?? 0) <= 0; // cift uygulama olmasin
                    $kapasite[] = AdisyonHesap::tlToKurus($k->fiyat ?? 0) - AdisyonHesap::tlToKurus($odemeModeli::where($fk, $k->id)->sum('tutar'));
                }
            }
            foreach (Hesap::musteriIndirimPaylari($fiyat, $uygun, $kapasite, $indirim) as $i => $payKurus) {
                $sira[$i]->indirim_tutari = AdisyonHesap::kurusToTl($payKurus);
                $sira[$i]->save();
            }
        } catch (\Throwable $e) {
            Log::error('musteri indirimi kaleme yazilamadi (mobil)', ['err' => $e->getMessage(), 'tahsilat_id' => $tahsilatId]);
        }
    }

    /** Yeni satis ekranindan gelen satis_tarihi ilk kalemin adisyonuna yazilir (hizmet, urun, paket sirasiyla). Taksit de kullanir. */
    public static function satisTarihi(array $g, array $idler): void
    {
        if (empty($g['satis_tarihi'])) {
            return;
        }
        foreach (self::TURLER as $tur => [$model]) {
            if ($idler[$tur]) {
                $adisyonId = $model::where('id', reset($idler[$tur]))->value('adisyon_id');
                if ($adisyonId) {
                    Adisyonlar::where('id', $adisyonId)->update(['tarih' => $g['satis_tarihi']]);
                }

                return;
            }
        }
    }
}
