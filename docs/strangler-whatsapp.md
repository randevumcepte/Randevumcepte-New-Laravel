# Strangler — WhatsApp Yönetimi (v2)

Eski web `/isletmeyonetim/whatsapp` (`StoreAdminController::whatsapp*`, `whatsappKonumKaydet`, `isletmeadmin/whatsapp.blade`,
`whatsapp_kontor_mobil.blade`) ve mobil `WhatsappMobileController` (`lib/yonetici/diger/menu/whatsapp/whatsapp_yonetimi.dart`)
ekranlarının v2 karşılığı. Web (`/v2/whatsapp`) ve Flutter **aynı** `/api/v2/whatsapp/*` uçlarını kullanır.
Menü: `PanelMenu` → `v2_rota`. Yetki: `pazarlama.whatsapp_gonder` (tüm uçlar); açık telefon `musteri.telefon_gor`.
Yazmalar `WHATSAPP_V2_YAZMA_AKTIF` arkasında (varsayılan açık; kapalıyken 409).

## Sunucu gereksinimi

Yeni uygulamanın `.env`'inde **`WHATSAPP_SERVICE_TOKEN`** eski monolitteki değerle aynı olmalı (köprü `X-Service-Token` ister;
yoksa 401 → durum "Bağlantı Kesildi", başlat 502). `WHATSMEOW_SERVICE_URL` varsayılanı `http://127.0.0.1:3002`.

## Uç sözleşmesi

Zarf: `{basarili, veri, mesaj}`. Tümü `salon_id` ister (GET: query, yazma: JSON gövde).

| Uç | Dönüş |
|---|---|
| `GET /whatsapp` | `{sekmeler[], nasil_calisir[], sozluk{baglanti_durumlari[], gonderim_durumlari[]}, kanal, linkler, kontor, yazabilir}` |
| `GET /whatsapp/durum` | `{status, ad, renk, phone, bagli, lastError, connectedAt, paylasilan}` |
| `GET /whatsapp/qr` | `{qr}` — PNG data URI ya da henüz yoksa `null` (`Image.memory(base64Decode(...))`) |
| `POST /whatsapp/baslat` | durum (taze oturum; gerçekten bağlı değilse eski kimlik silinir) |
| `POST /whatsapp/pair-phone {phone}` | `{code}` — oturumu kendisi başlatır, "henüz hazır değil"de 4 sn sonra bir kez daha dener |
| `POST /whatsapp/cikis` | — |
| `GET /whatsapp/ozet` | `{bugun{toplam,basari,fail,fallback}, hafta, ay, basariOrani, gunler[30], durum, numara, gunluk_limit}` |
| `GET /whatsapp/loglar?durum=&baslangic=&bitis=` + `ListeIstegi` | `{data[], current_page, last_page, per_page, total}` |
| `GET /whatsapp/aliciler` + `ListeIstegi` | `{data[{id, telefon, musteri_adi, toplam, basari, fail, fallback, son_mesaj, ilk_mesaj}], …}` |
| `GET /whatsapp/aliciler/{id}/gecmis` | `{telefon, musteri_adi, data[son 100]}` — `id`: alıcı satırındaki id |
| `GET /whatsapp/kanal` · `PUT {aktif}` | `{aktif, sms_aktif_aynigun, sms_aktif_24sa}` |
| `GET /whatsapp/linkler` · `PUT` (kısmi) | `{konum_linki, instagram_linki, web_linki, konum_baslik, instagram_baslik, web_baslik}` |
| `GET /whatsapp/kontor` | `{bakiye, kontorlu_donem, seviye, renk, ikon, baslik, metin, not, rozet, uyari, paketler[], paylasilan}` |
| `POST /whatsapp/kontor/talep {paket, iletisim?}` | mesaj (outbox) |

Liste parametreleri: `page, per_page (≤100), ara, sirala, yon`. `sirala`: loglar `tarih|durum|musteri|telefon`; alıcılar `son|ilk|toplam|musteri|telefon`.

## Flutter eşlemesi (`Backend/backend.dart`)

| v1 | v2 | Fark |
|---|---|---|
| `POST /whatsapp/baslat/{id}` | `POST /whatsapp/baslat` | eski mobil uç paylaşılan oturumu korumuyordu; v2 korur (409) |
| `GET /whatsapp/durum/{id}` | `GET /whatsapp/durum` | `status`, `phone` aynı; `ad`/`renk` ile `switch` kalkabilir |
| `GET /whatsapp/qr/{id}` | `GET /whatsapp/qr` | `qr` artık hazır görsel |
| `POST /whatsapp/cikis/{id}` | `POST /whatsapp/cikis` | |
| `POST /whatsapp/pair-phone/{id}` | `POST /whatsapp/pair-phone` | `phone` aynı |
| `GET /whatsapp/ozet/{id}` | `GET /whatsapp/ozet` | alan adları aynı |
| `GET /whatsapp/loglar/{id}` | `GET /whatsapp/loglar` | `rows`→`data`, `son_sayfa`→`last_page`, `telefon`+`arama`→`ara`; `telefon` maskeli gelebilir |
| `GET /whatsapp/aliciler/{id}` | `GET /whatsapp/aliciler` | `rows`→`data`; sayfalı (eski tavan 500) |
| `GET /whatsapp/alici/{id}/{telefon}` | `GET /whatsapp/aliciler/{aliciId}/gecmis` | telefon yerine alıcı satırının `id`'si; `rows`→`data` |
| `GET /whatsapp/kanal-durum/{id}` | `GET /whatsapp/kanal` | alan adları aynı |
| `POST /whatsapp/kanal-toggle/{id}` | `PUT /whatsapp/kanal` | `aktif` bool/0-1 |
| `POST /whatsapp-ayar-kaydet` | `PUT /whatsapp/linkler` | alan adları aynı |
| `musteri-whatsapp-durum` (linkler) | `GET /whatsapp/linkler` | müşteri onayı/manuel mesaj bu kapsamda değil |
| `mobil/webview-token?hedef=kontor` (WebView) | `GET /whatsapp/kontor` + `POST /whatsapp/kontor/talep` | yerel ekran yapılabilir; fiyat yine sunucudan |
| `GET/POST /whatsapp/paket-*` (eski abonelik; ekranda ölü kod) | — | taşınmadı (kontör modeli) |

