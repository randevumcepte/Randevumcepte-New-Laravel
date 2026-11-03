<?php

namespace App\Domain\Aktivite;

/**
 * Log hareketinin KATEGORİSİ (renk/ikon grubu) — web, Flutter ve filtre için TEK sözlük.
 *
 * Kategori DB'de tutulmaz; işlem kodundan (salon_aktivite_log.action) türetilir. Kural eski
 * log-hareketleri.blade ile BİREBİR (sıra önemli: 'randevu_iptal_sil' → Silme). Katalogda
 * açıkça kategori verilmiş işlemler bu kuralı ezer (HareketKatalogu::kategori).
 *
 * Renkler hex (Flutter Color(0xFF…) ve web inline stil aynı değeri kullanır); ikon web için
 * bootstrap-icons adı — Flutter `kod` üzerinden kendi ikonunu eşler.
 */
enum HareketKategorisi: string
{
    case EKLEME = 'ekleme';
    case GUNCELLEME = 'guncelleme';
    case SILME = 'silme';
    case IPTAL = 'iptal';
    case ODEME = 'odeme';
    case GONDERIM = 'gonderim';
    case GIRIS = 'giris';
    case DIGER = 'diger';

    /** Anahtar kelime kuralları — İLK eşleşen kazanır (eski blade sırası). */
    private const KURALLAR = [
        'silme'      => ['sil', 'kaldir'],
        'iptal'      => ['iptal'],
        'ekleme'     => ['ekle', 'olustur', 'kaydet', 'yeni'],
        'guncelleme' => ['guncelle', 'duzenle', 'degistir'],
        'odeme'      => ['onay', 'odeme', 'tahsilat'],
        'gonderim'   => ['gonder', 'yolla', 'sms'],
        'giris'      => ['giris'],
    ];

    /** İşlem kodundan anahtar kelime kuralıyla kategori (katalog override'ı HareketKatalogu'nda). */
    public static function anahtarKelimeden(string $islem): self
    {
        $islem = strtolower($islem);
        if ($islem === 'login' || $islem === 'logout') {
            return self::GIRIS;
        }
        foreach (self::KURALLAR as $kategori => $kelimeler) {
            foreach ($kelimeler as $k) {
                if (str_contains($islem, $k)) {
                    return self::from($kategori);
                }
            }
        }

        return self::DIGER;
    }

    /** İstek / filtre kodundan; geçersiz veya boşsa null. */
    public static function koddan(?string $kod): ?self
    {
        return $kod === null || $kod === '' ? null : self::tryFrom($kod);
    }

    public function etiket(): string
    {
        return match ($this) {
            self::EKLEME     => 'Ekleme',
            self::GUNCELLEME => 'Güncelleme',
            self::SILME      => 'Silme',
            self::IPTAL      => 'İptal',
            self::ODEME      => 'Ödeme / Onay',
            self::GONDERIM   => 'Gönderim',
            self::GIRIS      => 'Giriş',
            self::DIGER      => 'Diğer',
        };
    }

    /** Ön plan rengi (hex). */
    public function renk(): string
    {
        return match ($this) {
            self::EKLEME     => '#059669',
            self::GUNCELLEME => '#d97706',
            self::SILME      => '#ef4444',
            self::IPTAL      => '#dc2626',
            self::ODEME      => '#0d9488',
            self::GONDERIM   => '#2563eb',
            self::GIRIS      => '#4f46e5',
            self::DIGER      => '#7c3aed',
        };
    }

    /** Açık zemin rengi (hex) — rozet / satır vurgusu. */
    public function zemin(): string
    {
        return match ($this) {
            self::EKLEME     => '#ecfdf5',
            self::GUNCELLEME => '#fffbeb',
            self::SILME, self::IPTAL => '#fef2f2',
            self::ODEME      => '#f0fdfa',
            self::GONDERIM   => '#eff6ff',
            self::GIRIS      => '#eef2ff',
            self::DIGER      => '#f5f3ff',
        };
    }

    /** bootstrap-icons sınıfı (web). */
    public function ikon(): string
    {
        return match ($this) {
            self::EKLEME     => 'bi-plus-circle-fill',
            self::GUNCELLEME => 'bi-pencil-square',
            self::SILME      => 'bi-trash3-fill',
            self::IPTAL      => 'bi-x-circle-fill',
            self::ODEME      => 'bi-cash-coin',
            self::GONDERIM   => 'bi-send-fill',
            self::GIRIS      => 'bi-box-arrow-in-right',
            self::DIGER      => 'bi-lightning-charge-fill',
        };
    }

    /** @return array{kod:string, etiket:string, renk:string, zemin:string, ikon:string} */
    public function dizi(): array
    {
        return ['kod' => $this->value, 'etiket' => $this->etiket(), 'renk' => $this->renk(), 'zemin' => $this->zemin(), 'ikon' => $this->ikon()];
    }
}
