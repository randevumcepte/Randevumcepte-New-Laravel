<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Exceptions\CokFazlaIstekException;
use App\Exceptions\GecersizIslemException;
use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\KapsamServisi;
use App\Support\DogrulamaMetinleri;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * Tum /api/v2 controller'larinin ortak tabani — kimlik, kayit-bulunamadi ve yazma
 * kill-switch adimlari TEK yerde (her controller'da kopyalanmaz).
 *
 * Hatalar ApiException alt siniflari olarak firlatilir; bootstrap/app.php bunlari
 * standart ApiCevap zarfina ({basarili:false, veri:null, mesaj}) cevirir — web ve
 * Flutter ayni hata sozlesmesini gorur.
 */
abstract class V2Controller extends Controller
{
    /** Yazma kill-switch'inin config alani: config/<alan>.php → yazma_aktif. */
    protected ?string $yazmaAlani = null;

    /** Kill-switch kapaliyken donen 409 mesaji (cagri yerinde ozellestirilebilir). */
    protected string $yazmaKapaliMesaj = 'Gerçek yazma henüz etkin değil';

    /** Giris yapan isletme yetkilisi (legacy-passport). Yoksa 403. */
    protected function kullanici()
    {
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }

        return $user;
    }

    /**
     * Istekteki salon_id + KAPSAM + YETKI (OR-gate) tek adimda. $yetkiler = [] → yalniz kapsam.
     *
     * @param  array<string>|string  $yetkiler
     * @return array{0:int,1:int} [yetkili id, salon id]
     */
    protected function salonDenetle(Request $request, array|string $yetkiler): array
    {
        $user = $this->kullanici();
        $salonId = $this->salonId($request);
        $this->kapiDenetle((int) $user->id, $salonId, $yetkiler);

        return [(int) $user->id, $salonId];
    }

    /**
     * Kayit id'li uc (PATCH/DELETE /x/{id}): kaydin salonu cozulur; kayit YOKSA da kullanicinin kapsami DISINDAKI bir
     * salondaysa da AYNI 404 doner (id yoklama: "yok" ile "baskasinin" ayirt edilemez). Kapsam icindeyse lisans + yetki
     * (OR-gate; [] → yalniz kapsam) denetlenir, eksikse 403. Yazma bayragi (yazmaAcikMi) bundan SONRA cagrilir.
     *
     * @param  array<string>|string  $yetkiler
     * @return array{0:int,1:int} [yetkili id, kaydin salon id'si]
     */
    protected function kayitDenetle(?int $kayitSalonId, array|string $yetkiler, string $mesaj = 'Kayıt bulunamadı'): array
    {
        $user = $this->kullanici();
        $salonId = $this->bulundu($kayitSalonId, $mesaj);
        if (! app(KapsamServisi::class)->salonaBagliMi($user->id, $salonId)) {
            throw new BulunamadiException($mesaj);
        }
        $this->kapiDenetle((int) $user->id, $salonId, $yetkiler);

        return [(int) $user->id, $salonId];
    }

    /**
     * Govdede id'si gelen, istekteki salona ait olmasi gereken kayit (orn. POST /tahsilat {salon_id, adisyon_id}):
     * ONCE salon_id + kapsam + yetki, SONRA kaydin salonu cozulur ($kayitSalonu tembel calisir). Kayit yoksa ya da
     * baska salonunsa ayni 404.
     *
     * @param  array<string>|string  $yetkiler
     * @param  Closure(): ?int  $kayitSalonu
     * @return array{0:int,1:int} [yetkili id, salon id]
     */
    protected function salondakiKayit(Request $request, array|string $yetkiler, Closure $kayitSalonu, string $mesaj = 'Kayıt bulunamadı'): array
    {
        [$uid, $salonId] = $this->salonDenetle($request, $yetkiler);
        if ($kayitSalonu() !== $salonId) {
            throw new BulunamadiException($mesaj);
        }

        return [$uid, $salonId];
    }

    /** Kapsam (+ lisans) ve yetki (OR-gate); $yetkiler = [] → yalniz kapsam (salonun her uyesi). */
    private function kapiDenetle(int $yetkiliId, int $salonId, array|string $yetkiler): void
    {
        $yetkiler === []
            ? app(GuvenlikKapisi::class)->kapsamDenetle($yetkiliId, $salonId)
            : app(GuvenlikKapisi::class)->denetle($yetkiliId, $salonId, $yetkiler);
    }

    /**
     * Kullanici + salon basina kota (ucretli dis servis, toplu sorgu). $pencereler: [saniye => sinir]; $adet bu istegin
     * tukettigi birim (orn. sorulan numara sayisi). Herhangi bir pencere asilacaksa hicbir sayac artmadan 429; yoksa tum
     * pencereler $adet kadar artar. Kimlik + kapsam + yetki denetiminden SONRA cagrilir (yetkisiz istek kota yemez).
     *
     * @param  array<int,int>  $pencereler
     */
    protected function kotaDenetle(string $ad, int $yetkiliId, int $salonId, array $pencereler, int $adet = 1): void
    {
        foreach ($pencereler as $saniye => $sinir) {
            if (RateLimiter::attempts($this->kotaAnahtari($ad, $yetkiliId, $salonId, $saniye)) + $adet > $sinir) {
                throw new CokFazlaIstekException('İstek sınırı aşıldı. Lütfen daha sonra tekrar deneyin.');
            }
        }
        foreach ($pencereler as $saniye => $sinir) {
            RateLimiter::increment($this->kotaAnahtari($ad, $yetkiliId, $salonId, $saniye), $saniye, $adet);
        }
    }

    private function kotaAnahtari(string $ad, int $yetkiliId, int $salonId, int $saniye): string
    {
        return "v2-kota:{$ad}:{$yetkiliId}:{$salonId}:{$saniye}";
    }

    /** Istekteki zorunlu salon_id (yoksa 422). Kapsam denetimi cagirana aittir. */
    protected function salonId(Request $request): int
    {
        $salonId = $request->integer('salon_id');
        if (! $salonId) {
            throw new GecersizIslemException('salon_id zorunlu');
        }

        return $salonId;
    }

    /**
     * Salona bagli olmayan uclarin (kabuk, profil) baglam salonu: salon_id verildiyse kapsam
     * icinde olmali (degilse 403), verilmediyse kullanicinin ilk salonu.
     */
    protected function baglamSalonu(Request $request): int
    {
        $salonlar = app(KapsamServisi::class)->kullaniciSalonlari($this->kullanici()->id);
        if (! $salonlar) {
            throw new YetkisizErisimException('Erişebileceğiniz bir işletme bulunamadı');
        }
        $salonId = $request->integer('salon_id');
        if ($salonId <= 0) {
            return (int) $salonlar[0];
        }
        if (! in_array($salonId, $salonlar, true)) {
            throw new YetkisizErisimException('Bu işletmeye erişim yetkiniz yok');
        }

        return $salonId;
    }

    /** Bu istegi yapan oturumun token id'si (legacy-passport guard'i koyar). */
    protected function aktifTokenId(): ?string
    {
        return request()->attributes->get('oauth_access_token_id');
    }

    /** Kullanicinin bu salonda yetki anahtari var mi (buton/alan gorunurlugu icin; 403 atmaz). */
    protected function yetkiVar(int $yetkiliId, int $salonId, string $anahtar): bool
    {
        return app(\App\Services\Yetki\YetkiServisi::class)->yetkiliYetkiVar($yetkiliId, $salonId, $anahtar);
    }

    /** Kaydin salonu cozulemediyse 404 (kapsam sizintisi yok); cozulduyse salon_id. */
    protected function bulundu(?int $salonId, string $mesaj = 'Kayıt bulunamadı'): int
    {
        if (! $salonId) {
            throw new BulunamadiException($mesaj);
        }

        return $salonId;
    }

    /** Turkce mesajli girdi dogrulamasi; hata 422 zarfina doner (istemciler ilk alan mesajini gosterir). */
    protected function alanlariDogrula(Request $request, array $kurallar): array
    {
        return Validator::make($request->all(), $kurallar, DogrulamaMetinleri::mesajlar(), DogrulamaMetinleri::alanlar())->validate();
    }

    /** Eski mobil kuraliyla calisan uc: `mobil` bayragi yalniz imzali mobil token'la istege ulasir (MobilBayraklar). */
    protected function mobilOlmali(Request $request): void
    {
        if (! $request->boolean('mobil')) {
            throw new YetkisizErisimException('Bu uç yalnız mobil uygulama içindir');
        }
    }

    /** Yazma kill-switch'i kapaliysa 409. */
    protected function yazmaAcikMi(?string $mesaj = null): void
    {
        if ($this->yazmaAlani === null) {
            throw new \LogicException(static::class.': $yazmaAlani tanimli degil');
        }
        if (! config($this->yazmaAlani.'.yazma_aktif')) {
            throw new CakismaException($mesaj ?? $this->yazmaKapaliMesaj);
        }
    }
}
