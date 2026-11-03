<?php

namespace App\Services\Ayar;

use App\Domain\Ayar\KaynakTuru;
use App\Domain\Ayar\TakvimRengi;
use App\Exceptions\BulunamadiException;
use App\Support\AktiviteLog;
use App\Support\TakvimSirasi;
use App\Support\YerelListe;
use Illuminate\Support\Facades\DB;

/**
 * Takvim kaynaklari (cihaz / oda) ORTAK islemleri — eski cihaz_liste_getir / oda_liste_getir,
 * *musait(degil)isaretle, cihaz_sil / oda_sil, *SiralamaArtir/Azalt ikililerinin TEK karsiligi.
 * Tur'e ozel kayit (cihaz saatleri, oda personel/hizmetleri) CihazServisi / OdaServisi'nde.
 *
 * Eskiden farkli: durum isaretleme ve siralama yetki + salon kapsami ister (eskide yoktu);
 * silme sonrasi sira kaydirmasi yalniz o salonda (eski oda_sil tum salonlari kaydiriyordu).
 */
class TakvimKaynakServisi
{
    /**
     * Sayfali liste (ListeIstegi sozlesmesi; varsayilan sira = takvim sirasi). $ekle(satirlar)
     * tur'e ozel alanlari id'ye gore toplu doldurur (N+1 yok).
     *
     * @param  array{page:int, per_page:int, ara:?string, sirala:?string, yon:string}  $o
     * @param  callable(array<int,array>):array<int,array>|null  $ekle
     * @param  list<string>  $ekAraAlanlari
     */
    public function listele(KaynakTuru $tur, int $salonId, array $o, bool $tumu, ?callable $ekle = null, array $ekAraAlanlari = []): array
    {
        $satirlar = $this->satirlar($tur, $salonId);
        if ($ekle && $satirlar) {
            $satirlar = $ekle($satirlar);
        }
        $satirlar = array_values($satirlar);
        if ($tumu) {
            return ['data' => $satirlar] + ['total' => count($satirlar)];
        }

        return YerelListe::uygula($satirlar, $o, ['ad', 'aciklama', 'durum_etiket', ...$ekAraAlanlari]);
    }

    /** Tek kayit (aktif + bu salonun) — yoksa 404 (kapsam sizintisi yok). */
    public function bul(KaynakTuru $tur, int $salonId, int $id): object
    {
        $k = DB::table($tur->tablo())->where('id', $id)->where('salon_id', $salonId)->where('aktifmi', 1)->first();
        if (! $k) {
            throw new BulunamadiException($tur->etiket().' bulunamadı');
        }

        return $k;
    }

    /** Yeni kaynak + takvim rengi; id doner. Cagiran transaction acar. */
    public function olustur(KaynakTuru $tur, int $salonId, string $ad): int
    {
        $kosul = ['salon_id' => $salonId, 'aktifmi' => 1];
        $id = (int) DB::table($tur->tablo())->insertGetId([
            'salon_id' => $salonId, $tur->adKolonu() => $ad, 'aktifmi' => 1, 'durum' => 1,
            'takvim_sirasi' => TakvimSirasi::sonraki($tur->tablo(), $kosul),
        ] + $this->zaman());

        $sonRenk = DB::table($tur->renkTablosu())->where('salon_id', $salonId)->orderByDesc('id')->value('renk_id');
        DB::table($tur->renkTablosu())->insert([
            'salon_id' => $salonId, $tur->fk() => $id, 'renk_id' => TakvimRengi::sonraki($sonRenk !== null ? (int) $sonRenk : null),
        ] + $this->zaman());

        return $id;
    }

    public function adGuncelle(KaynakTuru $tur, int $id, string $ad): void
    {
        DB::table($tur->tablo())->where('id', $id)->update([$tur->adKolonu() => $ad, 'updated_at' => now()]);
    }

