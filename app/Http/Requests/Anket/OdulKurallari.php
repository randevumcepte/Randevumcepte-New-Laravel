<?php

namespace App\Http\Requests\Anket;

use Illuminate\Validation\Rule;

/** Ödül alanlarının doğrulaması — şablon ('odul_') ve Google ('google_odul_') aynı kural. */
trait OdulKurallari
{
    protected function odulKurallari(string $onek): array
    {
        $tip = fn (string $t) => fn () => $this->input($onek.'tipi') === $t;

        return [
            $onek.'tipi'                 => ['nullable', Rule::in(['yok', 'kupon', 'puan'])],
            $onek.'kupon_indirim_tipi'   => ['nullable', Rule::in(['yuzde', 'tutar'])],
            $onek.'kupon_deger'          => [Rule::requiredIf($tip('kupon')), 'nullable', 'numeric', 'min:0',
                Rule::when($tip('kupon'), ['gt:0']),
                Rule::when(fn () => $this->input($onek.'tipi') === 'kupon' && $this->input($onek.'kupon_indirim_tipi') !== 'tutar', ['max:100'])],
            $onek.'kupon_gecerlilik_gun' => ['nullable', 'integer', 'min:0', 'max:3650'],
            $onek.'kupon_hizmet_id'      => ['nullable', 'integer', 'min:0'],
            $onek.'puan'                 => [Rule::requiredIf($tip('puan')), 'nullable', 'numeric', 'min:0', Rule::when($tip('puan'), ['gt:0'])],
            $onek.'baslik'               => ['nullable', 'string', 'max:200'],
        ];
    }

    protected function odulAlanlari(string $onek): array
    {
        return [
            $onek.'kupon_deger' => 'İndirim değeri', $onek.'puan' => 'Puan',
            $onek.'kupon_gecerlilik_gun' => 'Geçerlilik (gün)', $onek.'baslik' => 'Ödül başlığı',
        ];
    }
}
