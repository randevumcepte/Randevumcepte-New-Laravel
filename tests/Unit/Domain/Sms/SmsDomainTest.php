<?php

namespace Tests\Unit\Domain\Sms;

use App\Domain\Sms\MesajUzunlugu;
use App\Domain\Sms\SmsAyarKatalogu;
use App\Domain\Sms\SmsDurumu;
use App\Domain\Sms\SmsKuyrukDurumu;
use App\Domain\Sms\SmsSaglayici;
use App\Domain\Sms\SmsSekmeleri;
use App\Domain\Sms\SmsTuru;
use App\Domain\Sms\VoiceTelekomPaketi;
use App\Exceptions\GecersizIslemException;
use PHPUnit\Framework\TestCase;

/** SMS Yönetimi domain kuralları (DB'siz). Eski ekran + Flutter ile aynı kalması gereken değerler sabitlenir. */
class SmsDomainTest extends TestCase
{
    public function test_mesaj_parcasi_eski_esiklerle_ayni(): void
    {
        $this->assertSame(1, MesajUzunlugu::parca(''));
        $this->assertSame(1, MesajUzunlugu::parca(str_repeat('a', 155)));
        $this->assertSame(2, MesajUzunlugu::parca(str_repeat('a', 156)));
        $this->assertSame(2, MesajUzunlugu::parca(str_repeat('a', 292)));
        $this->assertSame(3, MesajUzunlugu::parca(str_repeat('a', 293)));
        $this->assertSame(6, MesajUzunlugu::parca(str_repeat('a', 882)));
        $this->assertSame(7, MesajUzunlugu::parca(str_repeat('a', 883)));
        $this->assertSame(1, MesajUzunlugu::parca(str_repeat('ş', 155)), 'çok baytlı harf tek karakter sayılır');
    }

    public function test_tur_sayilari_ve_basliklari_rapor_kayitlariyla_ayni(): void
    {
        $this->assertSame(4, SmsTuru::koddan('toplu')->value);
        $this->assertSame(3, SmsTuru::koddan('filtre')->value);
        $this->assertSame(SmsTuru::Grup, SmsTuru::basliktan('Grup Sms'), 'eski kayıtlarda iki yazım da var');
        $this->assertSame(SmsTuru::Toplu, SmsTuru::basliktan('Toplu SMS'));
        $this->assertSame(SmsTuru::Bildirim, SmsTuru::basliktan('tanınmayan başlık'));
        $this->assertSame('Filtreli SMS', SmsTuru::Filtre->saglayiciBasligi());
    }

    public function test_yalniz_toplu_ve_filtreli_gonderilebilir(): void
    {
        $this->assertSame(SmsTuru::Filtre, SmsTuru::gonderilebilir('filtre'));
        $this->expectException(GecersizIslemException::class);
        SmsTuru::gonderilebilir('bildirim');
    }

    public function test_paket_durumu_etiketi_ve_voicetelekom_eslemesi(): void
    {
        $this->assertSame('Bekliyor', SmsDurumu::paketEtiketi(null));
        $this->assertSame('Gönderildi', SmsDurumu::paketEtiketi(99));
        $this->assertSame('Gönderilemedi (Bakiye Yetersiz)', SmsDurumu::paketEtiketi(91));
        $this->assertSame('yesil', SmsDurumu::paketRengi(2));
        $this->assertSame('kirmizi', SmsDurumu::paketRengi(95));
        $this->assertSame('turuncu', SmsDurumu::paketRengi(0));

        $this->assertSame(SmsDurumu::IPTAL, SmsDurumu::voiceTelekomPaket(-1, 5));
        $this->assertSame(SmsDurumu::GONDERILDI, SmsDurumu::voiceTelekomPaket(2, 0));
        $this->assertSame(SmsDurumu::GONDERILDI, SmsDurumu::voiceTelekomPaket(1, 1), 'iletim varsa durum 1 de gönderildi');
        $this->assertSame(SmsDurumu::BEKLIYOR, SmsDurumu::voiceTelekomPaket(1, 0));
    }

    public function test_ayar_katalogu_web_kartlariyla_ayni_kumede(): void
    {
        $kartlar = SmsAyarKatalogu::kartlar([3 => ['musteri' => true, 'personel' => false]]);
        $idler = array_column($kartlar, 'ayar_id');
        sort($idler);
        $this->assertSame(range(1, 23), $idler);

        $iptal = $kartlar[array_search(3, array_column($kartlar, 'ayar_id'), true)];
        $this->assertTrue($iptal['musteri']);
        $this->assertFalse($iptal['personel']);
    }

