<?php

namespace App\Domain\Karsilastirma;

/**
 * Eski mobil ucuyla yeni ucun esitlik kontrolleri — TEK KAYNAK. Yalniz okuma: eski uc GET veya okuma amacli POST, yeni uc yalniz GET.
 *
 * Kontrol: `eski` [yontem, yol, sorgu, govde]; `yeni` [yol, sorgu]; `alanlar` ve `eslestir` AlanKarsilastirici yol bicimindedir;
 * `eski_kok` varsayilan govde, `yeni_kok` varsayilan zarfin `veri` alani; `token` varsayilan isletme.
 * Yer tutucu: `{x}` zorunlu (yoksa kontrol atlanir), `{x?}` bos olabilir, `{x~}` bossa alan gonderilmez, `{x|y}` ilk dolu deger.
 * Her zaman dolu: salon, bas, bit, bugun, ay, yil, donem, app_bundle. Digerleri komutta --deger=ad=deger ile verilir.
 */
final class MobilTutarKontrolleri
{
    public const TOKEN_ISLETME = 'isletme';

    public const TOKEN_MUSTERI = 'musteri';

    public const TOKEN_PERSONEL = 'personel';

    public const ESKI_TABAN = 'https://app.randevumcepte.com.tr/api/v1';

    /** Salt okunur aracin disinda kalanlar ve nedeni; listeye alinmaz. */
    public const KAPSAM_DISI = [
        'Paket yazma satirlari' => 'yazma: kod okumayla veya test salonunda',
        'Seans yazma satirlari' => 'yazma: kod okumayla veya test salonunda',
        'Yazma karsilastirmasi (tahsilat, taksit, senet, harici, hizli satis, kampanya kodu)' => 'yazma: yalniz test salonunda ikiz kayitla',
        'Kontor WebView sayfasi' => 'HTML sayfa ve eski uc oturum acar: gozle karsilastirilir',
    ];

    /**
     * Eski mobille esitligi artik BEKLENMEYEN yazma yollari (kullanici karari 2026-11-08): odeme ve indirim dagitimi web ile ayni
     * kural ve kod (TahsilatDagitim, TahsilatYazServisi). Test salonundaki ikiz kayit karsilastirmasinda bu satirlarin kalem
     * tutarlari eski mobilden farkli cikar; fark hata sayilmaz.
     */
    public const BILINCLI_FARK = [
        '/tahsilatekle kalem paylari' => 'bilincli fark: web kurali (kalan borca oranli, kalanla sinirli, kurus-kesin; eski: fiyata oranli, yuvarlanmaz)',
        '/tahsilatekle taksit ve senet vadesi' => 'bilincli fark: web kurali (komisyon dusulur, vadeye en cok kendi tutari, plan kalaniyla sinirli, kismi odemede vade bolunur; eski: her vadeye odemenin tamami, "100.00" fiyat 10000 okunur)',
        '/tahsilatekle musteri ve harici indirim' => 'bilincli fark: web kurali (kalem kalaniyla sinirli, kurus-kesin, harici indirim delta kaydi)',
        '/harici-tahsilat-ekle kalem paylari' => 'bilincli fark: web kurali (kurus-kesin)',
        '/stok/hizli-satis ve /cagri-merkezi/hizli-satis kalem satirlari' => 'bilincli fark: web kurali (satir tutari iki hane, toplam kurus-kesin)',
        '/taksitekleguncelle on odeme paylari' => 'bilincli fark: web kurali (tek havuz, kurus artigi en buyuk kaleme)',
        '/taksitekleguncelle vade tutar ve tarihleri' => 'bilincli fark: TaksitPlani (vadelerin toplami plana esit, artik kurus ilk vadelere; tarih ay sonuna kirpilir; eski: 1000/3 = 3 x 333.33, 31 Ocak sonrasi 3 Mart)',
    ];

    public static function yazmaYoluMu(string $yol): bool
    {
        return preg_match('/ekle|sil\b|sil\/|guncelle|kaydet|gonder|isaretle|toggle|senetode\b|primode\b|kod-kullan|talep|tahsilet|hizli-satis/i', $yol) === 1;
    }

    /** @return array<int,array<string,mixed>> */
    public static function hepsi(): array
    {
        return array_merge(self::satis(), self::tahsilat(), self::kasa(), self::rapor(), self::prim(), self::paketSeans(), self::ozet());
    }

    private static function eskiSatis(array $alan): array
    {
        return ['POST', '/satislar', ['page' => 1], $alan + [
            'salonid' => '{salon}', 'tarih1' => '{bas}', 'tarih2' => '{bit}', 'musteriid' => '', 'adisyonturu' => '',
            'personel_id' => '', 'musteriMi' => false, 'user_id' => '', 'appBundle' => '{app_bundle}',
        ]];
    }

