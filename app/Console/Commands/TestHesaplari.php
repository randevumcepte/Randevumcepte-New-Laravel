<?php

namespace App\Console\Commands;

use App\Domain\Personel\HesapSahibiKorumasi;
use App\Domain\Telefon\TelefonNumarasi;
use App\Models\Personeller;
use App\Services\MusteriHesap\MusteriOturumServisi;
use App\Services\Musteri\MusteriYazServisi;
use App\Services\Personel\HesapSahibiDenetimi;
use App\Services\Personel\PersonelArsivServisi;
use App\Services\Personel\PersonelOkumaServisi;
use App\Services\Personel\PersonelYazServisi;
use App\Services\Personel\YetkiYonetimServisi;
use App\Support\Yetki\YetkiSabitleri;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Test salonunda (varsayilan 20) yetki ve gorunum testleri icin rol basina birer test hesabi.
 *
 * Tanima: ad "TEST " ile baslar VE telefon 500 999 00 xx araligindadir (Turkiye'de hicbir operatore tahsisli
 * olmayan 500 oneki; gercek bir kisiye ait olamaz). Iki kosul birlikte saglanmayan kayda dokunulmaz.
 *
 * Kurallar:
 *  - Personeller panelin servisleriyle acilir (PersonelYazServisi, YetkiYonetimServisi, PersonelArsivServisi).
 *    Yeni personel hesabina panel "personel_sifre" (WhatsApp/SMS) kuyruga yazar; bunu tetiklememek icin hesap
 *    once telefonsuz acilir, telefon ayni islemde duzenlemeyle eklenir (duzenlemede sifre gonderimi yok).
 *  - Musteri, telefonu dogrulanmis musterinin hesap acma yolu (MusteriOturumServisi::dogrulanmisMusteri) ile
 *    acilir; bu yol mesaj gondermez. (Panelin MusteriYazServisi::kaydet'i "musteri_kayit" SMS'i kuyruga yazar.)
 *  - Hesap sahibi (rol 1) eklenmez: rol 1'i yalniz salonun hesap sahibi ya da sistem yonetimi atayabilir
 *    (HesapSahibiKorumasi::rolAtamaEngeli). Komut gercek sahip adina islem yapmaz; gercek sahibe dokunulmaz.
 *  - Sifreler ekrana basilmaz; yalniz .env'ye yazilir (anahtar varsa guncellenir, yoksa sona eklenir).
 *    .env'de sifre zaten varsa korunur (tekrar calistirmak sifreyi degistirmez).
 *  - --sil: personel panelin arsiv kuraliyla arsivlenir, musteri portfoyden cikarilir (panelin musteri silme
 *    kurali); giris sifreleri bilinmeyen bir degere cevrilir ve .env anahtarlari kaldirilir.
 */
class TestHesaplari extends Command
{
    protected $signature = 'v2:test-hesaplari
        {--salon=20 : Test salonu}
        {--sil : Bu komutun actigi test hesaplarini kaldirir}
        {--dry-run : Yalniz ne yapilacagini yazar, hicbir sey yazmaz}
        {--zorla : Salon 20 disinda calismaya izin verir (dikkat: canli salon)}';

    protected $description = 'Test salonunda rol basina birer test hesabi acar (sifreler yalniz .env\'ye yazilir)';

    public const TEST_SALON = 20;

    /** Ad oneki ve telefon araligi: ikisi birlikte "bu komutun hesabi" demektir. */
    public const AD_ONEKI = 'TEST ';

    public const TEL_ONEKI = '50099900';

