<?php

namespace App\Domain\Satis;

/**
 * Satış Takibi satırının GÖSTERİM durumu + izinli işlemleri — SAF (DB yok, test edilebilir).
 *
 * Web ve Flutter bu kararları YENİDEN HESAPLAMAZ; API her satırda `kapali` ve
 * `aksiyonlar` olarak hazır gönderir (tek kaynak).
 *
 * Eski web listesi (StoreAdminController@adisyon_yukle) kuralları:
 *   - kapalı: stüdyoda adisyonlar.odendi=1, diğerlerinde kalan <= 0
 *   - stüdyo: Ödeme Alındı (açık) / Ödemeyi Geri Al (kapalı)
 *   - diğer : Tahsil Et (açık) / Ödeme Bilgileri (kapalı)
 *   - Düzenle her satırda; Fatura yalnız hesap sahibi + stüdyo değil; Sil satis.adisyon_sil
 * Tahsilat yazan butonlar (Tahsil Et, stüdyo ödeme) yalnız satis.tahsilat_al varsa
 * gösterilir — ilgili uç bu yetkiyi şart koşar (görünüp 403 veren buton olmasın).
 *
 * @param array{kalan_tutar_numeric?:float|int,odendi?:int|string} $satir
 * @param array{tahsilat_al?:bool,adisyon_sil?:bool,fatura_isaretle?:bool} $yetkiler
 */
final class SatirAksiyon
{
    public static function hesapla(array $satir, array $yetkiler, bool $studyo): array
    {
        $kapali = $studyo
            ? (int) ($satir['odendi'] ?? 0) === 1
            : (float) ($satir['kalan_tutar_numeric'] ?? 0) <= 0;
        $tahsilat = ! empty($yetkiler['tahsilat_al']);

        return [
            'kapali'     => $kapali,
            'aksiyonlar' => [
                'tahsil_et'     => ! $studyo && ! $kapali && $tahsilat,
                'odeme_bilgisi' => ! $studyo && $kapali,
                'odeme_al'      => $studyo && ! $kapali && $tahsilat,
                'odeme_geri_al' => $studyo && $kapali && $tahsilat,
                'duzenle'       => true,
                'fatura'        => ! $studyo && ! empty($yetkiler['fatura_isaretle']),
                'sil'           => ! empty($yetkiler['adisyon_sil']),
            ],
        ];
    }
}
