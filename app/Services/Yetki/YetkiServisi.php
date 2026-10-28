<?php

namespace App\Services\Yetki;

use App\Models\Personeller;
use App\Models\PersonelYetkiAyari;
use App\Support\Yetki\YetkiSabitleri;
use Illuminate\Support\Facades\DB;

/**
 * Personel yetki karar motoru — eski monolitin App\Services\PersonelYetkiServisi
 * dosyasindan BIREBIR portlanmistir. Karar zinciri ve invariant'lar korunmustur.
 *
 * Eski sistemden FARKLAR (sadik uyarlama):
 *   - tabloyuHazirla() (runtime tablo olusturma) KALDIRILDI: yeni app sema
 *     olusturamaz; personel_yetki_ayarlari zaten var (eski sistem kurdu).
 *   - Impersonation (sysadmin "Hesabina Gir") WEB session'a bagliydi; stateless
 *     API'de oturum yok, bu dal dogal olarak devre disi (yapi korundu).
 *   - static yerine instance (container singleton); request-level cache instance
 *     ozelligi olarak tutulur.
 *
 * Kurallar (birebir):
 *   1. Hesap Sahibi (model_has_roles.role_id=1) HER ZAMAN tam yetki.
 *   2. Ozel yetki kaydi (personel_yetki_ayarlari) varsa rolden BAGIMSIZ uygulanir.
 *   3. Kayit yoksa: Personel rolu (role_id=5) degilse tam yetki; ise 'personel' default.
 *   4. Eksik anahtar daima KAYDIN KENDI sablonundan tamamlanir (web/mobil paritesi).
 *   5. Rol-5 kopuk-bag (yetkili_id bagi yok ama role_id=5) → 'personel' default
 *      (tam yetki DEGIL).
 */
class YetkiServisi
{
    /** Request-level cache'ler (singleton ornekte request boyunca yasar). */
    private array $ayarlarCache = [];
    private array $sablonCache = [];
    private array $personelRoluCache = [];
    private array $yetkiliYetkiVarCache = [];
    private array $personelIdCache = [];
    private array $hesapSahibiCache = [];

    /**
     * Aksiyon/sayfa yetki kontrolu — salon_personelleri.id bazli.
     * (Impersonation bypass GECERLI yolu; stateless API'de etkisiz.)
     */
    public function yetkiVar($personelId, $salonId, string $key): bool
    {
        return $this->yetkiVarCekirdek($personelId, $salonId, $key, true);
    }

    /**
     * Aksiyon/sayfa yetki kontrolu — IsletmeYetkilileri.id (giris yapan) bazli.
     * Tahsilat uclarinda KULLANILACAK ana giris noktasi budur.
     */
    public function yetkiliYetkiVar($yetkiliId, $salonId, string $key): bool
    {
        return $this->yetkiliYetkiVarCekirdek($yetkiliId, $salonId, $key, true);
    }

    /**
     * MENU/UI gorunurlugu — yetkiliYetkiVar ile AYNI, tek fark impersonation
     * bypass UYGULANMAZ (impersonate edilenin GERCEK yetkisi gosterilsin).
     */
    public function menuYetki($yetkiliId, $salonId, string $key): bool
    {
        return $this->yetkiliYetkiVarCekirdek($yetkiliId, $salonId, $key, false);
    }

    // ---------------------------------------------------------------------
    // Cekirdek karar
    // ---------------------------------------------------------------------

    private function yetkiVarCekirdek($personelId, $salonId, string $key, bool $impersonationBypass): bool
    {
        if (! $personelId || ! $salonId) {
            return true; // bilinmiyorsa engelleme (eski davranis birebir)
        }

        if ($impersonationBypass && $this->impersonationAktif()) {
            return true;
        }

        // Hesap Sahibi her zaman tam yetki (ozel kayit onu kilitleyemez).
        if ($this->hesapSahibiMi($personelId, $salonId)) {
            return true;
        }

        $roluKey = $personelId.'|'.$salonId;

        if (! array_key_exists($roluKey, $this->ayarlarCache)) {
            $ayar = PersonelYetkiAyari::where('personel_id', $personelId)
                ->where('salon_id', $salonId)
                ->first();
            $this->ayarlarCache[$roluKey] = ($ayar && is_array($ayar->ayarlar)) ? $ayar->ayarlar : null;
            $this->sablonCache[$roluKey]  = $ayar ? ($ayar->sablon ?: 'ozel') : null;
        }
        $ozelAyar = $this->ayarlarCache[$roluKey];

        if ($ozelAyar === null) {
            // Ozel kayit yok → rol bazli. Personel rolu (5) degilse tam yetki.
            if (! array_key_exists($roluKey, $this->personelRoluCache)) {
                $this->personelRoluCache[$roluKey] = $this->personelRolundeMi($personelId, $salonId);
            }
            if (! $this->personelRoluCache[$roluKey]) {
                return true;
            }
            $ayarlar = YetkiSabitleri::sablonAyarlari('personel');
            $fallbackSablon = 'personel';
        } else {
            // Ozel yetki kaydi var → rolden bagimsiz bu kaydi uygula.
            $ayarlar = $ozelAyar;
            $fallbackSablon = $this->sablonCache[$roluKey] ?? 'personel';
        }

        // Anahtar kayitta yoksa kaydin KENDI sablonunun default'undan tamamla.
        if (! array_key_exists($key, $ayarlar)) {
            $sablonDefault = YetkiSabitleri::sablonAyarlari($fallbackSablon);

            return (bool) ($sablonDefault[$key] ?? false);
        }

        return (bool) $ayarlar[$key];
    }

