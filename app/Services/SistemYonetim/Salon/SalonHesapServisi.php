<?php

namespace App\Services\SistemYonetim\Salon;

use App\Exceptions\GecersizIslemException;
use App\Services\SistemYonetim\Denetim;
use Illuminate\Support\Facades\DB;

/** Salona bagli isletme hesaplari: isten cikar / geri al, hesap sahibi yap. */
class SalonHesapServisi
{
    private const ROL_HESAP_SAHIBI = 1;

    private const YETKILI_MODEL_TIPI = 'App\\IsletmeYetkilileri';

    /** @return string sonuc mesaji */
    public function aktiflikDegistir(object $salon, int $yetkiliId, bool $aktif): string
    {
        $ad = $this->baglaHesabinAdi((int) $salon->id, $yetkiliId);
        $personeller = DB::table('salon_personelleri')->where('salon_id', $salon->id)->where('yetkili_id', $yetkiliId);
        if (! (clone $personeller)->exists()) {
            throw new GecersizIslemException('Bu hesabın salonda personel kaydı yok.');
        }
        $etkilenen = $personeller->update(['aktif' => $aktif ? 1 : 0, 'updated_at' => now()]);

        Denetim::yaz($aktif ? 'salon_hesap_geri_al' : 'salon_hesap_pasif', 'salon', (int) $salon->id, $salon->salon_adi,
            $ad.($aktif ? ' tekrar aktif edildi' : ' işten çıkarıldı (pasif)'), ['yetkili_id' => $yetkiliId, 'etkilenen' => $etkilenen]);

        return $ad.($aktif ? ' tekrar aktif edildi.' : ' işten çıkarıldı — giriş varsayılanı artık bu hesabı atlar.');
    }

    /** Personele dusurulmus sahibi geri alir: personel rolu, sahip bayragi ve salon rolu birlikte yazilir. */
    public function sahipYap(object $salon, int $yetkiliId): string
    {
        $ad = $this->baglaHesabinAdi((int) $salon->id, $yetkiliId);

        DB::transaction(function () use ($salon, $yetkiliId) {
            DB::table('salon_personelleri')->where('salon_id', $salon->id)->where('yetkili_id', $yetkiliId)->update(['role_id' => self::ROL_HESAP_SAHIBI, 'updated_at' => now()]);
            DB::table('isletmeyetkilileri')->where('id', $yetkiliId)->update(['is_admin' => 1, 'updated_at' => now()]);
            DB::table('model_has_roles')->where('model_id', $yetkiliId)->where('salon_id', $salon->id)->delete();
            DB::table('model_has_roles')->insert(['role_id' => self::ROL_HESAP_SAHIBI, 'model_type' => self::YETKILI_MODEL_TIPI, 'model_id' => $yetkiliId, 'salon_id' => $salon->id]);
        });

        Denetim::yaz('salon_sahip_yap', 'salon', (int) $salon->id, $salon->salon_adi, $ad.' Hesap Sahibi (rol 1) yapıldı', ['yetkili_id' => $yetkiliId]);

        return $ad.' artık Hesap Sahibi (rol 1). Kullanıcı çıkış yapıp tekrar girsin.';
    }

    /** Baska salonun hesabi bu uclarla degistirilemez. */
    private function baglaHesabinAdi(int $salonId, int $yetkiliId): string
    {
        $yetkili = DB::table('isletmeyetkilileri')->where('id', $yetkiliId)->first(['name', 'salon_id']);
        $bagli = $yetkili && ((int) $yetkili->salon_id === $salonId
            || DB::table('salon_personelleri')->where('salon_id', $salonId)->where('yetkili_id', $yetkiliId)->exists());
        if (! $bagli) {
            throw new GecersizIslemException('Seçilen hesap bu salona bağlı değil.');
        }

        return (string) $yetkili->name;
    }
}
