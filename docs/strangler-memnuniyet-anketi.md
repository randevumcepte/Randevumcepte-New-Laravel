# Strangler — Memnuniyet Anketi (v2)

Eski web `/isletmeyonetim/anket-sonuclari` (StoreAdminController `anket*`, `googleReviewKaydet`, `reputationPremiumAc`) +
mobil v1 `/api/v1/anket*` uçlarının v2 karşılığı. Web paneli (`/v2/memnuniyet-anketi`) ve Flutter **aynı** `/api/v2/anket/*`
uçlarını kullanır. Yazmalar `ANKET_V2_YAZMA_AKTIF` (varsayılan açık) arkasında; kapalıyken 409.

**Kapsam dışı (eski monolitte kalır):** müşterinin anketi doldurduğu `/anket/{token}` sayfası + `/anket-kaydet` +
`/anket-google-tiklandi` (ödül tanımlama, düşük puan uyarısı) ve `anket:otomatik-gonder` cron'u. Tablolar ortak; v2'nin
yazdığı şablonu onlar aynen okur.

## Katmanlar

| Katman | Dosya | Sorumluluk |
|---|---|---|
| Domain (saf) | `app/Domain/Anket/SoruTipi.php` | soru tipi kataloğu (etiket, ikon, renk, grup, seçenekli, cevaplı), eski tip eşlemesi |
| Domain (saf) | `AnketSorulari.php` | `sorular_json` normalize / doğrula / oku, hazır şablon |
| Domain (saf) | `AnketOdulu.php` | kupon/puan ayarı; şablon (`odul_`) + Google (`google_odul_`) aynı kural |
| Domain (saf) | `AnketPuanlama.php` | NPS sınıfı, web sitesinde yayın kuralı |
| Domain (saf) | `GonderimDurumu.php`, `GonderimFiltresi.php` | durum rozeti, liste filtresi |
| Domain (saf) | `ReputasyonEsikleri.php` | eşik varsayılanları + sınırları |
| Domain (saf) | `GoogleYorumBaglantisi.php` | yapıştırılan bağlantı → yorum URL'si |
| Özet | `Services/Pazarlama/AnketOzetServisi.php` | tek toplama sorgusu: `ozet()` dashboard kartı, `yonetim()` bu ekran |
| Okuma | `Services/Anket/AnketSablonSorgusu.php`, `AnketGonderimSorgusu.php` | liste + detay + API biçimi |
| Yazma | `AnketSablonYazServisi.php`, `AnketAyarServisi.php` | şablon CRUD, Reputation ayarları |
| Gönderim | `AnketGonderServisi.php` | kayıt + token + outbox `anket_link`; Çağrı Merkezi de bunu kullanır |
| Form | `AnketFormServisi.php` | sekmeler, sözlükler, hazır şablon, dönemler, yetkiler |
| HTTP | `AnketController`, `AnketSablonController`, `AnketAyarController`, `Requests/Anket/*`, `routes/v2/anket.php` | ince controller, doğrulama |
| Web | `resources/views/v2/memnuniyet-anketi.blade.php`, `public/js/memnuniyet-anketi-v2.js`, `public/js/anket/*`, `public/css/anket-v2.css` | sekmeli ekran |

Yeniden kullanılanlar: `RaporDonemi` + `v2-donem` (dönem), `ListeIstegi` + `v2-tablo` (tablolar), `OzetHesap` (NPS formülü),
`SalonSahipligi`, `TelefonNumarasi`, `AktiviteLog`, `BildirimKuyrugu`, `v2-modal`, `v2-sekme`.

## Uçlar

Tümü `auth:isletmeyonetim-api`, yetki `pazarlama.anket_yonet`; açık telefon `musteri.telefon_gor`. Cevap zarfı `{basarili, veri, mesaj}`.

| Uç | Açıklama |
|---|---|
| `GET /anket/form-verisi?salon_id=` | `sekmeler, soru_katalogu, odul_tipleri, indirim_tipleri, filtreler, donemler, varsayilan_donem, hazir_sablon, varsayilan, sablon_sayisi, yetkiler{yaz,telefon_gor}` |
| `GET /anket/ozet?salon_id=&donem=&baslangic=&bitis=` | v1 anahtarları (aşağıda) + `npsSkor, uyariTelefon, donem` |
| `GET /anket/gonderimler?salon_id=&filtre=&donem=&page=&per_page=&ara=&sirala=&yon=` | `{data[], current_page, last_page, per_page, total}`; `donem` yoksa tüm zamanlar |
| `GET /anket/gonderimler/{id}` | `{gonderim, sorular, cevaplar, sablon_ad, musteri_ad}` |
| `PATCH /anket/gonderimler/{id}/web-gizle {gizle}` | güncel gönderim |
| `POST /anket/hizli-gonder {salon_id, user_id}` | `{gonderim_id, kuyrukta, kanal}` |
| `GET /anket/sablonlar?salon_id=` · `POST /anket/sablonlar` | liste · ekle |
| `GET` · `PATCH` · `DELETE /anket/sablonlar/{id}` | detay · kısmi güncelle · sil (`{silindi, pasiflestirildi, kullanim}`) |
| `POST /anket/sablonlar/{id}/gonder {telefon, ad_soyad?, user_id?, randevu_id?, personel_id?}` | `{gonderim_id, kuyrukta, kanal}` |
| `GET` · `PATCH /anket/ayarlar` | Reputation ayarları; PATCH kısmi, cevapta `uyari` |
| `POST /anket/ayarlar/premium` | Reputation Booster'ı açar |

