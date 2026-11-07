<?php

namespace App\Services\Tahsilat\Mobil;

use App\Domain\Tahsilat\MobilTahsilatHesabi as Hesap;
use App\Models\AdisyonHizmetler;
use App\Models\Adisyonlar;
use App\Models\AdisyonPaketler;
use App\Models\AdisyonUrunler;
use App\Models\SenetVadeleri;
use App\Models\TahsilatHizmetler;
use App\Models\TahsilatPaketler;
use App\Models\TahsilatUrunler;
use App\Models\TaksitVadeleri;
use App\Support\AktiviteLog;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Eski mobil tahsilatekle — ayni satirlar ve tutarlar. Hesap MobilTahsilatHesabi'nda. Eskiden farkli olarak tek
 * transaction ve idempotency: yarida kalan kayit ve cift tiklamada ikinci tahsilat olusmaz. Web TahsilatYazServisi'ne
 * dokunulmaz, ikisi bilincli olarak farkli dagitir.
 */
class MobilTahsilatServisi
{
    /** kalem turu → [model, tahsilat modeli, yabanci anahtar] */
    private const TURLER = [
        'hizmet' => [AdisyonHizmetler::class, TahsilatHizmetler::class, 'adisyon_hizmet_id'],
        'urun'   => [AdisyonUrunler::class, TahsilatUrunler::class, 'adisyon_urun_id'],
        'paket'  => [AdisyonPaketler::class, TahsilatPaketler::class, 'adisyon_paket_id'],
    ];

    public function __construct(private MobilKapsam $kapsam, private MobilTahsilatYazici $yazici) {}

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

        $this->musteriIndirimiYaz($g, $idler, (int) $tahsilat->id);
        $this->hariciIndirimYaz($g, $idler, $adisyonId, (int) $tahsilat->id);
        self::satisTarihi($g, $idler);

        $toplamAdisyon = 0;
        foreach (self::TURLER as $tur => [$model]) {
            $toplamAdisyon += $model::whereIn('id', $idler[$tur])->sum('fiyat');
        }
        foreach (self::TURLER as $tur => [$model, $odemeModeli, $fk]) {
            foreach ($idler[$tur] as $kalemId) {
                $kalem = $model::find($kalemId);
                $o = new $odemeModeli();
                $o->{$fk} = $kalemId;
                $o->tahsilat_id = $tahsilat->id;
                $o->tutar = Hesap::kalemPayi($kalem->fiyat, $toplamAdisyon, $odeme);
                $o->save();
            }
        }

        $this->vadeleriOde(TaksitVadeleri::class, 'taksit_vadeleri', 'taksitli_tahsilat_id', $taksitVadeleri, $g, (int) $tahsilat->id);
        $this->vadeleriOde(SenetVadeleri::class, 'senet_vadeleri', 'senet_id', $senetVadeleri, $g, (int) $tahsilat->id);

