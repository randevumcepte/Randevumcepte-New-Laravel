<?php

namespace App\Services\Randevu;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Eski App\Services\SesliRandevuCozService'in cozumleme bolumu; kurallar degismedi. Musaitlik SesliRandevuMusaitlikServisi'ndedir.
 *
 * Sesli / yazili komuttan randevu bilgisi cozumleyen KURAL MOTORU.
 *
 * LLM YOKTUR — tamamen PHP kurallariyla calisir (sifir maliyet).
 * Amac: "yarin 2'de Mehmet Bey'e sac kesimi" gibi bir Turkce cumleyi
 *   { intent, musteri, hizmetler, personel, tarih, saat, eksik_alanlar, guven }
 * yapisina cevirmek. Randevuyu OLUSTURMAZ — sadece cozumler; olusturma
 * onay sonrasi mevcut ApiController@randevuekleguncelle ile yapilir.
 *
 * v1: sadece "randevu_olustur" intent'i uygulanir; diger intent'ler tespit
 *     edilip "desteklenmiyor" bayragiyla doner (iskelet hazir).
 */
class SesliRandevuCozServisi
{
    /** @var int */
    protected $salonId;

    /** @var int|null Giris yapan personel (randevu onun adina; hizmetler onunkiyle sinirli) */
    protected $personelId;

    /** Turkce gun adlari => Carbon dayOfWeek (0=pazar ... 6=cumartesi) */
    protected $gunler = [
        'pazartesi' => 1,
        'sali'      => 2,
        'carsamba'  => 3,
        'persembe'  => 4,
        'cuma'      => 5,
        'cumartesi' => 6,
        'pazar'     => 0,
    ];

    /** Turkce ay adlari => ay no */
    protected $aylar = [
        'ocak' => 1, 'subat' => 2, 'mart' => 3, 'nisan' => 4, 'mayis' => 5,
        'haziran' => 6, 'temmuz' => 7, 'agustos' => 8, 'eylul' => 9,
        'ekim' => 10, 'kasim' => 11, 'aralik' => 12,
    ];

    /** Turkce sayi sozcukleri ("uc gun sonra", "iki hafta sonra") -> rakam. */
    protected $sayiKelimeleri = [
        'bir' => 1, 'iki' => 2, 'uc' => 3, 'dort' => 4, 'bes' => 5,
        'alti' => 6, 'yedi' => 7, 'sekiz' => 8, 'dokuz' => 9, 'on' => 10, 'yirmi' => 20,
    ];

    /** Token'i sayiya cevir (rakam ya da sozcuk); bulunamazsa null. */
    protected function sayiKelimeCoz($tok)
    {
        if (preg_match('/^\d+$/', $tok)) return (int) $tok;
        return $this->sayiKelimeleri[$tok] ?? null;
    }

    /**
     * "ayin <gun>" -> ayin gunu (1-31). Rakam ("ayin 20 si") ya da sozcuk ("ayin yirmi besi",
     * "ayin onu") olabilir; sayi kokleri ONEK olarak eslesir (ordinal/iyelik ekleri toleransli).
     * Bulunamazsa null.
     */
    protected function ayGunSayisi($fold)
    {
        if (!preg_match('/\bay(?:in|nin)?\s+(.+)$/u', $fold, $mm)) return null;
        $rest = trim($mm[1]);
        if (preg_match('/^(\d{1,2})\b/u', $rest, $m)) {
            return ((int) $m[1] >= 1 && (int) $m[1] <= 31) ? (int) $m[1] : null;
        }
        $birler = ['bir' => 1, 'iki' => 2, 'uc' => 3, 'dort' => 4, 'bes' => 5, 'alti' => 6, 'yedi' => 7, 'sekiz' => 8, 'dokuz' => 9];
        $onlar  = ['on' => 10, 'yirmi' => 20, 'otuz' => 30];
        $onek = function ($t, $map) {
            $best = null; $bl = -1;
            foreach ($map as $k => $v) { if (strpos($t, $k) === 0 && strlen($k) > $bl) { $best = $v; $bl = strlen($k); } }
            return $best;
        };
        $val = 0; $kullanildi = false;
        foreach (preg_split('/\s+/', $rest) as $i => $t) {
            if ($i === 0) { $o = $onek($t, $onlar); if ($o !== null) { $val += $o; $kullanildi = true; continue; } }
            $b = $onek($t, $birler); if ($b !== null) { $val += $b; $kullanildi = true; break; }
            break;
        }
        return ($kullanildi && $val >= 1 && $val <= 31) ? $val : null;
    }

    /** Ay+gun -> tarih (gecmisse gelecek yila alir). tarihCoz'daki iki kalibin ortak yardimcisi. */
    protected function ayGunTarih($ay, $gun, $bugun)
    {
        if (!checkdate($ay, $gun, $bugun->year)) return null;
        $t = Carbon::createFromDate($bugun->year, $ay, $gun)->startOfDay();
        if ($t->lt($bugun->copy()->startOfDay())) $t->addYear();
        return $t->toDateString();
    }

    /** Musteri adini bulurken atilacak dolgu kelimeleri */
    protected $stopKelimeler = [
        'randevu', 'randevusu', 'randevuyu', 'ver', 'versene', 'yaz', 'ekle', 'olustur',
        'olusturur', 'musun', 'lutfen', 'icin', 'ile', 've', 'bir',
        'ara', 'arama', 'aç', 'ac', 'lazim', 'istiyorum', 'istiyor', 'yaptir', 'yaptirmak',
        // tarih ekli/yalin
        'bugun', 'bugune', 'yarin', 'yarina', 'obur', 'ertesi', 'haftaya',
        'gun', 'gune', 'gunu', 'gunku',
        // saat ekli/yalin
        'saat', 'saatte', 'saatinde', 'sabah', 'sabaha', 'ogleden', 'once', 'sonra',
        'aksam', 'aksama', 'gece', 'geceye', 'ogle', 'ogleye', 'bucuk', 'bucukta',
        'bucugu', 'ceyrek', 'ceyrekte',
        // lokatif/ekler tek basina kalinca
        'te', 'de', 'da', 'ta',
        // hitap
        'bey', 'beye', 'beyi', 'hanim', 'hanima', 'hanimi', 'hn', 'by',
        'kardes', 'abi', 'abla', 'usta',
    ];

