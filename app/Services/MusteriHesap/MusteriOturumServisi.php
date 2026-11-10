<?php

namespace App\Services\MusteriHesap;

use App\Domain\Oturum\TokenTipi;
use App\Domain\Telefon\TelefonNumarasi;
use App\Exceptions\CokFazlaIstekException;
use App\Exceptions\GecersizIslemException;
use App\Exceptions\GirisHatasiException;
use App\Services\Online\BotKapisi;
use App\Services\Online\OnlineSalonKapisi;
use App\Services\Oturum\ErisimTokenServisi;
use App\Services\Oturum\Hesap\MusteriHesabi;
use App\Services\Oturum\PlatformMesaj;
use App\Support\BildirimKuyrugu;
use App\Support\OnayKodu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Musteri girisi, kaydi ve sifre sifirlama (mini site + musteri uygulamasi).
 *
 * Eskiden bilincli farklar:
 *   - Sifre sifirlama iki adimli: once SMS kodu, sonra yeni sifre. Eski /sifregonder numarayi
 *     bilen herkesin istegiyle sifreyi ANINDA degistiriyordu (hesap kilitleme acigi).
 *   - Girisin yanlis denemeleri de sayilir; eski kod yalniz basarili islemi sayiyordu.
 */
class MusteriOturumServisi
{
    private const SIFRE_KOD_AMAC = 'musteri_sifre';
    private const UYGULAMA_SIFRE_UZUNLUK = 6;

    public function __construct(
        private ErisimTokenServisi $token,
        private MusteriHesabi $hesap,
        private PortfoyKaydi $portfoy,
        private MusteriProfilServisi $profil,
        private OnlineSalonKapisi $kapi,
        private PlatformMesaj $mesaj,
    ) {}

    public function giris(string $telefonHam, string $sifre, ?int $salonId, string $ip): array
    {
        $tel = $this->telefon($telefonHam);
        $anahtar = 'musteri-giris:'.$tel.'|'.$ip;
        if (RateLimiter::tooManyAttempts($anahtar, (int) config('online.giris_deneme'))) {
            throw new CokFazlaIstekException();
        }

        $userId = $this->hesap->sifreyleBul($tel, $sifre);
        if ($userId === null) {
            RateLimiter::hit($anahtar, (int) config('online.giris_deneme_sn'));
            throw new GirisHatasiException();
        }
        RateLimiter::clear($anahtar);

        return $this->oturumAc($userId, $salonId);
    }

    /**
     * Hizli kayit (eski randevuonaylaDirekt): numara yeniyse hesap acar ve oturum verir;
     * numara kayitliysa sifre_gerekli doner, istemci sifre sorup giris'e gecer (mini site akisi geregi).
     * Numara taramasina karsi IP (saatte 5) ve numara (gunde 3) siniri kayitli numarada da islenir:
     * eskiden kayitli numara hic sayilmiyordu, sinirsiz "bu numara kayitli mi" sorgusu yapilabiliyordu.
     */
    public function kayit(array $g, string $ip): array
    {
        $tel = $this->kayitKapisi($g, $ip);
        if ($this->hesap->telefonlaBul($tel) !== null) {
            return ['sifre_gerekli' => true];
        }

        $salonId = (int) $g['salon_id'];
        $this->kapi->salon($salonId);
        $userId = $this->hesapAc((string) $g['ad_soyad'], $tel, $salonId);

        return ['sifre_gerekli' => false] + $this->oturumAc($userId, $salonId);
    }

    /**
     * Telefonu kodla dogrulanmis misafir (vitrin carki hediyesi; kod oncesi kayitKapisi'ndan gecer). Numara yeniyse
     * kayit() ile ayni hesap acilir; kayitliysa yalniz salon portfoyu garantilenir. Oturum VERMEZ: cagiran isini bitirince
     * yeni hesaba oturumAc ile verir; kayitli numaraya web'de kodla giris yoktur (sifreyle girer).
     *
     * @return array{user_id:int, yeni:bool}
     */
    public function dogrulanmisMusteri(string $adSoyad, string $tel, int $salonId): array
    {
        $this->kapi->salon($salonId);
        $mevcut = $this->hesap->telefonlaBul($tel);
        if ($mevcut !== null) {
            $this->portfoy->garantile($salonId, $mevcut);

            return ['user_id' => $mevcut, 'yeni' => false];
        }

        return ['user_id' => $this->hesapAc($adSoyad, $tel, $salonId), 'yeni' => true];
    }

