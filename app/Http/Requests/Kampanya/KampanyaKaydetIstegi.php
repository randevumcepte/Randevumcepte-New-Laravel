<?php

namespace App\Http\Requests\Kampanya;

use App\Http\Requests\V2Istegi;

/**
 * POST /kampanya · PATCH /kampanya/{id} — kampanya sihirbazı.
 * Eski web / Flutter v1 alan adları kabul edilir (gelenGelmeyenMusteri, katilimciTuru, hizmetUrunPaket,
 * seciliSablonId, kampanya_sms, kampanyaKodu, indirimTuru, Xal/Yode/kampanyaIndirim, asistan_tarih,
 * kampanyaGecerlilikTarihi, asistan_saat, hedefDegisti). PATCH kısmidir.
 *
 * Mesaj: `mesaj` (yer tutuculu ham metin) verilirse o kullanılır, yoksa `sablon_id`'den türetilir.
 * v1 istemcisi `kampanya_sms`'e ÖRNEK müşteriyle çözülmüş önizlemeyi koyuyordu — şablon da seçiliyse
 * bu alan yok sayılır (eski web davranışı: şablon kazanır).
 */
class KampanyaKaydetIstegi extends V2Istegi
{
    protected bool $guncellemedeKismi = true;

    protected function alanKurallari(): array
    {
        return [
            'gorev_turu'        => ['required', 'integer', 'in:1,2,3,4'],
            'ad'                => ['nullable', 'string', 'max:255'],
            'hedef_filtre'      => ['nullable', 'string', 'in:1,2,3,4,5,6,7,8'],
            'hedef_grup'        => ['nullable', 'string', 'max:40'],
            'hedef_cinsiyet'    => ['nullable', 'string', 'in:kadinlar,erkekler'],
            'hedef_yenile'      => ['nullable', 'boolean'],
            'hizmet_urun_paket' => ['nullable', 'string', 'max:40'],
            'sablon_id'         => ['nullable', 'string', 'max:40'],
            'mesaj'             => ['nullable', 'string', 'max:2000'],
            'indirim_kodu'      => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9]+$/'],
            'indirim_turu'      => ['nullable', 'string', 'in:yuzde,xalyode'],
            'yuzde'             => ['nullable', 'integer', 'min:1', 'max:100'],
            'xal'               => ['nullable', 'integer', 'min:2', 'max:99'],
            'yode'              => ['nullable', 'integer', 'min:1', 'max:98'],
            'baslangic_tarihi'  => ['required', 'date_format:Y-m-d'],
            'bitis_tarihi'      => ['required', 'date_format:Y-m-d'],
            'gonderim_saati'    => ['required', 'date_format:H:i'],
        ];
    }

    public function attributes(): array
    {
        return [
            'gorev_turu' => 'Kanal', 'hedef_filtre' => 'Hedef kitle', 'hedef_cinsiyet' => 'Cinsiyet', 'mesaj' => 'Mesaj',
            'indirim_kodu' => 'İndirim kodu', 'yuzde' => 'İndirim oranı', 'xal' => 'Alınan adet', 'yode' => 'Ödenen adet',
            'baslangic_tarihi' => 'Başlangıç tarihi', 'bitis_tarihi' => 'Bitiş tarihi', 'gonderim_saati' => 'Gönderim saati',
        ] + parent::attributes();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $sablonVar = trim((string) ($this->input('sablon_id') ?? $this->input('seciliSablonId'))) !== '';
        $this->eskiAdlar([
            'gorevTuru' => 'gorev_turu', 'gelenGelmeyenMusteri' => 'hedef_filtre', 'musteriGruplari' => 'hedef_grup',
            'katilimciTuru' => 'hedef_cinsiyet', 'hedefDegisti' => 'hedef_yenile', 'hizmetUrunPaket' => 'hizmet_urun_paket',
            'seciliSablonId' => 'sablon_id', 'kampanyaKodu' => 'indirim_kodu', 'Xal' => 'xal', 'Yode' => 'yode',
            'kampanyaIndirim' => 'yuzde', 'asistan_tarih' => 'baslangic_tarihi', 'kampanyaGecerlilikTarihi' => 'bitis_tarihi',
            'asistan_saat' => 'gonderim_saati',
        ] + ($sablonVar ? [] : ['kampanya_sms' => 'mesaj']));

        if (! $this->has('indirim_turu') && $this->has('indirimTuru')) {
            $this->merge(['indirim_turu' => filter_var($this->input('indirimTuru'), FILTER_VALIDATE_BOOLEAN) ? 'yuzde' : 'xalyode']);
        }
        // Boş metinler (eski istemciler '' gönderir) → null.
        foreach (['hedef_filtre', 'hedef_grup', 'hedef_cinsiyet', 'hizmet_urun_paket', 'sablon_id', 'indirim_kodu', 'yuzde', 'xal', 'yode'] as $a) {
            if ($this->input($a) === '') {
                $this->merge([$a => null]);
            }
        }
        if (is_string($this->input('gonderim_saati')) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $this->input('gonderim_saati'))) {
            $this->merge(['gonderim_saati' => substr($this->input('gonderim_saati'), 0, 5)]);
        }
    }
}
