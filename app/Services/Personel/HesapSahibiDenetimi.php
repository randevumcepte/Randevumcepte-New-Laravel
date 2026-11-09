<?php

namespace App\Services\Personel;

use App\Domain\Personel\HesapSahibiKorumasi;
use App\Exceptions\GecersizIslemException;
use App\Services\Oturum\ErisimTokenServisi;
use Illuminate\Support\Facades\DB;

/**
 * HesapSahibiKorumasi'nin olgu cozucusu: hedef/yapan hesap sahibi mi (personel role_id 1 YA DA model_has_roles
 * rol 1), sistem yonetimi oturumu mu ("salon hesabina gir" token'i). Karar saf kuralda; engel varsa 422.
 * PersonelYazServisi (ekle/duzenle, durum, sifre gonder) ve PersonelArsivServisi ayni yerden cagirir.
 */
class HesapSahibiDenetimi
{
    /** Yetkili bu salonda hesap sahibi mi? (personel satiri rol 1 ya da model_has_roles rol 1) */
    public function yetkiliSahipMi(?int $yetkiliId, int $salonId): bool
    {
        if (! $yetkiliId) {
            return false;
        }

        return DB::table('salon_personelleri')->where('salon_id', $salonId)->where('yetkili_id', $yetkiliId)
                ->where('role_id', HesapSahibiKorumasi::ROL_HESAP_SAHIBI)->exists()
            || DB::table('model_has_roles')->where('salon_id', $salonId)->where('model_id', $yetkiliId)
                ->where('role_id', HesapSahibiKorumasi::ROL_HESAP_SAHIBI)->exists();
    }

    /** Personel satiri hesap sahibine mi ait? */
    public function satirSahipMi(?int $rolId, ?int $yetkiliId, int $salonId): bool
    {
        return $rolId === HesapSahibiKorumasi::ROL_HESAP_SAHIBI || $this->yetkiliSahipMi($yetkiliId, $salonId);
    }

    /** Bu istek sistem yonetiminin "salon hesabina gir" oturumuyla mi geliyor? (HTTP disi cagri: hayir) */
    public function sistemOturumu(): bool
    {
        return app()->bound('request') && ErisimTokenServisi::impersonationTokeniMi(request()->attributes->get('oauth_access_token_id'));
    }

    /** Hedef satir icin islem kuralini uygula; engel varsa 422. */
    public function denetle(string $islem, ?int $rolId, ?int $hedefYetkiliId, int $salonId, ?int $yapanId): void
    {
        $engel = HesapSahibiKorumasi::engel($islem, $this->satirSahipMi($rolId, $hedefYetkiliId, $salonId), $hedefYetkiliId, $yapanId, $this->sistemOturumu());
        if ($engel !== null) {
            throw new GecersizIslemException($engel);
        }
    }

    /** @return int[] secilebilir rol id'leri (form verisiyle ayni tek kaynak) */
    public function gecerliRoller(): array
    {
        return array_column(PersonelOkumaServisi::hesapTurleri(), 'id');
    }

    /** Rol atamasi (gecerli liste + rol 1 yalniz hesap sahibi / sistem); engel varsa 422. */
    public function rolAtamasiDenetle(mixed $yeniRol, ?int $mevcutRol, int $salonId, ?int $yapanId): void
    {
        $engel = HesapSahibiKorumasi::rolAtamaEngeli($yeniRol, $mevcutRol, $this->gecerliRoller(),
            $this->yetkiliSahipMi($yapanId, $salonId), $this->sistemOturumu());
        if ($engel !== null) {
            throw new GecersizIslemException($engel);
        }
    }
}
