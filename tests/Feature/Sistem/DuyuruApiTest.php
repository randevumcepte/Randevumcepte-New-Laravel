<?php

namespace Tests\Feature\Sistem;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DuyuruApiTest extends TestCase
{
    use DestekTestKurulumu;

    private const KOK = '/api/v2/sistem/duyuru';

    private const TABLO = 'sistemyonetim_duyurular';

    protected function setUp(): void
    {
        parent::setUp();
        $this->destekKur();
    }

    private function form(array $alan = []): array
    {
        return $alan + ['baslik' => 'Bakım', 'icerik' => 'Gece bakım var', 'tip' => 'bakim', 'hedef_tipi' => 'hepsi', 'aktif' => true];
    }

    public function test_yazma_yalniz_yonetimde_okuma_herkese(): void
    {
        $id = DB::table(self::TABLO)->insertGetId(['baslik' => 'x', 'icerik' => 'x']);
        foreach (['destek', 'izleyici'] as $rol) {
            $this->girisYap($rol);
            $this->getJson(self::KOK)->assertOk();
            $this->getJson(self::KOK."/{$id}")->assertOk();
            $this->getJson(self::KOK.'/secenekler')->assertJsonPath('veri.yazabilir', false);
            $this->postJson(self::KOK, $this->form())->assertStatus(403);
            $this->putJson(self::KOK."/{$id}", $this->form())->assertStatus(403);
            $this->deleteJson(self::KOK."/{$id}")->assertStatus(403);
            $this->getJson(self::KOK."/{$id}/form")->assertStatus(403);
        }
    }

    public function test_olusturma_ve_dogrulama(): void
    {
        $this->girisYap('yonetici', 'Mehmet');

        $id = $this->postJson(self::KOK, $this->form(['baslangic_tarihi' => '2026-01-01 10:00']))->assertStatus(201)->json('veri.id');
        $d = DB::table(self::TABLO)->find($id);
        $this->assertSame('Mehmet', $d->olusturan_user_name);
        $this->assertNull($d->hedef_ids);
        $this->assertTrue($this->denetimVar('duyuru_olustur', $id));

        $this->postJson(self::KOK, $this->form(['tip' => 'uydurma']))->assertStatus(422);
        $this->postJson(self::KOK, $this->form(['hedef_tipi' => 'secili']))->assertStatus(422);
        $this->postJson(self::KOK, $this->form(['hedef_tipi' => 'il', 'il_ids' => []]))->assertStatus(422);
        $this->postJson(self::KOK, $this->form(['cta_link' => 'javascript:alert(1)']))->assertStatus(422);
        $this->postJson(self::KOK, $this->form(['cta_link' => '//kotu.site']))->assertStatus(422);
        $this->postJson(self::KOK, $this->form(['cta_link' => '/isletmeyonetim/x']))->assertStatus(201);
        $this->postJson(self::KOK, $this->form(['baslangic_tarihi' => '2026-02-01 10:00', 'bitis_tarihi' => '2026-01-01 10:00']))->assertStatus(422);
    }

    public function test_hedef_sayisi_oran_ve_okuyanlar(): void
    {
        $a = DB::table('salonlar')->insertGetId(['salon_adi' => 'A', 'il_id' => 35]);
        $b = DB::table('salonlar')->insertGetId(['salon_adi' => 'B', 'il_id' => 35]);
        DB::table('salonlar')->insert(['salon_adi' => 'C', 'il_id' => 6]);
        $yetkili = DB::table('isletmeyetkilileri')->insertGetId(['name' => 'Yetkili A']);
        $this->girisYap();

        $il = $this->postJson(self::KOK, $this->form(['hedef_tipi' => 'il', 'il_ids' => [35]]))->json('veri.id');
        $secili = $this->postJson(self::KOK, $this->form(['hedef_tipi' => 'secili', 'salon_ids' => [$a, $b, $b]]))->json('veri.id');
        $hepsi = $this->postJson(self::KOK, $this->form())->json('veri.id');
        DB::table('sistemyonetim_duyuru_okundu')->insert(['duyuru_id' => $il, 'salon_id' => $a, 'user_id' => $yetkili, 'okundu_tarihi' => now()]);

        $this->getJson(self::KOK."/{$il}")->assertJsonPath('veri.hedef_sayisi', 2)->assertJsonPath('veri.okundu_sayisi', 1)->assertJsonPath('veri.okundu_orani', 50);
        $this->getJson(self::KOK."/{$secili}")->assertJsonPath('veri.hedef_sayisi', 2)->assertJsonPath('veri.okundu_orani', 0);
        $this->getJson(self::KOK."/{$hepsi}")->assertJsonPath('veri.hedef_sayisi', 3);

        $this->getJson(self::KOK."/{$il}/okuyanlar?ara=Yetkili")->assertJsonPath('veri.total', 1)
            ->assertJsonPath('veri.data.0.salon_adi', 'A')->assertJsonPath('veri.data.0.yetkili_adi', 'Yetkili A');
    }

    public function test_form_verisi_ve_guncelle_sil(): void
    {
        $a = DB::table('salonlar')->insertGetId(['salon_adi' => 'A']);
        $this->girisYap();
        $id = $this->postJson(self::KOK, $this->form(['hedef_tipi' => 'secili', 'salon_ids' => [$a], 'bitis_tarihi' => '2026-03-01 09:30']))->json('veri.id');

        $this->getJson(self::KOK."/{$id}/form")->assertOk()
            ->assertJsonPath('veri.secili_salonlar.0.salon_adi', 'A')
            ->assertJsonPath('veri.bitis_tarihi', '2026-03-01 09:30');

        $this->putJson(self::KOK."/{$id}", $this->form(['hedef_tipi' => 'il', 'il_ids' => [6], 'aktif' => false]))->assertOk();
        $d = DB::table(self::TABLO)->find($id);
        $this->assertSame('[6]', $d->hedef_ids);
        $this->assertSame(0, (int) $d->aktif);
        $this->assertTrue($this->denetimVar('duyuru_guncelle', $id));

        $this->deleteJson(self::KOK."/{$id}")->assertOk();
        $this->assertTrue($this->denetimVar('duyuru_sil', $id));
        $this->getJson(self::KOK."/{$id}")->assertStatus(404);
    }

    public function test_liste_arama_ve_secenekler(): void
    {
        DB::table(self::TABLO)->insert(['baslik' => 'Kampanya', 'icerik' => '<p>'.str_repeat('a', 100).'</p>', 'tip' => 'kampanya']);
        DB::table(self::TABLO)->insert(['baslik' => 'Başka', 'icerik' => 'x']);
        DB::table('il')->insert(['il_adi' => 'İzmir']);
        $this->girisYap();

        $satir = $this->getJson(self::KOK.'?ara=Kampanya')->assertJsonPath('veri.total', 1)->assertJsonPath('veri.data.0.tip.renk', 'success')->json('veri.data.0');
        $this->assertStringNotContainsString('<p>', $satir['ozet']);
        $this->getJson(self::KOK.'/secenekler')->assertJsonPath('veri.iller.0.il_adi', 'İzmir')->assertJsonPath('veri.yazabilir', true);
    }
}
