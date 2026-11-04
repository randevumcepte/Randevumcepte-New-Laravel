<?php

namespace App\Services\MusteriHesap;

use App\Domain\Telefon\TelefonNumarasi;
use App\Exceptions\CokFazlaIstekException;
use App\Exceptions\GecersizIslemException;
use App\Exceptions\GirisHatasiException;
use App\Services\Online\OnlineSalonKapisi;
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

    public function __construct(
        private MusteriTokenServisi $token,
        private PortfoyKaydi $portfoy,
        private MusteriProfilServisi $profil,
        private OnlineSalonKapisi $kapi,
    ) {}

    public function giris(string $telefonHam, string $sifre, ?int $salonId, string $ip): array
    {
        $tel = $this->telefon($telefonHam);
        $anahtar = 'musteri-giris:'.$tel.'|'.$ip;
        if (RateLimiter::tooManyAttempts($anahtar, (int) config('online.giris_deneme'))) {
            throw new CokFazlaIstekException();
        }

        $kullanici = $this->kullanici($tel);
        if (! $kullanici || ! Hash::check($sifre, (string) $kullanici->password)) {
            RateLimiter::hit($anahtar, (int) config('online.giris_deneme_sn'));
            throw new GirisHatasiException();
        }
        RateLimiter::clear($anahtar);

        return $this->oturumAc((int) $kullanici->id, $salonId);
    }

    /**
     * Hizli kayit (eski randevuonaylaDirekt): numara yeniyse hesap acar ve oturum verir;
     * numara kayitliysa sifre_gerekli doner, istemci sifre sorup giris'e gecer.
     */
    public function kayit(array $g, string $ip): array
    {
        $this->botKapisi($g, $ip);
        $tel = $this->telefon($g['cep_telefon']);
        $telAnahtar = 'musteri-kayit-tel:'.$tel;
        if (RateLimiter::tooManyAttempts($telAnahtar, (int) config('online.kayit_tel_gunluk'))) {
            throw new CokFazlaIstekException();
        }
        if ($this->kullanici($tel)) {
            return ['sifre_gerekli' => true];
        }

        $salonId = (int) $g['salon_id'];
        $this->kapi->salon($salonId);
        $userId = DB::transaction(function () use ($g, $tel, $salonId) {
            $id = (int) DB::table('users')->insertGetId([
                'name' => trim((string) $g['ad_soyad']), 'cep_telefon' => $tel,
                'password' => Hash::make(Str::random(32)), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->portfoy->garantile($salonId, $id);

            return $id;
        });
        RateLimiter::hit($telAnahtar, 86400);
        RateLimiter::hit('musteri-kayit-ip:'.$ip, 3600);

        return ['sifre_gerekli' => false] + $this->oturumAc($userId, $salonId);
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
        $kullanici = $this->kullanici($tel);
        if (! $kullanici) {
            return;
        }
        $kod = OnayKodu::uret(self::SIFRE_KOD_AMAC, $salonId, (int) $kullanici->id);
        BildirimKuyrugu::sms($salonId, null, [[
            'to' => $tel, 'message' => $salon->salon_adi.' şifre yenileme kodunuz: '.$kod.'. Kod 10 dakika geçerlidir.',
        ]], true);
    }

    public function sifreSifirla(string $telefonHam, int $salonId, string $kod, string $yeniSifre): void
    {
        $kullanici = $this->kullanici($this->telefon($telefonHam));
        if (! $kullanici || ! OnayKodu::dogrula(self::SIFRE_KOD_AMAC, $salonId, (int) $kullanici->id, $kod)) {
            throw new GecersizIslemException('Kod hatalı veya süresi dolmuş.');
        }
        DB::table('users')->where('id', $kullanici->id)->update(['password' => Hash::make($yeniSifre), 'updated_at' => now()]);
        $this->token->digerleriniIptal((int) $kullanici->id, null);
    }

    private function oturumAc(int $userId, ?int $salonId): array
    {
        if ($salonId) {
            $this->kapi->salon($salonId);
            $this->portfoy->garantile($salonId, $userId);
        }

        return ['token' => $this->token->uret($userId), 'user_type' => '0', 'musteri' => $this->profil->goster($userId)];
    }

    /** Honeypot + cok hizli gonderim + IP siniri (eski randevuonaylaDirekt ile ayni degerler). */
    private function botKapisi(array $g, string $ip): void
    {
        $sure = isset($g['form_sure_sn']) ? (int) $g['form_sure_sn'] : null;
        if (! empty($g['_hp']) || ($sure !== null && $sure < (int) config('online.kayit_min_saniye'))) {
            throw new GecersizIslemException('İşlem doğrulanamadı. Lütfen tekrar deneyiniz.');
        }
        if (RateLimiter::tooManyAttempts('musteri-kayit-ip:'.$ip, (int) config('online.kayit_ip_saatlik'))) {
            throw new CokFazlaIstekException();
        }
    }

    private function telefon(string $ham): string
    {
        $tel = TelefonNumarasi::kanonik($ham);
        $tr = (bool) preg_match('/^5\d{9}$/', $tel);
        $yabanci = (bool) preg_match('/^\+\d{7,15}$/', $tel);
        if (! $tr && ! $yabanci) {
            throw new GecersizIslemException('Geçerli bir telefon numarası girin.');
        }

        return $tel;
    }

    private function kullanici(string $tel): ?object
    {
        return DB::table('users')->whereIn('cep_telefon', TelefonNumarasi::aramaVaryantlari($tel))->orderBy('id')->first(['id', 'password']);
    }
}
