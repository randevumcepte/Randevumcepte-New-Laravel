<?php

namespace App\Services\Sms\Rapor;

use App\Domain\Sms\SmsTuru;

/**
 * Bir SMS sağlayıcısının rapor kaynağı (strateji). Yeni sağlayıcı = yeni sınıf + SmsRaporServisi
 * eşlemesi; ekran ve uçlar değişmez.
 *
 * Paket satırı (eski alan adları — Flutter sms_raporlari_tab ile aynı):
 *   {id, date, count, price, toplam_kredi, msgdetails, status}
 */
interface SmsRaporSaglayici
{
    /**
     * Türün paketleri, sayfalı.
     *
     * @param  array{page:int, per_page:int, ara:?string, sirala:?string, yon:string}  $liste
     * @return array{satirlar:array<int,array>, toplam:int}
     *
     * @throws SaglayiciHatasi sağlayıcıya ulaşılamadıysa ("rapor yok" ile karışmasın)
     */
    public function paketler(object $salon, SmsTuru $tur, array $liste): array;

    /**
     * Paketin alıcıları: [{telefon, operator, durum, iletim_tarihi}].
     *
     * @throws SaglayiciHatasi
     */
    public function alicilar(object $salon, string $paketId): array;

    /** Önbelleği boşalt ("Yenile" düğmesi). */
    public function unut(object $salon): void;
}
