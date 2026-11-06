<?php

namespace Tests\Feature\Sistem\Izleme;

use App\Services\Oturum\ErisimTokenServisi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;

/** Ekip yazma kurallari ve kendi profili: audit, oturum dusurme, kendini pasif etme engeli. */
class EkipProfilTest extends IzlemeTestTabani
{
    private function tokenBekle(int $id, ?string $haric): void
    {
        $token = Mockery::mock(ErisimTokenServisi::class);
        $token->shouldReceive('sistemDigerleriniIptal')->once()->with($id, $haric);
        $this->app->instance(ErisimTokenServisi::class, $token);
    }

    public function test_secenekler_rolleri_ve_yazma_yetkisini_verir(): void
    {
        $this->olarak('yonetici');
        $this->getJson(self::KOK.'/ekip/secenekler')->assertOk()
            ->assertJsonPath('veri.yazabilir', false)
            ->assertJsonPath('veri.roller.0.kod', 'super_admin')
            ->assertJsonCount(4, 'veri.rol_izinleri.0.degerler');
    }

    public function test_liste_sifre_sizdirmaz_ve_rolsuz_eski_kaydi_cozer(): void
    {
        $this->olarak('super_admin');
        $this->yonetici('destek', ['rol' => null, 'admin' => 1, 'name' => 'Eski Admin']);

        $r = $this->getJson(self::KOK.'/ekip?ara=Eski')->assertOk()->assertJsonPath('veri.total', 1)->assertJsonPath('veri.data.0.rol', 'super_admin');
        $this->assertStringNotContainsString('password', $r->getContent());
    }

    public function test_ekleme_dogrular_sifreyi_hashler_ve_denetime_yazar(): void
    {
        $this->olarak('super_admin');
        $this->postJson(self::KOK.'/ekip', ['name' => 'Yeni', 'email' => 'yeni@test.tr', 'rol' => 'tanrı', 'password' => 'gizli123'])->assertStatus(422);
        $this->postJson(self::KOK.'/ekip', ['name' => 'Yeni', 'email' => 'yeni@test.tr', 'rol' => 'super_admin', 'password' => '123'])->assertStatus(422);

        $this->postJson(self::KOK.'/ekip', ['name' => 'Yeni', 'email' => 'yeni@test.tr', 'rol' => 'super_admin', 'password' => 'gizli123'])->assertStatus(201);

        $u = DB::table('sistemyoneticileri')->where('email', 'yeni@test.tr')->first();
        $this->assertTrue(Hash::check('gizli123', $u->password));
        $this->assertSame(1, (int) $u->admin);
        $this->assertTrue($this->denetimVar('kullanici_olustur'));
        $this->postJson(self::KOK.'/ekip', ['name' => 'Yeni', 'email' => 'yeni@test.tr', 'rol' => 'destek', 'password' => 'gizli123'])->assertStatus(422);
    }

    public function test_baska_uyenin_sifresi_degisince_tum_oturumlari_duser(): void
    {
        $this->olarak('super_admin');
        $hedef = $this->yonetici('destek');
        $this->tokenBekle((int) $hedef->id, null);

        $this->putJson(self::KOK."/ekip/{$hedef->id}", ['name' => 'Yeni Ad', 'email' => $hedef->email, 'rol' => 'izleyici', 'password' => 'yenisifre', 'aktif' => true])->assertOk();

        $u = DB::table('sistemyoneticileri')->find($hedef->id);
        $this->assertSame('izleyici', $u->rol);
        $this->assertTrue(Hash::check('yenisifre', $u->password));
        $this->assertTrue($this->denetimVar('kullanici_guncelle'));
    }

