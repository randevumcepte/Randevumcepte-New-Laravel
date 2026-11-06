<?php

namespace Tests\Feature\Sistem;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManuelOdemeApiTest extends TestCase
{
    use DestekTestKurulumu;

    private const KOK = '/api/v2/sistem/manuel-odeme';

    protected function setUp(): void
    {
        parent::setUp();
        $this->destekKur();
        config(['panel.eski_panel_url' => 'https://eski.test/']);
    }

    private function salon(array $alan = []): int
    {
        return DB::table('salonlar')->insertGetId($alan + ['salon_adi' => 'Salon A']);
    }

    public function test_link_uretir_form_ve_satirlar_yazilir(): void
    {
        $salon = $this->salon();
        $this->girisYap();

        $v = $this->postJson(self::KOK, ['salon_id' => $salon, 'notlar' => 'iç not', 'hizmetler' => [
            ['ad' => 'Üyelik', 'adet' => 2, 'tutar' => '1.500,50'],
            ['ad' => ' Kurulum ', 'adet' => 0, 'tutar' => '100'],
            ['ad' => '', 'tutar' => ''],
        ]])->assertStatus(201)->assertJsonPath('veri.toplam', 3101)->json('veri');

        $this->assertSame('https://eski.test/odeme?form='.$v['form_id'], $v['link']);
        $form = DB::table('musteri_formlari')->find($v['form_id']);
        $this->assertSame(6, (int) $form->durum_id);
        $this->assertSame('iç not', $form->notlar);
        $satirlar = DB::table('musteri_formlari_hizmetler')->where('form_id', $v['form_id'])->orderBy('id')->get();
        $this->assertCount(2, $satirlar);
        $this->assertSame(1500.5, (float) $satirlar[0]->ucret);
        $this->assertNull($satirlar[0]->uyelik_id);
        $this->assertSame('Kurulum', $satirlar[1]->aciklama);
        $this->assertSame(1, (int) $satirlar[1]->adet);
        $this->assertTrue($this->denetimVar('manuel_odeme_linki', $v['form_id']));
    }

    public function test_hatali_satir_varsa_form_acilmaz(): void
    {
        $salon = $this->salon();
        $this->girisYap();

        foreach ([
            [['ad' => 'A', 'tutar' => '1500.50']],
            [['ad' => 'A', 'tutar' => '100'], ['ad' => 'B', 'tutar' => '']],
            [['ad' => '', 'tutar' => '100']],
            [['ad' => '', 'tutar' => '']],
        ] as $hizmetler) {
            $this->postJson(self::KOK, ['salon_id' => $salon, 'hizmetler' => $hizmetler])->assertStatus(422);
        }
        $this->postJson(self::KOK, ['salon_id' => $salon])->assertStatus(422);
        $this->assertSame(0, DB::table('musteri_formlari')->count());
    }

    public function test_salon_ve_rol_kapisi(): void
    {
        $this->girisYap('izleyici');
        $this->getJson(self::KOK.'/secenekler')->assertOk()->assertJsonPath('veri.yazabilir', false);
        $this->postJson(self::KOK, [])->assertStatus(403);

        $y = $this->girisYap('destek');
        $hizmetler = [['ad' => 'A', 'tutar' => '10']];
        $this->postJson(self::KOK, ['salon_id' => 99999, 'hizmetler' => $hizmetler])->assertStatus(404);
        $this->postJson(self::KOK, ['salon_id' => $this->salon(['musteri_yetkili_id' => $y->id + 100]), 'hizmetler' => $hizmetler])->assertStatus(403);
        $this->postJson(self::KOK, ['salon_id' => $this->salon(['musteri_yetkili_id' => $y->id]), 'hizmetler' => $hizmetler])->assertStatus(201);
    }
}
