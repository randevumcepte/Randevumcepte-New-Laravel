# Müşteri tarafı v2: işletme sitesi, online randevu, müşteri paneli

Eski `HomeController@salonDetay_anasayfa`, `CustomerController` ve v1 müşteri uçlarının karşılığı.
Web (mini site + panel) ve Flutter müşteri uygulaması **aynı** `/api/v2` uçlarını kullanır.
Flutter koduna dokunulmadı, geçiş yapılmadı.

## Katmanlar

| Katman | Dosya | Sorumluluk |
|---|---|---|
| Saf kural | `Domain/Randevu/OnlineMusaitlik`, `OnlineSaatKurali` | Saat uygun mu, yerleşim, online pencere, seyreltme |
| Veri + karar | `Services/Online/OnlineMusaitlikServisi` | Takvimi toplar, saat listesi ve kayıt yerleşimi tek yoldan |
| Kapı | `Services/Online/OnlineSalonKapisi` | Salon çözümü, online açık mı, lisans |
| Vitrin | `Services/Online/VitrinServisi`, `OnlineSecenekServisi` | Herkese açık alanlar (açık liste) |
| Yazma | `Services/Online/OnlineRandevuServisi` → `Randevu/RandevuKayitServisi` | Kilit + yerleşim; asıl yazma mevcut serviste (seans, outbox, log) |
| Hesap | `Services/MusteriHesap/*` | Oturum, token, profil, randevularım, yorum |

## Uçlar

Kimliksiz (`routes/v2/vitrin.php`, throttle):

| Uç | v1 / eski karşılığı |
|---|---|
| `GET /vitrin/coz?domain=\|app_bundle=` | `salonAyarlariByBundle`, HTTP_HOST çözümü |
| `GET /vitrin/{id}` | `salonDetay_anasayfa` |
| `GET /vitrin/{id}/yorumlar` | `salonyorumlari` |
| `GET /vitrin/{id}/randevu-secenekleri` | `randevuIcinGerekliVeriler(randevuAlSayfasi)` |
| `GET /vitrin/{id}/personeller?hizmet_id=` | `personelAdiminaGec`, `/personelgetir/{id}` |
| `GET /vitrin/{id}/saatler?tarih=&kalemler[]` | `randevuTarihSaatAdimi`, `/tarihsaatadiminagec`, `/saatgetir` |
| `POST /musteri-oturum/giris` | `/login` (müşteri), web `Auth/LoginController` |
| `POST /musteri-oturum/kayit` | `/randevuonayla-direkt`, `yenimusteridanisankaydi` |
| `POST /musteri-oturum/sifre-kodu`, `/sifre-sifirla` | `/sifregonder` (iki adıma bölündü) |

Müşteri token'ı ile (`auth:api`, `routes/v2/musteri-uygulama.php`):

| Uç | v1 / eski karşılığı |
|---|---|
| `GET\|PATCH /musteri-uygulama/ben` | `/musteri/{id}`, `musteribilgiguncelle`, `/profilim` |
| `POST\|DELETE /ben/resim`, `POST /ben/sifre`, `POST /ben/cikis` | `musteriprofilresimyukle`, `/sifredegistir` |
| `GET /randevular` | `/randevular(musteriMi)`, `/randevularim` |
| `POST /randevular` | `randevuekleguncelle(uygulama)`, `/randevuekle` |
| `POST /randevular/{id}/iptal` | `randevuiptalet` |
| `GET /paketlerim?salon_id=` | `paketVarmiKontrolu` |
| `POST /yorumlar`, `POST /yorumlar/{id}/bildir` | `yorumyap`, `yorum-bildir` |

## Tek kural: saat listesi = kayıt kuralı

Eski üç algoritma birleşti. Kararlar ve eski davranışla bilinçli farklar:

