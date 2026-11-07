<?php

namespace App\Services\Seslendirme;

/**
 * Google Cloud Text-to-Speech ile metni MP3'e cevirir ve SUNUCUDA ONBELLEGE alir.
 *
 * Mantik:
 *  - Ayni metin (ayni ses) daha once uretildiyse tekrar Google'a gidilmez -> dosya
 *    dogrudan doner. Bilgilendirme cevaplari SABIT oldugu icin tum salonlarda ORTAK
 *    cache; pratikte tek seferlik uretim -> maliyet ~0.
 *  - Anahtar yoksa null doner; cagiran taraf cihaz TTS'ine duser (regresyon yok).
 *
 * Dosyalar: storage/app/tts/<hash>.mp3
 */
class SeslendirmeServisi
{
    protected $klasor;

    public function __construct()
    {
        $this->klasor = storage_path('app/tts');
        if (!is_dir($this->klasor)) {
            @mkdir($this->klasor, 0775, true);
        }
    }

    /**
     * Metni sese cevirir. Cache'de varsa uretmeden dosya ADINI doner (<hash>.mp3).
     * Basarisizsa null (cagiran cihaz TTS'ine duser).
     */
    public function uret($metin, $ses = null)
    {
        $metin = trim((string) $metin);
        if ($metin === '') return null;

        $ses = $ses ?: (string) config('services.google_tts.voice', 'tr-TR-Wavenet-E');
        $okunacak = $this->okunusHazirla($metin);
        if ($okunacak === '') return null;

        $hash = md5($ses . '|' . $okunacak);
        $ad = $hash . '.mp3';
        $yol = $this->klasor . '/' . $ad;

        // Cache hit
        if (is_file($yol) && filesize($yol) > 0) return $ad;

        $mp3 = $this->googleUret($okunacak, $ses);
        if ($mp3 === null || $mp3 === '') return null;

        @file_put_contents($yol, $mp3);

        return (is_file($yol) && filesize($yol) > 0) ? $ad : null;
    }

    /** Dosya adindan tam yol (guvenlik: sadece basename). Yoksa null. */
    public function dosyaYolu($ad)
    {
        $ad = basename((string) $ad);
        if (!preg_match('/^[a-f0-9]{32}\.mp3$/', $ad)) return null;
        $yol = $this->klasor . '/' . $ad;
        return is_file($yol) ? $yol : null;
    }

    /**
     * Google Cloud TTS. Once istenen ses (varsayilan Chirp3-HD) denenir; 400/hata
     * gelirse (orn. proje Chirp3-HD'yi desteklemiyor) fallback sese (Wavenet) duser
     * -> cagride sessizlik olmaz. Basarisizsa null.
     */
    protected function googleUret($metin, $ses)
    {
        $key = (string) config('services.google_tts.key', '');
        if ($key === '') return null;

        $rate = (float) config('services.google_tts.rate', 1.0);

        $bin = $this->googleSentez($metin, $ses, $rate, $key);
        if ($bin !== null) return $bin;

        // Fallback: istenen ses (Chirp3-HD) uretilemedi -> Wavenet vb. dene.
        $fallback = (string) config('services.google_tts.voice_fallback', '');
        if ($fallback !== '' && strcasecmp($fallback, $ses) !== 0) {
            $bin = $this->googleSentez($metin, $fallback, $rate, $key);
        }
        return $bin;
    }

