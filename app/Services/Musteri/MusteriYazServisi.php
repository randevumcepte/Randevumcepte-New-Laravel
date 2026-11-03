<?php

namespace App\Services\Musteri;

use App\Domain\Musteri\DogumTarihi;
use App\Domain\Musteri\MusteriSozluk;
use App\Domain\Musteri\MusteriTelefonu;
use App\Domain\Personel\Telefon;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use App\Support\Metin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Musteri YAZMA servisi — eski StoreAdminController::musteriekleguncelle, musteri_sil,
 * musterikaralisteayari, saglikbilgilerigir karsiligi (is kurallari burada, tek yerde).
 *
 * Yan etkiler v2'de URETILMEZ; ayni transaction'da v2_bildirim_kuyrugu'na yazilir:
 *   musteri_kayit       → eski kod: SMS ayari (ayar_id=4) aciksa kayit mesaji; yeni hesapta
 *                         sifre URETIP mesaja ekler; cinsiyet bossa addan tahmin eder.
 *   kara_liste_bildirim → eski kod: ayar_id=15 aciksa "kara listeye alindiniz" WA/SMS + push.
 *
 * Eskiden bilincli farklar (karar / guvenlik):
 *   - KVKK onayi YALNIZCA telefon degistiginde sifirlanir (eskisi her kayitta sifirliyordu).
 *   - Duzenlemede maskeli telefon ("0532 *** ** 47") gelirse numara DEGISMEZ (eskisi maskeyi yaziyordu).
 *   - Duzenlemede yeni numara baska musteriye aitse 409 (eskisi kontrol etmiyordu → mukerrer hesap).
 *   - Notlar portfoye yazilir ve oradan okunur (eski duzenle modali users.ozel_notlar'dan
 *     doldurup portfoyu ezebiliyordu).
 *   - Saglikta "hamile" ve aciklama alanlari gercekten kaydedilir (eski form yanlis isimdeydi).
 */
class MusteriYazServisi
{
    public function __construct(private PortfoyKapsami $kapsam) {}

    /**
     * Ekle (musteri_id yok) / duzenle.
     *
     * @param  array{salon_id:int, musteri_id:?int, yapan_id:int, ad_soyad:string, telefon:?string,
     *               email:?string, tc_kimlik_no:?string, dogum_tarihi:?string, cinsiyet:?int,
     *               musteri_tipi:?int, ozel_notlar:?string}  $g
     * @return array{id:int, name:string, yeni:bool, portfoye_eklendi:bool}
     */
    public function kaydet(array $g): array
    {
        $salonId = (int) $g['salon_id'];
        $yapanId = (int) $g['yapan_id'];
        $musteriId = $g['musteri_id'] ? (int) $g['musteri_id'] : null;
        $tel = MusteriTelefonu::kanonik($g['telefon'] ?? null);

        return DB::transaction(function () use ($g, $salonId, $yapanId, $musteriId, $tel) {
            if ($musteriId !== null) {
                return $this->guncelle($salonId, $musteriId, $yapanId, $tel, $g);
            }

            if ($tel === '' || Telefon::maskeliMi($g['telefon'] ?? '')) {
                throw new GecersizIslemException('Geçerli bir telefon numarası girin.');
            }

            $mevcut = DB::table('users')->whereIn('cep_telefon', MusteriTelefonu::aramaVaryantlari($tel))
                ->orderBy('id')->first(['id', 'name']);

            return $mevcut
                ? $this->portfoyeEkle($salonId, (int) $mevcut->id, (string) $mevcut->name, $yapanId, $g)
                : $this->yeniMusteri($salonId, $yapanId, $tel, $g);
        });
    }

    /** Portfoyden cikar (soft: aktif=0 — eski musteri_sil ile ayni; kayit ve gecmis korunur). */
    public function sil(int $salonId, int $userId, int $yapanId): array
    {
        DB::table('musteri_portfoy')->where('salon_id', $salonId)->where('user_id', $userId)
            ->update(['aktif' => 0, 'updated_at' => now()]);

        AktiviteLog::yaz($salonId, $yapanId, 'musteri_sil', 'musteri', $userId, $this->ad($userId), 'Müşteri silindi');

        return ['id' => $userId];
    }

