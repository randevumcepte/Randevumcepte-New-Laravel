<?php

namespace Tests\Unit\Yetki;

use App\Support\Yetki\YetkiSabitleri as Y;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Rol varsayilanlari ve sablonlarin saf kurallari (DB/HTTP'siz). */
class YetkiSabitleriTest extends TestCase
{
    private const SEKRETER_KAPALI = ['rapor.kasa', 'rapor.satis', 'rapor.personel_performans', 'rapor.musteri'];

    public function test_sekreterde_yalniz_kasa_satis_isletme_raporlari_kapali(): void
    {
        $ayarlar = Y::rolVarsayilanAyarlari([Y::ROL_SEKRETER]);
        $kapali = array_keys(array_filter($ayarlar, fn ($v) => $v === false));
        sort($kapali);
        $beklenen = [...self::SEKRETER_KAPALI, ...Y::kisitAnahtarlari()];
        sort($beklenen);

        $this->assertSame($beklenen, $kapali);
        $this->assertTrue($ayarlar['musteri.telefon_gor']);
        $this->assertTrue($ayarlar['personel.yetki_yonet']);
        $this->assertTrue($ayarlar['finans.masraf_gor']);
    }

    /** @return array<string, array{0: int[]}> */
    public static function tamYetkiliRoller(): array
    {
        return [
            'hesap sahibi' => [[1]],
            'rol 2'        => [[2]],
            'rol 3'        => [[3]],
            'rolsuz'       => [[]],
            'sahip + personel' => [[1, 5]],
            'sahip + sekreter' => [[1, 4]],
        ];
    }

    #[DataProvider('tamYetkiliRoller')]
    public function test_ozel_kaydi_olmayan_ust_rollerde_tum_sayfalar_acik(array $roller): void
    {
        $this->assertSame(Y::tamYetkiAyarlari(), Y::rolVarsayilanAyarlari($roller));
        $this->assertSame('tam_yetki', Y::rolVarsayilanSablonu($roller));
    }

    public function test_personel_rolu_personel_sablonunu_alir_ust_rol_kisitlamaz(): void
    {
        $this->assertSame(Y::sablonAyarlari('personel'), Y::rolVarsayilanAyarlari([Y::ROL_PERSONEL]));
        $this->assertSame(Y::sablonAyarlari('personel'), Y::rolVarsayilanAyarlari([Y::ROL_SEKRETER, Y::ROL_PERSONEL]));
        $this->assertSame('personel', Y::rolVarsayilanSablonu([Y::ROL_PERSONEL]));
        $this->assertSame('sekreter_varsayilan', Y::rolVarsayilanSablonu([Y::ROL_SEKRETER]));
    }

    public function test_tam_yetki_kisit_anahtarini_acmaz(): void
    {
        $tam = Y::tamYetkiAyarlari();

        $this->assertFalse($tam['personel.kapali_adisyon_gizle']);
        $this->assertCount(count(Y::tumAnahtarlar()), $tam);
        $this->assertSame([], array_diff(Y::kisitAnahtarlari(), Y::tumAnahtarlar()));
        unset($tam['personel.kapali_adisyon_gizle']);
        $this->assertNotContains(false, $tam);
    }

    public function test_telefon_varsayilani_yalniz_yonetici_sablonunda_acik(): void
    {
        $this->assertTrue(Y::sablonAyarlari('yonetici')['musteri.telefon_gor']);
        $this->assertFalse(Y::sablonAyarlari('sekreter')['musteri.telefon_gor']);
        $this->assertFalse(Y::sablonAyarlari('personel')['musteri.telefon_gor']);
    }

    public function test_sekreter_kapali_anahtarlari_tanimli(): void
    {
        $this->assertSame(self::SEKRETER_KAPALI, Y::sekreterKapaliAnahtarlar());
        $this->assertSame([], array_diff(Y::sekreterKapaliAnahtarlar(), Y::tumAnahtarlar()));
    }

    /** Yeni anahtar eklenip bir sablon unutulursa burada yakalanir. */
    public function test_her_sablon_tum_anahtarlari_tanimlar(): void
    {
        $anahtarlar = Y::tumAnahtarlar();
        sort($anahtarlar);
        foreach (Y::sablonlar() as $kod => $sablon) {
            $tanimli = array_keys($sablon['ayarlar']);
            sort($tanimli);
            $this->assertSame($anahtarlar, $tanimli, "Sablon eksik/fazla anahtar: $kod");
        }
    }
}
