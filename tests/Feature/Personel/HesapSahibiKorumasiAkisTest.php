<?php

namespace Tests\Feature\Personel;

use App\Domain\Personel\GirisTelefonu;
use App\Exceptions\GecersizIslemException;
use App\Services\Personel\PersonelArsivServisi;
use App\Services\Personel\PersonelYazServisi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Hesap sahibi korumasi servis akisinda (web ve mobil ayni servis): sahibin telefonu, sifre gonderimi, rolu, pasif ve
 * arsiv baskasi tarafindan 422; rol listesi dogrulanir, rol 1 yalniz sahip atar. Reddedilen istek hicbir sey yazmaz.
 * Izole sqlite; reddin yazmadan once verildigini dogrulamak icin yalniz gereken tablolar kurulur.
 */
class HesapSahibiKorumasiAkisTest extends TestCase
{
    private const SALON = 20;

    private const SAHIP = 1;      // yetkili

    private const MUDUR = 2;      // yetkili (rol 2)

    private const SAHIP_P = 10;   // personel satirlari

    private const MUDUR_P = 11;

    private const PERSONEL_P = 12;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sahip_sqlite',
            'database.connections.sahip_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('sahip_sqlite');
        DB::setDefaultConnection('sahip_sqlite');

        foreach ([
            'isletmeyetkilileri' => ['gsm1', 'name', 'unvan', 'cinsiyet', 'password', 'profil_resim', 'aktif', 'created_at', 'updated_at'],
            'salon_personelleri' => ['yetkili_id', 'salon_id', 'role_id', 'aktif', 'arsivli', 'takvimde_gorunsun', 'personel_adi', 'cep_telefon', 'created_at', 'updated_at',
                'renk', 'unvan', 'cinsiyet', 'maas', 'hizmet_prim_yuzde', 'urun_prim_yuzde', 'paket_prim_yuzde', 'hizmet_prim_detayli', 'urun_prim_detayli', 'paket_prim_detayli'],
            'model_has_roles' => ['role_id', 'model_type', 'model_id', 'salon_id'],
            'roles' => ['name'],
        ] as $ad => $kolonlar) {
            Schema::create($ad, function (Blueprint $t) use ($kolonlar) {
                $t->id();
                foreach ($kolonlar as $k) {
                    $t->text($k)->nullable();
                }
            });
        }
        foreach ([1 => 'Hesap Sahibi', 2 => 'Müdür', 3 => 'Yönetici', 4 => 'Sekreter', 5 => 'Personel', 6 => 'Gizli', 7 => 'X', 8 => 'Y'] as $id => $ad) {
            DB::table('roles')->insert(['id' => $id, 'name' => $ad]);
        }
        DB::table('isletmeyetkilileri')->insert([
            ['id' => self::SAHIP, 'gsm1' => '5321112233'],
            ['id' => self::MUDUR, 'gsm1' => '5324445566'],
            ['id' => 3, 'gsm1' => '5327778899'],
        ]);
        DB::table('salon_personelleri')->insert([
            ['id' => self::SAHIP_P, 'yetkili_id' => self::SAHIP, 'salon_id' => self::SALON, 'role_id' => 1, 'aktif' => 1, 'personel_adi' => 'Sahip', 'cep_telefon' => '5321112233'],
            ['id' => self::MUDUR_P, 'yetkili_id' => self::MUDUR, 'salon_id' => self::SALON, 'role_id' => 2, 'aktif' => 1, 'personel_adi' => 'Mudur', 'cep_telefon' => '5324445566'],
            ['id' => self::PERSONEL_P, 'yetkili_id' => 3, 'salon_id' => self::SALON, 'role_id' => 5, 'aktif' => 1, 'personel_adi' => 'Ayse', 'cep_telefon' => '5327778899'],
        ]);
        foreach ([[self::SAHIP, 1], [self::MUDUR, 2], [3, 5]] as [$y, $r]) {
            DB::table('model_has_roles')->insert(['role_id' => $r, 'model_type' => 'App\\IsletmeYetkilileri', 'model_id' => $y, 'salon_id' => self::SALON]);
        }
    }

    private function yaz(): PersonelYazServisi
    {
        return app(PersonelYazServisi::class);
    }

    private function duzenle(int $personelId, array $g): array
    {
        return $this->yaz()->ekleDuzenle($g + ['salon_id' => self::SALON, 'personel_id' => $personelId, 'personel_adi' => 'Ad', 'maas_koru' => true]);
    }

    private function reddedilir(callable $islem, string $mesaj): void
    {
        try {
            $islem();
            $this->fail('422 bekleniyordu: '.$mesaj);
        } catch (GecersizIslemException $e) {
            $this->assertSame(422, $e->durumKodu);
            $this->assertStringContainsString($mesaj, $e->getMessage());
        }
    }

    public function test_baskasi_sahibin_telefonunu_degistiremez(): void
    {
        $this->reddedilir(fn () => $this->duzenle(self::SAHIP_P, ['cep_telefon' => '05330000000', 'yapan_id' => self::MUDUR]), 'telefonunu yalnız kendisi');
        $this->assertSame('5321112233', DB::table('isletmeyetkilileri')->where('id', self::SAHIP)->value('gsm1'), 'reddedilen istek yazmaz');
        $this->assertSame('5321112233', DB::table('salon_personelleri')->where('id', self::SAHIP_P)->value('cep_telefon'));
    }

    public function test_baska_satiri_sahibin_numarasina_baglamak_reddedilir(): void
    {
        DB::table('salon_personelleri')->where('id', self::PERSONEL_P)->update(['yetkili_id' => null, 'cep_telefon' => null]);
        $this->reddedilir(fn () => $this->duzenle(self::PERSONEL_P, ['cep_telefon' => '5321112233', 'yapan_id' => self::MUDUR]), 'telefonunu yalnız kendisi');
        $this->assertNull(DB::table('salon_personelleri')->where('id', self::PERSONEL_P)->value('yetkili_id'));
    }

    public function test_sahibin_rolu_yalniz_kendisi_rol_1_yalniz_sahip_atar_gecersiz_rol_reddedilir(): void
    {
        $this->reddedilir(fn () => $this->duzenle(self::SAHIP_P, ['sistem_yetki' => '5', 'yapan_id' => self::MUDUR]), 'rolünü yalnız kendisi');
        $this->reddedilir(fn () => $this->duzenle(self::PERSONEL_P, ['sistem_yetki' => '1', 'yapan_id' => self::MUDUR]), 'yalnız hesap sahibi atayabilir');
        $this->reddedilir(fn () => $this->duzenle(self::PERSONEL_P, ['sistem_yetki' => '6', 'yapan_id' => self::SAHIP]), 'Geçersiz hesap türü');
        $this->reddedilir(fn () => $this->duzenle(self::PERSONEL_P, ['sistem_yetki' => '99', 'yapan_id' => self::SAHIP]), 'Geçersiz hesap türü');
        $this->assertSame('5', (string) DB::table('salon_personelleri')->where('id', self::PERSONEL_P)->value('role_id'));
        $this->assertSame(1, DB::table('model_has_roles')->where('model_id', self::SAHIP)->where('role_id', 1)->count());
    }

    public function test_sahip_model_has_roles_ile_de_korunur(): void
    {
        // Personel satiri rol 1 degil ama yetkilinin bu salondaki rolu 1.
        DB::table('salon_personelleri')->where('id', self::SAHIP_P)->update(['role_id' => 2]);
        $this->reddedilir(fn () => $this->yaz()->sifreGonder(self::SAHIP_P, self::MUDUR), 'şifre yalnız kendisi');
        $this->reddedilir(fn () => $this->yaz()->durum(self::SAHIP_P, false, self::MUDUR), 'Hesap sahibi pasife alınamaz');
    }

    public function test_sifre_pasif_arsiv(): void
    {
        $this->reddedilir(fn () => $this->yaz()->sifreGonder(self::SAHIP_P, self::MUDUR), 'şifre yalnız kendisi');
        $this->reddedilir(fn () => $this->yaz()->durum(self::SAHIP_P, false, self::SAHIP), 'Hesap sahibi pasife alınamaz');
        $this->reddedilir(fn () => $this->yaz()->durum(self::MUDUR_P, false, self::MUDUR), 'Kendi personel kaydınızı pasife');
        $this->reddedilir(fn () => app(PersonelArsivServisi::class)->arsivle(self::SALON, self::SAHIP_P, [], self::MUDUR), 'Hesap sahibi arşivlenemez');
        $this->reddedilir(fn () => app(PersonelArsivServisi::class)->arsivle(self::SALON, self::MUDUR_P, [], self::MUDUR), 'Kendi personel kaydınızı arşivleyemezsiniz');
        $this->assertSame('1', (string) DB::table('salon_personelleri')->where('id', self::SAHIP_P)->value('aktif'));
    }

    // ───────── Giris telefonu: baska isletmenin hesabini devralma (GirisTelefonu) ─────────

    private const DIGER_SALON = 30;

    private function ekle(string $tel, ?int $yapan = self::SAHIP): array
    {
        return $this->yaz()->ekleDuzenle(['salon_id' => self::SALON, 'personel_adi' => 'Yeni', 'cep_telefon' => $tel, 'yapan_id' => $yapan]);
    }

    /** Baska salonda personel (ya da yalniz rol) satiri olan hesap. */
    private function digerIsletmeHesabi(int $id, string $tel, bool $personelSatiri = true): void
    {
        DB::table('isletmeyetkilileri')->insert(['id' => $id, 'gsm1' => $tel, 'name' => 'Diger Sahip']);
        DB::table('model_has_roles')->insert(['role_id' => 1, 'model_type' => 'App\\IsletmeYetkilileri', 'model_id' => $id, 'salon_id' => self::DIGER_SALON]);
        if ($personelSatiri) {
            DB::table('salon_personelleri')->insert(['yetkili_id' => $id, 'salon_id' => self::DIGER_SALON, 'role_id' => 1, 'aktif' => 1, 'personel_adi' => 'Diger Sahip', 'cep_telefon' => $tel]);
        }
    }

    public function test_baska_isletmenin_hesabina_baglanamaz(): void
    {
        $this->digerIsletmeHesabi(40, '5339990000');
        $this->digerIsletmeHesabi(41, '5339990001', false); // yalniz rol satiri
        $once = DB::table('salon_personelleri')->count();

        $this->reddedilir(fn () => $this->ekle('05339990000'), GirisTelefonu::BASKA_ISLETMEDE);
        $this->reddedilir(fn () => $this->ekle('5339990001'), GirisTelefonu::BASKA_ISLETMEDE);
        // Hesabi olmayan mevcut satiri baska isletmenin hesabina baglamak da ayni kural.
        DB::table('salon_personelleri')->where('id', self::PERSONEL_P)->update(['yetkili_id' => null, 'cep_telefon' => null]);
        $this->reddedilir(fn () => $this->duzenle(self::PERSONEL_P, ['cep_telefon' => '5339990000', 'yapan_id' => self::SAHIP]), GirisTelefonu::BASKA_ISLETMEDE);

        $this->assertSame($once, DB::table('salon_personelleri')->count(), 'reddedilen istek yazmaz');
        $this->assertSame('Diger Sahip', DB::table('isletmeyetkilileri')->where('id', 40)->value('name'));
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', 40)->where('salon_id', self::SALON)->count());
        $this->assertNull(DB::table('salon_personelleri')->where('id', self::PERSONEL_P)->value('yetkili_id'));
    }

    public function test_gsm1_baska_hesabin_numarasina_cevrilemez(): void
    {
        $this->reddedilir(fn () => $this->duzenle(self::PERSONEL_P, ['cep_telefon' => '5324445566', 'yapan_id' => self::SAHIP]), GirisTelefonu::BASKA_HESAPTA);
        $this->assertSame('5327778899', DB::table('isletmeyetkilileri')->where('id', 3)->value('gsm1'));
        $this->assertSame(1, DB::table('isletmeyetkilileri')->where('gsm1', '5324445566')->count(), 'cift giris kimligi olusmaz');
    }

    public function test_baska_salonda_da_kayitli_hesabin_telefonunu_yalniz_kendisi_degistirir(): void
    {
        DB::table('model_has_roles')->insert(['role_id' => 1, 'model_type' => 'App\\IsletmeYetkilileri', 'model_id' => 3, 'salon_id' => self::DIGER_SALON]);

        $this->reddedilir(fn () => $this->duzenle(self::PERSONEL_P, ['cep_telefon' => '5330001122', 'yapan_id' => self::SAHIP]), GirisTelefonu::DEGISIM_YALNIZ_KENDISI);
        $this->assertSame('5327778899', DB::table('isletmeyetkilileri')->where('id', 3)->value('gsm1'));

        $this->duzenle(self::PERSONEL_P, ['cep_telefon' => '5330001122', 'yapan_id' => 3]);
        $this->assertSame('5330001122', DB::table('isletmeyetkilileri')->where('id', 3)->value('gsm1'), 'kendisi degistirir');
    }

    public function test_mesru_durumlar_korunur(): void
    {
        // Ayni salonun eski (arsivli) personelinin hesabi yeni satira yeniden baglanir.
        DB::table('isletmeyetkilileri')->insert(['id' => 6, 'gsm1' => '5331230000', 'name' => 'Eski']);
        DB::table('salon_personelleri')->insert(['yetkili_id' => 6, 'salon_id' => self::SALON, 'role_id' => 5, 'aktif' => 0, 'arsivli' => 1, 'personel_adi' => 'Eski']);
        $s = $this->ekle('5331230000');
        $this->assertFalse($s['yeni_hesap']);
        $this->assertSame('6', (string) DB::table('salon_personelleri')->where('id', $s['personel_id'])->value('yetkili_id'));

        // Salonsuz hesap baglanir; yalniz bu salondaki hesabin numarasi serbest numaraya degisir.
        DB::table('isletmeyetkilileri')->insert(['id' => 7, 'gsm1' => '5331230001']);
        $this->assertSame('7', (string) DB::table('salon_personelleri')->where('id', $this->ekle('5331230001')['personel_id'])->value('yetkili_id'));
        $this->duzenle(self::PERSONEL_P, ['cep_telefon' => '5330001133', 'yapan_id' => self::SAHIP]);
        $this->assertSame('5330001133', DB::table('isletmeyetkilileri')->where('id', 3)->value('gsm1'));
        // Ayni numara (degisiklik yok) ve maskeli numara denetime takilmaz.
        DB::table('model_has_roles')->insert(['role_id' => 5, 'model_type' => 'App\\IsletmeYetkilileri', 'model_id' => 3, 'salon_id' => self::DIGER_SALON]);
        $this->duzenle(self::PERSONEL_P, ['cep_telefon' => '05330001133', 'yapan_id' => self::SAHIP]);
        $this->duzenle(self::PERSONEL_P, ['cep_telefon' => '5330 *** ** 33', 'yapan_id' => self::SAHIP]);
        $this->assertSame('5330001133', DB::table('isletmeyetkilileri')->where('id', 3)->value('gsm1'));
    }

    public function test_ayni_sahibin_subesindeki_personel_telefonla_baglanir_subeler_arasi_gecis(): void
    {
        // Salon 31 de ayni hesap sahibinin (SAHIP) subesi; oradaki personelin hesabi bu salona baglanir.
        DB::table('model_has_roles')->insert(['role_id' => 1, 'model_type' => 'App\\IsletmeYetkilileri', 'model_id' => self::SAHIP, 'salon_id' => 31]);
        DB::table('isletmeyetkilileri')->insert(['id' => 8, 'gsm1' => '5331239999', 'name' => 'Sube Personeli']);
        DB::table('salon_personelleri')->insert(['yetkili_id' => 8, 'salon_id' => 31, 'role_id' => 5, 'aktif' => 1, 'personel_adi' => 'Sube Personeli']);

        $s = $this->ekle('5331239999');

        $this->assertFalse($s['yeni_hesap']);
        $this->assertSame('8', (string) DB::table('salon_personelleri')->where('id', $s['personel_id'])->value('yetkili_id'));
    }
}
