# Paket Yönetimi — v2 (API-first)

Eski web "Paket Yönetimi" (`/isletmeyonetim/paketsatislari` → `StoreAdminController@paketsatislari`,
`paket_ekle_guncelle`, `paketdetayigetir`, `paket_sil`, `pakettahsilatagit`) ve Flutter
`yonetici/diger/menu/ayarlar/paketler/*` (`/api/v1/paketler`, `paketget`, `paketgetir`, `paketdetay`,
`paket_ekle_guncelle`, `paket_sil`, `paketpasifet`) artık **tek uç kümesinden** beslenir.
Web ekranı: `/v2/paketler?sube=`. Flutter koduna dokunulmadı; aşağıdaki eşleme geçiş içindir.

## Katmanlar
| Katman | Dosya | Sorumluluk |
|---|---|---|
| Domain (saf) | `Domain/Paket/PaketTutari` | **etkin fiyat/seans** kuralı: hizmet toplamı > 0 ise o, değilse paket düzeyi (okuma + yazma aynı kural) |
| Servis | `Services/Paket/PaketSunumu` | JSON sözleşmesi (Flutter `Paket` / `PaketHizmetleri` alan adları) |
| Servis | `Services/Paket/PaketSorgusu` | liste (arama/sıralama/sayfalama), tek paket, satış tutarı (salon + aktiflik denetimli) |
| Servis | `Services/Paket/PaketYazServisi` | ekle / kısmi güncelle / pasif sil + aktivite logu |
| Servis | `Services/Paket/PaketSatisServisi` | seçili paketleri müşteriye sat → adisyon (kalemler `KalemServisi`'yle; tahsilat yok) |
| Servis | `Services/Yetki/SalonSahipligi` | müşteri / personel / hizmet sahiplik denetimi — Stok da bunu kullanır (`StokKapsam` devreder) |
| Controller | `Api/V2/PaketController` | ince; yazmalar `PAKET_V2_YAZMA_AKTIF` kill-switch arkasında (kapalıyken 409) |

`KatalogServisi` (satış dropdown'ları) ve `KalemServisi` (adisyona paket ekleme) artık paket fiyat/seansını
`PaketSorgusu` üzerinden alır — eskiden aynı `COALESCE(NULLIF(SUM…))` kuralı 3 ayrı yerde yazılıydı.

## Uçlar (`auth:isletmeyonetim-api`, zarf `{basarili, veri, mesaj}`)
| Yöntem | Yol | Yetki | Not |
|---|---|---|---|
| GET | `/api/v2/paket/form-verisi?salon_id=` | tanım \| sat | `hizmetler[{hizmet_id, ad, fiyat, sure}]`, `studyo_modu`, `yetkiler{tanim_olustur, sat, fiyat_degistir}`, `yazma_aktif` |
| GET | `/api/v2/paket?salon_id=&ara=&sirala=&yon=&page=&per_page=&tumu=` | tanım \| sat | `sirala`: `paket_adi\|fiyat\|miktar\|id`; `tumu=1` sayfasız (seçiciler) |
| GET | `/api/v2/paket/{id}` | tanım \| sat | pasif paket de okunur (geçmiş satışlar için) |
| POST | `/api/v2/paket` | `paket.tanim_olustur` | `{salon_id, paket_adi, hizmetler, miktar?, fiyat?, sure?}` → paket (201) |
| PATCH | `/api/v2/paket/{id}` | `paket.tanim_olustur` | yalnız gönderilen alanlar; `hizmetler` gönderilirse tamamen yenilenir |
| DELETE | `/api/v2/paket/{id}` | `paket.tanim_olustur` | `aktif=0` (idempotent) |
| GET | `/api/v2/paket/musteri-ara?salon_id=&q=` | `paket.sat` | `[{id, ad, telefon}]` (telefon `musteri.telefon_gor` ile maskeli) |
| POST | `/api/v2/paket/satis` | `paket.sat` | `{salon_id, musteri_id, personel_id?, tarih?, kalemler[{paket_id, seans?, fiyat?}], idempotency_key}` → `{adisyon_id, musteri_id, kalem_sayisi, toplam_tutar, replay}` |

`hizmetler` iki biçimde kabul edilir: `[{hizmet_id, seans, fiyat}]` (Flutter `PaketHizmetleri.toJson`) veya
`[3, 7]` (yalnız id; fiyat/seans paket düzeyinde — eski web modalı).

## Paket satırı (Flutter `Paket.fromJson` uyumlu)
`id, paket_adi, aktif (1/0), miktar, fiyat, sure, hizmetler[{hizmet_id, hizmet_adi, seans, fiyat, hizmet{id, hizmet_adi}}]`
+ `hizmet_adlari` (gösterim). `fiyat` / `miktar` **etkin** değerdir; istemci yeniden hesaplamaz.

## v1 → v2 eşlemesi (Flutter geçişi)
| v1 | v2 |
|---|---|
| `POST /paketler/{salonid}?page=` `{arama}` | `GET /paket?salon_id=&ara=&page=` (zarf `veri.data` + `current_page/last_page/total`) |
| `GET /paketget/{salonid}`, `POST /paketler` `{salon_id}` | `GET /paket?salon_id=&tumu=1` (v1 pasifleri de döndürüyordu; v2 yalnız aktif) |
| `POST /paketgetir/{salonid}` `{arama}` | `GET /paket?salon_id=&ara=&tumu=1` (`seanslar` → `miktar`, `hizmetler` metni → `hizmet_adlari`) |
| `GET /paketdetay/{paketid}` | `GET /paket/{id}` |
| `POST /paket_ekle_guncelle/{salonid}` `{paket_id, adpaket, hizmetler, paketsure, seanslar, fiyatlar}` | `paket_id==0` → `POST /paket`, aksi `PATCH /paket/{id}`; alanlar `paket_adi, hizmetler, sure, miktar, fiyat` |
| `POST /paket_sil` `{paket_id}`, `POST /paketpasifet` `{paketid}` | `DELETE /paket/{id}` |
| (web) `POST /isletmeyonetim/pakettahsilatagit` | `POST /paket/satis` → `/v2/tahsilat/{adisyon_id}` |

Her geçişte `backend.dart`'ta çağrı `ApiClient` (Bearer) ile yapılmalı — v1 paket uçları kimliksizdi.

## Eskiye göre bilinçli farklar
- **Kimlik + kapsam + yetki her uçta** (v1 paket uçlarının çoğu kimliksizdi; `paketdetayigetir`/`paket_sil` başka salonun paketini okuyup silebiliyordu).
- **Tek fiyat/seans kuralı**: web ve mobil farklı yazıyordu; v2 yazarken `paketler.fiyat/miktar` = etkin değer → eski panel, katalog ve v2 aynı rakamı görür.
- **Hizmetler salona ait olmalı**; düzenlemede hizmet bazlı seans/fiyat (mobil tanımlı paketler) korunur.
- **Satışta fiyat** katalogdan; farklı fiyat yalnız `satis.indirim_uygula` ile (eskide herkes değiştirebiliyordu). Seans sayısı değiştirilebilir (eskiyle aynı).
- **Satış idempotent** (`idempotency_key`) — çift tıklama ikinci adisyon açmaz. Başka salonun / pasif paketi satılamaz (`KalemServisi` de aynı denetimi yapar).
- **Düzenlemede "Süre" alanı dolu gelir** (eski düzenleme modalı süreyi doldurmuyordu).
- Stüdyo modu: fiyat kolonu/alanı gizli (MODEL B); satışta "Ödeme Alındı" mevcut `PATCH /adisyon/{id}/odeme` ucunu kullanır. Eski ekrandaki satış sonrası otomatik **ders planı modalı** v2'de henüz yok (eski panelde).

## Açma (kill-switch)
`.env`: `PAKET_V2_YAZMA_AKTIF=true` (kapalıyken tüm yazma uçları 409, okuma her zaman açık).

## Sınırsız / süreli paket
Paket tanımı: `sinirsiz (0/1)`, `gecerlilik_sayi` + `gecerlilik_birim (ay|yil)`, `gunluk_limit` (yalnız sınırsızda). Satışta `adisyon_paketler`'e kopyalanır (model `saving`, tek nokta); katalog sonradan değişse de satılan paket etkilenmez. Şema eski monolitte: `2026_11_05_120000_add_sinirsiz_gecerlilik_to_paketler`.

- **Süre ilk "Geldi"de başlar.** Bitiş saklanmaz: `MIN(seans_tarih where geldi=1) + süre` (`Domain\Paket\PaketGecerlilik`, veri `Services\Paket\PaketKullanimi`). "Geldi" geri alınır ya da geçmiş tarihli seans girilirse kendiliğinden düzelir. Hiç gelinmemiş pakette süre işlemez. Bitiş günü dahildir.
- **`seans_sayisi` teknik tavan:** sınırsızda `gün × günlük limit`. `> 0` ve kalan formülü kullanan eski kod bozulmaz; istemci adet göstermez, `sinirsiz` bayrağına bakar.
- **Seans adayı (`SeansHavuzu::yeniAday`):** süresi bitmiş / günlük limiti dolmuş paket aday olmaz; geçerli sınırsız paket FIFO'dan önce gelir. Uygun paket yoksa randevu paketsiz açılır (bugünkü "hak yok" davranışı).
- Sınırsız pakette telafi reddedilir; toplu randevu çalışmaz (`paket_hizmetler.seans` boş).
- Eski monolit karşılığı `App\Services\PaketGecerlilik`: iki repoda aynı kural.
- API: paket satırına `sinirsiz, gecerlilik_sayi, gecerlilik_birim, gecerlilik_metni, gunluk_limit`; `/seans` satırına `sinirsiz, gecerlilik{sure, baslangic, bitis, suresi_doldu}`; `POST/PATCH /paket` aynı alanları alır (`sinirsiz=1` iken süre zorunlu).

