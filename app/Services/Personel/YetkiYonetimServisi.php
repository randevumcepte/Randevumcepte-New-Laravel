<?php

namespace App\Services\Personel;

use App\Domain\Personel\HesapSahibiKorumasi;
use App\Exceptions\YetkisizErisimException;
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
    private HesapSahibiDenetimi $sahip;

    public function __construct(private YetkiServisi $motor, ?HesapSahibiDenetimi $sahip = null)
    {
        $this->sahip = $sahip ?? new HesapSahibiDenetimi();
    }

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
     * Karar motorun efektifYetkiler'inden gelir (uc denetimiyle ayni sonuc):
     *   - sablon=tam_yetki → personel_rolunde=false, tum yetkiler
     *   - Aksi halde       → personel_rolunde=true, gercek ayarlar
     * Yanittan mobilGizliAnahtarlar cikarilir (gelecekteki Flutter gocunde sahte
     * "zorla cikis" tetiklememek icin; sozlesme eski /api/v1 benimYetkilerim ile ayni).
     */
    public function benimYetkilerim(int $yetkiliId, int $salonId): array
    {
        $e = $this->motor->efektifYetkiler($yetkiliId, $salonId);
        $ayarlar = Arr::except($e['ayarlar'], YetkiSabitleri::mobilGizliAnahtarlar());

        if ($e['sablon'] === 'tam_yetki') {
            return [
                'personel_rolunde' => false,
                'salon_sahibi'     => in_array(YetkiSabitleri::ROL_HESAP_SAHIBI, $e['roller'], true) || $e['personel_id'] === null,
                'sablon'           => 'tam_yetki',
                'ayarlar'          => $ayarlar,
            ];
        }

        return [
            'personel_rolunde' => true,
            'salon_sahibi'     => false,
            'sablon'           => $e['sablon'],
            'ayarlar'          => $ayarlar,
            'personel_id'      => $e['personel_id'],
        ];
    }

    /**
     * Yetki ayarlarini kaydet. Kayit + whitelist motorda; personele force_logout push'u
     * ayni islemde kuyruga yazilir (eski personelYetkiDegistiBildir gonderir).
     * Kullanici kendi yetkisini degistiremez (kendine rapor/telefon acmasin). Hesap sahibinin satiri (personel rol 1
     * ya da model_has_roles rol 1; HesapSahibiDenetimi) yalniz sahibin kendisi / sistem yonetimi tarafindan
     * (HesapSahibiKorumasi::YETKI) — kendi yetkisi kurali sahibe de uygulandigindan fiilen yalniz sistem yonetimi.
     *
     * @throws YetkisizErisimException
     * @throws \App\Exceptions\GecersizIslemException hesap sahibinin yetkisi (422)
     */
    public function kaydet(int $personelId, int $salonId, string $sablon, array $ayarlar, ?int $yapanId = null): void
    {
        $p = Personeller::where('id', $personelId)->where('salon_id', $salonId)->first(['role_id', 'yetkili_id']);
        $hedefYetkili = $p && $p->yetkili_id ? (int) $p->yetkili_id : null;
        if ($yapanId && $hedefYetkili === $yapanId) {
            throw new YetkisizErisimException('Kendi yetkilerinizi değiştiremezsiniz');
        }
        $this->sahip->denetle(HesapSahibiKorumasi::YETKI, $p && $p->role_id !== null ? (int) $p->role_id : null, $hedefYetkili, $salonId, $yapanId);

        DB::transaction(function () use ($personelId, $salonId, $sablon, $ayarlar, $yapanId) {
            $this->motor->ayarlariKaydet($personelId, $salonId, $sablon, $ayarlar);
            BildirimKuyrugu::yaz($salonId, 'yetki_degisti', null, $yapanId, ['personel_id' => $personelId]);
            AktiviteLog::yaz($salonId, $yapanId, 'personel_yetki_kaydet', 'personel', $personelId,
                Personeller::where('id', $personelId)->value('personel_adi'), 'Personel yetkileri güncellendi', ['sablon' => $sablon]);
        });
    }
}
