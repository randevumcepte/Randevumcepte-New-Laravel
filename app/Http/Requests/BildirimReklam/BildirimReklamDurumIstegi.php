<?php

namespace App\Http\Requests\BildirimReklam;

use App\Domain\BildirimReklam\ReklamDurumu;
use App\Http\Requests\V2Istegi;
use Illuminate\Validation\Rule;

/** PATCH /bildirim-reklam/{id}/durum {durum: taslak|aktif|pasif|bitti} — yayınla / duraklat. */
class BildirimReklamDurumIstegi extends V2Istegi
{
    protected function alanKurallari(): array
    {
        return ['durum' => ['required', Rule::in(ReklamDurumu::kodlar())]];
    }

    public function attributes(): array
    {
        return ['durum' => 'Durum'] + parent::attributes();
    }
}
