<?php

namespace App\Services\SistemYonetim\Izleme;

use App\Exceptions\GecersizIslemException;
use App\Models\SistemYoneticisi;
use App\Services\Oturum\ErisimTokenServisi;
use App\Services\SistemYonetim\Denetim;
use App\Services\SistemYonetim\SistemOturumServisi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Giris yapan yoneticinin kendi profili (izleyici dahil herkes): bilgiler, sifre ve kendi hareketleri. */
class SistemProfilServisi
{
    public function __construct(private ErisimTokenServisi $token) {}

    public function ozet(SistemYoneticisi $y): array
    {
        return [
            'profil'          => SistemOturumServisi::kullaniciOzeti($y),
            'son_girisler'    => DB::table('sistemyonetim_login_loglari')->where('user_id', $y->id)->orderByDesc('id')->limit(20)
                ->get(IzlemeSunumu::GIRIS_KOLONLARI)->map(fn ($k) => IzlemeSunumu::giris($k))->all(),
            'son_aktiviteler' => DB::table('sistemyonetim_audit_log')->where('user_id', $y->id)->orderByDesc('id')->limit(30)
                ->get(IzlemeSunumu::AKTIVITE_KOLONLARI)->map(fn ($k) => IzlemeSunumu::aktivite($k))->all(),
        ];
    }

    /** @param array{name:string,email:string,telefon?:?string} $g */
    public function guncelle(SistemYoneticisi $y, array $g): array
    {
        $y->name = $g['name'];
        $y->email = $g['email'];
        $y->telefon = $g['telefon'] ?? null;
        $y->save();
        Denetim::yaz('profil_guncelle', 'sistem_yoneticisi', (int) $y->id, $y->name);

        return SistemOturumServisi::kullaniciOzeti($y);
    }

    /** Bu oturum acik kalir, diger oturumlar duser. */
    public function sifreDegistir(SistemYoneticisi $y, string $mevcut, string $yeni, ?string $aktifToken): void
    {
        if (! Hash::check($mevcut, (string) $y->password)) {
            throw new GecersizIslemException('Mevcut şifre hatalı.');
        }
        $y->password = Hash::make($yeni);
        $y->save();
        $this->token->sistemDigerleriniIptal((int) $y->id, $aktifToken);
        Denetim::yaz('sifre_degistir', 'sistem_yoneticisi', (int) $y->id, $y->name);
    }
}
