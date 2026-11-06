<?php

namespace Tests\Feature\Sistem\Izleme;

use App\Services\SistemYonetim\Izleme\IzlemeZamani;
use App\Services\SistemYonetim\Izleme\SistemSaglikServisi;
use Illuminate\Support\Facades\DB;

/** Rol kapilari, dashboard, risk kapsami, performans toplamlari, aktivite ve sistem sagligi. */
class IzlemeOkumaTest extends IzlemeTestTabani
{
    private function saat(int $gunOnce = 0): string
    {
        return IzlemeZamani::simdi()->subDays($gunOnce)->format('Y-m-d H:i:s');
    }

    public function test_rol_kapilari_eski_gerektir_listeleriyle_ayni(): void
    {
        foreach (['destek', 'izleyici'] as $rol) {
            $this->olarak($rol);
            foreach (['/performans', '/ekip', '/aktivite', '/aktivite/csv', '/guvenlik/girisler', '/guvenlik/impersonation', '/guvenlik-duvari', '/sistem-saglik'] as $yol) {
                $this->getJson(self::KOK.$yol)->assertStatus(403);
            }
            $this->getJson(self::KOK.'/dashboard')->assertOk();
            $this->getJson(self::KOK.'/risk')->assertOk();
            $this->getJson(self::KOK.'/profil')->assertOk();
        }

        $this->olarak('yonetici');
        $this->getJson(self::KOK.'/ekip')->assertOk();
        $this->postJson(self::KOK.'/ekip', [])->assertStatus(403);
    }

    public function test_dashboard_tek_uctan_gelir_grafik_gun_sinirini_uygular(): void
    {
        $this->olarak('izleyici');
        DB::table('salonlar')->insert(['salon_adi' => 'A', 'askiya_alindi' => 0, 'created_at' => $this->saat()]);
        DB::table('salonlar')->insert(['salon_adi' => 'B', 'askiya_alindi' => 1, 'created_at' => $this->saat(40)]);
        DB::table('sistemyonetim_destek_talepleri')->insert(['numara' => 'T1', 'konu' => 'Konu', 'durum' => 'acik', 'oncelik' => 'dusuk', 'kategori' => 'teknik', 'created_at' => $this->saat()]);
        DB::table('sistemyonetim_destek_talepleri')->insert(['numara' => 'T2', 'konu' => 'Konu', 'durum' => 'acik', 'oncelik' => 'acil', 'kategori' => 'odeme', 'created_at' => $this->saat(40)]);

        $this->getJson(self::KOK.'/dashboard')->assertOk()
            ->assertJsonPath('veri.metrikler.toplam_salon', 2)
            ->assertJsonPath('veri.metrikler.askida_salon', 1)
            ->assertJsonPath('veri.metrikler.bugun_yeni_salon', 1)
            ->assertJsonPath('veri.metrikler.acil_ticket', 1)
            ->assertJsonPath('veri.bekleyen_talepler.0.numara', 'T2')
            ->assertJsonCount(7, 'veri.trend');

        $this->getJson(self::KOK.'/dashboard/grafik?gun=7')->assertOk()
            ->assertJsonCount(7, 'veri.tarihler')
            ->assertJsonPath('veri.salon.6', 1)
            ->assertJsonPath('veri.kategori_dagilim.teknik', 1)
            ->assertJsonMissingPath('veri.kategori_dagilim.odeme')
            ->assertJsonPath('veri.durum_dagilim.acik', 2);
        $this->getJson(self::KOK.'/dashboard/grafik?gun=3')->assertOk()->assertJsonCount(30, 'veri.tarihler');
    }