    /**
     * Hesaplar. anahtar → .env oneki (TEST_<anahtar>_TEL / _SIFRE).
     * tur: sahip | personel | musteri. sablon: YetkiSabitleri::sablonlar() anahtari.
     */
    public const HESAPLAR = [
        'HESAP_SAHIBI' => ['tur' => 'sahip', 'ad' => 'TEST Hesap Sahibi', 'tel' => '5009990001'],
        'YONETICI' => ['tur' => 'personel', 'ad' => 'TEST Yönetici', 'tel' => '5009990002', 'sablon' => 'yonetici'],
        'SEKRETER' => ['tur' => 'personel', 'ad' => 'TEST Sekreter', 'tel' => '5009990003', 'sablon' => 'sekreter'],
        'PERSONEL' => ['tur' => 'personel', 'ad' => 'TEST Personel', 'tel' => '5009990004', 'sablon' => 'personel'],
        'MUSTERI' => ['tur' => 'musteri', 'ad' => 'TEST Müşteri', 'tel' => '5009990005'],
    ];

    private bool $kuru = false;

    private int $salonId = 0;

    public function handle(): int
    {
        $this->salonId = (int) $this->option('salon');
        $this->kuru = (bool) $this->option('dry-run');

        if ($this->salonId <= 0 || ! DB::table('salonlar')->where('id', $this->salonId)->exists()) {
            $this->error("Salon {$this->salonId} bulunamadı.");

            return self::FAILURE;
        }
        if ($this->salonId !== self::TEST_SALON) {
            if (! $this->option('zorla')) {
                $this->error("Yalnız test salonunda (".self::TEST_SALON.") çalışır. Salon {$this->salonId} için --zorla gerekir.");

                return self::FAILURE;
            }
            $this->warn("UYARI: Salon {$this->salonId} test salonu değil. Test hesapları bu salonun personel listesine ve müşteri portföyüne girer.");
            if ($this->input->isInteractive() && ! $this->confirm('Devam edilsin mi?', false)) {
                return self::FAILURE;
            }
        }
        if ($this->kuru) {
            $this->line('[dry-run] Hiçbir şey yazılmayacak.');
        }

        $engel = $this->yabanciKayitEngeli();
        if ($engel !== null) {
            $this->error($engel);

            return self::FAILURE;
        }

        return $this->option('sil') ? $this->sil() : $this->olustur();
    }

    // ───────────────────────────── olustur ─────────────────────────────

    private function olustur(): int
    {
        $envYolu = app()->environmentFilePath();
        if (! $this->kuru && (! is_file($envYolu) || ! is_writable($envYolu))) {
            $this->error(".env yazılamıyor: $envYolu");

            return self::FAILURE;
        }
        $mevcutEnv = is_file($envYolu) ? self::envOku($envYolu) : [];
        $roller = $this->roller();
        $envYeni = [];
        $hata = false;

        foreach (self::HESAPLAR as $anahtar => $h) {
            $tel = TelefonNumarasi::kanonik($h['tel']);
            $etiket = $h['ad'].' ('.$tel.')';

            if ($h['tur'] === 'sahip') {
                $this->line("ATLANDI  $etiket: ".$this->sahipAtlamaNedeni());

                continue;
            }

            $sifre = self::gucluMu($mevcutEnv["TEST_{$anahtar}_SIFRE"] ?? null)
                ? $mevcutEnv["TEST_{$anahtar}_SIFRE"] : self::sifreUret();

            try {
                if ($h['tur'] === 'musteri') {
                    $durum = $this->musteriKur($h['ad'], $tel, $sifre);
                    $this->line("$durum  $etiket: müşteri, salon {$this->salonId} portföyü");
                } else {
                    $rol = $roller[$anahtar] ?? null;
                    if ($rol === null) {
                        $this->error("ATLANDI  $etiket: '{$h['sablon']}' için rol bulunamadı (roles tablosu).");
                        $hata = true;

                        continue;
                    }
                    $durum = $this->personelKur($h['ad'], $tel, $rol, $h['sablon'], $sifre);
                    $this->line("$durum  $etiket: rol $rol, şablon {$h['sablon']}, takvimde görünmez");
                }
            } catch (\Throwable $e) {
                $this->error("HATA  $etiket: ".$e->getMessage());
                $hata = true;

                continue;
            }

            $envYeni["TEST_{$anahtar}_TEL"] = $tel;
            $envYeni["TEST_{$anahtar}_SIFRE"] = $sifre;
        }

        if ($envYeni && ! $this->kuru) {
            self::envYaz($envYolu, $envYeni);
            $this->info(".env'ye yazıldı: ".implode(', ', array_keys($envYeni)));
        } elseif ($envYeni) {
            $this->line('[dry-run] .env\'ye yazılacak: '.implode(', ', array_keys($envYeni)));
        }

        return $hata ? self::FAILURE : self::SUCCESS;
    }

