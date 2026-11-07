<?php

namespace App\Services\CagriMerkezi;

use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Exceptions\YetkisizErisimException;
use App\Services\Yetki\YetkiServisi;
use Illuminate\Support\Facades\DB;

/**
 * Müşterinin randevu geçmişi + özet (geldi/gelmedi/onaylı) — eski musteri_randevu_gecmisi karşılığı.
 * Eskiden farkı: personel (rol 5) yalnız kendi listesindeki müşteriyi görür; randevu notu musteri.not_yaz ister.
 */
class MusteriRandevuGecmisiServisi
{
    private const SATIR_TAVANI = 300;

    public function __construct(private YetkiServisi $yetki) {}

    public function getir(CagriMerkeziErisim $e, int $userId): array
    {
        if (! $e->yonetici && ! $this->kendiListesinde($e, $userId)) {
            throw new YetkisizErisimException('Bu müşteriye erişim yetkiniz yok');
        }
        $notGor = $this->yetki->yetkiliYetkiVar($e->yetkiliId, $e->salonId, 'musteri.not_yaz');
        $bugun = date('Y-m-d');
        $ozet = ['geldi' => 0, 'gelmedi' => 0, 'onayli' => 0];
        $sonGelis = null;
        $randevular = [];

        foreach ($this->randevular($e->salonId, $userId) as $r) {
            [$metin, $renk, $sayac] = self::durum((int) $r->durum, (bool) $r->randevuya_geldi, (string) $r->tarih >= $bugun);
            if ($sayac) {
                $ozet[$sayac]++;
            }
            if ($sayac === 'geldi' && $r->tarih > $sonGelis) {
                $sonGelis = $r->tarih;
            }
            $randevular[] = [
                'tarih'       => $r->tarih ? date('d.m.Y', strtotime($r->tarih)) : '',
                'saat'        => $r->saat ? substr($r->saat, 0, 5) : '',
                'personel'    => (string) $r->personel,
                'hizmet'      => implode(', ', $r->hizmetler),
                'not'         => $notGor ? trim((string) $r->not) : '',
                'durum_metin' => $metin,
                'renk'        => $renk,
            ];
        }

        return ['randevular' => $randevular, 'ozet' => $ozet + [
            'son_gelis_gun' => $sonGelis ? max(0, (int) floor((strtotime($bugun) - strtotime($sonGelis)) / 86400)) : null,
        ]];
    }

    /** @return array{0:string,1:string,2:?string} metin, renk, özet sayacı */
    private static function durum(int $durum, bool $geldi, bool $gelecek): array
    {
        return match (true) {
            $durum === 2 || $durum === 3 => ['İptal', '#dc2626', null],
            $geldi   => ['Geldi', '#16a34a', 'geldi'],
            $gelecek => ['Onaylı', '#2563eb', 'onayli'],
            default  => ['Gelmedi', '#b26a00', 'gelmedi'],
        };
    }

    private function kendiListesinde(CagriMerkeziErisim $e, int $userId): bool
    {
        return DB::table('aranacak_musteriler as am')->join('arama_listesi as al', 'al.id', '=', 'am.arama_id')
            ->where('am.user_id', $userId)->where('al.salon_id', $e->salonId)->where('al.personel_id', $e->personelId ?? 0)
            ->exists();
    }

    /** Randevu başına tek satır (tarih + saat azalan); hizmet join'i satır çoğaltır, burada birleşir. */
    private function randevular(int $salonId, int $userId): array
    {
        $satirlar = DB::table('randevular as r')
            ->leftJoin('randevu_hizmetler as rh', 'rh.randevu_id', '=', 'r.id')
            ->leftJoin('hizmetler as h', 'h.id', '=', 'rh.hizmet_id')
            ->leftJoin('salon_personelleri as p', 'p.id', '=', 'rh.personel_id')
            ->where('r.salon_id', $salonId)->where('r.user_id', $userId)
            ->orderByDesc('r.tarih')->orderByDesc('r.saat')->limit(self::SATIR_TAVANI)
            ->get(['r.id', 'r.tarih', 'r.saat', 'r.durum', 'r.randevuya_geldi', 'r.personel_notu', 'h.hizmet_adi', 'p.personel_adi']);

        $sonuc = [];
        foreach ($satirlar as $s) {
            $sonuc[$s->id] ??= (object) ['tarih' => $s->tarih, 'saat' => $s->saat, 'durum' => $s->durum,
                'randevuya_geldi' => $s->randevuya_geldi, 'not' => $s->personel_notu, 'personel' => $s->personel_adi, 'hizmetler' => []];
            if ($s->hizmet_adi && ! in_array($s->hizmet_adi, $sonuc[$s->id]->hizmetler, true)) {
                $sonuc[$s->id]->hizmetler[] = $s->hizmet_adi;
            }
        }

        return array_values($sonuc);
    }
}
