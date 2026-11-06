<?php

namespace Tests\Feature\Sistem;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Asistan Egitimi: kalip yazmalari + stateless PDF akisi (Claude cagrisi sahte). */
class AsistanEgitimTest extends TestCase
{
    use EntegrasyonKurulum;

    private const KOK = '/api/v2/sistem/asistan-egitim';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ortamKur();
        config(['sistem-asistan.anahtar' => 'test-anahtar']);
        Schema::create('asistan_kalip', function (Blueprint $t) {
            $t->id();
            $t->text('tetikleyiciler');
            $t->text('cevap');
            $t->string('kategori')->nullable();
            $t->integer('aktif')->default(1);
            $t->integer('kullanim_sayisi')->default(0);
            $t->timestamps();
        });
        Schema::create('asistan_cozulmeyen', function (Blueprint $t) {
            $t->id();
            $t->string('soru_norm');
            $t->text('ham')->nullable();
            $t->integer('adet')->default(1);
            $t->timestamp('son_tarih')->nullable();
            $t->timestamps();
        });
        Schema::create('ai_kullanim', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id')->nullable();
            $t->string('tur');
            $t->string('model')->nullable();
            $t->integer('girdi_token')->default(0);
            $t->integer('cikti_token')->default(0);
            $t->decimal('maliyet_usd', 12, 6)->default(0);
            $t->integer('cache')->default(0);
            $t->integer('basarili')->default(1);
            $t->timestamp('created_at')->nullable();
        });
    }

    private function pdf(string $icerik = '%PDF-1.4 icerik', string $ad = 'belge.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($ad, $icerik);
    }

    public function test_izleyici_okur_yazamaz(): void
    {
        $this->girisYap('izleyici');

        $this->getJson(self::KOK)->assertOk()->assertJsonPath('veri.izinler.yaz', false)->assertJsonPath('veri.tablo_yok', false);
        $this->getJson(self::KOK.'/kaliplar')->assertOk();
        $this->postJson(self::KOK.'/kalip', ['tetikleyiciler' => 'a', 'cevap' => 'b'])->assertStatus(403);
        $this->postJson(self::KOK.'/pdf-coz')->assertStatus(403);
    }

    public function test_kalip_ekle_duzenle_sil_ve_cozulmeyen_duser(): void
    {
        $this->girisYap('destek');
        $soru = DB::table('asistan_cozulmeyen')->insertGetId(['soru_norm' => 'iade', 'ham' => 'İade var mı', 'adet' => 3]);

        $this->postJson(self::KOK.'/kalip', ['tetikleyiciler' => '  iade, para iade ', 'cevap' => 'Var', 'cozulmeyen_id' => $soru])->assertOk();
        $this->assertSame(0, DB::table('asistan_cozulmeyen')->count());
        $this->getJson(self::KOK.'/kaliplar')->assertJsonPath('veri.data.0.tetikleyici_listesi', ['iade', 'para iade'])->assertJsonPath('veri.data.0.aktif', true);

        $id = (int) DB::table('asistan_kalip')->value('id');
        $this->putJson(self::KOK."/kalip/{$id}", ['tetikleyiciler' => 'x', 'cevap' => 'y', 'aktif' => false])->assertOk();
        $this->assertSame(0, (int) DB::table('asistan_kalip')->value('aktif'));
        $this->putJson(self::KOK.'/kalip/999', ['tetikleyiciler' => 'x', 'cevap' => 'y'])->assertStatus(404);

        $this->deleteJson(self::KOK."/kalip/{$id}")->assertOk();
        $this->deleteJson(self::KOK."/kalip/{$id}")->assertStatus(404);
        foreach (['asistan_kalip_ekle', 'asistan_kalip_guncelle', 'asistan_kalip_sil'] as $islem) {
            $this->assertTrue($this->denetimVar($islem));
        }
    }

    public function test_pdf_cikarma_kaydetmez_kullanimi_loglar(): void
    {
        $this->girisYap('yonetici');
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => "```json\n[{\"tetikleyiciler\":\"wifi, sifre\",\"cevap\":\"1234\",\"kategori\":\"\"},{\"tetikleyiciler\":\"\",\"cevap\":\"bos\"}]\n```"]],
            'usage'   => ['input_tokens' => 1000000, 'output_tokens' => 0],
        ])]);

        $this->post(self::KOK.'/pdf-coz', ['pdf' => $this->pdf()], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonCount(1, 'veri.maddeler')->assertJsonPath('veri.maddeler.0.kategori', 'pdf')->assertJsonPath('veri.dosya', 'belge.pdf');

        $this->assertSame(0, DB::table('asistan_kalip')->count());
        $k = DB::table('ai_kullanim')->first();
        $this->assertSame('pdf_egitim', $k->tur);
        $this->assertEqualsWithDelta(1.0, (float) $k->maliyet_usd, 0.0001);
        Http::assertSent(fn ($r) => $r->hasHeader('x-api-key', 'test-anahtar') && $r['model'] === 'claude-haiku-4-5' && $r['messages'][0]['content'][0]['source']['media_type'] === 'application/pdf');
    }

    public function test_pdf_olmayan_buyuk_dosya_ve_eksik_dosya_reddedilir(): void
    {
        $this->girisYap('yonetici');
        Http::fake();
        $basliklar = ['Accept' => 'application/json'];

        $this->post(self::KOK.'/pdf-coz', [], $basliklar)->assertStatus(422);
        $this->post(self::KOK.'/pdf-coz', ['pdf' => $this->pdf('duz metin')], $basliklar)->assertStatus(422);
        $this->post(self::KOK.'/pdf-coz', ['pdf' => $this->pdf('%PDF-1.4', 'belge.txt')], $basliklar)->assertStatus(422);
        $this->post(self::KOK.'/pdf-coz', ['pdf' => UploadedFile::fake()->create('b.pdf', 20 * 1024 + 1, 'application/pdf')], $basliklar)->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_ai_hatasi_ve_anahtar_yok_502(): void
    {
        $this->girisYap('yonetici');
        Http::fake(['*' => Http::response(['error' => 'x'], 429)]);
        $basliklar = ['Accept' => 'application/json'];

        $this->post(self::KOK.'/pdf-coz', ['pdf' => $this->pdf()], $basliklar)->assertStatus(502);
        $this->assertSame(0, DB::table('ai_kullanim')->count());

        config(['sistem-asistan.anahtar' => '']);
        $this->post(self::KOK.'/pdf-coz', ['pdf' => $this->pdf()], $basliklar)->assertStatus(502);
    }

    public function test_pdf_kaydet_bos_maddeyi_atlar(): void
    {
        $this->girisYap('destek');

        $this->postJson(self::KOK.'/pdf-kaydet', ['maddeler' => []])->assertStatus(422);
        $this->postJson(self::KOK.'/pdf-kaydet', ['dosya' => 'b.pdf', 'maddeler' => [
            ['tetikleyiciler' => 'a', 'cevap' => 'b', 'kategori' => ''],
            ['tetikleyiciler' => '', 'cevap' => 'c'],
        ]])->assertOk()->assertJsonPath('veri.eklenen', 1);

        $this->assertSame('pdf', DB::table('asistan_kalip')->value('kategori'));
        $this->assertTrue($this->denetimVar('asistan_pdf_kaydet'));
    }
}