    /** Personel: ekle (telefonsuz) → telefonu duzenlemeyle ekle → yetki sablonu → takvimden gizle → sifre. */
    private function personelKur(string $ad, string $tel, int $rol, string $sablon, string $sifre): string
    {
        $mevcut = $this->testPersoneli($tel);
        $durum = $mevcut === null ? 'AÇILDI' : ((int) $mevcut->arsivli === 1 || (int) $mevcut->aktif !== 1 ? 'YENİDEN ETKİN' : 'GÜNCELLENDİ');
        if ($this->kuru) {
            return '[dry-run] '.$durum;
        }

        $yaz = app(PersonelYazServisi::class);
        DB::transaction(function () use ($yaz, $mevcut, $ad, $tel, $rol, $sablon, $sifre) {
            $ortak = ['salon_id' => $this->salonId, 'personel_adi' => $ad, 'sistem_yetki' => (string) $rol, 'yapan_id' => null];

            $personelId = $mevcut?->id;
            if ($personelId === null) {
                // Telefonsuz yeni hesap: panel yeni hesaba sifreyi WhatsApp/SMS ile gonderir (yalniz telefon varsa).
                $personelId = $yaz->ekleDuzenle($ortak + ['cep_telefon' => ''])['personel_id'];
            }
            // Duzenleme: telefon + rol + ad (GirisTelefonu ve HesapSahibiKorumasi denetimleri serviste).
            $yaz->ekleDuzenle($ortak + ['personel_id' => $personelId, 'cep_telefon' => $tel, 'maas_koru' => true]);

            $p = Personeller::findOrFail($personelId);
            if ((int) $p->arsivli === 1) {
                // v2 panelinde "arsivden cikar" yok; yalniz bu komutun kendi (TEST) satiri geri alinir.
                $p->arsivli = 0;
                $p->save();
            }
            if ((int) $p->aktif !== 1) {
                $yaz->durum($personelId, true);
            }
            if ((bool) Personeller::where('id', $personelId)->value('takvimde_gorunsun')) {
                $yaz->takvimToggle($personelId);
            }

            $yetki = app(YetkiYonetimServisi::class);
            $hedef = YetkiSabitleri::sablonAyarlari($sablon);
            $simdi = $yetki->getir($personelId, $this->salonId);
            if (! $simdi['kayit_var'] || $simdi['sablon'] !== $sablon || $simdi['ayarlar'] != $hedef) {
                $yetki->kaydet($personelId, $this->salonId, $sablon, $hedef, null);
            }

            $yetkiliId = (int) Personeller::where('id', $personelId)->value('yetkili_id');
            DB::table('isletmeyetkilileri')->where('id', $yetkiliId)->update(['password' => Hash::make($sifre)]);
        });

        return $durum;
    }

