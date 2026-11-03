<?php

namespace App\Http\Requests\BildirimReklam;

use App\Domain\BildirimReklam\HedefSegment;
use App\Domain\BildirimReklam\ReklamAksiyonu;
use App\Domain\BildirimReklam\ReklamDurumu;
use App\Domain\BildirimReklam\ReklamTuru;
use App\Http\Requests\V2Istegi;
use Illuminate\Validation\Rule;

/**
 * POST /bildirim-reklam · PATCH /bildirim-reklam/{id} — tam form (eski web formu ve Flutter v1
 * adminKaydet aynı düz alanları gönderir; adlar DEĞİŞMEDİ, geçiş günü istemci değişmeden çalışır).
 *
 * Eskiden farklı olarak doğrulanır: kupon aksiyonunda değer > 0 zorunlu, yüzde ≤ 100, segment
 * parametresi (kişi / hizmet / ürün) zorunlu, bitiş tarihi/saati başlangıçtan önce olamaz.
 * Boolean kanal alanları "1"/"0"/"true"/"on" biçimlerinin hepsini kabul eder.
 */
class BildirimReklamKaydetIstegi extends V2Istegi
{
    protected array $jsonAlanlar = ['salon_ids'];

    protected function alanKurallari(): array
    {
        $segment = fn (HedefSegment $s) => fn () => $this->input('hedef_kitle') === 'segment' && $this->input('segment_tip') === $s->value;

        return [
            'baslik'               => ['required', 'string', 'max:120'],
            'mesaj'                => ['nullable', 'string', 'max:300'],
            'gorsel'               => ['nullable', 'string', 'max:255'],
            'tur'                  => ['required', Rule::in(ReklamTuru::kodlar())],
            'kanal_push'           => ['required', 'boolean'],
            'kanal_inapp'          => ['required', 'boolean'],
            'tam_ekran'            => ['required', 'boolean'],
            'aksiyon_tipi'         => ['required', Rule::in(ReklamAksiyonu::kodlar())],
            'aksiyon_hedef'        => ['nullable', 'string', 'max:255'],

            'kupon_indirim_tipi'   => ['nullable', Rule::in(['yuzde', 'tutar'])],
            'kupon_deger'          => [Rule::requiredIf(fn () => ReklamAksiyonu::dbden($this->input('aksiyon_tipi'))->kuponZorunlu()), 'nullable', 'numeric', 'min:0',
                                       Rule::when(fn () => $this->input('kupon_indirim_tipi') !== 'tutar', ['max:100'])],
            'kupon_gecerli_tip'    => ['nullable', Rule::in(['hizmet', 'urun', 'tumu'])],
            'kupon_hizmet_id'      => ['nullable', 'integer', 'min:1'],
            'kupon_urun_id'        => ['nullable', 'integer', 'min:1'],
            'kupon_baslik'         => ['nullable', 'string', 'max:150'],
            'kupon_gecerlilik_gun' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'kupon_kisi_limit'     => ['nullable', 'integer', 'min:1', 'max:1000'],
            'kupon_toplam_adet'    => ['nullable', 'integer', 'min:0'],

            'randevu_tarih'        => ['nullable', 'date_format:Y-m-d'],
            'randevu_tarih_bit'    => ['nullable', 'date_format:Y-m-d', Rule::when(fn () => filled($this->input('randevu_tarih')), ['after_or_equal:randevu_tarih'])],
            'randevu_saat_bas'     => ['nullable', 'date_format:H:i'],
            'randevu_saat_bit'     => ['nullable', 'date_format:H:i', Rule::when(fn () => filled($this->input('randevu_saat_bas')), ['after:randevu_saat_bas'])],

            'hedef_kitle'          => ['required', Rule::in(['tumu', 'segment'])],
            'segment_tip'          => ['required_if:hedef_kitle,segment', 'nullable', Rule::in(HedefSegment::kodlar())],
            'segment_gun'          => ['nullable', 'integer', 'min:1', 'max:3650'],
            'segment_hizmet_id'    => [Rule::requiredIf($segment(HedefSegment::HIZMET)), 'nullable', 'integer', 'min:1'],
            'segment_urun_id'      => [Rule::requiredIf($segment(HedefSegment::URUN)), 'nullable', 'integer', 'min:1'],
            'segment_user_id'      => [Rule::requiredIf($segment(HedefSegment::KISI)), 'nullable', 'integer', 'min:1'],
            'segment_cinsiyet'     => ['nullable', 'integer', 'in:0,1'],

            // Formda 'bitti' seçeneği yalnız zaten bitmiş reklamı düzenlerken görünür; dört değer de geçerli.
            'durum'                => ['required', Rule::in(ReklamDurumu::kodlar())],
            'yayin_baslangic'      => ['nullable', 'date'],
            'yayin_bitis'          => ['nullable', 'date', Rule::when(fn () => filled($this->input('yayin_baslangic')), ['after:yayin_baslangic'])],
            'salon_ids'            => ['nullable', 'array'],
            'salon_ids.*'          => ['integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return [
            'baslik' => 'Başlık', 'mesaj' => 'Mesaj', 'tur' => 'Reklam türü', 'aksiyon_tipi' => 'Aksiyon',
            'kupon_deger' => 'Kupon değeri', 'kupon_gecerlilik_gun' => 'Geçerlilik (gün)', 'kupon_kisi_limit' => 'Kişi başı limit',
            'kupon_toplam_adet' => 'Toplam adet', 'randevu_tarih' => 'Başlangıç tarihi', 'randevu_tarih_bit' => 'Bitiş tarihi',
            'randevu_saat_bas' => 'Başlangıç saati', 'randevu_saat_bit' => 'Bitiş saati', 'hedef_kitle' => 'Hedef kitle',
            'segment_tip' => 'Segment', 'segment_hizmet_id' => 'Hizmet', 'segment_urun_id' => 'Ürün', 'segment_user_id' => 'Kişi',
            'segment_gun' => 'Gün', 'durum' => 'Durum', 'yayin_bitis' => 'Yayın bitişi', 'salon_ids' => 'Şubeler',
        ] + parent::attributes();
    }

    public function messages(): array
    {
        return [
            'kupon_deger.max'          => 'Yüzde indirim en fazla 100 olabilir.',
            'kupon_deger.required'     => 'Kupon aksiyonunda indirim değeri zorunlu.',
            'segment_user_id.required' => 'Push gidecek kişiyi seçiniz.',
        ] + parent::messages();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        // Eski form: boş alanlar "" gelir; seçilmemiş id'ler "" / "0"; varsayılanlar eski kaydet'le aynı.
        $bos = fn ($v) => $v === '' || $v === 'null' ? null : $v;
        $this->merge(array_map($bos, $this->only([
            'mesaj', 'gorsel', 'aksiyon_hedef', 'kupon_deger', 'kupon_baslik', 'kupon_gecerlilik_gun', 'kupon_kisi_limit',
            'kupon_toplam_adet', 'randevu_tarih', 'randevu_tarih_bit', 'randevu_saat_bas', 'randevu_saat_bit',
            'segment_gun', 'segment_cinsiyet', 'yayin_baslangic', 'yayin_bitis',
        ])));
        $secimYok = fn ($v) => $v === '0' || $v === 0 ? null : $bos($v);
        $this->merge(array_map($secimYok, $this->only(['kupon_hizmet_id', 'kupon_urun_id', 'segment_hizmet_id', 'segment_urun_id', 'segment_user_id'])));

        $this->merge([
            'tur'          => $this->input('tur') ?: ReklamTuru::KAMPANYA->value,
            'aksiyon_tipi' => $this->input('aksiyon_tipi') ?: ReklamAksiyonu::KUPON->value,
            'hedef_kitle'  => $this->input('hedef_kitle') === 'segment' ? 'segment' : 'tumu',
            'durum'        => $this->input('durum') ?: ReklamDurumu::TASLAK->value,
            'kanal_push'   => $this->bayrak('kanal_push'),
            'kanal_inapp'  => $this->bayrak('kanal_inapp'),
            'tam_ekran'    => $this->bayrak('tam_ekran'),
        ]);
        foreach (['randevu_saat_bas', 'randevu_saat_bit'] as $s) {
            if (is_string($this->input($s))) {
                $this->merge([$s => substr($this->input($s), 0, 5)]);
            }
        }
    }

    /** Eski web "1"/"0", Flutter true/false, HTML checkbox "on"; gelmediyse false. */
    private function bayrak(string $alan): bool
    {
        return filter_var($this->input($alan, false), FILTER_VALIDATE_BOOLEAN);
    }

    public function after(): array
    {
        return [function ($v) {
            if (! $this->bayrak('kanal_push') && ! $this->bayrak('kanal_inapp') && ! $this->bayrak('tam_ekran')) {
                $v->errors()->add('kanal_push', 'En az bir kanal (Push / Uygulama içi / Tam ekran) seçili olmalı.');
            }
        }];
    }
}
