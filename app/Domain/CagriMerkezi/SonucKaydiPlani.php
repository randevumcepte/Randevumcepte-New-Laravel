<?php

namespace App\Domain\CagriMerkezi;

/**
 * "Sonucu Kaydet" kararı — eski StoreAdminController@santral_not_ekle'nin durum / zaman /
 * hatırlatma bayrağı mantığı, SAF hâliyle (DB'siz; saat dışarıdan). Servis yalnız bu planı yazar.
 *
 * Nihai durum önceliği:
 *   1) Agent'ın AÇIK seçtiği sonuç (0,2,4,5,6,7) — tarih/saat verilse bile korunur
 *   2) Tarih + saat verildiyse → 3 Tekrar Aranacak (arama randevusu)
 *   3) Hiç aranmamışsa → 4 Görüşüldü (yalnız not eklendi); aksi halde mevcut durum
 *
 * Zaman: yeni zaman verildiyse yazılır; kayıtta zaman yoksa temizlenir; kayıtta zaman VARSA ve
 * yeni zaman verilmediyse KORUNUR — takvimdeki kayıt (3/6/7) sonuç işaretlenince takvimden
 * düşmez, `ar_tamamlandi_at` ile "tamamlandı" görünür (geç/zamanında raporu bunu kullanır).
 * Boş not mevcut notu EZMEZ (takvimden ön görüşme/satış işaretlenirken not boş gelir).
 */
final class SonucKaydiPlani
{
    /**
     * @param array{durum:?int, tarih:?string, saat:?string, ar_tamamlandi_at:?string} $kayit
     * @param array{sonuc:?int, not:?string, tarih:?string, saat:?string, satis_tutari:?float} $istek
     */
    private function __construct(
        public readonly int $durum,
        public readonly bool $yeniAramaRandevusu,
        public readonly ?float $satisTutari,
        /** @var array<string,mixed> aranacak_musteriler'e yazılacak kolonlar */
        public readonly array $kolonlar,
    ) {}

    /** İstek hiçbir şey taşımıyor mu? (sonuç yok, zaman yok, not yok → kaydedilecek şey yok) */
    public static function bosMu(array $istek): bool
    {
        return self::gecerliSonuc($istek['sonuc'] ?? null) === null
            && ! self::zamanVar($istek)
            && trim((string) ($istek['not'] ?? '')) === '';
    }

    public static function olustur(array $kayit, array $istek, string $simdi): self
    {
        $mevcut = isset($kayit['durum']) && $kayit['durum'] !== null ? (int) $kayit['durum'] : null;
        $sonuc = self::gecerliSonuc($istek['sonuc'] ?? null);
        $zamanVar = self::zamanVar($istek);

        $durum = match (true) {
            $sonuc !== null => $sonuc,
            $zamanVar => AramaSonucu::TEKRAR_ARANACAK->value,
            $mevcut === null => AramaSonucu::GORUSULDU->value,
            default => $mevcut,
        };

        $kayittaZaman = ! empty($kayit['tarih']) && ! empty($kayit['saat']);
        $tamamlanmamis = empty($kayit['ar_tamamlandi_at']);
        $eskiTakvimKaydi = $mevcut !== null && (AramaSonucu::tryFrom($mevcut)?->takvimde() ?? false) && $kayittaZaman && $tamamlanmamis;
        $yeniArama = $durum === AramaSonucu::TEKRAR_ARANACAK->value && $zamanVar;

        $k = ['durum' => $durum, 'son_arama_zamani' => $simdi];
        $not = trim((string) ($istek['not'] ?? ''));
        if ($not !== '') {
            $k['musteri_not'] = (string) $istek['not'];
        }
        if ($zamanVar) {
            $k['tarih'] = $istek['tarih'];
            $k['saat'] = $istek['saat'];
        } elseif (! $kayittaZaman) {
            $k['tarih'] = null;
            $k['saat'] = null;
        }

        if ($yeniArama) {
            // Yeni / yeniden planlanan callback → hatırlatma döngüsü baştan.
            $k += ['ar_5dk_at' => null, 'ar_zaman_at' => null, 'ar_gecikti' => 0, 'ar_tamamlandi_at' => null];
        } elseif ($eskiTakvimKaydi && ! (AramaSonucu::tryFrom($durum)?->takvimde() ?? false)) {
            // Takvimdeki kayıt görüşme sonucuyla tamamlandı → takvimde "tamamlandı" olarak kalır.
            $k += ['ar_tamamlandi_at' => $simdi, 'ar_gecikti' => 0];
        }

        $tutar = $durum === AramaSonucu::TELEFONDA_SATIS->value && isset($istek['satis_tutari']) && $istek['satis_tutari'] !== null
            ? round((float) $istek['satis_tutari'], 2) : null;

        return new self($durum, $yeniArama, $tutar, $k);
    }

    /**
     * Aramayı tamamlayan sonuç işareti (randevu verildi / oluşturuldu 8, iptal 9) — bekleyen
     * hatırlatmayı susturur. `ar_tamamlandi_at` yalnız henüz tamamlanmamışsa basılır (ilk zaman korunur).
     * $damgala = false → takvim damgasına dokunulmaz (bekleyen arama randevusu olmayan kayıt).
     *
     * @return array<string,mixed>
     */
    public static function tamamla(AramaSonucu $sonuc, ?string $tamamlandiAt, string $simdi, bool $damgala = true): array
    {
        $k = ['durum' => $sonuc->value];
        if ($damgala) {
            $k += ['ar_gecikti' => 0, 'ar_tamamlandi_at' => $tamamlandiAt ?: $simdi];
        }
        if ($sonuc !== AramaSonucu::IPTAL) {
            $k['son_arama_zamani'] = $simdi;
        }

        return $k;
    }

    /** Arama başlatıldı: bekleyen arama randevusu "arandı" olur (popup susar), zaman korunur. */
    public static function aramaBasladi(?int $durum, string $simdi): array
    {
        $k = ['son_arama_zamani' => $simdi];
        if ($durum === AramaSonucu::TEKRAR_ARANACAK->value) {
            $k += ['durum' => AramaSonucu::ARANDI->value, 'ar_tamamlandi_at' => $simdi, 'ar_gecikti' => 0];
        }

        return $k;
    }

    /** Yeni arama randevusu / erteleme kolonları (durum 3 + döngü sıfır). */
    public static function aramaRandevusu(string $tarih, string $saat): array
    {
        return ['durum' => AramaSonucu::TEKRAR_ARANACAK->value, 'tarih' => $tarih, 'saat' => $saat,
            'ar_5dk_at' => null, 'ar_zaman_at' => null, 'ar_gecikti' => 0, 'ar_tamamlandi_at' => null];
    }

    private static function gecerliSonuc(mixed $sonuc): ?int
    {
        $s = AramaSonucu::koddan($sonuc);

        return $s !== null && $s->agentSecebilir() ? $s->value : null;
    }

    private static function zamanVar(array $istek): bool
    {
        return ! empty($istek['tarih']) && ! empty($istek['saat']);
    }
}
