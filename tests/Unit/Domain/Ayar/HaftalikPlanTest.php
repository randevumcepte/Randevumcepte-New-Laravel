<?php

namespace Tests\Unit\Domain\Ayar;

use App\Domain\Ayar\HaftalikPlan;
use App\Exceptions\GecersizIslemException;
use PHPUnit\Framework\TestCase;

class HaftalikPlanTest extends TestCase
{
    private function gun(array $alanlar): array
    {
        return [['haftanin_gunu' => 1, 'calisiyor' => 1] + $alanlar];
    }

    public function test_varsayilan_iki_yariyi_ayirir_ve_saniyeyi_atar(): void
    {
        $plan = HaftalikPlan::satirlar($this->gun([
            'baslangic_saati' => '09:00:00', 'bitis_saati' => '18:00',
            'mola_var' => 1, 'mola_baslangic_saati' => '12:00', 'mola_bitis_saati' => '13:00',
        ]));

        $this->assertSame([['haftanin_gunu' => 1, 'calisiyor' => 1, 'baslangic_saati' => '09:00', 'bitis_saati' => '18:00']], $plan['calisma']);
        $this->assertSame([['haftanin_gunu' => 1, 'mola_var' => 1, 'baslangic_saati' => '12:00', 'bitis_saati' => '13:00']], $plan['mola']);
    }

    public function test_varsayilan_gece_devrini_reddeder(): void
    {
        $this->expectException(GecersizIslemException::class);
        HaftalikPlan::satirlar($this->gun(['baslangic_saati' => '10:00', 'bitis_saati' => '00:00']));
    }

    public function test_gece_devri_bitisi_gece_yarisi_olan_saati_kabul_eder(): void
    {
        $plan = HaftalikPlan::satirlar($this->gun(['baslangic_saati' => '10:00', 'bitis_saati' => '00:00']), HaftalikPlan::CALISMA, true);

        $this->assertSame('00:00', $plan['calisma'][0]['bitis_saati']);
    }

    public function test_yalniz_calisma_molayi_denetlemez_ve_yazmaz(): void
    {
        $plan = HaftalikPlan::satirlar($this->gun([
            'baslangic_saati' => '09:00', 'bitis_saati' => '18:00',
            'mola_var' => 1, 'mola_baslangic_saati' => '14:00', 'mola_bitis_saati' => '13:00',
        ]), HaftalikPlan::CALISMA);

        $this->assertNull($plan['mola']);
        $this->assertCount(1, $plan['calisma']);
    }

    public function test_yalniz_mola_calismayi_denetlemez_ve_yazmaz(): void
    {
        $plan = HaftalikPlan::satirlar($this->gun([
            'baslangic_saati' => '18:00', 'bitis_saati' => '09:00',
            'mola_var' => 1, 'mola_baslangic_saati' => '12:00', 'mola_bitis_saati' => '13:00',
        ]), HaftalikPlan::MOLA);

        $this->assertNull($plan['calisma']);
        $this->assertSame(1, $plan['mola'][0]['mola_var']);
    }
}