    /** Musteri: dogrulanmis musteri hesap acma yolu (mesaj yok) + portfoy + sifre. */
    private function musteriKur(string $ad, string $tel, string $sifre): string
    {
        $mevcut = $this->testMusterisi($tel);
        $portfoyAktif = $mevcut !== null && DB::table('musteri_portfoy')->where('salon_id', $this->salonId)
            ->where('user_id', $mevcut->id)->where('aktif', 1)->exists();
        $durum = $mevcut === null ? 'AÇILDI' : ($portfoyAktif ? 'GÜNCELLENDİ' : 'YENİDEN ETKİN');
        if ($this->kuru) {
            return '[dry-run] '.$durum;
        }

        DB::transaction(function () use ($ad, $tel, $sifre) {
            $userId = app(MusteriOturumServisi::class)->dogrulanmisMusteri($ad, $tel, $this->salonId)['user_id'];
            // --sil ile pasife alinan portfoy satiri: panelin yeniden ekleme yolu "musteri_kayit" SMS'i yazar;
            // yalniz bu komutun TEST musterisinin satiri dogrudan etkinlestirilir.
            DB::table('musteri_portfoy')->where('salon_id', $this->salonId)->where('user_id', $userId)
                ->where('aktif', '!=', 1)->update(['aktif' => 1, 'updated_at' => now()]);
            DB::table('users')->where('id', $userId)->update(['password' => Hash::make($sifre), 'updated_at' => now()]);
        });

        return $durum;
    }

    // ───────────────────────────── sil ─────────────────────────────

    private function sil(): int
    {
        $hata = false;
        $silinenAnahtarlar = [];

        foreach (self::HESAPLAR as $anahtar => $h) {
            $tel = TelefonNumarasi::kanonik($h['tel']);
            $etiket = $h['ad'].' ('.$tel.')';
            try {
                $sonuc = match ($h['tur']) {
                    'personel' => $this->personelSil($tel),
                    'musteri' => $this->musteriSil($tel),
                    default => 'YOK',
                };
            } catch (\Throwable $e) {
                $this->error("HATA  $etiket: ".$e->getMessage());
                $hata = true;

                continue;
            }
            if ($sonuc === 'AKTARIM GEREKLİ') {
                $this->error("ATLANDI  $etiket: gelecek randevuları var; önce panelden başka personele aktarın.");
                $hata = true;

                continue;
            }
            $this->line("$sonuc  $etiket");
            $silinenAnahtarlar[] = "TEST_{$anahtar}_TEL";
            $silinenAnahtarlar[] = "TEST_{$anahtar}_SIFRE";
        }

        $envYolu = app()->environmentFilePath();
        if ($this->kuru) {
            $this->line('[dry-run] .env\'den kaldırılacak: TEST_*_TEL / TEST_*_SIFRE');
        } elseif (is_file($envYolu) && is_writable($envYolu)) {
            self::envSil($envYolu, $silinenAnahtarlar);
            $this->info(".env'den kaldırıldı: test hesabı anahtarları");
        }

        return $hata ? self::FAILURE : self::SUCCESS;
    }

    private function personelSil(string $tel): string
    {
        $p = $this->testPersoneli($tel);
        if ($p === null) {
            return 'YOK';
        }
        if ((int) $p->arsivli === 1) {
            if (! $this->kuru) {
                $this->sifreBoz('isletmeyetkilileri', (int) $p->yetkili_id);
            }

            return 'ZATEN ARŞİVDE';
        }
        if ($this->kuru) {
            return '[dry-run] ARŞİVLENECEK';
        }

        $durum = 'ARŞİVLENDİ';
        DB::transaction(function () use ($p, &$durum) {
            // Panelin arsiv kurali (HesapSahibiKorumasi + gelecek hizmet aktarimi + oturum sonlandirma kuyrugu).
            $sonuc = app(PersonelArsivServisi::class)->arsivle($this->salonId, (int) $p->id, [], null);
            if (($sonuc['sonuc'] ?? null) === 'aktarim_gerekli') {
                $durum = 'AKTARIM GEREKLİ';

                return;
            }
            $this->sifreBoz('isletmeyetkilileri', (int) $p->yetkili_id);
        });

        return $durum;
    }

    private function musteriSil(string $tel): string
    {
        $u = $this->testMusterisi($tel);
        if ($u === null) {
            return 'YOK';
        }
        if ($this->kuru) {
            return '[dry-run] PORTFÖYDEN ÇIKARILACAK';
        }
        DB::transaction(function () use ($u) {
            // Panelin musteri silme kurali: portfoy aktif=0 (kayit ve gecmis korunur).
            app(MusteriYazServisi::class)->sil($this->salonId, (int) $u->id, 0);
            $this->sifreBoz('users', (int) $u->id);
        });

        return 'PORTFÖYDEN ÇIKARILDI';
    }

