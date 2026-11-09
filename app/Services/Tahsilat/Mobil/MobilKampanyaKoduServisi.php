<?php

namespace App\Services\Tahsilat\Mobil;

use App\Domain\Tahsilat\AdisyonHesap;
use App\Domain\Tahsilat\MobilTahsilatHesabi as Hesap;
use App\Domain\Tahsilat\TahsilatDagitim;
use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Models\AdisyonHizmetler;
use App\Models\AdisyonPaketler;
use App\Models\AdisyonUrunler;
use App\Models\TahsilatHizmetler;
use App\Models\TahsilatPaketler;
use App\Models\TahsilatUrunler;
use App\Support\AktiviteLog;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Eski mobil kampanya indirim kodu: kodlar, kod-kullan (kod yakilir, indirim adisyona o an yazilir), adisyon durumu. */
class MobilKampanyaKoduServisi
{
    private const KALEM = [
        'hizmet' => [AdisyonHizmetler::class, TahsilatHizmetler::class, 'adisyon_hizmet_id', 'hizmet_id', 'adisyon_hizmetler', 'hizmetler', 'hizmet_adi'],
        'urun'   => [AdisyonUrunler::class, TahsilatUrunler::class, 'adisyon_urun_id', 'urun_id', 'adisyon_urunler', 'urunler', 'urun_adi'],
        'paket'  => [AdisyonPaketler::class, TahsilatPaketler::class, 'adisyon_paket_id', 'paket_id', 'adisyon_paketler', 'paketler', 'paket_adi'],
    ];

    public function __construct(private MobilKapsam $kapsam) {}

    public function kodlar(int $salonId, int $musteriId): array
    {
        $kodlar = DB::table('kampanya_katilimcilari as k')->join('kampanya_yonetimi as y', 'y.id', '=', 'k.kampanya_id')
            ->where('k.user_id', $musteriId)->whereNotNull('k.indirim_kodu')->where('k.indirim_kodu', '!=', '')
            ->where(fn ($q) => $q->whereNull('k.indirim_kodu_kullanildi')->orWhere('k.indirim_kodu_kullanildi', '!=', 1))
            ->where('y.salon_id', $salonId)->orderBy('k.id', 'desc')
            ->get(['k.indirim_kodu as kod', 'y.indirim_turu as indirim'])
            ->map(fn ($r) => ['kod' => $r->kod, 'indirim' => $r->indirim])->values()->all();

        return ['basarili' => true, 'kodlar' => $kodlar];
    }

    public function adisyonDurumu(int $salonId, int $adisyonId): array
    {
        if (! $adisyonId || ! Schema::hasColumn('kampanya_katilimcilari', 'indirim_kodu_adisyon_id')) {
            return ['basarili' => true, 'uygulanmis' => false];
        }
        $r = DB::table('kampanya_katilimcilari as k')->join('kampanya_yonetimi as y', 'y.id', '=', 'k.kampanya_id')
            ->where('k.indirim_kodu_adisyon_id', $adisyonId)->where('y.salon_id', $salonId)
            ->first(['k.indirim_kodu as kod', 'y.indirim_turu as metin']);

        return $r ? ['basarili' => true, 'uygulanmis' => true, 'kod' => $r->kod, 'metin' => $r->metin] : ['basarili' => true, 'uygulanmis' => false];
    }

    public function kullan(int $salonId, int $yetkiliId, array $g): array
    {
        $musteriId = (int) ($g['musteri_id'] ?? 0);
        $kod = trim((string) ($g['kod'] ?? ''));
        if ($kod === '' || ! $musteriId) {
            throw new GecersizIslemException('Kod ve musteri gerekli');
        }
        $this->kapsam->adisyonOlmali($salonId, $g['adisyon_id'] ?? null);

        return Idempotency::calistir('mobil_kampanya_kodu', (string) ($g['idempotency_key'] ?? ''), $yetkiliId, $salonId,
            fn () => $this->uygula($salonId, $yetkiliId, $musteriId, $kod, (int) ($g['adisyon_id'] ?? 0), ! empty($g['tahsilata_dusulmus'])));
    }