    private static function yeniSatis(array $alan): array
    {
        return ['/satislar', $alan + [
            'mobil' => 1, 'salon_id' => '{salon}', 'tarih1' => '{bas}', 'tarih2' => '{bit}', 'musteriid' => '', 'adisyonturu' => '',
            'user_id' => '', 'page' => 1,
        ]];
    }

    private static function satis(): array
    {
        $sayaclar = ['satisSayisi', 'acik_sayisi', 'kapali_sayisi', 'odenen', 'kalan'];
        $kisa = [...$sayaclar, 'data[].id', 'data[].toplam_numeric', 'data[].odenen_numeric', 'data[].kalan_tutar_numeric'];

        $liste = [[
            'ad' => 'Satis listesi - isletme, tumu',
            'eski' => self::eskiSatis(['tarih1' => '1970-01-01', 'tarih2' => '{bugun}', 'acikKapali' => -1]),
            'yeni' => self::yeniSatis(['tarih1' => '1970-01-01', 'tarih2' => '{bugun}', 'acikKapali' => -1]),
            'alanlar' => [...$sayaclar, 'current_page', 'last_page', 'total', 'data[].id', 'data[].toplam', 'data[].toplam_numeric', 'data[].odenen',
                'data[].odenen_numeric', 'data[].kalan_tutar', 'data[].kalan_tutar_numeric', 'data[].hakedisTutar', 'data[].hizmetHakedisTutar',
                'data[].urunHakedisTutar', 'data[].paketHakedisTutar', 'data[].hizmetToplam', 'data[].urunToplam', 'data[].paketToplam', 'data[].icerik',
                'data[].icerikKisaltilmis', 'data[].hizmet_veren', 'data[].durum', 'data[].son_tahsilat_tarihi', 'data[].planlanan_alacak_tarihi',
                'data[].fatura_kesildi', 'data[].odendi', 'data[].odendi_tarihi'],
        ]];
        foreach ([1, 2, 3] as $tur) {
            $liste[] = [
                'ad' => "Satis listesi - acik sekme, tur $tur",
                'eski' => self::eskiSatis(['adisyonturu' => $tur, 'acikKapali' => 1]),
                'yeni' => self::yeniSatis(['adisyonturu' => $tur, 'acikKapali' => 1]),
                'alanlar' => [...$sayaclar, 'last_page', 'data[].id', 'data[].toplam_numeric', 'data[].odenen_numeric', 'data[].kalan_tutar_numeric', 'data[].durum'],
            ];
        }
        $liste[] = [
            'ad' => 'Satis listesi - kapali sekme, personel filtresi',
            'token' => self::TOKEN_PERSONEL,
            'eski' => self::eskiSatis(['user_id' => '{personel_user_id}', 'acikKapali' => 0]),
            'yeni' => self::yeniSatis(['user_id' => '{personel_user_id}', 'acikKapali' => 0]),
            'alanlar' => [...$kisa, 'data[].hakedisTutar', 'data[].hizmetToplam', 'data[].urunToplam', 'data[].paketToplam'],
        ];
        $liste[] = [
            'ad' => 'Satis listesi - faturasiz_gizle acik salon',
            'eski' => self::eskiSatis(['salonid' => '{faturasiz_salon}', 'acikKapali' => -1]),
            'yeni' => self::yeniSatis(['salon_id' => '{faturasiz_salon}', 'acikKapali' => -1]),
            'alanlar' => [...$sayaclar, 'data[].id'],
        ];
        $liste[] = [
            'ad' => 'Satis listesi - studyo salonu',
            'eski' => self::eskiSatis(['salonid' => '{studyo_salon}', 'acikKapali' => 1]),
            'yeni' => self::yeniSatis(['salon_id' => '{studyo_salon}', 'acikKapali' => 1]),
            'alanlar' => ['satisSayisi', 'acik_sayisi', 'kapali_sayisi', 'data[].id', 'data[].odendi', 'data[].odendi_tarihi', 'data[].kalan_tutar_numeric'],
        ];
        $liste[] = [
            'ad' => 'Satis listesi - musteri karti',
            'eski' => self::eskiSatis(['musteriid' => '{musteri_id}', 'acikKapali' => 0]),
            'yeni' => self::yeniSatis(['musteriid' => '{musteri_id}', 'acikKapali' => 0]),
            'alanlar' => $kisa,
        ];
        $liste[] = [
            'ad' => 'Satis listesi - musteri uygulamasi',
            'token' => self::TOKEN_MUSTERI,
            'eski' => self::eskiSatis(['musteriid' => '{musteri_id}', 'musteriMi' => true, 'acikKapali' => 0]),
            'yeni' => ['/musteri-uygulama/satislar', ['salon_id' => '{salon}', 'app_bundle' => '{app_bundle}', 'tarih1' => '{bas}', 'tarih2' => '{bit}', 'acik_kapali' => 0, 'page' => 1]],
            'alanlar' => [...$sayaclar, 'last_page', 'data[].id', 'data[].salon_adi', 'data[].icerik', 'data[].toplam', 'data[].odenen', 'data[].kalan_tutar', 'data[].durum'],
        ];

        return array_merge($liste, [
            [
                'ad' => 'Gunluk paket satislari',
                'eski' => ['POST', '/paketsatisget/{salon}', ['page' => 1], ['salon_id' => '{salon}', 'musteridanisan' => '']],
                'yeni' => ['/satislar/paket-gunluk', ['mobil' => 1, 'salon_id' => '{salon}', 'page' => 1, 'musteridanisan' => '']],
                'alanlar' => ['total', 'current_page', 'last_page', 'data[].id', 'data[].fiyat', 'data[].paket_adi', 'data[].satan', 'data[].musteri', 'data[].tarih'],
            ],
            [
                'ad' => 'Gunluk urun satislari',
                'eski' => ['POST', '/urunsatisget/{salon}', ['page' => 1], ['salon_id' => '{salon}', 'musteridanisan' => '']],
                'yeni' => ['/satislar/urun-gunluk', ['mobil' => 1, 'salon_id' => '{salon}', 'page' => 1, 'musteridanisan' => '']],
                'alanlar' => ['total', 'current_page', 'last_page', 'data[].id', 'data[].fiyat', 'data[].urun_adi', 'data[].satan', 'data[].musteri'],
            ],
            [
                'ad' => 'Musteri turu',
                'eski' => ['POST', '/musteri-danisan-turunu-getir', [], ['musteri_id' => '{musteri_id}', 'salon_id' => '{salon}']],
                'yeni' => ['/satislar/musteri-turu', ['mobil' => 1, 'salon_id' => '{salon}', 'musteri_id' => '{musteri_id}']],
                'alanlar' => [['$', 'tur']],
            ],
            [
                'ad' => 'Faturasiz gizle durumu',
                'eski' => ['GET', '/faturasizgizledurum/{salon}'],
                'yeni' => ['/satislar/faturasiz-gizle', ['mobil' => 1, 'salon_id' => '{salon}']],
                'alanlar' => ['faturasiz_gizle'],
            ],
        ]);
    }

