<?php

namespace App\Domain\Kampanya;

use App\Exceptions\GecersizIslemException;

/**
 * Kampanya İNDİRİMİ — değer nesnesi (saf). Kayıtta TEK metin kolonu olarak durur
 * (kampanya_yonetimi.indirim_turu): "%10 İndirim" ya da "2 Al 1 Öde". Kupon kullanımı
 * (eski kampanyaKodKullanApi) yüzdeyi bu metinden /%\s*(\d+)/ ile okur — biçim KORUNUR.
 *
 * Eski farklar (v2'de düzeltildi):
 *   - {indirim} yer tutucusu X-Al-Y-Öde'de BOŞ kalıyordu → artık "2 Al 1 Öde".
 *   - Sistem şablonlarındaki {indirim_veya_x_al_y_ode} hiç çözülmüyordu → "%10 indirim" / "2 Al 1 Öde".
 */
final class Indirim
{
    public const YUZDE = 'yuzde';

    public const XAL_YODE = 'xalyode';

    /** Sihirbaz varsayılanları (eski form: %10 · 2 al 1 öde). */
    public const VARSAYILAN = ['tur' => self::YUZDE, 'yuzde' => 10, 'xal' => 2, 'yode' => 1];

    private function __construct(
        public readonly string $tur,
        public readonly int $yuzde,
        public readonly int $xal,
        public readonly int $yode,
    ) {}

    public static function olustur(string $tur, ?int $yuzde, ?int $xal, ?int $yode): self
    {
        if ($tur === self::YUZDE) {
            $y = $yuzde ?? 0;
            if ($y < 1 || $y > 100) {
                throw new GecersizIslemException('İndirim oranı 1 ile 100 arasında olmalı');
            }

            return new self(self::YUZDE, $y, 0, 0);
        }
        if ($tur === self::XAL_YODE) {
            $x = $xal ?? 0;
            $o = $yode ?? 0;
            if ($x < 2 || $o < 1 || $o >= $x) {
                throw new GecersizIslemException('"X Al Y Öde" için X, Y\'den büyük olmalı (ör. 2 Al 1 Öde)');
            }

            return new self(self::XAL_YODE, 0, $x, $o);
        }
        throw new GecersizIslemException('Geçersiz indirim türü');
    }

    /** İstek alanlarından (indirim_turu, yuzde, xal, yode); eksik alan sihirbaz varsayılanı. */
    public static function girdiden(array $v): self
    {
        $d = self::VARSAYILAN;
        $sayi = fn (string $a) => isset($v[$a]) && $v[$a] !== '' ? (int) $v[$a] : $d[$a];

        return self::olustur($v['indirim_turu'] ?? $d['tur'], $sayi('yuzde'), $sayi('xal'), $sayi('yode'));
    }

    /** Kayıttaki metinden (eski kampanyaDuzenleGetir regex'leri); çözülemezse null. */
    public static function metinden(?string $metin): ?self
    {
        $metin = (string) $metin;
        if (preg_match('/%\s*(\d+)/u', $metin, $m)) {
            return new self(self::YUZDE, (int) $m[1], 0, 0);
        }
        if (preg_match('/(\d+)\s*Al\s*(\d+)\s*Öde/iu', $metin, $m)) {
            return new self(self::XAL_YODE, 0, (int) $m[1], (int) $m[2]);
        }

        return null;
    }

    /** kampanya_yonetimi.indirim_turu (eski biçim BİREBİR). */
    public function metin(): string
    {
        return $this->tur === self::YUZDE ? '%'.$this->yuzde.' İndirim' : $this->xal.' Al '.$this->yode.' Öde';
    }

    /** {indirim} yer tutucusu: yüzdede sayı (şablonlar "%{indirim}" yazar), X-Al-Y-Öde'de metin. */
    public function yerTutucu(): string
    {
        return $this->tur === self::YUZDE ? (string) $this->yuzde : $this->metin();
    }

    /** {indirim_veya_x_al_y_ode} yer tutucusu. */
    public function uzunYerTutucu(): string
    {
        return $this->tur === self::YUZDE ? '%'.$this->yuzde.' indirim' : $this->metin();
    }

    /** @return array{tur:string, yuzde_mi:bool, yuzde:?int, xal:?int, yode:?int, metin:string} */
    public function sunum(): array
    {
        $y = $this->tur === self::YUZDE;

        return [
            'tur' => $this->tur, 'yuzde_mi' => $y,
            'yuzde' => $y ? $this->yuzde : null, 'xal' => $y ? null : $this->xal, 'yode' => $y ? null : $this->yode,
            'metin' => $this->metin(),
        ];
    }
}
