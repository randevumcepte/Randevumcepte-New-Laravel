<?php

namespace Tests\Unit\Domain\Kampanya;

use App\Domain\Kampanya\HedefKitle;
use App\Domain\Kampanya\Indirim;
use App\Domain\Kampanya\KalemSecimi;
use App\Domain\Kampanya\Kanal;
use App\Domain\Kampanya\KatilimDurumu;
use App\Domain\Kampanya\MesajMetni;
use App\Domain\Kampanya\SablonKimligi;
use App\Domain\Kampanya\Senaryo;
use App\Domain\Musteri\Segment;
use App\Exceptions\GecersizIslemException;
use PHPUnit\Framework\TestCase;

/** Reklam Yönetimi domain kuralları (DB'siz). Eski monolitle birebir olması gereken biçimler sabitlenir. */
class KampanyaDomainTest extends TestCase
{
    public function test_indirim_metni_eski_bicimle_ayni_ve_geri_okunur(): void
    {
        $y = Indirim::olustur(Indirim::YUZDE, 15, null, null);
        $this->assertSame('%15 İndirim', $y->metin());                 // kupon regex'i /%\s*(\d+)/ bunu okur
        $this->assertSame(15, Indirim::metinden($y->metin())->yuzde);

        $x = Indirim::olustur(Indirim::XAL_YODE, null, 3, 2);
        $this->assertSame('3 Al 2 Öde', $x->metin());
        $this->assertSame([3, 2], [Indirim::metinden('3 Al 2 Öde')->xal, Indirim::metinden('3 Al 2 Öde')->yode]);
        $this->assertSame('3 Al 2 Öde', $x->yerTutucu());               // eskiden boş kalıyordu
        $this->assertNull(Indirim::metinden(''));
    }

    public function test_indirim_gecersiz_degerler_reddedilir(): void
    {
        $this->expectException(GecersizIslemException::class);
        Indirim::olustur(Indirim::XAL_YODE, null, 2, 2);
    }

    public function test_hedef_kitle_segment_ve_etiket(): void
    {
        $h = HedefKitle::olustur('6', '', 'kadinlar');
        $this->assertSame(Segment::SADIK, $h->segment());
        $this->assertSame(0, $h->cinsiyetDb());
        $this->assertSame('Kadın sadık müşteriler', $h->etiket());
        $this->assertSame('sadik', $h->presetKodu());

        $this->assertSame(Segment::PASIF, HedefKitle::olustur('8', null, null)->segment());
        $this->assertSame('Tüm müşteriler', HedefKitle::olustur('', '', '')->etiket());
        $this->assertSame('2025-01-01 00:00:00', HedefKitle::olustur('1', '', '')->kayitBaslangici(new \DateTimeImmutable('2026-11-03')));
    }

    public function test_grup_secilince_filtre_yok_sayilir(): void
    {
        $h = HedefKitle::olustur('6', 'haricigrup-12', 'erkekler');
        $this->assertSame('', $h->filtre);
        $this->assertSame('haricigrup-12', $h->grupDegeri());
        $this->assertSame('grup', $h->presetKodu());
        $this->assertSame('VIP grubu', $h->etiket('VIP'));
        $this->assertTrue($h->esitMi(HedefKitle::olustur('', 'haricigrup-12', 'erkekler')));
        $this->assertFalse($h->esitMi(HedefKitle::olustur('', 'haricigrup-12', '')));
    }

    public function test_hedef_kitle_gecersiz_grup(): void
    {
        $this->expectException(GecersizIslemException::class);
        HedefKitle::olustur('', 'hastagrup-3', '');
    }

    public function test_kalem_ve_sablon_kimligi(): void
    {
        $this->assertSame(['hizmet_id' => null, 'urun_id' => 7, 'paket_id' => null], KalemSecimi::kolonlar(KalemSecimi::coz('urun-7')));
        $this->assertSame('paket', KalemSecimi::coz('42')->tur);   // eski: çıplak sayı = paket
        $this->assertNull(KalemSecimi::coz(''));
        $this->assertSame('hizmet-3', KalemSecimi::kayittan((object) ['hizmet_id' => 3, 'urun_id' => 9, 'paket_id' => null])->deger());

        $this->assertSame(SablonKimligi::SENARYO, SablonKimligi::coz('senaryo-5')->tur);
        $this->assertSame(SablonKimligi::TASLAK, SablonKimligi::coz('sablon-2')->tur);
        $this->assertSame('11', SablonKimligi::coz('11')->deger());
    }

