# Strangler — Bildirim Reklamları (v2)

Eski web `/isletmeyonetim/bildirim-reklamlari` (StoreAdminController `bildirim_reklam_*`) + mobil v1
`/api/v1/reklam/admin/*` (yönetici) + `/api/v1/reklam/liste|kupon-kap` (müşteri uygulaması) uçlarının v2
karşılığı. Web paneli (`/v2/bildirim-reklamlari`) ve Flutter **aynı** `/api/v2` uçlarını kullanır.
Yönetim yazmaları `BILDIRIM_REKLAM_V2_YAZMA_AKTIF` (varsayılan açık) arkasında; kapalıyken 409.

Eskiden üç ayrı kopya vardı (web controller, mobil admin controller, push job); kurallar (kupon başlığı,
yayında mı, şube grubu, segment) her birinde ayrı yazılmıştı. v2'de her kural tek yerde.

## Katmanlar

| Katman | Dosya | Sorumluluk |
|---|---|---|
| Domain (saf) | `app/Domain/BildirimReklam/ReklamTuru.php` | tür sözlüğü + etiket/ikon/renk (eski `$turEtiket`) |
| Domain (saf) | `ReklamDurumu.php` | taslak · aktif · pasif · bitti (+ formda seçilir mi) |
| Domain (saf) | `ReklamAksiyonu.php` | kupon · randevu (boş slot) · yok; kupon/pencere alanı var mı, kupon zorunlu mu |
| Domain (saf) | `HedefSegment.php` + `HedefKosulu.php` | `hedef_kitle` + `hedef_kosul` JSON'unun TEK okuma/yazma noktası (DB biçimi eskiyle aynı) |
| Domain (saf) | `ReklamKuponu.php` | kupon verir mi, başlık ("%20 İndirim" / "150 ₺ İndirim"), kısa metin, geçerlilik tarihi, ürün/hizmet kapsamı |
| Domain (saf) | `YayinKurali.php` | yayında mı (durum + pencere + adet), şube grubu, müşteriye görünür mü |
| Ortak | `app/Support/SalonIdListesi.php` | `gecerli_salonlar` çözümü (eski `Personeller::salonIdListesiCoz`) |
| Okuma | `Services/BildirimReklam/BildirimReklamSorgusu.php` | liste + durum sayaçları + arama, tek kayıt, kişi hedefi özeti |
| Sunum | `BildirimReklamSunumu.php` | yönetim JSON'u (eski `adminReklamArray` alanlarının tamamı + v2 ekleri), müşteri kartı |
| Yazma | `BildirimReklamYazServisi.php` | kaydet / durum / sil + referans sahipliği + şube grubu + aktivite logu |
| Görsel | `ReklamGorselServisi.php` | multipart (web) ya da base64 (Flutter v1 biçimi) → eski `public/reklam_gorselleri` |
| Hedef | `ReklamHedefKitlesi.php` | segment → alıcılar (hedef ∩ aktif FCM token, app_bundle izolasyonu); önizleme + gönderim aynı sonuç |
| Gönderim | `ReklamGonderServisi.php` | aktif + push_gonderildi + outbox `bildirim_reklam_push`; mükerrer gönderim 409 |
| Form | `BildirimReklamFormServisi.php` | sözlükler, hizmet/ürün, şubeler, varsayılanlar, yazma bayrağı |
| Müşteri | `MusteriReklamServisi.php` | in-app/popup listesi + tek dokunuşla kupon (satır kilidi) |
| HTTP | `BildirimReklamController`, `MusteriReklamController`, `Requests/BildirimReklam/*`, `routes/v2/bildirim-reklam.php`, `routes/v2/musteri-uygulama.php` | ince controller, doğrulama |
| Web | `resources/views/v2/bildirim-reklamlari.blade.php`, `public/js/bildirim-reklamlari-v2.js`, `public/css/bildirim-reklamlari-v2.css` | eski kart/modal tasarımı |

