<?php

namespace App\Domain\Kampanya;

use App\Exceptions\GecersizIslemException;

/**
 * Kampanyanın bağlı olduğu hizmet / ürün / paket — "hizmet-12" · "urun-3" · "paket-5"
 * (eski sihirbaz + Flutter hizmetUrunPaket değeri; çıplak sayı eski kodda pakettir).
 * Kitleyi DARALTMAZ; kuponun hangi kaleme uygulanacağını ve {ürün_hizmet_paket} metnini belirler.
 */
final class KalemSecimi
{
    public const TURLER = ['hizmet' => 'hizmet_id', 'urun' => 'urun_id', 'paket' => 'paket_id'];

    private function __construct(public readonly string $tur, public readonly int $id) {}

    public static function coz(?string $deger): ?self
    {
        $deger = trim((string) $deger);
        if ($deger === '') {
            return null;
        }
        if (ctype_digit($deger)) {
            return new self('paket', (int) $deger);
        }
        if (! preg_match('/^(hizmet|urun|paket)-(\d+)$/', $deger, $m) || (int) $m[2] < 1) {
            throw new GecersizIslemException('Geçersiz hizmet / ürün / paket seçimi');
        }

        return new self($m[1], (int) $m[2]);
    }

    /** Kayıttaki *_id kolonlarından (öncelik eski kampanyaDuzenleGetir ile aynı: hizmet > ürün > paket). */
    public static function kayittan(object $k): ?self
    {
        foreach (self::TURLER as $tur => $kolon) {
            if (! empty($k->{$kolon})) {
                return new self($tur, (int) $k->{$kolon});
            }
        }

        return null;
    }

    public function deger(): string
    {
        return $this->tur.'-'.$this->id;
    }

    /**
     * Üç id kolonunun TAMAMI (seçili olan dolu, diğerleri NULL) — eski web düzenlemede
     * eski id'leri bırakıyordu (kupon yanlış kaleme düşüyordu).
     *
     * @return array{hizmet_id:?int, urun_id:?int, paket_id:?int}
     */
    public static function kolonlar(?self $s): array
    {
        $k = array_fill_keys(array_values(self::TURLER), null);
        if ($s) {
            $k[self::TURLER[$s->tur]] = $s->id;
        }

        return $k;
    }
}
