<?php

namespace Tests\Unit\Domain\Iletisim;

use App\Domain\Iletisim\MusteriKartiYetkisi;
use App\Domain\Whatsapp\GonderimEngeli;
use PHPUnit\Framework\TestCase;

class GonderimEngeliTest extends TestCase
{
    public function test_engel_yoksa_null(): void
    {
        $this->assertNull(GonderimEngeli::coz('baileys', 200, 10, true, false, 0));
    }

    public function test_gunluk_tavan_once_gelir(): void
    {
        $this->assertSame(GonderimEngeli::GUNLUK_TAVAN, GonderimEngeli::coz('baileys', 200, 200, false, true, 0));
    }

    public function test_cloud_api_tavana_takilmaz(): void
    {
        $this->assertNull(GonderimEngeli::coz('cloud_api', 200, 900, true, false, 0));
    }

    public function test_mesai_disi_ve_kontor(): void
    {
        $this->assertSame(GonderimEngeli::MESAI_DISI, GonderimEngeli::coz('baileys', 200, 0, false, false, 0));
        $this->assertSame(GonderimEngeli::KONTOR_YETERSIZ, GonderimEngeli::coz('baileys', 200, 0, true, true, 0));
        $this->assertNull(GonderimEngeli::coz('baileys', 200, 0, true, true, 1));
    }

    public function test_metinler_eski_uctaki_gibi(): void
    {
        $this->assertSame('Gunluk WhatsApp mesaj limitiniz doldu.', GonderimEngeli::metin(GonderimEngeli::GUNLUK_TAVAN));
        $this->assertSame('Su an calisma saatleri disinda, mesaj gonderilemedi.', GonderimEngeli::metin(GonderimEngeli::MESAI_DISI));
        $this->assertSame('Mesaj gonderilemedi. Lutfen tekrar deneyin.', GonderimEngeli::metin(GonderimEngeli::KONTOR_YETERSIZ));
    }

    public function test_kart_kurali_yonetim_anahtari_tasimaz(): void
    {
        $this->assertSame(['musteri.liste_gor', 'randevu.takvim_gor'], MusteriKartiYetkisi::ANAHTARLAR);
    }
}
