<?php

namespace App\Services\MusteriHesap;

use App\Domain\Telefon\TelefonNumarasi;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Services\Oturum\ErisimTokenServisi;
use App\Support\DosyaYukleme;
use App\Support\EskiPanelLinki;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Musterinin kendi profili (eski HomeController@profilim + musteri_profil_guncelleme,
 * v1 musteri/{id} + musteribilgiguncelle). Kimlik token'dan; eski web ucunda dogrulama yoktu
 * ve telefon serbestce yazilabiliyordu. Yeni numara baska hesapta kayitliysa reddedilir.
 */
class MusteriProfilServisi
{
    private const ALANLAR = ['id', 'name', 'cep_telefon', 'email', 'meslek', 'cinsiyet', 'ev_telefon', 'dogum_tarihi', 'profil_resim', 'adres'];
    private const SIFRE_MIN = 6;

    public function __construct(private ErisimTokenServisi $token) {}

    public function goster(int $userId): array
    {
        $u = DB::table('users')->where('id', $userId)->first(self::ALANLAR);
        if (! $u) {
            throw new BulunamadiException('Kullanıcı bulunamadı');
        }

        return [
            'id'           => (int) $u->id,
            'name'         => $u->name,
            'cep_telefon'  => $u->cep_telefon,
            'email'        => $u->email,
            'ev_telefon'   => $u->ev_telefon,
            'meslek'       => $u->meslek,
            'cinsiyet'     => $u->cinsiyet,
            'dogum_tarihi' => $u->dogum_tarihi,
            'adres'        => $u->adres,
            'profil_resim' => EskiPanelLinki::mutlak($u->profil_resim),
            'salonlar'     => $this->salonlar($userId),
        ];
    }

    public function guncelle(int $userId, array $g): array
    {
        $alanlar = array_intersect_key($g, array_flip(['name', 'email', 'ev_telefon', 'meslek', 'cinsiyet', 'dogum_tarihi', 'adres']));
        if (array_key_exists('cep_telefon', $g)) {
            $alanlar['cep_telefon'] = $this->yeniTelefon($userId, (string) $g['cep_telefon']);
        }
        if ($alanlar) {
            DB::table('users')->where('id', $userId)->update($alanlar + ['updated_at' => now()]);
        }

        return $this->goster($userId);
    }

    public function resimYukle(int $userId, ?UploadedFile $dosya): array
    {
        $altDizin = (string) config('hesabim.profil_resim_alt_dizin');
        $dizin = rtrim((string) config('ayar.eski_public_kok'), '/').'/'.$altDizin;
        $ad = DosyaYukleme::kaydet($dosya, $dizin, DosyaYukleme::GORSEL_UZANTILARI, (int) config('hesabim.profil_resim_maks_kb'), 'musteri');
        DosyaYukleme::gorselKucult($dizin.'/'.$ad, (int) config('hesabim.profil_resim_yukseklik_px'));
        DB::table('users')->where('id', $userId)->update(['profil_resim' => '/public/'.$altDizin.'/'.$ad, 'updated_at' => now()]);

        return $this->goster($userId);
    }

    public function resimKaldir(int $userId): array
    {
        DB::table('users')->where('id', $userId)->update(['profil_resim' => '', 'updated_at' => now()]);

        return $this->goster($userId);
    }

    /** Musteri uygulamasi profil formu: giris yapmis musteri mevcut sifre sormadan yeni sifre belirler (v1 davranisi); diger oturumlar duser. */
    public function sifreAta(int $userId, string $yeni, ?string $oturumTokenId): void
    {
        DB::table('users')->where('id', $userId)->update(['password' => Hash::make($yeni), 'updated_at' => now()]);
        $this->token->digerleriniIptal($userId, $oturumTokenId);
    }

    public function sifreDegistir(int $userId, string $mevcut, string $yeni, ?string $oturumTokenId): void
    {
        $hash = (string) DB::table('users')->where('id', $userId)->value('password');
        if (! Hash::check($mevcut, $hash)) {
            throw new GecersizIslemException('Mevcut şifreniz hatalı.');
        }
        if (mb_strlen($yeni) < self::SIFRE_MIN) {
            throw new GecersizIslemException('Yeni şifre en az '.self::SIFRE_MIN.' karakter olmalıdır.');
        }
        if (Hash::check($yeni, $hash)) {
            throw new GecersizIslemException('Yeni şifre mevcut şifreyle aynı olamaz.');
        }
        DB::table('users')->where('id', $userId)->update(['password' => Hash::make($yeni), 'updated_at' => now()]);
        $this->token->digerleriniIptal($userId, $oturumTokenId);
    }

    private function yeniTelefon(int $userId, string $ham): string
    {
        $tel = TelefonNumarasi::kanonik($ham);
        if ($tel === '') {
            throw new GecersizIslemException('Geçerli bir telefon numarası girin.');
        }
        $baskasi = DB::table('users')->whereIn('cep_telefon', TelefonNumarasi::aramaVaryantlari($tel))->where('id', '!=', $userId)->exists();
        if ($baskasi) {
            throw new GecersizIslemException('Bu telefon numarası başka bir hesapta kayıtlı.');
        }

        return $tel;
    }

    /** Flutter marka cozumu icin: musteri_olunan_salonlar (salon_id + temel salon alanlari). */
    private function salonlar(int $userId): array
    {
        return $this->salonSatirlari(DB::table('musteri_portfoy as mp')->join('salonlar as s', 's.id', '=', 'mp.salon_id')
            ->where('mp.user_id', $userId)->where('mp.aktif', 1));
    }

    /**
     * Uygulama acilisi icin salonlar. Marka uygulamasi markanin salonlarini gorur (portfoy kaydi pasif olsa
     * da: giris yapan kisi yine o markanin uygulamasindadir). Ana uygulamada aktif kayit yoksa pasifler
     * doner; salonu olmayan musteri "lisans yok" ekranina dusmesin.
     *
     * @param  int[]  $markaSalonlari  bos: marka uygulamasi degil
     */
    public function acilisSalonlari(int $userId, array $markaSalonlari): array
    {
        if ($markaSalonlari) {
            return $this->salonSatirlari(DB::table('salonlar as s')->whereIn('s.id', $markaSalonlari));
        }

        return $this->salonlar($userId)
            ?: $this->salonSatirlari(DB::table('musteri_portfoy as mp')->join('salonlar as s', 's.id', '=', 'mp.salon_id')->where('mp.user_id', $userId));
    }

    private function salonSatirlari(Builder $q): array
    {
        return $q->get(['s.id', 's.salon_adi', 's.app_bundle', 's.domain', 's.telefon_1', 's.musteri_online_randevu_aktif', 's.studyo_modu', 's.grup_dersi_aktif', 's.uyelik_bitis_tarihi'])
            ->map(fn ($s) => ['salon_id' => (int) $s->id, 'salonlar' => (array) $s])->all();
    }
}
