<?php

namespace App\Services\SistemYonetim\Izleme;

use App\Support\SorguSayfasi;
use App\Domain\SistemYonetim\SistemRolu;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Models\SistemYoneticisi;
use App\Services\Oturum\ErisimTokenServisi;
use App\Services\SistemYonetim\Denetim;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Ekip uyeleri ve roller. Sifre degisince uyenin oturumlari duser; pasif hesap guard'da zaten duser. */
class EkipServisi
{
    public const SIRALAMA = ['uye' => 'name', 'email' => 'email', 'telefon' => 'telefon', 'rol' => 'rol', 'son_giris' => 'son_giris_tarihi', 'durum' => 'aktif'];

    private const ROL_ACIKLAMA = [
        'super_admin' => 'tüm yetkiler',
        'yonetici'    => 'ekip dışı tüm yetkiler',
        'destek'      => 'atanan salonlara giriş + ticket',
        'izleyici'    => 'sadece okuma',
    ];

    private const ROL_RENK = ['super_admin' => 'danger', 'yonetici' => 'info', 'destek' => 'success', 'izleyici' => 'muted'];

    private const ROL_IZINLERI = [
        ['Tüm salonları görme', '✅', '✅', 'Atananlar', '✅'],
        ['Salon hesabına giriş', '✅', '✅', 'Atananlar', '—'],
        ['Salon askıya alma', '✅', '✅', '—', '—'],
        ['Müşteri temsilcisi atama', '✅', '✅', '—', '—'],
        ['Ticket cevaplama', '✅', '✅', '✅', '—'],
        ['Ekip üyesi ekle/düzenle', '✅', '—', '—', '—'],
        ['Aktivite logu görme', '✅', '✅', '—', '—'],
        ['Sistem sağlık / güvenlik', '✅', '✅', '—', '—'],
    ];

    public function __construct(private ErisimTokenServisi $token) {}

    public function secenekler(SistemYoneticisi $yonetici): array
    {
        return [
            'roller'      => array_map(fn (array $r) => $r + ['aciklama' => self::ROL_ACIKLAMA[$r['kod']] ?? ''], SistemRolu::secenekler()),
            'rol_izinleri' => array_map(fn (array $s) => ['yetenek' => $s[0], 'degerler' => array_slice($s, 1)], self::ROL_IZINLERI),
            'yazabilir'   => $yonetici->rol() === SistemRolu::SuperAdmin,
            'ben_id'      => (int) $yonetici->id,
        ];
    }

    public function listele(array $liste): array
    {
        $sorgu = DB::table('sistemyoneticileri')->select(['id', 'name', 'email', 'telefon', 'rol', 'admin', 'aktif', 'son_giris_tarihi', 'son_giris_ip']);

        return SorguSayfasi::uret($sorgu, $liste, ['name', 'email', 'telefon'], self::SIRALAMA, fn ($u) => $this->satir($u));
    }

    /** @param array{name:string,email:string,password:string,rol:string,telefon?:?string} $g */
    public function ekle(array $g): void
    {
        $u = new SistemYoneticisi();
        $this->doldur($u, $g);
        $u->password = Hash::make($g['password']);
        $u->aktif = 1;
        $u->save();

        Denetim::yaz('kullanici_olustur', 'sistem_yoneticisi', (int) $u->id, $u->name, "Rol: {$u->rol}");
    }

    /** @param array{name:string,email:string,rol:string,telefon?:?string,aktif?:bool,password?:?string} $g */
    public function guncelle(int $id, array $g, SistemYoneticisi $yapan, ?string $aktifToken): void
    {
        $u = $this->bul($id);
        $aktif = (bool) ($g['aktif'] ?? $u->aktif);
        $this->kendiniPasifEtmeyiEngelle($u, $yapan, $aktif);
        $sifreDegisti = ! empty($g['password']);

        $eski = ['rol' => $u->rol, 'aktif' => $u->aktif, 'email' => $u->email];
        $this->doldur($u, $g);
        $u->aktif = $aktif ? 1 : 0;
        if ($sifreDegisti) {
            $u->password = Hash::make($g['password']);
        }
        $u->save();

        if ($sifreDegisti) {
            $this->token->sistemDigerleriniIptal($id, (int) $u->id === (int) $yapan->id ? $aktifToken : null);
        }

        Denetim::yaz('kullanici_guncelle', 'sistem_yoneticisi', (int) $u->id, $u->name, null, [
            'eski' => $eski,
            'yeni' => ['rol' => $u->rol, 'aktif' => $u->aktif, 'email' => $u->email],
        ] + ($sifreDegisti ? ['sifre_degisti' => true] : []));
    }

    public function pasifEt(int $id, SistemYoneticisi $yapan): void
    {
        $u = $this->bul($id);
        $this->kendiniPasifEtmeyiEngelle($u, $yapan, false);
        $u->aktif = 0;
        $u->save();

        Denetim::yaz('kullanici_pasif', 'sistem_yoneticisi', (int) $u->id, $u->name);
    }

    private function bul(int $id): SistemYoneticisi
    {
        return SistemYoneticisi::find($id) ?? throw new BulunamadiException('Ekip üyesi bulunamadı');
    }

    private function kendiniPasifEtmeyiEngelle(SistemYoneticisi $hedef, SistemYoneticisi $yapan, bool $aktif): void
    {
        if (! $aktif && (int) $hedef->id === (int) $yapan->id) {
            throw new GecersizIslemException('Kendinizi pasif edemezsiniz.');
        }
    }

    private function doldur(SistemYoneticisi $u, array $g): void
    {
        $u->name = $g['name'];
        $u->email = $g['email'];
        $u->rol = $g['rol'];
        $u->admin = $g['rol'] === SistemRolu::SuperAdmin->value ? 1 : 0;
        $u->telefon = $g['telefon'] ?? null;
    }

    private function satir(object $u): array
    {
        $rol = (new SistemYoneticisi())->forceFill(['rol' => $u->rol, 'admin' => $u->admin])->rol();

        return [
            'id'             => (int) $u->id,
            'ad'             => $u->name,
            'email'          => $u->email,
            'telefon'        => $u->telefon,
            'rol'            => $rol->value,
            'rol_etiket'     => $rol->etiket(),
            'rol_renk'       => self::ROL_RENK[$rol->value] ?? 'muted',
            'aktif'          => (bool) $u->aktif,
            'son_giris_once' => IzlemeZamani::once($u->son_giris_tarihi),
            'son_giris_ip'   => $u->son_giris_ip,
        ];
    }
}
