<?php

namespace App\Domain\BildirimReklam;

use Carbon\CarbonInterface;

/**
 * Reklamın verdiği indirim kuponu — kural TEK yerde (saf, DB'siz):
 *   - kupon verir mi   : aksiyon kupon/randevu VE değer > 0 (eski liste + kuponKap ile aynı)
 *   - başlık           : özel başlık yoksa "%20 İndirim" / "150 ₺ İndirim" (eski kuponKap)
 *   - kısa metin       : kart rozeti "%20" / "150 ₺" (eski blade)
 *   - geçerlilik tarihi: kap anından itibaren N gün; boş = süresiz
 *   - kapsam           : ürün id doluysa ürün kuponu, değilse hizmet kuponu (hizmet null = tüm hizmetler)
 */
final class ReklamKuponu
{
    public function __construct(
        public readonly ReklamAksiyonu $aksiyon,
        public readonly string $indirimTipi,   // 'yuzde' | 'tutar'
        public readonly ?float $deger,
        public readonly ?string $ozelBaslik,
        public readonly ?int $gecerlilikGun,
        public readonly ?int $hizmetId,
        public readonly ?int $urunId,
    ) {}

    /** bildirim_reklamlari satırından. */
    public static function satirdan(object $r): self
    {
        return new self(
            ReklamAksiyonu::dbden($r->aksiyon_tipi ?? null),
            ($r->kupon_indirim_tipi ?? null) === 'tutar' ? 'tutar' : 'yuzde',
            isset($r->kupon_deger) && $r->kupon_deger !== null ? (float) $r->kupon_deger : null,
            ($r->kupon_baslik ?? null) ?: null,
            isset($r->kupon_gecerlilik_gun) && $r->kupon_gecerlilik_gun !== null ? (int) $r->kupon_gecerlilik_gun : null,
            ! empty($r->kupon_hizmet_id) ? (int) $r->kupon_hizmet_id : null,
            ! empty($r->kupon_urun_id) ? (int) $r->kupon_urun_id : null,
        );
    }

    public function verirMi(): bool
    {
        return $this->aksiyon->kuponAlaniVar() && $this->deger !== null && $this->deger > 0;
    }

    public function urunKuponuMu(): bool
    {
        return $this->urunId !== null;
    }

    /** "%20" | "150 ₺" | "150,50 ₺" */
    public function kisaMetin(): string
    {
        if ($this->indirimTipi === 'tutar') {
            return rtrim(rtrim(number_format((float) $this->deger, 2, ',', '.'), '0'), ',').' ₺';
        }

        return '%'.(int) $this->deger;
    }

    /** Müşteriye tanımlanan kuponun başlığı. */
    public function baslik(): string
    {
        if ($this->ozelBaslik) {
            return $this->ozelBaslik;
        }

        return $this->indirimTipi === 'tutar' ? $this->kisaMetin().' İndirim' : ((int) $this->deger).'% İndirim';
    }

    /** Kap anından geçerlilik bitiş tarihi (Y-m-d) ya da null (süresiz). */
    public function gecerlilikTarihi(CarbonInterface $simdi): ?string
    {
        return $this->gecerlilikGun ? $simdi->copy()->addDays($this->gecerlilikGun)->toDateString() : null;
    }

    /** "Kupon ne için" seçimi (form): urun | hizmet | tumu. */
    public function gecerliTip(): string
    {
        return $this->urunId ? 'urun' : ($this->hizmetId ? 'hizmet' : 'tumu');
    }
}