| Konu | Yeni kural | Eski |
|---|---|---|
| Doluluk | `durum<2`, salon içinde, personelin gerçek aralığı | `saatgetir` iptalleri de sayıyor, başka personeli de engelliyordu |
| Süre | Hizmetin tüm süresi sığmalı | `saatgetir` yalnız başlangıca bakıyordu |
| Çoklu hizmet | Sıra bağımsız, her kalem kendi diliminde | `saatgetir`'de yoktu |
| Personel çalışmıyor | `calisiyor=0` o gün kapalı | `saatgetir` salon saatine düşüyordu |
| Salon molası | Herkesi kapatır | Mobil ve web ilk açılış bakmıyordu |
| Online saatler + günlük limit | Web ve mobil aynı; kayıtta da denetlenir | Kayıt ucu denetlemiyordu; web'de günlük limit yoktu |
| Online kapalı salon | Saat ve kayıt 422 | Yalnız butonlar gizliydi |
| Süre, fiyat, personel uygunluğu | Sunucu belirler | v1 istemciden alıyordu |
| Paket seansı | Web de mobil gibi seansa bağlanır | Web'de yoktu |
| Eşzamanlı kayıt | Personel kilidi altında yeniden hesap | Kilit yoktu |
| `saat_bitis` | Başlangıç + toplam süre | Web'de başlangıç + slot aralığı |
| "Farketmez" | API destekler (`personel_id=0`), boş ilk aday atanır | Web'de gizli, mobilde vardı |

## Güvenlik farkları

- Kimlik yalnız token'dan; gövdedeki `user_id` yok.
- İptalde sahiplik ve zaman denetimi var (eskisinde id'yi bilen iptal edebiliyordu).
- Şifre sıfırlama: önce SMS kodu, sonra yeni şifre. Eski `/sifregonder` numarayı bilenin isteğiyle şifreyi anında değiştiriyordu.
- Yanlış giriş denemeleri sayılır.
- Yorum: kullanıcı + salon başına tek kayıt, puan 1-5, sadakat satırına dokunmaz.
- Personel yanıtında maaş ve prim oranı yok.

## Token

`MusteriTokenServisi`, eski Passport'un personal access token'ı ile aynı biçimde üretir (RS256 JWT + `oauth_access_tokens`, `MUSTERI_TOKEN_CLIENT_ID` varsayılan 22). Bu yüzden `storage/oauth-private.key` sunucuda php-fpm tarafından okunabilir olmalı.

## Flutter geçişinde dikkat

Bugün müşteri uçlarının çoğu Bearer göndermiyor. Geçişte:
- Her çağrı token'ı göndermeli, `user_id` gönderimi kalkmalı.
- `Personel.fromJson` `maas` ve `*_prim_yuzde` okuyor; alanlar artık yok, null toleransı kontrol edilmeli.
- Saatler `saatler[].{saat, dolu}` aynı; zarf `veri` altında.
- Şifre sıfırlama akışı iki ekrana dönüşür.

## Web sayfaları

Önizleme: `/site/{salonId}`, `/giris`, `/profilim`, `/randevularim`, `/sifrem` (`SiteController`). Host salonun kendi domain'i değilse sayfa `noindex` olur, ziyaret yazılmaz. Salon domain'lerini yeni uygulamaya yönlendirmek (geçiş) yapılmadı.

Tasarım: `resources/views/site/*` eski `salondetaylari` ve `layout_salondetay`'ın HTML + CSS sınıflarıyla aynıdır; CSS `public/site/` altında kopyadır. Blade'de sorgu yok. Oturum tarayıcıda token ile (`rc_musteri_token`), header istemcide çizilir.

## Henüz taşınmayanlar

| Konu | Durum |
|---|---|
| Çarkıfelek bandı + popup (`/cark/*`) | Sayfada yok; müşteri API'si gerekli |
| Sadakat sekmesi (`/sadakat`, ödüllerim) | Panelde yok; müşteri API'si gerekli |
| Salon domain → yeni uygulama yönlendirmesi | Geçiş kararı bekliyor; `sitemap`, `robots`, `/personel/{id}` o zaman |
| Grup dersi mini-site sayfası | API var (`/musteri-uygulama/grup-dersleri`), sayfa eski monolitte |
| Giriş sayfası tasarımı | Panel kartı stilinde; eski `auth/login` görünümü taşınmadı |

Bilinçli çıkarılanlar (ölü/çalışmayan): favoriler, fırsatlarım, galeri admin sil butonu (görsel silme panelden), `/randevuyorumlapuanla`, `/kampanyafirastbildirimackapa`.

## Sunucuda doğrulama

```
php artisan route:clear
php artisan test --filter=OnlineMusaitlikTest
```
