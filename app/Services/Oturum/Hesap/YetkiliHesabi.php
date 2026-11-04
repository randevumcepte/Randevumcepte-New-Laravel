<?php

namespace App\Services\Oturum\Hesap;

use App\Domain\Telefon\TelefonNumarasi;
use App\Exceptions\GirisHatasiException;
use App\Services\Oturum\CihazKaydi;
use App\Services\Oturum\ErisimTokenServisi;
use App\Services\Oturum\IlkSayfa;
use App\Services\Oturum\UygulamaKapsami;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Isletme yetkilisi (isletmeyetkilileri): telefon (gsm1, gsm2) veya e-posta ile girer.
 */
class YetkiliHesabi implements OturumHesabi
{
    /** Cevaba cikan yetkili alanlari; password ve dogrulama_kodu asla cikmaz. */
    private const YETKILI_ALANLARI = ['id', 'name', 'email', 'profil_resim', 'gsm1', 'cinsiyet', 'sms_gonderimi', 'unvan', 'aktif'];

    /**
     * Flutter'in isletmebilgi'den okudugu salon alanlari. Satir SELECT * okunup bu listeyle suzulur:
     * semada olmayan kolon login'i 500'e dusurmesin, sms/santral kimlikleri de disari cikmasin.
     */
    private const SALON_ALANLARI = [
        'id', 'salon_adi', 'salon_turu_id', 'uyelik_turu', 'uyelik_bitis_tarihi', 'demo_hesabi', 'studyo_modu', 'grup_dersi_aktif',
        'randevu_takvim_turu', 'sadik_musteri_indirim_yuzde', 'aktif_musteri_indirim_yuzde', 'app_bundle', 'domain', 'logo', 'adres',
        'aciklama', 'whatsapp', 'instagram_sayfa', 'facebook_sayfa', 'kdv_orani', 'vergi_no', 'vergi_dairesi', 'vergi_adresi', 'vergi_adi',
    ];

    public function __construct(
        private UygulamaKapsami $kapsam,
        private IlkSayfa $ilkSayfa,
        private CihazKaydi $cihaz,
        private ErisimTokenServisi $token,
    ) {}

    public function tur(): string
    {
        return 'isletme';
    }

    public function kullaniciTipi(): string
    {
        return '1';
    }

    public function sifreyleBul(string $kimlik, string $sifre): ?int
    {
        $q = DB::table('isletmeyetkilileri');
        if (str_contains($kimlik, '@')) {
            $q->where('email', $kimlik);
        } else {
            $varyantlar = TelefonNumarasi::aramaVaryantlari(TelefonNumarasi::kanonik($kimlik));
            if (! $varyantlar) {
                return null;
            }
            $q->where(fn ($w) => $w->whereIn('gsm1', $varyantlar)->orWhereIn('gsm2', $varyantlar));
        }
        foreach ($q->orderBy('id')->get(['id', 'password']) as $satir) {
            if (Hash::check($sifre, (string) $satir->password)) {
                return (int) $satir->id;
            }
        }

        return null;
    }

    public function telefonlaBul(string $telefon): ?int
    {
        $id = DB::table('isletmeyetkilileri')->whereIn('gsm1', TelefonNumarasi::aramaVaryantlari($telefon))->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    public function acilisVerisi(int $id, ?string $bundle): array
    {
        $personeller = $this->kapsam->yetkiliPersonelSatirlari($id, $bundle);
        if (! $personeller) {
            throw new GirisHatasiException('Bu işletme için yetkiniz bulunmamaktadır.');
        }
        $yetkili = DB::table('isletmeyetkilileri')->where('id', $id)->first();
        $salonlar = DB::table('salonlar')->whereIn('id', array_column($personeller, 'salon_id'))->get()->keyBy('id');
        foreach ($personeller as $p) {
            AktiviteLog::yaz((int) $p->salon_id, $id, 'login', 'kullanici', $id, (string) $yetkili->name, 'Panele giriş yapıldı');
        }

        return [
            'user' => $this->suz($yetkili, self::YETKILI_ALANLARI) + [
                'yetkili_olunan_isletmeler' => array_map(fn ($p) => [
                    'id' => (int) $p->id, 'salon_id' => (int) $p->salon_id, 'yetkili_id' => $id, 'role_id' => $p->role_id, 'aktif' => (int) $p->aktif,
                    'salonlar' => $this->suz($salonlar[$p->salon_id], self::SALON_ALANLARI),
                ], $personeller),
            ],
            'musteri'   => '',
            'ilk_sayfa' => $bundle ? null : $this->ilkSayfa->coz($id, (int) $personeller[0]->salon_id),
        ];
    }

    public function cihazKaydet(int $id, ?string $bildirimId, ?string $cihaz, ?string $bundle): void
    {
        $this->cihaz->yetkili(array_map(fn ($p) => (int) $p->id, $this->kapsam->yetkiliPersonelSatirlari($id, $bundle)), $bildirimId, $cihaz, $bundle);
    }

    public function sifreGuncelle(int $id, string $yeniSifre): void
    {
        DB::transaction(function () use ($id, $yeniSifre) {
            DB::table('isletmeyetkilileri')->where('id', $id)->update(['password' => Hash::make($yeniSifre)]);
            $this->token->digerleriniIptal($id, null);
            // Eski panel oturumu + force_logout push'u monolitte (Profil ekraniyla ayni outbox turu).
            $salonId = DB::table('salon_personelleri')->where('yetkili_id', $id)->where('aktif', 1)->orderBy('id')->value('salon_id');
            if ($salonId) {
                BildirimKuyrugu::yaz((int) $salonId, 'sifre_degisti', null, $id, ['yetkili_id' => $id]);
            }
        });
    }

    private function suz(object $satir, array $alanlar): array
    {
        return array_intersect_key((array) $satir, array_flip($alanlar));
    }
}
