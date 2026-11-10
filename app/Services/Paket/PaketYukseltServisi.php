<?php

namespace App\Services\Paket;

use App\Domain\Paket\PaketUstKume;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Exceptions\YetkisizErisimException;
use App\Models\AdisyonPaketler;
use App\Services\Satis\KalemServisi;
use App\Support\AktiviteLog;
use App\Support\Idempotency;
use App\Support\Sema;
use Illuminate\Support\Facades\DB;

/**
 * "Paketi Yukselt" — satilmis paketi YERINDE kapsayan (ust kume) bir katalog paketine donusturur.
 * Eski StoreAdminController::paketYukseltmeAdaylari / paketYukselt (765f3853) karsiligi; web ve mobil ayni uc.
 *
 * Ayni adisyon_paketler satiri korunur: seans kayitlari (APS) ve tahsilat_paketler adisyon_paket_id'ye bagli
 * kalir, odeme gecmisi kopmaz. paket_id hedefe, fiyat hedefe (ya da yetkiyle girilen fiyata), seans_sayisi
 * hedefin bolge basina hakkina (PaketTutari::satisSeansi) cekilir. Satir model ile kaydedilir: saving kancasi
 * (PaketKullanimi::satisaKopyala) sinirsiz / sure / gunluk limit alanlarini hedeften alir.
 *
 * Eskiden farklar (hata duzeltmeleri):
 *   - Adaylar yalniz salonun AKTIF katalog paketleri (eski kod satisa ozel pasif kopyalari da gosteriyordu).
 *   - Hedef ayni salona ait olmali; salon_id NULL hedef de reddedilir (eski kod denetimi atliyordu).
 *   - Fiyat sayisal dogrulanir (eski kod '1500.50'den noktayi silip 150050 yaziyordu).
 *   - Katalogdan farkli fiyat yalniz satis.indirim_uygula ile (KalemServisi kurali); fiyat tahsil edilenin
 *     altina inemez (KalemServisi::tahsilAltSiniri).
 *   - Senet / taksit planina bagli pakette fiyat degisirse plan ve dagitim tutarsiz kalirdi: reddedilir.
 *   - idempotency_key: cift tiklamada ikinci kez donusturulmez.
 */
class PaketYukseltServisi
{
    public function __construct(
        private PaketSorgusu $sorgu,
        private KalemServisi $kalem,
    ) {}

    /**
     * Adim 1 — kapsayan katalog paketleri + mevcut paket ozeti.
     *
     * @return array{mevcut_paket_adi:string, mevcut_fiyat:float, mevcut_odenen:float, mevcut_hizmetler:array, adaylar: array<int,array<string,mixed>>}
     */
    public function adaylar(int $adisyonPaketId, int $salonId): array
    {
        $ap = AdisyonPaketler::find($adisyonPaketId) ?? throw new BulunamadiException('Paket kalemi bulunamadı');
        $mevcut = $this->paketHizmetleri((int) $ap->paket_id);
        $adlar = DB::table('hizmetler')->whereIn('id', $mevcut)->pluck('hizmet_adi', 'id');

        $adaylar = [];
        foreach ($this->sorgu->listele($salonId, ['sirala' => 'paket_adi', 'yon' => 'asc'], true)['data'] as $p) {
            $hedef = array_column($p['hizmetler'], 'hizmet_id');
            if ((int) $p['id'] === (int) $ap->paket_id || ! PaketUstKume::adayMi($mevcut, $hedef)) {
                continue;
            }
            $ekler = PaketUstKume::ekHizmetler($mevcut, $hedef);
            $adaylar[] = [
                'paket_id'         => (int) $p['id'],
                'paket_adi'        => $p['paket_adi'],
                'fiyat'            => $p['fiyat'],
                'seans_per'        => $this->seansPer($p['satis_seans'], $ap),
                'hizmet_adedi'     => count(array_unique($hedef)),
                'sinirsiz'         => $p['sinirsiz'],
                'gecerlilik_metni' => $p['gecerlilik_metni'],
                'ek_hizmetler'     => array_values(array_map(fn ($h) => ['hizmet_id' => $h['hizmet_id'], 'hizmet_adi' => $h['hizmet_adi'], 'seans' => $h['seans']],
                    array_filter($p['hizmetler'], fn ($h) => in_array($h['hizmet_id'], $ekler, true)))),
            ];
        }

        return [
            'mevcut_paket_adi' => (string) DB::table('paketler')->where('id', $ap->paket_id)->value('paket_adi'),
            'mevcut_fiyat'     => round((float) $ap->fiyat, 2),
            'mevcut_odenen'    => $this->odenen($adisyonPaketId),
            'mevcut_hizmetler' => array_map(fn ($id) => ['hizmet_id' => $id, 'hizmet_adi' => trim((string) ($adlar[$id] ?? ''))], $mevcut),
            'adaylar'          => $adaylar,
        ];
    }