    public function test_sifresiz_guncelleme_oturum_dusurmez_aktif_gelmezse_durum_korunur(): void
    {
        $this->olarak('super_admin');
        $hedef = $this->yonetici('destek');
        $token = Mockery::mock(ErisimTokenServisi::class);
        $token->shouldNotReceive('sistemDigerleriniIptal');
        $this->app->instance(ErisimTokenServisi::class, $token);

        $this->putJson(self::KOK."/ekip/{$hedef->id}", ['name' => 'Ad', 'email' => $hedef->email, 'rol' => 'destek'])->assertOk();

        $this->assertSame(1, (int) DB::table('sistemyoneticileri')->where('id', $hedef->id)->value('aktif'));
        $this->assertTrue(Hash::check('gizli123', DB::table('sistemyoneticileri')->where('id', $hedef->id)->value('password')));
    }

    public function test_kendini_pasif_etme_iki_yoldan_da_engelli(): void
    {
        $ben = $this->olarak('super_admin');
        $this->postJson(self::KOK."/ekip/{$ben->id}/pasif")->assertStatus(422);
        $this->putJson(self::KOK."/ekip/{$ben->id}", ['name' => 'Ben', 'email' => $ben->email, 'rol' => 'super_admin', 'aktif' => false])->assertStatus(422);
        $this->assertSame(1, (int) DB::table('sistemyoneticileri')->where('id', $ben->id)->value('aktif'));

        $baska = $this->yonetici('destek');
        $this->postJson(self::KOK."/ekip/{$baska->id}/pasif")->assertOk();
        $this->assertSame(0, (int) DB::table('sistemyoneticileri')->where('id', $baska->id)->value('aktif'));
        $this->assertTrue($this->denetimVar('kullanici_pasif'));
        $this->postJson(self::KOK.'/ekip/99999/pasif')->assertStatus(404);
    }

    public function test_izleyici_kendi_profilini_gorur_ve_gunceller_baskasinin_kaydi_gelmez(): void
    {
        $ben = $this->olarak('izleyici', ['name' => 'Ben']);
        DB::table('sistemyonetim_login_loglari')->insert(['user_id' => $ben->id, 'email_attempt' => 'ben@test.tr', 'basarili' => 1, 'created_at' => '2026-05-10 10:00:00']);
        DB::table('sistemyonetim_login_loglari')->insert(['user_id' => 999, 'email_attempt' => 'baska@test.tr', 'basarili' => 1, 'created_at' => '2026-05-10 10:00:00']);

        $this->getJson(self::KOK.'/profil')->assertOk()->assertJsonPath('veri.profil.rol', 'izleyici')->assertJsonCount(1, 'veri.son_girisler');
        $this->putJson(self::KOK.'/profil', ['name' => 'Yeni Ad', 'email' => $ben->email])->assertOk()->assertJsonPath('veri.ad', 'Yeni Ad');
        $this->assertTrue($this->denetimVar('profil_guncelle'));
    }

    public function test_sifre_degisimi_mevcut_sifreyi_ister_diger_oturumlari_dusurur(): void
    {
        $ben = $this->olarak('izleyici');
        $this->tokenBekle((int) $ben->id, null);

        $this->postJson(self::KOK.'/profil/sifre', ['mevcut_sifre' => 'yanlis', 'yeni_sifre' => 'yenisifre', 'yeni_sifre_confirmation' => 'yenisifre'])->assertStatus(422);
        $this->postJson(self::KOK.'/profil/sifre', ['mevcut_sifre' => 'gizli123', 'yeni_sifre' => 'yenisifre', 'yeni_sifre_confirmation' => 'farkli'])->assertStatus(422);
        $this->postJson(self::KOK.'/profil/sifre', ['mevcut_sifre' => 'gizli123', 'yeni_sifre' => 'yenisifre', 'yeni_sifre_confirmation' => 'yenisifre'])->assertOk();

        $this->assertTrue(Hash::check('yenisifre', DB::table('sistemyoneticileri')->where('id', $ben->id)->value('password')));
        $this->assertTrue($this->denetimVar('sifre_degistir'));
    }

    public function test_yazma_kapaliyken_409(): void
    {
        $ben = $this->olarak('super_admin');
        config(['sistem.yazma_aktif' => false]);
        $this->putJson(self::KOK.'/profil', ['name' => 'Ad', 'email' => $ben->email])->assertStatus(409);
        $this->postJson(self::KOK.'/ekip', [])->assertStatus(409);
    }
}
