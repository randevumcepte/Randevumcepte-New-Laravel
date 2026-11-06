<?php

namespace Tests\Feature\Sistem;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** WhatsApp Yonetim: rol kapilari, KVKK, filtreler, paket ve kontor yazmalari. */
class WhatsappYonetimTest extends TestCase
{
    use EntegrasyonKurulum;

    private const KOK = '/api/v2/sistem/whatsapp-yonetim';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ortamKur();
        Schema::create('whatsapp_gonderim_loglari', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->integer('user_id')->nullable();
            $t->integer('randevu_id')->nullable();
            $t->string('telefon');
            $t->text('mesaj');
            $t->integer('durum')->default(0);
            $t->string('hata')->nullable();
            $t->string('mesaj_id')->nullable();
            $t->dateTime('gonderim_tarihi')->nullable();
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('cep_telefon')->nullable();
        });
        Schema::create('whatsapp_kontor_hareketleri', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->string('tip');
            $t->integer('adet');
            $t->integer('bakiye_sonrasi')->nullable();
            $t->string('aciklama')->nullable();
            $t->timestamps();
        });
        DB::table('salonlar')->insert([
            ['id' => 1, 'salon_adi' => 'Bir', 'whatsapp_aktif' => 1, 'whatsapp_durum' => 'connected', 'whatsapp_numara' => '905300000001', 'musteri_yetkili_id' => 50, 'whatsapp_kontor' => 100],
            ['id' => 2, 'salon_adi' => 'Iki', 'whatsapp_aktif' => 0, 'whatsapp_durum' => 'banned-or-loggedout', 'whatsapp_numara' => null, 'musteri_yetkili_id' => 60, 'whatsapp_kontor' => 0],
            ['id' => 3, 'salon_adi' => 'Kullanmayan', 'whatsapp_aktif' => 0, 'whatsapp_durum' => null, 'whatsapp_numara' => null, 'musteri_yetkili_id' => 50, 'whatsapp_kontor' => 0],
        ]);
    }

    private function log(int $salon, int $durum, string $tarih, string $mesaj = 'Merhaba'): void
    {
        DB::table('whatsapp_gonderim_loglari')->insert(['salon_id' => $salon, 'telefon' => '905551112233', 'mesaj' => $mesaj, 'durum' => $durum, 'created_at' => $tarih]);
    }

    public function test_izleyici_log_goremez_ozet_gorur(): void
    {
        $this->girisYap('izleyici');

        $this->getJson(self::KOK.'/ozet')->assertOk();
        $this->getJson(self::KOK.'/secenekler')->assertOk()->assertJsonPath('veri.izinler.log', false)->assertJsonPath('veri.izinler.para', false);
        foreach (['/loglar', '/loglar/csv', '/mesaj/1', '/salon/1/aliciler', '/salon/1/aliciler/1/gecmis'] as $yol) {
            $this->getJson(self::KOK.$yol)->assertStatus(403);
        }
    }

    public function test_ozet_gunleri_sayar_ve_riskli_salonu_gosterir(): void
    {
        $this->girisYap('yonetici');
        $this->log(1, 1, now()->toDateTimeString());
        $this->log(1, 2, now()->toDateTimeString());
        $this->log(1, 1, now()->subDays(40)->toDateTimeString());

        $this->getJson(self::KOK.'/ozet')->assertOk()
            ->assertJsonPath('veri.bugun.toplam', 2)->assertJsonPath('veri.bugun.basari', 1)->assertJsonPath('veri.ay.toplam', 2)
            ->assertJsonPath('veri.aktif_salon', 1)->assertJsonPath('veri.ban_risk_salon', 1)->assertJsonCount(30, 'veri.gunler');
    }

    public function test_dagilim_mesaj_kalibina_gore_ayirir(): void
    {
        $this->girisYap('izleyici');
        $this->log(1, 1, now()->toDateTimeString(), 'Randevunuz iptal edilmiştir');
        $this->log(1, 3, now()->toDateTimeString(), 'Başka metin');

        $tipler = collect($this->getJson(self::KOK.'/dagilim?gun=30')->assertOk()->json('veri.tipler'))->keyBy('tip');
        $this->assertSame(1, $tipler['İptal/Red']['basari']);
        $this->assertSame(1, $tipler['Diğer']['fallback']);
    }

    public function test_salon_listesi_kullanmayani_cikarir_ve_destek_kendi_salonunu_gorur(): void
    {
        $this->girisYap('yonetici');
        $this->log(2, 1, now()->toDateTimeString());
        $this->getJson(self::KOK.'/salonlar')->assertOk()->assertJsonPath('veri.total', 2)
            ->assertJsonPath('veri.data.0.id', 1)->assertJsonPath('veri.data.0.saglayici.kod', 'whatsmeow')->assertJsonPath('veri.data.0.gunluk_limit', 150)
            ->assertJsonPath('veri.data.1.bugun_basari', 1)->assertJsonPath('veri.data.1.durum_renk', 'kirmizi');
        $this->getJson(self::KOK.'/salonlar?durum=connected')->assertJsonPath('veri.total', 1);

        $this->girisYap('destek', 50);
        $this->getJson(self::KOK.'/salonlar')->assertOk()->assertJsonPath('veri.total', 1)->assertJsonPath('veri.data.0.id', 1);
    }

    public function test_log_filtreleri_kuyruk_durumu_ve_tarih_araligi(): void
    {
        $this->girisYap('yonetici');
        $this->log(1, 0, '2026-03-10 23:59:00');
        $this->log(1, 1, '2026-03-11 00:00:00');
        $this->log(2, 2, '2026-03-09 12:00:00', 'gizli metin');

        $this->getJson(self::KOK.'/loglar?durum=0')->assertOk()->assertJsonPath('veri.total', 1)->assertJsonPath('veri.data.0.durum_ad', 'Kuyrukta');
        $this->getJson(self::KOK.'/loglar?baslangic=2026-03-10&bitis=2026-03-10')->assertJsonPath('veri.total', 1);
        $this->getJson(self::KOK.'/loglar?ara=gizli')->assertJsonPath('veri.total', 1)->assertJsonPath('veri.data.0.salon_adi', 'Iki');
        $this->getJson(self::KOK.'/loglar?baslangic=10.03.2026')->assertStatus(422);
    }

    public function test_destek_baska_salonun_logunu_ve_alicisini_goremez(): void
    {
        $this->log(1, 1, '2026-03-10 10:00:00');
        $this->log(2, 1, '2026-03-10 10:00:00');
        $baskaLog = (int) DB::table('whatsapp_gonderim_loglari')->where('salon_id', 2)->value('id');
        $this->girisYap('destek', 50);

        $this->getJson(self::KOK.'/loglar')->assertOk()->assertJsonPath('veri.total', 1)->assertJsonPath('veri.data.0.salon_id', 1);
        $this->getJson(self::KOK.'/mesaj/'.$baskaLog)->assertStatus(404);
        $this->getJson(self::KOK.'/salon/2/aliciler')->assertStatus(403);
        $this->getJson(self::KOK.'/salon/1/aliciler')->assertOk()->assertJsonPath('veri.total', 1)->assertJsonPath('veri.salon.salon_adi', 'Bir');
    }

    public function test_csv_iner_ve_denetime_yazilir(): void
    {
        $this->girisYap('yonetici');
        $this->log(1, 3, '2026-03-10 10:00:00', "iki\nsatir");

        $r = $this->get(self::KOK.'/loglar/csv')->assertOk();
        $icerik = $r->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $icerik);
        $this->assertStringContainsString("SMS'e Düştü", $icerik);
        $this->assertStringContainsString('iki satir', $icerik);
        $this->assertTrue($this->denetimVar('whatsapp_log_csv'));
    }

    public function test_kontor_yukleme_rol_hareket_ve_denetim(): void
    {
        $this->girisYap('destek', 50);
        $this->postJson(self::KOK.'/salon/1/kontor', ['adet' => 100])->assertStatus(403);

        $this->girisYap('yonetici');
        $this->postJson(self::KOK.'/salon/1/kontor', ['adet' => 0])->assertStatus(422);
        $this->postJson(self::KOK.'/salon/1/kontor', ['adet' => 40000, 'aciklama' => 'test'])->assertOk()->assertJsonPath('veri.bakiye', 40100);
        $this->postJson(self::KOK.'/salon/1/kontor', ['adet' => -100])->assertOk()->assertJsonPath('veri.bakiye', 40000);

        $this->assertSame(['yukleme', 'harcama'], DB::table('whatsapp_kontor_hareketleri')->orderBy('id')->pluck('tip')->all());
        $this->assertSame(40000, (int) DB::table('whatsapp_kontor_hareketleri')->orderByDesc('id')->value('bakiye_sonrasi'));
        $this->assertTrue($this->denetimVar('whatsapp_kontor_yukle'));
    }

    public function test_paket_deneme_ve_iptal(): void
    {
        $this->girisYap('izleyici');
        $this->getJson(self::KOK.'/salon/1/paket')->assertOk()->assertJsonPath('veri.paket', 'baslangic');
        $this->postJson(self::KOK.'/salon/1/deneme', [])->assertStatus(403);

        $this->girisYap('super_admin');
        $this->postJson(self::KOK.'/salon/1/deneme', ['paket' => 'baslangic'])->assertStatus(422);
        $this->postJson(self::KOK.'/salon/1/deneme', ['gun' => 30])->assertOk()->assertJsonPath('veri.paket', 'pro')->assertJsonPath('veri.gun', 30);
        $this->getJson(self::KOK.'/salon/1/paket')->assertJsonPath('veri.deneme', true)->assertJsonPath('veri.paket_ad', 'WhatsApp Hatırlatma');

        $this->postJson(self::KOK.'/salon/1/deneme/iptal')->assertOk();
        $s = DB::table('salonlar')->where('id', 1)->first();
        $this->assertSame('baslangic', $s->whatsapp_paket);
        $this->assertNull($s->whatsapp_paket_bitis);
        $this->assertTrue($this->denetimVar('whatsapp_deneme_baslat'));
        $this->assertTrue($this->denetimVar('whatsapp_deneme_iptal'));
    }

    public function test_manuel_paket_bos_alani_korur_baslangic_sifirlar(): void
    {
        $this->girisYap('yonetici');
        $yol = self::KOK.'/salon/1/paket';
        $this->putJson($yol, ['paket' => 'yanlis'])->assertStatus(422);
        $this->putJson($yol, ['paket' => 'pro', 'periyot' => 'aylik', 'baslangic' => '2026-01-01', 'bitis' => '2026-02-01'])->assertOk()->assertJsonPath('veri.periyot_ad', 'Aylık');
        $this->putJson($yol, ['paket' => 'pro', 'bitis' => '2026-03-01'])->assertOk()->assertJsonPath('veri.baslangic', '2026-01-01')->assertJsonPath('veri.bitis', '2026-03-01');
        $this->putJson($yol, ['paket' => 'baslangic'])->assertOk()->assertJsonPath('veri.periyot', null)->assertJsonPath('veri.bitis', null);
    }

    public function test_yazma_kapaliyken_409(): void
    {
        config(['sistem.yazma_aktif' => false]);
        $this->girisYap('super_admin');

        $this->postJson(self::KOK.'/salon/1/kontor', ['adet' => 5])->assertStatus(409);
        $this->assertSame(100, (int) DB::table('salonlar')->where('id', 1)->value('whatsapp_kontor'));
    }
}
