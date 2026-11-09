<?php

namespace Tests\Feature\Sistem\Izleme;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Salon hesabina gir" (imp_, 10 saat) oturumu, yonetici cikis yapinca ya da pasife alininca kapanir: token
 * iptal, log bitis tarihi dolar. Baska yoneticinin oturumu ve zaten kapali log etkilenmez.
 */
class ImpersonationKapanisTest extends IzlemeTestTabani
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('oauth_access_tokens', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->integer('user_id')->nullable();
            $t->string('name')->nullable();
            $t->integer('revoked')->default(0);
            $t->timestamps();
        });
    }

    /** Acik impersonation logu + token satiri. */
    private function oturum(int $yoneticiId, bool $kapali = false): int
    {
        $logId = DB::table('sistemyonetim_impersonation_loglari')->insertGetId([
            'user_id' => $yoneticiId, 'salon_id' => 1, 'baslangic_tarihi' => now(), 'bitis_tarihi' => $kapali ? now() : null,
        ]);
        DB::table('oauth_access_tokens')->insert(['id' => 'imp_'.$logId, 'user_id' => 9, 'name' => 'impersonation:'.$logId, 'revoked' => 0]);

        return $logId;
    }

    private function iptalMi(int $logId): bool
    {
        return (int) DB::table('oauth_access_tokens')->where('id', 'imp_'.$logId)->value('revoked') === 1;
    }

    public function test_cikis_acik_salon_girislerini_kapatir(): void
    {
        $ben = $this->olarak('destek');
        $acik = $this->oturum($ben->id);
        $baskasi = $this->oturum($this->yonetici('destek')->id);

        $this->postJson(self::KOK.'/oturum/cikis')->assertOk();

        $this->assertTrue($this->iptalMi($acik));
        $this->assertNotNull(DB::table('sistemyonetim_impersonation_loglari')->where('id', $acik)->value('bitis_tarihi'));
        $this->assertFalse($this->iptalMi($baskasi));
        $this->assertNull(DB::table('sistemyonetim_impersonation_loglari')->where('id', $baskasi)->value('bitis_tarihi'));
    }

    public function test_pasife_alinan_yoneticinin_salon_girisleri_kapanir(): void
    {
        $this->olarak('super_admin');
        $hedef = $this->yonetici('destek');
        $acik = $this->oturum($hedef->id);

        $this->postJson(self::KOK."/ekip/{$hedef->id}/pasif")->assertOk();

        $this->assertTrue($this->iptalMi($acik));
        $this->assertNotNull(DB::table('sistemyonetim_impersonation_loglari')->where('id', $acik)->value('bitis_tarihi'));
    }

    public function test_duzenlemede_pasif_yapilinca_da_kapanir(): void
    {
        $this->olarak('super_admin');
        $hedef = $this->yonetici('destek');
        $acik = $this->oturum($hedef->id);

        $this->putJson(self::KOK."/ekip/{$hedef->id}", ['name' => 'Ad', 'email' => $hedef->email, 'rol' => 'destek', 'aktif' => false])->assertOk();

        $this->assertTrue($this->iptalMi($acik));
    }
}
