<?php

namespace Tests\Feature\Tahsilat;

use App\Services\Tahsilat\Mobil\MobilTahsilatServisi;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Mobil tahsilatekle dagitimi web ile ayni kodla (kullanici karari 2026-11-08). Eski mobil formulunun hatalari burada
 * ornek sayilarla kilitlenir: kurus farki, kalan borcu asan vade payi, birden cok vadede katlanan odeme.
 */
class MobilTahsilatDagitimTest extends TestCase
{
    use TahsilatTestKurulumu;

    private MobilTahsilatServisi $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tahsilatKur();
        $this->s = app(MobilTahsilatServisi::class);
    }

    private function ozel(string $ad, mixed ...$arg): mixed
    {
        $m = new \ReflectionMethod($this->s, $ad);

        return $m->invoke($this->s, ...$arg);
    }

    private function plan(array $g, array $idler, array $taksit, array $senet, int $odemeKurus): array
    {
        $kalemler = $this->ozel('plansiz', $idler + ['hizmet' => [], 'urun' => [], 'paket' => []]);

        return $this->ozel('plan', $g, $kalemler, $taksit, $senet, $odemeKurus, 1);
    }

    public function test_normal_tahsilat_kurus_kesin(): void
    {
        $idler = [];
        foreach ([1, 2, 3] as $_) {
            $idler[] = $this->kalem('adisyon_hizmetler', ['fiyat' => '100.00']);
        }

        $p = $this->plan([], ['hizmet' => $idler], [], [], 10000);

        // Eski mobil: 33.333.. x3 (kolon 33.33 x3 = 99.99). Yeni: 33.34 + 33.33 + 33.33 = 100.00.
        $this->assertSame([33.34, 33.33, 33.33], array_column($p['kalem_tahsilatlari'], 'tutar'));
    }

    public function test_normal_tahsilat_kalan_borca_oranli_ve_sinirli(): void
    {
        $a = $this->kalem('adisyon_hizmetler', ['fiyat' => '100.00']);
        $b = $this->kalem('adisyon_urunler', ['fiyat' => '100.00']);
        $this->odenmis('tahsilat_hizmetler', 'adisyon_hizmet_id', $a, '90.00');

        $p = $this->plan([], ['hizmet' => [$a], 'urun' => [$b]], [], [], 11000);

        // Eski mobil fiyata oranli 55 / 55 yazardi (hizmet 145 odenmis olurdu). Yeni: kalan 10 / 100.
        $this->assertSame([10.0, 100.0], array_column($p['kalem_tahsilatlari'], 'tutar'));
    }

    public function test_cok_vadede_odeme_katlanmaz_ve_kalan_kaleme_gider(): void
    {
        DB::table('senet_vadeleri')->insert([['senet_id' => 4, 'tutar' => '100.00'], ['senet_id' => 4, 'tutar' => '100.00']]);
        $this->kalem('adisyon_hizmetler', ['fiyat' => '200.00', 'senet_id' => 4]);
        $k = $this->kalem('adisyon_urunler', ['fiyat' => '50.00']);

        $p = $this->plan(['odeme_yontemi' => '1'], ['urun' => [$k]], [], [1, 2], 25000);

        // Eski mobil her vadeye 250 TL'nin tamamini ve kaleme yine 250 TL'yi yazardi (toplam 750). Yeni: 100 + 100 + 50 = 250.
        $this->assertSame([100.0, 100.0, 50.0], array_column($p['kalem_tahsilatlari'], 'tutar'));
        $this->assertCount(2, $p['vade_guncellemeleri']);
    }

    public function test_musteri_indirimi_kurus_kesin_ve_indirimli_kalem_atlanir(): void
    {
        $idler = [
            $this->kalem('adisyon_hizmetler', ['fiyat' => '100.00']),
            $this->kalem('adisyon_hizmetler', ['fiyat' => '100.00']),
            $this->kalem('adisyon_hizmetler', ['fiyat' => '100.00', 'indirim_tutari' => '5.00']),
            $this->kalem('adisyon_hizmetler', ['fiyat' => '100.00']),
        ];
        $kalemler = $this->ozel('plansiz', ['hizmet' => $idler, 'urun' => [], 'paket' => []]);

        $this->ozel('musteriIndirimiYaz', ['musteri_indirimi' => '120,00', 'indirim_tutari' => '20,00'], $kalemler, 1);

        // 100 TL musteri indirimi 4 kaleme 25'er; indirimli kalemin payi baskasina aktarilmaz.
        $this->assertSame([25.0, 25.0, 5.0, 25.0], DB::table('adisyon_hizmetler')->orderBy('id')->pluck('indirim_tutari')->map(fn ($v) => (float) $v)->all());
        $this->assertSame(25.0, (float) $kalemler['hizmet'][0]->indirim_tutari);
    }
}
