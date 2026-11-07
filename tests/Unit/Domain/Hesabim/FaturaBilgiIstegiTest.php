<?php

namespace Tests\Unit\Domain\Hesabim;

use App\Http\Requests\Hesabim\FaturaBilgiIstegi;
use PHPUnit\Framework\TestCase;

class FaturaBilgiIstegiTest extends TestCase
{
    private function kurallar(array $govde): array
    {
        return FaturaBilgiIstegi::create('/hesabim/fatura-bilgisi', 'PUT', $govde)->rules();
    }

    public function test_web_sinirlari_degismez(): void
    {
        $k = $this->kurallar([]);

        $this->assertContains('max:20', $k['vergi_no']);
        $this->assertContains('max:1000', $k['vergi_adresi']);
        $this->assertContains('max:100', $k['kdv_orani']);
    }

    public function test_mobil_metin_sinirini_gevsetir_kdv_sinirini_gevsetmez(): void
    {
        $k = $this->kurallar(['mobil' => 1]);

        $this->assertContains('max:255', $k['vergi_no']);
        $this->assertContains('numeric', $k['kdv_orani']);
        $this->assertContains('min:0', $k['kdv_orani']);
        $this->assertContains('max:100', $k['kdv_orani']);
    }
}
