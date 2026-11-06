# Sistem Yönetimi (v2)

Eski `/sistemyonetim/v2` panelinin karşılığı: `/sistemyonetim/*` ekranları + `/api/v2/sistem/*`. Eski panel çalışmaya devam eder, aynı tabloları kullanır.

## Mimari

| Katman | Yer | Kural |
|---|---|---|
| Rota | `routes/v2/sistem.php`, `routes/v2/sistem/*.php` | Rol kapısı rotada: `sistem.rol:super_admin,yonetici` |
| Controller | `Api/V2/Sistem/*`, taban `SistemTabanController` | İnce: doğrula, servisi çağır, `ApiCevap` |
| İş mantığı | `Services/SistemYonetim/*` | Request bilmez |
| Saf kural | `Domain/SistemYonetim/*` | DB'siz, birim test edilir |
| Ekran | `SistemSayfalari` + `public/js/sistem/<modul>.js` | Tek görünüm (`sistem/ekran.blade.php`), veri yalnız API'den |

Ortak: `SistemYetki` (rol kümeleri), `SalonKapsami` (destek rolü yalnız atanmış salon), `Denetim` (audit log), `SorguSayfasi` + `DiziSayfasi` (liste), `CsvCevabi` (formül korumalı), `SistemBildirimServisi` (sistem WhatsApp + SMS), `JsonAyarDosyasi`; web'de `lib/v2-sistem.js` (istemci), `sistem/ortak.js` (görünüm parçaları), `lib/v2-tablo.js`, `lib/v2-modal.js`.

## Kimlik

`POST /api/v2/sistem/oturum/giris {email, password}`. Guard `sistemyonetim-api`. Token subject'i `sy:<id>`: aynı id işletme tablosunda başka kişi olabildiği için token'lar guard'lar arasında geçmez. Süre 12 saat. Pasif edilen hesap anında düşer. Tarayıcıda `rc_sy_token`.

**Salon hesabına gir:** yetkili adına 10 saatlik token (`imp_` önekli id). Eski oturum/çerez hilelerinin yerini alır; işletme panelinde üst bant ve "Yönetim Paneline Dön". `POST /sistem/salon/{id}/hesabina-gir`, `POST /sistem/impersonation/{logId}/bitir`.

## Roller

| Rol | Kapsam |
|---|---|
| `super_admin` | Her şey; ekip yazma, hesap sahibi yapma, Sistem WhatsApp, toplu silme yalnız burada |
| `yonetici` | Salon yazma, ekip okuma, izleme ekranları, kredi/kontör/paket |
| `destek` | Yalnız atanmış salonlar; talep, not, hazır cevap, asistan kalıbı, sipariş ve ödeme linki yazma, salon hesabına giriş |
| `izleyici` | Yalnız okuma |

## Uçlar

| Alan | Rota dosyası | Kök |
|---|---|---|
| Salon, not, üyelik, hesap | `sistem/salon.php` | `/salon` |
| Talep, hazır cevap, duyuru, SMS paket, sipariş, manuel ödeme | `sistem/destek.php` | `/talep`, `/hazir-cevap`, `/duyuru`, `/sms-paket`, `/paket-siparis`, `/manuel-odeme` |
| Dashboard, risk, performans, ekip, aktivite, güvenlik, sağlık, profil | `sistem/izleme.php` | aynı adlarla |
| WhatsApp, sistem WhatsApp, dakika, AI kredi, asistan eğitimi | `sistem/entegrasyon.php` | `/whatsapp-yonetim`, `/sistem-whatsapp`, `/dakika`, `/ai-kredi`, `/asistan-egitim` |

## Eski panelden bilinçli farklar

- **Yetki sıkılaştı:** izleyici hiçbir yazma ucuna giremez (eskide talep, not, kredi gibi çoğu uç açıktı). AI kredi, WhatsApp kontör ve paket yazma yalnız yönetim. WhatsApp log ve mesajları izleyiciye kapalı. Destek rolünün salon kapsamı not, talep açma, ödeme linki, WhatsApp okumaları ve dakikaya da uygulanır.
- **Denetim:** güvenlik duvarı, WhatsApp paket/kontör, AI kredi, asistan yazmaları ve CSV indirmeleri artık loglanır.
- **Demo tanımı:** `demo_hesabi=1` (eskide `uyelik_turu=3` ve 90 gün). Bayrağı bayat kalmış salonlar Demo görünebilir.
- **Salon hesabına gir:** eski panel yerine v2 panelini açar.
- **Taşınmayanlar:** geçici toplu salon silme; gerçek xlsx (müşteri aktarımı CSV); eski avantaj ve hizmet havuzu ekranları ("Klasik Görünüm" linkiyle eski panelde); salon tarafı destek ve duyuru okundu ekranları.
- **Son giriş sinyali:** `isletmeyetkilileri`'nde giriş tarihi kolonu yok. Sağlık skoru yetkilinin `updated_at`'ini kullanır, yaklaşık bir değerdir.
- **Asistan kalıp önbelleği:** eski uygulamada; kalıp değişikliği en geç 10 dakikada yansır.

## Sunucuda gerekenler

`.env`: `PLATFORM_SMS_*`, `WHATSAPP_SERVICE_TOKEN` (zaten gerekliydi), `ANTHROPIC_API_KEY`. PDF için `upload_max_filesize` ve `post_max_size` en az 20 MB. Eski monolit yolu farklıysa `SISTEM_WA_AYAR_DOSYASI`, `SISTEM_AI_AYAR_DOSYASI`. PHP kullanıcısı bu iki dosyaya yazabilmeli. Yeni rota sonrası `php artisan route:clear`.

Test: `php artisan test tests/Feature/Sistem tests/Unit/SistemYonetim` (izole sqlite; `SistemKimlikTest` oauth anahtarı ister). Henüz çalıştırılmadı.
