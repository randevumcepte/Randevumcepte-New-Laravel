<?php

namespace App\Services\PatronAsistan;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PATRON ASISTANI — niyet cozumleme + dogal dil cevap yazimi (KURAL MOTORU).
 *
 * Mimari (restoran copilot'undan tasindi):
 *   1) Patron sesli/yazili SERBEST konusur ("bugun kasa ne durumda", "en cok kim
 *      sattı", "ahmet ne yaptı bu ay"). Sesli ise cihazda STT ile metne cevrilir.
 *   2) Bu servis niyeti cozer  ->  { intent, donem }  (LLM YOK, sifir maliyet).
 *   3) StoreAdminController MEVCUT rapor fonksiyonunu cagirir (yetki + rakam ORADAN;
 *      rakami ASLA bu servis uretmez — halusinasyon olmasin diye).
 *   4) Donen gercek veriyi bu servis dogal Turkce cevaba cevirir (+ opsiyonel kart).
 *
 * Kural: AI/kural KARAR verir (hangi rapor), mevcut kod HESAPLAR (rakam),
 *        bu servis SUNAR (cevap metni). Ileride niyet cozumu Haiku'ya alinabilir;
 *        cevap + veri katmani aynen kalir.
 */
class PatronAsistanServisi
{
    public function __construct(private PatronAsistanAi $ai) {}

    /** Desteklenen niyetler icin anahtar kelimeler (ASCII-normalize edilmis metinde aranir) */
    protected $niyetAnahtarlari = [
        'kasa' => [
            'kasa', 'ciro', 'hasilat', 'kazanc', 'kazandik', 'kazandim', 'kazanmis',
            'ne kadar kazan', 'kac para', 'para girdi', 'ne kadar para', 'ne kadar oldu',
            'tahsilat', 'nakit', 'gelir', 'topladik', 'kac lira', 'lira topla',
        ],
        'personel' => [
            'personel', 'eleman', 'calisan', 'kim satti', 'en cok kim', 'en iyi personel',
            'hangi personel', 'performans', 'hakedis', 'kim ne kadar', 'kim ne satti',
            'kim calisti', 'basarili',
            // En DUSUK performans sorgulari da personel niyetine gitsin.
            'en dusuk', 'en az', 'en kotu', 'en zayif', 'dusuk performans', 'az satan',
            'az calisan', 'en dip', 'en kotu personel',
        ],
        'hizmet' => [
            'hizmet', 'islem', 'hangi hizmet', 'populer hizmet', 'en cok yapilan',
            'cok yapilan', 'hizmet raporu', 'hangi islem',
        ],
        'urun' => [
            'urun', 'hangi urun', 'satan urun', 'urun satis', 'mamul', 'kozmetik',
        ],
        'musteri' => [
            'musteri', 'kac kisi', 'kac musteri', 'yeni musteri', 'kim geldi',
            'kisi geldi', 'sadik musteri', 'en iyi musteri', 'kadin erkek', 'musteri sayisi',
        ],
        'ozet' => [
            'ozet', 'genel durum', 'gun sonu', 'nasil gidiyor', 'nasil gecti',
            'isler nasil', 'toplam durum', 'bugun nasil', 'yolunda', 'bereket',
            'ne alemde', 'gunumuz nasil', 'nasil bir gun',
        ],
        'iptal' => [
            'iptal', 'iptaller', 'iptal olan', 'iptal edilen', 'iptal randevu', 'kac iptal',
            'iptal edilmis', 'gelmedi', 'gelmeyen', 'gelmeyenler', 'gelinmeyen', 'kim gelmedi',
            'no show', 'gelmeyen randevu', 'gelmeyen musteri', 'kacan randevu',
        ],
        'saat' => [
            'calisma saat', 'calisma saatleri', 'calisma gunleri', 'mesai', 'kacta acil',
            'kacta kapan', 'kacta acik', 'kaca kadar acik', 'acik misiniz', 'acik misin',
            'aciksiniz', 'kapali misiniz', 'acik miyiz', 'hangi saatler', 'hangi gunler acik',
            'saatleriniz', 'acilis saati', 'kapanis saati', 'ne zaman aciksiniz', 'ne zaman kapali',
            'pazar acik', 'bugun acik', 'su an acik', 'acik mi',
        ],
        'bugun' => [
            'randevu', 'takvim', 'gundem', 'kimler var', 'kimler gelecek', 'kim gelecek',
            'kac randevu', 'randevu var', 'gunluk program',
        ],
    ];

    /**
     * Serbest metinden niyeti + donemi cozer.
     * @return array{intent:string,donem:string,donemAdi:string,personelIpucu:?string,ham:string}
     */
    public function niyetCoz($metin)
    {
        $ham  = trim((string) $metin);
        $norm = $this->normalize($ham);

        $donem = $this->donemCoz($norm);

        // Niyet: anahtar kelime eslesme sayisina gore en yuksek skorlu niyet secilir.
        $skor = array_fill_keys(array_keys($this->niyetAnahtarlari), 0);
        foreach ($this->niyetAnahtarlari as $niyet => $kelimeler) {
            foreach ($kelimeler as $k) {
                if (strpos($norm, $this->normalize($k)) !== false) {
                    $skor[$niyet]++;
                }
            }
        }
        // En yuksek skorlu niyet; esitlikte oncelik sirasina gore (deterministik).
        // NOT: personel, kasa'dan ONCE -> "en dusuk CIRO yapan PERSONEL" gibi cumlelerde
        // 'ciro'(kasa) ile 'personel' esit skorlanir; personel kazanmali.
        $oncelik = ['saat', 'iptal', 'personel', 'kasa', 'hizmet', 'urun', 'musteri', 'bugun', 'ozet'];
        $enIyi = 'bilinmiyor'; $enYuksek = 0;
        foreach ($oncelik as $n) {
            if (($skor[$n] ?? 0) > $enYuksek) {
                $enYuksek = $skor[$n];
                $enIyi = $n;
            }
        }
        $intent = $enYuksek > 0 ? $enIyi : 'bilinmiyor';

        // KOK NEDEN FIX: Lone buyuk-harfli kelimeyi personel ADI sanip niyeti personele
        // YUKSELTMEYI biraktik. STT her cumlenin ilk harfini buyuttugu icin "Sen kimsin",
        // "Isleri buyutmem lazim", "Adin ne" gibi cumlelerde ilk kelime ("Sen"/"Isleri")
        // personel sanilip yanlis ciro donuyordu. Artik personel ipucu YALNIZ intent
        // zaten personelken ("hangi kisi") kullanilir; belirsiz cumleyi AI dogru
        // kategoriye koyar (kasa/personel/.../oneri) ya da sohbete duser.
        $personelIpucu = ($intent === 'personel') ? $this->personelIpucuCoz($norm, $ham) : null;

        return [
            'intent'        => $intent,
            'donem'         => $donem['anahtar'],
            'donemAdi'      => $donem['ad'],
            'personelIpucu' => $personelIpucu,
            'ham'           => $ham,
        ];
    }

    /** Metinden zaman araligini cozer (varsayilan: bugun). */
    protected function donemCoz($norm)
    {
        if (preg_match('/\b(bu ?ay|aylik|son ?30|gecen ?ay|ay ?boyunca)\b/', $norm)) {
            return ['anahtar' => 'ay', 'ad' => 'bu ay'];
        }
        if (preg_match('/\b(bu ?hafta|haftalik|son ?7|hafta ?boyunca)\b/', $norm)) {
            return ['anahtar' => 'hafta', 'ad' => 'bu hafta'];
        }
        if (preg_match('/\b(dun)\b/', $norm)) {
            // Kasa/personel raporlari "dun"u ayri tutmuyor; en yakin bugun'e duser.
            return ['anahtar' => 'gun', 'ad' => 'bugün'];
        }
        return ['anahtar' => 'gun', 'ad' => 'bugün'];
    }

    /**
     * Personel adi ipucu: anahtar kelimeler cikarildiktan sonra kalan, buyuk harfle
     * baslayan olasi isim. Kesin eslestirme controller'da gercek personel listesiyle
     * yapilir; burada sadece aday dondururuz.
     */
    protected function personelIpucuCoz($norm, $ham)
    {
        $stop = ['bugun','bu','ay','hafta','ne','kadar','sattı','satti','yaptı','yapti',
                 'kim','en','cok','satis','kasa','ciro','personel','eleman','performans',
                 'gosterir','misin','bana','ver','getir','durum','nasil','gidiyor','ki',
                 'da','de','icin','the','a','an',
                 // Sohbet/kimlik zamirleri: personel ADI sanilmasin ("Sen"->"Esen").
                 'sen','senin','sensin','ben','benim','biz','siz','adin','ismin','kimsin','nesin'];
        $kelimeler = preg_split('/\s+/', trim($ham));
        foreach ($kelimeler as $kel) {
            $temiz = preg_replace('/[^\p{L}]/u', '', $kel);
            if ($temiz === '') continue;
            $normKel = $this->normalize($temiz);
            if (in_array($normKel, $stop, true)) continue;
            if (mb_strlen($temiz, 'UTF-8') < 3) continue;
            // Ilk harfi buyukse ya da metinde tek basina bir ozel ad gibi duruyorsa aday.
            $ilk = mb_substr($temiz, 0, 1, 'UTF-8');
            if (mb_strtoupper($ilk, 'UTF-8') === $ilk) {
                return $temiz;
            }
        }
        return null;
    }


    // ------------------------------------------------------------------
    // CEVAP YAZIMI — gelen GERCEK veriyi dogal Turkce metne cevirir
    // ------------------------------------------------------------------

    /** Kasa/ciro cevabi. $veri = dashboardKasa JSON (nakit,kart,havale,diger,toplam). */
    public function cevapKasa(array $veri, array $niyet)
    {
        $toplam = (float) ($veri['toplam'] ?? 0);
        $nakit  = (float) ($veri['nakit'] ?? 0);
        $kart   = (float) ($veri['kart'] ?? 0);
        $havale = (float) ($veri['havale'] ?? 0);
        $donemAdi = $niyet['donemAdi'];

        if ($toplam <= 0) {
            $cevap = ucfirst($donemAdi) . " için henüz bir tahsilat görünmüyor.";
        } else {
            $cevap = ucfirst($donemAdi) . " toplam " . $this->tl($toplam) . " tahsilat gerçekleşmiş. "
                   . "Nakit olarak " . $this->tl($nakit) . ", kart ile " . $this->tl($kart) . " alınmış";
            if ($havale > 0) {
                $cevap .= ", ayrıca " . $this->tl($havale) . " havale ile ödenmiş";
            }
            $cevap .= ".";
        }

        // Kart KALDIRILDI: rakamlar zaten cevap metninde (okunuyor) — kart ayni veriyi
        // tekrarlayip "iki kere" gostermesin diye tek kaynak: metin.
        return [
            'basarili' => true,
            'intent'   => 'kasa',
            'cevap'    => $cevap,
            'seslendir'=> true,
            'kart'     => null,
            'niyet'    => $niyet,
        ];
    }

    /** Personel performans cevabi. $veri = isletmeRaporlariPersonel JSON (personeller[]). */
    public function cevapPersonel(array $veri, array $niyet)
    {
        $liste = $veri['personeller'] ?? [];
        $donemAdi = $niyet['donemAdi'];

        // "en dusuk / en az / en kotu ..." -> en dusuk performansli personel istenmis.
        $ham = $this->normalize($niyet['ham'] ?? '');
        $dusuk = (strpos($ham, 'en dusuk') !== false || strpos($ham, 'en az') !== false
            || strpos($ham, 'en kotu') !== false || strpos($ham, 'en zayif') !== false
            || strpos($ham, 'dusuk performans') !== false || strpos($ham, 'az satan') !== false
            || strpos($ham, 'az calisan') !== false || strpos($ham, 'en dip') !== false);

        // Belirli personel soruldu mu?
        if (!empty($niyet['personelIpucu'])) {
            $aday = $this->normalize($niyet['personelIpucu']);
            $bulunan = null; $sira = 0;
            foreach ($liste as $i => $p) {
                if (strpos($this->normalize($p['personel_adi'] ?? ''), $aday) !== false) {
                    $bulunan = $p; $sira = $i + 1; break;
                }
            }
            if ($bulunan) {
                $cevap = $bulunan['personel_adi'] . ", " . $donemAdi . " toplam "
                       . $this->tl((float) $bulunan['ciro']) . " ciro yapmış ve "
                       . (int) $bulunan['hizmet_say'] . " işlem gerçekleştirmiş. "
                       . "Genel sıralamada " . $sira . ". sırada yer alıyor.";
                $yorum = $this->personelYorumAI($bulunan, $donemAdi, count($liste), $sira, false);
                if ($yorum) $cevap .= ' ' . $yorum;
                return [
                    'basarili' => true, 'intent' => 'personel', 'cevap' => $cevap,
                    'seslendir' => true,
                    'kart' => [
                        'tip' => 'personel_tek',
                        'baslik' => $bulunan['personel_adi'] . ' · ' . ucfirst($donemAdi),
                        'ciro' => (float) $bulunan['ciro'],
                        'islem' => (int) $bulunan['hizmet_say'],
                        'sira' => $sira,
                    ],
                    'niyet' => $niyet,
                ];
            }
        }

        if (empty($liste)) {
            return [
                'basarili' => true, 'intent' => 'personel', 'seslendir' => true,
                'cevap' => ucfirst($donemAdi) . " için henüz bir personel satışı görünmüyor.",
                'kart' => null, 'niyet' => $niyet,
            ];
        }

        // EN DUSUK performans: liste ciroya gore AZALAN sirali -> son eleman en dusuk.
        if ($dusuk) {
            $enDusuk = $liste[count($liste) - 1];
            $sira    = count($liste);
            $ciro    = (float) ($enDusuk['ciro'] ?? 0);
            $islem   = (int) ($enDusuk['hizmet_say'] ?? 0);

            if ($ciro <= 0 && $islem <= 0) {
                $cevap = ucfirst($donemAdi) . " en düşük performans " . $enDusuk['personel_adi']
                       . " tarafında; bu dönem hiç işlem yapmamış görünüyor.";
            } else {
                $cevap = ucfirst($donemAdi) . " en düşük performansı " . $enDusuk['personel_adi']
                       . " gösteriyor; toplam " . $this->tl($ciro) . " ciro ve " . $islem . " işlem.";
            }
            $yorum = $this->personelYorumAI($enDusuk, $donemAdi, count($liste), $sira, true);
            if ($yorum) $cevap .= ' ' . $yorum;

            return [
                'basarili' => true, 'intent' => 'personel', 'cevap' => $cevap, 'seslendir' => true,
                'kart' => [
                    'tip' => 'personel_tek',
                    'baslik' => $enDusuk['personel_adi'] . ' · ' . ucfirst($donemAdi),
                    'ciro' => $ciro, 'islem' => $islem, 'sira' => $sira,
                ],
                'niyet' => $niyet,
            ];
        }

        $ilk3 = array_slice($liste, 0, 3);
        $siralar = ['ilk sırada', 'ikinci sırada', 'üçüncü sırada'];
        $parcalar = [];
        foreach ($ilk3 as $i => $p) {
            $parcalar[] = $siralar[$i] . " " . $p['personel_adi'] . ", " . $this->tl((float) $p['ciro']);
        }
        $cevap = ucfirst($donemAdi) . " en çok ciro yapan personeller; " . implode(", ", $parcalar) . ".";

        return [
            'basarili' => true, 'intent' => 'personel', 'cevap' => $cevap, 'seslendir' => true,
            'kart' => [
                'tip'    => 'personel_sirali',
                'baslik' => 'Personel · ' . ucfirst($donemAdi),
                'satirlar' => array_map(function ($p) {
                    return [
                        'ad'    => $p['personel_adi'],
                        'ciro'  => (float) $p['ciro'],
                        'islem' => (int) ($p['hizmet_say'] ?? 0),
                    ];
                }, $liste),
            ],
            'niyet' => $niyet,
        ];
    }

