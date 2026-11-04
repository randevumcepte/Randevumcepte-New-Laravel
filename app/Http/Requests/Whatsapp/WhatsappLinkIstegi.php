<?php

namespace App\Http\Requests\Whatsapp;

use App\Domain\Whatsapp\IsletmeLinkleri;
use App\Http\Requests\V2Istegi;

/** PUT /whatsapp/linkler — alanlar kısmi gönderilebilir; bağlantı http(s) ile başlamalı (eski kural). */
class WhatsappLinkIstegi extends V2Istegi
{
    protected function alanKurallari(): array
    {
        $kurallar = [];
        foreach (IsletmeLinkleri::LINK_ALANLARI as $alan) {
            $kurallar[$alan] = ['sometimes', 'nullable', 'string', 'max:'.IsletmeLinkleri::LINK_UZUNLUK, 'regex:~^https?://~i'];
        }
        foreach (IsletmeLinkleri::BASLIK_ALANLARI as $alan) {
            $kurallar[$alan] = ['sometimes', 'nullable', 'string', 'max:'.IsletmeLinkleri::BASLIK_UZUNLUK];
        }

        return $kurallar;
    }

    public function messages(): array
    {
        return ['regex' => 'Geçerli bir bağlantı girin (https:// ile başlamalı).'] + parent::messages();
    }

    public function attributes(): array
    {
        return parent::attributes() + [
            'konum_linki' => 'Konum bağlantısı', 'instagram_linki' => 'Instagram bağlantısı', 'web_linki' => 'Web bağlantısı',
            'instagram_baslik' => 'Buton başlığı', 'web_baslik' => 'Buton başlığı',
        ];
    }
}
