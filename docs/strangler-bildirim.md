# Bildirimler v2 (strangler)

Bildirim kutusu, FCM cihaz kaydı ve panel zili New-Laravel'de. Web paneli, Flutter yönetici ve Flutter müşteri aynı servisleri kullanır. Flutter kodu değişmedi; v1 uçları yerinde duruyor ve iki taraf aynı tablolarla (`bildirimler`, `bildirim_kimlikleri`) çalışıyor.

## Uçlar

Yönetici (`auth:isletmeyonetim-api`, her istekte `salon_id`):

| v2 | v1 karşılığı |
|---|---|
| `GET /api/v2/bildirim` (`filtre=okunmamis`, `page`, `per_page`, `ara`) | `GET /bildirimgetir/{salon}/{personel}`, web `bildirimkontrolet?liste=1`, `/bildirim/liste` |
| `GET /api/v2/bildirim/sayac` | dashboard `okunmamisbildirimler`, web `bildirimkontrolet`, `/bildirim/okunmamis-sayi` |
| `POST /api/v2/bildirim/{id}/okundu` | `POST /bildirimguncelle`, web `bildirimokundu`, `/bildirim/okundu` |
| `POST /api/v2/bildirim/tumu/okundu` | `POST /tumBildirimleriOku/{salon}/{personel}` |
| `DELETE /api/v2/bildirim/{id}` | web `bildirimsil` |
| `DELETE /api/v2/bildirim` | web `bildirimtumusil` |
| `POST /api/v2/bildirim/cihaz` | `/bildirim/cihaz-kaydet`, `/bildirimkimligiekleguncelle`, web `bildirim/cihaz-kaydet` |
| `DELETE /api/v2/bildirim/cihaz` | `/bildirim/cihaz-sil` |
| `GET /api/v2/bildirim/web-ayar` | layout'a gömülü Firebase ayarı |

Müşteri (`auth:api`, kapsam `app_bundle` veya `salon_id`; eski adlar `appBundle`, `sube` de geçerli):

| v2 | v1 karşılığı |
|---|---|
| `GET /api/v2/musteri-uygulama/bildirimler` | `POST /bildirimgetirmusteri` |
| `GET /api/v2/musteri-uygulama/bildirimler/sayac` | `musteriozet.musteriokunmamis` |
| `POST /api/v2/musteri-uygulama/bildirimler/{id}/okundu` | `POST /bildirimguncelle` |
| `POST /api/v2/musteri-uygulama/bildirimler/tumu/okundu` | `POST /tumBildirimleriOkuMusteri/{salon}/{user}` |
| `POST`, `DELETE /api/v2/musteri-uygulama/bildirimler/cihaz` | `/bildirim/cihaz-kaydet`, `/bildirim/cihaz-sil` |

Kullanılmadığı için taşınmayanlar: `/bildirim/test` ve `/bildirim/test-cihaz` (Flutter çağırmıyor; ikisi de kimliksiz push atabiliyor).

## Flutter geçiş notları

Satır alanları `SistemBildirimleri.fromJson` ile aynı: `id, tarih_saat, url, img_src, aciklama, randevu_id, okundu, arsiv{uzanti}, baslik, tip, deep_link`. Ek alanlar: `salon_id`, `resim_url` (mutlak adres), `popup`; yönetici kutusunda `hedef{tur, musteri_id, url, web_yol}`.

Geçişte değişecekler:

- Cevap zarfı `{basarili, veri, mesaj}`; liste `veri.data` içinde sayfalı gelir (`per_page` en çok 100). `/bildirimgetir` düz dizi ve limitsizdi.
- Bearer token zorunlu. `personel_id`, `user_id`, `kullanici_tipi` gönderilmez; kimlik token'dan çözülür.
- `cihaz-kaydet` gövdesinde yönetici için `salon_id` zorunlu, `platform` zorunlu.
- Çıkışta önce `DELETE .../cihaz`, sonra çıkış çağrılır. Sırası tersine dönerse token iptal olduğu için 401 alınır.
- Dashboard'daki `okunmamisbildirimler` v2 Özet'te yok; `/bildirim/sayac` kullanılır.

## Kurallar

- Yapı: `BildirimKutusu` sözleşmesi, `PersonelKutusu` ve `MusteriKutusu` uygulamaları. Servisler alıcı türünü bilmez. İki controller ortak eylemleri `BildirimKutusuUclari` trait'inden alır.
- Yönetici kutusu: `salon_personelleri` kaydı (`yetkili_id` + `salon_id`) çözülür; kayıt yoksa kutu boştur.
- Müşteri kutusu: marka uygulamasında markanın tüm şubeleri + salonsuz eski kayıtlar; ana uygulamada verilen şube ya da tümü.
- Cihaz: bir token tek satır, tek sahip; aynı sahip + cihazın eski token'ı silinir (v1 ile aynı). Çok şubeli yönetici şube değiştirdikçe satır o şubeye geçer.
- Gönderim v2'de yok: FCM `NotificationService` ve `v2:bildirim-isle` (outbox) ile eski monolitte kalıyor.

## v1'den bilinçli farklar

- Kimliksiz okuma/yazma kapandı: v1'de id değiştirerek başkasının kutusu okunabiliyor, okundu işaretlenebiliyor, başkasının token'ı kapatılabiliyordu.
- Kişisel veri dökümü kalktı: v1 satırları `user`, `personel`, `randevu` kayıtlarını bütünüyle taşıyordu.
- Personel kaydı olmayan yetkiliye web kutusu `personel_id IS NULL` üzerinden salonun müşteri bildirimlerini gösteriyordu; artık boş.
- Personel kaydı yoksa cihaz kaydı 422 döner. v1 `isletme_yetkili_id` alanına yetkili id yazıyordu; bu id başka bir personelle çakışıp push'u yanlış kişiye götürebilir.
- Web token'ının `app_bundle` değeri salon markasıdır (v1 tarayıcı adı yazıyordu, markalı salonlarda web push süzgece takılıyordu).
- `url` kolonundaki görsel adresi hedef sayılmaz.
- v2 service worker yalnız veri mesajı çizer; görünen bildirimi Firebase SDK'sı çizdiği için v1'deki çift bildirim riski yok.

## Sunucu

- Web push için yeni uygulamanın `.env` dosyasına eski `.env`'deki `FIREBASE_API_KEY, FIREBASE_AUTH_DOMAIN, FIREBASE_PROJECT_ID, FIREBASE_STORAGE_BUCKET, FIREBASE_MESSAGING_SENDER_ID, FIREBASE_APP_ID, FIREBASE_VAPID_KEY` kopyalanmalı. Yoksa web push kapalı kalır, zil çalışır.
- Push'a tıklama, gönderim kodu `app.url` ile link ürettiği için eski panele gider. Gönderim taşınana kadar böyle kalır.
- Test: `php artisan test --filter='BildirimKutusuTest|BildirimDomainTest'`
