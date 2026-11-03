<?php

namespace App\Http\Requests\Kampanya;

use App\Http\Requests\V2Istegi;

/**
 * POST /kampanya/kitle-sayisi · POST /kampanya/mesaj-onizle — sihirbazın canlı önizlemeleri (yazmaz).
 * Eski adlar: filtre/gelenGelmeyenMusteri, grup/musteriGruplari, cinsiyet/katilimciTuru,
 * seciliSablonId, hizmetUrunPaket, kampanyaIndirim ('' → X Al Y Öde).
 */
class KampanyaOnizlemeIstegi extends V2Istegi
{
    protected function alanKurallari(): array
    {
        return [
            'gorev_turu'        => ['nullable', 'integer', 'in:1,2,3,4'],
            'hedef_filtre'      => ['nullable', 'string', 'in:1,2,3,4,5,6,7,8'],
            'hedef_grup'        => ['nullable', 'string', 'max:40'],
            'hedef_cinsiyet'    => ['nullable', 'string', 'in:kadinlar,erkekler'],
            'hizmet_urun_paket' => ['nullable', 'string', 'max:40'],
            'sablon_id'         => ['nullable', 'string', 'max:40'],
            'mesaj'             => ['nullable', 'string', 'max:2000'],
            'indirim_turu'      => ['nullable', 'string', 'in:yuzde,xalyode'],
            'yuzde'             => ['nullable', 'integer', 'min:1', 'max:100'],
            'xal'               => ['nullable', 'integer', 'min:2', 'max:99'],
            'yode'              => ['nullable', 'integer', 'min:1', 'max:98'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->eskiAdlar([
            'filtre' => 'hedef_filtre', 'gelenGelmeyenMusteri' => 'hedef_filtre', 'grup' => 'hedef_grup', 'musteriGruplari' => 'hedef_grup',
            'cinsiyet' => 'hedef_cinsiyet', 'katilimciTuru' => 'hedef_cinsiyet', 'gorevTuru' => 'gorev_turu',
            'seciliSablonId' => 'sablon_id', 'hizmetUrunPaket' => 'hizmet_urun_paket', 'kampanyaIndirim' => 'yuzde',
        ]);
        if (! $this->has('indirim_turu') && $this->has('yuzde')) {
            $this->merge(['indirim_turu' => trim((string) $this->input('yuzde')) === '' ? 'xalyode' : 'yuzde']);
        }
        foreach (['hedef_filtre', 'hedef_grup', 'hedef_cinsiyet', 'hizmet_urun_paket', 'sablon_id', 'yuzde', 'xal', 'yode'] as $a) {
            if ($this->input($a) === '') {
                $this->merge([$a => null]);
            }
        }
    }
}
