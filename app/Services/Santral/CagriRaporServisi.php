<?php

namespace App\Services\Santral;

use App\Domain\Santral\CdrSiniflandirici;
use App\Domain\Santral\SantralErisim;
use App\Models\Personeller;
use App\Support\ListeIstegi;
use App\Support\Yetki\YetkiSabitleri;
use Illuminate\Support\Facades\DB;

/**
 * Santral cagri raporu (CDR) — TEK KAYNAK (web v2 santral ekrani + Flutter santralraporlari).
 *
 * Eski iki ayri uygulamanin (web santral_raporlari / mobil cdrRaporLatest) yerine:
 *   1. Santralden tarih araligindaki CDR'lar cekilir (SantralIstemcisi, tavanli + onbellekli).
 *   2. Her satir saf CdrSiniflandirici ile siniflandirilir.
 *   3. Erisim kapsami uygulanir (personel → yalniz kendi dahilisi; SantralErisim).
 *   4. Musteri / personel adlari TOPLU sorguyla cozulur (N+1 yok).
 *   5. Ozet sayaclar TUM gorunur kume uzerinden; kategori/arama filtresi + sayfalama sonra.
 * Hicbir sey YAZMAZ.
 */
class CagriRaporServisi
{
    public function __construct(private SantralIstemcisi $istemci) {}

    /**
     * @param array{tarih1:string, tarih2:string, kategori:?string, ara:?string, page:int, per_page:int} $o
     */
    public function listele(int $salonId, SantralErisim $erisim, array $o): array
    {
        $personeller = Personeller::where('salon_id', $salonId)
            ->where(fn ($q) => $q->whereNotNull('dahili_no')->orWhereNotNull('dahili_no_webrtc'))
            ->get(['personel_adi', 'dahili_no', 'dahili_no_webrtc']);
        $salonDahilileri = SantralErisimServisi::dahililer($personeller);
        $personelAdlari = $this->personelAdlari($personeller);

        $tavan = (int) config('santral.cdr_tavan', 500);
        $trunk = (string) DB::table('sabit_numaralar')->where('salon_id', $salonId)->value('numara');
        // Ne dahili ne trunk varsa santrale GIDILMEZ: filtresiz istek baska salonlarin
        // CDR'larini dondurebilir (kapsam sizintisi).
        $cdr = $salonDahilileri || $trunk
            ? $this->istemci->cdrListesi($salonDahilileri, $trunk ?: null, $o['tarih1'], $o['tarih2'], $tavan)
            : ['satirlar' => [], 'toplam' => 0, 'hata' => false];

        $siniflar = [];
        foreach ($cdr['satirlar'] as $satir) {
            $sinif = CdrSiniflandirici::siniflandir($satir, $salonDahilileri);
            if ($sinif !== null && $erisim->gorebilirMi($sinif)) {
                $siniflar[] = $sinif;
            }
        }

        $musteriler = $this->musteriler($salonId, $siniflar);
        $satirlar = array_map(fn ($s) => $this->satir($s, $musteriler, $personelAdlari, $erisim->telefonGor), $siniflar);
        usort($satirlar, fn ($a, $b) => strcmp((string) $b['zaman'], (string) $a['zaman']));

        $ozet = $this->ozet($satirlar);
        $filtreli = $this->filtrele($satirlar, $o['kategori'] ?? null, $o['ara'] ?? null, $erisim->telefonGor);
        $toplam = count($filtreli);

        return [
            'data'    => array_values(array_slice($filtreli, ($o['page'] - 1) * $o['per_page'], $o['per_page'])),
            'ozet'    => $ozet,
            'tarih1'  => $o['tarih1'],
            'tarih2'  => $o['tarih2'],
            'kesildi' => count($cdr['satirlar']) >= $tavan,
            'hata'    => $cdr['hata'],
        ] + ListeIstegi::meta($o['page'], $o['per_page'], $toplam);
    }

    /** dahili → personel adi (normal + WebRTC dahilisi ayni kisiye isaret eder). */
    private function personelAdlari(iterable $personeller): array
    {
        $map = [];
        foreach ($personeller as $p) {
            foreach (SantralErisimServisi::dahililer([$p]) as $d) {
                $map[$d] = trim((string) $p->personel_adi);
            }
        }

        return $map;
    }

    /**
     * Bu salonun portfoyundeki musteriler, son-10-hane anahtarli. Diger salonlarin
     * musterisi ESLESTIRILMEZ (eski web genel users'a dusuyordu → baska salonun
     * musteri adi gorunebiliyordu). kayitli = AKTIF portfoy kaydi.
     */
    private function musteriler(int $salonId, array $siniflar): array
    {
        $son10lar = [];
        foreach ($siniflar as $s) {
            $k = $s['dahili_arama'] ? '' : CdrSiniflandirici::son10($s['karsi_numara']);
            if ($k !== '') {
                $son10lar[$k] = true;
            }
        }
        if (! $son10lar) {
            return [];
        }

        $adaylar = [];
        foreach (array_keys($son10lar) as $k) {
            array_push($adaylar, $k, '0'.$k, '90'.$k, '+90'.$k, '0090'.$k);
        }

        $map = [];
        $kayitlar = DB::table('users')
            ->join('musteri_portfoy', 'musteri_portfoy.user_id', '=', 'users.id')
            ->where('musteri_portfoy.salon_id', $salonId)
            ->whereIn('users.cep_telefon', $adaylar)
            ->orderByDesc('musteri_portfoy.aktif')
            ->get(['users.id', 'users.name', 'users.cep_telefon', 'users.profil_resim', 'musteri_portfoy.aktif']);
        foreach ($kayitlar as $u) {
            $map[CdrSiniflandirici::son10((string) $u->cep_telefon)] ??= $u;
        }

        return $map;
    }

