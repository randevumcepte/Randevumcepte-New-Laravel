# Strangler — Satış Takibi (adisyon listesi) yol haritası

Tahsilat deseninin ikinci tüketicisi. Eski "Satış Takibi" (web
`StoreAdminController@adisyon_yukle` + mobil `ApiController@adisyon_yukle` /
`formatAdisyonFast`) iki kopyada yaşıyordu → tek kaynağa taşındı.

## Yapıldı (Faz — salt okuma, tam parite)

- **Domain** `app/Domain/Satis/AdisyonSatir.php` — saf (DB'siz, kuruş) satır
  hesabı: NET=Σ(fiyat−indirim), odenen=Σ kalem tahsilat, kalan, durum, tür bazlı
  hakediş (seansli hizmet primi paket kovasına), icerik/icerikKisaltilmis/
  hizmet_veren etiketleri. Eski `formatAdisyonFast` ile birebir.
- **Servis** `app/Services/Satis/SatisListeServisi.php` — sorgu + filtre + kapsam
  + prim çözümü; satır matematiğini Domain'e delege eder. Filtreler: tarih,
  tür (0-3), müşteri, personel, açık/kapalı, faturasiz_gizle ayarı, studyo modu
  (`odendi` bazlı açık/kapalı), musteriMi+appBundle çok-şube. Hiçbir şey yazmaz.
  (Not: eski `Services/Tahsilat/SatisListeServisi` iskeleti buraya taşındı.)
- **Controller** `Api/V2/SatisController@index` — ince: kimlik → salon_id 422 →
  tenant/kapsam → GuvenlikKapisi OR-gate (`satis.tum_satis_gor|adisyon_olustur|
  tahsilat_al`) → kendi-satışı kısıtı (tum_satis_gor yoksa; müşteri kartı +
  `musteri.gecmis_satis_gor` istisnası) → servis → ApiCevap. GET `/api/v2/satislar`.
- **Modeller** (salt okuma, migration YOK): Salonlar, Hizmetler, Urunler,
  Paketler, PersonelPrimOrani + kalem modellerine (musteri/salon/hizmet/urun/
  paket/personel/tahsilatlar) ilişkiler.
- **Web ekran** `GET /v2/satislar` → `resources/views/v2/satislar.blade.php`
  (`layout.panel`) + `public/js/satislar-v2.js` + `public/css/satislar-v2.css`.
  Sekme + tarih/durum/müşteri filtresi + özet kutuları + sayfalı tablo.
- **Test** `tests/Unit/Domain/Satis/AdisyonSatirTest.php` — saf matematik/hakediş/
  etiket paritesi (DB yok).

## Parite sözleşmesi

`data[]` satırları eski `formatAdisyonFast` anahtarlarıyla **birebir** (drop-in):
`id, acilis_tarihi, acilis_saati, musteri, salon_adi, icerik, icerikKisaltilmis,
hizmet_veren, toplam(+_numeric), odenen(+_numeric), kalan_tutar(+_numeric),
hakedisTutar, hizmet/urun/paketHakedisTutar, hizmet/urun/paketToplam, islemler,
durum, fatura_kesildi, odendi, odendi_tarihi, …`. Üst düzey: `satisSayisi,
acik_sayisi, kapali_sayisi, odenen, kalan, current_page, last_page, total`.
Envelope/summary v2 (`ApiCevap`) konvansiyonu; `data[]` anahtarları legacy.

## Flutter geçişi (geri alınabilir bayrak)

`lib/Backend/backend.dart` → `kSatisTakibiV2` (varsayılan `false`). `true` iken
`satislar()` v2 adaptörüne (`_satislarV2`, Bearer + zarf açma) gider; imza ve
`adisyonpage.dart` / `Adisyon.fromJson` DEĞİŞMEZ. Parite doğrulanınca `true`.

## Doğrulama

- Birim: `php artisan test --testsuite=Unit` (AdisyonSatir yeşil).
- Parite: aynı salon+tarih için `/api/v2/satislar` cevabını eski `/api/v1/satislar`
  ile diff'le (toplam/odenen/kalan/hakediş kuruş-kuruş; indirimli + çok-tür +
  seanslı örnekler).
- Web: `/v2/satislar?sube=X` sekme/filtre/özet legacy ile aynı mı.