    /**
     * Ana giris noktasi.
     *
     * @param  string   $metin       Kullanicinin soyledigi / yazdigi cumle
     * @param  int      $salonId     Salon id
     * @param  int|null $personelId  Varsa: randevu bu personel adina; hizmetler onunkiyle sinirli
     * @return array
     */
    /** Eski kayitlarda utf8 kolonda latin5 bayt olabilir: json_encode patlamasin diye cevap onarilir. */
    public function coz($metin, $salonId, $personelId = null, $tumPersonel = false)
    {
        return $this->utf8Duzelt($this->cozumle($metin, $salonId, $personelId, $tumPersonel));
    }

    private function utf8Duzelt($deger)
    {
        if (is_array($deger)) {
            return array_map(fn ($v) => $this->utf8Duzelt($v), $deger);
        }

        return is_string($deger) && ! mb_check_encoding($deger, 'UTF-8') ? mb_convert_encoding($deger, 'UTF-8', 'Windows-1254') : $deger;
    }

    private function cozumle($metin, $salonId, $personelId, $tumPersonel)
    {
        $this->salonId = (int) $salonId;
        $this->personelId = $personelId ? (int) $personelId : null;
        $girisPersonelId = $this->personelId; // yetki gate icin: giris yapan sabit

        $ham = trim((string) $metin);
        // Girdi UTF-8 degilse (latin5/Windows-1254 tek baytlar) once cevir; yoksa Turkce
        // harfler foldlanamaz ve silinir ("yarin"->"yar n", "sac"->"sa").
        if (!mb_check_encoding($ham, 'UTF-8')) {
            $ham = mb_convert_encoding($ham, 'UTF-8', 'Windows-1254');
        }
        $fold   = $this->fold($ham);           // karsilastirma icin sadelestirilmis metin
        $intent = $this->intentBul($fold);

        $tarih = $this->tarihCoz($fold);
        $saat  = $this->saatCoz($fold);
        $vakit = $this->vakitBul($fold); // sabah | ogleden_sonra | aksam | null

        // Datif ekli isim (Ferdi Korkmaz'A) MUSTERI'dir — bu ipucu, ayni adli bir
        // personelin musteri adini kapmasini onler ("Ferdi" personel degil, musteri).
        $musteriIpucu = $this->datifMusteriBul($fold);

        // ACIK ROL etiketleri (tek cumlelik tam komut): "Ayse musterisine"/"musteri Ayse"
        // -> musteri; "personel Fatih"/"Fatih personeline" -> personel. Ayni cumlede
        // musteri ile personel adinin karismasini onler.
        $rolMusteri = $this->rolAdiBul($fold, 'musteri');
        $rolPersonel = $this->rolAdiBul($fold, 'personel');
        if ($rolMusteri && !$musteriIpucu) {
            $musteriIpucu = $rolMusteri; // musteri adini isaretle -> personelden haric tutulur
        }

        // Personel: cumlede baska bir personel adi geciyorsa ONU kullan; hizmetler de
        // o personelinkiyle sinirli olsun. "personel X" etiketi varsa X tercih edilir.
        // Cumlede isim gecmezse giris yapan personel (personel_id) SABIT kalir.
        list($cumlePersonel, $personelMetni) =
            $this->personelEslestir($fold, [], $musteriIpucu, $rolPersonel);
        // YETKI GATE: BASKA personele randevu SADECE 'tum_personel' yetkisi olanlara.
        // Yetki yoksa cumlede baska personel gecse bile YOKSAY -> giris yapan personele
        // yazilir (hizmet de onunla sinirli kalir; tutarli). Kendini soylemesi serbest.
        if ($cumlePersonel
            && !$tumPersonel
            && $girisPersonelId
            && (int) $cumlePersonel['personel_id'] !== (int) $girisPersonelId) {
            $cumlePersonel = null;
            $personelMetni = null;
            $this->personelId = (int) $girisPersonelId; // giris yapan sabit kalsin
        }
        if ($cumlePersonel) {
            // Cumlede gecen personel adina -> hizmet eslestirmesi de onunla sinirli
            $this->personelId = (int) $cumlePersonel['personel_id'];
            $personel = [
                'personel_id'  => (int) $cumlePersonel['personel_id'],
                'personel_adi' => $cumlePersonel['personel_adi'],
                'sabit'        => false,
            ];
        } elseif ($this->personelId) {
            $p = DB::table('salon_personelleri')->where('id', $this->personelId)->first();
            $personel = $p ? ['personel_id' => (int) $p->id, 'personel_adi' => $p->personel_adi, 'sabit' => true] : null;
            $personelMetni = null;
        } else {
            $personel = null;
            $personelMetni = null;
        }

        // Hizmet: yukarida cozulen personel ($this->personelId) SADECE onun
        // sundugu hizmetlerle sinirli.
        list($hizmetler, $hizmetMetinleri) = $this->hizmetEslestir($fold);

        // Musteri: datif ipucu varsa onu kullan, yoksa kalan kelimelerden ad tahmini
        $musteri = $this->musteriEslestir($ham, $fold, $hizmetMetinleri, $personelMetni, $musteriIpucu);

        $eksik = $this->eksikAlanlar($musteri, $hizmetler, $tarih, $saat);
        $guven = $this->guvenHesapla($intent, $musteri, $hizmetler, $tarih, $saat);

        return [
            'basarili'      => $intent === 'randevu_olustur',
            'intent'        => $intent,
            'desteklenmiyor' => $intent !== 'randevu_olustur',
            'ham_metin'     => $ham,
            'musteri'       => $musteri,
            'hizmetler'     => $hizmetler,
            'personel'      => $personel,
            'tarih'         => $tarih,      // Y-m-d | null
            'saat'          => $saat,       // H:i   | null
            'vakit'         => $vakit,      // sabah | ogleden_sonra | aksam | null
            'eksik_alanlar' => $eksik,      // ['musteri','hizmet','tarih','saat','personel'] alt kumesi
            'guven'         => $guven,      // yuksek | orta | dusuk
        ];
    }