    /** Musait / musait degil (+ aciklama). Musaitte aciklama temizlenir (eskiyle ayni). */
    public function durum(KaynakTuru $tur, int $salonId, int $yapanId, int $id, bool $musait, ?string $aciklama): array
    {
        $k = $this->bul($tur, $salonId, $id);
        $aciklama = $musait ? null : (trim((string) $aciklama) ?: null);
        DB::table($tur->tablo())->where('id', $id)->update(['durum' => $musait ? 1 : 0, 'aciklama' => $aciklama, 'updated_at' => now()]);

        AktiviteLog::yaz($salonId, $yapanId, $tur->value.'_durum_degistir', $tur->value, $id, $k->{$tur->adKolonu()},
            $tur->etiket().' "'.($musait ? 'Müsait' : 'Müsait Değil').'" olarak işaretlendi', $aciklama ? ['aciklama' => $aciklama] : []);

        return $this->satir($tur, $salonId, $id);
    }

    /** Pasife al (eski "sil"), takvim rengini kaldir, salonun sirasini yeniden numarala. */
    public function sil(KaynakTuru $tur, int $salonId, int $yapanId, int $id): void
    {
        $k = $this->bul($tur, $salonId, $id);
        DB::transaction(function () use ($tur, $salonId, $id) {
            DB::table($tur->tablo())->where('id', $id)->update(['aktifmi' => 0, 'updated_at' => now()]);
            DB::table($tur->renkTablosu())->where($tur->fk(), $id)->delete();
            TakvimSirasi::numarala($tur->tablo(), ['salon_id' => $salonId, 'aktifmi' => 1]);
        });

        AktiviteLog::yaz($salonId, $yapanId, $tur->value.'_sil', $tur->value, $id, $k->{$tur->adKolonu()}, $tur->etiket().' kaldırıldı');
    }

    /** @param  int  $delta  -1 yukari / +1 asagi */
    public function sirala(KaynakTuru $tur, int $salonId, int $yapanId, int $id, int $delta): void
    {
        $k = $this->bul($tur, $salonId, $id);
        if (TakvimSirasi::kaydir($tur->tablo(), ['salon_id' => $salonId, 'aktifmi' => 1], $id, $delta) === 'ok') {
            AktiviteLog::yaz($salonId, $yapanId, $tur->value.'_siralama_degistir', $tur->value, $id, $k->{$tur->adKolonu()},
                $tur->etiket().' takvim sırası bir '.($delta < 0 ? 'yukarı' : 'aşağı').' taşındı');
        }
    }

    /** Tek satir (liste bicimiyle ayni). */
    public function satir(KaynakTuru $tur, int $salonId, int $id): array
    {
        foreach ($this->satirlar($tur, $salonId) as $s) {
            if ($s['id'] === $id) {
                return $s;
            }
        }
        throw new BulunamadiException($tur->etiket().' bulunamadı');
    }

    /**
     * Aktif kaynaklar takvim sirasiyla (once 0..n-1 numaralanir). Alan adlari Flutter
     * Cihaz/Oda modelleriyle uyumlu: id, <tur>_adi, durum (1/0), aktifmi, aciklama.
     *
     * @return array<int,array> id => satir
     */
    private function satirlar(KaynakTuru $tur, int $salonId): array
    {
        TakvimSirasi::numarala($tur->tablo(), ['salon_id' => $salonId, 'aktifmi' => 1]);
        $liste = DB::table($tur->tablo())->where('salon_id', $salonId)->where('aktifmi', 1)
            ->orderBy('takvim_sirasi')->orderBy('id')->get(['id', $tur->adKolonu(), 'durum', 'aciklama', 'takvim_sirasi']);
        $son = $liste->count() - 1;

        $satirlar = [];
        foreach ($liste as $i => $k) {
            $musait = (int) $k->durum !== 0;
            $satirlar[(int) $k->id] = [
                'id'             => (int) $k->id,
                'ad'             => (string) $k->{$tur->adKolonu()},
                $tur->adKolonu() => (string) $k->{$tur->adKolonu()},
                'durum'          => $musait ? 1 : 0,
                'musait'         => $musait,
                'durum_etiket'   => $musait ? 'Müsait' : 'Müsait Değil',
                'aciklama'       => $k->aciklama,
                'aktifmi'        => 1,
                'takvim_sirasi'  => $i,
                'yukari'         => $i > 0,
                'asagi'          => $i < $son,
            ];
        }

        return $satirlar;
    }

    private function zaman(): array
    {
        $simdi = now();

        return ['created_at' => $simdi, 'updated_at' => $simdi];
    }
}
