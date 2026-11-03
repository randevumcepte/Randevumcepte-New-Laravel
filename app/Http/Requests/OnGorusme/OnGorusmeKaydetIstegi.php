<?php

namespace App\Http\Requests\OnGorusme;

use App\Domain\OnGorusme\GorusmeNedeni;
use App\Http\Requests\V2Istegi;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * POST /ongorusme · PATCH /ongorusme/{id} — tam form (iki kanal da formun tamamını gönderir).
 *
 * Eski istemci alan adları da kabul edilir (geçiş günü Flutter / eski web değişmeden çalışsın):
 *   gorusmeyi_yapan → personel_id · randevu_tarihi | ongorusme_tarihi → tarih ·
 *   randevu_saati | ongorusme_saati → saat · sehir → il_id
 * Neden üç biçimden biriyle gelir (GorusmeNedeni::istekten): neden_tur + neden_id (v2),
 * paket_id | urun_id | hizmet_id (mobil v1), paket_urun "12" | "urun-5" | "hizmet-3" (eski web).
 */
class OnGorusmeKaydetIstegi extends V2Istegi
{
    protected function alanKurallari(): array
    {
        return [
            'musteri_id'         => ['nullable', 'integer', 'min:1'],
            'ad_soyad'           => ['required', 'string', 'max:255'],
            'telefon'            => ['required_without:musteri_id', 'nullable', 'string', 'max:30'],
            'email'              => ['nullable', 'email', 'max:255'],
            'cinsiyet'           => ['nullable', 'integer', 'in:0,1'],
            'musteri_tipi'       => ['nullable', 'integer', 'min:0', 'max:5'],
            'adres'              => ['nullable', 'string', 'max:1000'],
            'meslek'             => ['nullable', 'string', 'max:255'],
            'il_id'              => ['nullable', 'integer', 'min:1'],
            'aciklama'           => ['nullable', 'string', 'max:2000'],
            'neden_tur'          => ['required', Rule::in(GorusmeNedeni::turler())],
            'neden_id'           => ['required', 'integer', 'min:1'],
            'tarih'              => ['required', 'date_format:Y-m-d'],
            'saat'               => ['required', 'date_format:H:i'],
            'personel_id'        => ['required', 'integer', 'min:1'],
            'oda_id'             => ['nullable', 'integer', 'min:1'],
            'cakisanrandevuekle' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'ad_soyad' => 'Ad soyad', 'telefon' => 'Telefon', 'musteri_tipi' => 'Referans', 'aciklama' => 'Açıklama',
            'neden_tur' => 'Ön görüşme sebebi', 'neden_id' => 'Ön görüşme sebebi', 'tarih' => 'Tarih', 'saat' => 'Saat',
            'personel_id' => 'Görüşmeyi yapan', 'oda_id' => 'Oda', 'musteri_id' => 'Müşteri',
        ] + parent::attributes();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->eskiAdlar([
            'gorusmeyi_yapan' => 'personel_id', 'randevu_tarihi' => 'tarih', 'ongorusme_tarihi' => 'tarih',
            'randevu_saati' => 'saat', 'ongorusme_saati' => 'saat', 'sehir' => 'il_id',
        ]);

        // Boş / "null" gönderilen alanlar seçilmemiş; seçimlerde 0 da seçilmemiş (musteri_tipi 0 = eski
        // "Yok"). cinsiyet 0 = Kadın olduğundan yalnız boş / "null" temizlenir.
        $bos = fn ($v) => $v === '' || $v === 'null' ? null : $v;
        $secimYok = fn ($v) => $v === '0' || $v === 0 ? null : $bos($v);
        $this->merge(array_map($secimYok, $this->only(['musteri_id', 'oda_id', 'il_id', 'musteri_tipi'])));
        $this->merge(array_map($bos, $this->only(['cinsiyet', 'email', 'telefon'])));

        try {
            if ($neden = GorusmeNedeni::istekten($this->all())) {
                $this->merge(['neden_tur' => $neden->tur, 'neden_id' => $neden->id]);
            }
        } catch (InvalidArgumentException) {
            // Geçersiz neden → neden_tur / neden_id doğrulama kuralı Türkçe hatayı verir.
        }

        $this->merge(array_filter([
            'tarih' => self::tarihNormal($this->input('tarih')),
            'saat'  => is_string($this->input('saat')) ? substr($this->input('saat'), 0, 5) : null,
        ], fn ($v) => $v !== null));
    }

    /** "03.11.2026" / "03-11-2026" / "03/11/2026" → "2026-11-03"; diğerleri olduğu gibi. */
    private static function tarihNormal(mixed $t): ?string
    {
        if (! is_string($t)) {
            return null;
        }

        return preg_match('/^(\d{2})[.\-\/](\d{2})[.\-\/](\d{4})$/', trim($t), $m) ? "{$m[3]}-{$m[2]}-{$m[1]}" : substr(trim($t), 0, 10);
    }
}