    public function test_risk_listesi_esik_siralama_ve_destek_kapsami(): void
    {
        $destek = $this->yonetici('destek', ['name' => 'Temsilci']);
        $saglikli = DB::table('salonlar')->insertGetId(['salon_adi' => 'Saglikli', 'whatsapp_aktif' => 1]);
        for ($i = 0; $i < 60; $i++) {
            DB::table('randevular')->insert(['salon_id' => $saglikli, 'created_at' => $this->saat()]);
        }
        $orta = DB::table('salonlar')->insertGetId(['salon_adi' => 'Riskli', 'musteri_yetkili_id' => $destek->id]);
        DB::table('randevular')->insert(['salon_id' => $orta, 'created_at' => $this->saat()]);
        DB::table('salonlar')->insert(['salon_adi' => 'Kritik']);
        DB::table('salonlar')->insert(['salon_adi' => 'Askida', 'askiya_alindi' => 1]);

        $this->olarak('yonetici');
        $this->getJson(self::KOK.'/risk')->assertOk()
            ->assertJsonPath('veri.total', 2)
            ->assertJsonPath('veri.data.0.salon_adi', 'Kritik')
            ->assertJsonPath('veri.data.0.durum', 'kritik')
            ->assertJsonPath('veri.data.1.mt_ad', 'Temsilci');

        // Ayni onbellek, farkli rol: destek yalniz kendi salonunu gorur.
        $this->actingAs($destek, 'sistemyonetim-api');
        $this->getJson(self::KOK.'/risk')->assertOk()->assertJsonPath('veri.total', 1)->assertJsonPath('veri.data.0.salon_adi', 'Riskli');
    }

    public function test_yetkili_guncellemesi_son_giris_sinyaline_girer(): void
    {
        $salon = DB::table('salonlar')->insertGetId(['salon_adi' => 'Girisli']);
        $yetkili = DB::table('isletmeyetkilileri')->insertGetId(['name' => 'Y', 'updated_at' => $this->saat()]);
        DB::table('salon_personelleri')->insert(['salon_id' => $salon, 'yetkili_id' => $yetkili]);

        $this->olarak('yonetici');
        $this->getJson(self::KOK.'/risk')->assertOk()->assertJsonPath('veri.data.0.skor', 25);
    }

    public function test_performans_toplamlari_kullanici_bazinda(): void
    {
        $uye = $this->olarak('yonetici', ['name' => 'Ayse']);
        $simdi = IzlemeZamani::simdi();
        DB::table('sistemyonetim_destek_talepleri')->insert([
            'atanan_user_id' => $uye->id, 'durum' => 'kapali', 'created_at' => $simdi->copy()->subHours(2)->format('Y-m-d H:i:s'),
            'ilk_yanit_tarihi' => $simdi->copy()->subHour()->format('Y-m-d H:i:s'), 'cozumlenme_tarihi' => $simdi->format('Y-m-d H:i:s'),
        ]);
        DB::table('sistemyonetim_destek_talepleri')->insert(['atanan_user_id' => $uye->id, 'durum' => 'acik', 'created_at' => $this->saat()]);
        DB::table('sistemyonetim_destek_talepleri')->insert(['atanan_user_id' => $uye->id, 'durum' => 'acik', 'created_at' => $this->saat(60)]);
        DB::table('sistemyonetim_destek_mesajlari')->insert(['user_id' => $uye->id, 'created_at' => $this->saat()]);
        DB::table('sistemyonetim_impersonation_loglari')->insert(['user_id' => $uye->id, 'user_name' => 'Ayse', 'salon_id' => 1, 'baslangic_tarihi' => $this->saat()]);

        $this->getJson(self::KOK.'/performans?gun=30')->assertOk()
            ->assertJsonPath('veri.genel.toplam', 2)
            ->assertJsonPath('veri.genel.cozum_orani', 50.0)
            ->assertJsonPath('veri.genel.ort_yanit_dk', 60.0)
            ->assertJsonPath('veri.data.0.ad', 'Ayse')
            ->assertJsonPath('veri.data.0.toplam', 2)
            ->assertJsonPath('veri.data.0.cozulen', 1)
            ->assertJsonPath('veri.data.0.acik', 1)
            ->assertJsonPath('veri.data.0.mesaj_sayisi', 1)
            ->assertJsonPath('veri.data.0.imp_sayisi', 1);
    }