    /** Kaldirilan test hesabiyla giris yapilamasin: sifre bilinmeyen bir degere cevrilir. */
    private function sifreBoz(string $tablo, int $id): void
    {
        DB::table($tablo)->where('id', $id)->where('name', 'like', self::AD_ONEKI.'%')
            ->update(['password' => Hash::make(bin2hex(random_bytes(32)))]);
    }

    // ───────────────────────────── tanima / guvenlik ─────────────────────────────

    /** Bu salondaki TEST personel satiri (ad oneki + telefon araligi). */
    private function testPersoneli(string $tel): ?Personeller
    {
        return Personeller::where('salon_id', $this->salonId)->where('cep_telefon', $tel)
            ->where('personel_adi', 'like', self::AD_ONEKI.'%')->orderBy('id')->first();
    }

    private function testMusterisi(string $tel): ?object
    {
        return DB::table('users')->whereIn('cep_telefon', TelefonNumarasi::aramaVaryantlari($tel))
            ->where('name', 'like', self::AD_ONEKI.'%')->orderBy('id')->first(['id', 'name']);
    }

    /**
     * Test telefonlarindan biri TEST olmayan bir kayitta (giris hesabi, personel, musteri) ya da bu salon disinda bir
     * personel/rol satirinda varsa hicbir sey yapilmaz: yabanci kayda dokunulmaz.
     */
    private function yabanciKayitEngeli(): ?string
    {
        foreach (self::HESAPLAR as $h) {
            $tel = TelefonNumarasi::kanonik($h['tel']);
            if (! str_starts_with($tel, self::TEL_ONEKI)) {
                return "Test telefonu aralık dışında: $tel";
            }
            $v = TelefonNumarasi::aramaVaryantlari($tel);
            $yetkililer = DB::table('isletmeyetkilileri')->whereIn('gsm1', $v)->get(['id', 'name']);
            foreach ($yetkililer as $y) {
                if (! str_starts_with((string) $y->name, self::AD_ONEKI)) {
                    return "$tel numarası TEST olmayan bir giriş hesabında (id {$y->id}); hiçbir şey yapılmadı.";
                }
                $digerSalon = DB::table('salon_personelleri')->where('yetkili_id', $y->id)->where('salon_id', '!=', $this->salonId)->exists()
                    || DB::table('model_has_roles')->where('model_id', $y->id)->where('salon_id', '!=', $this->salonId)->exists();
                if ($digerSalon) {
                    return "$tel numaralı test hesabı başka bir salonda da kayıtlı (id {$y->id}); hiçbir şey yapılmadı.";
                }
            }
            if (DB::table('salon_personelleri')->whereIn('cep_telefon', $v)->where('personel_adi', 'not like', self::AD_ONEKI.'%')->exists()) {
                return "$tel numarası TEST olmayan bir personel satırında; hiçbir şey yapılmadı.";
            }
            if (DB::table('users')->whereIn('cep_telefon', $v)->where('name', 'not like', self::AD_ONEKI.'%')->exists()) {
                return "$tel numarası TEST olmayan bir müşteride; hiçbir şey yapılmadı.";
            }
        }

        return null;
    }

