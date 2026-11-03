<?php

namespace Tests\Unit\Domain;

use App\Domain\Telefon\TelefonNumarasi;
use App\Domain\Telefon\TelefonUlkeleri;
use PHPUnit\Framework\TestCase;

/**
 * Telefon kanonik bicimi (TUM telefon alanlari + duplicate-musteri kontrolunun temeli) ve
 * web/Flutter'in okudugu ulke listesi sozlesmesi.
 */
class TelefonNumarasiTest extends TestCase
{
    public function test_tr_telefon_kanonik_bicim(): void
    {
        foreach (['0532 123 45 67', '+90 (532) 123-45-67', '905321234567', '00905321234567', '5321234567', "0532\u{00A0}1234567", '+90 0532 123 45 67'] as $ham) {
            $this->assertSame('5321234567', TelefonNumarasi::kanonik($ham), $ham);
        }
    }

    public function test_yabanci_telefon_ulke_kodu_korunur(): void
    {
        $this->assertSame('+31612345678', TelefonNumarasi::kanonik('+31 6 12345678'));
        $this->assertSame('+4915112345678', TelefonNumarasi::kanonik('0049 151 12345678'));
        $this->assertSame('+491761234567', TelefonNumarasi::kanonik('+49 0176 1234567'));   // trunk 0 atilir
        $this->assertSame('+390612345678', TelefonNumarasi::kanonik('+39 06 1234 5678'));   // Italya: 0 numaranin parcasi
        $this->assertSame('31612345678', TelefonNumarasi::kanonik('31612345678'));          // oneksiz eski kayit
        $this->assertSame('', TelefonNumarasi::kanonik(null));
        $this->assertSame('', TelefonNumarasi::kanonik('—'));
    }

    public function test_arama_varyantlari(): void
    {
        $this->assertSame(['5321234567', '05321234567', '905321234567', '+905321234567', '00905321234567'], TelefonNumarasi::aramaVaryantlari('5321234567'));
        $this->assertSame(['31612345678', '+31612345678', '0031612345678'], TelefonNumarasi::aramaVaryantlari('31612345678'));
        $this->assertSame(['+31612345678', '31612345678', '0031612345678'], TelefonNumarasi::aramaVaryantlari('+31612345678'));
        $this->assertSame([], TelefonNumarasi::aramaVaryantlari(''));
    }

    public function test_kktc_ve_paylasilan_kodlar_tercihe_gore_cozulur(): void
    {
        $this->assertSame('TR', TelefonUlkeleri::koddanCoz('905331234567')['iso']);
        $this->assertSame('US', TelefonUlkeleri::koddanCoz('12025550123')['iso']);
        $this->assertSame('AI', TelefonUlkeleri::koddanCoz('12645551234')['iso']); // +1264 en uzun eslesme
        $this->assertSame('RU', TelefonUlkeleri::koddanCoz('79161234567')['iso']);
        $this->assertNull(TelefonUlkeleri::koddanCoz(''));
    }

    public function test_ulke_listesi_sozlesmesi(): void
    {
        $liste = TelefonUlkeleri::liste();
        $isolar = array_column($liste, 'iso');
        $this->assertSame(count($isolar), count(array_unique($isolar)), 'iso tekil olmali');
        foreach (TelefonUlkeleri::SIK_KULLANILAN as $iso) {
            $this->assertContains($iso, $isolar);
        }
        $tr = $liste[array_search('TR', $isolar, true)];
        $this->assertSame(['iso' => 'TR', 'ad' => 'Türkiye', 'kod' => '90', 'maske' => '### ### ## ##', 'en_cok' => 10, 'tercih' => true, 'sifir_kalir' => false], $tr);
    }
}