    /* ------------------------------------------------------------------ */
    /* INTENT                                                             */
    /* ------------------------------------------------------------------ */

    protected function intentBul($fold)
    {
        // Saat kapatma: "yarin 2'den sonra kapat", "bugun kapali"
        if (preg_match('/\bkapat\b|\bkapali\b|\bkapama\b|\bmesai.*kapat/u', $fold)) {
            return 'saat_kapat';
        }
        // Personel izni: "Merve bugun ogleden sonra izinli"
        if (preg_match('/\bizinli\b|\bizin\b|\brapor(lu)?\b/u', $fold)) {
            return 'personel_izin';
        }
        return 'randevu_olustur';
    }

    /**
     * Datif ekli ismi (musteri) bulur: "Ferdi Korkmaz'a", "Ayse'ye".
     * Apostrof + a/e/ya/ye eki guclu musteri sinyalidir. Ekten onceki 1-3 kelimeyi
     * (dolgu/tarih/saat kelimelerini eleyerek) musteri adi olarak doner.
     */
    protected function datifMusteriBul($fold)
    {
        if (!preg_match('/([a-z]+(?:\s+[a-z]+){0,2})[\x{2019}\x{27}](?:a|e|ya|ye|na|ne)\b/u', $fold, $m)) {
            return null;
        }
        $kelimeler = array_values(array_filter(preg_split('/\s+/u', trim($m[1])), function ($w) {
            return mb_strlen($w) >= 2 && !in_array($w, $this->stopKelimeler, true)
                && !array_key_exists($w, $this->gunler)
                && !array_key_exists($w, $this->aylar);
        }));
        if (empty($kelimeler)) {
            return null;
        }
        return implode(' ', array_slice($kelimeler, -3)); // en fazla ad + soyad (+1)
    }

    /**
     * ACIK ROL etiketiyle gecen adi bulur (tek cumlelik tam komutlar icin):
     *   "<rol> Ahmet"        -> rol kelimesinden SONRAKI ilk kelime (ad)
     *   "Ahmet <rol...ine>"  -> rol kelimesinden ONCEKI 1-2 kelime (ad soyad)
     * $rolKok fold'lu: 'musteri' | 'personel'. Bulamazsa null. Boylece ayni cumlede
     * "Ayse musterisine ... personel Fatih" -> Ayse=musteri, Fatih=personel ayrisir.
     */
    protected function rolAdiBul($fold, $rolKok)
    {
        // SONRAKI: "musteri ayse", "personel fatih olsun" -> tek kelime ad (yan alani kapmasin)
        if (preg_match('/\b' . $rolKok . '[a-z]*\s+([a-z]+)/u', $fold, $m)) {
            $ad = $this->rolAdTemizle($m[1]);
            if ($ad !== '') return $ad;
        }
        // ONCEKI: "ayse musterisine", "fatih gul personeline" -> 1-2 kelime ad(+soyad)
        if (preg_match('/([a-z]+(?:\s+[a-z]+)?)\s+' . $rolKok . '[a-z]*\b/u', $fold, $m)) {
            $ad = $this->rolAdTemizle($m[1]);
            if ($ad !== '') return $ad;
        }
        return null;
    }

    /** Rol etiketi yaninda yakalanan adi temizler (komut/dolgu/tarih kelimelerini eler). */
    protected function rolAdTemizle($s)
    {
        static $komut = [
            'olsun', 'olustur', 'olusturalim', 'olusturun', 'olusabilir', 'ver', 'verin',
            'al', 'alalim', 'icin', 'randevu', 'randevusu', 'lutfen', 'bir', 'adli',
            'adinda', 'ismi', 'isimli', 'olarak', 'da', 'de', 'ye', 'ya', 'na',
        ];
        $kel = array_values(array_filter(preg_split('/\s+/u', trim($s)), function ($w) use ($komut) {
            return mb_strlen($w) >= 2
                && !in_array($w, $komut, true)
                && !in_array($w, $this->stopKelimeler, true)
                && !array_key_exists($w, $this->gunler)
                && !array_key_exists($w, $this->aylar);
        }));
        return implode(' ', array_slice($kel, 0, 2)); // ad (+soyad)
    }

    /* ------------------------------------------------------------------ */
    /* TARIH                                                              */
    /* ------------------------------------------------------------------ */

