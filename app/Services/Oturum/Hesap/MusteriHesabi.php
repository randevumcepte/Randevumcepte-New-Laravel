<?php

namespace App\Services\Oturum\Hesap;

use App\Domain\Telefon\TelefonNumarasi;
use App\Services\MusteriHesap\MusteriProfilServisi;
use App\Services\MusteriHesap\PortfoyKaydi;
use App\Services\Oturum\CihazKaydi;
use App\Services\Oturum\ErisimTokenServisi;
use App\Services\Oturum\UygulamaKapsami;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Musteri (users): telefonla girer. Marka uygulamasinda portfoye eklenir ve yalniz markanin salonlarini gorur.
 */
class MusteriHesabi implements OturumHesabi
{
    private const PROFIL_ALANLARI = [
        'kayit_tarihi', 'referans', 'alerji_var', 'alkol_alimi_yapildi', 'baslangic_basen', 'baslangic_bel', 'baslangic_diger', 'baslangic_gobek',
        'baslangic_gogus', 'baslangic_kalca', 'baslangic_kg', 'baslangic_sirt', 'cilt_tipi', 'daha_once_uygulama_yaptirildi',
        'daha_once_yaptirilan_uygulama_aciklama', 'deri_yumusak_doku_hastaligi_var', 'ek_saglik_sorunu', 'hamile', 'hemofili_hastaligi_var', 'il_id',
        'kemoterapi_goruyor', 'kil_yapisi', 'musteri_tipi', 'regl_doneminde', 'seker_hastaligi_var', 'surekli_kullanilan_ilac_aciklama',
        'surekli_kullanilan_ilac_Var', 'tc_kimlik_no', 'ten_rengi', 'yakin_zamanda_ameliyat_gecirildi',
    ];

    public function __construct(
        private UygulamaKapsami $kapsam,
        private PortfoyKaydi $portfoy,
        private MusteriProfilServisi $profil,
        private CihazKaydi $cihaz,
        private ErisimTokenServisi $token,
    ) {}

    public function tur(): string
    {
        return 'musteri';
    }

    public function kullaniciTipi(): string
    {
        return '0';
    }

    public function sifreyleBul(string $kimlik, string $sifre): ?int
    {
        $id = $this->telefonlaBul(TelefonNumarasi::kanonik($kimlik));
        if ($id === null) {
            return null;
        }

        return Hash::check($sifre, (string) DB::table('users')->where('id', $id)->value('password')) ? $id : null;
    }

    public function telefonlaBul(string $telefon): ?int
    {
        $varyantlar = TelefonNumarasi::aramaVaryantlari($telefon);
        if (! $varyantlar) {
            return null;
        }
        $id = DB::table('users')->whereIn('cep_telefon', $varyantlar)->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    public function acilisVerisi(int $id, ?string $bundle): array
    {
        if ($this->kapsam->markaMi($bundle)) {
            foreach ($this->kapsam->markaSalonlari($bundle) as $salonId) {
                $this->portfoy->garantile($salonId, $id);
            }
        }
        $musteri = $this->profil->goster($id);
        // Profil ve saglik ekranlari: kisinin kendi kaydi. SELECT *: semada olmayan kolon girisi dusurmesin.
        $musteri += array_intersect_key((array) DB::table('users')->where('id', $id)->first(), array_flip(self::PROFIL_ALANLARI));
        if ($this->kapsam->markaMi($bundle)) {
            $musteri['salonlar'] = array_values(array_filter($musteri['salonlar'], fn ($s) => $s['salonlar']['app_bundle'] === $bundle));
        }

        return ['user' => '', 'musteri' => $musteri, 'ilk_sayfa' => null];
    }

    public function cihazKaydet(int $id, ?string $bildirimId, ?string $cihaz, ?string $bundle): void
    {
        $this->cihaz->musteri($id, $bildirimId, $cihaz, $bundle);
    }

    public function sifreGuncelle(int $id, string $yeniSifre): void
    {
        DB::table('users')->where('id', $id)->update(['password' => Hash::make($yeniSifre), 'updated_at' => now()]);
        $this->token->digerleriniIptal($id, null);
    }
}