    /** Sifresiz yeni musteri hesabi + salon portfoyu (hizli kayit ve dogrulanmis misafir ortak). */
    private function hesapAc(string $adSoyad, string $tel, int $salonId): int
    {
        return DB::transaction(function () use ($adSoyad, $tel, $salonId) {
            $id = (int) DB::table('users')->insertGetId([
                'name' => trim($adSoyad), 'cep_telefon' => $tel,
                'password' => Hash::make(Str::random(32)), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->portfoy->garantile($salonId, $id);

            return $id;
        });
    }

    /**
     * Musteri uygulamasi kaydi (v1 yenimusteridanisankaydi): yeni numaraya hesap ve marka subelerine portfoy acar, sifre
     * SMS ile gider, musteri giris ekranina doner. Kayitli numarada hicbir sey degismez (kimliksiz istekle sifre
     * degistirilmez, pasif kayit acilmaz, baska salonun musterisi portfoye eklenmez) ve CEVAP AYNIDIR: numaranin
     * sahibine "hesabiniz var, giris yapin" mesaji gider. Eskiden kayitli:true donuyordu (numara sizdiriyordu).
     *
     * @return array{sms_gonderildi:true}
     */
    public function sifreyleKayit(array $g, string $ip): array
    {
        $salonId = (int) $g['salon_id'];
        $this->kapi->salon($salonId);
        $tel = $this->kayitKapisi($g, $ip);
        $marka = $this->kapi->markaAdi($g['app_bundle'] ?? null);

        if ($this->hesap->telefonlaBul($tel) !== null) {
            $this->mesajGonder($tel, $marka.' uygulamasında bu numarayla kayıtlı bir hesabınız var. Giriş yapabilir ya da "Şifremi unuttum" ile yeni şifre alabilirsiniz.', 'Bilgi', $salonId);

            return ['sms_gonderildi' => true];
        }

        $salonlar = array_values(array_unique(array_merge([$salonId], $this->markaSalonlari($g['app_bundle'] ?? null))));
        $sifre = Str::random(self::UYGULAMA_SIFRE_UZUNLUK);
        $this->mesajGonder($tel, $marka.' uygulama şifreniz  : '.$sifre, 'Sifre', $salonId);
        DB::transaction(function () use ($g, $tel, $salonlar, $sifre) {
            $userId = (int) DB::table('users')->insertGetId([
                'name' => trim((string) $g['ad_soyad']), 'cep_telefon' => $tel, 'password' => Hash::make($sifre), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($salonlar as $salon) {
                $this->portfoy->garantile($salon, $userId);
            }
        });

        return ['sms_gonderildi' => true];
    }

    /**
     * Kayit yollarinin ortak kapisi (hizli kayit, uygulama kaydi, vitrin carki misafir kaydi): bot + IP siniri, numara
     * siniri; deneme kayitli numarada da sayilir. Numara gunluk sinira ulasinca kayitli/kayitsiz ayni 429'u alir.
     *
     * @param  array{cep_telefon:string, _hp?:?string, form_sure_sn?:int|string|null}  $g
     * @return string kanonik telefon
     */
    public function kayitKapisi(array $g, string $ip): string
    {
        $this->botKapisi($g, $ip);
        $tel = $this->telefon($g['cep_telefon']);
        $telAnahtar = 'musteri-kayit-tel:'.$tel;
        if (RateLimiter::tooManyAttempts($telAnahtar, (int) config('online.kayit_tel_gunluk'))) {
            throw new CokFazlaIstekException();
        }
        RateLimiter::hit($telAnahtar, 86400);
        RateLimiter::hit('musteri-kayit-ip:'.$ip, 3600);

        return $tel;
    }

    private function mesajGonder(string $tel, string $mesaj, string $baslik, int $salonId): void
    {
        if (! $this->mesaj->kod($tel, $mesaj, $baslik, $salonId)) {
            throw new GecersizIslemException('Mesaj gönderilemedi. Lütfen daha sonra tekrar deneyin.');
        }
    }

    /** @return int[] ana uygulama marka sayilmaz */
    private function markaSalonlari(?string $bundle): array
    {
        return $bundle && $bundle !== config('oturum.ana_uygulama_bundle') ? $this->kapi->bundleSalonlari($bundle) : [];
    }

    /** Numara kayitli olsa da olmasa da ayni cevap: hesap varligi sizdirilmaz. */
    public function sifreKoduGonder(string $telefonHam, int $salonId, string $ip): void
    {
        $tel = $this->telefon($telefonHam);
        $salon = $this->kapi->salon($salonId);
        if (! RateLimiter::attempt('musteri-sifre-ip:'.$ip, (int) config('online.sifre_kodu_ip_gunluk'), fn () => true, 86400)) {
            throw new CokFazlaIstekException();
        }
        if (! RateLimiter::attempt('musteri-sifre-tel:'.$tel, 1, fn () => true, (int) config('online.sifre_kodu_tel_sn'))) {
            return;
        }
        $userId = $this->hesap->telefonlaBul($tel);
        if ($userId === null) {
            return;
        }
        $kod = OnayKodu::uret(self::SIFRE_KOD_AMAC, $salonId, $userId);
        BildirimKuyrugu::sms($salonId, null, [[
            'to' => $tel, 'message' => $salon->salon_adi.' şifre yenileme kodunuz: '.$kod.'. Kod 10 dakika geçerlidir.',
        ]], true);
    }

    public function sifreSifirla(string $telefonHam, int $salonId, string $kod, string $yeniSifre): void
    {
        $userId = $this->hesap->telefonlaBul($this->telefon($telefonHam));
        if ($userId === null || ! OnayKodu::dogrula(self::SIFRE_KOD_AMAC, $salonId, $userId, $kod)) {
            throw new GecersizIslemException('Kod hatalı veya süresi dolmuş.');
        }
        $this->hesap->sifreGuncelle($userId, $yeniSifre);
    }

    public function oturumAc(int $userId, ?int $salonId): array
    {
        if ($salonId) {
            $this->kapi->salon($salonId);
            $this->portfoy->garantile($salonId, $userId);
        }

        return ['token' => $this->token->uret($userId, TokenTipi::MUSTERI), 'user_type' => '0', 'musteri' => $this->profil->goster($userId)];
    }

    /** Honeypot + cok hizli gonderim + IP siniri (eski randevuonaylaDirekt ile ayni degerler). */
    private function botKapisi(array $g, string $ip): void
    {
        BotKapisi::denetle($g);
        if (RateLimiter::tooManyAttempts('musteri-kayit-ip:'.$ip, (int) config('online.kayit_ip_saatlik'))) {
            throw new CokFazlaIstekException();
        }
    }

    private function telefon(string $ham): string
    {
        $tel = TelefonNumarasi::kanonik($ham);
        if (! TelefonNumarasi::gecerliMi($tel)) {
            throw new GecersizIslemException('Geçerli bir telefon numarası girin.');
        }

        return $tel;
    }
}