## v1 → v2 eşlemesi (Flutter geçiş günü)

Flutter'a dokunulmadı. Geçişte `Backend/backend.dart` anket fonksiyonları Bearer + `veri` zarfına geçer; alan adları korunduğu için ekran modelleri değişmez.

| v1 | v2 | Not |
|---|---|---|
| `GET /anketOzet/{salon}?gun=N` | `GET /anket/ozet?salon_id=&donem=sonN` | N: 7, 30, 90, 365; anahtarlar aynı (`toplamGonderim, toplamCevap, cevapOrani, ortNps, ortCsat, promoter, passive, detractor, trend, googleTiklamalar, kotuPuanUyarilari, reputationPremiumAktif, googleUrlVar, gun`) |
| `GET /anketGonderimleri/{salon}?limit&offset&filtre&sadeceCevaplilar` | `GET /anket/gonderimler?salon_id=&per_page&page&filtre` | `kayitlar` → `veri.data`; `sadeceCevaplilar=1` → `filtre=cevapli`; varsayılan filtre artık `tum`. Öğe alanları aynı + `durum, nps_sinifi, webe_uygun, web_gizle` |
| `GET /anketGonderimDetay/{salon}/{id}` | `GET /anket/gonderimler/{id}` | zarf aynı; `gonderim` içinde ip / user_agent / token yok |
| `GET /anketSablonlari/{salon}` | `GET /anket/sablonlar?salon_id=&per_page=100` | `sablonlar` → `veri.data`; `sorular_json` metni de döner; boolean'lar `true/false` |
| `GET /anketSablon/{salon}/{id}` | `GET /anket/sablonlar/{id}` | `sablon` → `veri` |
| `POST /anketSablon/{salon}` · `POST /anketSablon/{salon}/{id}` | `POST /anket/sablonlar` · `PATCH /anket/sablonlar/{id}` | `sorular_json` metni aynen kabul |
| `DELETE /anketSablon/{salon}/{id}` | `DELETE /anket/sablonlar/{id}` | `pasiflestirildi, kullanim` aynı |
| `POST /anketManuelGonder/{salon}` | `POST /anket/sablonlar/{id}/gonder` | `sablon_id` yoldan; `veri.gonderim_id` |
| `POST /anket-hizli-gonder` | `POST /anket/hizli-gonder` | gerçek kanal arka planda belirlenir; `kuyrukta: true` |
| `GET` · `POST /anketAyarlar/{salon}` | `GET` · `PATCH /anket/ayarlar` | alan adları aynı + `kotu_puan_uyari_telefon`, `reputation_premium_aktif` |

## Eskiden bilinçli farklar

- **Kimlik:** mobil v1 anket uçlarının hiçbirinde kimlik yoktu (salon id değiştirerek okuma/yazma). v2'de token + kapsam + yetki. Eski web `reputationPremiumAc`, `googleReviewKaydet`, `anketSablonGetir` da yetki bakmıyordu.
- **Müşteri sahipliği:** hızlı/manuel gönderimde `user_id` salonun portföyünde olmalı (eski: herhangi bir kullanıcı).
- **Soru metni düzeltmesi:** Flutter `baslik`, müşteri sayfası `soru` okuyordu; Flutter'da oluşturulan şablonların soruları müşteriye boş görünüyordu. v2 ikisini de yazar. Kayıtlı eski şablonlar v2'den yeniden kaydedilene kadar müşteri sayfasında boş kalır.
- **Varsayılan şablon düzeltmesi:** eski otomatik şablondaki `csat` ve kısa `metin` tipleri müşteri sayfasında skor üretmiyordu; v2 hazır şablonu `csat_yildiz` + `uzun_metin` kullanır. Hızlı gönderim "varsayılan" işaretli şablonu tercih eder (eski: ilk aktif şablon).
- **Gönderim:** kayıt + token v2'de; link outbox `anket_link` → eski `anketSmsGonder` (WhatsApp-first, SMS yedek). Ekran "kuyruğa alındı" der, kanal kayda sonra yazılır.
- **KVKK:** telefon listede ve kişi listelerinde yetkiye göre maskeli (eski kişi listesi ham numarayı sayfaya gömüyordu); maskeli kullanıcı numarayla arama yapamaz.
- **Performans:** eski sayfa dönemin tüm kayıtlarını çekip PHP'de sayıyordu ve şablon başına ayrı COUNT atıyordu; v2'de tek toplama sorgusu + sunucu tarafı sayfalama.
- **Doğrulama:** seçenekli soruda seçenek zorunlu, yüzde indirim ≤ 100, gecikme ≤ 720 saat.

## Bilinen eski davranışlar (değiştirilmedi)

- `anket:otomatik-gonder` `gonder_saat_sonra` değerine bakmıyor; anket her zaman hizmet bitiminde gider. Formdaki "Gecikme (saat)" alanı bu yüzden şu an etkisizdir.
- "Premium Aç" ücret/plan denetimi yapmaz, yetkili kullanıcı tek tıkla açar.

## Kalan

- Sunucuda phpunit / canlı test yapılmadı (yerelde PHP 8.5 lint + domain testi çalıştırıldı).
- Menü `v2_rota` bağlı; eski panel menüsünün v2'ye çevrilmesi kullanıcı "geçiş" diyene kadar YOK.
- Monolitteki `anket_gonder` outbox dalı yalnız geçişte kuyrukta kalan satırlar içindir; sonra silinebilir.