    private static function tahsilat(): array
    {
        $liste = [
            [
                'ad' => 'Alacaklar',
                'eski' => ['POST', '/alacaklar/{salon}', ['page' => 1], ['id' => '{salon}', 'musteridanisan' => '']],
                'yeni' => ['/mobil-para/alacaklar', ['salon_id' => '{salon}', 'page' => 1, 'ara' => '', 'mobil' => 1]],
                'alanlar' => ['total', 'last_page', 'data[].id', 'data[].musteri', 'data[].tutar', 'data[].planlanan_odeme_tarihi', 'data[].olusturulma'],
            ],
            [
                'ad' => 'Alacaklar v2',
                'eski' => ['POST', '/alacaklar-v2/{salon}', ['page' => 1], ['id' => '{salon}', 'musteridanisan' => '']],
                'yeni' => ['/mobil-para/alacaklar-v2', ['salon_id' => '{salon}', 'page' => 1, 'ara' => '', 'mobil' => 1]],
                'alanlar' => ['total', 'last_page', 'data[].id', 'data[].user_id', 'data[].adisyon_id', 'data[].musteri', 'data[].kalem', 'data[].tutar',
                    'data[].planlanan_odeme_tarihi', 'data[].olusturulma', 'data[].vadesi_gecmis'],
            ],
            [
                'ad' => 'Tum alacaklar, adisyonsuz',
                'eski' => ['POST', '/tum-alacaklar', [], ['salon_id' => '{salon}', 'musteri_id' => '{musteri_id}', 'adisyon_id' => '']],
                'yeni' => ['/mobil-para/tum-alacaklar', ['salon_id' => '{salon}', 'musteri_id' => '{musteri_id}', 'adisyon_id' => '', 'mobil' => 1]],
                'alanlar' => ['adisyon_hizmet[].id', 'adisyon_hizmet[].fiyat', 'adisyon_hizmet[].indirim_tutari', 'adisyon_hizmet[].hediye', 'adisyon_hizmet[].hizmet.hizmet_adi',
                    'adisyon_hizmet[].personel.personel_adi', 'adisyon_urun[].id', 'adisyon_urun[].fiyat', 'adisyon_urun[].adet', 'adisyon_urun[].indirim_tutari', 'adisyon_urun[].urun.urun_adi',
                    'adisyon_paket[].id', 'adisyon_paket[].fiyat', 'adisyon_paket[].indirim_tutari', 'adisyon_paket[].paket.paket_adi', 'senet[].id', 'senet[].kalem_ozeti', 'senet[].created_at',
                    'senet[].vadeler[].id', 'senet[].vadeler[].tutar', 'senet[].vadeler[].odendi', 'senet[].vadeler[].vade_tarih', 'taksit[].id', 'taksit[].kalem_ozeti',
                    'taksit[].vadeler[].id', 'taksit[].vadeler[].tutar', 'taksit[].vadeler[].odendi', 'taksit[].vadeler[].vade_tarih'],
            ],
            [
                'ad' => 'Tum alacaklar, adisyonlu',
                'eski' => ['POST', '/tum-alacaklar', [], ['salon_id' => '{salon}', 'musteri_id' => '{musteri_id}', 'adisyon_id' => '{adisyon_id}']],
                'yeni' => ['/mobil-para/tum-alacaklar', ['salon_id' => '{salon}', 'musteri_id' => '{musteri_id}', 'adisyon_id' => '{adisyon_id}', 'mobil' => 1]],
                'alanlar' => ['adisyon_hizmet[].fiyat', 'adisyon_urun[].fiyat', 'adisyon_paket[].fiyat', 'senet[].id', 'taksit[].id'],
            ],
        ];
        foreach (['Tümü', 'Kapalı', 'Açık', 'Ödenmemiş'] as $durum) {
            $liste[] = [
                'ad' => "Senetler, $durum",
                'eski' => ['POST', '/senetler/{salon}', ['page' => 1], ['arama' => '', 'durum' => $durum]],
                'yeni' => ['/mobil-para/senetler', ['salon_id' => '{salon}', 'page' => 1, 'ara' => '', 'durum' => $durum, 'mobil' => 1]],
                'alanlar' => ['total', 'last_page', 'data[].id', 'data[].created_at', 'data[].senet_turu', 'data[].kefil_adi', 'data[].musteri.name', 'data[].musteri.adres',
                    'data[].musteri.tc_kimlik_no', 'data[].vadeler[].id', 'data[].vadeler[].tutar', 'data[].vadeler[].vade_tarih', 'data[].vadeler[].odendi', 'data[].vadeler[].notlar'],
            ];
        }

        return array_merge($liste, [
            [
                'ad' => 'Harici kalemler',
                'eski' => ['POST', '/harici-tahsilat-kalemler/{salon}', [], ['salon_id' => '{salon}']],
                'yeni' => ['/mobil-para/harici-kalemler', ['salon_id' => '{salon}', 'mobil' => 1]],
                'alanlar' => ['hizmetler[].id', 'hizmetler[].ad', 'hizmetler[].fiyat', 'urunler[].id', 'urunler[].ad', 'urunler[].fiyat', 'paketler[].id', 'paketler[].ad', 'paketler[].fiyat'],
            ],
            [
                'ad' => 'Cagri kalemleri',
                'eski' => ['POST', '/cagri-merkezi/satis-kalemler', [], ['sube' => '{salon}']],
                'yeni' => ['/mobil-para/cagri-kalemler', ['salon_id' => '{salon}', 'mobil' => 1]],
                'alanlar' => ['hizmetler[].fiyat', 'urunler[].fiyat', 'paketler[].fiyat', 'odeme_yontemleri[].id', 'odeme_yontemleri[].ad'],
            ],
            [
                'ad' => 'Kampanya kodlari',
                'eski' => ['GET', '/kampanyaAdmin/kodlar/{salon}', ['user_id' => '{musteri_id}']],
                'yeni' => ['/mobil-para/kampanya-kodlari', ['salon_id' => '{salon}', 'user_id' => '{musteri_id}', 'mobil' => 1]],
                'alanlar' => ['kodlar[].kod', 'kodlar[].indirim'],
            ],
            [
                'ad' => 'Kampanya kodu adisyon durumu',
                'eski' => ['POST', '/kampanyaKoduAdisyonDurum/{salon}', [], ['adisyon_id' => '{adisyon_id}']],
                'yeni' => ['/mobil-para/kampanya-kod-durum', ['salon_id' => '{salon}', 'adisyon_id' => '{adisyon_id}', 'mobil' => 1]],
                'alanlar' => ['uygulanmis', 'kod', 'metin'],
            ],
        ]);
    }

