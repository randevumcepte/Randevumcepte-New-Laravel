<?php

namespace Tests\Feature\Yetki;

use App\Models\IsletmeYetkilileri;
use App\Models\PersonelYetkiAyari;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\YetkiServisi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Mobil ve web AYNI yetki kuralini kullanir (kullanici karari 2026-11-12): `mobil=1` dali yetki anahtarini gevsetmez.
 *
 * Her senaryoda yazma bayragi KAPALI: yetkisiz istek 403 alir (bayragin durumunu ogrenmez), yetkili istek kapidan
 * gecip 409'a (kill-switch) ulasir — "gecer" kaniti budur ve is tablolarina dokunmadan denetlenir.
 * Izole sqlite; `mobil` bayragi actingAs ile guard'dan gecmeden controller'a ulasir (imzali mobil token karsiligi).
 */
class MobilYetkiParitesiTest extends TestCase
{
    private const SALON = 100;

    private const SAHIP = 1;

    private const SEKRETER = 4;

    /** Rol 5, istenen anahtari ACIK ozel kayit. */
    private const YETKILI = 11;

    /** Rol 5, istenen anahtari KAPALI ozel kayit (ya da personel varsayilani). */
    private const YETKISIZ = 12;

    private const PAKET = 700;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'mobil_yetki_sqlite',
            'database.connections.mobil_yetki_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'tahsilat.yazma_aktif' => false,
            'paket.yazma_aktif' => false,
            'randevu.yazma_aktif' => false,
            'randevu.yazma_salonlari' => [],
            'hesabim.yazma_aktif' => false,
            'panel.randevu_modulu.hostlar' => [],
        ]);
        DB::purge('mobil_yetki_sqlite');
        DB::setDefaultConnection('mobil_yetki_sqlite');

        $this->semaKur();
        $this->veriKur();
    }

    private function semaKur(): void
    {
        $tablolar = [
            'isletmeyetkilileri' => ['name', 'aktif'],
            'salonlar' => ['salon_adi', 'uyelik_turu', 'studyo_modu', 'uyelik_bitis_tarihi'],
            'salon_personelleri' => ['yetkili_id', 'salon_id', 'role_id', 'personel_adi', 'aktif'],
            'model_has_roles' => ['role_id', 'model_type', 'model_id', 'salon_id'],
            'personel_yetki_ayarlari' => ['personel_id', 'salon_id', 'sablon', 'ayarlar', 'created_at', 'updated_at'],
            'paketler' => ['salon_id', 'paket_adi', 'aktif'],
        ];
        foreach ($tablolar as $ad => $kolonlar) {
            Schema::create($ad, function (Blueprint $t) use ($kolonlar) {
                $t->bigIncrements('id');
                foreach ($kolonlar as $k) {
                    preg_match('/_id$|^aktif$|^uyelik_turu$|^studyo_modu$/', $k) ? $t->integer($k)->nullable() : $t->text($k)->nullable();
                }
            });
        }
    }

    private function veriKur(): void
    {
        DB::table('salonlar')->insert(['id' => self::SALON, 'salon_adi' => 'Test', 'uyelik_turu' => 3, 'studyo_modu' => 0, 'uyelik_bitis_tarihi' => '2099-12-31']);
        foreach ([[self::SAHIP, 1], [self::SEKRETER, 4], [self::YETKILI, 5], [self::YETKISIZ, 5]] as [$yetkili, $rol]) {
            DB::table('isletmeyetkilileri')->insert(['id' => $yetkili, 'name' => "Yetkili $yetkili", 'aktif' => 1]);
            DB::table('salon_personelleri')->insert(['id' => $yetkili * 10, 'yetkili_id' => $yetkili, 'salon_id' => self::SALON,
                'role_id' => $rol, 'personel_adi' => "Personel $yetkili", 'aktif' => 1]);
            DB::table('model_has_roles')->insert(['role_id' => $rol, 'model_type' => 'isletme', 'model_id' => $yetkili, 'salon_id' => self::SALON]);
        }
        DB::table('paketler')->insert(['id' => self::PAKET, 'salon_id' => self::SALON, 'paket_adi' => 'Lazer 6', 'aktif' => 1]);
    }

    /** Personel sablonu uzerine tek anahtar acik/kapali ozel kayit. */
    private function anahtar(int $yetkili, string $anahtar, bool $acik): void
    {
        PersonelYetkiAyari::create(['personel_id' => $yetkili * 10, 'salon_id' => self::SALON, 'sablon' => 'personel', 'ayarlar' => [$anahtar => $acik]]);
    }

    private function giris(int $yetkili): self
    {
        // Karar motoru istek basi cache tutar; tek uygulama orneginde her giriste tazelenir.
        $this->app->forgetInstance(YetkiServisi::class);
        $this->app->forgetInstance(GuvenlikKapisi::class);

        return $this->actingAs(IsletmeYetkilileri::find($yetkili), 'isletmeyonetim-api');
    }

    // ------------------------------------------------------------------
    // Tahsilat (mobil-para): satis.tahsilat_al — web POST /tahsilat ile ayni anahtar
    // ------------------------------------------------------------------

    public function test_mobil_tahsilat_yetkisiz_personel_403(): void
    {
        $this->anahtar(self::YETKISIZ, 'satis.tahsilat_al', false);

        $this->giris(self::YETKISIZ)->postJson('/api/v2/mobil-para/tahsilat', ['mobil' => 1, 'salon_id' => self::SALON])
            ->assertForbidden()->assertJsonPath('basarili', false);
    }

    public function test_mobil_tahsilat_yetkili_personel_kapidan_gecer(): void
    {
        $this->anahtar(self::YETKILI, 'satis.tahsilat_al', true);

        $this->giris(self::YETKILI)->postJson('/api/v2/mobil-para/tahsilat', ['mobil' => 1, 'salon_id' => self::SALON])
            ->assertStatus(409);
    }

    // ------------------------------------------------------------------
    // Paket sil (mobil): paket.tanim_olustur — web DELETE /paket/{id} ile ayni anahtar
    // ------------------------------------------------------------------

    public function test_mobil_paket_sil_yetkisiz_personel_403(): void
    {
        // Personel sablonu varsayilani: paket.tanim_olustur kapali (eskiden mobilde salon uyesi yeterdi).
        $this->giris(self::YETKISIZ)->deleteJson('/api/v2/paket/'.self::PAKET.'?mobil=1')
            ->assertForbidden()->assertJsonPath('basarili', false);
        $this->assertSame(1, (int) DB::table('paketler')->where('id', self::PAKET)->value('aktif'));
    }

    public function test_mobil_paket_sil_yetkili_personel_kapidan_gecer(): void
    {
        $this->anahtar(self::YETKILI, 'paket.tanim_olustur', true);

        $this->giris(self::YETKILI)->deleteJson('/api/v2/paket/'.self::PAKET.'?mobil=1')->assertStatus(409);
    }

    // ------------------------------------------------------------------
    // Randevu olustur (mobil): randevu.olustur — web POST /randevu ile ayni anahtar
    // ------------------------------------------------------------------

    public function test_mobil_randevu_olustur_yetkisiz_personel_403(): void
    {
        $this->anahtar(self::YETKISIZ, 'randevu.olustur', false);

        $this->giris(self::YETKISIZ)->postJson('/api/v2/randevu', ['mobil' => 1, 'salon_id' => self::SALON, 'dry_run' => 0])
            ->assertForbidden()->assertJsonPath('basarili', false);
    }

    public function test_mobil_randevu_olustur_yetkili_personel_kapidan_gecer(): void
    {
        $this->anahtar(self::YETKILI, 'randevu.olustur', true);

        $this->giris(self::YETKILI)->postJson('/api/v2/randevu', ['mobil' => 1, 'salon_id' => self::SALON, 'dry_run' => 0])
            ->assertStatus(409);
    }

    // ------------------------------------------------------------------
    // Fatura bilgisi (mobil): web ile ayni rol kapisi — Personel (rol 5) kapali
    // ------------------------------------------------------------------

    public function test_mobil_fatura_bilgisi_personel_403(): void
    {
        $this->giris(self::YETKISIZ)->putJson('/api/v2/hesabim/fatura-bilgisi?salon_id='.self::SALON, ['mobil' => 1, 'vergi_adi' => 'Test Ltd'])
            ->assertForbidden()->assertJsonPath('basarili', false);
    }

    public function test_mobil_fatura_bilgisi_yonetici_kapidan_gecer(): void
    {
        foreach ([self::SEKRETER, self::SAHIP] as $yetkili) {
            $this->giris($yetkili)->putJson('/api/v2/hesabim/fatura-bilgisi?salon_id='.self::SALON, ['mobil' => 1, 'vergi_adi' => 'Test Ltd'])
                ->assertStatus(409);
        }
    }

    // ------------------------------------------------------------------
    // Ortak: web ve mobil ayni sonucu verir
    // ------------------------------------------------------------------

    public function test_web_ve_mobil_dal_ayni_yetki_sonucunu_verir(): void
    {
        $this->anahtar(self::YETKISIZ, 'randevu.olustur', false);

        foreach ([0, 1] as $mobil) {
            $this->giris(self::YETKISIZ)->postJson('/api/v2/randevu', ['mobil' => $mobil, 'salon_id' => self::SALON, 'dry_run' => 0])
                ->assertForbidden();
            $this->giris(self::YETKISIZ)->deleteJson('/api/v2/paket/'.self::PAKET.'?mobil='.$mobil)->assertForbidden();
        }
    }
}
