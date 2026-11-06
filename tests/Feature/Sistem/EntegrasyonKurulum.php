<?php

namespace Tests\Feature\Sistem;

use App\Models\SistemYoneticisi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Entegrasyon testlerinin ortagi: izole sqlite + gecici JSON ayar dosyalari + rolle giris. */
trait EntegrasyonKurulum
{
    private array $geciciDosyalar = [];

    protected function ortamKur(): void
    {
        config([
            'database.default' => 'entegrasyon_sqlite',
            'database.connections.entegrasyon_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'sistem.wa_ayar_dosyasi' => $this->geciciDosya(),
            'sistem.ai_ayar_dosyasi' => $this->geciciDosya(),
            'sistem.yazma_aktif' => true,
        ]);
        DB::purge('entegrasyon_sqlite');
        DB::setDefaultConnection('entegrasyon_sqlite');

        Schema::create('sistemyoneticileri', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'email', 'password', 'rol'] as $k) {
                $t->string($k)->nullable();
            }
            $t->integer('admin')->default(0);
            $t->integer('aktif')->default(1);
        });
        Schema::create('sistemyonetim_audit_log', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id')->nullable();
            foreach (['user_name', 'user_rol', 'action', 'target_type', 'target_label', 'ip', 'user_agent'] as $k) {
                $t->string($k)->nullable();
            }
            $t->integer('target_id')->nullable();
            $t->text('aciklama')->nullable();
            $t->text('meta')->nullable();
            $t->timestamps();
        });
        Schema::create('salonlar', function (Blueprint $t) {
            $t->id();
            foreach (['salon_adi', 'yetkili_adi', 'yetkili_telefon', 'whatsapp_durum', 'whatsapp_numara', 'whatsapp_son_hata', 'whatsapp_saglayici', 'whatsapp_paket', 'whatsapp_paket_periyot'] as $k) {
                $t->string($k)->nullable();
            }
            foreach (['whatsapp_baglanti_tarihi', 'whatsapp_paket_baslangic', 'whatsapp_paket_bitis', 'whatsapp_deneme_bitis', 'uyelik_bitis_tarihi', 'created_at'] as $k) {
                $t->dateTime($k)->nullable();
            }
            foreach (['whatsapp_aktif', 'whatsapp_paket_deneme', 'whatsapp_kontor'] as $k) {
                $t->integer($k)->default(0);
            }
            foreach (['whatsapp_gunluk_limit', 'whatsapp_paylasilan_salon_id', 'uyelik_turu', 'musteri_yetkili_id'] as $k) {
                $t->integer($k)->nullable();
            }
        });
    }

    protected function yonetici(string $rol, int $id = 0): SistemYoneticisi
    {
        $id = $id ?: (int) DB::table('sistemyoneticileri')->max('id') + 1;
        DB::table('sistemyoneticileri')->insert(['id' => $id, 'name' => ucfirst($rol), 'email' => "{$rol}{$id}@test.tr", 'rol' => $rol]);

        return SistemYoneticisi::findOrFail($id);
    }

    protected function girisYap(string $rol, int $id = 0): SistemYoneticisi
    {
        $y = $this->yonetici($rol, $id);
        $this->actingAs($y, 'sistemyonetim-api');

        return $y;
    }

    protected function denetimVar(string $islem): bool
    {
        return DB::table('sistemyonetim_audit_log')->where('action', $islem)->exists();
    }

    protected function geciciDosya(): string
    {
        $yol = tempnam(sys_get_temp_dir(), 'sy_ent_');
        unlink($yol);

        return $this->geciciDosyalar[] = $yol;
    }

    protected function tearDown(): void
    {
        foreach ($this->geciciDosyalar as $yol) {
            @unlink($yol);
        }
        parent::tearDown();
    }
}