    /** Tek bir Google TTS sentez cagrisi (belirli ses). Basarisizsa null. */
    protected function googleSentez($metin, $ses, $rate, $key)
    {
        $url = 'https://texttospeech.googleapis.com/v1/text:synthesize?key=' . urlencode($key);

        $audioConfig = ['audioEncoding' => 'MP3', 'speakingRate' => $rate];
        // Chirp3-HD pitch DESTEKLEMEZ (400 verir); yalnizca Wavenet/Standard'da gonder.
        if (stripos($ses, 'Chirp') === false) {
            $audioConfig['pitch'] = 0.0;
        }

        $govde = json_encode([
            'input'       => ['text' => $metin],
            'voice'       => ['languageCode' => 'tr-TR', 'name' => $ses],
            'audioConfig' => $audioConfig,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_POSTFIELDS     => $govde,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $res = curl_exec($ch);
        $kod = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($kod !== 200 || !$res) return null;
        $j = json_decode($res, true);
        if (empty($j['audioContent'])) return null;
        $bin = base64_decode($j['audioContent']); // strict degil: Google'a guveniyoruz
        return ($bin !== false && $bin !== '') ? $bin : null;
    }

    /**
     * Okunusu hazirlar: (1) marka terimlerini fonetik yaz (Hydrafacial -> Hidrafeşıl),
     * (2) saat "14:30" -> "on dort otuz", (3) kalan rakamlari yaziya cevir. Boylece
     * TTS rakamlari/markalari dogru okur.
     */
    protected function okunusHazirla($metin)
    {
        $metin = (string) $metin;
        // Gecersiz UTF-8 ise onar; aksi halde /u regex NULL doner ve cevap KAYBOLUR.
        if (!mb_check_encoding($metin, 'UTF-8')) {
            $metin = @mb_convert_encoding($metin, 'UTF-8', 'UTF-8');
        }
        $m = ' ' . trim($metin) . ' ';

        // Her adimda NULL gelirse onceki degeri koru (asla cevabi kaybetme).
        // 1) Marka/yabanci terimler (Turkce TTS yanlis okuyor)
        $t = preg_replace('/hydra\s*facial/iu', 'Hidrafeşıl', $m); if ($t !== null) $m = $t;
        $t = preg_replace('/hydrafacial/iu', 'Hidrafeşıl', $m);   if ($t !== null) $m = $t;

        // 2) Saat HH:MM -> yaziyla
        $t = preg_replace_callback('/\b([01]?\d|2[0-3]):([0-5]\d)\b/u', function ($x) {
            $s = $this->sayiYazi((int) $x[1]);
            $d = ((int) $x[2]) > 0 ? ' ' . $this->sayiYazi((int) $x[2]) : '';
            return $s . $d;
        }, $m);
        if ($t !== null) $m = $t;

        // 3a) Binlik ayiracli sayilar (Turkce nokta): "13.500" -> 13500,
        //     "1.250.000" -> 1250000. Once bunlari coz, sonra yaziya cevir.
        $t = preg_replace_callback('/\b\d{1,3}(?:\.\d{3})+\b/u', function ($x) {
            return $this->sayiYazi((int) str_replace('.', '', $x[0]));
        }, $m);
        if ($t !== null) $m = $t;

        // 3b) Ondalik (virgul): "13,5" -> "on uc virgul bes"
        $t = preg_replace_callback('/\b(\d+),(\d+)\b/u', function ($x) {
            return $this->sayiYazi((int) $x[1]) . ' virgül ' . $this->sayiYazi((int) $x[2]);
        }, $m);
        if ($t !== null) $m = $t;

        // 3c) Kalan tam sayilar -> yaziya
        $t = preg_replace_callback('/\d+/u', function ($x) {
            return $this->sayiYazi((int) $x[0]);
        }, $m);
        if ($t !== null) $m = $t;

        $t = preg_replace('/\s+/u', ' ', $m); if ($t !== null) $m = $t;
        $m = trim($m);

        // Emniyet: bir sekilde bosaldiysa orijinal metne don.
        return $m !== '' ? $m : trim((string) $metin);
    }

    /** Tam sayiyi Turkce yaziya cevirir (milyar'a kadar; ustu nadir -> oldugu gibi). */
    protected function sayiYazi($n)
    {
        if ($n === 0) return 'sıfır';
        $on = '';
        if ($n < 0) { $on = 'eksi '; $n = -$n; }
        if ($n >= 1000000000000) return $on . (string) $n; // trilyon ustu cok nadir

        $gruplar = [1000000000 => 'milyar', 1000000 => 'milyon', 1000 => 'bin', 1 => ''];
        $out = '';
        foreach ($gruplar as $bol => $ad) {
            $adet = intdiv($n, $bol);
            $n = $n % $bol;
            if ($adet <= 0) continue;
            // "bir bin" DEGIL, sadece "bin"; ama "bir milyon"/"bir milyar" DOGRU.
            if ($bol === 1000 && $adet === 1) {
                $out .= 'bin ';
            } else {
                $out .= $this->ucBasamak($adet) . ($ad !== '' ? ' ' . $ad . ' ' : ' ');
            }
        }
        return $on . trim(preg_replace('/\s+/', ' ', $out));
    }

    protected function ucBasamak($n)
    {
        $birler = ['', 'bir', 'iki', 'üç', 'dört', 'beş', 'altı', 'yedi', 'sekiz', 'dokuz'];
        $onlar  = ['', 'on', 'yirmi', 'otuz', 'kırk', 'elli', 'altmış', 'yetmiş', 'seksen', 'doksan'];
        $out = '';
        $yuz = intdiv($n, 100);
        $k   = $n % 100;
        if ($yuz > 0) $out .= (($yuz === 1) ? 'yüz' : $birler[$yuz] . ' yüz') . ' ';
        $o = intdiv($k, 10);
        $b = $k % 10;
        if ($o > 0) $out .= $onlar[$o] . ' ';
        if ($b > 0) $out .= $birler[$b] . ' ';
        return trim($out);
    }
}
