# Özet (Dashboard) — v2

Eski web özeti (`isletmeadmin/dashboard.blade.php` + `StoreAdminController::dashboard*`, 10 uç) ve
Flutter ana ekranının iki v1 ucu (`POST /v1/dashboard`, `GET /v1/dashboardKarsilastirma/{salon}` —
ikisi de **kimliksizdi**) tek bir v2 uç kümesinde birleşti. Web `/v2/ozet` ve Flutter aynı uçları kullanır.

## Uçlar (`routes/v2/ozet.php`, hepsi `auth:isletmeyonetim-api` + kapsam + yetki)

| Uç | İçerik |
|---|---|
| `GET /ozet/acilis?salon_id=` | `salon{ad, studyo_modu, lisans_gun}`, `bugun{randevu, on_gorusme, urun_satis, paket_satis, gelir}` (yetkisiz → `null`), `kartlar[{kod, baslik, ikon, donemler[{kod,ad}], varsayilan_donem}]`, `sekmeler[{kod, ad, ikon, bos_mesaj, kolonlar[{alan, baslik, tip}]}]`, `eylemler[{kod, etiket, ikon, url}]`, `personel_kapsami` |
| `GET /ozet/kart/{kod}?salon_id=&donem=` | `kasa`, `randevu`, `anket`, `cark`, `karsilastirma`, `akis`, `personel` |
| `GET /ozet/sekme/{kod}?salon_id=` | `online_talep`, `bugunku_randevu`, `acik_adisyon`, `alacak`, `dogum_gunu` → `{sekme, liste[]}` |

Kart/sekme/eylem yetkileri tek yerde: `App\Domain\Ozet\OzetKarti | OzetSekmesi | OzetEylemi`. Açılış listesi
ve uç kapısı aynı tanımı kullanır — istemci yetki kuralı tutmaz, yalnız `acilis` cevabını çizer.
Dönem: `RaporDonemi` (tüm raporlarla aynı sözleşme) + kayan `son7 | son30 | son90`; her kart yalnız
kendi `donemler` listesini kabul eder (aksi 422).

## Kanonik kurallar (eski farklılıkların kararı)

| Konu | Eskisi | v2 |
|---|---|---|
| Ciro / kasa | web: `tahsilatlar.tutar`; mobil: kalem tahsilat toplamı; "Bugünkü Kasa" = ay başından bugüne tahsilat − masraf | `tahsilatlar.tutar`, `odeme_tarihi` dönemde (İşletme Raporları ile **aynı rakam**, `IsletmeRaporServisi::tahsilatKovalari`). Masraf düşülmez. |
| Ödeme yöntemi | web: ad içinde "nakit/kart" araması | id kovası (`OdemeKovasi`, Online ayrı) |
| Personel kapsamı | web: yetkiden; mobil: istemcinin `personel_id`'si (atlatılabilirdi) | **token'dan**: rol 5 → kendi; yönetici tam yetkisi yoksa (`personel.kendi_ciro_gor`) → kendi kalemlerinden gelen tahsilatlar (`SatisKalemSorgusu::personelTahsilatIdleri`) |
| Randevu sayımları | kapalı saat blokları (user 2012) dahil; iptal durumları tutarsız | `IsletmeRaporServisi::randevuDurumlari` (blok hariç); sonuçlanmayan = iptal + gelmedi |
| Karşılaştırma dönemi | Carbon `subMonth` 29–31'de taşıyordu | `RaporDonemi::kaydir` (taşma yok); önceki dönem etiketi API'den |
| Liderler | personel: yalnız hizmet tahsilatı; hizmet/ürün: `created_at` | İşletme Raporları ile aynı (net satış, satış tarihi) |
| Alacak | dönem penceresi, ödenmiş vade kalıntısı dahil; kart ≠ açtığı liste | `AlacakServisi::acikSorgu` (tutar>0, bağlı taksit/senet vadesi ödenmemiş) — sekme ve kart aynı kural |
| Açık adisyon | ödenen = parent `tahsilatlar` | ödenen = kalem-split (Satış Takibi ile aynı, `SatisListeServisi::odenenSql`) |
| Randevu listeleri | randevu × hizmet JOIN → aynı randevu tekrar ediyordu | randevu başına tek satır (hizmetler birleşik) |
| Doğum günü | yıl dönümü (28 Ara → 5 Oca) boş; pasif portföy dahil | yıl dönümü doğru, yalnız aktif portföy, `kalan_gun` |
| Telefon | — | `musteri.telefon_gor` yoksa maskeli (her listede) |
| Sayılar | mobil `"12.345,67"` string (Flutter `_parseAmount` ≥1000'de 0 okuyordu) | ham sayı |

## Flutter eşlemesi (ana ekran `yonetici/dashboard/home_screen.dart`)

| v1 alanı | v2 |
|---|---|
| `randevu_sayisi`, `ongorusme_sayisi`, `urun_satislari`, `paket_satislari` | `acilis.bugun.randevu / on_gorusme / urun_satis / paket_satis` (null = gizle) |
| `toplam_kasa` ("Bugünkü Kasa") | `acilis.bugun.gelir` (bugünün tahsilatı) veya `kart/kasa?donem=…` |
| `kalan_tutar` | `kart/karsilastirma.alacak` |
| karşılaştırma `period=gunluk/haftalik/aylik/yillik` | `kart/karsilastirma?donem=bugun/hafta/ay/yil` |
| `current{label,value}`, `previous{…}`, `series[]` | `simdi{etiket,tutar}`, `onceki{etiket,tutar,baslangic,bitis}`, `degisim`, `seri[{baslangic,bitis,tutar}]` |
| `topPersonel/topHizmet/topUrun` | `liderler.personel{ad,tutar} / hizmet{ad,adet} / urun{ad,adet}` (null = yetki yok) |
| `hourlyDensity[24]`, `hourlyAnalysis.peakHour` | `saat_dagilim[{saat,adet}]`, `en_yogun_saat` |

**v1'de kalanlar (bu fazda taşınmadı):** okunmamış bildirim, WhatsApp/SMS rozeti, santral sayaçları,
boş saat kampanya önerisi (`hourlyAnalysis.gaps` + `saatBoslugu*` yazma uçları), e-asistan özeti,
stüdyo grup dersi raporu. Flutter ekranı henüz v2'ye bağlanmadı (Flutter dondurması — geçiş ayrı iş).
