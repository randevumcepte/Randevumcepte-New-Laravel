<?php

namespace Tests\Unit\Domain\OnGorusme;

use App\Services\OnGorusme\OnGorusmeSunumu;
use PHPUnit\Framework\TestCase;

/** Mobil on gorusme satis penceresi paket varsayilanlarini e.paket'ten okur (eski ongorusmegetir $with['paket']). */
class OnGorusmeSunumuTest extends TestCase
{
    private function satir(array $ek = []): object
    {
        return (object) ($ek + [
            'id' => 5, 'user_id' => null, 'ad_soyad' => 'Ayse', 'cep_telefon' => '05551112233', 'email' => null, 'cinsiyet' => null,
            'adres' => null, 'meslek' => null, 'il_id' => null, 'aciklama' => null, 'musteri_tipi' => null, 'hatirlatma_tarihi' => '2026-11-08',
            'on_gorusme_saati' => '10:00:00', 'durum' => null, 'satisyapilmadi_not' => null, 'paket_id' => 9, 'urun_id' => null,
            'hizmet_id' => null, 'personel_id' => null, 'created_at' => null, 'personel_adi' => null, 'paket_adi' => 'Lazer 3 Bolge',
            'urun_adi' => null, 'urun_fiyat' => null, 'hizmet_adi' => null, 'musteri_adi' => null, 'randevu_id' => null, 'oda_id' => null,
        ]);
    }

    public function test_paket_nesnesi_satis_varsayilanlarini_tasir(): void
    {
        $ozet = ['fiyat' => 3000.0, 'miktar' => 24, 'satis_seans' => 8, 'seans_araligi' => 21, 'sinirsiz' => 0,
            'hizmetler' => [['hizmet_id' => 1, 'seans' => 8, 'fiyat' => 1000.0]]];
        $s = OnGorusmeSunumu::satir($this->satir(['paket_ozeti' => $ozet]), ['telefon_gor' => true, 'yazabilir' => true]);

        $this->assertSame(9, $s['paket']['id']);
        $this->assertSame('Lazer 3 Bolge', $s['paket']['paket_adi']);
        $this->assertSame(3000.0, $s['paket']['fiyat']);
        $this->assertSame(8, $s['paket']['satis_seans']);
        $this->assertSame(21, $s['paket']['seans_araligi']);
        $this->assertSame(0, $s['paket']['sinirsiz']);
        $this->assertSame(8, $s['paket']['hizmetler'][0]['seans']);
    }

    public function test_ozet_yoksa_eski_alanlar_ve_urun_fiyati(): void
    {
        $s = OnGorusmeSunumu::satir($this->satir(), ['telefon_gor' => true, 'yazabilir' => true]);
        $this->assertSame(['id' => 9, 'paket_adi' => 'Lazer 3 Bolge'], $s['paket']);

        $u = OnGorusmeSunumu::satir($this->satir(['paket_id' => null, 'urun_id' => 4, 'urun_adi' => 'Krem', 'urun_fiyat' => '150.00']), ['telefon_gor' => true, 'yazabilir' => true]);
        $this->assertNull($u['paket']);
        $this->assertSame(['id' => 4, 'urun_adi' => 'Krem', 'fiyat' => 150.0], $u['urun']);
    }
}
