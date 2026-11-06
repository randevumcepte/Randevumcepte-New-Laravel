<?php

namespace App\Services\SistemYonetim\Salon;

use App\Domain\Telefon\TelefonNumarasi;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Services\Deneme\DemoHesapKurServisi;
use App\Services\Deneme\DenemeKayitServisi;
use App\Services\Oturum\Hesap\YetkiliHesabi;
use App\Services\SistemYonetim\Denetim;
use Illuminate\Support\Facades\DB;

/** Sistem yonetiminden demo salon: ucretsiz denemeyle ayni kurulum, SMS dogrulamasi olmadan. */
class SalonDemoServisi
{
    private const VARSAYILAN_GUN = 7;

    public function __construct(private DemoHesapKurServisi $kurulum, private DenemeKayitServisi $deneme, private YetkiliHesabi $yetkili) {}

    /**
     * @param  array{salon_adi:string,salon_turu_id:int,yetkili_ad:string,yetkili_telefon:string,yetkili_email:string,demo_gun?:?int,yetkili_sifre?:?string,il_id?:?int,ilce_id?:?int,adres?:?string}  $g
     * @return array{salon_id:int,email:string,telefon:string,sifre:string,demo_bitis:string,sifre_gonderildi:bool}
     */
    public function olustur(array $g): array
    {
        $telefon = $this->telefon($g['yetkili_telefon']);
        $demoGun = (int) ($g['demo_gun'] ?? self::VARSAYILAN_GUN);

        $sonuc = $this->kurulum->kur([
            'ad_soyad'       => $g['yetkili_ad'],
            'cep_telefon'    => $telefon,
            'email'          => trim($g['yetkili_email']),
            'isletme_adi'    => $g['salon_adi'],
            'isletme_adresi' => (string) ($g['adres'] ?? ''),
            'salon_turu_id'  => (int) $g['salon_turu_id'],
        ], [
            'demo_gun' => $demoGun,
            'sifre'    => $g['yetkili_sifre'] ?? null,
            'il_id'    => $g['il_id'] ?? null,
            'ilce_id'  => $g['ilce_id'] ?? null,
        ]);

        Denetim::yaz('salon_demo_olustur', 'salon', $sonuc['salon_id'], $g['salon_adi'], "Demo salon oluşturuldu ({$demoGun} gün) — sistem yönetiminden", ['yetkili_email' => trim($g['yetkili_email'])]);

        return [
            'salon_id'         => $sonuc['salon_id'],
            'email'            => trim($g['yetkili_email']),
            'telefon'          => $telefon,
            'sifre'            => $sonuc['sifre'],
            'demo_bitis'       => (string) DB::table('salonlar')->where('id', $sonuc['salon_id'])->value('uyelik_bitis_tarihi'),
            'sifre_gonderildi' => $this->deneme->girisBilgisiGonder($telefon, $sonuc['sifre']),
        ];
    }

    private function telefon(string $ham): string
    {
        $telefon = TelefonNumarasi::kanonik($ham);
        if (! TelefonNumarasi::gecerliMi($telefon)) {
            throw new GecersizIslemException('Lütfen geçerli bir cep telefonu numarası giriniz.');
        }
        if ($this->yetkili->telefonlaBul($telefon) !== null) {
            throw new CakismaException('Bu telefon numarasıyla daha önce açılmış bir üyelik var. Farklı bir numara ile tekrar deneyin.');
        }

        return $telefon;
    }
}