    private function yetkiliYetkiVarCekirdek($yetkiliId, $salonId, string $key, bool $impersonationBypass): bool
    {
        if (! $yetkiliId || ! $salonId) {
            return true;
        }

        if ($impersonationBypass && $this->impersonationAktif()) {
            return true;
        }

        $cacheKey = $yetkiliId.'|'.$salonId.'|'.$key.'|'.($impersonationBypass ? '1' : '0');
        if (array_key_exists($cacheKey, $this->yetkiliYetkiVarCache)) {
            return $this->yetkiliYetkiVarCache[$cacheKey];
        }

        // Yetkili'nin bu salondaki personel_id'si (bag Personeller.yetkili_id'de).
        $personelCacheKey = $yetkiliId.'|'.$salonId;
        if (array_key_exists($personelCacheKey, $this->personelIdCache)) {
            $personelId = $this->personelIdCache[$personelCacheKey];
        } else {
            $personelId = Personeller::where('yetkili_id', $yetkiliId)
                ->where('salon_id', $salonId)
                ->value('id');
            $this->personelIdCache[$personelCacheKey] = $personelId;
        }

        if (! $personelId) {
            // Bag kopuk. role_id=5 ise sahip DEGIL → 'personel' default (tam yetki DEGIL).
            $personelRolunde = DB::table('model_has_roles')
                ->where('model_id', $yetkiliId)
                ->where('salon_id', $salonId)
                ->where('role_id', 5)
                ->exists();

            if ($personelRolunde) {
                $sade = YetkiSabitleri::sablonAyarlari('personel');

                return $this->yetkiliYetkiVarCache[$cacheKey] = (bool) ($sade[$key] ?? false);
            }

            // Gercekten personel rolu degil (sahip / direkt yetkili) → tam yetki.
            return $this->yetkiliYetkiVarCache[$cacheKey] = true;
        }

        return $this->yetkiliYetkiVarCache[$cacheKey] =
            $this->yetkiVarCekirdek($personelId, $salonId, $key, $impersonationBypass);
    }

    // ---------------------------------------------------------------------
    // Yardimcilar
    // ---------------------------------------------------------------------

    /** Hesap Sahibi mi? (model_has_roles.role_id=1) */
    public function hesapSahibiMi($personelId, $salonId): bool
    {
        $k = $personelId.'|'.$salonId;
        if (array_key_exists($k, $this->hesapSahibiCache)) {
            return $this->hesapSahibiCache[$k];
        }

        $yetkiliId = Personeller::where('id', $personelId)
            ->where('salon_id', $salonId)
            ->value('yetkili_id');
        if (! $yetkiliId) {
            return $this->hesapSahibiCache[$k] = false;
        }

        return $this->hesapSahibiCache[$k] = DB::table('model_has_roles')
            ->where('role_id', 1) // Hesap Sahibi
            ->where('model_id', $yetkiliId)
            ->where('salon_id', $salonId)
            ->exists();
    }

    /** Personel rolunde mi? (model_has_roles.role_id=5) */
    public function personelRolundeMi($personelId, $salonId): bool
    {
        $yetkiliId = Personeller::where('id', $personelId)
            ->where('salon_id', $salonId)
            ->value('yetkili_id');
        if (! $yetkiliId) {
            return false;
        }

        return DB::table('model_has_roles')
            ->where('role_id', 5) // Personel rolu
            ->where('model_id', $yetkiliId)
            ->where('salon_id', $salonId)
            ->exists();
    }

    /**
     * Personelin tum yetki ayarlarini dondur (yoksa default). benimYetkilerim /
     * UI icin. Secili sablondan eksik anahtarlar tamamlanir (sade'den DEGIL).
     */
    public function ayarlariGetir($personelId, $salonId): array
    {
        $ayar = PersonelYetkiAyari::where('personel_id', $personelId)
            ->where('salon_id', $salonId)
            ->first();

        if ($ayar) {
            $kayitliAyarlar = is_array($ayar->ayarlar) ? $ayar->ayarlar : [];
            $sablon = $ayar->sablon ?: 'ozel';
            $eskidenYeniye = [
                'personel_sade' => 'personel',
                'personel_tam'  => 'yonetici',
                'demo'          => 'personel',
            ];
            if (isset($eskidenYeniye[$sablon])) {
                $sablon = $eskidenYeniye[$sablon];
            }
            $sablonDefault = YetkiSabitleri::sablonAyarlari($sablon);

            return [
                'sablon'  => $sablon,
                'ayarlar' => array_merge($sablonDefault, $kayitliAyarlar),
            ];
        }

        return [
            'sablon'  => 'personel',
            'ayarlar' => YetkiSabitleri::sablonAyarlari('personel'),
        ];
    }

    /**
     * Sysadmin impersonation aktif mi? Eski sistemde web session anahtari
     * ('sysadmin_impersonation_id'). Stateless API'de oturum olmadigi icin
     * normalde false; web paneli de bu app'e tasinirsa calismaya devam eder.
     */
    private function impersonationAktif(): bool
    {
        try {
            return app()->bound('session')
                && session()->isStarted()
                && session()->has('sysadmin_impersonation_id');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