    /**
     * $dusulmus: istemci kod indirimini tahsilattaki harici indirime ayrıca EKLEMEZ (web v2 Satış & Tahsilat) —
     * indirim kaleme yazıldığı an hesaba düşmüş sayılır; yoksa kupon guard'ı sonraki harici/müşteri indirimini yer.
     */
    private function uygula(int $salonId, int $yetkiliId, int $musteriId, string $kod, int $adisyonId, bool $dusulmus = false): array
    {
        $k = DB::table('kampanya_katilimcilari as k')->join('kampanya_yonetimi as y', 'y.id', '=', 'k.kampanya_id')
            ->where('k.user_id', $musteriId)->where('k.indirim_kodu', $kod)->where('y.salon_id', $salonId)
            ->orderBy('k.id', 'desc')->lockForUpdate()
            ->first(['k.*', 'y.indirim_turu as k_indirim_turu', 'y.hizmet_id as k_hizmet_id', 'y.urun_id as k_urun_id', 'y.paket_id as k_paket_id']);
        if (! $k) {
            throw new BulunamadiException('Gecersiz kod ya da bu musteriye ait degil');
        }
        if (! empty($k->indirim_kodu_kullanildi)) {
            $t = $k->indirim_kodu_kullanim_tarihi ? date('d.m.Y', strtotime($k->indirim_kodu_kullanim_tarihi)) : '';
            throw new CakismaException('Bu kod zaten kullanilmis'.($t ? " ($t)" : ''));
        }

        $metin = (string) $k->k_indirim_turu;
        [$yuzde, $tip] = Hesap::kampanyaIndirimi($metin);

        // Kalem kisiti: kampanya belirli bir kalem icinse indirim yalniz ona; adisyonda yoksa kod yakilmaz.
        $hedef = match (true) {
            (int) $k->k_hizmet_id > 0 => ['hizmet', (int) $k->k_hizmet_id],
            (int) $k->k_urun_id > 0   => ['urun', (int) $k->k_urun_id],
            (int) $k->k_paket_id > 0  => ['paket', (int) $k->k_paket_id],
            default                   => null,
        };
        $indirimTutar = null;
        $kalemAdi = '';
        if ($tip === 'yuzde' && $hedef && $adisyonId) {
            [, , , $fk, $tablo, $katalog, $kolon] = self::KALEM[$hedef[0]];
            $kalemTutar = (float) DB::table($tablo)->where('adisyon_id', $adisyonId)->where($fk, $hedef[1])->sum('fiyat');
            $kalemAdi = (string) DB::table($katalog)->where('id', $hedef[1])->value($kolon);
            if ($kalemTutar <= 0) {
                throw new CakismaException('Bu kod yalnizca "'.$kalemAdi.'" icin gecerli; bu adisyonda o kalem yok');
            }
            $indirimTutar = Hesap::yuzdeIndirim($kalemTutar, $yuzde);
        }

        $guncelle = ['indirim_kodu_kullanildi' => 1, 'indirim_kodu_kullanim_tarihi' => now()];
        if (Schema::hasColumn('kampanya_katilimcilari', 'indirim_kodu_adisyon_id') && $adisyonId) {
            $guncelle['indirim_kodu_adisyon_id'] = $adisyonId;
        }
        DB::table('kampanya_katilimcilari')->where('id', $k->id)->update($guncelle);

        if ($adisyonId && $tip === 'yuzde' && $yuzde > 0) {
            try {
                $yazili = $this->adisyonaIndirimYaz($adisyonId, $yuzde, $hedef);
                if ($yazili > 0) {
                    $indirimTutar = $yazili;
                    if (Schema::hasColumn('kampanya_katilimcilari', 'indirim_kodu_tutar')) {
                        DB::table('kampanya_katilimcilari')->where('id', $k->id)->update(['indirim_kodu_tutar' => $yazili]
                            + ($dusulmus && Schema::hasColumn('kampanya_katilimcilari', 'indirim_kodu_tahsilata_dusuldu') ? ['indirim_kodu_tahsilata_dusuldu' => 1] : []));
                    }
                }
            } catch (\Throwable $e) {
                Log::error('kampanya indirimi adisyona yazilamadi (mobil)', ['err' => $e->getMessage(), 'adisyon_id' => $adisyonId]);
            }
        }

        AktiviteLog::yaz($salonId, $yetkiliId, 'kampanya_indirim_kodu_kullan', 'kampanya_katilimci', (int) $k->id, $kod, 'Kampanya indirim kodu kullanildi (app)', ['indirim_turu' => $metin]);

        return [
            'basarili' => true, 'tip' => $tip, 'yuzde' => $yuzde, 'metin' => $metin, 'indirimTutar' => $indirimTutar, 'kalem_adi' => $kalemAdi,
            'mesaj' => $tip === 'yuzde'
                ? ($indirimTutar !== null ? "\"{$kalemAdi}\" icin %{$yuzde} indirim" : "%{$yuzde} indirim uygulandi")
                : "Indirim: {$metin} (elle uygulayin)",
        ];
    }

