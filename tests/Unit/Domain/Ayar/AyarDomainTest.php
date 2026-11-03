<?php

namespace Tests\Unit\Domain\Ayar;

use App\Domain\Ayar\AyarSozluk;
use App\Domain\Ayar\HaftalikSaat;
use App\Domain\Ayar\HexRenk;
use App\Domain\Ayar\SalonTemaSeed;
use PHPUnit\Framework\TestCase;

class AyarDomainTest extends TestCase
{
    public function test_saat_normalize(): void
    {
        $this->assertSame('09:30', HaftalikSaat::saat('09:30:00'));
        $this->assertSame('23:59', HaftalikSaat::saat(' 23:59 '));
        $this->assertNull(HaftalikSaat::saat('24:00'));
        $this->assertNull(HaftalikSaat::saat('9:30'));
        $this->assertNull(HaftalikSaat::saat(null));
        $this->assertTrue(HaftalikSaat::sirali('09:00', '09:05'));
        $this->assertFalse(HaftalikSaat::sirali('10:00', '10:00'));
        $this->assertFalse(HaftalikSaat::sirali(null, '10:00'));
    }

    public function test_aralik_varsayilani(): void
    {
        $this->assertSame(30, HaftalikSaat::aralik(0));
        $this->assertSame(30, HaftalikSaat::aralik(4));
        $this->assertSame(15, HaftalikSaat::aralik('15'));
    }

    public function test_slot_uretimi_bitis_haric(): void
    {
        $this->assertSame(['09:00', '09:30', '10:00', '10:30'], HaftalikSaat::slotlar('09:00', '11:00', 30));
        $this->assertSame(['09:00', '09:45'], HaftalikSaat::slotlar('09:00', '10:00', 45));
        $this->assertSame([], HaftalikSaat::slotlar('10:00', '10:00', 30));
    }

    public function test_pencere_birlestirme_eski_kaydet_ile_ayni(): void
    {
        // Bitisik slotlar tek pencere; bosluk yeni pencere; sirasiz/tekrar/gecersiz tolere edilir.
        $this->assertSame(
            [['bas' => '09:00', 'bit' => '10:30'], ['bas' => '14:00', 'bit' => '14:30']],
            HaftalikSaat::pencereler(['10:00', '09:00', '09:30', '09:30', '14:00', 'xx'], 30),
        );
        $this->assertSame([], HaftalikSaat::pencereler([], 30));
        // Gun sonu tasmaz.
        $this->assertSame([['bas' => '23:30', 'bit' => '23:59']], HaftalikSaat::pencereler(['23:30'], 30));
    }

    public function test_pencere_slot_gidis_donus(): void
    {
        $slotlar = ['08:00', '08:15', '08:30', '13:00', '13:15'];
        $geri = [];
        foreach (HaftalikSaat::pencereler($slotlar, 15) as $p) {
            array_push($geri, ...HaftalikSaat::slotlar($p['bas'], $p['bit'], 15));
        }
        $this->assertSame($slotlar, $geri);
    }

    public function test_hex_normalize_ve_otomatik_yazi(): void
    {
        $this->assertSame('#5C008E', HexRenk::normalize('#5c008e'));
        $this->assertNull(HexRenk::normalize('5c008e'));
        $this->assertNull(HexRenk::normalize('#5c008'));
        $this->assertSame('#FFFFFF', HexRenk::otomatikYazi('#5C008E'));
        $this->assertSame('#000000', HexRenk::otomatikYazi('#FCD34D'));
    }

    public function test_renk_sirasi_eski_bicimler(): void
    {
        $beklenen = [['bg' => '#5C008E', 'text' => '#FFFFFF'], ['bg' => '#FCD34D', 'text' => '#000000']];
        $this->assertSame($beklenen, HexRenk::siraCoz('#5c008e, #fcd34d'));
        $this->assertSame($beklenen, HexRenk::siraCoz('["#5c008e","#fcd34d"]'));
        $this->assertSame($beklenen, HexRenk::siraCoz([['bg' => '#5c008e'], ['bg' => '#FCD34D', 'text' => 'yanlis'], ['bg' => 'x']]));
        $this->assertSame([['bg' => '#111111', 'text' => '#ABCDEF']], HexRenk::siraCoz([['bg' => '#111111', 'text' => '#abcdef']]));
        $this->assertSame([], HexRenk::siraCoz(''));
        $this->assertSame([], HexRenk::siraCoz(null));
    }

    public function test_renk_sirasi_palet_ile_tamamlanir(): void
    {
        $kayitli = [['bg' => '#222222', 'text' => '#FFFFFF']];
        $sonuc = HexRenk::siraTamamla($kayitli, ['#111111', '#222222']);
        $this->assertSame(['#222222', '#111111'], array_column($sonuc, 'bg'));
    }

    public function test_tema_seed(): void
    {
        $this->assertSame('#0EA5E9', SalonTemaSeed::seed(['preset' => 'okyanus']));
        $this->assertSame('#5C008E', SalonTemaSeed::seed(['preset' => 'olmayan']));
        $this->assertSame('#123456', SalonTemaSeed::seed(['preset' => 'ozel', 'customSeedArgb' => 0xFF123456]));
        $this->assertSame('#5C008E', SalonTemaSeed::seed(['preset' => 'ozel']));
        $this->assertTrue(SalonTemaSeed::presetGecerli('ozel'));
        $this->assertFalse(SalonTemaSeed::presetGecerli('mor'));
    }

    public function test_sozluk_sekme_yetkileri_eski_harita(): void
    {
        $this->assertSame(['hizmet.tanim_olustur', 'hizmet.kategori_yonet'], AyarSozluk::yetki('hizmetler'));
        $this->assertSame(['randevu.online_ayar'], AyarSozluk::yetki('online_saatler'));
        $this->assertTrue(AyarSozluk::takvimTuruGecerli(0));
        $this->assertFalse(AyarSozluk::takvimTuruGecerli(4));
        $this->expectException(\LogicException::class);
        AyarSozluk::yetki('yok');
    }
}