    private static function kasa(): array
    {
        $aralik = ['tarih1' => '{bas}', 'tarih2' => '{bit}'];
        $yeni = ['salon_id' => '{salon}', 'mobil' => 1, 'baslangic' => '{bas}', 'bitis' => '{bit}', 'odeme_yontemi_id' => '{odeme_yontemi_id~}'];
        $satir = fn (string $l, array $alanlar) => array_map(fn ($a) => $l.'[].'.$a, $alanlar);

        return [
            [
                'ad' => 'Kasa ozeti',
                'eski' => ['POST', '/kasaraporu/{salon}', [], $aralik + ['odemeyontemi' => '{odeme_yontemi_id?}']],
                'yeni' => ['/kasa/ozet', $yeni],
                'alanlar' => ['toplamgelir', 'toplamgider', 'toplamKasa', 'toplamCiro'],
            ],
            [
                'ad' => 'Tahsilat listesi',
                'eski' => ['POST', '/tahsilatraporu/{salon}', ['page' => 1], $aralik + ['odemeyontemi' => '{odeme_yontemi_id?}']],
                'yeni' => ['/kasa/gelirler', $yeni + ['page' => 1]],
                'alanlar' => ['total', 'last_page', 'current_page', 'data[].id', 'data[].tutar', 'data[].odeme_tarihi', 'data[].odeme_yontemi_id', 'data[].notlar', 'data[].musteri.name',
                    'data[].olusturan.personel_adi', 'data[].hizmet_odemeleri[].tutar', 'data[].urun_odemeleri[].tutar', 'data[].paket_odemeleri[].tutar'],
            ],
            [
                'ad' => 'Masraf listesi',
                'eski' => ['POST', '/masrafraporu/{salon}', ['page' => 1], $aralik + ['odemeyontemi' => '{odeme_yontemi_id?}', 'harcayan' => '{harcayan?}']],
                'yeni' => ['/kasa/giderler', $yeni + ['page' => 1, 'harcayan' => '{harcayan~}']],
                'alanlar' => ['total', 'last_page', 'current_page', 'data[].id', 'data[].tutar', 'data[].tarih', 'data[].aciklama', 'data[].personel_gideri', 'data[].harcayan.personel_adi',
                    'data[].masraf_kategorisi.kategori', 'data[].odeme_yontemi.odeme_yontemi'],
            ],
            [
                'ad' => 'Gun sonu',
                'eski' => ['POST', '/gunsonuraporu/{salon}', [], $aralik],
                'yeni' => ['/kasa/gun-sonu', ['salon_id' => '{salon}', 'mobil' => 1, 'baslangic' => '{bas}', 'bitis' => '{bit}']],
                'alanlar' => ['gelir', 'gelir_toplam', 'masraf', 'masraf_toplam', 'net', 'net_toplam', 'islem_say', 'musteri_say', 'ort_sepet',
                    ...$satir('personeller', ['personel_adi', 'adet', 'ciro']),
                    ...$satir('top_hizmet', ['hizmet_adi', 'adet', 'ciro']),
                    ...$satir('top_urun', ['urun_adi', 'adet', 'ciro']),
                    ...$satir('satislar', ['saat', 'musteri', 'tahsil_eden', 'yontem', 'para_girisi', 'tutar']),
                    ...$satir('isletme_masraflari', ['harcayan', 'aciklama', 'yontem', 'tutar']),
                    ...$satir('personel_giderleri', ['harcayan', 'aciklama', 'yontem', 'tutar', 'tip']),
                    'isletme_masraf_toplam', 'personel_gider_toplam'],
            ],
            [
                'ad' => 'Devreden aylar',
                'eski' => ['POST', '/devredenAylar', [], ['salonId' => '{salon}', 'yil' => '{yil}']],
                'eski_kok' => 'data',
                'yeni' => ['/kasa/aylik', ['salon_id' => '{salon}', 'mobil' => 1, 'yil' => '{yil}']],
                'alanlar' => ['[].ay', '[].ay_adi', '[].yil', '[].tahsilatlar', '[].masraflar', '[].donem_net_kar'],
            ],
        ];
    }