    /** Kara liste ac/kapat. Alinirken bilgilendirme mesaji kuyruga (eski: ayar_id=15). */
    public function karaListe(int $salonId, int $userId, bool $kara, bool $modulAktif, int $yapanId): array
    {
        if ($kara && ! $modulAktif) {
            throw new GecersizIslemException('Kara liste modülü bu işletmede pasif.');
        }

        DB::transaction(function () use ($salonId, $userId, $kara, $yapanId) {
            DB::table('musteri_portfoy')->where('salon_id', $salonId)->where('user_id', $userId)
                ->update(['kara_liste' => $kara ? 1 : 0, 'updated_at' => now()]);
            if ($kara) {
                BildirimKuyrugu::yaz($salonId, 'kara_liste_bildirim', null, $yapanId, ['user_id' => $userId]);
            }
        });

        AktiviteLog::yaz($salonId, $yapanId, 'musteri_karaliste_ayar', 'musteri', $userId, $this->ad($userId),
            $kara ? 'Müşteri kara listeye alındı' : 'Müşteri kara listeden çıkarıldı');

        return ['id' => $userId, 'kara_liste' => $kara ? 1 : 0];
    }

    /** Saglik bilgileri (users satiri — kisi bazli, tum salonlarda ortak; eski davranis). */
    public function saglikKaydet(int $salonId, int $userId, array $alanlar, int $yapanId): array
    {
        $guncel = [];
        foreach (array_keys(MusteriSozluk::SAGLIK_SORULARI) as $k) {
            if (array_key_exists($k, $alanlar)) {
                $guncel[$k] = $alanlar[$k] === null || $alanlar[$k] === '' ? null : ((int) (bool) $alanlar[$k]);
            }
        }
        if (array_key_exists('cilt_tipi', $alanlar)) {
            $v = $alanlar['cilt_tipi'];
            $guncel['cilt_tipi'] = $v === null || $v === '' || ! isset(MusteriSozluk::CILT_TIPLERI[(int) $v]) ? null : (int) $v;
        }
        foreach (MusteriSozluk::SAGLIK_METINLERI as $k) {
            if (array_key_exists($k, $alanlar)) {
                $t = trim((string) $alanlar[$k]);
                $guncel[$k] = $t === '' ? null : mb_substr($t, 0, 2000);
            }
        }
        $guncel = array_filter($guncel, fn ($_, $k) => Schema::hasColumn('users', $k), ARRAY_FILTER_USE_BOTH);

        if ($guncel) {
            DB::table('users')->where('id', $userId)->update($guncel + ['updated_at' => now()]);
        }

        AktiviteLog::yaz($salonId, $yapanId, 'musteri_saglik_guncelle', 'musteri', $userId, $this->ad($userId), 'Sağlık bilgileri güncellendi');

        return ['id' => $userId, 'guncellenen' => array_keys($guncel)];
    }

    // ───────────────────────────── ic adimlar ─────────────────────────────

