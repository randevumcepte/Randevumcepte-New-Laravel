<?php

namespace App\Services\Deneme;

use App\Domain\Deneme\DemoSablonu;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use App\Support\ZamanliKayit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Deneme hesabi kurulumu (eski ApiController::demoHesapKur). Hesap + salon + ayarlar tek transaction'da:
 * yarim kurulmus hesap kalmaz. Ornek icerik ayri: bozulursa log'lanir, kullanicinin kaydi engellenmez.
 */
class DemoHesapKurServisi
{
    private const ROL_HESAP_SAHIBI = 1;

    private const YETKILI_MODEL_TIPI = 'App\\IsletmeYetkilileri';

    public function __construct(private VarsayilanKopyalayici $kopyalayici, private DemoOrnekIcerik $ornek) {}

    /**
     * @param  array{ad_soyad:string,cep_telefon:string,email:string,isletme_adi:string,isletme_adresi:string,salon_turu_id:int}  $g  telefon kanonik
     * @param  array{demo_gun?:?int,sifre?:?string,il_id?:?int,ilce_id?:?int}  $secenek  sistem yonetiminden acilista; bos alan varsayilana duser
     * @return array{yetkili_id:int,salon_id:int,sifre:string}
     */
    public function kur(array $g, array $secenek = []): array
    {
        $sifre = ($secenek['sifre'] ?? null) ?: Str::password((int) config('oturum.deneme.sifre_uzunluk'), true, true, false);
        [$yetkiliId, $salonId, $personelId] = DB::transaction(fn () => $this->cekirdek($g, $sifre, $secenek));

        try {
            $this->ornek->olustur($salonId, $yetkiliId, $personelId);
        } catch (\Throwable $e) {
            Log::error('Deneme ornek icerigi yazilamadi', ['salon_id' => $salonId, 'hata' => $e->getMessage()]);
        }
        AktiviteLog::yaz($salonId, $yetkiliId, 'isletme_kayit', 'isletme', $salonId, $g['isletme_adi'], 'Siteden yeni işletme kaydı oluşturuldu.');

        return ['yetkili_id' => $yetkiliId, 'salon_id' => $salonId, 'sifre' => $sifre];
    }

    /** @return array{0:int,1:int,2:int} */
    private function cekirdek(array $g, string $sifre, array $secenek): array
    {
        $yetkiliId = ZamanliKayit::ekle('isletmeyetkilileri', [
            'name' => $g['ad_soyad'], 'gsm1' => $g['cep_telefon'], 'email' => $g['email'],
            'profil_resim' => '/public/isletmeyonetim_assets/img/avatar.png', 'password' => Hash::make($sifre),
        ]);
        $salonId = ZamanliKayit::ekle('salonlar', [
            'santral_aktif' => 1, 'salon_adi' => $g['isletme_adi'], 'adres' => $g['isletme_adresi'], 'salon_turu_id' => $g['salon_turu_id'],
            'randevu_saat_araligi' => 15, 'randevu_takvim_turu' => 1,
            'uyelik_bitis_tarihi' => now()->addDays((int) (($secenek['demo_gun'] ?? null) ?: config('oturum.deneme.gun')))->toDateString(),
            'uyelik_turu' => (int) config('oturum.deneme.uyelik_turu'), 'demo_hesabi' => 1,
            'yetkili_adi' => $g['ad_soyad'], 'yetkili_telefon' => $g['cep_telefon'],
        ] + array_filter(['il_id' => $secenek['il_id'] ?? null, 'ilce_id' => $secenek['ilce_id'] ?? null]));

        $satirlar = fn (array $liste) => array_map(fn ($s) => $s + ['salon_id' => $salonId], $liste);
        ZamanliKayit::ekle('salon_santral_ayarlari', ['ayar_id' => 1, 'salon_id' => $salonId, 'musteri' => 0, 'personel' => 0]);
        ZamanliKayit::topluEkle('salon_calisma_saatleri', $satirlar(DemoSablonu::calismaSaatleri()));
        ZamanliKayit::topluEkle('salon_mola_saatleri', $satirlar(DemoSablonu::molaSaatleri()));
        ZamanliKayit::topluEkle('salon_sms_ayarlari', $satirlar(DemoSablonu::smsAyarlari()));

        DB::table('model_has_roles')->insert(['role_id' => self::ROL_HESAP_SAHIBI, 'model_type' => self::YETKILI_MODEL_TIPI, 'model_id' => $yetkiliId, 'salon_id' => $salonId]);
        $personelId = ZamanliKayit::ekle('salon_personelleri', [
            'salon_id' => $salonId, 'personel_adi' => $g['ad_soyad'], 'cep_telefon' => $g['cep_telefon'], 'yetkili_id' => $yetkiliId,
            'role_id' => self::ROL_HESAP_SAHIBI, 'takvimde_gorunsun' => 1, 'takvim_sirasi' => 1, 'renk' => 1, 'aktif' => 1,
        ]);

        $this->kopyalayici->kopyala($salonId, (int) $g['salon_turu_id']);
        BildirimKuyrugu::yaz($salonId, 'sistem_bildirim', null, null, ['mesaj' => $this->sahipMesaji($g, $salonId)]);

        return [$yetkiliId, $salonId, $personelId];
    }

    private function sahipMesaji(array $g, int $salonId): string
    {
        return "🆕 YENİ DEMO KAYDI\nSalon: {$g['isletme_adi']}\nYetkili: {$g['ad_soyad']}\nTel: {$g['cep_telefon']}\nID: {$salonId}\n".date('d.m.Y H:i');
    }
}
