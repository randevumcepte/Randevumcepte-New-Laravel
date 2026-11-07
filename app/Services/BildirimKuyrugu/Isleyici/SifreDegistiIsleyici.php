<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Services\BildirimKuyrugu\BildirimIsleyici;
use App\Services\BildirimKuyrugu\Kanal\BildirimTipleri;
use App\Services\BildirimKuyrugu\OturumSonlandirici;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Kullanici sifresini degistirdi: diger oturumlar kapatilir, islemi yapan token (payload.haric_token) korunur. */
class SifreDegistiIsleyici implements BildirimIsleyici
{
    public function __construct(private OturumSonlandirici $oturum) {}

    public function isle(object $kayit, array $payload): void
    {
        $yetkiliId = (int) ($payload['yetkili_id'] ?? 0);
        if (! DB::table('isletmeyetkilileri')->where('id', $yetkiliId)->exists()) {
            throw new RuntimeException('Yetkili bulunamadi: '.($payload['yetkili_id'] ?? '-'));
        }

        $this->oturum->tokenlariIptalEt($yetkiliId, $payload['haric_token'] ?? null);
        $this->oturum->webOturumlariniGecersizKil($yetkiliId);
        $this->oturum->yetkiliTumCihazlaraForceLogout(
            $yetkiliId,
            BildirimTipleri::SIFRE_DEGISTI,
            'Şifreniz değiştirildi',
            'Hesabınızın şifresi değiştirildi. Lütfen tekrar giriş yapınız.'
        );
    }
}
