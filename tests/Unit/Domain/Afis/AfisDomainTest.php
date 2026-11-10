<?php

namespace Tests\Unit\Domain\Afis;

use App\Domain\Afis\AfisIcerigi;
use App\Domain\Afis\MagazaLinkleri;
use App\Domain\Afis\Qr\QrKodlayici;
use App\Domain\Afis\Qr\QrMatris;
use App\Domain\Afis\Qr\ReedSolomon;
use App\Support\Pdf\GorselPdf;
use PHPUnit\Framework\TestCase;

/**
 * Uygulama afişi domain'i (DB'siz, Laravel'siz). QR referansları BaconQrCode (eski monolitin
 * afiş QR'ını üreten kütüphane) çıktısından sabitlendi — kodlayıcı onunla modül modül aynı olmalı.
 */
class AfisDomainTest extends TestCase
{
    public function test_reed_solomon_iso_ornegi(): void
    {
        // "HELLO WORLD" 1-M veri sözcükleri → bilinen 10 EC sözcüğü.
        $veri = [32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17];
        $this->assertSame([196, 35, 39, 119, 235, 215, 231, 226, 93, 23], ReedSolomon::ecBaytlari($veri, 10));
    }

    public function test_qr_bacon_ile_birebir_surum_3(): void
    {
        $qr = QrKodlayici::kodla('https://app.randevumcepte.com.tr/indir/15');
        $this->assertSame([3, 5, 29], [$qr->surum, $qr->maske, $qr->boyut()]);
        $this->assertSame('a10e1655dce85c8c9d80d248be296cb7b223f863', $this->ozet($qr));
    }

    public function test_qr_bacon_ile_birebir_surum_9_surum_bilgisi_dahil(): void
    {
        $metin = 'https://app.randevumcepte.com.tr/indir/123456?kaynak=afis&utm_source=qr-kod-baskisi-salon-ici-a4-'.str_repeat('x', 60);
        $qr = QrKodlayici::kodla($metin);
        $this->assertSame([9, 2, 53], [$qr->surum, $qr->maske, $qr->boyut()]);
        $this->assertSame('8561a979c2a41a6dec217656f932577f138df509', $this->ozet($qr));
    }

    public function test_qr_bulucu_desenler_ve_kapasite_siniri(): void
    {
        $qr = QrKodlayici::kodla('https://app.randevumcepte.com.tr/indir/1');
        $n = $qr->boyut();
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$x, $y]) {
            $this->assertTrue($qr->koyuMu($x, $y) && $qr->koyuMu($x + 6, $y + 6) && $qr->koyuMu($x + 3, $y + 3));
            $this->assertFalse($qr->koyuMu($x + 1, $y + 1));
        }
        $this->assertTrue($qr->koyuMu(8, $n - 8));        // sabit koyu modül

        $this->assertSame(10, QrKodlayici::kodla(str_repeat('a', 213))->surum);
        $this->assertSame(13, QrKodlayici::kodla(str_repeat('a', 331))->surum); // tablo surum 13'e kadar
        $this->expectException(\LengthException::class);
        QrKodlayici::kodla(str_repeat('a', 332));
    }

    public function test_magaza_linkleri_hazir_kurali(): void
    {
        $this->assertTrue((new MagazaLinkleri('https://play', 'https://apps'))->afisHazir());
        $yalnizAndroid = new MagazaLinkleri('https://play', '  ', 'https://huawei');
        $this->assertFalse($yalnizAndroid->afisHazir());
        $this->assertTrue($yalnizAndroid->herhangiVar());
        $this->assertSame(['android' => true, 'ios' => false, 'huawei' => true], $yalnizAndroid->tanimlilar());
        $yalnizHuawei = new MagazaLinkleri(null, null, 'https://huawei');
        $this->assertFalse($yalnizHuawei->herhangiVar());   // huawei paylaşım linki açmaz (eski kural)
    }

    public function test_afis_icerigi_dosya_adi_bas_harf_parmak_izi(): void
    {
        $i = new AfisIcerigi(7, '  Şık Güzellik & Spa  ', 'https://x/indir/7', null);
        $this->assertSame('Şık Güzellik & Spa', $i->ad);
        $this->assertSame('uygulama-afisi-Sik-Guzellik-Spa.pdf', $i->dosyaAdi('pdf'));
        $this->assertSame('Ş', $i->basHarf());
        $this->assertSame('uygulama-afisi-afis.pdf', (new AfisIcerigi(7, '', 'l', null))->dosyaAdi('pdf'));
        $this->assertSame('A', (new AfisIcerigi(7, null, 'l', null))->basHarf());

        $this->assertNotSame($i->parmakIzi(), (new AfisIcerigi(7, 'Şık Güzellik & Spa', 'https://x/indir/7', '/l.png', 1))->parmakIzi());
        $this->assertNotSame(
            (new AfisIcerigi(7, 'A', 'l', '/l.png', 1))->parmakIzi(),
            (new AfisIcerigi(7, 'A', 'l', '/l.png', 2))->parmakIzi(),   // logo değişti → yeni render
        );
    }

    public function test_gorsel_pdf_gecerli_yapi(): void
    {
        if (! function_exists('imagejpeg')) {
            $this->markTestSkipped('GD yok');
        }
        $g = imagecreatetruecolor(21, 30);
        ob_start();
        imagejpeg($g);
        $jpeg = (string) ob_get_clean();

        $pdf = GorselPdf::jpegden($jpeg);
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('/MediaBox [0 0 595.28 841.89]', $pdf);
        $this->assertStringContainsString('/Width 21 /Height 30', $pdf);
        $this->assertStringContainsString($jpeg, $pdf);

        preg_match('/startxref\n(\d+)/', $pdf, $m);
        $this->assertSame('xref', substr($pdf, (int) $m[1], 4));
        preg_match_all('/(\d{10}) 00000 n/', $pdf, $ofsetler);
        foreach ($ofsetler[1] as $sira => $ofset) {
            $this->assertSame(($sira + 1).' 0 obj', substr($pdf, (int) $ofset, strlen(($sira + 1).' 0 obj')));
        }

        $this->expectException(\InvalidArgumentException::class);
        GorselPdf::jpegden('png-degil');
    }

    private function ozet(QrMatris $qr): string
    {
        return sha1(implode("\n", array_map(fn (array $s) => implode('', $s), $qr->satirlar())));
    }
}
