<?php

namespace App\Services\BildirimKuyrugu;

/**
 * Bir outbox turunun isleyicisi (v2_bildirim_kuyrugu). Sinif config/bildirim_kuyrugu.php 'harita'dan container ile
 * kurulur; bagimliliklar constructor'dan gelir.
 *
 * Sozlesme (eski V2BildirimIsle ile ayni):
 *   - normal donus   : kayit durum=1 olur (sessiz atlamalar dahil; eskide Log::warning + return olanlar boyle).
 *   - istisna        : deneme+1, 3. denemede durum=2, oncesinde durum=0 (yeniden denenir); mesaj 'hata' kolonuna yazilir.
 * Eskinin her turdeki "exception mu, log + return mu" secimi aynen korunur.
 */
interface BildirimIsleyici
{
    /**
     * @param  object  $kayit  kuyruk satiri: id, salon_id, tur, randevu_id, yetkili_id, payload, durum, deneme
     * @param  array  $payload  cozulmus payload; bos/bozuk JSON icin []
     *
     * @throws \Throwable yeniden denenecekse
     */
    public function isle(object $kayit, array $payload): void;
}
