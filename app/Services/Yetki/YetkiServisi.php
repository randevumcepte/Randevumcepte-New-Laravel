<?php

namespace App\Services\Yetki;

use App\Services\Oturum\ErisimTokenServisi;
use App\Services\Personel\HesapSahibiDenetimi;
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
 *   3. Kayit yoksa: rol varsayilani (YetkiSabitleri::rolVarsayilanAyarlari) — rol 5 'personel'
 *      default; rol 4 tam yetki, yalniz Kasa/Satis/Isletme raporlari kapali; digerleri tam yetki.
 *   4. Eksik anahtar daima KAYDIN KENDI sablonundan tamamlanir (web/mobil paritesi).
 *   5. Kopuk-bag (bu salonda personel kaydi yok) da ayni rol varsayilanini alir
 *      (rol 5 tam yetki ALMAZ).
 *   6. "Tam yetki" kisit anahtarlarini (kapali_adisyon_gizle) ACMAZ.
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

    /** Hesap sahibi TEK tanimi (personel rol 1 VEYA model_has_roles rol 1) — korumalar ve onay kapisiyla ortak. */
    private HesapSahibiDenetimi $sahip;

    public function __construct(?HesapSahibiDenetimi $sahip = null)
    {
        $this->sahip = $sahip ?? new HesapSahibiDenetimi();
    }

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
            return $this->tamYetkiDegeri($key); // bilinmiyorsa engelleme (eski davranis birebir)
        }

        if ($impersonationBypass && $this->impersonationAktif()) {
            return $this->tamYetkiDegeri($key);
        }

        // Hesap Sahibi her zaman tam yetki (ozel kayit onu kilitleyemez).
        if ($this->hesapSahibiMi($personelId, $salonId)) {
            return $this->tamYetkiDegeri($key);
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
            // Ozel kayit yok → rol varsayilani.
            return $this->rolVarsayilani($this->personelRolleri($personelId, $salonId), $key);
        }

        // Ozel yetki kaydi var → rolden bagimsiz bu kaydi uygula.
        $ayarlar = $ozelAyar;
        $fallbackSablon = $this->sablonCache[$roluKey] ?? 'personel';

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
            return $this->tamYetkiDegeri($key);
        }

        if ($impersonationBypass && $this->impersonationAktif()) {
            return $this->tamYetkiDegeri($key);
        }

        $cacheKey = $yetkiliId.'|'.$salonId.'|'.$key.'|'.($impersonationBypass ? '1' : '0');
        if (array_key_exists($cacheKey, $this->yetkiliYetkiVarCache)) {
            return $this->yetkiliYetkiVarCache[$cacheKey];
        }

        // Yetkili'nin bu salondaki AKTIF personel_id'si (bag Personeller.yetkili_id'de).
        $personelCacheKey = $yetkiliId.'|'.$salonId;
        if (array_key_exists($personelCacheKey, $this->personelIdCache)) {
            $personelId = $this->personelIdCache[$personelCacheKey];
        } else {
            $personelId = $this->aktifPersonelId($yetkiliId, $salonId);
            $this->personelIdCache[$personelCacheKey] = $personelId;
        }

        if (! $personelId) {
            // Bag kopuk → rol varsayilani (rol 5 tam yetki ALMAZ).
            return $this->yetkiliYetkiVarCache[$cacheKey] = $this->rolVarsayilani($this->yetkiliRolleri($yetkiliId, $salonId), $key);
        }

        return $this->yetkiliYetkiVarCache[$cacheKey] =
            $this->yetkiVarCekirdek($personelId, $salonId, $key, $impersonationBypass);
    }

    // ---------------------------------------------------------------------
    // Yardimcilar
    // ---------------------------------------------------------------------

    /** Tam yetkili hesapta anahtarin degeri (kisit anahtarlari kapali). */
    private function tamYetkiDegeri(string $key): bool
    {
        return ! in_array($key, YetkiSabitleri::kisitAnahtarlari(), true);
    }

    /** Ozel kaydi olmayan hesapta anahtarin degeri; tanimsiz anahtar yalniz rol 5'e kapali. */
    private function rolVarsayilani(array $roller, string $key): bool
    {
        $ayarlar = YetkiSabitleri::rolVarsayilanAyarlari($roller);

        return (bool) ($ayarlar[$key] ?? ! in_array(YetkiSabitleri::ROL_PERSONEL, $roller, true));
    }

    /**
     * Yetkilinin bu salondaki personel kaydi — YALNIZ aktif=1 (KapsamServisi ile ayni kural). Pasif satir
     * yetki kaynagi olmaz; kapsam zaten disarida birakir, hesap sahibi rol kurali (model_has_roles) korunur.
     */
    private function aktifPersonelId($yetkiliId, $salonId): ?int
    {
        $id = Personeller::where('yetkili_id', $yetkiliId)->where('salon_id', $salonId)->where('aktif', 1)->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * @return int[] kullanicinin bu salondaki rolleri (model_has_roles); hesap sahibi (HesapSahibiDenetimi: personel
     * rol 1 de sayilir) rol 1'siz kayitliysa rol 1 eklenir — rol varsayilani ve benimYetkilerim sahibi ayni tanimla tanir.
     */
    private function yetkiliRolleri($yetkiliId, $salonId): array
    {
        $roller = DB::table('model_has_roles')
            ->where('model_id', $yetkiliId)
            ->where('salon_id', $salonId)
            ->pluck('role_id')
            ->map(fn ($r) => (int) $r)
            ->all();

        return self::sahipRolEkle($roller, ! in_array(YetkiSabitleri::ROL_HESAP_SAHIBI, $roller, true)
            && $this->sahip->yetkiliSahipMi((int) $yetkiliId, (int) $salonId));
    }

    /**
     * Saf: hesap sahibiyse rol listesine rol 1 eklenir (yoksa).
     *
     * @param  int[]  $roller
     * @return int[]
     */
    public static function sahipRolEkle(array $roller, bool $sahip): array
    {
        return $sahip && ! in_array(YetkiSabitleri::ROL_HESAP_SAHIBI, $roller, true)
            ? [...$roller, YetkiSabitleri::ROL_HESAP_SAHIBI] : $roller;
    }

    /** @return int[] personelin bagli oldugu yetkilinin rolleri (bag yoksa bos) */
    private function personelRolleri($personelId, $salonId): array
    {
        $k = $personelId.'|'.$salonId;
        if (array_key_exists($k, $this->personelRoluCache)) {
            return $this->personelRoluCache[$k];
        }
        $yetkiliId = Personeller::where('id', $personelId)
            ->where('salon_id', $salonId)
            ->value('yetkili_id');

        return $this->personelRoluCache[$k] = $yetkiliId ? $this->yetkiliRolleri($yetkiliId, $salonId) : [];
    }

    /**
     * Hesap Sahibi mi? Personel satiri rol 1 YA DA yetkilisinin bu salondaki model_has_roles rol 1'i —
     * HesapSahibiDenetimi::satirSahipMi (korumalar, onay kapisi ve motor ayni iki kaynakli tanim).
     */
    public function hesapSahibiMi($personelId, $salonId): bool
    {
        $k = $personelId.'|'.$salonId;
        if (array_key_exists($k, $this->hesapSahibiCache)) {
            return $this->hesapSahibiCache[$k];
        }

        $p = Personeller::where('id', $personelId)
            ->where('salon_id', $salonId)
            ->first(['role_id', 'yetkili_id']);
        if (! $p) {
            return $this->hesapSahibiCache[$k] = false;
        }

        return $this->hesapSahibiCache[$k] = $this->sahip->satirSahipMi(
            $p->role_id !== null ? (int) $p->role_id : null, $p->yetkili_id ? (int) $p->yetkili_id : null, (int) $salonId);
    }

    /**
     * Hesap sahibi ISLEM kurali (eski adisyonFaturaIsaretle / faturasizGizleToggle):
     * yetkilinin bu salondaki personel kaydinin role_id'si 1.
     */
    public function yetkiliHesapSahibiIslem($yetkiliId, $salonId): bool
    {
        return (int) Personeller::where('salon_id', $salonId)
            ->where('yetkili_id', $yetkiliId)->value('role_id') === 1;
    }

    /**
     * Hesap sahibi GORUNURLUK kurali (eski satis listesi fatura butonu):
     * personel role_id 1 VEYA model_has_roles role_id 1.
     */
    public function yetkiliHesapSahibiGorunur($yetkiliId, $salonId): bool
    {
        return $this->sahip->yetkiliSahipMi($yetkiliId ? (int) $yetkiliId : null, (int) $salonId);
    }

    /** Personel rolunde mi? (model_has_roles.role_id=5) */
    public function personelRolundeMi($personelId, $salonId): bool
    {
        return in_array(YetkiSabitleri::ROL_PERSONEL, $this->personelRolleri($personelId, $salonId), true);
    }

    /**
     * Personelin tum yetki ayarlarini dondur (kayit yoksa rol varsayilani). benimYetkilerim /
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

        $roller = $this->personelRolleri($personelId, $salonId);

        return [
            'sablon'  => YetkiSabitleri::rolVarsayilanSablonu($roller),
            'ayarlar' => YetkiSabitleri::rolVarsayilanAyarlari($roller),
        ];
    }

    /**
     * Giris yapanin EFEKTIF yetkileri — yetkiliYetkiVar ile AYNI karar zinciri (ekran ve uc
     * farkli sonuc vermesin): hesap sahibi tam; ozel kayit uygulanir; yoksa rol varsayilani.
     *
     * @return array{roller:int[], personel_id:?int, sablon:string, ayarlar:array<string,bool>}
     */
    public function efektifYetkiler($yetkiliId, $salonId): array
    {
        $roller = $this->yetkiliRolleri($yetkiliId, $salonId);
        $personelId = $this->aktifPersonelId($yetkiliId, $salonId);

        if (! in_array(YetkiSabitleri::ROL_HESAP_SAHIBI, $roller, true) && $personelId) {
            return ['roller' => $roller, 'personel_id' => $personelId] + $this->ayarlariGetir($personelId, $salonId);
        }

        return [
            'roller'      => $roller,
            'personel_id' => $personelId,
            'sablon'      => YetkiSabitleri::rolVarsayilanSablonu($roller),
            'ayarlar'     => YetkiSabitleri::rolVarsayilanAyarlari($roller),
        ];
    }

    /**
     * Personel yetki ayarlarini kaydet — eski PersonelYetkiServisi::ayarlariKaydet'ten
     * BIREBIR port. Bilinmeyen sablon → 'ozel'; sadece tanimli anahtarlar kabul edilir
     * (whitelist, bool cast); updateOrCreate((personel_id,salon_id)).
     * Kayit sonrasi request-level cache'ler bosaltilir (ayni istek icinde eskimesin).
     */
    public function ayarlariKaydet($personelId, $salonId, string $sablon, array $ayarlar): PersonelYetkiAyari
    {
        $bilinen = array_keys(YetkiSabitleri::sablonlar());
        if (! in_array($sablon, $bilinen, true) && $sablon !== 'ozel') {
            $sablon = 'ozel';
        }

        $izinli = YetkiSabitleri::tumAnahtarlar();
        $temizAyarlar = [];
        foreach ($izinli as $key) {
            if (array_key_exists($key, $ayarlar)) {
                $temizAyarlar[$key] = (bool) $ayarlar[$key];
            }
        }

        $kayit = PersonelYetkiAyari::updateOrCreate(
            ['personel_id' => $personelId, 'salon_id' => $salonId],
            ['sablon' => $sablon, 'ayarlar' => $temizAyarlar]
        );

        // Request-level cache invalidasyonu (bu personel/salon icin).
        $roluKey = $personelId.'|'.$salonId;
        unset($this->ayarlarCache[$roluKey], $this->sablonCache[$roluKey], $this->personelRoluCache[$roluKey]);
        $this->yetkiliYetkiVarCache = [];

        return $kayit;
    }

    /**
     * Sysadmin impersonation aktif mi? Bu oturum "Salon hesabina gir" ile acildiysa token id'si
     * imp_ onekli (ErisimTokenServisi). Eski panelin session anahtarinin karsiligi.
     */
    private function impersonationAktif(): bool
    {
        return ErisimTokenServisi::impersonationTokeniMi(request()->attributes->get('oauth_access_token_id'));
    }
}
