<?php

namespace Tests\Feature\Sistem;

use App\Domain\SistemYonetim\TalepNumarasi;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class TalepApiTest extends TestCase
{
    use DestekTestKurulumu;

    private const KOK = '/api/v2/sistem/talep';

    protected function setUp(): void
    {
        parent::setUp();
        $this->destekKur();
    }

    private function talep(array $alan = []): int
    {
        static $no = 0;
        $no++;

        return DB::table('sistemyonetim_destek_talepleri')->insertGetId($alan + ['numara' => "T-{$no}", 'konu' => "Konu {$no}", 'created_at' => now()]);
    }

    private function form(array $alan = []): array
    {
        return $alan + ['konu' => 'Yazıcı çalışmıyor', 'kategori' => 'teknik', 'oncelik' => 'yuksek'];
    }

    public function test_kimliksiz_giremez(): void
    {
        $this->getJson(self::KOK)->assertStatus(401);
    }

    public function test_izleyici_okur_hicbir_yazma_ucuna_giremez(): void
    {
        $id = $this->talep();
        $this->girisYap('izleyici');

        $this->getJson(self::KOK)->assertOk();
        $this->getJson(self::KOK.'/secenekler')->assertOk()->assertJsonPath('veri.yazabilir', false)->assertJsonPath('veri.csv', false);
        $this->postJson(self::KOK, $this->form())->assertStatus(403);
        foreach (['yanit', 'durum', 'ata', 'oncelik'] as $uc) {
            $this->postJson(self::KOK."/{$id}/{$uc}", [])->assertStatus(403);
        }
    }

    public function test_csv_yalniz_yonetimde_ve_loglanir(): void
    {
        $this->talep(['numara' => 'T-CSV-1', 'salon_adi' => 'Salon A']);

        $this->girisYap('destek');
        $this->get(self::KOK.'/csv')->assertStatus(403);

        $this->girisYap('yonetici');
        $cevap = $this->get(self::KOK.'/csv')->assertOk();
        $this->assertStringContainsString('T-CSV-1', $cevap->streamedContent());
        $this->assertTrue($this->denetimVar('csv_export'));
    }

    public function test_olusturma_numara_ilk_mesaj_ve_denetim_yazar(): void
    {
        $y = $this->girisYap('destek', 'Ayşe');

        $id = $this->postJson(self::KOK, $this->form(['aciklama' => 'Detay metni']))->assertStatus(201)->json('veri.id');

        $t = DB::table('sistemyonetim_destek_talepleri')->find($id);
        $this->assertMatchesRegularExpression('/^T-\d{6}-[0-9A-F]{4}$/', $t->numara);
        $this->assertSame('acik', $t->durum);
        $this->assertSame('Ayşe', $t->olusturan_user_name);
        $this->assertSame((int) $y->id, (int) $t->olusturan_user_id);
        $this->assertSame('Detay metni', DB::table('sistemyonetim_destek_mesajlari')->where('ticket_id', $id)->value('mesaj'));
        $this->assertTrue($this->denetimVar('ticket_olustur', $id));
    }

    public function test_aciklamasiz_talepte_mesaj_acilmaz_ve_gecersiz_girdi_422(): void
    {
        $this->girisYap();

        $this->postJson(self::KOK, $this->form())->assertStatus(201);
        $this->assertSame(0, DB::table('sistemyonetim_destek_mesajlari')->count());

        $this->postJson(self::KOK, $this->form(['kategori' => 'uydurma']))->assertStatus(422);
        $this->postJson(self::KOK, $this->form(['konu' => 'x']))->assertStatus(422);
        $this->postJson(self::KOK, $this->form(['iletisim_email' => 'eposta-degil']))->assertStatus(422);
    }

    public function test_cakisan_numara_yenisiyle_denenir(): void
    {
        $this->talep(['numara' => 'T-CAKISAN']);
        $numara = Mockery::mock(TalepNumarasi::class);
        $numara->shouldReceive('uret')->andReturn('T-CAKISAN', 'T-YENI');
        $this->app->instance(TalepNumarasi::class, $numara);
        $this->girisYap();

        $this->postJson(self::KOK, $this->form())->assertStatus(201)->assertJsonPath('veri.numara', 'T-YENI');
    }

    public function test_numara_surekli_cakisirsa_409(): void
    {
        $this->talep(['numara' => 'T-CAKISAN']);
        $numara = Mockery::mock(TalepNumarasi::class);
        $numara->shouldReceive('uret')->andReturn('T-CAKISAN');
        $this->app->instance(TalepNumarasi::class, $numara);
        $this->girisYap();

        $this->postJson(self::KOK, $this->form())->assertStatus(409);
    }

    public function test_salon_secimi_kapsama_tabi(): void
    {
        $y = $this->girisYap('destek');
        $benim = DB::table('salonlar')->insertGetId(['salon_adi' => 'Benim', 'musteri_yetkili_id' => $y->id]);
        $baska = DB::table('salonlar')->insertGetId(['salon_adi' => 'Başka', 'musteri_yetkili_id' => $y->id + 100]);

        $id = $this->postJson(self::KOK, $this->form(['salon_id' => $benim]))->assertStatus(201)->json('veri.id');
        $this->assertSame('Benim', DB::table('sistemyonetim_destek_talepleri')->where('id', $id)->value('salon_adi'));

        $this->postJson(self::KOK, $this->form(['salon_id' => $baska]))->assertStatus(403);
        $this->postJson(self::KOK, $this->form(['salon_id' => 99999]))->assertStatus(404);
    }

    public function test_varsayilan_liste_acik_kapsam_ve_aciliyet_sirasi(): void
    {
        $this->talep(['numara' => 'ORTA', 'oncelik' => 'orta']);
        $this->talep(['numara' => 'ACIL', 'oncelik' => 'acil']);
        $this->talep(['numara' => 'ISLEM', 'oncelik' => 'acil', 'durum' => 'islemde']);
        $this->talep(['numara' => 'KAPALI', 'durum' => 'kapali']);
        $this->girisYap();

        $numaralar = array_column($this->getJson(self::KOK)->assertOk()->assertJsonPath('veri.total', 3)->json('veri.data'), 'numara');
        $this->assertSame(['ACIL', 'ORTA', 'ISLEM'], $numaralar);

        $this->getJson(self::KOK.'?durum=hepsi')->assertJsonPath('veri.total', 4);
        $this->getJson(self::KOK.'?durum=kapali')->assertJsonPath('veri.total', 1);
        $this->getJson(self::KOK.'?durum=uydurma')->assertStatus(422);
    }

    public function test_liste_filtre_arama_ve_sayfalama(): void
    {
        $y = $this->girisYap();
        $this->talep(['numara' => 'BANA', 'atanan_user_id' => $y->id, 'konu' => 'Ödeme sorunu']);
        $this->talep(['numara' => 'BOS', 'salon_adi' => 'Güzel Salon']);

        $this->getJson(self::KOK.'?atanan=bana')->assertJsonPath('veri.total', 1)->assertJsonPath('veri.data.0.numara', 'BANA');
        $this->getJson(self::KOK.'?atanan=atanmamis')->assertJsonPath('veri.data.0.numara', 'BOS');
        $this->getJson(self::KOK.'?atanan='.$y->id)->assertJsonPath('veri.data.0.numara', 'BANA');
        $this->getJson(self::KOK.'?atanan=x;drop')->assertStatus(422);
        $this->getJson(self::KOK.'?ara=Güzel')->assertJsonPath('veri.total', 1)->assertJsonPath('veri.data.0.numara', 'BOS');
        $this->getJson(self::KOK.'?per_page=1&sirala=numara&yon=asc')
            ->assertJsonPath('veri.data.0.numara', 'BANA')->assertJsonPath('veri.last_page', 2)->assertJsonPath('veri.per_page', 1);
    }

    public function test_yanit_ilk_yaniti_damgalar_isleme_alir_ic_not_doner(): void
    {
        $id = $this->talep();
        $this->girisYap();

        $this->postJson(self::KOK."/{$id}/yanit", ['mesaj' => 'Bakıyoruz', 'ic_not' => true])->assertOk()
            ->assertJsonPath('veri.talep.durum.kod', 'islemde')
            ->assertJsonPath('veri.mesajlar.0.ic_not', true);
        $ilk = DB::table('sistemyonetim_destek_talepleri')->where('id', $id)->value('ilk_yanit_tarihi');
        $this->assertNotNull($ilk);
        $this->assertTrue($this->denetimVar('ticket_yanit', $id));

        DB::table('sistemyonetim_destek_talepleri')->where('id', $id)->update(['ilk_yanit_tarihi' => '2020-01-01 10:00:00', 'durum' => 'bekliyor']);
        $this->postJson(self::KOK."/{$id}/yanit", ['mesaj' => 'İkinci'])->assertOk()
            ->assertJsonPath('veri.talep.durum.kod', 'bekliyor')
            ->assertJsonPath('veri.talep.ilk_yanit_tarihi', '2020-01-01 10:00:00');

        $this->postJson(self::KOK."/{$id}/yanit", ['mesaj' => ''])->assertStatus(422);
    }

    public function test_durum_tarihleri_bir_kez_yazilir(): void
    {
        $id = $this->talep(['cozumlenme_tarihi' => '2020-01-01 10:00:00']);
        $this->girisYap();

        $this->postJson(self::KOK."/{$id}/durum", ['durum' => 'cozumlendi'])->assertOk()->assertJsonPath('veri.talep.cozumlenme_tarihi', '2020-01-01 10:00:00');
        $this->postJson(self::KOK."/{$id}/durum", ['durum' => 'kapali'])->assertOk()->assertJsonPath('veri.talep.durum.kod', 'kapali');
        $this->assertNotNull(DB::table('sistemyonetim_destek_talepleri')->where('id', $id)->value('kapanis_tarihi'));
        $this->assertSame('cozumlendi → kapali', DB::table('sistemyonetim_audit_log')->where('action', 'ticket_durum')->orderByDesc('id')->value('aciklama'));

        $this->postJson(self::KOK."/{$id}/durum", ['durum' => 'uydurma'])->assertStatus(422);
        $this->postJson(self::KOK.'/99999/durum', ['durum' => 'acik'])->assertStatus(404);
    }

    public function test_atama_olmayan_ve_pasif_kullanicida_404_null_kaldirir(): void
    {
        $id = $this->talep();
        $y = $this->girisYap();
        $pasif = DB::table('sistemyoneticileri')->insertGetId(['name' => 'Pasif', 'aktif' => 0]);

        $this->postJson(self::KOK."/{$id}/ata", ['atanan_user_id' => 99999])->assertStatus(404);
        $this->postJson(self::KOK."/{$id}/ata", ['atanan_user_id' => $pasif])->assertStatus(404);

        $this->postJson(self::KOK."/{$id}/ata", ['atanan_user_id' => $y->id])->assertOk()->assertJsonPath('veri.talep.atanan_user_name', 'Ekip Üyesi');
        $this->postJson(self::KOK."/{$id}/ata", ['atanan_user_id' => null])->assertOk()->assertJsonPath('veri.talep.atanan_user_id', null);
        $this->assertTrue($this->denetimVar('ticket_ata', $id));
    }

    public function test_oncelik_degisir_ve_denetlenir(): void
    {
        $id = $this->talep();
        $this->girisYap();

        $this->postJson(self::KOK."/{$id}/oncelik", ['oncelik' => 'acil'])->assertOk()->assertJsonPath('veri.talep.oncelik.renk', 'danger');
        $this->assertTrue($this->denetimVar('ticket_oncelik', $id));
    }

    public function test_detay_salon_ve_404(): void
    {
        $salon = DB::table('salonlar')->insertGetId(['salon_adi' => 'Salon X']);
        $id = $this->talep(['salon_id' => $salon]);
        $this->girisYap('izleyici');

        $this->getJson(self::KOK."/{$id}")->assertOk()->assertJsonPath('veri.salon.salon_adi', 'Salon X');
        $this->getJson(self::KOK.'/99999')->assertStatus(404);
    }

    public function test_yazma_kapaliyken_409(): void
    {
        config(['sistem.yazma_aktif' => false]);
        $this->girisYap();

        $this->postJson(self::KOK, $this->form())->assertStatus(409);
    }
}