    /**
     * Adim 2 — yerinde donusturme.
     *
     * @param  array{yetkili_id:int, fiyat_degistirebilir:bool}  $kim
     */
    public function yukselt(int $adisyonPaketId, int $salonId, int $hedefPaketId, ?float $fiyat, array $kim, string $idempotencyKey): array
    {
        $yetkiliId = (int) $kim['yetkili_id'];
        $hedef = $this->sorgu->satisTutari($hedefPaketId, $salonId); // salon + aktiflik (salon_id NULL hedef de reddedilir)
        if ($fiyat !== null && round($fiyat, 2) !== round($hedef['fiyat'], 2) && ! $kim['fiyat_degistirebilir']) {
            throw new YetkisizErisimException('Paket fiyatını değiştirme yetkiniz yok');
        }

        $sonuc = Idempotency::calistir('paket_yukselt', $idempotencyKey, $yetkiliId, $salonId,
            function () use ($adisyonPaketId, $hedefPaketId, $hedef, $fiyat) {
                $ap = AdisyonPaketler::where('id', $adisyonPaketId)->lockForUpdate()->first() ?? throw new BulunamadiException('Paket kalemi bulunamadı');
                $mevcut = $this->paketHizmetleri((int) $ap->paket_id);
                $hedefHizmetler = $this->paketHizmetleri($hedefPaketId);
                if (! PaketUstKume::adayMi($mevcut, $hedefHizmetler)) {
                    throw new GecersizIslemException(array_diff($mevcut, $hedefHizmetler)
                        ? 'Seçilen paket mevcut paketin tüm hizmetlerini içermiyor.'
                        : 'Seçilen paket bir yükseltme değil (ek hizmet yok).');
                }

                $yeniFiyat = round($fiyat ?? $hedef['fiyat'], 2);
                if (($ap->senet_id || $ap->taksitli_tahsilat_id) && $yeniFiyat !== round((float) $ap->fiyat, 2)) {
                    throw new GecersizIslemException('Senet veya taksit planına bağlı paketin fiyatı yükseltmede değiştirilemez; önce planı güncelleyin.');
                }
                $this->kalem->tahsilAltSiniri($ap, 'tahsilat_paketler', 'adisyon_paket_id', $yeniFiyat);

                $eskiPaketId = (int) $ap->paket_id;
                $eskiFiyat = (float) $ap->fiyat;
                $seansPer = $this->seansPer($hedef['seans'], $ap);
                $ap->paket_id = $hedefPaketId;
                $ap->fiyat = $yeniFiyat;
                $ap->seans_sayisi = $seansPer;
                $ap->bekleyen_seans = PaketUstKume::bekleyenSeans($seansPer, count($hedefHizmetler),
                    DB::table('adisyon_paket_seanslar')->where('adisyon_paket_id', $ap->id)->count());
                if (self::kaynakKolonuVar() && empty($ap->yukseltme_kaynak_paket_id)) {
                    $ap->yukseltme_kaynak_paket_id = $eskiPaketId; // ilk yukseltmede asil kaynak korunur
                }
                $ap->save(); // saving kancasi: sinirsiz/sure alanlari hedeften (sinirsizda seans tavani)

                return [
                    'adisyon_paket_id' => (int) $ap->id,
                    'adisyon_id'       => (int) $ap->adisyon_id,
                    'eski_paket_id'    => $eskiPaketId,
                    'hedef_paket_id'   => $hedefPaketId,
                    'eski_fiyat'       => $eskiFiyat,
                    'fiyat'            => $yeniFiyat,
                    'seans_per'        => (int) $ap->seans_sayisi,
                ];
            });

        if (! $sonuc['replay']) {
            $eskiAd = DB::table('paketler')->where('id', $sonuc['eski_paket_id'])->value('paket_adi') ?? 'Paket #'.$sonuc['eski_paket_id'];
            AktiviteLog::yaz($salonId, $yetkiliId, 'paket_yukseltildi', 'adisyon_paket', $sonuc['adisyon_paket_id'],
                $eskiAd.' → '.$hedef['paket_adi'], 'Paket yükseltildi (yerinde dönüştürme; seans kayıtları korundu)',
                ['adisyon_id' => $sonuc['adisyon_id'], 'eski_paket_id' => $sonuc['eski_paket_id'], 'hedef_paket_id' => $hedefPaketId,
                    'eski_fiyat' => $sonuc['eski_fiyat'], 'yeni_fiyat' => $sonuc['fiyat'], 'seans_per' => $sonuc['seans_per']]);
        }

        $odenen = $this->odenen($adisyonPaketId);

        return $sonuc + ['odenen' => $odenen, 'kalan' => PaketUstKume::kalan($sonuc['fiyat'], $odenen)];
    }