    protected function tarihCoz($fold)
    {
        $bugun = Carbon::today();

        // 1) Kesin format: 2026-07-28 / 28.07.2026 / 28/07
        if (preg_match('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/u', $fold, $m)) {
            return $this->guvenliTarih($m[1], $m[2], $m[3]);
        }
        // dd.mm(.yyyy) — ay 1-12 ve gun 1-31 degilse tarih SAYMA ("14.30" saat olabilir)
        if (preg_match('/\b(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{2,4}))?\b/u', $fold, $m)
            && (int) $m[2] >= 1 && (int) $m[2] <= 12 && (int) $m[1] >= 1 && (int) $m[1] <= 31) {
            $yil = isset($m[3]) && $m[3] !== '' ? $this->yilNormalize($m[3]) : $bugun->year;
            return $this->guvenliTarih($yil, $m[2], $m[1]);
        }

        // 2) Goreli: bugun / yarin / obur gun / ertesi gun / yarindan sonra
        if (preg_match('/\bbugun\b/u', $fold))                  return $bugun->toDateString();
        if (preg_match('/\byarin\b/u', $fold) && !preg_match('/\byarindan sonra\b/u', $fold)) return $bugun->copy()->addDay()->toDateString();
        if (preg_match('/\bobur gun\b|\bobursu gun\b|\bertesi gun\b|\byarindan sonra\b/u', $fold)) return $bugun->copy()->addDays(2)->toDateString();

        // 2b) "uc gun sonra" / "3 gun icinde" / "iki hafta sonra"  (sozcuk ya da rakam)
        $sayiRe = '(?:\d{1,2}|' . implode('|', array_keys($this->sayiKelimeleri)) . ')';
        if (preg_match('/\b(' . $sayiRe . ')\s*gun\s*(?:sonra|icinde|sonrasi|sonrasinda)\b/u', $fold, $m)) {
            $n = $this->sayiKelimeCoz($m[1]);
            if ($n) return $bugun->copy()->addDays($n)->toDateString();
        }
        if (preg_match('/\b(' . $sayiRe . ')\s*hafta\s*(?:sonra|icinde|sonrasinda)\b/u', $fold, $m)) {
            $n = $this->sayiKelimeCoz($m[1]);
            if ($n) return $bugun->copy()->addWeeks($n)->toDateString();
        }
        // 2c) "hafta sonu" -> ilk cumartesi
        if (preg_match('/\bhafta ?sonu\b/u', $fold)) {
            return $bugun->copy()->next(6)->toDateString();
        }

        // 3) "15 agustos" / "3 nisan"  VEYA ters "agustos 15" / "agustosun 15" (ek toleransli)
        $ayRe = implode('|', array_keys($this->aylar));
        if (preg_match('/\b(\d{1,2})\s+(' . $ayRe . ')\b/u', $fold, $m)) {
            return $this->ayGunTarih($this->aylar[$m[2]], (int) $m[1], $bugun);
        }
        if (preg_match('/\b(' . $ayRe . ')(?:in|un|nin|nun)?\s+(\d{1,2})\b/u', $fold, $m)) {
            return $this->ayGunTarih($this->aylar[$m[1]], (int) $m[2], $bugun);
        }
        // 3b) "ayin 20 si" / "bu ayin ucu" / "gelecek ayin yirmi besi" (rakam ya da sozcuk)
        $ayGun = $this->ayGunSayisi($fold);
        if ($ayGun !== null) {
            $gun = $ayGun;
            $gelecekAy = (bool) preg_match('/\bgelecek ay|\bonumuzdeki ay/u', $fold);
            $y = $bugun->year; $ay = $bugun->month;
            if ($gelecekAy) { $ay++; if ($ay > 12) { $ay = 1; $y++; } }
            if (!checkdate($ay, $gun, $y)) return null;
            $t = Carbon::createFromDate($y, $ay, $gun)->startOfDay();
            if (!$gelecekAy && $t->lt($bugun->copy()->startOfDay())) {
                $ay++; if ($ay > 12) { $ay = 1; $y++; }
                if (!checkdate($ay, $gun, $y)) return null;
                $t = Carbon::createFromDate($y, $ay, $gun)->startOfDay();
            }
            return $t->toDateString();
        }

        // 4) Gun adi: "cumartesi", "haftaya sali", "bu cuma"
        foreach ($this->gunler as $ad => $dow) {
            if (preg_match('/\b' . $ad . '\b/u', $fold)) {
                $hedef = $bugun->copy()->next($dow);            // her zaman ILERI tarih
                // "haftaya / gelecek hafta / onumuzdeki hafta carsamba" -> gelecek haftaya kaydir.
                if (preg_match('/\bhaftaya\b|\b(gelecek|onumuzdeki|onumuzdaki) hafta\b/u', $fold)) {
                    $hedef->addWeek();
                }
                return $hedef->toDateString();
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /* SAAT                                                               */
    /* ------------------------------------------------------------------ */

    protected function saatCoz($fold)
    {
        $ogledenSonra = (bool) preg_match('/\bogleden sonra\b|\baksam\b|\bgece\b/u', $fold);
        $sabah        = (bool) preg_match('/\bsabah\b|\bogleden once\b/u', $fold);

        // 1) 14:30 / 9.45
        if (preg_match('/\b(\d{1,2})[:.](\d{2})\b/u', $fold, $m)) {
            return $this->saatFormat((int) $m[1], (int) $m[2], $ogledenSonra, $sabah, true);
        }

        // 1b) "10'a ceyrek kala" -> (saat-1):45 ; "3'u ceyrek gece" -> saat:15
        if (preg_match('/\b(\d{1,2}).{0,6}\bceyrek kala\b/u', $fold, $m)) {
            $h = (int) $m[1] - 1; if ($h < 0) $h = 23;
            return $this->saatFormat($h, 45, $ogledenSonra, $sabah, false);
        }
        if (preg_match('/\b(\d{1,2}).{0,6}\bceyrek (?:gece|geciyor)\b/u', $fold, $m)) {
            return $this->saatFormat((int) $m[1], 15, $ogledenSonra, $sabah, false);
        }

        // 2) "2 bucuk" / "3 bucukta" (ekli hali de) -> :30
        if (preg_match('/\b(\d{1,2})\s*bucuk/u', $fold, $m)) {
            return $this->saatFormat((int) $m[1], 30, $ogledenSonra, $sabah, false);
        }

        // 2b) rakamsiz "oglen" / "ogleyin" / "ogle vakti" -> 12:00
        if (!preg_match('/\d/', $fold) && preg_match('/\boglen\b|\bogle\b|\bogleyin\b|\bogle vakti\b/u', $fold)) {
            return $this->saatFormat(12, 0, false, false, true);
        }

        // 3) "saat 14" / "2'de" / "saat 2" / "ogleden sonra 3" / "3 gibi" / "9'a dogru"
        if (preg_match('/\bsaat\s+(\d{1,2})\b/u', $fold, $m)
            || preg_match('/\b(\d{1,2})[\'’]?(?:de|da|te|ta)\b/u', $fold, $m)
            || preg_match('/\b(?:ogleden sonra|aksam|sabah)\s+(\d{1,2})\b/u', $fold, $m)
            || preg_match('/\b(\d{1,2})\s*(?:gibi|sularinda|civari|civarinda|dolaylarinda)\b/u', $fold, $m)
            || preg_match('/\b(\d{1,2})[\'’]?\s*[ae]\s+dogru\b/u', $fold, $m)) {
            return $this->saatFormat((int) $m[1], 0, $ogledenSonra, $sabah, false);
        }

        return null;
    }

    /**
     * Saati normalize eder ve H:i doner.
     *
     * @param bool $kesin  Kullanici saati acikca yazdi mi (14:30 gibi)? Ise AM/PM tahmini yapilmaz.
     */
    protected function saatFormat($saat, $dakika, $ogledenSonra, $sabah, $kesin)
    {
        if ($saat < 0 || $saat > 23 || $dakika < 0 || $dakika > 59) {
            return null;
        }

        if (!$kesin) {
            if ($ogledenSonra && $saat < 12) {
                $saat += 12;
            } elseif ($sabah && $saat == 12) {
                $saat = 0;
            } elseif (!$ogledenSonra && !$sabah && $saat >= 1 && $saat <= 7) {
                // Salon calisma saati mantigi: yalin kucuk saat ogleden sonra kabul edilir
                // ("yarin saat 2" -> 14:00). Rakip uygulama da boyle davraniyor.
                $saat += 12;
            }
        }

        return sprintf('%02d:%02d', $saat, $dakika);
    }

    /* ------------------------------------------------------------------ */
    /* MUSAITLIK (bos slot bulma)                                         */
    /* ------------------------------------------------------------------ */

    /** Cumleden vakit ifadesi: sabah | ogleden_sonra | aksam | null */
    protected function vakitBul($fold)
    {
        if (preg_match('/\baksam\b|\bgece\b/u', $fold)) {
            return 'aksam';
        }
        if (preg_match('/\bogleden sonra\b/u', $fold)) {
            return 'ogleden_sonra';
        }
        if (preg_match('/\bsabah\b|\bogleden once\b/u', $fold)) {
            return 'sabah';
        }
        return null;
    }

    /* ------------------------------------------------------------------ */
    /* HIZMET                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * @return array [0 => eslesen hizmetler[], 1 => metinde eslesen fold parcalari[]]
     */
    protected function hizmetEslestir($fold)
    {
        $query = DB::table('salon_sunulan_hizmetler as sh')
            ->leftJoin('hizmetler as h', 'h.id', '=', 'sh.hizmet_id')
            ->where('sh.salon_id', $this->salonId)
            ->select('sh.*', 'h.hizmet_adi as _hizmet_adi');

        // Personel verildiyse SADECE onun sundugu hizmetler (personel_sunulan_hizmetler)
        if ($this->personelId) {
            $hizmetIdleri = DB::table('personel_sunulan_hizmetler')->where('personel_id', $this->personelId)
                ->pluck('hizmet_id')->all();
            $query->whereIn('sh.hizmet_id', $hizmetIdleri ?: [0]); // bos ise hicbir hizmet eslesmez
        }

        $liste = $query->get();

        // Metnin kelimeleri (kelime-bazli eslestirme icin)
        $metinKelimeleri = array_values(array_filter(
            preg_split('/\s+/u', $fold),
            function ($w) { return mb_strlen($w) >= 2; }
        ));

        $eslesen = [];
        $metinler = [];
        foreach ($liste as $sh) {
            $ad = $sh->_hizmet_adi;
            if (!$ad) {
                continue;
            }
            $adFold = $this->fold($ad);
            if ($adFold === '') {
                continue;
            }

            // KELIME-BAZLI eslestirme: hizmetin TUM kelimeleri (ayirt edici olan
            // dahil, or. "cilt"/"sac") metinde gecmeli. Boylece "cilt bakimi" yalnizca
            // "Cilt Bakimi" ile eslesir; ortak "bakimi" yuzunden "Sac Bakimi"ye kaymaz.
            $skor = 0.0;
            $eslesenKelime = 0;
            $adKelimeleri = [];
            if (mb_strpos($fold, $adFold) !== false) {
                $skor = 1.0; // tam hizmet adi cumlede geciyor
            } else {
                $adKelimeleri = array_values(array_filter(
                    preg_split('/\s+/u', $adFold),
                    function ($w) { return mb_strlen($w) >= 2; }
                ));
                if (!empty($adKelimeleri)) {
                    foreach ($adKelimeleri as $ak) {
                        foreach ($metinKelimeleri as $mk) {
                            if ($this->kelimeUyar($ak, $mk)) {
                                $eslesenKelime++;
                                break;
                            }
                        }
                    }
                    $toplam = count($adKelimeleri);
                    $oran = $eslesenKelime / $toplam;
                    if ($eslesenKelime === $toplam) {
                        $skor = 0.9; // hizmetin TUM kelimeleri metinde
                    } elseif ($eslesenKelime >= 2) {
                        // KISMI: kullanicinin soyledigi kelimeler hizmette geciyor ama
                        // hizmet adinda FAZLA kelime var (or. "sac boyama" -> "Komple Sac
                        // Boyama"). En cok kelime eslesen + en yuksek oran kazanir.
                        $skor = 0.6 + 0.25 * $oran;
                    } elseif ($eslesenKelime === 1 && $toplam === 1) {
                        $skor = 0.75; // tek kelimelik hizmet ve o kelime geciyor
                    }
                }
            }

            // KAMPANYA/PAKET adli hizmetler (icinde bircok hizmet olan demetler):
            // kullanici ACIKCA "kampanya/paket" demediyse GERI PLANA at. Boylece
            // "kalici oje" deyince "... Kalici Oje Kampanyasi/Paketi" degil, gercek
            // "Kalici Oje" hizmeti secilir. Gercek hizmet yoksa skor 0.6 altina
            // duserek elenir -> asistan yanlis demeti otomatik eklemek yerine sorar.
            $adKampanyaMi = (mb_strpos($adFold, 'kampanya') !== false)
                || (mb_strpos($adFold, 'paket') !== false);
            $kullaniciDemetIstedi = (mb_strpos($fold, 'kampanya') !== false)
                || (mb_strpos($fold, 'paket') !== false);
            if ($adKampanyaMi && !$kullaniciDemetIstedi) {
                $skor -= 0.5;
            }

            if ($skor >= 0.6) {
                $eslesen[] = [
                    'hizmet_id'      => $sh->hizmet_id,
                    'salon_hizmet_id'=> $sh->id,
                    'hizmet_adi'     => $ad,
                    'sure_dk'        => ((int) $sh->sure_dk > 0 ? (int) $sh->sure_dk : 30),
                    'fiyat'          => $sh->son_fiyat ?: $sh->baslangic_fiyat,
                    'skor'           => round($skor, 2),
                    'eslesen_kelime' => $eslesenKelime,
                ];
                $metinler[] = $adFold;
            }
        }

        // En yuksek skor; esitlikte EN COK kelime eslesen kazanir.
        usort($eslesen, function ($a, $b) {
            if ($b['skor'] !== $a['skor']) return $b['skor'] <=> $a['skor'];
            return ($b['eslesen_kelime'] ?? 0) <=> ($a['eslesen_kelime'] ?? 0);
        });

        // Ayni adli hizmet birden fazla kayitliysa tekille (en yuksek skorlusu kalir)
        $gorulen = [];
        $tekil = [];
        foreach ($eslesen as $h) {
            $anahtar = $this->fold($h['hizmet_adi']);
            if (isset($gorulen[$anahtar])) {
                continue;
            }
            $gorulen[$anahtar] = true;
            $tekil[] = $h;
        }

        return [$tekil, $metinler];
    }

    /* ------------------------------------------------------------------ */
    /* PERSONEL                                                           */
    /* ------------------------------------------------------------------ */

    protected function personelEslestir($fold, $hizmetler, $musteriIpucu = null, $tercihAd = null)
    {
        // Datif ipucundaki kelimeler MUSTERI adidir; personel eslestirmesinden haric tut
        $haric = $musteriIpucu ? preg_split('/\s+/u', $musteriIpucu) : [];

        $liste = DB::table('salon_personelleri')->where('salon_id', $this->salonId)
            ->where(function ($q) {
                $q->whereNull('arsivli')->orWhere('arsivli', '!=', 1);
            })
            ->get();

        // ACIK "personel X" etiketi: X bir personele uyuyorsa DOGRUDAN onu sec (cumledeki
        // baska adlarla karismasin). Ek-toleransli eslesme (Fatih/Fatih Gul).
        if ($tercihAd) {
            $tf = $this->fold($tercihAd);
            foreach ($liste as $p) {
                $adFold = $this->fold($p->personel_adi ?? '');
                if ($adFold === '') continue;
                $ilk = explode(' ', $adFold)[0];
                if ($adFold === $tf || $ilk === $tf
                    || mb_strpos($adFold, $tf) !== false
                    || $this->kelimeUyar($ilk, $tf)) {
                    return [[
                        'personel_id'  => $p->id,
                        'personel_adi' => $p->personel_adi,
                    ], $adFold];
                }
            }
        }

        $enIyi = null;
        $enIyiSkor = 0.0;
        $metin = null;
        foreach ($liste as $p) {
            $ad = $p->personel_adi;
            if (!$ad) {
                continue;
            }
            $adFold = $this->fold($ad);
            $ilk = explode(' ', $adFold)[0];
            // Personel adi/ilk kelimesi musteri ipucundaysa personel sayma
            if (in_array($adFold, $haric, true) || in_array($ilk, $haric, true)) {
                continue;
            }
            // Ad ya da adin ilk kelimesi cumlede geciyor mu?
            $gecti = mb_strpos($fold, $adFold) !== false
                || (mb_strlen($ilk) >= 3 && preg_match('/\b' . preg_quote($ilk, '/') . '\b/u', $fold));
            if ($gecti && mb_strlen($adFold) > $enIyiSkor) {
                $enIyi = $p;
                $enIyiSkor = mb_strlen($adFold);
                $metin = $adFold;
            }
        }

        if (!$enIyi) {
            return [null, null]; // belirtilmedi -> onay ekraninda secilir / "fark etmez"
        }

        return [[
            'personel_id'  => $enIyi->id,
            'personel_adi' => $enIyi->personel_adi,
        ], $metin];
    }

    /* ------------------------------------------------------------------ */
    /* MUSTERI                                                            */
    /* ------------------------------------------------------------------ */

    protected function musteriEslestir($ham, $fold, $hizmetMetinleri, $personelMetni, $musteriIpucu = null)
    {
        if ($musteriIpucu) {
            // Datif ipucu (Ferdi Korkmaz'a) -> dogrudan musteri adi; kalan kelime tahminine gerek yok
            $temiz = $musteriIpucu;
            $tokenlar = array_values(array_filter(preg_split('/\s+/u', $musteriIpucu), function ($t) {
                return mb_strlen(trim($t)) >= 2;
            }));
        } else {
            // Cumleden tarih/saat/hizmet/personel ve dolgu kelimeleri cikar -> geriye ad kalir.
            // Hizmet/personel adlarini KELIME KELIME de cikar: kullanici hizmeti farkli
            // sirayla/eksik soylese ("yuz lazer" -> "Lazer Epilasyon Yuz") ya da yanlis
            // eslesse bile o kelimeler musteri adina KARISMASIN (or. "lazer" -> "Irem Lazer").
            $temiz = $fold;
            $cikarKelimeler = [];
            foreach (array_merge($hizmetMetinleri, [$personelMetni]) as $cikar) {
                if (!$cikar) continue;
                $temiz = str_replace($cikar, ' ', $temiz); // bitisik gecerse toptan sil
                foreach (preg_split('/\s+/u', $cikar) as $kw) {
                    $kw = trim($kw);
                    if (mb_strlen($kw) >= 2) $cikarKelimeler[$kw] = true;
                }
            }
            foreach (array_keys($cikarKelimeler) as $kw) {
                $temiz = preg_replace('/(?:^| )' . preg_quote($kw, '/') . '(?= |$)/u', ' ', $temiz);
            }
            // saat/tarih iz birakan rakam ve kaliplari sil
            $temiz = preg_replace('/\b\d{1,2}[:.]\d{2}\b/u', ' ', $temiz);
            $temiz = preg_replace('/\b\d{1,4}[.\/]\d{1,2}([.\/]\d{2,4})?\b/u', ' ', $temiz);
            $temiz = preg_replace('/\b\d+\b/u', ' ', $temiz);
            $temiz = preg_replace('/[\'’]\S*/u', ' ', $temiz);

            $tokenlar = array_filter(preg_split('/\s+/u', $temiz), function ($t) {
                $t = trim($t);
                return mb_strlen($t) >= 2 && !in_array($t, $this->stopKelimeler, true)
                    && !array_key_exists($t, $this->gunler)
                    && !array_key_exists($t, $this->aylar);
            });
            $tokenlar = array_values($tokenlar);
        }

        if (empty($tokenlar)) {
            return [
                'ad_tahmini' => null,
                'user_id'    => null,
                'adaylar'    => [],
            ];
        }

        // Portfoyde bulanik arama
        $sorgu = DB::table('musteri_portfoy as mp')
            ->join('users as u', 'mp.user_id', '=', 'u.id')
            ->where('mp.salon_id', $this->salonId)
            ->where('mp.aktif', 1)
            ->where(function ($q) use ($tokenlar) {
                foreach ($tokenlar as $t) {
                    $q->orWhere('u.name', 'like', '%' . $t . '%');
                }
            })
            ->select('u.id', 'u.name', 'u.cep_telefon')
            ->limit(25)
            ->get();

        $adTahmini = implode(' ', $tokenlar);
        $adaylar = [];
        foreach ($sorgu as $u) {
            $nameFold = $this->fold($u->name);
            $skor = $this->benzerlik($nameFold, $this->fold($adTahmini));
            // Bir token, adin icinde TAM KELIME olarak geciyorsa skoru yukselt
            foreach ($tokenlar as $t) {
                if (mb_strlen($t) >= 3 && preg_match('/\b' . preg_quote($t, '/') . '\b/u', $nameFold)) {
                    $skor = max($skor, 0.85);
                }
            }
            if ($skor < 0.5) {
                continue; // zayif eslesme = gurultu, aday sayma
            }
            $adaylar[] = [
                'user_id'       => $u->id,
                'name'          => $u->name,
                'telefon_maske' => $this->telMaskele($u->cep_telefon),
                'skor'          => round($skor, 2),
            ];
        }
        usort($adaylar, function ($a, $b) {
            return $b['skor'] <=> $a['skor'];
        });
        $adaylar = array_slice($adaylar, 0, 5);

        // Yeterince net aday varsa otomatik sec (aksi halde onay ekraninda secilir)
        $userId = null;
        $en     = isset($adaylar[0]) ? $adaylar[0] : null;
        $ikinci = isset($adaylar[1]) ? $adaylar[1] : null;
        if ($en) {
            if (!$ikinci && $en['skor'] >= 0.6) {
                $userId = $en['user_id'];                                   // tek aday
            } elseif ($en['skor'] >= 0.95 && (!$ikinci || $en['skor'] - $ikinci['skor'] >= 0.1)) {
                $userId = $en['user_id'];                                   // net/tam eslesme
            } elseif ($en['skor'] >= 0.85 && $ikinci && $en['skor'] - $ikinci['skor'] >= 0.25) {
                $userId = $en['user_id'];                                   // digerlerinden acik ara onde
            }
        }

        return [
            'ad_tahmini' => $adTahmini,
            'user_id'    => $userId,
            'adaylar'    => $adaylar,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* DEGERLENDIRME                                                      */
    /* ------------------------------------------------------------------ */

    protected function eksikAlanlar($musteri, $hizmetler, $tarih, $saat)
    {
        $eksik = [];
        if (empty($musteri['user_id']))  $eksik[] = 'musteri';
        if (empty($hizmetler))           $eksik[] = 'hizmet';
        if (empty($tarih))               $eksik[] = 'tarih';
        if (empty($saat))                $eksik[] = 'saat';
        return $eksik;
    }

    protected function guvenHesapla($intent, $musteri, $hizmetler, $tarih, $saat)
    {
        if ($intent !== 'randevu_olustur') {
            return 'dusuk';
        }
        $puan = 0;
        if (!empty($musteri['user_id'])) $puan++;
        elseif (!empty($musteri['adaylar'])) $puan += 0.5;
        if (!empty($hizmetler)) $puan++;
        if (!empty($tarih))     $puan++;
        if (!empty($saat))      $puan++;

        if ($puan >= 3.5) return 'yuksek';
        if ($puan >= 2)   return 'orta';
        return 'dusuk';
    }

    /* ------------------------------------------------------------------ */
    /* YARDIMCILAR                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Karsilastirma icin sadelestir: UTF-8'e cevir + Turkce harfleri (buyuk/kucuk)
     * ASCII'ye indir + kucuk harf + ASCII disi temizle.
     * Turkce eslemesi \u{} kacislariyla yapilir -> kaynak dosya encoding'inden bagimsiz.
     */
    protected function fold($s)
    {
        $s = (string) $s;
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1254');
        }
        $s = strtr($s, [
            "\u{00C7}" => 'c', "\u{00E7}" => 'c', // Ç ç
            "\u{011E}" => 'g', "\u{011F}" => 'g', // Ğ ğ
            "\u{0130}" => 'i', "\u{0131}" => 'i', // İ ı
            "\u{00D6}" => 'o', "\u{00F6}" => 'o', // Ö ö
            "\u{015E}" => 's', "\u{015F}" => 's', // Ş ş
            "\u{00DC}" => 'u', "\u{00FC}" => 'u', // Ü ü
        ]);
        $s = mb_strtolower($s, 'UTF-8');                              // kalan ASCII buyuk harfler
        $s = preg_replace('/[^a-z0-9:.\/\x{2019}\x{27}\s]/u', ' ', $s); // artik sadece ASCII bekliyoruz
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /** 0..1 arasi benzerlik (similar_text yuzdesi) */
    protected function benzerlik($a, $b)
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        similar_text($a, $b, $yuzde);
        return $yuzde / 100;
    }

    /**
     * ES ANLAMLI SOZLUK: ayni hizmetin FARKLI soyleyislerini ortak koke indirger.
     * ONEMLI: SADECE ayni hizmet birlestirilir. Farkli hizmetler (rofle/balyaj/ombre,
     * dip boya/komple boya, boyama/yikama) ASLA burada birlestirilmez -> onlar
     * kendi kelimeleriyle (rofle, balyaj, dip, komple...) zaten ayrisir.
     * Yeni kelime cikarsa buraya 1 satir eklemek yeterli.
     */
    protected $esAnlamli = [
        // BOYAMA (renk verme) — dip/komple KELIMESI hizmeti ayirir, bunlar birlesmez
        'renk' => 'boya', 'renkli' => 'boya', 'renklendirme' => 'boya',
        'renklendir' => 'boya', 'boyat' => 'boya', 'boyatma' => 'boya',
        'boyatmak' => 'boya', 'boyama' => 'boya', 'boyasi' => 'boya', 'boyanma' => 'boya',
        // KESIM
        'kestir' => 'kesim', 'kestirme' => 'kesim', 'kestirmek' => 'kesim',
        'kesme' => 'kesim', 'kesimi' => 'kesim',
        // YIKAMA
        'yika' => 'yikama', 'yikat' => 'yikama', 'yikatma' => 'yikama',
        'yikatmak' => 'yikama', 'yikanma' => 'yikama',
    ];

    /** Kelimeyi es anlamli sozlukten kanonik forma cevirir (yoksa aynen doner). */
    protected function kanonik($w)
    {
        return isset($this->esAnlamli[$w]) ? $this->esAnlamli[$w] : $w;
    }

    /**
     * Iki kelime (fold'lanmis) ayni HIZMETI mi ifade ediyor? TURKCE EK toleransli:
     * dogal konusma "sac boyamasi / sacimi boyatmak / boya yaptirmak" da hizmetteki
     * "sac boyama" ile eslessin diye.
     *  1) Birebir ayni
     *  2) Biri digerinin ONEKI (>=3 harf): sac/sacimi, boyama/boyamasi, kesim/kesimi
     *  3) Ortak ON EK govdesi >=4: boyama/boyatmak -> "boya", rofle/roflelerim
     *  4) Genel yazim benzerligi (typo) >=0.80
     */
    protected function kelimeUyar($a, $b)
    {
        if ($a === '' || $b === '') return false;
        // Once es anlamli koke cevir: "renk"->"boya", "kestir"->"kesim" -> sonra karsilastir.
        $a = $this->kanonik($a);
        $b = $this->kanonik($b);
        if ($a === $b) return true;
        $kisa = min(mb_strlen($a), mb_strlen($b));
        if ($kisa >= 3 && (mb_strpos($a, $b) === 0 || mb_strpos($b, $a) === 0)) {
            return true;
        }
        if ($this->ortakOnek($a, $b) >= 4) {
            return true;
        }
        return $this->benzerlik($a, $b) >= 0.80;
    }

    /** Iki kelimenin bastan ortak (ayni) harf sayisi. */
    protected function ortakOnek($a, $b)
    {
        $n = min(mb_strlen($a), mb_strlen($b));
        $i = 0;
        while ($i < $n && mb_substr($a, $i, 1) === mb_substr($b, $i, 1)) {
            $i++;
        }
        return $i;
    }

    protected function telMaskele($tel)
    {
        $tel = preg_replace('/\D/', '', (string) $tel);
        if (mb_strlen($tel) < 4) {
            return null;
        }
        return str_repeat('*', mb_strlen($tel) - 4) . mb_substr($tel, -4);
    }

    protected function guvenliTarih($yil, $ay, $gun)
    {
        $yil = (int) $yil;
        $ay  = (int) $ay;
        $gun = (int) $gun;
        if (!checkdate($ay, $gun, $yil)) {
            return null;
        }
        return Carbon::createFromDate($yil, $ay, $gun)->toDateString();
    }

    protected function yilNormalize($y)
    {
        $y = (int) $y;
        return $y < 100 ? 2000 + $y : $y;
    }
}
