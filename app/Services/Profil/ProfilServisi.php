<?php

namespace App\Services\Profil;

use App\Domain\Personel\Telefon;
use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Models\IsletmeYetkilileri;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use App\Support\DosyaYukleme;
use App\Support\EskiPanelLinki;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Giriş yapmış yetkilinin KENDİ profili — eski web yetkilibilgiguncelle/profilresimyukle ve
 * v1 bilgiguncelle/profilresimyukle'nin tek karşılığı. Kimlik her zaman token'dan gelir:
 * eski v1'deki govde yetkili_id açığı (başkasının resmini değiştirme) burada yoktur.
 *
 * Şifre değişiminde oturum kapatma (token iptali, web oturumu damgası, force_logout push'u)
 * eski monolitin OturumServisi'nde kalır → outbox 'sifre_degisti'. İşlemi yapan oturum korunur.
 */
class ProfilServisi
{
    public function getir(int $yetkiliId): array
    {
        return $this->sunum($this->bul($yetkiliId));
    }

    /**
     * @param  array<string,mixed>  $g  doğrulanmış alanlar
     * @param  int  $salonId  log + outbox bağlamı (profil salona bağlı değildir)
     * @param  string|null  $aktifTokenId  korunacak oturum
     */
    public function guncelle(int $yetkiliId, array $g, int $salonId, ?string $aktifTokenId): array
    {
        $y = $this->bul($yetkiliId);
        $eski = ['name' => $y->name, 'email' => $y->email, 'gsm1' => $y->gsm1];
        $sifreDegisti = ! empty($g['password']);

        $y->name = trim($g['name']);
        $y->gsm1 = $this->telefonCoz($g['gsm1'], $y);
        foreach (['email', 'unvan', 'cinsiyet', 'sms_gonderimi'] as $alan) {
            if (array_key_exists($alan, $g)) {
                $y->{$alan} = $g[$alan];
            }
        }
        if ($sifreDegisti) {
            $y->password = Hash::make($g['password']);
        }

        DB::transaction(function () use ($y, $salonId, $sifreDegisti, $aktifTokenId) {
            $y->save();
            if ($sifreDegisti) {
                BildirimKuyrugu::yaz($salonId, 'sifre_degisti', null, (int) $y->id, array_filter([
                    'yetkili_id'  => (int) $y->id,
                    'haric_token' => $aktifTokenId,
                ]));
            }
        });

        AktiviteLog::yaz($salonId, (int) $y->id, 'profil_guncelle', 'yetkili', (int) $y->id, $y->name ?: 'Kullanıcı',
            'Kullanıcı kendi profil bilgilerini güncelledi', [
                'eski' => $eski,
                'yeni' => ['name' => $y->name, 'email' => $y->email, 'gsm1' => $y->gsm1],
                'sifre_degisti' => $sifreDegisti,
            ]);

        return $this->sunum($y);
    }

    public function resimYukle(int $yetkiliId, ?UploadedFile $dosya, int $salonId): array
    {
        $y = $this->bul($yetkiliId);
        $altDizin = (string) config('hesabim.profil_resim_alt_dizin');
        $dizin = rtrim((string) config('ayar.eski_public_kok'), '/').'/'.$altDizin;

        $ad = DosyaYukleme::kaydet($dosya, $dizin, DosyaYukleme::GORSEL_UZANTILARI, (int) config('hesabim.profil_resim_maks_kb'), 'profil');
        DosyaYukleme::gorselKucult($dizin.'/'.$ad, (int) config('hesabim.profil_resim_yukseklik_px'));

        // Eski dosya SİLİNMEZ: aynı yol başka kayıtlarda / panel logosunda kullanılıyor olabilir.
        $y->profil_resim = '/public/'.$altDizin.'/'.$ad;
        $y->save();

        AktiviteLog::yaz($salonId, (int) $y->id, 'profil_resim_yukle', 'yetkili', (int) $y->id, $y->name, 'Profil resmi yüklendi');

        return $this->sunum($y);
    }

    /** gsm1 giriş kimliğidir: normalize edilir; değiştiyse başka hesapta olmamalı. */
    private function telefonCoz(string $ham, IsletmeYetkilileri $y): string
    {
        if (Telefon::maskeliMi($ham)) {
            throw new GecersizIslemException('Geçerli bir telefon numarası giriniz.');
        }
        $tel = Telefon::normalize($ham);
        if ($tel !== (string) $y->gsm1
            && IsletmeYetkilileri::where('gsm1', $tel)->where('id', '!=', $y->id)->exists()) {
            throw new CakismaException('Bu telefon numarası başka bir hesapta kayıtlı.');
        }

        return $tel;
    }

    private function bul(int $yetkiliId): IsletmeYetkilileri
    {
        return IsletmeYetkilileri::find($yetkiliId) ?? throw new BulunamadiException('Kullanıcı bulunamadı');
    }

    private function sunum(IsletmeYetkilileri $y): array
    {
        return [
            'id'               => (int) $y->id,
            'name'             => (string) $y->name,
            'unvan'            => (string) $y->unvan,
            'cinsiyet'         => $y->cinsiyet === null ? null : (int) $y->cinsiyet,
            'email'            => (string) $y->email,
            'gsm1'             => (string) $y->gsm1,
            'sms_gonderimi'    => $y->sms_gonderimi === null ? null : (int) $y->sms_gonderimi,
            'profil_resim'     => $y->profil_resim,
            'profil_resim_url' => EskiPanelLinki::mutlak($y->profil_resim),
        ];
    }
}