    public function test_aktivite_filtreleri_ve_csv(): void
    {
        $y = $this->olarak('yonetici');
        DB::table('sistemyonetim_audit_log')->insert(['user_id' => $y->id, 'user_name' => 'Ali', 'action' => 'salon_pasif', 'target_label' => 'Salon A', 'meta' => '{"a":1}', 'created_at' => '2026-05-10 10:00:00']);
        DB::table('sistemyonetim_audit_log')->insert(['user_id' => 999, 'user_name' => 'Veli', 'action' => 'login', 'target_label' => 'B', 'created_at' => '2026-05-11 10:00:00']);

        $this->getJson(self::KOK.'/aktivite?action=salon_pasif')->assertOk()->assertJsonPath('veri.total', 1)->assertJsonPath('veri.data.0.meta.a', 1);
        $this->getJson(self::KOK.'/aktivite?tarih=2026-05-11')->assertOk()->assertJsonPath('veri.data.0.kullanici', 'Veli');
        $this->getJson(self::KOK.'/aktivite?ara=Salon')->assertOk()->assertJsonPath('veri.total', 1);
        $this->getJson(self::KOK.'/aktivite?tarih=10.05.2026')->assertStatus(422);
        $this->getJson(self::KOK.'/aktivite/secenekler')->assertOk()->assertJsonCount(2, 'veri.aksiyonlar');

        $csv = $this->get(self::KOK.'/aktivite/csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Salon A', $csv);
        $this->assertTrue($this->denetimVar('csv_export'));
    }

    public function test_guvenlik_loglari_basari_ve_sure_alanlari(): void
    {
        $this->olarak('yonetici');
        DB::table('sistemyonetim_login_loglari')->insert(['email_attempt' => 'a@b.c', 'basarili' => 1, 'created_at' => $this->saat()]);
        DB::table('sistemyonetim_impersonation_loglari')->insert(['user_id' => 1, 'user_name' => 'A', 'salon_id' => 2, 'salon_adi' => 'S', 'baslangic_tarihi' => '2026-05-10 10:00:00', 'bitis_tarihi' => '2026-05-10 11:00:00']);
        DB::table('sistemyonetim_impersonation_loglari')->insert(['user_id' => 1, 'user_name' => 'A', 'salon_id' => 2, 'salon_adi' => 'S', 'baslangic_tarihi' => '2026-05-11 10:00:00']);

        $this->getJson(self::KOK.'/guvenlik/girisler')->assertOk()->assertJsonPath('veri.data.0.basarili', true);
        $r = $this->getJson(self::KOK.'/guvenlik/impersonation')->assertOk()->assertJsonPath('veri.data.0.sure', null);
        $this->assertNotNull($r->json('veri.data.1.sure'));
    }

    public function test_sistem_sagligi_db_hatasini_istemciye_vermez(): void
    {
        $this->olarak('super_admin');
        $govde = $this->getJson(self::KOK.'/sistem-saglik')->assertOk()->assertJsonPath('veri.veritabani.durum', 'HATA')->getContent();
        $this->assertStringNotContainsString('no such function', $govde);
    }

    public function test_log_hatalari_kuyruk_kisaltma_ve_bozuk_utf8(): void
    {
        $yol = tempnam(sys_get_temp_dir(), 'log');
        file_put_contents($yol, "[x] local.INFO: bilgi\n[x] local.ERROR: ".str_repeat('a', 400)."\n[x] local.CRITICAL: bozuk \xC3\n");

        $hatalar = SistemSaglikServisi::logHatalari($yol);
        unlink($yol);

        $this->assertCount(2, $hatalar);
        $this->assertStringContainsString('CRITICAL', $hatalar[0]);
        $this->assertSame(250, mb_strlen($hatalar[1]));
        $this->assertNotFalse(json_encode($hatalar));
        $this->assertSame([], SistemSaglikServisi::logHatalari('/yok/boyle/dosya.log'));
    }
}