    /** Gunun ozeti cevabi. $veri = dashboardBugun JSON (liste[]). */
    public function cevapBugun(array $veri, array $niyet)
    {
        $liste = $veri['liste'] ?? [];
        $adet = count($liste);

        if ($adet === 0) {
            $cevap = "Bugün için planlanmış bir randevu görünmüyor.";
        } else {
            $ilk = $liste[0];
            $cevap = "Bugün toplam " . $adet . " randevu bulunuyor. Sıradaki randevu saat "
                   . ($ilk['saat'] ?? '') . ", " . ($ilk['musteri'] ?? '')
                   . (!empty($ilk['hizmet']) ? ", " . $ilk['hizmet'] . " için" : "") . ".";
        }

        return [
            'basarili' => true, 'intent' => 'bugun', 'cevap' => $cevap, 'seslendir' => true,
            'kart' => [
                'tip'    => 'bugun',
                'baslik' => 'Bugün · ' . $adet . ' randevu',
                'liste'  => $liste,
            ],
            'niyet' => $niyet,
        ];
    }

    /**
     * Belirli DONEM icin randevu SAYISI cevabi ("bu hafta/bu ay kaç randevu").
     * $veri = ozet JSON (toplam_randevu vb.). "bugun" disindaki donemlerde kullanilir.
     */
    public function cevapRandevuDonem(array $veri, array $niyet)
    {
        $donemAdi = $niyet['donemAdi'];
        $adet = (int) ($veri['toplam_randevu'] ?? 0);
        $cevap = $adet > 0
            ? ucfirst($donemAdi) . " toplam " . $adet . " randevu bulunuyor."
            : ucfirst($donemAdi) . " için planlanmış bir randevu görünmüyor.";

        return [
            'basarili' => true, 'intent' => 'bugun', 'cevap' => $cevap, 'seslendir' => true,
            'kart' => [
                'tip' => 'ozet',
                'baslik' => 'Randevular · ' . ucfirst($donemAdi),
                'toplam_randevu' => $adet,
                'toplam_adisyon' => (int) ($veri['toplam_adisyon'] ?? 0),
                'toplam_gelir' => (float) ($veri['toplam_gelir'] ?? 0),
                'nakit' => (float) ($veri['nakit'] ?? 0),
                'kart' => (float) ($veri['kart'] ?? 0),
            ],
            'niyet' => $niyet,
        ];
    }

    /** Hizmet karlilik cevabi. $veri = isletmeRaporlariHizmet JSON. */
    public function cevapHizmet(array $veri, array $niyet)
    {
        $liste = $veri['hizmetler'] ?? [];
        $donemAdi = $niyet['donemAdi'];
        if (empty($liste)) {
            return $this->basitCevap('hizmet', ucfirst($donemAdi) . " için henüz bir hizmet kaydı görünmüyor.", $niyet);
        }
        $ilk3 = array_slice($liste, 0, 3);
        $siralar = ['ilk sırada', 'ikinci sırada', 'üçüncü sırada'];
        $parcalar = [];
        foreach ($ilk3 as $i => $h) {
            $ad = is_array($h) ? ($h['hizmet_adi'] ?? '') : ($h->hizmet_adi ?? '');
            $ciro = is_array($h) ? ($h['ciro'] ?? 0) : ($h->ciro ?? 0);
            $parcalar[] = $siralar[$i] . " " . $ad . ", " . $this->tl((float) $ciro);
        }
        $cevap = ucfirst($donemAdi) . " en çok kazandıran hizmetler; " . implode(", ", $parcalar) . ".";
        return [
            'basarili' => true, 'intent' => 'hizmet', 'cevap' => $cevap, 'seslendir' => true,
            'kart' => ['tip' => 'hizmet', 'baslik' => 'Hizmet · ' . ucfirst($donemAdi), 'satirlar' => $liste],
            'niyet' => $niyet,
        ];
    }

    /** Urun karlilik cevabi. $veri = isletmeRaporlariUrun JSON. */
    public function cevapUrun(array $veri, array $niyet)
    {
        $liste = $veri['urunler'] ?? [];
        $donemAdi = $niyet['donemAdi'];
        if (empty($liste)) {
            return $this->basitCevap('urun', ucfirst($donemAdi) . " için henüz bir ürün satışı görünmüyor.", $niyet);
        }
        $ilk3 = array_slice($liste, 0, 3);
        $siralar = ['ilk sırada', 'ikinci sırada', 'üçüncü sırada'];
        $parcalar = [];
        foreach ($ilk3 as $i => $u) {
            $ad = is_array($u) ? ($u['urun_adi'] ?? '') : ($u->urun_adi ?? '');
            $ciro = is_array($u) ? ($u['ciro'] ?? 0) : ($u->ciro ?? 0);
            $parcalar[] = $siralar[$i] . " " . $ad . ", " . $this->tl((float) $ciro);
        }
        $cevap = ucfirst($donemAdi) . " en çok satılan ürünler; " . implode(", ", $parcalar) . ".";
        return [
            'basarili' => true, 'intent' => 'urun', 'cevap' => $cevap, 'seslendir' => true,
            'kart' => ['tip' => 'urun', 'baslik' => 'Ürün · ' . ucfirst($donemAdi), 'satirlar' => $liste],
            'niyet' => $niyet,
        ];
    }

    /** Musteri ozeti cevabi. $veri = isletmeRaporlariMusteri JSON. */
    public function cevapMusteri(array $veri, array $niyet)
    {
        $donemAdi = $niyet['donemAdi'];
        $aktif = (int) ($veri['toplam_aktif'] ?? 0);
        $yeni  = (int) ($veri['yeni_musteri'] ?? 0);
        $tekrar = (int) ($veri['tekrar_gelen'] ?? 0);
        if ($aktif === 0) {
            return $this->basitCevap('musteri', ucfirst($donemAdi) . " için henüz bir müşteri hareketi görünmüyor.", $niyet);
        }
        $cevap = ucfirst($donemAdi) . " toplam " . $aktif . " müşteri gelmiş. Bunların "
               . $yeni . " tanesi yeni, " . $tekrar . " tanesi tekrar gelen müşteri.";
        return [
            'basarili' => true, 'intent' => 'musteri', 'cevap' => $cevap, 'seslendir' => true,
            'kart' => [
                'tip' => 'musteri', 'baslik' => 'Müşteri · ' . ucfirst($donemAdi),
                'toplam_aktif' => $aktif, 'yeni' => $yeni, 'tekrar' => $tekrar,
                'cinsiyet' => $veri['cinsiyet'] ?? null,
            ],
            'niyet' => $niyet,
        ];
    }


    /**
     * ZENGIN GUN OZETI: kasa + en cok satilan hizmet + (varsa) urun + en cok islem
     * yapan personel + samimi yorum. Hepsi tek cevapta. $veri controller'da toplanir:
     *   ['kasa'=>[toplam,nakit,kart], 'enHizmet'=>[ad,adet,ciro]|null,
     *    'enUrun'=>[ad,ciro]|null, 'enPersonel'=>[ad,islem]|null]
     */
    public function cevapGunOzeti(array $veri, array $niyet)
    {
        $donemAdi = $niyet['donemAdi'];
        $kasa = $veri['kasa'] ?? [];
        $toplam = (float) ($kasa['toplam'] ?? 0);

        // Duzgun, tam kurulmus cumleler (her metrik ayri cumle -> TTS dogal/vurgulu okur).
        $cumleler = [];
        if ($toplam > 0) {
            $cumleler[] = ucfirst($donemAdi) . " kasada toplam " . $this->tl($toplam) . " bulunuyor";
        } else {
            $cumleler[] = ucfirst($donemAdi) . " kasada henüz bir hareket görünmüyor";
        }
        if (!empty($veri['enHizmet']['ad'])) {
            $cumleler[] = "En çok uygulanan hizmet " . $veri['enHizmet']['ad']
                        . ", toplam " . (int) $veri['enHizmet']['adet'] . " kez gerçekleştirilmiş";
        }
        // Urun SADECE varsa soylenir (yoksa hic bahsedilmez).
        if (!empty($veri['enUrun']['ad'])) {
            $cumleler[] = "En çok satılan ürün " . $veri['enUrun']['ad']
                        . ", " . $this->tl($veri['enUrun']['ciro']) . " gelir getirmiş";
        }
        if (!empty($veri['enPersonel']['ad'])) {
            $cumleler[] = "En çok işlem yapan personel " . $veri['enPersonel']['ad']
                        . ", toplam " . (int) $veri['enPersonel']['islem'] . " işlem gerçekleştirmiş";
        }

        $cevap = implode(". ", $cumleler) . ". " . $this->gunYorum($toplam, $veri);

        return [
            'basarili' => true, 'intent' => 'ozet', 'cevap' => $cevap, 'seslendir' => true,
            'kart' => [
                'tip'        => 'gun_ozeti',
                'baslik'     => 'Günün Özeti · ' . ucfirst($donemAdi),
                'kasa'       => $toplam,
                'nakit'      => (float) ($kasa['nakit'] ?? 0),
                'kart'       => (float) ($kasa['kart'] ?? 0),
                'enHizmet'   => $veri['enHizmet'] ?? null,
                'enUrun'     => $veri['enUrun'] ?? null,
                'enPersonel' => $veri['enPersonel'] ?? null,
            ],
            'niyet' => $niyet,
        ];
    }

    /**
     * Samimi yorum — HER SEFERINDE FARKLI olsun diye rastgele secilir (ezbere durmaz).
     * Bedava (AI'a gerek yok). Sesli asistan icin DUZ yazim: unlem yok, az noktalama.
     */
    protected function gunYorum($toplam, array $veri)
    {
        if ($toplam <= 0) {
            $sifir = [
                'Gün daha yeni bol bereketli olsun',
                'Henüz erken güzel bir gün diliyorum',
                'Gün yeni başlıyor bereketli geçsin',
                'Bugün için henüz erken umarım güzel geçer',
            ];
            return $sifir[array_rand($sifir)] . '.';
        }
        $genel = [
            'Gün güzel gidiyor eline sağlık',
            'Bugün bereketli görünüyor aynen devam',
            'İşler yolunda tebrik ederim',
            'Güzel bir gün geçiriyorsun böyle devam',
            'Bugün gayet iyi görünüyor',
            'Fena değil istikrarı koru',
            'Güzel bir tempo yakalamışsın',
            'İyi bir gün emeğine sağlık',
            'Bugün için memnun olabilirsin',
            'Akışında güzel bir gün',
        ];
        $yorum = $genel[array_rand($genel)];
        if (!empty($veri['enPersonel']['ad'])) {
            $ad = $veri['enPersonel']['ad'];
            $ovgu = [
                ' Bugün en çok emeği geçen ' . $ad . ' olmuş',
                ' ' . $ad . ' bugün gerçekten yoğun çalışmış',
                ' ' . $ad . ' bugünkü performansıyla öne çıkıyor',
                ' Özellikle ' . $ad . ' bugün çok iş çıkarmış',
                '', // bazen ovgu olmasin -> cesitlilik
                '', // ovgusuz ihtimali biraz artir
            ];
            $yorum .= $ovgu[array_rand($ovgu)];
        }
        return $yorum . '.';
    }

    /** Kisa/veri-yok cevaplari icin ortak sarmalayici. */
    protected function basitCevap($intent, $metin, array $niyet)
    {
        return [
            'basarili' => true, 'intent' => $intent, 'cevap' => $metin,
            'seslendir' => true, 'kart' => null, 'niyet' => $niyet,
        ];
    }

    // ------------------------------------------------------------------
    // OGRENEN ONBELLEK: AI ile bir kez cozuleni sakla -> sonraki sefer bedava
    // ------------------------------------------------------------------

    /**
     * Daha once (AI ile) cozulmus bir sorunun niyetini dondurur; yoksa null.
     * Ayni/normalize-esit soru tekrar gelince AI'ya GITMEDEN bedava cevaplanir.
     * Niyet salon-bagimsiz (sadece cumle -> intent/donem) oldugu icin tum salonlar paylasir.
     */
    public function ogrenilenNiyet($metin)
    {
        try {
            $v = Cache::get($this->ogrenAnahtar($metin));
            if (is_array($v) && !empty($v['intent'])) {
                $v['ham'] = trim((string) $metin);
                return $v;
            }
        } catch (\Throwable $e) {}
        return null;
    }

    /** AI bir soruyu basariyla cozunce eslesmeyi kalici sakla (sonraki sefer bedava). */
    public function ogren($metin, array $niyet)
    {
        if (($niyet['intent'] ?? 'bilinmiyor') === 'bilinmiyor') return;
        try {
            Cache::forever($this->ogrenAnahtar($metin), [
                'intent'        => $niyet['intent'],
                'donem'         => $niyet['donem'] ?? 'gun',
                'donemAdi'      => $niyet['donemAdi'] ?? 'bugün',
                'personelIpucu' => $niyet['personelIpucu'] ?? null,
            ]);
        } catch (\Throwable $e) {}
    }

    protected function ogrenAnahtar($metin)
    {
        return 'patron_asistan_ogr:' . md5($this->normalize($metin));
    }

    // ------------------------------------------------------------------
    // OPSIYONEL: Haiku ile niyet cozumu (TAM SERBEST DIYALOG)
    // ------------------------------------------------------------------

    /**
     * ANTHROPIC_API_KEY tanimliysa, serbest metni Haiku'ya function-calling ile cozdurur.
     * Haiku SADECE niyeti yapilandirir (hangi rapor + donem); RAKAM URETMEZ.
     * Key yoksa / hata olursa null doner -> cagiran taraf kural motoruna duser.
     *
     * @return array|null niyetCoz() ile ayni yapida (intent/donem/donemAdi/personelIpucu/ham) veya null
     */
    /** Kullanim logu icin aktif salon (controller set eder). */
    public $aktifSalonId = 0;