    public function test_mesaj_yer_tutuculari_iki_asamada_cozulur(): void
    {
        $sablon = 'Sayın {müşteri}, {işletmeden} {ürün_hizmet_paket} için %{indirim} — {indirim_veya_x_al_y_ode}. {gün} gün oldu.';
        $kayit = MesajMetni::kampanyaAsamasi($sablon, 'Güzellik Evi', Indirim::olustur(Indirim::YUZDE, 20, null, null), 'Cilt Bakımı');
        $this->assertSame('Sayın {müşteri}, Güzellik Evi Cilt Bakımı için %20 — %20 indirim. {gün} gün oldu.', $kayit);
        $this->assertSame('Sayın Ayşe Kaya, Güzellik Evi Cilt Bakımı için %20 — %20 indirim. 45 gün oldu.', MesajMetni::kisiAsamasi($kayit, 'Ayşe Kaya', 45));
        // Kalem seçilmediyse {ürün_hizmet_paket} metinde kalır (eski davranış).
        $this->assertStringContainsString('{ürün_hizmet_paket}', MesajMetni::kampanyaAsamasi('{ürün_hizmet_paket}', 'X', null, null));
    }

    public function test_arama_kanalinda_sablon_selami_atilir_ve_cron_selami_eklenir(): void
    {
        $this->assertSame('Size özel indirimimiz var.', MesajMetni::ilkCumleyiAt('İyi günler {müşteri}, sizi {işletmeden} adına arıyorum. Size özel indirimimiz var.'));
        $o = MesajMetni::onizleme('Size özel indirimimiz var.', Kanal::Arama, 'Ali Veli', 3, 'Salon');
        $this->assertSame('Merhaba Ali Veli. Sizi Salon arıyorum. Umarım gününüz sağlıklı geçiyordur. Size özel indirimimiz var.', $o);
        $this->assertSame('Size özel', MesajMetni::onizleme('Size özel', Kanal::Sms, 'Ali', 3, 'Salon'));
    }

    public function test_katilim_durumu_satir_onceligi_ve_filtreler(): void
    {
        $r = fn (array $a) => (object) ($a + ['durum_asistan' => null, 'kilitli' => 0, 'tekrar_arandi' => null, 'tekrar_aranacak' => null, 'tekrar_arama_tarih_saat' => null]);
        $this->assertSame('katildi', KatilimDurumu::satir($r(['durum_asistan' => 1, 'kilitli' => 1]), Kanal::Arama)['kod']);
        $this->assertSame('katilmadi', KatilimDurumu::satir($r(['durum_asistan' => 0]), Kanal::Arama)['kod']);
        $this->assertSame('araniyor', KatilimDurumu::satir($r(['kilitli' => 1, 'tekrar_arandi' => 1]), Kanal::Arama)['kod']);
        $this->assertSame('ulasilamadi', KatilimDurumu::satir($r(['tekrar_arandi' => 1]), Kanal::Arama)['kod']);
        $this->assertSame('sirada', KatilimDurumu::satir($r([]), Kanal::Arama)['kod']);
        $this->assertSame('listede', KatilimDurumu::satir($r([]), Kanal::Sms)['kod']);

        $this->assertSame(KatilimDurumu::IndirimKullanan, KatilimDurumu::coz('2'));     // Flutter katilimDurumu
        $this->assertSame(KatilimDurumu::Ulasilamadi, KatilimDurumu::coz('ulasilamadi'));
        $this->assertSame(KatilimDurumu::Tumu, KatilimDurumu::coz('bilinmeyen'));
        $this->assertNull(KatilimDurumu::Tumu->sqlKosulu());
    }

    public function test_kanal_sozlugu(): void
    {
        $this->assertFalse(Kanal::Bilgilendirme->indirimli());
        $this->assertSame('arama_ile_gonderim', Kanal::Arama->bayrakKolonu());
        $this->assertSame('sms_tarih_saat', Kanal::Sms->zamanKolonu());
        $this->assertNull(Kanal::degerden('9'));
    }

    public function test_senaryo_sablon_metni_ve_onizleme(): void
    {
        $p = Senaryo::presetler()['geri_kazanim'];
        $this->assertCount(8, Senaryo::adimlariTemizle($p['adimlar']));
        $this->assertSame(trim($p['adimlar']['acilis'].' '.$p['adimlar']['soru_kod']), Senaryo::sablonMetni($p['adimlar'], 1));
        $this->assertSame($p['adimlar']['acilis'], Senaryo::sablonMetni($p['adimlar'], 2));

        $o = Senaryo::onizle($p['adimlar'], $p['aksiyonlar'], 1, fn ($m) => $m);
        $kimler = array_column($o['diyalog'], 'kim');
        $this->assertSame('bot', $kimler[0]);
        $this->assertContains('musteri', $kimler);
        $this->assertSame($p['adimlar']['kapanis'], end($o['diyalog'])['metin']);

        $sms = Senaryo::onizle($p['adimlar'], $p['aksiyonlar'], 2, fn ($m) => $m);
        $this->assertCount(1, $sms['diyalog']);
        $this->assertSame(['indirim_kodu_sms' => true, 'yol_tarifi_sms' => false, 'randevu_olustur' => false],
            Senaryo::aksiyonlariTemizle(['indirim_kodu_sms' => '1', 'fazla' => true]));
    }
}
