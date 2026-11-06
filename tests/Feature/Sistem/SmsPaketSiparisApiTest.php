<?php

namespace Tests\Feature\Sistem;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SmsPaketSiparisApiTest extends TestCase
{
    use DestekTestKurulumu;

    private const PAKET = '/api/v2/sistem/sms-paket';

    private const SIPARIS = '/api/v2/sistem/paket-siparis';

    protected function setUp(): void
    {
        parent::setUp();
        $this->destekKur();
    }

    private function siparis(array $alan = []): int
    {
        return DB::table('sms_paket_siparisleri')->insertGetId($alan + ['salon_id' => 1, 'sms_adet' => 1000, 'tutar' => 500, 'merchant_oid' => uniqid(), 'created_at' => now()]);
    }

    public function test_paket_yazma_yalniz_yonetimde(): void
    {
        $id = DB::table('sms_paketleri')->insertGetId(['sms_adet' => 100, 'ucret' => 120]);
        foreach (['destek', 'izleyici'] as $rol) {
            $this->girisYap($rol);
            $this->getJson(self::PAKET)->assertOk();
            $this->postJson(self::PAKET, ['sms_adet' => 1, 'ucret' => 1])->assertStatus(403);
            $this->putJson(self::PAKET."/{$id}", ['sms_adet' => 1, 'ucret' => 1])->assertStatus(403);
            $this->deleteJson(self::PAKET."/{$id}")->assertStatus(403);
        }
    }

    public function test_paket_kdv_sira_ve_crud(): void
    {
        DB::table('sms_paketleri')->insert(['sms_adet' => 5000, 'ucret' => 600]);
        $this->girisYap();

        $id = $this->postJson(self::PAKET, ['sms_adet' => 100, 'ucret' => 120])->assertStatus(201)->json('veri.id');
        $this->assertSame('primary', DB::table('sms_paketleri')->where('id', $id)->value('class'));
        $this->assertTrue($this->denetimVar('sms_paket_olustur', $id));

        $this->getJson(self::PAKET)->assertJsonPath('veri.data.0.id', $id)
            ->assertJsonPath('veri.data.0.matrah', 100)->assertJsonPath('veri.data.0.kdv', 20);
        $this->getJson(self::PAKET.'/secenekler')->assertJsonPath('veri.kdv_yuzde', 20)->assertJsonCount(5, 'veri.kart_renkleri');

        $this->putJson(self::PAKET."/{$id}", ['sms_adet' => 200, 'ucret' => 240, 'class' => 'danger', 'paket_adi' => 'Büyük'])->assertOk();
        $this->assertSame('Büyük', DB::table('sms_paketleri')->where('id', $id)->value('paket_adi'));

        $this->postJson(self::PAKET, ['sms_adet' => 0, 'ucret' => 1])->assertStatus(422);
        $this->postJson(self::PAKET, ['sms_adet' => 1, 'ucret' => 1, 'class' => 'uydurma'])->assertStatus(422);

        $this->deleteJson(self::PAKET."/{$id}")->assertOk();
        $this->assertTrue($this->denetimVar('sms_paket_sil', $id));
    }

    public function test_siparis_listesi_bekleyen_ve_rozetler(): void
    {
        DB::table('salonlar')->insert(['id' => 1, 'salon_adi' => 'Salon A']);
        $this->siparis(['durum' => 1]);
        $this->siparis(['durum' => 1, 'yukleme_durumu' => 1, 'fatura_durumu' => 1]);
        $this->siparis(['durum' => 0, 'salon_id' => 777]);
        $this->girisYap('izleyici');

        $v = $this->getJson(self::SIPARIS)->assertOk()->assertJsonPath('veri.bekleyen', 1)->assertJsonPath('veri.yazabilir', false)->json('veri.data');
        $this->assertSame('Salon #777', $v[0]['salon_adi']);
        $this->assertNull($v[0]['yukleme']);
        $this->assertFalse($v[0]['yuklenebilir']);
        $this->assertSame('Yüklendi', $v[1]['yukleme']['etiket']);
        $this->assertSame('Kesildi', $v[1]['fatura']['etiket']);
        $this->assertSame('Bekliyor', $v[2]['yukleme']['etiket']);
        $this->assertTrue($v[2]['yuklenebilir']);
    }

    public function test_yuklendi_yalniz_odenmis_ve_bir_kez(): void
    {
        $odenmis = $this->siparis(['durum' => 1]);
        $odenmemis = $this->siparis(['durum' => 0]);

        $this->girisYap('izleyici');
        $this->postJson(self::SIPARIS."/{$odenmis}/yuklendi")->assertStatus(403);

        $this->girisYap('destek', 'Ali');
        $this->postJson(self::SIPARIS."/{$odenmis}/yuklendi")->assertOk();
        $s = DB::table('sms_paket_siparisleri')->find($odenmis);
        $this->assertSame(1, (int) $s->yukleme_durumu);
        $this->assertSame('Ali', $s->yukleyen);
        $this->assertNotNull($s->yukleme_tarihi);
        $this->assertTrue($this->denetimVar('duyuru_paketi_yuklendi', $odenmis));

        $this->postJson(self::SIPARIS."/{$odenmis}/yuklendi")->assertStatus(422);
        $this->postJson(self::SIPARIS."/{$odenmemis}/yuklendi")->assertStatus(422);
        $this->postJson(self::SIPARIS.'/99999/yuklendi')->assertStatus(404);
    }
}