    public function niyetCozAI($metin, $gecmis = [])
    {
        if (!$this->ai->anahtarVar()) {
            return null; // Anahtar yok -> sessizce kural motoruna dus
        }

        $ham   = trim((string) $metin);
        $araclar = [[
            'name'        => 'rapor_sec',
            'description' => 'Patronun sorusuna gore hangi isletme raporunun hangi donem icin getirilecegini secer.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'intent' => [
                        'type' => 'string',
                        'enum' => ['kasa','personel','hizmet','urun','musteri','ozet','bugun','iptal','saat','oneri','bilinmiyor'],
                        'description' => 'kasa=ciro/tahsilat, personel=kim ne sattı, hizmet=hizmet karlilik, urun=urun satis, musteri=musteri ozeti, ozet=genel/gun sonu, bugun=bugunku/donem randevu SAYISI, iptal=IPTAL edilen ya da GELMEYEN randevu, saat=salonun CALISMA SAATLERI / hangi gunler-saatler acik / bugun acik mi, oneri=isletmeyi buyutme/tavsiye, bilinmiyor=anlasilamadi',
                    ],
                    'donem' => [
                        'type' => 'string',
                        'enum' => ['gun','hafta','ay'],
                        'description' => 'Zaman araligi: gun=bugun, hafta=bu hafta, ay=bu ay',
                    ],
                    'personel_adi' => [
                        'type' => 'string',
                        'description' => 'Soru belirli bir personel hakkindaysa o kisinin adi, degilse bos birak',
                    ],
                ],
                'required' => ['intent','donem'],
            ],
        ]];

        $govde = [
            'model'      => $this->ai->model(),
            'max_tokens' => 256,
            'tool_choice'=> ['type' => 'tool', 'name' => 'rapor_sec'],
            'tools'      => $araclar,
            'system'     => 'Sen bir salon isletme yonetim panelinin asistanisin. Patronun Turkce (argo/yarim cumle olabilir) sorusunu oku ve rapor_sec aracini cagirarak hangi raporu hangi donem icin istedigini belirt. Onceki konusma varsa takip sorularini (ornek: "peki bu hafta", "onu detaylandir") ona gore yorumla. Rakam URETME, yorum yapma; sadece araci cagir.',
            'messages'   => array_merge($this->gecmisMesajlari($gecmis), [['role' => 'user', 'content' => $ham]]),
        ];

        $data = $this->ai->cagir($govde, 12, 'niyet', $this->aktifSalonId);
        if ($data === null) {
            return null; // Her turlu hatada kural motoruna dus
        }
        // tool_use blogunu bul
        $tool = null;
        foreach ($data['content'] as $blok) {
            if (($blok['type'] ?? '') === 'tool_use' && ($blok['name'] ?? '') === 'rapor_sec') {
                $tool = $blok['input'] ?? null;
                break;
            }
        }
        if (!is_array($tool) || empty($tool['intent'])) {
            return null;
        }

        $donem = in_array($tool['donem'] ?? '', ['gun','hafta','ay'], true) ? $tool['donem'] : 'gun';
        $donemAdi = ['gun' => 'bugün', 'hafta' => 'bu hafta', 'ay' => 'bu ay'][$donem];
        $personel = trim((string) ($tool['personel_adi'] ?? '')) ?: null;

        return [
            'intent'        => $tool['intent'],
            'donem'         => $donem,
            'donemAdi'      => $donemAdi,
            'personelIpucu' => $personel,
            'ham'           => $ham,
            '_kaynak'       => 'ai',
        ];
    }

    // ------------------------------------------------------------------
    // KONUSMA AI FALLBACK — kural HICBIR seyi cozemeyince Haiku dogal cevap verir.
    // Rapor sorulari zaten yukarida cozuluyor; buraya SADECE sohbet/kimlik/serbest
    // ifadeler duser. Cevap OGRENILIR -> ayni soru tekrar gelince BEDAVA.
    // ------------------------------------------------------------------

    /** Daha once AI ile uretilmis sohbet cevabini dondurur (bedava tekrar); yoksa null. */
    public function ogrenilenSohbet($metin)
    {
        try {
            $v = Cache::get($this->ogrenSohbetAnahtar($metin));
            if (is_string($v) && $v !== '') return $v;
        } catch (\Throwable $e) {}
        return null;
    }

    /** AI sohbet cevabini kalici sakla (sonraki sefer bedava). */
    public function ogrenSohbet($metin, $cevap)
    {
        $cevap = trim((string) $cevap);
        if ($cevap === '') return;
        try {
            Cache::forever($this->ogrenSohbetAnahtar($metin), $cevap);
        } catch (\Throwable $e) {}
    }

    protected function ogrenSohbetAnahtar($metin)
    {
        return 'patron_asistan_sohbet:' . md5($this->normalize($metin));
    }

    // ------------------------------------------------------------------
    // KONUSMA BELLEGI (kisa sureli) — takip sorulari icin baglam.
    // "az once onerdigin seyi detaylandir", "peki bu hafta?" gibi.
    // Kullanici basina son birkac tur; TTL 30 dk.
    // ------------------------------------------------------------------

    /** Kullanicinin son konusma turlarini dondurur: [['role'=>..,'content'=>..], ...]. */
    public function gecmisGetir($userId)
    {
        try {
            $v = Cache::get($this->gecmisAnahtar($userId));
            if (is_array($v)) return $v;
        } catch (\Throwable $e) {}
        return [];
    }

    /** Bir turu (soru + cevap) gecmise ekler; son 6 mesaji (3 tur) tutar. */
    public function gecmisEkle($userId, $soru, $cevap)
    {
        $soru  = trim((string) $soru);
        $cevap = trim((string) $cevap);
        if ($soru === '' || $cevap === '') return;
        try {
            $g = $this->gecmisGetir($userId);
            $g[] = ['role' => 'user', 'content' => mb_substr($soru, 0, 500)];
            $g[] = ['role' => 'assistant', 'content' => mb_substr($cevap, 0, 800)];
            if (count($g) > 6) $g = array_slice($g, -6); // son 3 tur
            Cache::put($this->gecmisAnahtar($userId), $g, now()->addMinutes(30));
        } catch (\Throwable $e) {}
    }

    protected function gecmisAnahtar($userId)
    {
        return 'patron_asistan_gecmis:' . (int) $userId;
    }

    /** Gecmisi gecerli Anthropic mesaj dizisine cevirir (user ile baslar, rol/gecerli icerik). */
    protected function gecmisMesajlari($gecmis)
    {
        if (!is_array($gecmis) || empty($gecmis)) return [];
        $out = [];
        foreach ($gecmis as $m) {
            $rol = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $ic  = trim((string) ($m['content'] ?? ''));
            if ($ic === '') continue;
            // Ilk mesaj 'user' olmali; bastaki assistant'i at.
            if (empty($out) && $rol !== 'user') continue;
            $out[] = ['role' => $rol, 'content' => $ic];
        }
        return $out;
    }

    /**
     * Haiku ile DOGAL sohbet cevabi. Salon asistani kimligiyle kisa, sicak, DUZ metin
     * (TTS icin). Rakam/veri URETMEZ; veri istenirse nasil sorulacagini soyler; konu
     * disi ise nazikce salona yonlendirir. Key yoksa/hata olursa null (-> yardimCevabi).
     *
     * @return string|null
     */
    public function sohbetAI($metin, $gecmis = [])
    {
        if (!$this->ai->anahtarVar()) { return null; }

        $ham = trim((string) $metin);
        if ($ham === '') return null;

        $sistem = 'Sen bir GUZELLIK/KUAFOR SALONUNUN patronu icin calisan sesli asistansin. '
            . 'Adin yok, kendini "salonunuzun asistani" diye tanit. Kisa (en fazla iki cumle), '
            . 'sicak ve NET konus. TTS ile seslendirilecegin icin DUZ yaz: emoji, madde isareti, '
            . 'yildiz, tirnak KULLANMA. Yapabildiklerin: kasa ve ciro durumu, en cok satilan hizmet, '
            . 'urun satisi, personel performansi, gunun randevulari, gunluk ozet, isletmeyi buyutme '
            . 'onerileri ve musterilere ucretsiz indirim kampanyasi hazirlayip gonderme. '
            . 'RAKAM veya VERI UYDURMA; patron rakam isterse "bugun kasa ne durumda gibi sorabilirsiniz" '
            . 'de. Salonla ilgisiz konularda (genel kultur, siyaset, hava durumu vb.) nazikce '
            . 'salonuyla ilgili yardimci olabilecegini soyle. "Buyurun" kelimesini KULLANMA. '
            . 'Sadece Turkce yanit ver.';

        $mesajlar = $this->gecmisMesajlari($gecmis);
        $mesajlar[] = ['role' => 'user', 'content' => $ham];

        $govde = [
            'model'      => $this->ai->model(),
            'max_tokens' => 220,
            'system'     => $sistem,
            'messages'   => $mesajlar,
        ];

        return $this->ai->metin($this->ai->cagir($govde, 12, 'sohbet', $this->aktifSalonId));
    }

    /** Calisma saatleri cevabi — VERIDEN (bedava). Belirli gun / bugun / tum hafta. */
    public function cevapCalismaSaati(array $liste, array $niyet)
    {
        $gunAd = [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar'];
        $harita = [];
        foreach ($liste as $r) {
            $harita[(int) $r['gun']][] = $r['bas'] . ' ile ' . $r['bit'];
        }
        if (empty($harita)) {
            return ['basarili' => true, 'intent' => 'saat', 'seslendir' => true, 'kart' => null,
                'cevap' => 'Çalışma saatleriniz sistemde tanımlı görünmüyor. Salon ayarlarından ekleyebilirsiniz.'];
        }
        $ham = $this->normalize($niyet['ham'] ?? '');

        // 1) Belirli bir gun adi gecti mi?
        foreach ($gunAd as $no => $ad) {
            if (strpos($ham, $this->normalize($ad)) !== false) {
                $cevap = isset($harita[$no])
                    ? $ad . ' günü ' . implode(', ', $harita[$no]) . ' saatlerinde açığız.'
                    : $ad . ' günü kapalıyız.';
                return ['basarili' => true, 'intent' => 'saat', 'seslendir' => true, 'kart' => null, 'cevap' => $cevap];
            }
        }
        // 2) Bugun / su an
        if (strpos($ham, 'bugun') !== false || strpos($ham, 'su an') !== false || strpos($ham, 'simdi') !== false) {
            $bg = (int) date('N'); // 1=Pzt .. 7=Paz
            $cevap = isset($harita[$bg])
                ? 'Bugün ' . $gunAd[$bg] . ', ' . implode(', ', $harita[$bg]) . ' saatleri arasında açığız.'
                : 'Bugün ' . $gunAd[$bg] . ' kapalıyız.';
            return ['basarili' => true, 'intent' => 'saat', 'seslendir' => true, 'kart' => null, 'cevap' => $cevap];
        }
        // 3) Genel: tum hafta
        $parcalar = [];
        for ($g = 1; $g <= 7; $g++) {
            $parcalar[] = isset($harita[$g]) ? $gunAd[$g] . ' ' . implode(' ve ', $harita[$g]) : $gunAd[$g] . ' kapalı';
        }
        return ['basarili' => true, 'intent' => 'saat', 'seslendir' => true, 'kart' => null,
            'cevap' => 'Çalışma saatlerimiz şöyle: ' . implode(', ', $parcalar) . '.'];
    }

    /**
     * IPTAL + GELMEYEN (no-show) randevu cevabi. $veri = randevuDurum JSON
     * (iptal, gelmedi, iptal_personel, gelmedi_personel). Ikisini de soyler +
     * varsa en cok iptal/gelmedigi olan personeli ekler.
     */
    public function cevapRandevuDurum(array $veri, array $niyet)
    {
        $donemAdi = $niyet['donemAdi'];
        $iptal    = (int) ($veri['iptal'] ?? 0);
        $gelmedi  = (int) ($veri['gelmedi'] ?? 0);
        $ip       = $veri['iptal_personel'] ?? null;
        $gp       = $veri['gelmedi_personel'] ?? null;

        if ($iptal <= 0 && $gelmedi <= 0) {
            $cevap = ucfirst($donemAdi) . ' iptal edilen ya da gelinmeyen randevu görünmüyor, tablo tertemiz.';
            return [
                'basarili' => true, 'intent' => 'iptal', 'seslendir' => true,
                'cevap' => $cevap, 'kart' => null, 'niyet' => $niyet,
            ];
        }

        $cevap = ucfirst($donemAdi) . ' ' . $iptal . ' randevu iptal edilmiş, '
               . $gelmedi . ' randevuya da gelinmemiş.';

        if ($ip && $iptal > 0) {
            $cevap .= ' En çok iptal ' . $ip['personel_adi'] . ' tarafında, '
                    . $ip['adet'] . ' randevu.';
        }
        if ($gp && $gelmedi > 0) {
            $cevap .= ' Gelinmeyen randevularda ise en çok ' . $gp['personel_adi']
                    . ' öne çıkıyor, ' . $gp['adet'] . ' randevu.';
        }

        return [
            'basarili' => true, 'intent' => 'iptal', 'seslendir' => true,
            'cevap' => $cevap, 'kart' => null, 'niyet' => $niyet,
        ];
    }

    /**
     * Bir personelin performans verisine bakip Haiku ile KISA yapici yorum + oneri uretir.
     * Rakam UYDURMAZ (verilen ciro/islem'i kullanir). Key yoksa/hata olursa null.
     * @return string|null
     */
    /**
     * AI cevabini VERI-ANAHTARIYLA cache'ler: ayni girdiyle (ayni gun) tekrar sorulursa
     * Haiku'ya GITMEDEN kaliptan doner (BEDAVA). $uret sadece cache bosken calisir.
     */
    protected function aiCache($anahtar, callable $uret, $dakika = 720, $tur = 'ai')
    {
        try {
            $c = Cache::get($anahtar);
            if (is_string($c) && $c !== '') {
                $this->ai->onbellekYaz($this->aktifSalonId, $tur);
                return $c;
            }
        } catch (\Throwable $e) {}
        $out = $uret(); // canli cagri -> haikuText kendi logunu yazar
        if (is_string($out) && $out !== '') {
            try { Cache::put($anahtar, $out, now()->addMinutes($dakika)); } catch (\Throwable $e) {}
        }
        return $out;
    }

    public function personelYorumAI($personel, $donemAdi, $toplam = 0, $sira = 0, $dusuk = false)
    {
        $ad    = (string) ($personel['personel_adi'] ?? 'Personel');
        $ciro  = (float) ($personel['ciro'] ?? 0);
        $islem = (int) ($personel['hizmet_say'] ?? 0);

        $sistem = 'Sen bir salon isletme danismani asistanisin. Verilen TEK personelin performans '
            . 'verisine bakip PATRONA hitaben KISA (en fazla iki cumle), yapici ve NET bir yorum ve '
            . 'somut bir oneri ver. Personeli suclama; motivasyon, egitim ya da musteri dagilimi '
            . 'odakli konus. TTS icin DUZ yaz: emoji, tirnak, madde isareti, yildiz YOK. Sadece '
            . 'Turkce ve yalniz yorumu yaz, baska bir sey ekleme.';
        $kullanici = "Donem: {$donemAdi}. Personel: {$ad}. Ciro: " . number_format($ciro, 0, ',', '.')
            . " TL. Islem sayisi: {$islem}. Toplam personel: {$toplam}. Siralamasi: {$sira}."
            . ($dusuk ? ' Bu personel donemin EN DUSUK performanslisi.' : '');

        // Ayni personel+veri, ayni gun -> BEDAVA kalip (12 saat).
        $anahtar = 'pa_pyorum:' . md5($ad . '|' . $donemAdi . '|' . $ciro . '|' . $islem . '|' . ($dusuk ? 1 : 0))
                 . ':' . date('Y-m-d');
        return $this->aiCache($anahtar, function () use ($sistem, $kullanici) {
            return $this->haikuText($sistem, $kullanici, 160, 'yorum');
        }, 720, 'yorum');
    }

    /**
     * Genel amacli tek seferlik Haiku metin cagrisi (arac yok, gecmis yok).
     * @return string|null
     */
    protected function haikuText($sistem, $userContent, $maxTokens = 160, $tur = 'ai')
    {
        if (!$this->ai->anahtarVar()) { return null; }

        return $this->ai->metin($this->ai->cagir([
            'model'      => $this->ai->model(),
            'max_tokens' => $maxTokens,
            'system'     => $sistem,
            'messages'   => [['role' => 'user', 'content' => (string) $userContent]],
        ], 30, $tur, $this->aktifSalonId));
    }



    // ------------------------------------------------------------------
    // YENI PERSONEL DETAYLI DEGERLENDIRME (istenince) — kayit/migration yok.
    // "Ahmet'i son 7 gun degerlendir" -> zengin veri + AI detayli karne.
    // ------------------------------------------------------------------

    /** Mesaj bir personel DEGERLENDIRME/karne istegi mi? */
    /**
     * Personel degerlendirme tetigi ve GUCU:
     *  - 'guclu': "degerlendir/karne/performans raporu..." -> her zaman degerlendirme
     *    (isim yoksa "kimi degerlendireyim" diye sorulur).
     *  - 'zayif': "rapor/performans/analiz/olc/incele..." -> YALNIZ bir personel adi
     *    varsa degerlendirme (yoksa "kasa raporu" gibi sorular kacmasin diye normal akis).
     *  - null: degerlendirme degil.
     * @return string|null 'guclu' | 'zayif' | null
     */
    public function degerlendirmeTur($metin)
    {
        $n = ' ' . $this->normalize($metin) . ' ';
        $guclu = ['degerlendir', 'degerlendirme', 'degerlendirmesi', 'karne', 'detayli rapor',
                  'detay rapor', 'detayli degerlendir', 'performans raporu', 'takip raporu',
                  'ise yeni', 'yeni personel', 'personeli degerlendir', 'performansini olc',
                  'performans olc', 'performansini degerlendir', 'performans degerlendir'];
        foreach ($guclu as $k) {
            if (strpos($n, $k) !== false) return 'guclu';
        }
        $zayif = ['rapor', 'raporu', 'raporunu', 'performans', 'performansi', 'analiz',
                  'incele', 'inceleme', 'olc', 'olcelim', 'nasil gidiyor', 'ne durumda',
                  'nasil calisiyor', 'durumu ne', 'nasil biri', 'verimli mi', 'basarili mi'];
        foreach ($zayif as $k) {
            if (strpos($n, $k) !== false) return 'zayif';
        }
        return null;
    }

    /** Degerlendirme penceresi (gun). "son N gun", "bir hafta", "aylik"... Varsayilan 7. */
    public function degerlendirmeGun($metin)
    {
        $n = $this->normalize($metin);
        if (preg_match('/son\s*(\d{1,3})\s*gun/', $n, $mm)) {
            $g = (int) $mm[1]; return ($g >= 1 && $g <= 180) ? $g : 7;
        }
        if (preg_match('/(\d{1,3})\s*gun/', $n, $mm)) {
            $g = (int) $mm[1]; return ($g >= 1 && $g <= 180) ? $g : 7;
        }
        if (strpos($n, 'iki hafta') !== false || strpos($n, '2 hafta') !== false) return 14;
        if (strpos($n, 'bir hafta') !== false || strpos($n, 'haftalik') !== false || strpos($n, '1 hafta') !== false) return 7;
        if (strpos($n, 'yarim ay') !== false || strpos($n, '15 gun') !== false) return 15;
        if (strpos($n, 'bir ay') !== false || strpos($n, 'aylik') !== false || strpos($n, 'bu ay') !== false) return 30;
        return 7; // varsayilan bir hafta
    }

    /**
     * Mesajda gecen personeli bulur (adin TUM kelimeleri mesajda; guclu eslesme).
     * @return object|array|null tek=object{id,personel_adi}, coklu=['coklu','adlar'], yok=null
     */
    public function personelMesajdanBul($liste, $metin)
    {
        $mesajKel = array_values(array_filter(
            preg_split('/\s+/', trim($this->normalize($metin))),
            function ($w) { return mb_strlen($w) >= 3; }
        ));
        if (empty($mesajKel)) return null;

        $es = [];
        foreach ($liste as $p) {
            $adKel = array_values(array_filter(
                preg_split('/\s+/', $this->normalize($p->personel_adi ?? '')),
                function ($w) { return mb_strlen($w) >= 2; }
            ));
            if (empty($adKel)) continue;
            $tumu = true;
            foreach ($adKel as $aw) {
                $bulundu = false;
                foreach ($mesajKel as $mw) {
                    if ($aw === $mw) { $bulundu = true; break; }
                    $kisa = mb_strlen($mw) <= mb_strlen($aw) ? $mw : $aw;
                    $uzun = ($kisa === $mw) ? $aw : $mw;
                    if (mb_strlen($kisa) >= 3 && strpos($uzun, $kisa) === 0) { $bulundu = true; break; }
                }
                if (!$bulundu) { $tumu = false; break; }
            }
            if ($tumu && (count($adKel) >= 2 || mb_strlen($adKel[0]) >= 4)) {
                $es[] = $p;
                if (count($es) > 5) break;
            }
        }
        if (empty($es)) return null;
        if (count($es) === 1) return $es[0];
        return ['coklu' => true, 'adlar' => array_map(function ($p) { return $p->personel_adi; }, $es)];
    }

    /** Detayli degerlendirme cevabi: faktuel ozet (duz) + AI detayli karne. */
    public function cevapPersonelDegerlendirme(array $d, $ad, $gun)
    {
        $ciro     = (float) ($d['ciro'] ?? 0);
        $islem    = (int) ($d['islem'] ?? 0);
        $musteri  = (int) ($d['musteri'] ?? 0);
        $aktifGun = (int) ($d['aktif_gun'] ?? 0);
        $iptal    = (int) ($d['iptal'] ?? 0);
        $gelmedi  = (int) ($d['gelmedi'] ?? 0);
        $ort      = (float) ($d['salon_ortalama'] ?? 0);
        $hizmetler = $d['hizmetler'] ?? [];

        $ozet = $ad . ' için son ' . $gun . ' günlük değerlendirme. ';
        if ($islem <= 0 && $ciro <= 0) {
            $ozet .= 'Bu sürede kayıtlı bir işlem görünmüyor, henüz veriye dayalı bir sonuç çıkarmak zor. ';
        } else {
            $ozet .= 'Toplam ' . $this->tl($ciro) . ' ciro, ' . $islem . ' işlem, ' . $musteri
                   . ' farklı müşteri ve ' . $aktifGun . ' aktif çalışma günü. ';
            if (!empty($hizmetler)) {
                $hAd = [];
                foreach (array_slice($hizmetler, 0, 3) as $h) {
                    $hAd[] = ($h['hizmet_adi'] ?? '') . ' ' . (int) ($h['adet'] ?? 0) . ' kez';
                }
                $ozet .= 'En çok yaptığı işlemler: ' . implode(', ', $hAd) . '. ';
            }
            if ($iptal > 0 || $gelmedi > 0) {
                $ozet .= 'Randevularında ' . $iptal . ' iptal ve ' . $gelmedi . ' gelmeyen kayıtlı. ';
            }
            if ($ort > 0) {
                $ozet .= 'Aynı dönemde salon kişi başı ortalama ciro ' . $this->tl($ort) . '. ';
            }
        }

        $yorum = $this->personelDegerlendirmeAI($ad, $gun, $d);
        $cevap = trim($ozet) . ($yorum ? ' ' . $yorum : '');

        return [
            'basarili' => true, 'intent' => 'personel', 'seslendir' => true, 'kart' => null,
            'cevap' => $cevap,
        ];
    }

    /** Haiku ile DETAYLI personel degerlendirmesi (guclu/gelisim/kiyas/oneri/uygunluk). */
    public function personelDegerlendirmeAI($ad, $gun, array $d)
    {
        $sistem = 'Sen deneyimli bir salon isletme ve insan kaynaklari danismanisin. Verilen YENI '
            . 'personelin surecteki performans verisine bakip PATRONA hitaben DETAYLI ama derli toplu '
            . 'bir degerlendirme yaz. Su noktalari akici cumlelerle isle: guclu yonleri, gelisim '
            . 'alanlari, salon ortalamasina gore durumu, ilk ve ikinci yari trendi, somut egitim ya da '
            . 'yonlendirme onerisi, ve ise uygunluk yonunde genel bir kanaat. Verilen rakamlarin '
            . 'DISINDA sayi UYDURMA. Adil, cozum odakli ve saygili ol. TTS icin DUZ yaz: emoji, tirnak, '
            . 'madde isareti, yildiz YOK. EN FAZLA 6 cumle yaz ve cumleni MUTLAKA tamamla (yarim '
            . 'birakma). Sadece Turkce yaz.';

        $u = "Yeni personel: {$ad}. Takip suresi: {$gun} gun. "
           . "Ciro: " . number_format((float) ($d['ciro'] ?? 0), 0, ',', '.') . " TL. "
           . "Islem sayisi: " . (int) ($d['islem'] ?? 0) . ". "
           . "Farkli musteri: " . (int) ($d['musteri'] ?? 0) . ". "
           . "Aktif calisma gunu: " . (int) ($d['aktif_gun'] ?? 0) . " / {$gun}. "
           . "Iptal: " . (int) ($d['iptal'] ?? 0) . ". Gelmeyen: " . (int) ($d['gelmedi'] ?? 0) . ". "
           . "Salon kisi basi ortalama ciro: " . number_format((float) ($d['salon_ortalama'] ?? 0), 0, ',', '.') . " TL. "
           . "Ilk yari ciro: " . number_format((float) ($d['ilk_yari'] ?? 0), 0, ',', '.') . " TL, "
           . "ikinci yari ciro: " . number_format((float) ($d['ikinci_yari'] ?? 0), 0, ',', '.') . " TL. ";
        $hizmetler = $d['hizmetler'] ?? [];
        if (!empty($hizmetler)) {
            $hs = [];
            foreach (array_slice($hizmetler, 0, 3) as $h) {
                $hs[] = ($h['hizmet_adi'] ?? '') . ' ' . (int) ($h['adet'] ?? 0) . ' kez';
            }
            $u .= "En cok yaptigi islemler: " . implode(', ', $hs) . ". ";
        }

        // Ayni personel+veri, ayni gun tekrar sorulursa BEDAVA kalip (12 saat).
        $anahtar = 'pa_karne:' . md5($ad . '|' . $gun . '|' . json_encode($d)) . ':' . date('Y-m-d');
        return $this->aiCache($anahtar, function () use ($sistem, $u) {
            return $this->haikuText($sistem, $u, 600, 'karne');
        }, 720, 'karne');
    }

    /**
     * SALON BUYUTME / IS ARTIRMA DANISMANLIGI. "Nasil daha cok musteri cekerim,
     * isleri nasil artiririm, ne yapmaliyim" gibi sorularda pratik oneriler sunar.
     * Rapor niyetinden ONCE cagirilir (yoksa "musteri artir" -> musteri istatistigi olur).
     * Her seferinde RASTGELE 3 farkli oneri -> ezbere durmaz, bedava.
     */
    public function salonOnerisiTetikMi($metin)
    {
        $norm = ' ' . $this->normalize($metin) . ' ';
        $tetik = [
            'nasil artir', 'nasil buyut', 'nasil buyur', 'buyutmek', 'buyumek',
            'musteri cek', 'musteri kazan', 'nasil musteri', 'musteri sayisini artir',
            'ne yapmaliyim', 'ne yapmali', 'ne yapabilirim', 'ne onerirsin', 'ne tavsiye',
            'tavsiye ver', 'oneri ver', 'onerir misin', 'onerin var', 'fikrin var',
            'gelistir', 'gelismek', 'daha iyi hale', 'daha basarili', 'daha cok kazan',
            'isleri artir', 'is artir', 'islerimi artir', 'ciro artir', 'ciromu artir',
            'kazanc artir', 'satis artir', 'satislari artir', 'isleri canlandir',
            'islerim durgun', 'islerim kotu', 'isler durgun', 'isler kotu', 'isler acilmiyor',
            'salonumu buyut', 'isimi buyut', 'daha cok is', 'nasil kazan', 'buyumek istiyorum',
            // Kok-bazli genis kaliplar (cekim eklerinden etkilenmesin).
            'buyut', 'buyume', 'buyutme', 'isleri buyut', 'islerimi buyut', 'isimi buyut',
            'ne yapmam', 'neler yapmam', 'yapmam lazim', 'yapmam gerek', 'ne yapmaliyim',
            'ne yapmali', 'ne yapmam lazim', 'neler yapmam lazim', 'nasil yapmali',
            'sence ne', 'sizce ne', 'sence neler', 'sizce neler', 'ne dersin', 'fikrin ne',
            'onerin ne', 'tavsiyen ne', 'daha cok musteri', 'musteri artirmak', 'is artirmak',
            // "Dilerseniz basliklari acalim" teklifine devam: yeni oneriler ver.
            'acalim', 'baslikları ac', 'basliklari ac', 'daha ac', 'daha fazla oneri',
            'biraz daha oneri', 'baska oneri', 'baska oneriler', 'devam edelim', 'detaylandir',
            'daha fazla anlat', 'baska ne yapabilirim', 'baska neler yapabilirim',
            'biraz daha detay', 'daha detay', 'detay ver', 'biraz daha ac', 'biraz daha bilgi',
            'daha fazla detay', 'devam et',
        ];
        foreach ($tetik as $t) {
            if (strpos($norm, $t) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Genel (veriden bagimsiz) pratik oneri havuzu. */
    protected function genelIpuclari()
    {
        return [
            'Sadakat programı kurun: her ziyarette puan verin, belli puanda ücretsiz hizmet ya da indirim tanımlayın. Müşteri geri gelmek için sebep bulur.',
            'Boş ve sakin saatlere özel indirim yapın; örneğin öğle saatleri için indirim kampanyasıyla ölü saatleri doldurabilirsiniz.',
            'Google işletme profilinizi güncel tutun ve memnun müşterilerden yorum isteyin. Yüksek puan yeni müşteri getirir.',
            'Sosyal medyada öncesi ve sonrası fotoğraflarını düzenli paylaşın. Görsel sonuçlar en güçlü reklamdır.',
            'Randevu hatırlatması gönderin; gelmeyen müşteri oranı ciddi şekilde düşer.',
            'Uzun süredir gelmeyen müşterilere sizi özledik mesajı ve küçük bir indirimle geri dönüş sağlayın.',
            'Paket ve abonelik satışına ağırlık verin; tek seferlik hizmet yerine seans paketleri hem cironuzu hem bağlılığı artırır.',
            'Personelinize ek hizmet önerme alışkanlığı kazandırın. Bugün bakım da yapalım mı cümlesi ciroyu yükseltir.',
            'Doğum günü kutlaması ve küçük bir hediye gönderin; müşteri kendini özel hisseder.',
            'Arkadaşını getir kampanyası yapın: getiren de gelen de indirim kazansın. En ucuz yeni müşteri, mevcut müşterinin tavsiyesidir.',
            'Bakım ürünleri satışına ağırlık verin. Hizmete ek ürün, ekstra gelir demektir.',
            'Özel gün kampanyaları kurun; sevgililer günü, anneler günü, yılbaşı gibi dönemlerde özel paketler hazırlayın.',
            'Online randevuyu kolaylaştırın. Müşteri telefon açmadan, gece bile randevu alabilmeli.',
            'Memnuniyet anketi gönderin; hem müşteriyi önemsediğinizi gösterir hem eksikleri erken yakalarsınız.',
            'Bekleme alanı deneyimini iyileştirin. İkram, temizlik ve güzel bir ortam sadakati artırır.',
            'En kârlı hizmetlerinizi öne çıkarın; menüde ve personel önerisinde onları vurgulayın.',
            'Fiyatlarınızı ve maliyetlerinizi düzenli gözden geçirin. Kârı düşük hizmetleri iyileştirin.',
            'İlk kez gelen müşteriye küçük bir jest yapın. İyi bir ilk izlenim, tekrar gelmesini sağlar.',
            'Çok satan ve müşteri memnuniyeti yüksek personeli ödüllendirin; motivasyon doğrudan ciroya yansır.',
            'Kombine paketler oluşturun, örneğin saç ve bakımı birlikte sunun. Avantajlı fiyat sepet tutarını büyütür.',
            'Düzenli gelen sadık müşterilere ayrıcalıklar tanıyın; öncelikli randevu ya da sürpriz indirim gibi.',
            'Kampanyalarınızı mevcut müşterilere düzenli duyurun. Onları bilgilendirmek, yeni müşteri aramaktan daha ucuz ve etkilidir.',
        ];

    }

    /**
     * VERIYE DAYALI oneri: son donem (bu ay) verilerine bakip salona OZEL tavsiye
     * uretir; eksik kalirsa genel havuzdan tamamlar (3 oneri). Controller veriyi
     * toplar: ['kasa'=>..., 'hizmet'=>..., 'urun'=>..., 'personel'=>..., 'musteri'=>...].
     * Bedava (kural + veri analizi).
     */
    public function veriliOneri(array $veri, $userId = 0)
    {
        $veriVar = false;
        $adaylar = []; // sirali aday oneriler: ['k'=>sabit_anahtar, 't'=>metin]

        // --- Veriye dayali (oncelikli) oneriler ---
        $m = $veri['musteri'] ?? [];
        $aktif  = (int) ($m['toplam_aktif'] ?? 0);
        $yeni   = (int) ($m['yeni_musteri'] ?? 0);
        $tekrar = (int) ($m['tekrar_gelen'] ?? 0);
        if ($aktif >= 5) {
            $veriVar = true;
            if ($yeni <= $aktif * 0.2) {
                $adaylar[] = ['k' => 'veri:yeni_az', 't' => "Bu ay yeni müşteri sayınız düşük görünüyor; sadece " . $yeni
                    . " yeni müşteri gelmiş. Arkadaşını getir kampanyası ve sosyal medyada öncesi sonrası paylaşımlarıyla yeni müşteri çekebilirsiniz."];
            }
            if ($tekrar >= 5 && $tekrar >= $yeni) {
                $adaylar[] = ['k' => 'veri:sadik', 't' => "Sadık müşteri kitleniz güçlü; " . $tekrar
                    . " tekrar gelen müşteriniz var. Onlara seans paketleri ve abonelik sunarak hem cironuzu hem bağlılığı artırabilirsiniz."];
            }
        }
        if ((float) ($veri['urun']['toplam_ciro'] ?? 0) <= 0) {
            $adaylar[] = ['k' => 'veri:urun_yok', 't' => "Bu ay ürün satışınız görünmüyor. Bakım ürünleri satışıyla her müşteriden ek gelir elde edebilirsiniz."];
        } else {
            $veriVar = true;
        }
        $hizmetler = $veri['hizmet']['hizmetler'] ?? [];
        $hToplam   = (float) ($veri['hizmet']['toplam_ciro'] ?? 0);
        if (!empty($hizmetler) && $hToplam > 0) {
            $veriVar = true;
            $enH = (array) $hizmetler[0];
            if ((float) ($enH['ciro'] ?? 0) >= $hToplam * 0.6) {
                $adaylar[] = ['k' => 'veri:hizmet_konsantre', 't' => ($enH['hizmet_adi'] ?? 'Bir hizmetiniz')
                    . " cironuzun büyük kısmını oluşturuyor. Bu hizmetin yanına tamamlayıcı hizmetler önererek sepet tutarını yükseltebilirsiniz."];
            }
        }
        $pers = $veri['personel']['personeller'] ?? [];
        if (count($pers) >= 2) {
            $pToplam = 0; $enP = null; $enPciro = 0;
            foreach ($pers as $p) {
                $c = (float) ($p['ciro'] ?? 0);
                $pToplam += $c;
                if ($c > $enPciro) { $enPciro = $c; $enP = $p; }
            }
            if ($pToplam > 0 && $enPciro >= $pToplam * 0.55 && $enP) {
                $veriVar = true;
                $adaylar[] = ['k' => 'veri:personel_konsantre', 't' => "Cironuzun önemli bölümü " . ($enP['personel_adi'] ?? 'tek bir personel')
                    . " üzerinde. Diğer personeli eğitip müşteri dağılımını dengeleyerek hem riski azaltır hem kapasiteyi artırırsınız."];
            }
        }
        if ((float) ($veri['kasa']['toplam'] ?? 0) <= 0) {
            $adaylar[] = ['k' => 'veri:kasa_dusuk', 't' => "Bu dönem kasada belirgin bir hareket görünmüyor. Mevcut müşterilerinize kampanya duyurusu ve boş saatlere özel indirimle hızlı bir canlanma sağlayabilirsiniz."];
        }

        // --- Genel havuz (karisik sirayla, sabit anahtarli) ---
        $havuz = $this->genelIpuclari();
        shuffle($havuz);
        foreach ($havuz as $ip) {
            $adaylar[] = ['k' => 'genel:' . md5($ip), 't' => $ip];
        }

        // --- Daha once bu kullaniciya verilenleri disla (tekrar etmesin) ---
        $anahtar = 'patron_asistan_oneri_verildi:' . (int) $userId;
        $verilen = [];
        if ($userId > 0) {
            try { $v = Cache::get($anahtar); if (is_array($v)) $verilen = $v; } catch (\Throwable $e) {}
        }
        $verilenSet = array_flip($verilen);

        $secilen = []; $yeniKeys = [];
        foreach ($adaylar as $a) {
            if (isset($verilenSet[$a['k']])) continue;
            $secilen[] = $a['t'];
            $yeniKeys[] = $a['k'];
            if (count($secilen) >= 3) break;
        }

        // Hepsi verilmisse: seti sifirla, kibarca bildir (bir dahakine bastan).
        if (empty($secilen)) {
            if ($userId > 0) { try { Cache::forget($anahtar); } catch (\Throwable $e) {} }
            return [
                'basarili' => true, 'intent' => 'oneri', 'seslendir' => true, 'kart' => null,
                'cevap' => 'Şimdilik önerebileceklerimin hepsini paylaştım. Dilerseniz bir başlığı birlikte '
                         . 'derinleştirelim ya da hazır bir indirim kampanyası kuralım.',
            ];
        }

        $ilkTur = empty($verilen);
        if ($userId > 0) {
            try { Cache::put($anahtar, array_merge($verilen, $yeniKeys), now()->addMinutes(30)); } catch (\Throwable $e) {} // 30 dk
        }

        if ($ilkTur) {
            $giris   = $veriVar ? 'İşletmenizin son dönem verilerine göz attım. ' : 'İşletmenizi büyütmek için birkaç önerim var. ';
            $kapanis = ' Dilerseniz daha fazla öneri sıralayabilirim.';
        } else {
            // TAKIP: veri cumlesini TEKRARLAMA, yeni onerileri sun.
            $giris   = 'Şunları da ekleyebilirim. ';
            $kapanis = ' Dilerseniz devam edeyim.';
        }
        $cevap = $giris . implode(' ', $secilen) . $kapanis;

        return [
            'basarili' => true, 'intent' => 'oneri', 'seslendir' => true,
            'cevap' => $cevap, 'kart' => null,
        ];
    }

    /**
     * Mesaj bir KARSILASTIRMA istegi mi? (bu ay vs gecen ay gibi). Cok sayida farkli
     * kalip -> salon sahipleri degisik sorabilir.
     */
    public function karsilastirmaTetik($metin)
    {
        $n = ' ' . $this->normalize($metin) . ' ';
        $anahtarlar = [
            'karsilastir', 'karsilastirma', 'karsilastirmali', 'kiyasla', 'kiyaslama', 'mukayese',
            'gecen aya gore', 'onceki aya gore', 'bu ayla gecen', 'gecen ayla', 'gecen ay ile',
            'gecen ay ne kadar', 'gecen aya kiyasla', 'onceki ay', 'gecen haftaya gore',
            'gecen hafta bu hafta', 'bu haftayla gecen', 'gecen yila gore', 'gecen yil bu yil',
            'dune gore', 'dunle bugun', 'onceki doneme', 'gecen doneme gore', 'gecen sefere gore',
            'artti mi azaldi mi', 'arttimi azaldimi', 'artti mi', 'azaldi mi', 'yukseldi mi',
            'dustu mu', 'daha iyi mi', 'daha mi iyi', 'daha kotu mu', 'buyuduk mu', 'kuculduk mu',
            'geriledik mi', 'gelistik mi', 'ilerledik mi', 'artis var mi', 'dusus var mi',
            'fark ne', 'ne kadar fark', 'farki ne', 'ne fark var', 'gecmise gore',
        ];
        foreach ($anahtarlar as $k) {
            if (strpos($n, $k) !== false) return true;
        }
        return false;
    }

    /** Mesajda personel/eleman sozu var mi? ("personelleri kiyasla" -> DONEM degil, personel siralamasi). */
    public function personelSozuVarMi($metin)
    {
        $n = ' ' . $this->normalize($metin) . ' ';
        foreach (['personel', 'eleman', 'calisan', 'ekip', 'kadro'] as $k) {
            if (strpos($n, $k) !== false) return true;
        }
        return false;
    }

    /** Karsilastirma donemi: bu vs onceki tarih araliklari + adlar. Varsayilan AY. */
    public function karsilastirmaDonem($metin)
    {
        $n = $this->normalize($metin);
        if (strpos($n, 'hafta') !== false) {
            return [
                'buT1' => date('Y-m-d', strtotime('monday this week')),
                'buT2' => date('Y-m-d', strtotime('sunday this week')),
                'onT1' => date('Y-m-d', strtotime('monday last week')),
                'onT2' => date('Y-m-d', strtotime('sunday last week')),
                'buAd' => 'bu hafta', 'onAd' => 'geçen hafta',
            ];
        }
        if (strpos($n, 'yil') !== false || strpos($n, 'sene') !== false) {
            return [
                'buT1' => date('Y-01-01'), 'buT2' => date('Y-12-31'),
                'onT1' => date('Y-01-01', strtotime('-1 year')),
                'onT2' => date('Y-12-31', strtotime('-1 year')),
                'buAd' => 'bu yıl', 'onAd' => 'geçen yıl',
            ];
        }
        if (strpos($n, 'dun') !== false) {
            return [
                'buT1' => date('Y-m-d'), 'buT2' => date('Y-m-d'),
                'onT1' => date('Y-m-d', strtotime('-1 day')),
                'onT2' => date('Y-m-d', strtotime('-1 day')),
                'buAd' => 'bugün', 'onAd' => 'dün',
            ];
        }
        return [
            'buT1' => date('Y-m-01'), 'buT2' => date('Y-m-t'),
            'onT1' => date('Y-m-01', strtotime('first day of last month')),
            'onT2' => date('Y-m-t', strtotime('last day of last month')),
            'buAd' => 'bu ay', 'onAd' => 'geçen ay',
        ];
    }

    /** Kullanici neyi kiyasla dediyse ("randevu/urun/hizmet/musteri" ya da ciro). */
    public function karsilastirmaOdak($metin)
    {
        $n = ' ' . $this->normalize($metin) . ' ';
        if (strpos($n, 'randevu') !== false || strpos($n, 'takvim') !== false
            || strpos($n, 'iptal') !== false || strpos($n, 'gelmeyen') !== false) return 'randevu';
        if (strpos($n, 'urun') !== false) return 'urun';
        if (strpos($n, 'hizmet') !== false || strpos($n, 'islem') !== false) return 'hizmet';
        if (strpos($n, 'musteri') !== false || strpos($n, 'yeni musteri') !== false) return 'musteri';
        return 'ciro';
    }

    /** Karsilastirma cevabi: odakli sozlu ozet + tam liste kart. AI YOK (veriye dayali). */
    public function cevapKarsilastirma(array $bu, array $on, $buAd, $onAd, $odak = 'ciro')
    {
        $metrikler = [
            ['etiket' => 'Ciro',            'bu' => $bu['gelir'],        'on' => $on['gelir'],        'tip' => 'tl',   'artiIyi' => true],
            ['etiket' => 'İşlem (adisyon)', 'bu' => $bu['adisyon'],      'on' => $on['adisyon'],      'tip' => 'adet', 'artiIyi' => true],
            ['etiket' => 'Hizmet cirosu',   'bu' => $bu['hizmet_ciro'],  'on' => $on['hizmet_ciro'],  'tip' => 'tl',   'artiIyi' => true],
            ['etiket' => 'Ürün cirosu',     'bu' => $bu['urun_ciro'],    'on' => $on['urun_ciro'],    'tip' => 'tl',   'artiIyi' => true],
            ['etiket' => 'Randevu',         'bu' => $bu['randevu'],      'on' => $on['randevu'],      'tip' => 'adet', 'artiIyi' => true],
            ['etiket' => 'İptal',           'bu' => $bu['iptal'],        'on' => $on['iptal'],        'tip' => 'adet', 'artiIyi' => false],
            ['etiket' => 'Gelmeyen',        'bu' => $bu['gelmedi'],      'on' => $on['gelmedi'],      'tip' => 'adet', 'artiIyi' => false],
            ['etiket' => 'Yeni müşteri',    'bu' => $bu['yeni_musteri'], 'on' => $on['yeni_musteri'], 'tip' => 'adet', 'artiIyi' => true],
        ];

        $satirlar = [];
        foreach ($metrikler as $m) {
            $b = (float) $m['bu']; $o = (float) $m['on'];
            $fark = $b - $o;
            $yuzde = $o > 0 ? (int) round(($fark / $o) * 100) : ($b > 0 ? 100 : 0);
            $yon = ($fark > 0) ? 'up' : (($fark < 0) ? 'down' : 'flat');
            $iyi = ($fark == 0) ? true : ((($fark > 0) && $m['artiIyi']) || (($fark < 0) && !$m['artiIyi']));
            $satirlar[] = [
                'etiket' => $m['etiket'],
                'bu'     => $m['tip'] === 'tl' ? $this->tl($b) : (string) (int) $b,
                'onceki' => $m['tip'] === 'tl' ? $this->tl($o) : (string) (int) $o,
                'yuzde'  => abs($yuzde),
                'yon'    => $yon,
                'iyi'    => $iyi,
            ];
        }

        // ODAK metrige gore sozlu ozet (kullanici NEYI sorduysa onunla basla).
        $harita = [
            'ciro'    => ['ad' => 'ciro',           'bu' => (float) $bu['gelir'],       'on' => (float) $on['gelir'],       'tl' => true],
            'randevu' => ['ad' => 'randevu sayısı', 'bu' => (float) $bu['randevu'],      'on' => (float) $on['randevu'],      'tl' => false],
            'urun'    => ['ad' => 'ürün cirosu',    'bu' => (float) $bu['urun_ciro'],    'on' => (float) $on['urun_ciro'],    'tl' => true],
            'hizmet'  => ['ad' => 'hizmet cirosu',  'bu' => (float) $bu['hizmet_ciro'],  'on' => (float) $on['hizmet_ciro'],  'tl' => true],
            'musteri' => ['ad' => 'yeni müşteri',   'bu' => (float) $bu['yeni_musteri'], 'on' => (float) $on['yeni_musteri'], 'tl' => false],
        ];
        $f = $harita[$odak] ?? $harita['ciro'];
        $fFark  = $f['bu'] - $f['on'];
        $fYuzde = $f['on'] > 0 ? (int) round(($fFark / $f['on']) * 100) : ($f['bu'] > 0 ? 100 : 0);
        $buStr  = $f['tl'] ? $this->tl($f['bu']) : (string) (int) $f['bu'];
        $onStr  = $f['tl'] ? $this->tl($f['on']) : (string) (int) $f['on'];
        if ($fFark > 0) {
            $ozet = ucfirst($buAd) . ', ' . $onAd . 'a göre ' . $f['ad'] . ' yüzde ' . abs($fYuzde)
                  . ' artmış; ' . $onStr . 'dan ' . $buStr . 'a yükselmiş.';
        } elseif ($fFark < 0) {
            $ozet = ucfirst($buAd) . ', ' . $onAd . 'a göre ' . $f['ad'] . ' yüzde ' . abs($fYuzde)
                  . ' azalmış; ' . $onStr . 'dan ' . $buStr . 'a inmiş.';
        } else {
            $ozet = ucfirst($buAd) . ' ile ' . $onAd . ', ' . $f['ad'] . ' açısından hemen hemen aynı.';
        }
        // Randevu odaginda kaliteyi de ekle (iptal/gelmeyen).
        if ($odak === 'randevu') {
            $ozet .= ' İptal ' . (int) $on['iptal'] . 'ten ' . (int) $bu['iptal'] . 'e, gelmeyen '
                   . (int) $on['gelmedi'] . 'ten ' . (int) $bu['gelmedi'] . 'e geldi.';
        }
        $ozet .= ' Detaylı karşılaştırma listede.';

        return [
            'basarili' => true, 'intent' => 'karsilastirma', 'seslendir' => true,
            'cevap'    => $ozet,
            'kart'     => [
                'tip'       => 'karsilastirma',
                'baslik'    => ucfirst($buAd) . ' — ' . ucfirst($onAd),
                'bu_ad'     => $buAd,
                'onceki_ad' => $onAd,
                'satirlar'  => $satirlar,
            ],
        ];
    }

    /** PERSONEL iki-donem karsilastirma cevabi: her personel bu vs onceki ciro + %. Liste kart. */
    public function cevapPersonelKarsilastirma(array $liste, $buAd, $onAd)
    {
        if (empty($liste)) {
            return [
                'basarili' => true, 'intent' => 'karsilastirma', 'seslendir' => true, 'kart' => null,
                'cevap' => ucfirst($buAd) . ' ve ' . $onAd . ' için karşılaştırılacak personel hareketi bulunamadı.',
            ];
        }

        $satirlar = [];
        $enArtan = null; $enDusen = null;
        foreach ($liste as $p) {
            $b = (float) $p['bu_ciro']; $o = (float) $p['on_ciro'];
            $fark  = $b - $o;
            $yuzde = $o > 0 ? (int) round(($fark / $o) * 100) : ($b > 0 ? 100 : 0);
            $yon   = ($fark > 0) ? 'up' : (($fark < 0) ? 'down' : 'flat');
            $satirlar[] = [
                'etiket' => $p['ad'],
                'bu'     => $this->tl($b),
                'onceki' => $this->tl($o),
                'yuzde'  => abs($yuzde),
                'yon'    => $yon,
                'iyi'    => ($fark >= 0),
            ];
            if ($enArtan === null || $fark > $enArtan['d']) $enArtan = ['ad' => $p['ad'], 'd' => $fark];
            if ($enDusen === null || $fark < $enDusen['d']) $enDusen = ['ad' => $p['ad'], 'd' => $fark];
        }

        $ozet = ucfirst($buAd) . ' ile ' . $onAd . ' personel karşılaştırması. ';
        if ($enArtan && $enArtan['d'] > 0) $ozet .= 'En çok yükselen ' . $enArtan['ad'] . '. ';
        if ($enDusen && $enDusen['d'] < 0) $ozet .= 'En çok gerileyen ' . $enDusen['ad'] . '. ';
        $ozet .= 'Kişi kişi detay listede.';

        return [
            'basarili' => true, 'intent' => 'karsilastirma', 'seslendir' => true,
            'cevap'    => $ozet,
            'kart'     => [
                'tip'       => 'karsilastirma',
                'baslik'    => 'Personel · ' . ucfirst($buAd) . ' — ' . ucfirst($onAd),
                'bu_ad'     => $buAd,
                'onceki_ad' => $onAd,
                'satirlar'  => $satirlar,
            ],
        ];
    }

    /** Genel KALEM (urun/hizmet) iki-donem karsilastirma cevabi: her kalem bu vs onceki + %. */
    public function cevapKalemKarsilastirma(array $liste, $baslikOn, $buAd, $onAd, $bosMesaj)
    {
        if (empty($liste)) {
            return [
                'basarili' => true, 'intent' => 'karsilastirma', 'seslendir' => true, 'kart' => null,
                'cevap' => $bosMesaj,
            ];
        }
        $satirlar = [];
        $enArtan = null; $enDusen = null;
        foreach ($liste as $p) {
            $b = (float) $p['bu_ciro']; $o = (float) $p['on_ciro'];
            $fark  = $b - $o;
            $yuzde = $o > 0 ? (int) round(($fark / $o) * 100) : ($b > 0 ? 100 : 0);
            $yon   = ($fark > 0) ? 'up' : (($fark < 0) ? 'down' : 'flat');
            $satirlar[] = [
                'etiket' => $p['ad'], 'bu' => $this->tl($b), 'onceki' => $this->tl($o),
                'yuzde' => abs($yuzde), 'yon' => $yon, 'iyi' => ($fark >= 0),
            ];
            if ($enArtan === null || $fark > $enArtan['d']) $enArtan = ['ad' => $p['ad'], 'd' => $fark];
            if ($enDusen === null || $fark < $enDusen['d']) $enDusen = ['ad' => $p['ad'], 'd' => $fark];
        }
        $ozet = $baslikOn . ' karşılaştırması, ' . $buAd . ' ile ' . $onAd . '. ';
        if ($enArtan && $enArtan['d'] > 0) $ozet .= 'En çok artan ' . $enArtan['ad'] . '. ';
        if ($enDusen && $enDusen['d'] < 0) $ozet .= 'En çok düşen ' . $enDusen['ad'] . '. ';
        $ozet .= 'Kalem kalem detay listede.';

        return [
            'basarili' => true, 'intent' => 'karsilastirma', 'seslendir' => true,
            'cevap'    => $ozet,
            'kart'     => [
                'tip' => 'karsilastirma', 'baslik' => $baslikOn . ' · ' . ucfirst($buAd) . ' — ' . ucfirst($onAd),
                'bu_ad' => $buAd, 'onceki_ad' => $onAd, 'satirlar' => $satirlar,
            ],
        ];
    }

    /** RANDEVU odakli iki-donem karsilastirma: Randevu / Iptal / Gelmeyen bu vs onceki. */
    public function cevapRandevuKarsilastirma(array $bu, array $on, $buAd, $onAd)
    {
        $metrik = [
            ['etiket' => 'Randevu',  'bu' => (int) $bu['randevu'], 'on' => (int) $on['randevu'], 'artiIyi' => true],
            ['etiket' => 'İptal',    'bu' => (int) $bu['iptal'],   'on' => (int) $on['iptal'],   'artiIyi' => false],
            ['etiket' => 'Gelmeyen', 'bu' => (int) $bu['gelmedi'], 'on' => (int) $on['gelmedi'], 'artiIyi' => false],
        ];
        $satirlar = [];
        foreach ($metrik as $m) {
            $b = (float) $m['bu']; $o = (float) $m['on'];
            $fark  = $b - $o;
            $yuzde = $o > 0 ? (int) round(($fark / $o) * 100) : ($b > 0 ? 100 : 0);
            $yon   = ($fark > 0) ? 'up' : (($fark < 0) ? 'down' : 'flat');
            $iyi   = ($fark == 0) ? true : ((($fark > 0) && $m['artiIyi']) || (($fark < 0) && !$m['artiIyi']));
            $satirlar[] = [
                'etiket' => $m['etiket'], 'bu' => (string) (int) $b, 'onceki' => (string) (int) $o,
                'yuzde' => abs($yuzde), 'yon' => $yon, 'iyi' => $iyi,
            ];
        }
        $rFark = (int) $bu['randevu'] - (int) $on['randevu'];
        $rY = (int) $on['randevu'] > 0 ? (int) round(($rFark / (int) $on['randevu']) * 100) : 0;
        $ozet = ucfirst($buAd) . ', ' . $onAd . 'a göre randevu '
              . ($rFark >= 0 ? 'yüzde ' . abs($rY) . ' artmış' : 'yüzde ' . abs($rY) . ' azalmış')
              . '; ' . (int) $on['randevu'] . 'ten ' . (int) $bu['randevu'] . 'e. '
              . 'İptal ' . (int) $on['iptal'] . 'ten ' . (int) $bu['iptal'] . 'e, gelmeyen '
              . (int) $on['gelmedi'] . 'ten ' . (int) $bu['gelmedi'] . 'e. Detaylar listede.';

        return [
            'basarili' => true, 'intent' => 'karsilastirma', 'seslendir' => true,
            'cevap'    => $ozet,
            'kart'     => [
                'tip' => 'karsilastirma', 'baslik' => 'Randevu · ' . ucfirst($buAd) . ' — ' . ucfirst($onAd),
                'bu_ad' => $buAd, 'onceki_ad' => $onAd, 'satirlar' => $satirlar,
            ],
        ];
    }

    /** Mesaj bir BILANCO / GELIR TABLOSU / KASA RAPORU-OZETI istegi mi? (genis kalip). */
    public function bilancoTetik($metin)
    {
        $n = ' ' . $this->normalize($metin) . ' ';
        foreach ([
            // bilanco / gelir tablosu / kar-zarar
            'bilanco', 'kar zarar', 'kar-zarar', 'karzarar', 'gelir gider', 'gelir-gider',
            'gelir tablosu', 'kar zarar tablosu', 'net kar', 'kar durumu', 'kazanc gider',
            'mali durum', 'mali tablo', 'mali rapor', 'aylik bilanco', 'aylik rapor',
            // kasa raporu / ozeti + es anlamlilar
            'kasa raporu', 'kasa ozeti', 'kasa detay', 'kasa dokum', 'kasa analiz',
            'kasa durumu raporu', 'gelir gider raporu', 'gelir gider ozeti',
            'finansal ozet', 'finansal durum', 'finansal rapor', 'finansal tablo',
            'ozet rapor', 'genel mali', 'gelir raporu', 'gelir ozeti', 'ciro raporu',
            'ciro ozeti', 'hesap ozeti', 'hesap raporu', 'muhasebe ozeti',
        ] as $k) {
            if (strpos($n, $k) !== false) return true;
        }
        return false;
    }

    /** Kac aylik? "son N ay"/"N aylik"/"yillik". Sure belirtilmezse BU AY (1). */
    public function bilancoAySayisi($metin)
    {
        $n = $this->normalize($metin);
        if (preg_match('/son\s*(\d{1,2})\s*ay/', $n, $m)) { $s = (int) $m[1]; return ($s >= 1 && $s <= 24) ? $s : 1; }
        if (preg_match('/(\d{1,2})\s*ayl/', $n, $m)) { $s = (int) $m[1]; return ($s >= 1 && $s <= 24) ? $s : 1; }
        if (preg_match('/(\d{1,2})\s*ay/', $n, $m)) { $s = (int) $m[1]; return ($s >= 1 && $s <= 24) ? $s : 1; }
        if (strpos($n, 'yillik') !== false || strpos($n, 'bu yil') !== false || strpos($n, '12 ay') !== false) return 12;
        if (strpos($n, 'alti ay') !== false) return 6;
        if (strpos($n, 'gecen ay') !== false) return 2; // gecen ay dahil kiyas hissi
        return 1; // sure yok -> bu ay (kasa ozeti mantigi)
    }

    /** GELIR TABLOSU (kar-zarar) cevabi: gelir/gider DOKUMU + aylik trend. AI YOK. */
    public function cevapBilanco(array $b, $salonAdi = '')
    {
        $net = (float) ($b['net'] ?? 0);
        $marj = (float) ($b['marj'] ?? 0);

        // --- Gelir/Gider dokumu ---
        $dokum = [];
        $dokum[] = ['grup' => 'GELİR (TAHSİLAT)'];
        $dokum[] = ['etiket' => 'Toplam tahsilat', 'deger' => $this->tl((float) $b['toplam_tahsilat']), 'vurgu' => true];
        if ((float) $b['nakit']  > 0) $dokum[] = ['etiket' => 'Nakit',  'deger' => $this->tl((float) $b['nakit'])];
        if ((float) $b['kart']   > 0) $dokum[] = ['etiket' => 'Kart',   'deger' => $this->tl((float) $b['kart'])];
        if ((float) $b['havale'] > 0) $dokum[] = ['etiket' => 'Havale', 'deger' => $this->tl((float) $b['havale'])];
        if ((float) $b['diger']  > 0) $dokum[] = ['etiket' => 'Diğer',  'deger' => $this->tl((float) $b['diger'])];

        $dokum[] = ['grup' => 'SATIŞ DAĞILIMI'];
        $dokum[] = ['etiket' => 'Hizmet geliri', 'deger' => $this->tl((float) $b['hizmet_geliri'])];
        $dokum[] = ['etiket' => 'Ürün geliri',   'deger' => $this->tl((float) $b['urun_geliri'])];

        $dokum[] = ['grup' => 'GİDER'];
        $dokum[] = ['etiket' => 'Salon masrafı',   'deger' => $this->tl((float) $b['salon_masrafi'])];
        $dokum[] = ['etiket' => 'Personel gideri', 'deger' => $this->tl((float) $b['personel_gideri'])];
        $dokum[] = ['etiket' => 'Toplam gider',    'deger' => $this->tl((float) $b['toplam_gider']), 'vurgu' => true];

        $dokum[] = ['grup' => 'SONUÇ'];
        $dokum[] = ['etiket' => ($net >= 0 ? 'Net kâr' : 'Net zarar'), 'deger' => $this->tl(abs($net)), 'vurgu' => true, 'kar' => ($net >= 0)];
        $dokum[] = ['etiket' => 'Kâr marjı', 'deger' => '%' . round($marj)];

        // --- Aylik trend ---
        $satirlar = []; $enKarli = null;
        foreach (($b['aylar'] ?? []) as $a) {
            $an = (float) $a['net'];
            $satirlar[] = [
                'ay'    => $a['ay_adi'],
                'gelir' => $this->tl((float) $a['gelir']),
                'gider' => $this->tl((float) $a['gider']),
                'net'   => $this->tl($an),
                'kar'   => ($an >= 0),
            ];
            if ($enKarli === null || $an > $enKarli['net']) $enKarli = ['ay' => $a['ay_adi'], 'net' => $an];
        }

        $tekAy = ((int) $b['ay_sayisi']) <= 1;
        $donemAdi = $tekAy ? ($b['aylar'][0]['ay_adi'] ?? 'bu ay') : ('son ' . $b['ay_sayisi'] . ' ay');
        $baslik   = $tekAy ? ('Kasa Özeti · ' . $donemAdi) : ('Gelir Tablosu · son ' . $b['ay_sayisi'] . ' ay');

        $ozet = ($tekAy ? ucfirst($donemAdi) . ' kasa özeti. ' : 'Son ' . $b['ay_sayisi'] . ' ayın gelir tablosu. ')
              . 'Toplam tahsilat ' . $this->tl((float) $b['toplam_tahsilat']) . ', toplam gider '
              . $this->tl((float) $b['toplam_gider']) . ', net ' . ($net >= 0 ? 'kâr ' : 'zarar ')
              . $this->tl(abs($net)) . ', kâr marjı yüzde ' . round($marj) . '. ';
        if (!$tekAy && $enKarli) $ozet .= 'En kârlı ay ' . $enKarli['ay'] . '. ';
        $ozet .= $tekAy ? 'Gelir gider dökümü listede.' : 'Gelir gider dökümü ve aylık trend listede.';

        return [
            'basarili' => true, 'intent' => 'bilanco', 'seslendir' => true,
            'cevap'    => $ozet,
            'kart'     => [
                'tip'       => 'bilanco',
                'baslik'    => $baslik,
                'salon_adi' => (string) $salonAdi,
                'dokum'     => $dokum,
                'satirlar'  => $tekAy ? [] : $satirlar,
            ],
        ];
    }

    /**
     * Net KAPANIS/TESEKKUR/VEDA mesaji mi? ("tesekkurler kapat", "tamam yeter",
     * "sag ol gorusuruz"...). Boyleyse AI'ya GITMEDEN sohbete dusurulur; yoksa
     * konusma bellegi yuzunden onceki konu ("oneri ver") devam ediyor saniliyor.
     * Rapor/donem/soru baglami varsa (karisik mesaj) kapanis SAYILMAZ.
     */
    public function kapanisMi($metin)
    {
        $n = ' ' . $this->normalize($metin) . ' ';
        $kapanis = ['tesekkur', 'sagol', 'sag ol', 'saol', 'eyvallah', 'tamam', 'tamamdir',
            'kapat', 'yeter', 'bu kadar', 'gerek yok', 'bitti', 'anladim', 'peki tamam',
            'gorusuruz', 'hosca kal', 'hoscakal', 'iyi gunler', 'iyi aksamlar', 'iyi geceler',
            'kendine iyi bak', 'kapatabilirsin', 'yeterli', 'oldu bu kadar'];
        $var = false;
        foreach ($kapanis as $k) {
            if (strpos($n, ' ' . $k . ' ') !== false || strpos($n, ' ' . $k) !== false) { $var = true; break; }
        }
        if (!$var) return false;

        // Rapor/donem/soru baglami varsa kapanis sayma ("tesekkurler bu hafta ne durumda").
        foreach (['kasa', 'ciro', 'randevu', 'personel', 'hizmet', 'urun', 'musteri', 'iptal',
                  'oneri', 'oner', 'kampanya', 'ne kadar', 'hafta', 'aylik', 'bugun', 'nasil',
                  'degerlendir', 'satis', 'buyut'] as $r) {
            if (strpos($n, $r) !== false) return false;
        }
        return true;
    }

    /**
     * GENEL SOHBET (selam/hal hatir/tesekkur/kimsin/veda...). Eslesirse sicak bir
     * cevap dondurur, degilse null. Rapor niyeti BULUNAMAYINCA cagirilir; boylece
     * "merhaba bugun kasa" gibi cumlelerde once RAPOR cevabi verilir, salt "merhaba"da sohbet.
     * Bedava (kural motoru); cevaplar rastgele -> ezbere durmaz.
     */
    public function sohbetCevabi($metin)
    {
        $norm = ' ' . $this->normalize($metin) . ' ';

        // --- Hesaplanan cevaplar: saat / gun / tarih ---
        if (strpos($norm, ' saat kac ') !== false || strpos($norm, ' saati soyler ') !== false) {
            return $this->sohbetDon('Şu an saat ' . date('H:i') . '.');
        }
        if (strpos($norm, ' gunlerden ne ') !== false || strpos($norm, ' bugun ne gunu ') !== false
            || strpos($norm, ' hangi gundeyiz ') !== false || strpos($norm, ' bugun gunlerden ') !== false) {
            return $this->sohbetDon('Bugün ' . $this->gunAdi() . '.');
        }
        if (strpos($norm, ' tarih ne ') !== false || strpos($norm, ' bugunun tarihi ') !== false
            || strpos($norm, ' ayin kaci ') !== false || strpos($norm, ' bugun ayin ') !== false) {
            return $this->sohbetDon('Bugün ' . (int) date('d') . ' ' . $this->ayAdi() . ' ' . date('Y') . ', ' . $this->gunAdi() . '.');
        }

        $gruplar = [
            [ // selamlama
                'kelimeler' => ['merhaba','merhabalar','selam','selamlar','gunaydin','iyi gunler','iyi sabahlar','selamun aleykum','hey','alo','hosgeldin','hos geldin'],
                'cevaplar'  => [
                    'Merhaba, size nasıl yardımcı olabilirim?',
                    'Merhaba, hoş geldiniz. İşletmeniz hakkında ne öğrenmek istersiniz?',
                    'Selam, bugün size nasıl yardımcı olayım?',
                    'Merhaba efendim, sizi dinliyorum.',
                ],
            ],
            [ // hal hatir
                'kelimeler' => ['nasilsin','naber','ne haber','iyi misin','nasilsiniz','keyifler nasil'],
                'cevaplar'  => [
                    'İyiyim, teşekkür ederim. Siz nasılsınız, merak ettiğiniz bir şey var mı?',
                    'Gayet iyiyim, sağ olun. Size nasıl yardımcı olabilirim?',
                    'Turp gibiyim diyebilirim, hep hazırım. Ne öğrenmek istersiniz?',
                ],
            ],
            [ // tesekkur
                'kelimeler' => ['tesekkur','tesekkurler','sagol','sag ol','saol','eyvallah','ellerine saglik','minnettarim'],
                'cevaplar'  => [
                    'Rica ederim, başka bir konuda yardımcı olabilir miyim?',
                    'Ne demek, her zaman buradayım.',
                    'Rica ederim efendim.',
                    'Görev bizim, iyi çalışmalar dilerim.',
                ],
            ],
            [ // kimsin / ne yaparsin
                'kelimeler' => ['kimsin','sen kimsin','adin ne','ismin ne','sen nesin','ne yapabilirsin','neler yapabilirsin','ne is yaparsin','ne ise yararsin','ne yaparsin','gorevin ne','ne yapiyorsun','neler yapiyorsun','napiyorsun','ne is yapiyorsun','sen ne yapiyorsun','nasil calisirsin'],
                'cevaplar'  => [
                    'Ben salonunuzun asistanıyım. Size kasa ve ciro durumunu, en çok satılan hizmeti, personel performansını ve günün randevularını söyleyebilirim. Ayrıca işletmenizi büyütmek için öneriler sunar, indirim kampanyası hazırlayıp müşterilerinize gönderebilirim.',
                    'Salonunuzun dijital asistanıyım. Günlük özet, kasa, personel, hizmet ve randevu bilgisi verebilir, büyüme önerileri sunabilir, hatta müşterilerinize ücretsiz kampanya bildirimi gönderebilirim. Sadece sorun yeter.',
                ],
            ],
            [ // kisisel merak (oyunbaz)
                'kelimeler' => ['kac yasindasin','nerelisin','evli misin','robot musun','insan misin','gercek misin','cinsiyetin ne'],
                'cevaplar'  => [
                    'Ben dijital bir asistanım; yaşım yok ama işletmeniz için her zaman hazırım. Bir bilgi ister misiniz?',
                    'Sizin salon asistanınızım, gece gündüz buradayım. Ne öğrenmek istersiniz?',
                ],
            ],
            [ // dinliyor mu
                'kelimeler' => ['dinliyor musun','orada misin','beni duyuyor musun','uyuyor musun','mesgul musun'],
                'cevaplar'  => [
                    'Evet, buradayım ve sizi dinliyorum.',
                    'Buradayım efendim, sizi dinliyorum.',
                ],
            ],
            [ // ozur
                'kelimeler' => ['ozur dilerim','ozur','pardon','kusura bakma','affet','affedersin'],
                'cevaplar'  => [
                    'Rica ederim, önemli değil. Size nasıl yardımcı olabilirim?',
                    'Estağfurullah, hiç sorun değil.',
                ],
            ],
            [ // moral / empati
                'kelimeler' => ['moralim bozuk','yoruldum','yorgunum','sinirliyim','stresliyim','kotu hissediyorum','canim sikkin','bunaldim'],
                'cevaplar'  => [
                    'Anlıyorum, yoğun bir gün olabilir. İsterseniz bugünün özetine bakıp biraz rahatlayalım mı?',
                    'Geçer efendim, siz merak etmeyin. Dilerseniz işletmenizin durumuna göz atalım.',
                ],
            ],
            [ // onay / ack
                'kelimeler' => ['tamam','tamamdir','peki','anladim','oldu','olur','anlasildi'],
                'cevaplar'  => [
                    'Ne zaman isterseniz buradayım.',
                    'Emrinizdeyim efendim.',
                    'Başka bir şey öğrenmek isterseniz söylemeniz yeterli.',
                ],
            ],
            [ // yardim iste
                'kelimeler' => ['yardim et','yardimci ol','bana yardim','yardim eder misin','yardimini istiyorum'],
                'cevaplar'  => [
                    'Tabii ki. Kasa, ciro, hizmet, personel ya da randevular hakkında sorabilirsiniz. Örneğin "bugün nasıl" diyerek başlayabilirsiniz.',
                ],
            ],
            [ // veda
                'kelimeler' => ['gorusuruz','hosca kal','hoscakal','bay bay','baybay','gorusmek uzere','iyi geceler','kendine iyi bak'],
                'cevaplar'  => [
                    'Görüşmek üzere, iyi çalışmalar dilerim.',
                    'Hoşça kalın, bereketli işler.',
                    'İyi günler efendim, ihtiyaç olursa buradayım.',
                ],
            ],
            [ // takdir
                'kelimeler' => ['harikasin','bravo','aferin','cok iyisin','supersin','mukemmelsin','helal','tam isabet','cok tatlisin'],
                'cevaplar'  => [
                    'Teşekkür ederim, elimden geleni yapıyorum. Başka nasıl yardımcı olabilirim?',
                    'Çok naziksiniz, sağ olun. Emrinizdeyim.',
                ],
            ],
        ];
        foreach ($gruplar as $g) {
            foreach ($g['kelimeler'] as $k) {
                if (strpos($norm, ' ' . $this->normalize($k) . ' ') !== false) {
                    return $this->sohbetDon($g['cevaplar'][array_rand($g['cevaplar'])]);
                }
            }
        }
        return null;
    }

    /** Sohbet cevabi icin ortak sarmalayici. */
    protected function sohbetDon($cevap)
    {
        return [
            'basarili' => true, 'intent' => 'sohbet', 'seslendir' => true,
            'cevap' => $cevap, 'kart' => null,
        ];
    }

    /** Turkce gun adi (bugun). */
    protected function gunAdi()
    {
        $g = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
        return $g[(int) date('w')];
    }

    /** Turkce ay adi (bu ay). */
    protected function ayAdi()
    {
        $a = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz',
              'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
        return $a[(int) date('n')];
    }

    // ------------------------------------------------------------------
    // ASISTAN EGITIMI — bedava KALIP kutuphanesi + cevaplanamayan soru kaydi.
    // Amac: sorularin cogu AI'ya GITMEDEN kaliptan bedava cevaplansin.
    // ------------------------------------------------------------------

    /** Aktif kaliplari (10 dk cache). Panelde degisince Cache::forget ile tazelenir. */
    protected function kalipListesi()
    {
        try {
            // Cache'e dizi yazilir: config/cache.php serializable_classes=false, nesne geri okunamaz.
            $satirlar = Cache::remember('asistan_kalip_liste_v1', now()->addMinutes(10), function () {
                if (!Schema::hasTable('asistan_kalip')) return [];
                return DB::table('asistan_kalip')->where('aktif', 1)
                    ->select('id', 'tetikleyiciler', 'cevap')->get()
                    ->map(fn ($r) => (array) $r)->all();
            });
            return array_map(fn ($r) => (object) $r, $satirlar);
        } catch (\Throwable $e) { return []; }
    }

    /**
     * KALIP kutuphanesinden BEDAVA cevap; yoksa null. Rapor niyeti bulunamayinca,
     * AI'dan ONCE cagirilir. Tetik ifadelerin en UZUN (en spesifik) eslesmesi kazanir.
     */
    public function kalipCevabi($metin, $userId = 0)
    {
        $liste = $this->kalipListesi();
        if (empty($liste)) return null;

        $n = ' ' . $this->normalize($metin) . ' ';
        $enIyi = null; $enSkor = 0;
        foreach ($liste as $k) {
            foreach (preg_split('/[\r\n,;]+/', (string) $k->tetikleyiciler) as $t) {
                $t = trim($this->normalize($t));
                if (mb_strlen($t) < 2) continue;
                // TURKCE EK TOLERANSLI eslesme: kelime basinda tetik + (varsa) ek harfleri
                // + sinir. "dip boya" -> "dip boyam / dip boyasi / dip boyayi / dip boyama"
                // hepsini yakalar (aksi halde "dip boyaM geldi" kacar).
                if (preg_match('/(?:^| )' . preg_quote($t, '/') . '[a-z]*(?= |$)/u', $n)) {
                    $skor = mb_strlen($t);
                    if ($skor > $enSkor) { $enSkor = $skor; $enIyi = $k; }
                }
            }
        }
        if (!$enIyi) return null;

        try { DB::table('asistan_kalip')->where('id', $enIyi->id)->increment('kullanim_sayisi'); } catch (\Throwable $e) {}
        $cevap = $this->cevapSec($enIyi->cevap);
        $this->sonKalipKaydet($userId, $enIyi->id, $cevap);
        return [
            'basarili' => true, 'intent' => 'kalip', 'seslendir' => true,
            'cevap' => $cevap, 'kart' => null,
        ];
    }

    /**
     * Cevap havuzunu diziye ayirir: "---" satiriyla ayrilmis parcalar. Ayirici yoksa
     * tek elemanli dizi (geriye donuk uyumlu).
     */
    protected function cevapHavuzu($cevap)
    {
        $c = (string) $cevap;
        if (strpos($c, '---') === false) {
            $c = trim($c);
            return $c === '' ? [] : [$c];
        }
        $parcalar = preg_split('/^\s*-{3,}\s*$/m', $c);
        return array_values(array_filter(array_map('trim', $parcalar), function ($p) { return $p !== ''; }));
    }

    /** Havuzdan RASTGELE bir cevap secer (asistan hep ayni seyi soylemesin). */
    protected function cevapSec($cevap)
    {
        $h = $this->cevapHavuzu($cevap);
        if (empty($h)) return trim((string) $cevap);
        return count($h) === 1 ? $h[0] : $h[mt_rand(0, count($h) - 1)];
    }

    /** Kullanici basina EN SON eslesplen kalibi + verilen cevaplari 12 dk hatirla. */
    protected function sonKalipKaydet($userId, $kalipId, $verilenText)
    {
        if ((int) $userId <= 0) return;
        try {
            $k = 'patron_asistan_son_kalip:' . (int) $userId;
            $v = Cache::get($k);
            if (!is_array($v) || (int) ($v['id'] ?? 0) !== (int) $kalipId) {
                $v = ['id' => (int) $kalipId, 'verilen' => []];
            }
            $v['verilen'][] = (string) $verilenText;
            if (count($v['verilen']) > 30) $v['verilen'] = array_slice($v['verilen'], -30);
            $v['devam'] = 0; // TAM soru soruldu -> "biraz daha" sayaci sifirlanir (yeniden 4 hak)
            Cache::put($k, $v, now()->addMinutes(12));
        } catch (\Throwable $e) {}
    }

    /** Mesaj "ayni konuda biraz daha bilgi ver / detay / baska / devam" mi? */
    public function devamMi($metin)
    {
        $n = ' ' . $this->normalize($metin) . ' ';
        $anahtar = [
            'biraz daha', 'daha fazla', 'daha detay', 'detay ver', 'detaylandir',
            'baska ne', 'baska bilgi', 'devam et', 'devami', 'biraz anlat',
            'daha anlat', 'baska soyle', 'baska bir sey soyle', 'bilgi ver',
            'bilgilendir', 'ornek ver', 'aciklar misin', 'biraz daha bilgi',
        ];
        foreach ($anahtar as $a) {
            if (strpos($n, $a) !== false) return true;
        }
        $tek = trim($this->normalize($metin));
        return in_array($tek, ['devam', 'daha', 'baska', 'peki', 'sonra', 'anlat', 'aciklasana'], true);
    }

    /** Yeterince bilgi verildikten sonra NAZIK randevu yonlendirmesi (rastgele). */
    protected function randevuYonlendir()
    {
        $havuz = [
            'Bu konuda yeterince bilgi verebildiğimi düşünüyorum efendim 😊 Dilerseniz sizin için bir randevu oluşturabilirim; “randevu al” demeniz yeterli.',
            'Umarım aklınızdaki soruları yanıtlayabilmişimdir 😊 İsterseniz size uygun bir randevu ayarlayalım; “randevu al” demeniz kâfi.',
            'Sanırım gerekli bilgileri paylaştım efendim 😊 Dilerseniz sizi bir randevuya alabilirim; “randevu al” demeniz yeterli.',
            'Bu konuda elimden gelen bilgiyi vermeye çalıştım 😊 İsterseniz hemen bir randevu oluşturalım; “randevu al” demeniz yeterli.',
            'Merak ettiklerinizi aktarabildiysem ne mutlu bana 😊 Dilerseniz sizin için bir randevu planlayabilirim; “randevu al” demeniz yeterli.',
        ];
        return $havuz[mt_rand(0, count($havuz) - 1)];
    }

    /**
     * AYNI KONUDA "biraz daha bilgi ver" -> en son eslesplen kalibin cevap HAVUZUNDAN
     * DAHA ONCE VERILMEMIS bir cevabi getirir (bedava). Yakinda bir kalip cevabi
     * verilmediyse ya da devam ifadesi degilse null (normal akis islesin).
     */
    public function kalipDevam($metin, $userId)
    {
        if ((int) $userId <= 0 || !$this->devamMi($metin)) return null;
        try {
            $k = 'patron_asistan_son_kalip:' . (int) $userId;
            $v = Cache::get($k);
            if (!is_array($v) || empty($v['id'])) return null;
            $row = DB::table('asistan_kalip')->where('id', (int) $v['id'])->where('aktif', 1)->first();
            if (!$row) return null;

            // 4 kez ek bilgi verdikten SONRA: bilgi doniyoruz -> nazikce randevuya yonlendir.
            $devamSayisi = (int) ($v['devam'] ?? 0);
            if ($devamSayisi >= 4) {
                return [
                    'basarili' => true, 'intent' => 'kalip', 'seslendir' => true, 'kart' => null,
                    'cevap' => $this->randevuYonlendir(),
                ];
            }

            $havuz = $this->cevapHavuzu($row->cevap);
            if (count($havuz) < 2) {
                return [
                    'basarili' => true, 'intent' => 'kalip', 'seslendir' => true, 'kart' => null,
                    'cevap' => $this->randevuYonlendir(),
                ];
            }
            $verilen = is_array($v['verilen'] ?? null) ? $v['verilen'] : [];
            $kalan = array_values(array_filter($havuz, function ($c) use ($verilen) {
                return !in_array($c, $verilen, true);
            }));
            if (empty($kalan)) {
                // Havuz bitti -> nazikce randevuya yonlendir (tekrar etmesin).
                return [
                    'basarili' => true, 'intent' => 'kalip', 'seslendir' => true, 'kart' => null,
                    'cevap' => $this->randevuYonlendir(),
                ];
            }
            $sec = $kalan[mt_rand(0, count($kalan) - 1)];
            $v['verilen'][] = $sec;
            $v['devam'] = $devamSayisi + 1;
            Cache::put($k, $v, now()->addMinutes(12));
            try { DB::table('asistan_kalip')->where('id', (int) $v['id'])->increment('kullanim_sayisi'); } catch (\Throwable $e) {}
            return [
                'basarili' => true, 'intent' => 'kalip', 'seslendir' => true, 'kart' => null,
                'cevap' => $sec,
            ];
        } catch (\Throwable $e) { return null; }
    }

    /** Kural+kalip bulamayip AI'ya/genel yanita dusen soruyu (adet sayacli) kaydet. */
    public function cozulemeyenKaydet($metin)
    {
        try {
            if (!Schema::hasTable('asistan_cozulmeyen')) return;
            $norm = mb_substr($this->normalize($metin), 0, 191);
            if ($norm === '') return;
            $row = DB::table('asistan_cozulmeyen')->where('soru_norm', $norm)->first();
            if ($row) {
                DB::table('asistan_cozulmeyen')->where('id', $row->id)->update([
                    'adet' => $row->adet + 1, 'ham' => mb_substr((string) $metin, 0, 500),
                    'son_tarih' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } else {
                DB::table('asistan_cozulmeyen')->insert([
                    'soru_norm' => $norm, 'ham' => mb_substr((string) $metin, 0, 500), 'adet' => 1,
                    'son_tarih' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {}
    }

    /**
     * Cevaptan "buyurun" kelimesini (ve cevresindeki noktalamayi) suzer. Kaynak ne
     * olursa olsun (AI, cache, kural) kullaniciya gitmeden temizlenir. Cumleyi bozmaz.
     */
    public function buyurunTemizle($cevap)
    {
        $c = (string) $cevap;
        if (stripos($c, 'buyurun') === false) return $c;
        $c = preg_replace('/\bbuyur[uü]n\b[ ]*[,\.\!]?/iu', '', $c); // kelime + bitisik noktalama
        $c = preg_replace('/[ ]{2,}/u', ' ', $c);                     // cift bosluk
        $c = preg_replace('/[ ]+([,\.\!\?])/u', '$1', $c);            // noktalama oncesi bosluk
        $c = preg_replace('/^[ ,\.\!]+/u', '', $c);                   // bastaki artik
        $c = trim($c);
        if ($c !== '') $c = mb_strtoupper(mb_substr($c, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($c, 1, null, 'UTF-8');
        return $c;
    }

    /**
     * Cevaptan EMOJI ve piktogramlari suzer (TTS "aglayan yuz / gulen yuz" diye
     * OKUMASIN diye). Genis Unicode emoji araliklari + varyasyon/ZWJ temizlenir.
     */
    public function emojiTemizle($cevap)
    {
        $c = (string) $cevap;
        $c = preg_replace(
            '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}'
            . '\x{2300}-\x{23FF}\x{2190}-\x{21FF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}]/u',
            '',
            $c
        );
        $c = preg_replace('/[ ]{2,}/u', ' ', $c);            // cift bosluk
        $c = preg_replace('/[ ]+([,\.\!\?])/u', '$1', $c);   // noktalama oncesi bosluk
        return trim($c);
    }

    /**
     * Terbiyesiz / kufur / hakaret iceriyor mu? Kelime siniriyla aranir (yanlis
     * pozitif azaltmak icin; is terimleriyle cakisan kelimeler listede YOK).
     */
    public function kufurMu($metin)
    {
        $norm = ' ' . $this->normalize($metin) . ' ';
        $kelimeler = [
            // --- Agir / cinsel kufurler ---
            'amk', 'aq', 'amina', 'amina koyayim', 'amina koyim', 'aminakoyim', 'amcik', 'amcik agzi',
            'orospu', 'orospu cocugu', 'oc', 'pic', 'pic kurusu', 'siktir', 'siktir git', 'siktir lan',
            'sikeyim', 'sikerim', 'sikik', 'siktigim', 'sicayim', 'sicarim', 'yarrak', 'yarak', 'yarragi',
            'gavat', 'kahpe', 'ibne', 'pust', 'kaltak', 'fahise', 'surtuk', 'pezevenk', 'godos', 'yavsak',
            'anani', 'anani sikeyim', 'avradini', 'sulaleni', 'sulalene',
            // --- Hakaret / asagilama ---
            'serefsiz', 'onursuz', 'haysiyetsiz', 'alcak', 'soysuz', 'asalak', 'suluk', 'yalaka', 'dalkavuk',
            'gerizekali', 'geri zekali', 'beyinsiz', 'dangalak', 'salak', 'aptal', 'embesil', 'ahmak',
            'gerzek', 'budala', 'sersem', 'hoduk', 'denyo', 'psikopat', 'sapik', 'ucube', 'zibidi',
            'pislik', 'igrenc', 'madara', 'defol', 'defol git', 'cehennem ol',
            // --- Beddua / kargis ---
            'allah belani versin', 'belani versin', 'cezani versin', 'allah cezani versin',
            'allah kahretsin', 'kahrol', 'gebersin', 'geber', 'gebertirim', 'canin ciksin',
            'canin cehenneme', 'lanet olsun', 'nalet olsun',
        ];
        foreach ($kelimeler as $k) {
            if (strpos($norm, ' ' . $this->normalize($k) . ' ') !== false) {
                return true;
            }
        }
        return false;
    }

    /** Terbiyesiz konusmaya NAZIK uyari. */
    public function kufurCevabi()
    {
        return [
            'basarili'  => true,
            'intent'    => 'uyari',
            'seslendir' => true,
            'cevap'     => 'Efendim sizi saygıya davet ediyorum. Eğer böyle konuşmaya devam ederseniz görüşmeyi kapatmak zorunda kalacağım.',
            'kart'      => null,
        ];
    }

    /**
     * Niyet anlasilamadi / konu disi soru (ör. mac skoru, hava durumu).
     * Siri tarzi nazik geri cevirme + salon konularina yonlendirme.
     */
    public function yardimCevabi(array $niyet = [])
    {
        return [
            'basarili' => true,
            'intent'   => 'bilinmiyor',
            'seslendir'=> true,
            'cevap'    => "Efendim bu konu hakkında bilgim yok, ama isterseniz salonunuz "
                        . "hakkında bilgi verebilirim. Örneğin bugünkü kasa, en çok satılan "
                        . "hizmet ya da personel performansı gibi konularda yardımcı olabilirim.",
            'ornekler' => [
                'Bugün kasa ne durumda?',
                'Bu ay ciro ne kadar?',
                'Bu hafta en çok kim sattı?',
                'Bugün kaç randevu var?',
            ],
            'kart'  => null,
            'niyet' => $niyet,
        ];
    }

    // ------------------------------------------------------------------
    // Yardimcilar
    // ------------------------------------------------------------------



    /** Turkce para bicimi: 12.500 ₺ */
    protected function tl($tutar)
    {
        // Sonuna "TL" (₺ sembolunu TTS okuyamiyordu). Binlik ayraci nokta kalir.
        return number_format((float) $tutar, 0, ',', '.') . ' TL';
    }

    /** Turkce metni kucuk harfe + ASCII-benzeri hale getirir (aksan/karakter toleransi). */
    protected function normalize($metin)
    {
        $metin = mb_strtolower(trim((string) $metin), 'UTF-8');
        $ceviri = [
            'ç'=>'c','ğ'=>'g','ı'=>'i','İ'=>'i','ö'=>'o','ş'=>'s','ü'=>'u',
            'â'=>'a','î'=>'i','û'=>'u',
        ];
        $metin = strtr($metin, $ceviri);
        // Kalan buyuk I gibi durumlar
        $metin = str_replace(['i̇'], ['i'], $metin);
        return $metin;
    }
}
