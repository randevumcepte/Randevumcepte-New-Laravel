<?php

namespace Tests\Feature\Yetki;

use App\Domain\Oturum\TokenTipi;
use App\Services\BildirimKuyrugu\Kanal\FcmIstemcisi;
use App\Services\Oturum\IstekTokeni;
use App\Services\Seslendirme\SeslendirmeServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\Yetki\YetkiSabitleri;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpIstegi;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as Rota;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * UC YETKI TARAMASI — tum /api/v2 rotalari, bu salonda HICBIR yetkisi olmayan personel token'iyla (rol 5, tum anahtarlari
 * kapali ozel sablon) birer kez cagrilir. Beklenen: veriye ulasmadan ret (401/403/404/422...).
 *
 * Nasil yakalar: izole sqlite'ta YALNIZ kimlik + kapsam + yetki + lisans tablolari kurulur. Yetkiyi denetlemeden is
 * tablosuna giden uc "no such table" ile 500 verir (500-denetimden-once-veri); hic denetlemeyen uc 200 doner
 * (200-denetimsiz). Reddettigi halde once veritabanina yazan ya da dis servise istek atan uc yan-etki-sonra-reddetti olur.
 *
 * Iki kip: 'web' (isletme token'i) ve 'mobil' (imzali mobil token + config('oturum.mobil_bayraklari') alanlarinin hepsi
 * 1). Mobil bayraklar bazi uclarda yetki anahtarini gevsetir; mobil kipte de ret beklenir.
 *
 * Izin listesi: UcYetkiIzinListesi (kimliksiz, musteri tokeni, kimlik yeter). Rapor: storage/logs/uc-yetki-raporu.json.
 * Gercek veritabanina baglanmaz; dis HTTP sahte (Http::fake), FCM ve seslendirme Mockery ile kapali.
 */
class UcYetkiTaramasiTest extends TestCase
{
    /** Test salonu (salon 20 test salonu). */
    private const SALON = 20;

    private const YETKILI = 7001;

    private const PERSONEL = 70010;

    private const ROL_PERSONEL = 5;

    /** Rota parametrelerine verilen, var olmayan kayit id'si. */
    private const YOK_ID = '999999';

    /** Bearer token metni → [kapsam, subject]. Sahte IstekTokeni bunu okur; gercek guard zinciri (TokenTipi) calisir. */
    private const TOKEN_WEB = 'tarama-isletme-web';

    private const TOKEN_MOBIL = 'tarama-isletme-mobil';

    public const RAPOR = 'logs/uc-yetki-raporu.json';

    public const SINIF_RET = 'reddetti';

    public const SINIF_200 = '200-denetimsiz';

    public const SINIF_500 = '500-denetimden-once-veri';

    public const SINIF_YAN_ETKI = 'yan-etki-sonra-reddetti';

    public const SINIF_IZINLI = 'izinli-acik';

    /** Kimlik/kapsam/yetki/lisans zinciri icin kurulan tablolar (bunlarin disindaki her tablo "is tablosu"dur). */
    private const KURULAN_TABLOLAR = ['isletmeyetkilileri', 'salonlar', 'salon_personelleri', 'model_has_roles', 'personel_yetki_ayarlari'];

    /** Tek tarama: iki test ayni sonucu kullanir (uygulama her testte yeniden kurulur, sonuc dizidir). */
    private static ?array $tarama = null;

    /** @var array<int,string> bu istekte calisan SQL'ler */
    private array $sorgular = [];