    /**
     * Rol id'leri: sekreter ve personel YetkiSabitleri'nden; yonetici roles tablosundaki adindan (secilebilir roller,
     * PersonelOkumaServisi::hesapTurleri — panelin rol listesi).
     *
     * @return array<string, int|null>
     */
    private function roller(): array
    {
        $yonetici = null;
        foreach (PersonelOkumaServisi::hesapTurleri() as $r) {
            $ad = mb_strtolower(trim((string) $r['ad']));
            if (in_array($ad, ['yönetici', 'yonetici'], true)
                && ! in_array($r['id'], [YetkiSabitleri::ROL_HESAP_SAHIBI, YetkiSabitleri::ROL_SEKRETER, YetkiSabitleri::ROL_PERSONEL], true)) {
                $yonetici = $r['id'];
                break;
            }
        }

        return ['YONETICI' => $yonetici, 'SEKRETER' => YetkiSabitleri::ROL_SEKRETER, 'PERSONEL' => YetkiSabitleri::ROL_PERSONEL];
    }

    /** Hesap sahibi neden eklenmez: panelin rol atama kurali (komut sahip degil, sistem oturumu degil). */
    private function sahipAtlamaNedeni(): string
    {
        $engel = HesapSahibiKorumasi::rolAtamaEngeli((string) HesapSahibiKorumasi::ROL_HESAP_SAHIBI, null,
            app(HesapSahibiDenetimi::class)->gecerliRoller(), false, false);

        return ($engel ?? 'Rol 1 ataması bu komuta açık değil.')
            .' Gerçek hesap sahibine dokunulmadı; test için salonun sahibi ya da "salon hesabına gir" kullanılır.';
    }

    // ───────────────────────────── sifre / .env ─────────────────────────────

    /** 24 karakter; buyuk, kucuk, rakam ve .env'de tirnak gerektirmeyen isaretler. */
    public static function sifreUret(): string
    {
        $gruplar = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '-_.'];
        $hepsi = implode('', $gruplar);
        $s = [];
        foreach ($gruplar as $g) {
            $s[] = $g[random_int(0, strlen($g) - 1)];
        }
        while (count($s) < 24) {
            $s[] = $hepsi[random_int(0, strlen($hepsi) - 1)];
        }
        for ($i = count($s) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }

        return implode('', $s);
    }

    private static function gucluMu(?string $s): bool
    {
        return $s !== null && strlen($s) >= 16 && preg_match('/[A-Z]/', $s) && preg_match('/[a-z]/', $s)
            && preg_match('/\d/', $s) && preg_match('/^[A-Za-z0-9._-]+$/', $s);
    }

    /** @return array<string, string> yalniz TEST_ anahtarlari (sifre yeniden kullanimi icin) */
    private static function envOku(string $yol): array
    {
        $deg = [];
        foreach (preg_split('/\R/', (string) file_get_contents($yol)) as $satir) {
            if (preg_match('/^(TEST_[A-Z_]+)=(.*)$/', $satir, $m)) {
                $deg[$m[1]] = trim($m[2], " \t\"'");
            }
        }

        return $deg;
    }

    /** Anahtar varsa o satiri gunceller, yoksa sona ekler; diger satirlar aynen kalir. */
    public static function envYaz(string $yol, array $degerler): void
    {
        $icerik = (string) file_get_contents($yol);
        foreach ($degerler as $k => $v) {
            $satir = $k.'='.$v;
            $desen = '/^'.preg_quote($k, '/').'=.*$/m';
            if (preg_match($desen, $icerik)) {
                $icerik = preg_replace_callback($desen, fn () => $satir, $icerik, 1);
            } else {
                if ($icerik !== '' && ! str_ends_with($icerik, "\n")) {
                    $icerik .= "\n";
                }
                $icerik .= $satir."\n";
            }
        }
        file_put_contents($yol, $icerik, LOCK_EX);
    }

    /** Verilen anahtarlarin satirlarini kaldirir; diger satirlar aynen kalir. */
    public static function envSil(string $yol, array $anahtarlar): void
    {
        if (! $anahtarlar) {
            return;
        }
        $icerik = (string) file_get_contents($yol);
        foreach ($anahtarlar as $k) {
            $icerik = preg_replace('/^'.preg_quote($k, '/').'=.*(\r?\n|$)/m', '', $icerik);
        }
        file_put_contents($yol, $icerik, LOCK_EX);
    }
}
