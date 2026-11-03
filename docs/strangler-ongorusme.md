# Strangler — Ön Görüşme (v2)

Eski web `/isletmeyonetim/ongorusmeler` + mobil v1 `ongorusme*` uçlarının v2 karşılığı. Web paneli
(`/v2/ongorusmeler`) ve Flutter **aynı** `/api/v2/ongorusme/*` uçlarını kullanır. Yazmalar
`ONGORUSME_V2_YAZMA_AKTIF` (varsayılan açık) arkasında; kapalıyken 409.

## Katmanlar

| Katman | Dosya | Sorumluluk |
|---|---|---|
| Domain (saf) | `app/Domain/OnGorusme/GorusmeDurumu.php` | durum sözlüğü (NULL beklemede · 1 satış yapıldı · 0 yapılmadı), açık mı |
| Domain (saf) | `app/Domain/OnGorusme/GorusmeNedeni.php` | paket / ürün / hizmet nedeni; üç istemci biçimini tek değere çözer |
| Okuma | `Services/OnGorusme/OnGorusmeSorgusu.php` | liste + sekme sayaçları, tek kayıt, personel kapsamı |
| Sunum | `Services/OnGorusme/OnGorusmeSunumu.php` | JSON sözleşmesi (Flutter `OnGorusme.fromJson` uyumlu) |
| Yazma | `Services/OnGorusme/OnGorusmeYazServisi.php` | ekle/düzenle + takvim randevusu + outbox |
| Sonuç | `Services/OnGorusme/OnGorusmeSonucServisi.php` | satış yapıldı (mevcut satış yazıcıları) / yapılmadı |
| Form | `Services/OnGorusme/OnGorusmeFormServisi.php` | neden kataloğu, personel/oda, sözlükler, yetkiler |
| HTTP | `OnGorusmeController`, `Requests/OnGorusme/*`, `routes/v2/ongorusme.php` | ince controller, doğrulama, eski alan adı eşleme |

Yeniden kullanılanlar (kopya yok): `KatalogServisi` (neden kataloğu), `RandevuFormServisi::kaynakSecenekleri`
(personel/oda/etiket — randevu editörüyle ortak), `RandevuKayitServisi::kaynakCakismasi` (çakışma kuralı/mesajı),
`MusteriYazServisi::bulVeyaOlustur` (müşteri), `PaketSatisServisi` / `HizliSatisServisi` / `KalemServisi`
(satış), `BildirimKuyrugu` (outbox → eski `v2:bildirim-isle` → `OnGorusmeBildirimServisi`).

## v1 → v2 eşlemesi

| v1 (Flutter / eski web) | v2 | Not |
|---|---|---|
| `POST /ongorusmeget/{salon}?page {musteridanisan}` | `GET /ongorusme?salon_id=&ara=&page=` | + `durum`, `tarih_bas/bit`, `personel_id`, `sayaclar` |
| `POST /ongorusmegetgunluk/{salon}` | `GET /ongorusme?salon_id=&tarih_bas=BUGUN&tarih_bit=BUGUN` | |
| `POST /ongorusmebilgi {ongorusmeid}` · web `ongorusmedetay` | `GET /ongorusme/{id}` | tarih/saat/oda randevudan |
| `GET /ongorusmenedeni/{salon}` | `GET /ongorusme/form-verisi?salon_id=` | `nedenler[]` (paket+ürün+hizmet, fiyat/seans) + personel/oda/sözlük/yetki |
| `POST /ongorusmeekleguncelle` · web `ongorusmeekleduzenle` | `POST /ongorusme` · `PATCH /ongorusme/{id}` | eski alan adları kabul: `gorusmeyi_yapan`, `randevu_tarihi/saati`, `ongorusme_tarihi/saati`, `paket_id/urun_id/hizmet_id`, `paket_urun`, `sehir`; çakışma `veri.cakismavar='1'` + `cakisanrandevuekle=1` |
| `POST /ongorusmesatisyapildi {ongorusmeid, ...}` | `POST /ongorusme/{id}/satis-yapildi` | `baslangic_tarihi→tarih`, `seans_sayisi→seans`, `urun_adedi→adet`; cevap `adisyon_id` |
| `POST /ongorusmesatisyapilmadi {ongorusmeid, satisyapilmamasebebi}` | `POST /ongorusme/{id}/satis-yapilmadi {not}` | `satisyapilmamasebebi` kabul |

Flutter geçişi = `backend.dart`'ta taban `/api/v2` + Bearer + `govde.veri` okuma; model alanları değişmez.

## Eskiden bilinçli farklar

- **Kapsam tek kural:** personel rolündeki kullanıcı yalnız kendi görüşmelerini görür/işler (mobil davranışı; eski web tüm salonu gösteriyordu). Başkasınınki 404.
- **Düzenlemede randevu silinip yeniden açılmaz**, aynı randevu güncellenir (id sabit). Randevu hizmet_id = `config('ongorusme.randevu_hizmet_id')` (1, mobil ile aynı; eski web 0 yazıyordu).
- Müşteri / personel / oda / neden istekteki salona ait olmalı; takvim odaya göreyse oda zorunlu.
- Sonuçlanmış görüşme düzenlenemez / tekrar sonuçlandırılamaz (satır kilidi → çift tıklamada ikinci adisyon yok, 409).
- Satış fiyatı katalogdan; farklı fiyat `satis.indirim_uygula` ister (eski: herkes). Ürün satışında fiyat daima katalog × adet ve stok `satis` hareketi yazılır.
- Düzenlemede bildirim yalnız tarih/saat/personel değiştiyse gider (eski: her kayıtta SMS).
- Maskeli telefon gelirse kayıtlı numara korunur.

## Kalan

- Menü geçişi (eski panel menüsü → `/v2/ongorusmeler`) kullanıcı "geçiş" diyene kadar YAPILMAZ.
- Flutter ekranları v1'de (Flutter donduruldu).
- Seçili görüşmelere hatırlatma SMS'i (eski `hatirlatmasmsgonder`, ekranda butonu yok) taşınmadı.
