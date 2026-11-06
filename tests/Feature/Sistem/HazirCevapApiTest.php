<?php

namespace Tests\Feature\Sistem;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HazirCevapApiTest extends TestCase
{
    use DestekTestKurulumu;

    private const KOK = '/api/v2/sistem/hazir-cevap';

    private const TABLO = 'sistemyonetim_hazir_cevaplar';

    protected function setUp(): void
    {
        parent::setUp();
        $this->destekKur();
    }

    private function form(array $alan = []): array
    {
        return $alan + ['baslik' => 'Merhaba', 'icerik' => 'Merhaba içerik', 'kategori' => 'genel'];
    }

    public function test_izleyici_yazamaz_destek_yazar(): void
    {
        $this->girisYap('izleyici');
        $this->getJson(self::KOK)->assertOk();
        $this->postJson(self::KOK, $this->form())->assertStatus(403);

        $this->girisYap('destek');
        $this->postJson(self::KOK, $this->form())->assertStatus(201);
    }

    public function test_olustururken_aktif_alani_dikkate_alinir(): void
    {
        $this->girisYap();

        $varsayilan = $this->postJson(self::KOK, $this->form())->json('veri.id');
        $pasif = $this->postJson(self::KOK, $this->form(['aktif' => false]))->json('veri.id');

        $this->assertSame(1, (int) DB::table(self::TABLO)->where('id', $varsayilan)->value('aktif'));
        $this->assertSame(0, (int) DB::table(self::TABLO)->where('id', $pasif)->value('aktif'));
        $this->assertTrue($this->denetimVar('hazir_cevap_olustur', $varsayilan));
    }

    public function test_dogrulama(): void
    {
        $this->girisYap();

        $this->postJson(self::KOK, $this->form(['kategori' => 'uydurma']))->assertStatus(422);
        $this->postJson(self::KOK, $this->form(['kisayol' => str_repeat('x', 31)]))->assertStatus(422);
        $this->postJson(self::KOK, $this->form(['icerik' => 'x']))->assertStatus(422);
    }

    public function test_liste_en_cok_kullanilan_once_ve_guncelle_sil(): void
    {
        $az = DB::table(self::TABLO)->insertGetId(['baslik' => 'Az', 'icerik' => '<b>kalın</b> metin', 'kullanim_sayisi' => 1]);
        DB::table(self::TABLO)->insert(['baslik' => 'Çok', 'icerik' => 'x', 'kullanim_sayisi' => 9]);
        $this->girisYap();

        $this->getJson(self::KOK)->assertJsonPath('veri.data.0.baslik', 'Çok')->assertJsonPath('veri.data.1.ozet', 'kalın metin');

        $this->putJson(self::KOK."/{$az}", $this->form(['baslik' => 'Yeni ad', 'aktif' => false]))->assertOk();
        $this->assertSame('Yeni ad', DB::table(self::TABLO)->where('id', $az)->value('baslik'));
        $this->assertSame(0, (int) DB::table(self::TABLO)->where('id', $az)->value('aktif'));

        $this->deleteJson(self::KOK."/{$az}")->assertOk();
        $this->assertTrue($this->denetimVar('hazir_cevap_sil', $az));
        $this->deleteJson(self::KOK."/{$az}")->assertStatus(404);
    }

    public function test_secici_yalniz_aktifleri_arar_ve_kullanim_sayilir(): void
    {
        $aktif = DB::table(self::TABLO)->insertGetId(['baslik' => 'WhatsApp', 'kisayol' => '/wa', 'icerik' => 'x']);
        DB::table(self::TABLO)->insert(['baslik' => 'WhatsApp eski', 'icerik' => 'x', 'aktif' => 0]);
        $this->girisYap('destek');

        $this->getJson(self::KOK.'/secim?q=/wa')->assertOk()->assertJsonCount(1, 'veri')->assertJsonPath('veri.0.id', $aktif);
        $this->getJson(self::KOK.'/secim?q=whatsapp')->assertJsonCount(1, 'veri');

        $this->postJson(self::KOK."/{$aktif}/kullan")->assertOk();
        $this->assertSame(1, (int) DB::table(self::TABLO)->where('id', $aktif)->value('kullanim_sayisi'));
    }
}
