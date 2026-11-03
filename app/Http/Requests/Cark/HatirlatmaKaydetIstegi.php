<?php

namespace App\Http\Requests\Cark;

use App\Http\Requests\V2Istegi;

/**
 * PUT /cark/hatirlatma — v2 gövdesi: {aktif, slotlar:[{no, aktif, baslik, altyazi, saat, mesaj}], atlanan_gunler:[1..7]}.
 * Eski web / mobil düz gövdesi (aktif_1, saat_1, mesaj_1, baslik_1, altyazi_1 … _3, gonderim_gunleri)
 * da kabul edilir — Flutter ekranı değişmeden v2'ye bağlanabilsin.
 */
class HatirlatmaKaydetIstegi extends V2Istegi
{
    protected array $jsonAlanlar = ['slotlar', 'atlanan_gunler', 'gonderim_gunleri'];

    protected function alanKurallari(): array
    {
        return [
            'aktif'            => ['required', 'boolean'],
            'slotlar'          => ['required', 'array', 'size:3'],
            'slotlar.*.no'     => ['required', 'integer', 'in:1,2,3', 'distinct'],
            'slotlar.*.aktif'  => ['required', 'boolean'],
            'slotlar.*.baslik' => ['nullable', 'string', 'max:80'],
            'slotlar.*.altyazi' => ['nullable', 'string', 'max:120'],
            'slotlar.*.saat'   => ['required', 'date_format:H:i'],
            'slotlar.*.mesaj'  => ['nullable', 'string', 'max:'.config('cark.hatirlatma.mesaj_max')],
            'atlanan_gunler'   => ['sometimes', 'nullable', 'array'],
            'atlanan_gunler.*' => ['integer', 'between:1,7'],
        ];
    }

    public function attributes(): array
    {
        return [
            'slotlar.*.saat'  => 'Hatırlatma saati',
            'slotlar.*.mesaj' => 'Hatırlatma mesajı',
            'atlanan_gunler'  => 'Gönderilmeyecek günler',
        ] + parent::attributes();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->eskiAdlar(['gonderim_gunleri' => 'atlanan_gunler']);
        if (! $this->has('slotlar') && $this->has('saat_1')) {
            $this->merge(['slotlar' => array_map(fn (int $no) => [
                'no'      => $no,
                'aktif'   => (bool) $this->input("aktif_$no", 1),
                'baslik'  => $this->input("baslik_$no"),
                'altyazi' => $this->input("altyazi_$no"),
                'saat'    => substr((string) $this->input("saat_$no"), 0, 5),
                'mesaj'   => $this->input("mesaj_$no"),
            ], [1, 2, 3])]);
        }
    }
}
