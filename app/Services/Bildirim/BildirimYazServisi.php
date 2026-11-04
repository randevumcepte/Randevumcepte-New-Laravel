<?php

namespace App\Services\Bildirim;

use App\Exceptions\BulunamadiException;
use App\Support\AktiviteLog;

/**
 * Bildirim YAZMA: okundu ve silme. Her işlem kutu kapsamında yürür; başka kişinin bildirimi 404'tür
 * (eski bildirimguncelle kimliksizdi, herhangi bir id okundu işaretlenebiliyordu). Log kodları eskiyle aynı.
 * Tüm işlemler güncel sayacı döner: {okunmamis, toplam}.
 */
class BildirimYazServisi
{
    private const HEDEF_TIPI = 'bildirim';

    public function __construct(private BildirimSorgusu $sorgu) {}

    public function okundu(BildirimKutusu $kutu, int $id): array
    {
        $satir = $this->bul($kutu, $id);
        $this->sorgu->kutuSorgusu($kutu)->where('id', $id)->update(['okundu' => 1]);
        $this->logla($kutu, (int) $satir->salon_id, 'bildirim_okundu', $id, 'Bildirim okundu işaretlendi.');

        return $this->sorgu->sayac($kutu);
    }

    public function tumunuOku(BildirimKutusu $kutu): array
    {
        $adet = $this->sorgu->kutuSorgusu($kutu)->where('okundu', 0)->update(['okundu' => 1]);
        $this->logla($kutu, (int) $kutu->salonId(), 'bildirim_toplu_okundu', null, 'Tüm bildirimler okundu işaretlendi.', ['guncellenen' => $adet]);

        return $this->sorgu->sayac($kutu);
    }

    public function sil(BildirimKutusu $kutu, int $id): array
    {
        $satir = $this->bul($kutu, $id);
        $this->sorgu->kutuSorgusu($kutu)->where('id', $id)->delete();
        $this->logla($kutu, (int) $satir->salon_id, 'bildirim_sil', $id, 'Bildirim silindi.');

        return $this->sorgu->sayac($kutu);
    }

    public function tumunuSil(BildirimKutusu $kutu): array
    {
        $adet = $this->sorgu->kutuSorgusu($kutu)->delete();
        $this->logla($kutu, (int) $kutu->salonId(), 'bildirim_toplu_sil', null, 'Tüm bildirimler silindi.', ['silinen' => $adet]);

        return $this->sorgu->sayac($kutu);
    }

    private function bul(BildirimKutusu $kutu, int $id): object
    {
        $satir = $this->sorgu->kutuSorgusu($kutu)->where('id', $id)->first(['id', 'salon_id']);
        if (! $satir) {
            throw new BulunamadiException('Bildirim bulunamadı');
        }

        return $satir;
    }

    /** Salonsuz eski kayıtta log yeri yok; işlem yine de tamamlanır. */
    private function logla(BildirimKutusu $kutu, int $salonId, string $islem, ?int $id, string $aciklama, array $meta = []): void
    {
        if ($salonId > 0) {
            AktiviteLog::yaz($salonId, $kutu->yetkiliId(), $islem, self::HEDEF_TIPI, $id, null, $aciklama, $meta);
        }
    }
}