        return ['sonuc' => 'Başarılı', 'tahsilat_id' => (int) $tahsilat->id, 'adisyon_id' => $adisyonId ? (int) $adisyonId : null];
    }

    /** Kalemin planli (taksit/senet) olmayan hali: musteri ve harici indirim yalniz bunlara yazilir. */
    private function plansiz(array $idler): array
    {
        $liste = [];
        foreach (self::TURLER as $tur => [$model, $odemeModeli, $fk]) {
            $liste[] = [$model::whereIn('id', $idler[$tur])->whereNull('senet_id')->whereNull('taksitli_tahsilat_id')->get(), $odemeModeli, $fk];
        }

        return $liste;
    }

    private function musteriIndirimiYaz(array $g, array $idler, int $tahsilatId): void
    {
        $indirim = Hesap::musteriIndirimi($g['musteri_indirimi'] ?? '0', $g['indirim_tutari'] ?? '0');
        if ($indirim <= 0) {
            return;
        }
        try {
            $liste = $this->plansiz($idler);
            $toplam = (float) array_sum(array_map(fn ($l) => $l[0]->sum('fiyat'), $liste));
            if ($toplam <= 0) {
                return;
            }
            foreach ($liste as [$kalemler, $odemeModeli, $fk]) {
                foreach ($kalemler as $k) {
                    if ((float) ($k->indirim_tutari ?? 0) > 0) {
                        continue; // cift uygulama olmasin
                    }
                    $onceki = (float) $odemeModeli::where($fk, $k->id)->sum('tutar');
                    $pay = Hesap::musteriIndirimPayi((float) $k->fiyat, $toplam, $indirim, $onceki);
                    if ($pay <= 0) {
                        continue;
                    }
                    $k->indirim_tutari = $pay;
                    $k->save();
                }
            }
        } catch (\Throwable $e) {
            Log::error('musteri indirimi kaleme yazilamadi (mobil)', ['err' => $e->getMessage(), 'tahsilat_id' => $tahsilatId]);
        }
    }

    private function hariciIndirimYaz(array $g, array $idler, mixed $adisyonId, int $tahsilatId): void
    {
        $harici = $this->kuponDus(Hesap::tlSayi($g['indirim_tutari'] ?? '0'), (int) $adisyonId);
        if ($harici <= 0) {
            return;
        }
        try {
            $liste = $this->plansiz($idler);
            $toplam = (float) array_sum(array_map(fn ($l) => $l[0]->sum('fiyat'), $liste));
            if ($toplam <= 0) {
                return;
            }
            $mevcut = (float) array_sum(array_map(fn ($l) => $l[0]->sum('indirim_tutari'), $liste));
            $onceki = 0.0;
            foreach ($liste as [$kalemler, $odemeModeli, $fk]) {
                $kalemIdleri = $kalemler->pluck('id')->all();
                if ($kalemIdleri) {
                    $onceki += (float) $odemeModeli::whereIn($fk, $kalemIdleri)->sum('tutar');
                }
            }
            $uygulanacak = Hesap::uygulanacakIndirim($harici, $toplam, $mevcut, $onceki);
            if ($uygulanacak <= 0) {
                return;
            }
            foreach ($liste as [$kalemler]) {
                foreach ($kalemler as $k) {
                    $k->indirim_tutari = (float) ($k->indirim_tutari ?? 0) + Hesap::indirimPayi((float) $k->fiyat, $toplam, $uygulanacak);
                    $k->save();
                }
            }
        } catch (\Throwable $e) {
            Log::error('harici indirim kaleme yazilamadi (mobil)', ['err' => $e->getMessage(), 'tahsilat_id' => $tahsilatId]);
        }
    }

    /** Kupon indirimi adisyona zaten yazildiysa (kod-kullan) harici indirimden bir kez dusulur ve isaretlenir. */
    private function kuponDus(float $harici, int $adisyonId): float
    {
        try {
            if ($adisyonId && $harici > 0 && Schema::hasColumn('kampanya_katilimcilari', 'indirim_kodu_tutar') && Schema::hasColumn('kampanya_katilimcilari', 'indirim_kodu_tahsilata_dusuldu')) {
                $kuponlar = DB::table('kampanya_katilimcilari')->where('indirim_kodu_adisyon_id', $adisyonId)
                    ->where('indirim_kodu_tahsilata_dusuldu', 0)->where('indirim_kodu_tutar', '>', 0)->get(['id', 'indirim_kodu_tutar']);
                $kupon = (float) $kuponlar->sum('indirim_kodu_tutar');
                if ($kupon > 0) {
                    DB::table('kampanya_katilimcilari')->whereIn('id', $kuponlar->pluck('id')->all())->update(['indirim_kodu_tahsilata_dusuldu' => 1]);

                    return Hesap::kuponDusulmus($harici, $kupon);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('kupon indirim guard hata (mobil): '.$e->getMessage());
        }

        return $harici;
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

    /**
     * Vade odemesi: vade odendi isaretlenir, plana bagli her kaleme vadeKalemPayi yazilir. Kalem sirasi hizmet, urun,
     * paket. Eski kod urunde `aciklama` metni de yazar, korunur.
     *
     * @param  class-string  $vadeModeli
     */
    private function vadeleriOde(string $vadeModeli, string $tablo, string $planKolonu, array $vadeIdleri, array $g, int $tahsilatId): void
    {
        $odemeHam = $g['indirimli_toplam_tahsilat_tutari'] ?? null;
        foreach ($vadeIdleri as $vadeId) {
            $vade = $vadeModeli::where('id', $vadeId)->first();
            $vade->odendi = true;
            $vade->odeme_yontemi_id = $g['odeme_yontemi'] ?? null;
            $vade->save();

            // sum ham kolon degerini doner ("3000.00"); vadeKalemPayi bu metni eski kod gibi cevirir.
            $planToplami = DB::table($tablo)->where($planKolonu, $vade->{$planKolonu})->sum('tutar');

            foreach (self::TURLER as $tur => [$model, $odemeModeli, $fk]) {
                foreach ($model::where($planKolonu, $vade->{$planKolonu})->get() as $k) {
                    $onceki = $odemeModeli::where($fk, $k->id)->sum('tutar');
                    $o = new $odemeModeli();
                    $o->{$fk} = $k->id;
                    $o->tahsilat_id = $tahsilatId;
                    $o->tutar = Hesap::vadeKalemPayi($k->fiyat, $k->indirim_tutari, $onceki, $planToplami, $odemeHam);
                    if ($tur === 'urun') {
                        $o->aciklama = '(('.Hesap::tlMetin($k->fiyat).'-'.$k->indirim_tutari.'/'.Hesap::tlMetin($planToplami).')*'.Hesap::tlMetin($odemeHam);
                    }
                    $o->save();
                }
            }
        }
    }
}