    private static function rapor(): array
    {
        $aralik = ['salonId' => '{salon}', 'tarih1' => '{bas}', 'tarih2' => '{bit}'];
        $yeni = ['salon_id' => '{salon}', 'mobil' => 1, 'baslangic' => '{bas}', 'bitis' => '{bit}'];
        $ortak = ['id', 'adet', 'toplam_tutar', 'toplamKazanc', 'borc', 'toplamTutarNumeric', 'toplamKazancNumeric', 'borcNumeric'];

        $liste = [];
        foreach ([
            ['Hizmet raporu', 'hizmetRaporlari', 'hizmet', ['id']],
            ['Urun raporu', 'urunRaporlari', 'urun', ['id']],
            ['Paket raporu', 'paketRaporlari', 'paket', ['paket_id', 'paket_adi']],
        ] as [$ad, $yol, $sekme, $anahtar]) {
            $liste[] = [
                'ad' => $ad,
                'eski' => ['POST', "/$yol", [], $aralik + ['personel' => '{personel_id?}']],
                'yeni' => ['/rapor/satis', $yeni + ['sekme' => $sekme, 'personel_id' => '{personel_id~}']],
                'alanlar' => array_map(fn ($a) => "[].$a", [...$ortak, "{$sekme}_adi", "{$sekme}_id"]),
                'eslestir' => ['' => $anahtar],
            ];
        }
        $liste[] = [
            'ad' => 'Personel raporu',
            'eski' => ['POST', '/personelRaporlari', [], $aralik],
            'yeni' => ['/rapor/satis/personel', $yeni],
            'alanlar' => ['[].personel_adi', '[].hizmet_geliri', '[].hizmet_primi', '[].urun_geliri', '[].urun_primi', '[].paket_geliri', '[].paket_primi'],
            'eslestir' => ['' => ['personel_adi']],
        ];

        $musteri = ['[]', '[].id', '[].name', '[].cep_telefon', '[].email'];
        foreach ([
            ['Hizmet musteri listesi', 'hizmet-musteri-listes', 'hizmetId', 'hizmet', '{hizmet_id}', '{personel_id?}', '{personel_id~}'],
            ['Urun musteri listesi', 'urun-musteri-listesi', 'urunId', 'urun', '{urun_id}', '{personel_id?}', '{personel_id~}'],
            // Eski istemci personel seçili değilken filtreye paket numarasını yazıyordu.
            ['Paket musteri listesi', 'paket-musteri-listesi', 'paketId', 'paket', '{paket_id}', '{personel_id|paket_id}', '{personel_id|paket_id}'],
        ] as [$ad, $yol, $kimlik, $tur, $kalem, $eskiPersonel, $yeniPersonel]) {
            $liste[] = [
                'ad' => $ad,
                'eski' => ['POST', "/$yol", [], $aralik + [$kimlik => $kalem, 'personelId' => $eskiPersonel]],
                'yeni' => ["/rapor/satis/$tur/$kalem/musteriler", $yeni + ['olusturan_id' => $yeniPersonel]],
                'alanlar' => $musteri,
                'eslestir' => ['' => ['id']],
            ];
        }

        return $liste;
    }

