<?php

namespace Tests\Feature\Sistem\Izleme;

use App\Models\SistemYoneticisi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Izleme + ekip testleri: izole sqlite, paylasilan veritabanina dokunmaz. */
abstract class IzlemeTestTabani extends TestCase
{
    protected const KOK = '/api/v2/sistem';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'izleme_sqlite',
            'database.connections.izleme_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('izleme_sqlite');
        DB::setDefaultConnection('izleme_sqlite');
        $this->semaKur();
    }

    protected function yonetici(string $rol, array $alan = []): SistemYoneticisi
    {
        $id = DB::table('sistemyoneticileri')->insertGetId($alan + [
            'name' => ucfirst($rol), 'email' => $rol.uniqid().'@test.tr', 'password' => Hash::make('gizli123'), 'rol' => $rol, 'admin' => $rol === 'super_admin' ? 1 : 0, 'aktif' => 1,
        ]);

        return SistemYoneticisi::find($id);
    }

    protected function olarak(string $rol, array $alan = []): SistemYoneticisi
    {
        $y = $this->yonetici($rol, $alan);
        $this->actingAs($y, 'sistemyonetim-api');

        return $y;
    }

    protected function denetimVar(string $islem): bool
    {
        return DB::table('sistemyonetim_audit_log')->where('action', $islem)->exists();
    }

    private function semaKur(): void
    {
        Schema::create('sistemyoneticileri', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'email', 'password', 'rol', 'telefon', 'son_giris_ip', 'remember_token'] as $k) {
                $t->string($k)->nullable();
            }
            $t->integer('admin')->default(0);
            $t->integer('aktif')->default(1);
            $t->timestamp('son_giris_tarihi')->nullable();
            $t->timestamps();
        });
        Schema::create('salonlar', function (Blueprint $t) {
            $t->id();
            $t->string('salon_adi')->nullable();
            $t->integer('musteri_yetkili_id')->nullable();
            $t->integer('whatsapp_aktif')->default(0);
            $t->integer('askiya_alindi')->default(0);
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('isletmeyetkilileri', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('salon_personelleri', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->integer('yetkili_id')->nullable();
        });
        Schema::create('randevular', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->timestamp('created_at')->nullable();
        });
        // TIMESTAMPDIFF(MINUTE, ...) sqlite'ta yok: birim adlari kolon olarak degerini verir, fonksiyon asagida tanimli.
        Schema::create('sistemyonetim_destek_talepleri', function (Blueprint $t) {
            $t->id();
            foreach (['numara', 'konu', 'salon_adi', 'kategori', 'oncelik', 'durum'] as $k) {
                $t->string($k)->nullable();
            }
            $t->integer('salon_id')->nullable();
            $t->integer('atanan_user_id')->nullable();
            $t->timestamp('ilk_yanit_tarihi')->nullable();
            $t->timestamp('cozumlenme_tarihi')->nullable();
            $t->string('MINUTE')->default('MINUTE');
            $t->string('HOUR')->default('HOUR');
            $t->timestamps();
        });
        $pdo = DB::connection()->getPdo();
        $fark = fn ($birim, $bas, $bit) => (strtotime($bit) - strtotime($bas)) / ($birim === 'HOUR' ? 3600 : 60);
        method_exists($pdo, 'createFunction') ? $pdo->createFunction('TIMESTAMPDIFF', $fark, 3) : $pdo->sqliteCreateFunction('TIMESTAMPDIFF', $fark, 3);

        Schema::create('sistemyonetim_destek_mesajlari', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id')->nullable();
            $t->timestamps();
        });
        Schema::create('sistemyonetim_salon_notlari', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->string('tip')->default('genel');
            $t->timestamps();
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
        Schema::create('sistemyonetim_login_loglari', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id')->nullable();
            foreach (['email_attempt', 'hata', 'ip', 'user_agent'] as $k) {
                $t->string($k)->nullable();
            }
            $t->integer('basarili')->default(0);
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('sistemyonetim_impersonation_loglari', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id');
            foreach (['user_name', 'salon_adi', 'isletme_yetkili_email', 'sebep', 'ip'] as $k) {
                $t->string($k)->nullable();
            }
            $t->integer('salon_id');
            $t->integer('ticket_id')->nullable();
            $t->timestamp('baslangic_tarihi')->nullable();
            $t->timestamp('bitis_tarihi')->nullable();
        });
        Schema::create('guvenlik_olaylari', function (Blueprint $t) {
            $t->id();
            $t->string('tur');
            $t->string('ip')->nullable();
            $t->integer('deger')->nullable();
            $t->string('aksiyon')->default('izlendi');
            $t->string('detay')->nullable();
            $t->integer('bildirildi')->default(0);
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('guvenlik_ip_kurallari', function (Blueprint $t) {
            $t->id();
            $t->string('ip')->unique();
            foreach (['tip', 'aciklama', 'ekleyen'] as $k) {
                $t->string($k)->nullable();
            }
            $t->timestamp('created_at')->nullable();
        });
    }
}
