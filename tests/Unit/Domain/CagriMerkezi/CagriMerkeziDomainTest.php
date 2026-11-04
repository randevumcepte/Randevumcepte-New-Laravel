<?php

namespace Tests\Unit\Domain\CagriMerkezi;

use App\Domain\CagriMerkezi\AramaRandevuDurumu as D;
use App\Domain\CagriMerkezi\AramaSonucu as S;
use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Domain\CagriMerkezi\CagriMerkeziSekmeleri;
use App\Domain\CagriMerkezi\ListeDurumu;
use App\Domain\CagriMerkezi\SantralKaydi as K;
use App\Support\KvkkMaske;
use PHPUnit\Framework\TestCase;

/**
 * Çağrı merkezi saf kuralları (DB/HTTP'siz): sonuç sözlüğü, takvim durumu, santral kaydı
 * yorumu, ses eşleme, erişim/sekme, KVKK maskesi.
 */
class CagriMerkeziDomainTest extends TestCase
{
    public function test_sonuc_metinleri_eski_aranacakDurumMetin_ile_ayni(): void
    {
        $this->assertSame('Bekliyor', S::metin(null));
        $this->assertSame('Bekliyor', S::metin(''));
        $this->assertSame('Ulaşılamadı', S::metin(0));       // eski (int)null===0 hatası tekrar etmesin
        $this->assertSame('Ulaşılamadı', S::metin('0'));
        $this->assertSame('Tekrar Aranacak', S::metin(3));
        $this->assertSame('Ön Görüşme Randevusu', S::metin(6));
        $this->assertSame('İptal Edildi', S::metin(9));
        $this->assertSame('Bekliyor', S::metin(42));
        $this->assertSame('gri', S::renkKodu(null));
        $this->assertSame([0, 2, 4, 5, 6, 7], array_values(array_map(fn ($s) => $s->value, array_filter(S::cases(), fn ($s) => $s->agentSecebilir()))));
        $this->assertSame('Bekleyen', S::segmentEtiketi('bekleyen'));
        $this->assertCount(10, S::sozluk()['sonuclar']);
    }

    public function test_takvim_durum_zinciri(): void
    {
        $simdi = strtotime('2026-11-03 15:00:00');
        $once = strtotime('2026-11-03 14:00:00');
        $sonra = strtotime('2026-11-03 16:00:00');
        $this->assertSame('ongorusme', D::takvim(6, $once, '2026-11-03 14:30:00', false, $simdi)['kod']);   // durum, tamamlanmadan önce
        $this->assertSame('#b8860b', D::takvim(7, $sonra, null, false, $simdi)['renk']);
        $this->assertTrue(D::takvim(9, $sonra, null, false, $simdi)['islenmis']);
        $this->assertSame('gec', D::takvim(1, $once, '2026-11-03 14:30:00', false, $simdi)['kod']);
        $this->assertSame('zamaninda', D::takvim(1, $once, '2026-11-03 13:59:00', false, $simdi)['kod']);
        $this->assertSame('gecikti', D::takvim(3, $once, null, false, $simdi)['kod']);
        $this->assertSame('gecikti', D::takvim(3, $sonra, null, true, $simdi)['kod']);
        $this->assertSame('aranacak', D::takvim(3, $sonra, null, false, $simdi)['kod']);
        $this->assertFalse(D::takvim(3, $sonra, null, false, $simdi)['islenmis']);
        $this->assertSame('bekliyor', D::ajanda($sonra, null, false, $simdi));
        $this->assertSame('gecikti', D::ajanda($once, null, false, $simdi));
        $this->assertSame('on5', D::faz($simdi + 120, $simdi));
        $this->assertSame('zaman', D::faz($simdi - 300, $simdi));
        $this->assertSame('gecikti', D::faz($simdi - 601, $simdi));
        $this->assertCount(8, D::lejand());
    }

    public function test_santral_kaydi_cozumle_yon_ve_dis_numara(): void
    {
        $dahili = ['101', '201'];
        $trunk10 = '2322404046';
        // Giden (from-internal): dış müşteri dst
        $g = K::cozumle(['dcontext' => 'from-internal', 'src' => '101', 'dst' => '05321234567', 'calldate' => '2026-11-03 10:00:00', 'billsec' => 42], $dahili, $trunk10);
        $this->assertSame(['5321234567', 'giden', 42], [$g['son10'], $g['yon'], $g['sure_sn']]);
        // Gelen: src müşteri; trunk numarası dış sayılmaz
        $this->assertSame('gelen', K::cozumle(['dcontext' => 'ext-local', 'src' => '905321234567', 'dst' => '101'], $dahili, $trunk10)['yon']);
        $this->assertNull(K::cozumle(['dcontext' => 'ext-local', 'src' => '902322404046', 'dst' => '101'], $dahili, $trunk10));
        // Dahili-dahili atlanır; originate'te numara kanal adından
        $this->assertNull(K::cozumle(['dcontext' => 'from-internal', 'src' => '101', 'dst' => '201'], $dahili, $trunk10));
        $o = K::cozumle(['dcontext' => 'from-internal', 'src' => '101', 'dst' => 's', 'channel' => 'Local/101@from-internal', 'dstchannel' => 'SIP/902322404046-out/05329998877-0001'], $dahili, $trunk10);
        $this->assertNotNull($o);
    }

