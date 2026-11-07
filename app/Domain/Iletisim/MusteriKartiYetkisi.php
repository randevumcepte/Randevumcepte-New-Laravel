<?php

namespace App\Domain\Iletisim;

/**
 * Müşteri kartı ve takvim randevu kartındaki iletişim işlemlerinin (WhatsApp mesajı, hazır bağlantılar,
 * hızlı anket) yetki kuralı: kartı açabilen personel bu işlemleri de yapar. Salon kapsamı ayrıca denetlenir.
 * `pazarlama.*` anahtarları varsayılan personel şablonunda kapalıdır; yönetim sayfalarının kuralı değişmez.
 */
final class MusteriKartiYetkisi
{
    /** @var list<string> OR: biri yeterli */
    public const ANAHTARLAR = ['musteri.liste_gor', 'randevu.takvim_gor'];
}