Sekme kodları: `baglanti, kontor, istatistik, mesajlar, aliciler`. Flutter'a dokunulmadı.

## Katmanlar

| Katman | Dosya | Sorumluluk |
|---|---|---|
| Domain (saf) | `Domain/Whatsapp/BaglantiDurumu` | durum sözlüğü + köprü cevabı → kanonik kod |
| | `GonderimDurumu` (enum), `GonderimOzeti` | log durumları; istatistik hesabı |
| | `KontorPaketleri`, `KontorDurumu`, `KontorTalepMesaji` | katalog (fiyat otoriter); bant/uyarı; talep metni |
| | `IsletmeLinkleri`, `WhatsappSayfasi` | link sunum/güncelleme; sekme kayıt defteri |
| Köprü | `Services/Whatsapp/Kopru/WhatsappKoprusu` + `WhatsmeowKoprusu` + `KopruCevabi` | sağlayıcı arayüzü (`AppServiceProvider` bağlar) |
| Servis | `WhatsappOturumServisi` (mevcut) | paylaşılan oturum çözümü — tek yer |
| | `WhatsappBaglantiServisi`, `QrGorselUretici` | oturum akışı; QR → PNG |
| | `WhatsappGonderimSorgusu`, `AliciAdCozucu` | log okuma + maske; telefondan ad |
| | `WhatsappAyarServisi`, `WhatsappKontorServisi` | kanal + linkler; kontör + talep |
| API | `WhatsappTabanController` → `Whatsapp{,Baglanti,Gonderim,Kontor}Controller`, `Requests/Whatsapp/WhatsappLinkIstegi` | ince katman |
| Web | `resources/views/v2/whatsapp.blade`, `public/js/whatsapp-v2.js`, `public/js/whatsapp/*`, `public/css/whatsapp-v2.css` | sekmeli island |

Yan etki: kontör talebi outbox `sistem_bildirim` (payload `mesaj`) → eski `v2:bildirim-isle` → `SistemBildirim::gonder`.

## Eskiden bilinçli farklar

- **QR sunucuda üretilir.** Eski ekran eşleştirme dizesini (oturum sırrı) görsele çevirmek için `api.qrserver.com`'a gönderiyordu. Bunun için `QrSurumTablosu` 11–13. sürümlerle genişletildi (kısa içerik yine küçük sürümde kalır).
- **Kimlik + kapsam + yetki:** eski `WhatsappMobileController` uçları kimliksizdi (`salonId` değiştirilerek veri okunup oturum kapatılabiliyor).
- **Telefon maskesi sunucuda;** yetkisiz kullanıcı telefonda arayamaz, geçmiş telefonla değil id ile istenir.
- **Kontör bakiyesi oturum sahibinden:** paylaşılan oturumda eski ekran alt salonun bakiyesini gösteriyordu; düşüm üst salondan olduğundan rozet ve gönderimle hizalandı.
- **Pair kodu akışı sunucuda;** istemci yalnız numarayı gönderir.
- **Talep sonucu dürüst:** eski ekran hata/zaman aşımında da "Talebiniz alındı" diyordu; v2 kuyruğa yazıldıysa başarılı, değilse hata döner.
- **Tek sayfa yerine sekmeler:** içerik aynı; Kontör ayrı sekmede, düşük bakiye uyarısı aynı sıklıkla (`uyari.gunluk_limit`).
- **Alıcılar sayfalı;** eski 500 tavanı yok. Ad araması `users` adında; yalnız telefon eşleşmesiyle doldurulan adlar aranamaz.

## Açık konular

- Paylaşılan oturumda loglar üst salonun `salon_id`'siyle yazılır (`WhatsAppService::logPending`); alt salonun Mesajlarım/İstatistik'i bu yüzden boş kalır. Eski ekranla aynı bırakıldı; üst salonun müşteri mesajlarını açmak kararı ürün sahibinin.
- Mesajlarım, şifre/OTP mesajlarının metnini de gösterir (eskiyle aynı).
- Webhook (`/webhook/whatsapp`), mesaj gönderimi ve kontör düşümü eski monolitte kalır.

## Doğrulama

`tests/Unit/Domain/Whatsapp/WhatsappDomainTest.php` — sunucuda: `php artisan test --filter=Whatsapp`.
QR çıktısı 40–331 bayt için bağımsız okuyucuyla (CoreImage) çözülerek doğrulandı. Sunucu uçtan uca testi yapılmadı.