    private static function prim(): array
    {
        $hakedis = ['maas', 'hizmet_toplam', 'urun_toplam', 'paket_toplam', 'hizmet_hakedis', 'urun_hakedis', 'paket_hakedis', 'odenen_maas', 'odenen_prim', 'odenen_diger',
            'bonus', 'kesinti', 'telafi_devri', 'masraf', 'borclu', 'gun_prim', 'gun_odenen', 'gun_kalan', 'gun_durum'];
        $topluAlan = ['mod', 'gun', 'donem', 'personeller[].personel_id', ...array_map(fn ($a) => "personeller[].$a", $hakedis)];

        return [
            [
                'ad' => 'Personel prim ay/yil',
                'eski' => ['POST', '/personelPrimHesaplaAyYil', [], ['sube' => '{salon}', 'personel_id' => '{personel_id}', 'ay' => '{ay}', 'yil' => '{yil}']],
                'yeni' => ['/prim/personel/{personel_id}', ['salon_id' => '{salon}', 'ay' => '{ay}', 'yil' => '{yil}']],
                'alanlar' => ['hizmet_toplam', 'hizmet_hakedis', 'urun_toplam', 'urun_hakedis', 'paket_toplam', 'paket_hakedis', 'hizmet_toplam_numeric', 'hizmet_hakedis_numeric',
                    'urun_toplam_numeric', 'urun_hakedis_numeric', 'paket_toplam_numeric', 'paket_hakedis_numeric', 'donem', 'adisyonlar[].id', 'adisyonlar[].acilis_tarihi', 'adisyonlar[].musteri',
                    'adisyonlar[].hizmet_veren', 'adisyonlar[].icerik', 'adisyonlar[].icerikKisaltilmis', 'adisyonlar[].toplam', 'adisyonlar[].odenen', 'adisyonlar[].kalan_tutar', 'adisyonlar[].hakedisTutar'],
            ],
            [
                'ad' => 'Toplu hak edis, aylik',
                'eski' => ['POST', '/primHakedisToplu/{salon}', [], ['ay' => '{ay}', 'yil' => '{yil}']],
                'yeni' => ['/prim/hakedis', ['salon_id' => '{salon}', 'ay' => '{ay}', 'yil' => '{yil}']],
                'alanlar' => $topluAlan,
                'eslestir' => ['personeller' => ['personel_id']],
            ],
            [
                'ad' => 'Toplu hak edis, gunluk',
                'eski' => ['POST', '/primHakedisToplu/{salon}', [], ['ay' => '{ay}', 'yil' => '{yil}', 'gun' => '{bit}']],
                'yeni' => ['/prim/hakedis', ['salon_id' => '{salon}', 'ay' => '{ay}', 'yil' => '{yil}', 'gun' => '{bit}']],
                'alanlar' => $topluAlan,
                'eslestir' => ['personeller' => ['personel_id']],
            ],
            [
                'ad' => 'Prim odeme listesi',
                'eski' => ['POST', '/primOdemeListesi', [], ['sube' => '{salon}', 'personel_id' => '{personel_id}', 'donem' => '{donem}']],
                'yeni' => ['/prim/odemeler', ['salon_id' => '{salon}', 'personel_id' => '{personel_id}', 'donem' => '{donem}']],
                'alanlar' => ['odemeler[].id', 'odemeler[].odeme_tarihi', 'odemeler[].tutar', 'odemeler[].odeme_tipi', 'odemeler[].odeme_yontemi', 'odemeler[].aciklama',
                    'hareketler[].id', 'hareketler[].tarih', 'hareketler[].tip', 'hareketler[].tutar', 'hareketler[].aciklama', 'odenen_toplam', 'odeme_sayisi',
                    'toplamlar.maas', 'toplamlar.prim', 'toplamlar.diger', 'toplamlar.bonus', 'toplamlar.kesinti'],
            ],
        ];
    }

