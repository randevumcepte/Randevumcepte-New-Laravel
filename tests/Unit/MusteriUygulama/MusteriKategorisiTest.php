<?php

namespace Tests\Unit\MusteriUygulama;

use App\Domain\Musteri\MusteriKategorisi;
use PHPUnit\Framework\TestCase;

class MusteriKategorisiTest extends TestCase
{
    public function test_odeme_sayisina_gore_kategori(): void
    {
        $this->assertSame(['pasif', 'Pasif Müşteri'], MusteriKategorisi::coz(0));
        $this->assertSame(['aktif', 'Aktif Müşteri'], MusteriKategorisi::coz(1));
        $this->assertSame(['aktif', 'Aktif Müşteri'], MusteriKategorisi::coz(2));
        $this->assertSame(['sadik', 'Sadık Müşteri'], MusteriKategorisi::coz(3));
    }
}
