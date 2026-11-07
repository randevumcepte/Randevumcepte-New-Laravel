<?php

namespace App\Http\Requests\Bildirim;

use App\Http\Requests\V2Istegi;

/** POST /bildirim/voip-token. */
class VoipTokenIstegi extends V2Istegi
{
    protected function alanKurallari(): array
    {
        return [
            'token'             => ['required', 'string', 'max:512'],
            'android_fcm_token' => ['nullable', 'string', 'max:512'],
        ];
    }

    public function attributes(): array
    {
        return ['token' => 'Çağrı bildirim kimliği'] + parent::attributes();
    }
}