    private static function paketSeans(): array
    {
        $paketAlan = ['id', 'paket_adi', 'fiyat', 'miktar', 'sure', 'sinirsiz', 'hizmetler[].hizmet_id', 'hizmetler[].seans', 'hizmetler[].fiyat'];
        $kok = array_map(fn ($a) => "[].$a", $paketAlan);
        $tablo = ['total', 'last_page', 'data[].id', 'data[].paket_adi', 'data[].aktif', 'data[].fiyat', 'data[].miktar', 'data[].sure', 'data[].sinirsiz', 'data[].gecerlilik_sayi',
            'data[].gecerlilik_birim', 'data[].gunluk_limit', 'data[].hizmetler[].hizmet_id', 'data[].hizmetler[].seans', 'data[].hizmetler[].fiyat', 'data[].hizmetler[].hizmet.hizmet_adi'];
        $seans = ['total', 'last_page', 'data[].adisyon', 'data[].paket', 'data[].tip', 'data[].paketHizmetId', 'data[].toplamSeansSayisi', 'data[].bekleyenSeansSayisi', 'data[].gelinenSeansSayisi',
            'data[].gelinmeyenSeansSayisi', 'data[].sinirsiz', 'data[].sure', 'data[].baslangic', 'data[].bitis', 'data[].musteri.name', 'data[].hizmetler[].id', 'data[].hizmetler[].hizmet_adi',
            'data[].seanslar[].id', 'data[].seanslar[].hizmet_id', 'data[].seanslar[].seans_no', 'data[].seanslar[].seans_tarih', 'data[].seanslar[].seans_saat', 'data[].seanslar[].geldi',
            'data[].seanslar[].iptal', 'data[].seanslar[].personel_adi', 'data[].seanslar[].hizmet.hizmet_adi'];

        return [
            [
                'ad' => 'Paket tablosu',
                'eski' => ['POST', '/paketler/{salon}', ['page' => 1], ['arama' => '']],
                'yeni' => ['/paket', ['salon_id' => '{salon}', 'mobil' => 1, 'page' => 1, 'arama' => '']],
                'alanlar' => $tablo,
            ],
            [
                'ad' => 'Paket tablosu, aramali',
                'eski' => ['POST', '/paketler/{salon}', ['page' => 1], ['arama' => '{arama}']],
                'yeni' => ['/paket', ['salon_id' => '{salon}', 'mobil' => 1, 'page' => 1, 'arama' => '{arama}']],
                'alanlar' => $tablo,
            ],
            [
                'ad' => 'Paket secici, id artan',
                'eski' => ['GET', '/paketget/{salon}'],
                'yeni' => ['/paket', ['salon_id' => '{salon}', 'mobil' => 1, 'tumu' => 1, 'yon' => 'asc']],
                'alanlar' => ['[]', ...$kok],
            ],
            [
                'ad' => 'Paket secici, id azalan',
                'eski' => ['POST', '/paketler', [], ['salon_id' => '{salon}']],
                'yeni' => ['/paket', ['salon_id' => '{salon}', 'mobil' => 1, 'tumu' => 1, 'yon' => 'desc']],
                'alanlar' => ['[]', ...$kok],
            ],
            [
                'ad' => 'Seans takibi listesi',
                'eski' => ['POST', '/seanslar', ['page' => 1], ['salonId' => '{salon}', 'arama' => '', 'musteri_id' => '', 'musteriMi' => false, 'appBundle' => '{app_bundle}']],
                'yeni' => ['/seans', ['salon_id' => '{salon}', 'mobil' => 1, 'page' => 1, 'arama' => '', 'musteri_id' => '']],
                'alanlar' => $seans,
            ],
            [
                'ad' => 'Seans takibi listesi, musteri',
                'eski' => ['POST', '/seanslar', ['page' => 1], ['salonId' => '{salon}', 'arama' => '', 'musteri_id' => '{musteri_id}', 'musteriMi' => false, 'appBundle' => '{app_bundle}']],
                'yeni' => ['/seans', ['salon_id' => '{salon}', 'mobil' => 1, 'page' => 1, 'arama' => '', 'musteri_id' => '{musteri_id}']],
                'alanlar' => $seans,
            ],
            [
                'ad' => 'Randevu formu kalan seans',
                'eski' => ['POST', '/paketVarmiKontrolu', [], ['userId' => '{musteri_id}', 'sube' => '{salon}', 'paketRandevuOnayiVar' => false]],
                'yeni' => ['/paket/musteri-kontrol', ['salon_id' => '{salon}', 'user_id' => '{musteri_id}', 'mobil' => 1, 'onay_var' => 0]],
                'alanlar' => ['paketVarMi', 'paketRandevuOnayiGerekli', 'onayMetni', 'toplamSeans', 'userName', 'paketHizmetleri', 'paketDetaylari'],
            ],
            [
                'ad' => 'Seans dokumu PDF, paket',
                'pdf' => true,
                'eski' => ['GET', '/seansDokumuPdf', ['adisyonpaketid' => '{adisyon_paket_id}', 'appBundle' => '{app_bundle}']],
                'yeni' => ['/seans/dokum', ['tur' => 'paket', 'kalem_id' => '{adisyon_paket_id}', 'mobil' => 1]],
                'alanlar' => ['Toplam Seans', 'Kullanilan', 'Kalan', 'Baslangic', 'bolge satirlari'],
            ],
            [
                'ad' => 'Seans dokumu PDF, hizmet',
                'pdf' => true,
                'eski' => ['GET', '/seansDokumuPdf', ['adisyonhizmetid' => '{adisyon_hizmet_id}', 'appBundle' => '{app_bundle}']],
                'yeni' => ['/seans/dokum', ['tur' => 'hizmet', 'kalem_id' => '{adisyon_hizmet_id}', 'mobil' => 1]],
                'alanlar' => ['Toplam Seans', 'Kullanilan', 'Kalan', 'Baslangic', 'bolge satirlari'],
            ],
        ];
    }

