<?php

namespace Tests\Feature\Sistem\Izleme;

use App\Services\SistemYonetim\Izleme\IzlemeZamani;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Deklaratif kural yazimi (panel iptables calistirmaz), denetim izi, engelli listesi ve watchdog durumu. */
class GuvenlikDuvariTest extends IzlemeTestTabani
{
    private const KOK_GD = self::KOK.'/guvenlik-duvari';

    private function saat(string $fark = 'now'): string
    {
        return IzlemeZamani::simdi()->modify($fark)->format('Y-m-d H:i:s');
    }

    private function heartbeat(string $fark): void
    {
        Schema::create('guvenlik_durum', function (Blueprint $t) {
            $t->string('anahtar')->primary();
            $t->string('deger')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        DB::table('guvenlik_durum')->insert([
            ['anahtar' => 'heartbeat', 'deger' => null, 'updated_at' => $this->saat($fark)],
            ['anahtar' => 'aktif_ban', 'deger' => '4', 'updated_at' => $this->saat($fark)],
        ]);
    }

    public function test_tablo_yoksa_watchdog_kurulmamis_gorunur(): void
    {
        $this->olarak('yonetici');
        $this->getJson(self::KOK_GD)->assertOk()->assertJsonPath('veri.watchdog.durum', 'kurulmamis')->assertJsonPath('veri.watchdog.canli', false);
    }

    public function test_heartbeat_tazeyse_calisiyor(): void
    {
        $this->olarak('yonetici');
        $this->heartbeat('-1 minute');
        $this->getJson(self::KOK_GD)->assertOk()->assertJsonPath('veri.watchdog.durum', 'calisiyor')->assertJsonPath('veri.watchdog.aktif_ban', 4);
    }

    public function test_yerel_saatle_yazilan_eski_heartbeat_durmus_sayilir(): void
    {
        $this->olarak('yonetici');
        $this->heartbeat('-10 minutes');
        $this->getJson(self::KOK_GD)->assertOk()->assertJsonPath('veri.watchdog.durum', 'durmus');
    }

    public function test_engelli_listesi_ip_basina_son_olay_whitelist_ve_eskiler_haric(): void
    {
        $this->olarak('yonetici');
        $olay = fn (string $ip, string $zaman, int $deger) => DB::table('guvenlik_olaylari')->insert(['tur' => 'flood', 'ip' => $ip, 'deger' => $deger, 'aksiyon' => 'engellendi', 'created_at' => $zaman]);
        $olay('1.1.1.1', $this->saat('-2 hours'), 10);
        $olay('1.1.1.1', $this->saat('-1 hour'), 20);
        $olay('2.2.2.2', $this->saat('-1 hour'), 30);
        $olay('3.3.3.3', $this->saat('-3 days'), 40);
        DB::table('guvenlik_ip_kurallari')->insert(['ip' => '2.2.2.2', 'tip' => 'whitelist']);

        $this->getJson(self::KOK_GD.'/engelliler')->assertOk()
            ->assertJsonPath('veri.total', 1)
            ->assertJsonPath('veri.data.0.ip', '1.1.1.1')
            ->assertJsonPath('veri.data.0.deger_metin', '20 bağ.');
        $this->getJson(self::KOK_GD)->assertOk()->assertJsonPath('veri.ozet.engelli_aktif', 1)->assertJsonPath('veri.ozet.son24_engel', 3);
    }

    public function test_gecersiz_ip_reddedilir_tablo_degismez(): void
    {
        $this->olarak('yonetici');
        foreach (['blacklist', 'whitelist', 'engel-kaldir'] as $yol) {
            $this->postJson(self::KOK_GD."/{$yol}", ['ip' => '1.2.3; rm -rf /'])->assertStatus(422);
        }
        $this->assertSame(0, DB::table('guvenlik_ip_kurallari')->count());
    }

    public function test_blacklist_kural_yazar_denetime_ekleyen_adiyla_kaydeder(): void
    {
        $this->olarak('yonetici', ['name' => 'Ayse']);
        $this->postJson(self::KOK_GD.'/blacklist', ['ip' => '5.5.5.5', 'aciklama' => 'Saldirgan'])->assertOk();

        $kural = DB::table('guvenlik_ip_kurallari')->where('ip', '5.5.5.5')->first();
        $this->assertSame('blacklist', $kural->tip);
        $this->assertSame('Ayse', $kural->ekleyen);
        $this->assertTrue($this->denetimVar('guvenlik_blacklist_ekle'));
        $this->getJson(self::KOK_GD.'/kurallar?tip=blacklist')->assertOk()->assertJsonPath('veri.total', 1);
        $this->getJson(self::KOK_GD.'/kurallar?tip=baska')->assertStatus(422);
    }

    public function test_engel_kaldirma_ayni_ipyi_whitelista_cevirir_ve_olay_yazar(): void
    {
        $this->olarak('yonetici');
        DB::table('guvenlik_ip_kurallari')->insert(['ip' => '6.6.6.6', 'tip' => 'blacklist']);

        $this->postJson(self::KOK_GD.'/engel-kaldir', ['ip' => '6.6.6.6'])->assertOk();

        $this->assertSame(1, DB::table('guvenlik_ip_kurallari')->count());
        $this->assertSame('whitelist', DB::table('guvenlik_ip_kurallari')->value('tip'));
        $this->assertSame('izlendi', DB::table('guvenlik_olaylari')->where('ip', '6.6.6.6')->value('aksiyon'));
        $meta = json_decode(DB::table('sistemyonetim_audit_log')->where('action', 'guvenlik_engel_kaldir')->value('meta'), true);
        $this->assertSame('blacklist', $meta['eski_tip']);
    }

    public function test_kural_silme_denetime_yazilir_olmayan_404(): void
    {
        $this->olarak('yonetici');
        $id = DB::table('guvenlik_ip_kurallari')->insertGetId(['ip' => '7.7.7.7', 'tip' => 'whitelist']);

        $this->deleteJson(self::KOK_GD."/kural/{$id}")->assertOk();
        $this->assertSame(0, DB::table('guvenlik_ip_kurallari')->count());
        $this->assertTrue($this->denetimVar('guvenlik_kural_sil'));
        $this->deleteJson(self::KOK_GD."/kural/{$id}")->assertStatus(404);
    }

    public function test_son_olaylar_yuz_ile_sinirli_ve_deger_bicimli(): void
    {
        $this->olarak('yonetici');
        for ($i = 0; $i < 105; $i++) {
            DB::table('guvenlik_olaylari')->insert(['tur' => 'load_yuksek', 'deger' => 250, 'aksiyon' => 'uyari', 'created_at' => $this->saat()]);
        }
        $this->getJson(self::KOK_GD.'/olaylar?per_page=10')->assertOk()
            ->assertJsonPath('veri.total', 100)
            ->assertJsonCount(10, 'veri.data')
            ->assertJsonPath('veri.data.0.deger_metin', '2.5')
            ->assertJsonPath('veri.data.0.aksiyon_etiket', 'Uyarı');
    }
}
