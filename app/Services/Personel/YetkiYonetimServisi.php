<?php

namespace App\Services\Personel;

use App\Models\Personeller;
use App\Models\PersonelYetkiAyari;
use App\Services\Yetki\YetkiServisi;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use App\Support\Yetki\YetkiSabitleri;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Personel YETKI EKRANI orkestrasyonu — yetki ekranini besler (sema/getir/benim/kaydet).
 *
 * Karar MOTORU (App\Services\Yetki\YetkiServisi) ve sabitler (YetkiSabitleri)
 * yeniden kullanilir; motor BURADA COGALTILMAZ. Eski ApiController@personelYetki*
 * uclarinin birebir mantigidir.
 */
class YetkiYonetimServisi
{
    public function __construct(private YetkiServisi $motor) {}

    /** Yetki semasi: tanimlar + kategori etiketleri + hazir sablonlar. (Public referans veri.) */
    public function sema(): array
    {
        return [
            'tanimlar'    => YetkiSabitleri::tanimlar(),
            'kategoriler' => YetkiSabitleri::kategoriEtiketleri(),
            'sablonlar'   => collect(YetkiSabitleri::sablonlar())->map(fn ($s, $k) => [
                'key'      => $k,
                'ad'       => $s['ad'],
                'aciklama' => $s['aciklama'],
                'ikon'     => $s['ikon'],
                'ayarlar'  => $s['ayarlar'],
            ])->values()->all(),
        ];
    }

    /** Belli bir personelin mevcut yetki ayarlari (yetki yonetimi ekrani icin). */
    public function getir(int $personelId, int $salonId): array
    {
        $ayarlar = $this->motor->ayarlariGetir($personelId, $salonId);

        return [
            'personel_rolunde' => $this->motor->personelRolundeMi($personelId, $salonId),
            // Gercek kayitli satir var mi? (yoksa ayarlar yine default doner ama
            // ekranda hicbir sablon "secili" gosterilmemeli).
            'kayit_var'        => PersonelYetkiAyari::where('personel_id', $personelId)
                                    ->where('salon_id', $salonId)->exists(),
            'sablon'           => $ayarlar['sablon'],
            'ayarlar'          => $ayarlar['ayarlar'],
        ];
    }

    /**
     * Giris yapan kisinin KENDI efektif yetkileri (benimYetkilerim).
     *   - Personel kaydi yok → salon sahibi: tum yetki=true, sablon=tam_yetki
     *   - Personel rolu degil + ozel kayit yok → tam yetki
     *   - Aksi halde → gercek ayarlar
     * Yanittan mobilGizliAnahtarlar cikarilir (gelecekteki Flutter gocunde sahte
     * "zorla cikis" tetiklememek icin; sozlesme eski /api/v1 benimYetkilerim ile ayni).
     */
    public function benimYetkilerim(int $yetkiliId, int $salonId): array
    {
        $personelId = Personeller::where('yetkili_id', $yetkiliId)
            ->where('salon_id', $salonId)
            ->value('id');

        if (! $personelId) {
            return $this->tamYetkiYaniti(salonSahibi: true);
        }

        $personelRolunde = $this->motor->personelRolundeMi($personelId, $salonId);
        $ozelKayitVar = PersonelYetkiAyari::where('personel_id', $personelId)
            ->where('salon_id', $salonId)->exists();

        if (! $personelRolunde && ! $ozelKayitVar) {
            return $this->tamYetkiYaniti(salonSahibi: false);
        }

        $ayarlar = $this->motor->ayarlariGetir($personelId, $salonId);

        return [
            'personel_rolunde' => true,
            'salon_sahibi'     => false,
            'sablon'           => $ayarlar['sablon'],
            'ayarlar'          => Arr::except($ayarlar['ayarlar'], YetkiSabitleri::mobilGizliAnahtarlar()),
            'personel_id'      => (int) $personelId,
        ];
    }

    /**
     * Yetki ayarlarini kaydet. Kayit + whitelist motorda; personele force_logout push'u
     * ayni islemde kuyruga yazilir (eski personelYetkiDegistiBildir gonderir).
     */
    public function kaydet(int $personelId, int $salonId, string $sablon, array $ayarlar, ?int $yapanId = null): void
    {
        DB::transaction(function () use ($personelId, $salonId, $sablon, $ayarlar, $yapanId) {
            $this->motor->ayarlariKaydet($personelId, $salonId, $sablon, $ayarlar);
            BildirimKuyrugu::yaz($salonId, 'yetki_degisti', null, $yapanId, ['personel_id' => $personelId]);
            AktiviteLog::yaz($salonId, $yapanId, 'personel_yetki_kaydet', 'personel', $personelId,
                Personeller::where('id', $personelId)->value('personel_adi'), 'Personel yetkileri güncellendi', ['sablon' => $sablon]);
        });
    }

    /** Tum anahtarlar true + sablon=tam_yetki (mobilGizli cikarilmis). */
    private function tamYetkiYaniti(bool $salonSahibi): array
    {
        $tum = [];
        foreach (YetkiSabitleri::tumAnahtarlar() as $k) {
            $tum[$k] = true;
        }

        return [
            'personel_rolunde' => false,
            'salon_sahibi'     => $salonSahibi,
            'sablon'           => 'tam_yetki',
            'ayarlar'          => Arr::except($tum, YetkiSabitleri::mobilGizliAnahtarlar()),
        ];
    }
}