Yeniden kullanılanlar (kopya yok): `KatalogServisi::hizmetler`, `RandevuFormServisi::musteriAra` (kişi seçici,
telefon maskesi), `SalonSahipligi` (hizmet/müşteri sahipliği), `SubeGrubu` (çoklu şube seçenekleri + hedef şubeler;
çark / puan ödülü ile ortak), `DosyaYukleme`, `AktiviteLog`, `BildirimKuyrugu` (outbox), web'de `v2-modal` / `v2-secici` /
`v2-dosya` / `v2-sube-secici` / `v2-ongorusme` form yardımcıları.

Kampanya Yönetimi'nin `HedefKitleServisi` (SMS kampanyası: sadık/aktif/pasif portföy) ayrı kuraldır; push segmentleri
(kişi, gelmeyen, doğum günü, hizmet/ürün alan, cinsiyet) `ReklamHedefKitlesi`'nde.

## Uçlar

Yönetim — `auth:isletmeyonetim-api`, yetki `pazarlama.kampanya_yonet` (açık telefon `musteri.telefon_gor`):

| Uç | Açıklama |
|---|---|
| `GET /api/v2/bildirim-reklam/form-verisi?salon_id=` | `turler, durumlar, aksiyonlar, segmentler, hizmetler, urunler, subeler, varsayilan, gorsel_onerisi, yetkiler.yaz` |
| `GET /api/v2/bildirim-reklam?salon_id=&durum=&ara=&page=&per_page=` | `{data[], sayaclar{tumu,taslak,aktif,pasif,bitti}, current_page, last_page, per_page, total}` |
| `GET /api/v2/bildirim-reklam/{id}` | tek reklam |
| `GET /api/v2/bildirim-reklam/musteri-ara?salon_id=&q=` | "belirli kişi" hedefi için `[{id, ad, telefon}]` |
| `POST /api/v2/bildirim-reklam/gorsel` | multipart `gorsel` **veya** base64 `gorsel` → `{yol, url}`; `yol` kaydın `gorsel` alanına |
| `POST /api/v2/bildirim-reklam` · `PATCH /api/v2/bildirim-reklam/{id}` | tam form (alan adları eskiyle aynı) → güncel reklam |
| `PATCH /api/v2/bildirim-reklam/{id}/durum {durum}` | yayınla / duraklat |
| `DELETE /api/v2/bildirim-reklam/{id}` | sil (dağıtılmış kuponlar müşteride kalır) |
| `GET /api/v2/bildirim-reklam/{id}/alici-sayisi` | `{hedef, alici}` — gönder onayında gösterilir |
| `POST /api/v2/bildirim-reklam/{id}/gonder` | `{alici, kuyrukta}`; gönderim outbox ile |

Müşteri uygulaması — `auth:api` (users token'ı; kimlik token'dan):

| Uç | Açıklama |
|---|---|
| `GET /api/v2/musteri-uygulama/reklamlar?salon_id=` (ya da `appBundle=`) | in-app kart / açılış popup listesi (eski liste öğesiyle aynı alanlar) |
| `POST /api/v2/musteri-uygulama/reklamlar/{id}/kupon` | `{zaten, kupon{id,kod,baslik,deger,gecerlilik_tarihi}}` |

## v1 → v2 eşlemesi (Flutter geçiş günü)