    public function test_ses_adaylari_ve_eslestirme(): void
    {
        $satirlar = [
            ['recording_path' => '/r/out-1-20261103-101500-x.wav', 'channel' => 'SIP/x-out/05321234567'],
            ['recording_path' => '/r/out-1-20261103-091500-x.wav', 'src' => '05321234567'],
            ['recording_path' => '/r/out-1-20261103-091500-x.wav', 'src' => '05321234567'],   // tekrar
            ['recording_path' => '/r/baska.wav', 'src' => '05550000000'],
            ['recording_path' => '', 'src' => '05321234567'],
        ];
        $a = K::adaylar($satirlar, '5321234567');
        $this->assertSame(['/r/out-1-20261103-101500-x.wav', '/r/out-1-20261103-091500-x.wav'], array_column($a, 'path'));
        $this->assertSame(20261103101500, $a[0]['ts']);

        $esik = strtotime('2026-11-02 00:00:00');
        $notlar = [
            ['id' => 3, 'ts' => strtotime('2026-11-03 10:20:00'), 'ses' => null],
            ['id' => 2, 'ts' => strtotime('2026-11-03 09:20:00'), 'ses' => '/yanlis.wav'],
            ['id' => 1, 'ts' => strtotime('2026-10-01 09:00:00'), 'ses' => '/r/out-1-20261103-091500-x.wav'],   // eski: rezerve
        ];
        $e = K::eslestir($notlar, $a, $esik);
        $this->assertSame([3 => '/r/out-1-20261103-101500-x.wav', 2 => null], $e['degisen']);
        $this->assertSame([], $e['kullanilmayan']);
        $this->assertSame('https://h.tr/a.wav', K::url('a.wav', 'https://h.tr/'));
        $this->assertSame('', K::url(null, 'https://h.tr'));
        $this->assertSame('http://x/y.wav', K::url('http://x/y.wav', 'https://h.tr'));
    }

    public function test_erisim_ve_sekmeler(): void
    {
        $personel = new CagriMerkeziErisim(20, 7, false, 55, ['101'], false);
        $yonetici = new CagriMerkeziErisim(20, 3, true, null, [], false);
        $this->assertTrue($personel->maskele());
        $this->assertTrue($yonetici->maskele());   // anahtar kapaliysa yonetici de maskelenir
        $this->assertFalse((new CagriMerkeziErisim(20, 3, true, null, [], true))->maskele());
        $this->assertFalse((new CagriMerkeziErisim(20, 7, false, 55, [], true))->maskele());
        $this->assertTrue($personel->listeyeErisir(55));
        $this->assertFalse($personel->listeyeErisir(56));
        $this->assertFalse($personel->listeyeErisir(null));
        $this->assertTrue($yonetici->listeyeErisir(null));
        $this->assertFalse($personel->personeleAtayabilir(56));
        $this->assertSame(['calisma', 'ajanda', 'takvim', 'kayitlar'], array_column(CagriMerkeziSekmeleri::gorunur($personel), 'kod'));
        $this->assertSame(['calisma', 'ajanda', 'takvim', 'kayitlar', 'performans', 'listeler', 'ayarlar'], array_column(CagriMerkeziSekmeleri::gorunur($yonetici), 'kod'));
        $this->assertFalse(CagriMerkeziSekmeleri::acikMi('performans', $personel));
        $this->assertSame(ListeDurumu::AKTIF, ListeDurumu::dbden(null));
        $this->assertSame(ListeDurumu::AKTIF, ListeDurumu::dbden(0));
        $this->assertFalse(ListeDurumu::PASIF->bildirimUretir());
    }

    public function test_kvkk_maske(): void
    {
        $this->assertSame('Ferdi K.', KvkkMaske::adSoyad('Ferdi Korkmaz'));
        $this->assertSame('Ayşe Nur Ş.', KvkkMaske::adSoyad('  Ayşe  Nur şahin '));
        $this->assertSame('İ.', mb_substr(KvkkMaske::adSoyad('Ali İnce'), -2));
        $this->assertSame('Cem', KvkkMaske::adSoyad('Cem'));
        $this->assertSame('', KvkkMaske::adSoyad(null));
        $this->assertSame('0532 *** ** 67', KvkkMaske::telefon('05321234567'));
    }
}