    /**
     * API satir sozlesmesi. Flutter Cdr.fromJson alanlari (tarih, saat, musteri,
     * gorusmeyiyapan, telefon, durum, seskaydi, avatar, kayitli) AYNEN korunur;
     * yapisal alanlar (musteri_id, musteri_adi, kategori, etiket, renk, …) eklenir.
     */
    private function satir(array $s, array $musteriler, array $personelAdlari, bool $telGor): array
    {
        $numara = self::yerelNumara($s['karsi_numara']);
        $musteri = $s['dahili_arama'] ? null : ($musteriler[CdrSiniflandirici::son10($s['karsi_numara'])] ?? null);
        // Dahili numara kisisel veri degil → maskelenmez; dis numara telefon_gor yetkisine bagli.
        $acik = $telGor || $s['dahili_arama'];
        $telefon = $acik ? $numara : YetkiSabitleri::telefonMaskele($numara);

        if ($s['dahili_arama']) {
            $ad = $personelAdlari[$numara] ?? null;
            $gosterim = $ad ? $ad.' ('.$numara.')' : 'Dahili '.$numara;
        } else {
            $ad = $musteri ? trim((string) $musteri->name) : null;
            $gosterim = $ad ? $ad.' ('.$telefon.')' : $telefon;
        }

        return [
            'zaman'           => $s['zaman'],
            'tarih'           => $s['zaman'] ? date('d.m.Y', strtotime($s['zaman'])) : '',
            'saat'            => $s['zaman'] ? date('H:i', strtotime($s['zaman'])) : '',
            'yon'             => $s['yon'],
            'kategori'        => $s['kategori'],
            'durum'           => $s['durum'],
            'etiket'          => $s['etiket'],
            'renk'            => $s['renk'],
            'dahili_arama'    => $s['dahili_arama'],
            'musteri'         => $gosterim,
            'musteri_adi'     => $ad,
            'musteri_id'      => $musteri ? (int) $musteri->id : null,
            'kayitli'         => $musteri !== null && (int) $musteri->aktif === 1,
            'telefon'         => $telefon,
            // Arama butonu icin cevrilebilir numara: yalniz telefon_gor yetkisinde (maske sizmasin).
            'arama_numarasi'  => $acik && $numara !== '' ? (strlen($numara) === 10 ? '0'.$numara : $numara) : null,
            'gorusmeyiyapan'  => $this->gorusmeyiYapan($s, $personelAdlari),
            'sure_sn'         => $s['sure_sn'],
            'seskaydi'        => $s['ses_kaydi'] ?? '',
            'avatar'          => $musteri && $musteri->profil_resim ? (string) $musteri->profil_resim : null, // ham yol (eski v1 gibi)
        ];
    }

    /** "905321234567" / "+90 532…" / "05321234567" → "5321234567"; dahili/yabanci numara rakamlariyla kalir. */
    private static function yerelNumara(string $numara): string
    {
        $r = (string) preg_replace('/\D/', '', $numara);
        if (strlen($r) === 12 && str_starts_with($r, '90')) {
            $r = substr($r, 2);
        } elseif (strlen($r) === 14 && str_starts_with($r, '0090')) {
            $r = substr($r, 4);
        }

        return ltrim($r, '0');
    }

    /** "Ad (dahili)"; personel yoksa asistan menusunde menu adi, aksi halde dahili no. */
    private function gorusmeyiYapan(array $s, array $personelAdlari): string
    {
        $d = $s['personel_dahili'];
        $ad = $d !== '' ? ($personelAdlari[$d] ?? '') : '';
        if ($ad !== '') {
            return $ad.' ('.$d.')';
        }
        if ($s['asistan']) {
            return 'Sesli asistan · '.$s['etiket'];
        }

        return $d !== '' ? 'Dahili '.$d : '';
    }

    /** Eski sayaclarla ayni anahtarlar (gelen/giden/cevapsiz/basarisiz) + toplam. */
    private function ozet(array $satirlar): array
    {
        $say = array_fill_keys(CdrSiniflandirici::KATEGORILER, 0);
        foreach ($satirlar as $s) {
            $say[$s['kategori']]++;
        }

        return [
            'gelen_arama'     => $say['gelen'],
            'giden_arama'     => $say['giden'],
            'cevapsiz_arama'  => $say['cevapsiz'],
            'basarisiz_arama' => $say['ulasilamadi'],
            'toplam'          => count($satirlar),
        ];
    }

    private function filtrele(array $satirlar, ?string $kategori, ?string $ara, bool $telGor): array
    {
        if ($kategori && in_array($kategori, CdrSiniflandirici::KATEGORILER, true)) {
            $satirlar = array_filter($satirlar, fn ($s) => $s['kategori'] === $kategori);
        }
        $ara = mb_strtolower(trim((string) $ara));
        if ($ara !== '') {
            // Maskeli kullanici numara parcasiyla arayamaz (maskenin arkasi sizmasin).
            $satirlar = array_filter($satirlar, function ($s) use ($ara, $telGor) {
                $metin = mb_strtolower($s['musteri_adi'].' '.$s['gorusmeyiyapan'].' '.$s['etiket'].($telGor ? ' '.$s['telefon'] : ''));

                return str_contains($metin, $ara);
            });
        }

        return array_values($satirlar);
    }
}
