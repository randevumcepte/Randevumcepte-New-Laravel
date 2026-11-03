<?php

namespace App\Domain\Form;

/**
 * Eski "sabit blade şablonlu" formların (formtaslaklari id 9-13) özel kuralları —
 * SAF, TEK KAYNAK. Eskide bu id'ler modal JS'i, StoreAdminController ve
 * ApiController içinde ayrı ayrı sabit yazılmıştı.
 *
 *   - Özel doldurma sayfası: 9→musteriformdoldurma2 … 13→musteriformdoldurma6
 *   - Gönderim modalı ek alanları:
 *       9, 10 (Gelin Başı / Riskli Saç sözleşmeleri): Toplam Ücret + Kapora ZORUNLU
 *       12: Hizmet + Toplam Ücret (zorunlu) + Kapora
 */
final class SabitFormlar
{
    private const SAYFA = [
        9  => 'musteriformdoldurma2',
        10 => 'musteriformdoldurma3',
        11 => 'musteriformdoldurma4',
        12 => 'musteriformdoldurma5',
        13 => 'musteriformdoldurma6',
    ];

    /** Formun özel doldurma sayfası; yoksa null (genel /musteriformdoldurma kullanılır). */
    public static function sayfa(int $formId): ?string
    {
        return self::SAYFA[$formId] ?? null;
    }

    /**
     * Gönderim modalında gösterilecek ek alanlar.
     *
     * @return array{hizmet:bool,ucret:bool,toplam_zorunlu:bool,kapora_zorunlu:bool}
     */
    public static function ekAlanlar(int $formId): array
    {
        return match ($formId) {
            9, 10   => ['hizmet' => false, 'ucret' => true, 'toplam_zorunlu' => true, 'kapora_zorunlu' => true],
            12      => ['hizmet' => true, 'ucret' => true, 'toplam_zorunlu' => true, 'kapora_zorunlu' => false],
            default => ['hizmet' => false, 'ucret' => false, 'toplam_zorunlu' => false, 'kapora_zorunlu' => false],
        };
    }

    /**
     * Ek alan doğrulaması (eski modal JS'iyle aynı mesajlar). Hata yoksa null.
     */
    public static function ucretHatasi(int $formId, ?float $toplam, ?float $kapora): ?string
    {
        $k = self::ekAlanlar($formId);
        if ($k['kapora_zorunlu'] && ($toplam === null || $kapora === null)) {
            return 'Gelin Başı ve Riskli Saç sözleşmeleri için Toplam Ücret ve Kapora alanları zorunludur!';
        }
        if ($k['toplam_zorunlu'] && $toplam === null) {
            return 'Toplam ücret zorunludur.';
        }
        if ($toplam !== null && $kapora !== null && $kapora > $toplam) {
            return 'Kapora miktarı toplam ücretten fazla olamaz!';
        }

        return null;
    }
}