    /** Eski KampanyaKatilimcilari::adisyonaIndirimYaz: plansiz kalemlere fiyata oranli, kalanla sinirli. Yazilan toplam doner. */
    private function adisyonaIndirimYaz(int $adisyonId, int $yuzde, ?array $hedef): float
    {
        $liste = [];
        foreach (self::KALEM as $tur => [$model, $odemeModeli, $odemeFk, $fk]) {
            if ($hedef && $hedef[0] !== $tur) {
                continue;
            }
            $q = $model::where('adisyon_id', $adisyonId)->whereNull('senet_id')->whereNull('taksitli_tahsilat_id');
            $liste[] = [$hedef ? $q->where($fk, $hedef[1])->get() : $q->get(), $odemeModeli, $odemeFk];
        }
        // Web ile ayni dagitim (TahsilatDagitim::oransal): fiyata oranli, her kalem kendi kalaniyla sinirli, artik kapasitesi
        // olana, kurus-kesin. Eskiden kalem basina sinir ve kurus artigi yoktu (3 x 100 TL'ye 100 TL indirim 99,99 yaziliyordu).
        $sira = [];
        $agirlik = [];
        $kapasite = [];
        foreach ($liste as [$kalemler, $odemeModeli, $odemeFk]) {
            $idler = $kalemler->pluck('id')->all();
            $onceki = $idler
                ? $odemeModeli::whereIn($odemeFk, $idler)->groupBy($odemeFk)->selectRaw($odemeFk.' as kalem, SUM(tutar) as toplam')->pluck('toplam', 'kalem')
                : collect();
            foreach ($kalemler as $k) {
                $fiyat = AdisyonHesap::tlToKurus($k->fiyat ?? 0);
                $sira[] = $k;
                $agirlik[] = $fiyat;
                $kapasite[] = max(0, $fiyat - AdisyonHesap::tlToKurus($k->indirim_tutari ?? 0) - AdisyonHesap::tlToKurus($onceki[$k->id] ?? 0));
            }
        }
        $toplam = array_sum($agirlik);
        if ($toplam <= 0) {
            return 0.0;
        }
        $paylar = TahsilatDagitim::oransal($agirlik, $kapasite, AdisyonHesap::tlToKurus(Hesap::yuzdeIndirim(AdisyonHesap::kurusToTl($toplam), $yuzde)));
        foreach ($paylar as $i => $payKurus) {
            $k = $sira[$i];
            $k->indirim_tutari = AdisyonHesap::kurusToTl(AdisyonHesap::tlToKurus($k->indirim_tutari ?? 0) + $payKurus);
            $k->save();
        }

        return AdisyonHesap::kurusToTl(array_sum($paylar));
    }
}