| v1 | v2 | Not |
|---|---|---|
| `POST /reklam/admin/liste/{salon} {user_id}` | `GET /bildirim-reklam?salon_id=` | cevap `veri.data`; öğe alanları aynı (+ `tur_etiket`, `durum_etiket`, `kupon_ozet`, `hedef`, `gecerli_salonlar`) |
| `POST /reklam/admin/kaydet/{salon} {id?,...}` | `POST /bildirim-reklam` · `PATCH /bildirim-reklam/{id}` | alan adları aynı; cevap `veri` = güncel reklam (v1 `reklam`) |
| `POST /reklam/admin/durum/{salon} {id, durum}` | `PATCH /bildirim-reklam/{id}/durum` | |
| `POST /reklam/admin/sil/{salon} {id}` | `DELETE /bildirim-reklam/{id}` | |
| `POST /reklam/admin/gorsel/{salon} {gorsel: base64}` | `POST /bildirim-reklam/gorsel` | base64 aynen kabul; `veri.yol` / `veri.url` |
| `POST /reklam/admin/gonder/{salon} {id}` | `POST /bildirim-reklam/{id}/gonder` | v1 `gonderilen` → `veri.alici`; gönderim artık arka planda |
| `POST /reklam/admin/hizmetler|urunler/{salon}` | `GET /bildirim-reklam/form-verisi` | `hizmetler[] / urunler[]` `{id, ad}` |
| `POST /reklam/admin/musteriler/{salon}` | `GET /bildirim-reklam/musteri-ara?q=` | tüm portföy dökümü yok (KVKK); sunucu araması |
| `GET|POST /reklam/liste {salon_id|appBundle, user_id}` | `GET /musteri-uygulama/reklamlar` | `user_id` token'dan; cevap `veri` |
| `POST /reklam/kupon-kap {user_id, reklam_id}` | `POST /musteri-uygulama/reklamlar/{id}/kupon` | `zaten` aynı; hata 422 + `mesaj` |

Push deep link (`reklam_detay`, `reklam_id`) ve FCM gönderimi değişmedi (eski `NotificationService`).

## Eskiden bilinçli farklar

- **Kimlik:** eski müşteri uçları ve mobil admin uçları kimliksizdi (gövdede `user_id`); başkası adına kupon
  kapılabiliyor, başka salonun reklamı yönetilebiliyordu. v2'de token + kapsam + yetki.
- **Yetki:** eski web uçları "oturum açmış herkes" (test notu); v2 `pazarlama.kampanya_yonet` (eski menü anahtarı).
- **Kupon kapma yarışı:** kişi limiti artık satır kilidi İÇİNDE denetlenir (eski: dışında → çift dokunuşta iki kupon).
  Kişiye özel reklamın kuponu yalnız o kişiye.
- **Push:** alıcılar v2'de çözülür, gönderim outbox `bildirim_reklam_push` → eski `v2:bildirim-isle` →
  `BildirimReklamGonderJob($reklamId, $userIds)` (sync değilse `hatirlatmalar` kuyruğu, tries=1). Aynı reklamın bekleyen
  gönderimi varken ikinci gönder 409 (mükerrer push yok). Onaydan önce gerçek alıcı sayısı gösterilir.
- **Doğrulama:** kupon aksiyonunda değer > 0, yüzde ≤ 100; segment kişi/hizmet/ürün zorunlu ve salona ait;
  bitiş tarih/saati başlangıçtan önce olamaz; görsel yalnız görsel ucundan yüklenen yol olabilir (içerik gerçekten görsel mi denetlenir).
- **Şube grubu:** yalnız kullanıcının `pazarlama.kampanya_yonet` yetkisi olduğu şubelerden (mobil v1 sahibin tüm şubelerini kabul ediyordu);
  düzenlemede seçim gönderilmezse grup korunur (eskiyle aynı).
- Mobil v1 hiç aktivite logu yazmıyordu; v2 her yazmayı `salon_aktivite_log`'a yazar.

## Kalan

- Menü `v2_rota` bağlı (`/v2/bildirim-reklamlari`); eski panel menüsünün v2'ye çevrilmesi kullanıcı "geçiş" diyene kadar YOK.
- Flutter'a dokunulmadı (yönetici + müşteri ekranları v1'de). Eski uçlar yerinde; Patron Asistan kampanya servisi eski job'u kullanmaya devam eder.
- `auth:api` (müşteri token'ı) v2'de ilk kez kullanılıyor — sunucuda müşteri token'ıyla duman testi gerekli.
- Sunucuda phpunit / canlı test yapılmadı (lokalde php 8.5 lint + domain testi bağımsız çalıştırıldı).