    /** Ortak veritabani kolonu eski reponun migration'iyla gelir; yoksa yukseltme kaynagi yazilmaz. */
    public static function kaynakKolonuVar(): bool
    {
        static $var = null;

        return $var ??= Sema::kolonVar('adisyon_paketler', 'yukseltme_kaynak_paket_id');
    }

    /**
     * Kaynak paket adlari (toplu, satir basina sorgu yok).
     *
     * @param  int[]  $adisyonPaketIdler
     * @return array<int,array{paket_id:int, paket_adi:string}>  adisyon_paket_id → kaynak
     */
    public static function kaynaklar(array $adisyonPaketIdler): array
    {
        if (! $adisyonPaketIdler || ! self::kaynakKolonuVar()) {
            return [];
        }
        $satirlar = DB::table('adisyon_paketler as ap')->leftJoin('paketler as p', 'p.id', '=', 'ap.yukseltme_kaynak_paket_id')
            ->whereIn('ap.id', $adisyonPaketIdler)->whereNotNull('ap.yukseltme_kaynak_paket_id')
            ->get(['ap.id', 'ap.yukseltme_kaynak_paket_id', 'p.paket_adi']);

        $sonuc = [];
        foreach ($satirlar as $s) {
            $sonuc[(int) $s->id] = ['paket_id' => (int) $s->yukseltme_kaynak_paket_id, 'paket_adi' => trim((string) ($s->paket_adi ?? 'Paket #'.$s->yukseltme_kaynak_paket_id))];
        }

        return $sonuc;
    }

    /** Hedefin bolge basina hakki; paket seans tanimlamiyorsa mevcut satisin hakki (eski kural), o da yoksa 1. */
    private function seansPer(int $hedefSeans, AdisyonPaketler $ap): int
    {
        return $hedefSeans > 0 ? $hedefSeans : max(1, (int) $ap->seans_sayisi);
    }

    /** @return int[] */
    private function paketHizmetleri(int $paketId): array
    {
        return DB::table('paket_hizmetler')->where('paket_id', $paketId)->whereNotNull('hizmet_id')
            ->pluck('hizmet_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    private function odenen(int $adisyonPaketId): float
    {
        return round((float) DB::table('tahsilat_paketler')->where('adisyon_paket_id', $adisyonPaketId)->sum('tutar'), 2);
    }
}
