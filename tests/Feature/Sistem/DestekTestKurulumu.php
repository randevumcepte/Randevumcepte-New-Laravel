<?php

namespace Tests\Feature\Sistem;

use App\Models\SistemYoneticisi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Destek ve icerik testlerinin izole sqlite semasi: paylasilan veritabanina dokunmaz. Kimlik actingAs ile. */
trait DestekTestKurulumu
{
    protected function destekKur(): void
    {
        config([
            'sistem.yazma_aktif' => true,
            'database.default' => 'destek_sqlite',
            'database.connections.destek_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('destek_sqlite');
        DB::setDefaultConnection('destek_sqlite');

        Schema::create('sistemyoneticileri', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'email', 'password', 'rol'] as $k) {
                $t->string($k)->nullable();
            }
            $t->integer('admin')->default(0);
            $t->integer('aktif')->default(1);
            $t->timestamps();
        });
        Schema::create('isletmeyetkilileri', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
        });
        Schema::create('salonlar', function (Blueprint $t) {
            $t->id();
            $t->string('salon_adi')->nullable();
            $t->integer('il_id')->nullable();
            $t->integer('musteri_yetkili_id')->nullable();
        });
        Schema::create('il', function (Blueprint $t) {
            $t->id();
            $t->string('il_adi');
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
        Schema::create('sistemyonetim_destek_talepleri', function (Blueprint $t) {
            $t->id();
            $t->string('numara', 20)->unique();
            $t->integer('salon_id')->nullable();
            foreach (['salon_adi', 'iletisim_ad', 'iletisim_telefon', 'iletisim_email', 'konu', 'atanan_user_name', 'olusturan_user_name'] as $k) {
                $t->string($k)->nullable();
            }
            $t->text('aciklama')->nullable();
            $t->string('kategori')->default('diger');
            $t->string('oncelik')->default('orta');
            $t->string('durum')->default('acik');
            $t->integer('atanan_user_id')->nullable();
            $t->integer('olusturan_user_id')->nullable();
            foreach (['ilk_yanit_tarihi', 'cozumlenme_tarihi', 'kapanis_tarihi'] as $k) {
                $t->timestamp($k)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('sistemyonetim_destek_mesajlari', function (Blueprint $t) {
            $t->id();
            $t->integer('ticket_id');
            $t->integer('user_id')->nullable();
            $t->string('user_name');
            $t->string('user_tipi')->default('ekip');
            $t->text('mesaj');
            $t->integer('ic_not')->default(0);
            $t->timestamps();
        });
        Schema::create('sistemyonetim_hazir_cevaplar', function (Blueprint $t) {
            $t->id();
            $t->string('baslik');
            $t->text('icerik');
            $t->string('kategori')->default('genel');
            $t->string('kisayol')->nullable();
            $t->integer('kullanim_sayisi')->default(0);
            $t->integer('olusturan_user_id')->nullable();
            $t->string('olusturan_user_name')->nullable();
            $t->integer('aktif')->default(1);
            $t->timestamps();
        });
        Schema::create('sistemyonetim_duyurular', function (Blueprint $t) {
            $t->id();
            $t->string('baslik');
            $t->text('icerik');
            $t->string('tip')->default('bilgi');
            $t->string('hedef_tipi')->default('hepsi');
            $t->text('hedef_ids')->nullable();
            $t->timestamp('baslangic_tarihi')->nullable();
            $t->timestamp('bitis_tarihi')->nullable();
            $t->integer('aktif')->default(1);
            $t->integer('sticky')->default(0);
            $t->string('cta_metin')->nullable();
            $t->string('cta_link')->nullable();
            $t->integer('olusturan_user_id')->nullable();
            $t->string('olusturan_user_name')->nullable();
            $t->timestamps();
        });
        Schema::create('sistemyonetim_duyuru_okundu', function (Blueprint $t) {
            $t->id();
            $t->integer('duyuru_id');
            $t->integer('salon_id');
            $t->integer('user_id')->nullable();
            $t->timestamp('okundu_tarihi')->nullable();
        });
        // Gercek tabloda da zaman damgasi yok: servis yazmaya kalkarsa test patlar.
        Schema::create('sms_paketleri', function (Blueprint $t) {
            $t->id();
            $t->integer('sms_adet');
            $t->string('paket_adi')->nullable();
            $t->string('alt_baslik')->nullable();
            $t->float('ucret');
            $t->string('class')->nullable();
        });
        Schema::create('sms_paket_siparisleri', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->integer('paket_id')->nullable();
            $t->integer('sms_adet')->default(0);
            $t->float('tutar')->default(0);
            $t->string('merchant_oid')->unique();
            $t->integer('durum')->default(0);
            $t->integer('yukleme_durumu')->default(0);
            $t->dateTime('yukleme_tarihi')->nullable();
            $t->string('yukleyen')->nullable();
            $t->integer('fatura_durumu')->default(0);
            $t->timestamps();
        });
        Schema::create('musteri_formlari', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->integer('durum_id')->nullable();
            $t->dateTime('satis_tarihi')->nullable();
            $t->text('notlar')->nullable();
            $t->timestamps();
        });
        Schema::create('musteri_formlari_hizmetler', function (Blueprint $t) {
            $t->id();
            $t->integer('form_id');
            $t->integer('uyelik_id')->nullable();
            $t->string('aciklama')->nullable();
            $t->integer('adet')->default(1);
            $t->float('ucret')->default(0);
            $t->timestamps();
        });
    }

    protected function girisYap(string $rol = 'super_admin', string $ad = 'Ekip Üyesi'): SistemYoneticisi
    {
        $id = DB::table('sistemyoneticileri')->insertGetId(['name' => $ad, 'email' => uniqid().'@test.tr', 'rol' => $rol, 'aktif' => 1]);
        $yonetici = SistemYoneticisi::find($id);
        $this->actingAs($yonetici, 'sistemyonetim-api');

        return $yonetici;
    }

    protected function denetimVar(string $islem, ?int $hedefId = null): bool
    {
        return DB::table('sistemyonetim_audit_log')->where('action', $islem)
            ->when($hedefId, fn ($q, $id) => $q->where('target_id', $id))->exists();
    }
}