    public function test_yalniz_kartin_hedefi_yazilir(): void
    {
        $this->assertSame(['musteri' => 1], SmsAyarKatalogu::kolonlar(['ayar_id' => 4, 'musteri' => true, 'personel' => true]));
        $this->assertSame(['personel' => 0], SmsAyarKatalogu::kolonlar(['ayar_id' => 21, 'musteri' => true, 'personel' => false]));
        $this->assertSame([], SmsAyarKatalogu::kolonlar(['ayar_id' => 2]), 'istekte olmayan kolon değişmez');
    }

    public function test_whatsapp_eslenikli_ayar_iki_kolonu_birlikte_yazar(): void
    {
        foreach ([1, 3, 6, 14] as $id) {
            $this->assertSame(
                ['musteri' => 1, 'whatsapp_musteri' => 1, 'personel' => 0, 'whatsapp_personel' => 0],
                SmsAyarKatalogu::kolonlar(['ayar_id' => $id, 'musteri' => true, 'personel' => false]),
            );
        }
        $this->assertSame(['musteri' => 1, 'personel' => 1], SmsAyarKatalogu::kolonlar(['ayar_id' => 2, 'musteri' => 1, 'personel' => 'true']));
    }

    public function test_katalogda_olmayan_ayar_reddedilir(): void
    {
        $this->expectException(GecersizIslemException::class);
        SmsAyarKatalogu::kolonlar(['ayar_id' => 99, 'musteri' => true]);
    }

    public function test_saglayici_secimi_ve_hesap_kontrolu(): void
    {
        $vt = (object) ['yeni_sms' => 1, 'sms_baslik' => 'SALON', 'sms_user_name' => 'k', 'sms_secret' => 's'];
        $this->assertSame(SmsSaglayici::VoiceTelekom, SmsSaglayici::salondan($vt));
        $this->assertTrue(SmsSaglayici::VoiceTelekom->hesapTanimli($vt));
        $this->assertFalse(SmsSaglayici::VoiceTelekom->hesapTanimli((object) ['yeni_sms' => 1, 'sms_baslik' => 'SALON', 'sms_user_name' => 'k']));

        $ef = (object) ['yeni_sms' => 0, 'sms_baslik' => 'SALON', 'sms_apikey' => 'a'];
        $this->assertSame(SmsSaglayici::Efetech, SmsSaglayici::salondan($ef));
        $this->assertTrue(SmsSaglayici::Efetech->hesapTanimli($ef));
        $this->assertFalse(SmsSaglayici::Efetech->hesapTanimli((object) ['yeni_sms' => 0, 'sms_apikey' => 'a']), 'gönderici başlığı zorunlu');
    }

    public function test_kuyruk_durumlari_eski_tuketicinin_gormedigi_degerler(): void
    {
        foreach ([SmsKuyrukDurumu::BEKLIYOR, SmsKuyrukDurumu::ISLENIYOR] as $d) {
            $this->assertFalse(in_array($d, [0, 3], true), 'eski v2:bildirim-isle 0 ve 3 ile çalışır');
            $this->assertFalse(SmsKuyrukDurumu::sunum($d)['tamamlandi']);
        }
        $this->assertTrue(SmsKuyrukDurumu::sunum(SmsKuyrukDurumu::HATA)['tamamlandi']);
    }

    public function test_voicetelekom_paketi_eski_istemciyle_ayni(): void
    {
        $g = VoiceTelekomPaketi::govde('Toplu SMS', 'Merhaba', [1 => '5321234567'], 'SALON', 'sms_x');
        $this->assertSame(['5321234567'], $g['numbers']);
        $this->assertSame('Toplu SMS', $g['title']);
        $this->assertSame('SALON', $g['sender']);
        $this->assertSame(0, $g['encoding']);
        $this->assertSame(true, $g['skipAhsQuery']);
        $this->assertSame(false, $g['commercial']);
        $this->assertSame(60, $g['validity']);
    }

    public function test_ayarlar_sekmesi_yalniz_hesap_sahibine(): void
    {
        $kodlar = fn (bool $ayar) => array_column(SmsSekmeleri::gorunur(['ayar' => $ayar]), 'kod');
        $this->assertSame(['raporlar', 'toplu', 'ayarlar', 'kara_liste'], $kodlar(true));
        $this->assertSame(['raporlar', 'toplu', 'kara_liste'], $kodlar(false));
    }
}