    private static function ozet(): array
    {
        $karsilastirma = ['current.value', 'previous.value', 'series', 'kasa', 'maliyet', 'kar', 'alacak', 'topPersonel', 'topHizmet', 'topUrun', 'hourlyDensity', 'hourlyAnalysis'];
        $liste = [[
            'ad' => 'Ana ekran ozeti',
            'eski' => ['POST', '/dashboard', [], ['sube' => '{salon}', 'user_id' => '{user_id}']],
            'yeni' => ['/ozet/mobil', ['mobil' => 1, 'salon_id' => '{salon}']],
            'alanlar' => ['randevu_sayisi', 'ongorusme_sayisi', 'urun_satislari', 'paket_satislari', 'toplam_kasa', 'kalan_tutar', 'prim', 'kalan_sms', 'gelen_arama', 'giden_arama',
                'cevapsiz_arama', 'puan', 'okunmamisbildirimler', 'ajanda', 'whatsapp_aktif', 'whatsapp_bagli', 'whatsapp_numara', 'isletme_adi'],
        ]];
        foreach (['gunluk', 'haftalik', 'aylik', 'yillik'] as $donem) {
            $liste[] = [
                'ad' => "Karsilastirma, $donem, salon geneli",
                'eski' => ['GET', '/dashboardKarsilastirma/{salon}', ['period' => $donem]],
                'yeni' => ['/ozet/mobil-karsilastirma', ['mobil' => 1, 'salon_id' => '{salon}', 'period' => $donem]],
                'alanlar' => [...$karsilastirma, 'subeler'],
            ];
            $liste[] = [
                'ad' => "Karsilastirma, $donem, personel rolu",
                'token' => self::TOKEN_PERSONEL,
                'eski' => ['GET', '/dashboardKarsilastirma/{salon}', ['period' => $donem, 'personel_id' => '{personel_id}']],
                'yeni' => ['/ozet/mobil-karsilastirma', ['mobil' => 1, 'salon_id' => '{salon}', 'period' => $donem]],
                'alanlar' => $karsilastirma,
            ];
        }
        $liste[] = [
            'ad' => 'WhatsApp paket durumu',
            'eski' => ['GET', '/whatsapp/paket-durum/{salon}'],
            'yeni' => ['/whatsapp/paket', ['salon_id' => '{salon}']],
            'alanlar' => ['paket', 'periyot', 'baslangic', 'bitis', 'deneme', 'kalan_gun'],
        ];

        return $liste;
    }
}