    /** @var array<int,string> bu istekte giden dis HTTP istekleri */
    private array $disIstekler = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'uc_tarama_sqlite',
            'database.connections.uc_tarama_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            // 500 cevabinda hata metni (eksik tablo) okunabilsin; yuzlerce iz log dosyasina yazilmasin.
            'app.debug' => true,
            'logging.default' => 'null',
            // Genel istek siniri yuzlerce istekte 429'a dusmesin (throttle ayrica devre disi).
            'oturum.genel_istek_dakika' => 1000000,
            'oturum.tipsiz_token_kabul' => false,
            // Lisans kapisi acik; salonun lisansi asagida gecerli.
            'lisans.aktif' => true,
            // Randevu modulu host kapisi taramayi etkilemesin.
            'panel.randevu_modulu.hostlar' => [],
        ]);
        DB::purge('uc_tarama_sqlite');
        DB::setDefaultConnection('uc_tarama_sqlite');

        $this->yazmaBayraklariniAc();
        $this->semaKur();
        $this->veriKur();
        $this->sahteleriKur();

        // Istek siniri yetki degildir: 429 yetkiyi gizlemesin.
        $this->withoutMiddleware(ThrottleRequests::class);

        DB::listen(function (QueryExecuted $q) {
            $this->sorgular[] = $q->sql;
        });
    }

    /**
     * Tum *_YAZMA_AKTIF bayraklari ACIK: kapaliyken yazma uclari 409 doner; 409 yetkiden once gelirse yetki eksigini gizler.
     */
    private function yazmaBayraklariniAc(): void
    {
        foreach (config()->all() as $dosya => $deger) {
            if (is_array($deger)) {
                $this->bayrakAc($dosya, $deger);
            }
        }
    }

    private function bayrakAc(string $yol, array $dizi): void
    {
        foreach ($dizi as $anahtar => $deger) {
            if ($anahtar === 'yazma_aktif') {
                config([$yol.'.yazma_aktif' => true]);
            } elseif (is_array($deger) && is_string($anahtar)) {
                $this->bayrakAc($yol.'.'.$anahtar, $deger);
            }
        }
    }

    private function semaKur(): void
    {
        Schema::create('isletmeyetkilileri', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'email', 'gsm1', 'gsm2', 'password', 'profil_resim', 'remember_token'] as $k) {
                $t->string($k)->nullable();
            }
            $t->integer('aktif')->default(1);
            $t->timestamps();
        });
        Schema::create('salonlar', function (Blueprint $t) {
            $t->id();
            foreach (['salon_adi', 'yetkili_adi', 'app_bundle', 'domain', 'telefon_1', 'whatsapp_numara', 'firebase_profile', 'tema_config',
                'randevu_takvim_turu', 'sozlesme_varsayilan_metin'] as $k) {
                $t->string($k)->nullable();
            }
            // Paket/modul kapilari acik: kapilar yetkiden once 403 verip yetkiyi gizlemesin.
            foreach (['uyelik_turu' => 3, 'santral_aktif' => 1, 'grup_dersi_aktif' => 1, 'studyo_modu' => 0, 'salon_turu_id' => 1,
                'whatsapp_kontor' => 0, 'faturasiz_gizle' => 0, 'cakisma_uyarisi_aktif' => 0, 'randevu_araligi' => 15, 'kara_liste_aktif' => 0,
                'gecmis_randevulari_gizle' => 0, 'e_asistan_hatirlatma' => 0, 'musteri_online_randevu_aktif' => 1,
                'santral_telaffuz_hatirlatma_aramasi' => 0] as $k => $v) {
                $t->integer($k)->default($v);
            }
            $t->date('uyelik_bitis_tarihi')->nullable();
            $t->timestamps();
        });
        Schema::create('salon_personelleri', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->integer('yetkili_id')->nullable();
            $t->integer('role_id')->nullable();
            $t->integer('aktif')->default(1);
            foreach (['personel_adi', 'cep_telefon', 'dahili_no', 'dahili_no_webrtc', 'renk', 'unvan'] as $k) {
                $t->string($k)->nullable();
            }
            $t->integer('sira')->nullable();
            $t->timestamps();
        });
        Schema::create('model_has_roles', function (Blueprint $t) {
            $t->integer('role_id');
            $t->string('model_type')->nullable();
            $t->integer('model_id');
            $t->integer('salon_id')->nullable();
        });
        Schema::create('personel_yetki_ayarlari', function (Blueprint $t) {
            $t->id();
            $t->integer('personel_id');
            $t->integer('salon_id');
            $t->string('sablon')->nullable();
            $t->text('ayarlar')->nullable();
            $t->timestamps();
        });
    }

    private function veriKur(): void
    {
        DB::table('salonlar')->insert(['id' => self::SALON, 'salon_adi' => 'Tarama Salonu', 'uyelik_bitis_tarihi' => '2099-12-31']);
        DB::table('isletmeyetkilileri')->insert(['id' => self::YETKILI, 'name' => 'Yetkisiz Personel', 'aktif' => 1]);
        DB::table('salon_personelleri')->insert(['id' => self::PERSONEL, 'salon_id' => self::SALON, 'yetkili_id' => self::YETKILI,
            'role_id' => self::ROL_PERSONEL, 'aktif' => 1, 'personel_adi' => 'Yetkisiz Personel']);
        DB::table('model_has_roles')->insert(['role_id' => self::ROL_PERSONEL, 'model_type' => 'isletme', 'model_id' => self::YETKILI, 'salon_id' => self::SALON]);

        // Bos yetki sablonu: her anahtar kapali; kisit anahtarlari (acikken kisitlayan) acik — en dar hesap.
        $kisit = YetkiSabitleri::kisitAnahtarlari();
        $ayarlar = [];
        foreach (YetkiSabitleri::tumAnahtarlar() as $anahtar) {
            $ayarlar[$anahtar] = in_array($anahtar, $kisit, true);
        }
        DB::table('personel_yetki_ayarlari')->insert(['personel_id' => self::PERSONEL, 'salon_id' => self::SALON, 'sablon' => 'ozel',
            'ayarlar' => json_encode($ayarlar)]);
    }

    private function sahteleriKur(): void
    {
        // Token: imza dogrulamasi sahte, gerisi gercek guard (TokenTipi, MobilBayraklar, KapsamServisi::tamamenPasifMi).
        $this->app->instance(IstekTokeni::class, new class extends IstekTokeni
        {
            public function dogrula(Request $istek): ServerRequestInterface
            {
                $kapsam = match ($istek->bearerToken()) {
                    UcYetkiTaramasiTest::tokenMetni('web') => TokenTipi::kapsam(TokenTipi::ISLETME, false),
                    UcYetkiTaramasiTest::tokenMetni('mobil') => TokenTipi::kapsam(TokenTipi::ISLETME, true),
                    default => throw new RuntimeException('gecersiz token'),
                };

                return (new ServerRequest('GET', '/'))->withAttribute('oauth_scopes', $kapsam)
                    ->withAttribute('oauth_user_id', (string) UcYetkiTaramasiTest::yetkiliId())
                    ->withAttribute('oauth_access_token_id', 'tarama-'.$istek->bearerToken());
            }
        });

        // Dis servisler: gercek istek gitmez. Http facade'ini kullananlar (SMS, WhatsApp, santral, Google...) kaydedilir.
        Http::preventStrayRequests();
        Http::fake(function (HttpIstegi $istek) {
            $this->disIstekler[] = $istek->method().' '.$istek->url();

            return Http::response(['basarili' => false, 'mesaj' => 'uc-yetki-taramasi sahte cevap'], 503);
        });
        // Http facade'ini kullanmayanlar (Guzzle / curl): beklentisiz mock, herhangi bir cagri hata firlatir (raporda gorunur).
        $this->app->instance(FcmIstemcisi::class, Mockery::mock(FcmIstemcisi::class));
        $this->app->instance(SeslendirmeServisi::class, Mockery::mock(SeslendirmeServisi::class));

        Storage::fake('local');
        Storage::fake('public');
    }

    public static function tokenMetni(string $kip): string
    {
        return $kip === 'mobil' ? self::TOKEN_MOBIL : self::TOKEN_WEB;
    }

    public static function yetkiliId(): int
    {
        return self::YETKILI;
    }

    // ------------------------------------------------------------------
    // Testler
    // ------------------------------------------------------------------

    public function test_yetkisiz_personel_hicbir_uca_veriye_ulasmadan_giremez(): void
    {
        $tarama = $this->tarama();

        $this->assertNotEmpty($tarama['sonuclar'], 'api/v2 rotasi bulunamadi');
        $this->assertCount(0, $tarama['eslesmeyen'], "Taramanin urettigi adres baska rotaya gitti (parametre degeri uymuyor):\n"
            .implode("\n", $tarama['eslesmeyen']));

        $kirmizi = array_filter($tarama['sonuclar'], fn (array $s) => $s['kirmizi']);
        $this->assertCount(0, $kirmizi, $this->kirmiziMesaji($kirmizi));
    }

    public function test_izin_listesinde_eskimis_ya_da_gereksiz_girdi_yok(): void
    {
        $tarama = $this->tarama();

        $this->assertCount(0, $tarama['izin_listesi_sorunlari'], "Izin listesi (UcYetkiIzinListesi) duzeltilmeli:\n"
            .implode("\n", $tarama['izin_listesi_sorunlari'])."\nRapor: storage/".self::RAPOR);
    }

    // ------------------------------------------------------------------
    // Tarama
    // ------------------------------------------------------------------

    private function tarama(): array
    {
        if (self::$tarama !== null) {
            return self::$tarama;
        }

        $sonuclar = [];
        $eslesmeyen = [];
        foreach ($this->v2Rotalari() as $rota) {
            foreach ($this->yontemler($rota) as $yontem) {
                $izin = $this->izinBul($yontem, $rota->uri());
                if ($izin !== null && $izin['liste'] === 'KIMLIKSIZ') {
                    $sonuclar[] = $this->satir('-', $yontem, $rota, null, null, self::SINIF_IZINLI, false, [
                        'izin' => 'KIMLIKSIZ', 'gerekce' => $izin['gerekce'], 'not' => 'cagrilmadi (kimliksiz uc)',
                    ]);

                    continue;
                }
                foreach (['web', 'mobil'] as $kip) {
                    [$satir, $eslesme] = $this->cagir($kip, $yontem, $rota, $izin);
                    $sonuclar[] = $satir;
                    if ($eslesme !== null) {
                        $eslesmeyen[] = $eslesme;
                    }
                }
            }
        }

        $tarama = [
            'sonuclar' => $sonuclar,
            'eslesmeyen' => array_values(array_unique($eslesmeyen)),
            'izin_listesi_sorunlari' => $this->izinListesiSorunlari($sonuclar),
        ];
        $this->raporYaz($tarama);

        return self::$tarama = $tarama;
    }

    /** @return array<int, Rota> */
    private function v2Rotalari(): array
    {
        $rotalar = array_filter(Route::getRoutes()->getRoutes(), fn (Rota $r) => Str::startsWith($r->uri(), 'api/v2/'));
        usort($rotalar, fn (Rota $a, Rota $b) => strcmp($a->uri(), $b->uri()));

        return $rotalar;
    }

    /** @return array<int,string> HEAD disindaki yontemler (her biri ayri uc) */
    private function yontemler(Rota $rota): array
    {
        return array_values(array_diff($rota->methods(), ['HEAD']));
    }

    /**
     * @param  array{liste:string,desen:string,gerekce:string}|null  $izin
     * @return array{0:array,1:?string} [rapor satiri, eslesmeme mesaji]
     */
    private function cagir(string $kip, string $yontem, Rota $rota, ?array $izin): array
    {
        $adres = $this->adres($rota, $kip);
        $eslesme = $this->eslesmeDenetle($yontem, $adres, $rota);

        $this->istegeHazirla();
        $govde = in_array($yontem, ['GET', 'DELETE'], true) ? [] : $this->salonAlanlari($kip);
        try {
            $cevap = $this->json($yontem, $adres, $govde, ['Authorization' => 'Bearer '.self::tokenMetni($kip)]);
            $durum = $cevap->getStatusCode();
            // JSON olmayan cevap (PDF, CSV, akis) da olabilir: TestResponse::json() yerine elle cozulur.
            $icerik = $cevap->baseResponse->getContent();
            $veri = is_string($icerik) ? json_decode($icerik, true) : null;
            $veri = is_array($veri) ? $veri : [];
            $hata = $durum >= 500 ? (string) ($veri['message'] ?? $veri['mesaj'] ?? Str::limit((string) $icerik, 300)) : null;
            $mesaj = $durum < 500 ? ($veri['mesaj'] ?? null) : null;
        } catch (Throwable $e) {
            // Istisna isleyiciye ulasmadan duserse de 500 sayilir.
            $durum = 500;
            $hata = get_class($e).': '.$e->getMessage();
            $mesaj = null;
        }

        $yazmalar = array_values(array_unique(array_filter(array_map(fn ($s) => $this->yazmaTablosu($s), $this->sorgular))));
        $tablolar = array_values(array_unique(array_merge(...array_map(fn ($s) => $this->tablolar($s), $this->sorgular ?: ['']))));
        $isTablolari = array_values(array_diff($tablolar, self::KURULAN_TABLOLAR));
        $ek = array_filter([
            'mesaj' => is_string($mesaj) ? $mesaj : null,
            'hata' => $hata !== null ? Str::limit($hata, 400) : null,
            'alt_sinif' => $durum >= 500 ? $this->altSinif((string) $hata) : null,
            'tablolar' => $tablolar ?: null,
            'is_tablolari' => $isTablolari ?: null,
            'yazilan_tablolar' => $yazmalar ?: null,
            'dis_istekler' => $this->disIstekler ?: null,
            'izin' => $izin['liste'] ?? null,
            'gerekce' => $izin['gerekce'] ?? null,
        ], fn ($v) => $v !== null);

        [$sinif, $kirmizi, $beklenen] = $this->siniflandir($durum, $rota, $izin, $yazmalar !== [] || $this->disIstekler !== []);
        if ($beklenen !== null) {
            $ek['beklenen'] = $beklenen;
        }

        return [$this->satir($kip, $yontem, $rota, $adres, $durum, $sinif, $kirmizi, $ek), $eslesme];
    }

    /**
     * @return array{0:string,1:bool,2:?string} [sinif, kirmizi mi, beklenen aciklamasi]
     */
    private function siniflandir(int $durum, Rota $rota, ?array $izin, bool $yanEtki): array
    {
        $sinif = match (true) {
            $durum >= 500 => self::SINIF_500,
            $durum < 400 => self::SINIF_200,
            $yanEtki => self::SINIF_YAN_ETKI,
            default => self::SINIF_RET,
        };

        // Isletme guard'i disindaki rotalar (musteri, sistem yonetimi): personel token'i guard'da 401 almali (TokenTipi).
        if (! $this->isletmeRotasi($rota)) {
            return [$sinif, $durum !== 401, 'guard 401 (isletme token\'i bu guard\'a giremez)'];
        }
        if (($izin['liste'] ?? null) === 'KIMLIK_YETER') {
            return [self::SINIF_IZINLI, false, null];
        }

        return [$sinif, $sinif !== self::SINIF_RET, null];
    }

    /** Rotanin auth middleware'i isletme guard'ini iceriyor mu? (auth:isletmeyonetim-api ya da guard listesinde) */
    private function isletmeRotasi(Rota $rota): bool
    {
        foreach ($rota->gatherMiddleware() as $m) {
            if (is_string($m) && str_starts_with($m, 'auth:') && in_array('isletmeyonetim-api', explode(',', substr($m, 5)), true)) {
                return true;
            }
        }

        return false;
    }

    private function altSinif(string $hata): string
    {
        return match (true) {
            str_contains($hata, 'no such table') && str_contains($hata, self::YOK_ID) => 'id-ile-kayit-okuma (kayittan salon cozumu olabilir)',
            str_contains($hata, 'no such table') => 'is-tablosu-okuma',
            str_contains($hata, 'no such column') => 'eksik-kolon (test semasi kurulan tabloda; KURULAN_TABLOLAR semasina eklenebilir)',
            str_contains($hata, 'Mockery') => 'dis-servis-cagrisi (FCM / seslendirme)',
            default => 'diger',
        };
    }

    /**
     * Her istek taze baslar: kimlik/yetki satirlari (onceki bir uc yazdiysa sonrakileri bozmasin), guard onbellegi, istek
     * omurlu yetki onbellegi, sorgu ve dis istek kayitlari.
     */
    private function istegeHazirla(): void
    {
        foreach (self::KURULAN_TABLOLAR as $tablo) {
            DB::table($tablo)->delete();
        }
        $this->veriKur();
        Auth::forgetGuards();
        $this->app->forgetInstance(YetkiServisi::class);
        $this->app->forgetInstance(GuvenlikKapisi::class);
        $this->app->forgetScopedInstances();
        $this->sorgular = [];
        $this->disIstekler = [];
    }

    /** Salon alanlari (+ mobil kipte tum mobil bayraklar = 1). */
    private function salonAlanlari(string $kip): array
    {
        $alanlar = ['salon_id' => self::SALON, 'sube' => self::SALON, 'salonid' => self::SALON];
        if ($kip === 'mobil') {
            foreach ((array) config('oturum.mobil_bayraklari') as $bayrak) {
                $alanlar[$bayrak] = 1;
            }
        }

        return $alanlar;
    }

    /** Rota sablonundan cagrilacak adres: parametreler doldurulur, salon alanlari sorguya eklenir. */
    private function adres(Rota $rota, string $kip): string
    {
        $yol = preg_replace_callback('/\{(\w+)(\?)?\}/', fn ($m) => $this->parametreDegeri($rota, $m[1]), $rota->uri());
        $yol = preg_replace('#/+#', '/', rtrim($yol, '/'));

        return '/'.$yol.'?'.http_build_query($this->salonAlanlari($kip));
    }

    private function parametreDegeri(Rota $rota, string $ad): string
    {
        if (preg_match('/salon|sube/i', $ad)) {
            return (string) self::SALON;
        }
        $desen = $rota->wheres[$ad] ?? null;
        if ($desen === null) {
            return self::YOK_ID;
        }
        // whereIn('tur', [...]) → 'a|b|c': ilk secenek; aksi halde bilinen adaylardan uyan ilki.
        $adaylar = [self::YOK_ID, explode('|', $desen)[0], 'tarama', 'hizmet', 'urun', 'paket', 'a'];
        foreach ($adaylar as $aday) {
            if (@preg_match('#^(?:'.$desen.')$#u', $aday) === 1) {
                return $aday;
            }
        }

        return self::YOK_ID;
    }

    /** Uretilen adres gercekten bu rotaya mi gidiyor? Gitmiyorsa mesaj (tarama o ucu olcmemis olur). */
    private function eslesmeDenetle(string $yontem, string $adres, Rota $rota): ?string
    {
        try {
            $bulunan = Route::getRoutes()->match(Request::create($adres, $yontem));
        } catch (Throwable $e) {
            return "$yontem {$rota->uri()} → $adres: rota bulunamadi";
        }

        return $bulunan->uri() === $rota->uri() ? null : "$yontem {$rota->uri()} → $adres: {$bulunan->uri()} rotasina gitti";
    }

    /** @return array<int,string> sorgudaki tablo adlari */
    private function tablolar(string $sql): array
    {
        preg_match_all('/\b(?:from|join|into|update)\s+["`]?([A-Za-z0-9_]+)["`]?/i', $sql, $m);

        return $m[1] ?? [];
    }

    private function yazmaTablosu(string $sql): ?string
    {
        return preg_match('/^\s*(?:insert\s+(?:or\s+\w+\s+)?into|update|delete\s+from|replace\s+into)\s+["`]?([A-Za-z0-9_]+)/i', $sql, $m) ? $m[1] : null;
    }

    private function satir(string $kip, string $yontem, Rota $rota, ?string $adres, ?int $durum, string $sinif, bool $kirmizi, array $ek): array
    {
        return array_merge([
            'kip' => $kip,
            'yontem' => $yontem,
            'yol' => $rota->uri(),
            'adres' => $adres,
            'durum' => $durum,
            'sinif' => $sinif,
            'kirmizi' => $kirmizi,
            'eylem' => $rota->getActionName(),
        ], $ek);
    }

    // ------------------------------------------------------------------
    // Izin listesi
    // ------------------------------------------------------------------

    /** @return array<string, array<string,string>> */
    private function izinListeleri(): array
    {
        return [
            'KIMLIKSIZ' => UcYetkiIzinListesi::KIMLIKSIZ,
            'MUSTERI_TOKENI' => UcYetkiIzinListesi::MUSTERI_TOKENI,
            'KIMLIK_YETER' => UcYetkiIzinListesi::KIMLIK_YETER,
        ];
    }

    /** @return array{liste:string,desen:string,gerekce:string}|null ilk eslesen izin girdisi */
    private function izinBul(string $yontem, string $uri): ?array
    {
        foreach ($this->izinListeleri() as $liste => $girdiler) {
            foreach ($girdiler as $desen => $gerekce) {
                if (self::desenEslesir($desen, $yontem, $uri)) {
                    return ['liste' => $liste, 'desen' => $desen, 'gerekce' => $gerekce];
                }
            }
        }

        return null;
    }

    private static function desenEslesir(string $desen, string $yontem, string $uri): bool
    {
        if (str_contains($desen, ' ')) {
            [$desenYontem, $desen] = explode(' ', $desen, 2);
            if (strtoupper($desenYontem) !== $yontem) {
                return false;
            }
        }

        return Str::is($desen, $uri);
    }

    /**
     * Bekci: eskimis (hicbir rotaya uymayan) ve gereksiz/yanlis girdiler.
     *
     * @return array<int,string>
     */
    private function izinListesiSorunlari(array $sonuclar): array
    {
        $sorunlar = [];
        $rotalar = $this->v2Rotalari();
        foreach ($this->izinListeleri() as $liste => $girdiler) {
            foreach (array_keys($girdiler) as $desen) {
                $eslesen = [];
                foreach ($rotalar as $rota) {
                    foreach ($this->yontemler($rota) as $yontem) {
                        if (self::desenEslesir($desen, $yontem, $rota->uri())) {
                            $eslesen[] = [$yontem, $rota];
                        }
                    }
                }
                if ($eslesen === []) {
                    $sorunlar[] = "$liste '$desen': hicbir rotaya uymuyor (eskimis girdi)";

                    continue;
                }
                $sorunlar = array_merge($sorunlar, $this->girdiSorunlari($liste, $desen, $eslesen, $sonuclar));
            }
        }

        return $sorunlar;
    }

    /**
     * @param  array<int, array{0:string,1:Rota}>  $eslesen
     * @return array<int,string>
     */
    private function girdiSorunlari(string $liste, string $desen, array $eslesen, array $sonuclar): array
    {
        $sorunlar = [];
        foreach ($eslesen as [$yontem, $rota]) {
            $kimlikli = collect($rota->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth:'));
            if ($liste === 'KIMLIKSIZ' && $kimlikli) {
                $sorunlar[] = "KIMLIKSIZ '$desen': $yontem {$rota->uri()} kimlik istiyor; kimliksiz listede durursa taranmaz";
            }
            if ($liste === 'MUSTERI_TOKENI' && ! in_array('auth:api', $rota->gatherMiddleware(), true)) {
                $sorunlar[] = "MUSTERI_TOKENI '$desen': $yontem {$rota->uri()} musteri guard'inda (auth:api) degil";
            }
        }
        if ($liste === 'KIMLIK_YETER') {
            $uyan = array_filter($sonuclar, fn ($s) => ($s['izin'] ?? null) === 'KIMLIK_YETER'
                && self::desenEslesir($desen, $s['yontem'], $s['yol']));
            $durumlar = array_column($uyan, 'durum');
            if ($durumlar !== [] && array_diff($durumlar, [401, 403]) === []) {
                $sorunlar[] = "KIMLIK_YETER '$desen': eslesen uclarin hepsi zaten 401/403 veriyor (gereksiz girdi)";
            }
        }

        return $sorunlar;
    }

    // ------------------------------------------------------------------
    // Rapor
    // ------------------------------------------------------------------

    private function raporYaz(array $tarama): void
    {
        $ozet = [];
        foreach ($tarama['sonuclar'] as $s) {
            $ozet['sinif'][$s['sinif']] = ($ozet['sinif'][$s['sinif']] ?? 0) + 1;
            $ozet['durum'][(string) $s['durum']] = ($ozet['durum'][(string) $s['durum']] ?? 0) + 1;
            if ($s['kirmizi']) {
                $ozet['kirmizi'][$s['kip']] = ($ozet['kirmizi'][$s['kip']] ?? 0) + 1;
            }
        }
        $rapor = [
            'olusturma' => now()->toIso8601String(),
            'salon_id' => self::SALON,
            'hesap' => 'rol 5 personel, bos yetki sablonu (tum anahtarlar kapali)',
            'siniflar' => [
                self::SINIF_RET => 'Veriye ulasmadan reddetti (4xx). 422 dogrulama yetkiden once olabilir; yetki gorulemedi demektir.',
                self::SINIF_200 => 'Yetkisiz personele basarili cevap: yetki denetimi yok ya da yalniz kapsam.',
                self::SINIF_500 => 'Denetimden once kurulmamis is tablosuna gitti (alt_sinif ve hata alanina bakin).',
                self::SINIF_YAN_ETKI => 'Reddetti ama once veritabanina yazdi ya da dis servise istek atti.',
                self::SINIF_IZINLI => 'Izin listesinde (UcYetkiIzinListesi): kimliksiz ya da kimlik yeter.',
            ],
            'ozet' => $ozet,
            'eslesmeyen' => $tarama['eslesmeyen'],
            'izin_listesi_sorunlari' => $tarama['izin_listesi_sorunlari'],
            'kirmizi' => array_values(array_filter($tarama['sonuclar'], fn ($s) => $s['kirmizi'])),
            'uclar' => $tarama['sonuclar'],
        ];

        $dosya = storage_path(self::RAPOR);
        @mkdir(dirname($dosya), 0775, true);
        file_put_contents($dosya, json_encode($rapor, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function kirmiziMesaji(array $kirmizi): string
    {
        $satirlar = array_map(fn ($s) => sprintf('[%s] %s %s → %s %s%s%s', $s['kip'], $s['yontem'], $s['yol'], $s['durum'], $s['sinif'],
            isset($s['alt_sinif']) ? ' ('.$s['alt_sinif'].')' : '',
            isset($s['hata']) ? ' — '.Str::limit($s['hata'], 160) : (isset($s['beklenen']) ? ' — beklenen: '.$s['beklenen'] : '')), $kirmizi);

        return count($kirmizi)." uc yetkisiz personele veriye ulasmadan ret vermedi. Rapor: storage/".self::RAPOR."\n".implode("\n", $satirlar);
    }
}