    private function yeniMusteri(int $salonId, int $yapanId, string $tel, array $g): array
    {
        $kimlik = $this->kimlikAlanlari($g);
        $userId = (int) DB::table('users')->insertGetId($kimlik + [
            'cep_telefon' => $tel,
            // Gecici, bilinmeyen sifre: SMS ayari aciksa eski kod yenisini uretip musteriye gonderir.
            'password'    => Hash::make(Str::random(24)),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->portfoyYaz($salonId, $userId, $yapanId, $g, yeniSatir: true);
        BildirimKuyrugu::yaz($salonId, 'musteri_kayit', null, $yapanId, [
            'user_id' => $userId, 'yeni_hesap' => true, 'cinsiyet_tahmin' => ($kimlik['cinsiyet'] ?? null) === null,
        ]);
        AktiviteLog::yaz($salonId, $yapanId, 'musteri_ekle', 'musteri', $userId, $kimlik['name'], 'Yeni müşteri eklendi');

        return ['id' => $userId, 'name' => $kimlik['name'], 'yeni' => true, 'portfoye_eklendi' => true];
    }

    /**
     * Telefon sistemde var: bu salonda aktifse 409; degilse portfoye (yeniden) eklenir.
     * Kisinin kimlik alanlari DEGISMEZ (users baska salonlarla ortak) — eski davranis.
     */
    private function portfoyeEkle(int $salonId, int $userId, string $ad, int $yapanId, array $g): array
    {
        $aktif = DB::table('musteri_portfoy')->where('salon_id', $salonId)->where('user_id', $userId)->where('aktif', 1)->exists();
        if ($aktif) {
            throw new CakismaException("Sistemde girdiğiniz telefon numarasına ait {$ad} isimli kayıt portföyünüzde mevcuttur.");
        }

        $this->portfoyYaz($salonId, $userId, $yapanId, $g, yeniSatir: true);
        BildirimKuyrugu::yaz($salonId, 'musteri_kayit', null, $yapanId, ['user_id' => $userId, 'yeni_hesap' => false]);
        AktiviteLog::yaz($salonId, $yapanId, 'musteri_ekle', 'musteri', $userId, $ad, 'Kayıtlı kişi portföye eklendi');

        return ['id' => $userId, 'name' => $ad, 'yeni' => false, 'portfoye_eklendi' => true];
    }

    private function guncelle(int $salonId, int $userId, int $yapanId, string $tel, array $g): array
    {
        $eski = DB::table('users')->where('id', $userId)->first(['id', 'cep_telefon']);

        // Maskeli / bos telefon gelirse numara korunur (telefon_gor yetkisizin duzenlemesi).
        $telDegisti = $tel !== '' && ! Telefon::maskeliMi($g['telefon'] ?? '')
            && $tel !== MusteriTelefonu::kanonik($eski->cep_telefon);

        if ($telDegisti && DB::table('users')->where('id', '!=', $userId)
            ->whereIn('cep_telefon', MusteriTelefonu::aramaVaryantlari($tel))->exists()) {
            throw new CakismaException('Bu telefon numarası başka bir müşteri kaydına ait.');
        }

        $kimlik = $this->kimlikAlanlari($g);
        DB::table('users')->where('id', $userId)->update($kimlik
            + ($telDegisti ? ['cep_telefon' => $tel] : [])
            + ['updated_at' => now()]);

        $this->portfoyYaz($salonId, $userId, $yapanId, $g, yeniSatir: false, kvkkSifirla: $telDegisti);
        AktiviteLog::yaz($salonId, $yapanId, 'musteri_guncelle', 'musteri', $userId, $kimlik['name'], 'Müşteri bilgileri güncellendi');

        return ['id' => $userId, 'name' => $kimlik['name'], 'yeni' => false, 'portfoye_eklendi' => false];
    }

    /** users kimlik alanlari (ad bas harfleri buyuk — eski User::setNameAttribute ile ayni). */
    private function kimlikAlanlari(array $g): array
    {
        $alanlar = [
            'name'         => Metin::basHarfBuyut(trim((string) $g['ad_soyad'])),
            'email'        => ($e = trim((string) ($g['email'] ?? ''))) === '' ? null : $e,
            'tc_kimlik_no' => ($tc = preg_replace('/\D/', '', (string) ($g['tc_kimlik_no'] ?? ''))) === '' ? null : $tc,
        ];
        // Cinsiyet yalniz 0/1 secildiyse yazilir (eski: secilmediyse mevcut korunur).
        $c = $g['cinsiyet'] ?? null;
        if ($c !== null && $c !== '' && isset(MusteriSozluk::CINSIYETLER[(int) $c])) {
            $alanlar['cinsiyet'] = (int) $c;
        }
        // Dogum tarihi yalniz gecerli girildiyse yazilir (eski: gun+ay yoksa mevcut korunur).
        if ($d = $g['dogum_tarihi'] ?? null) {
            $alanlar['dogum_tarihi'] = $d;
        }

        return $alanlar;
    }

    /**
     * Portfoy satiri ekle/guncelle. Eski MusteriPortfoy model hook'larinin karsiligi (v2
     * dogrudan DB'ye yazar): yeni / yeniden aktiflesen satirda KVKK onayi sifirlanir ve
     * 4 haneli onay_kodu uretilir.
     */
    private function portfoyYaz(int $salonId, int $userId, int $yapanId, array $g, bool $yeniSatir, bool $kvkkSifirla = false): void
    {
        $alanlar = [
            'musteri_tipi' => isset(MusteriSozluk::REFERANSLAR[(int) ($g['musteri_tipi'] ?? 0)]) ? (int) $g['musteri_tipi'] : null,
            'ozel_notlar'  => ($n = trim((string) ($g['ozel_notlar'] ?? ''))) === '' ? null : $n,
            'updated_at'   => now(),
        ];
        if ($yeniSatir || $kvkkSifirla) {
            $alanlar += ['kvkk_onay_alindi' => 0, 'onay_kodu' => (string) random_int(1000, 9999)];
        }

        $var = DB::table('musteri_portfoy')->where('salon_id', $salonId)->where('user_id', $userId)->orderByDesc('aktif')->value('id');
        if ($var && $yeniSatir) {
            $alanlar += ['aktif' => 1, 'olusturan_personel_id' => $this->kapsam->personelId($yapanId, $salonId)];
        }

        if ($var) {
            DB::table('musteri_portfoy')->where('id', $var)->update($alanlar);

            return;
        }
        if (! $yeniSatir) {
            throw new GecersizIslemException('Müşteri bu işletmenin portföyünde değil.');
        }
        DB::table('musteri_portfoy')->insert($alanlar + [
            'salon_id' => $salonId, 'user_id' => $userId, 'aktif' => 1, 'kara_liste' => 0,
            'olusturan_personel_id' => $this->kapsam->personelId($yapanId, $salonId), 'created_at' => now(),
        ]);
    }

    private function ad(int $userId): ?string
    {
        return DB::table('users')->where('id', $userId)->value('name');
    }
}
