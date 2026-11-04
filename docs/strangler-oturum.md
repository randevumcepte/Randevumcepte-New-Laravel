# Giriş, Şifremi Unuttum, Ücretsiz Deneme (v2)

Eski `AuthStoreAdmin\LoginController`, `AuthController@login`, `SifreSifirlamaController` ve `ApiController::siteden_yeni_kayit_kullanici` + `demoHesapKur` karşılığı. Web ve Flutter aynı `/api/v2` uçlarını kullanır. Flutter koduna dokunulmadı, geçiş yapılmadı; eski ekranlar çalışmaya devam eder.

## Sayfalar

| Yol | Görünüm | Betik |
|---|---|---|
| `/v2/giris` | `oturum/giris` | `js/oturum/giris.js` |
| `/v2/sifremi-unuttum` | `oturum/sifremi-unuttum` | `js/oturum/sifremi-unuttum.js` |
| `/ucretsiz-denemenizi-baslatin`, `/ucretsiz-deneme` | `deneme/baslat` | `js/oturum/deneme.js` |

Ortak betikler: `lib/v2-oturum-form.js` (form, hata), `lib/v2-oturum-depo.js` (tarayıcıdaki oturum).

## Uçlar

Hepsi `{basarili, veri, mesaj}` zarfıyla döner. Hata kodları: 401 giriş, 409 çakışma/kapalı, 422 doğrulama, 429 sınır.

| Uç | Girdi | `veri` |
|---|---|---|
| `POST /oturum/giris` | `kimlik`, `password`, `hesap_turu?`, `app_bundle?`, `cihaz_bilgi?`, `bildirim_id?` | `token, token_type, expires_at, user_type, user, musteri, ilk_sayfa` |
| `POST /oturum/sifre-kodu` | `cep_telefon`, `hesap_turu?` | `null` |
| `POST /oturum/sifre-sifirla` | `cep_telefon`, `kod`, `yeni_sifre`, `hesap_turu?` | `null` |
| `POST /oturum/cikis` (Bearer) | | `null` |
| `GET /deneme/secenekler` | | `salon_turleri[{id, ad}]` |
| `POST /deneme/kod-gonder` | `salon_turu_id, isletme_adi, isletme_adresi, ad_soyad, cep_telefon, email` | `sure_sn` |
| `POST /deneme/dogrula` | `cep_telefon`, `kod` | `salon_id, sifre_gonderildi, giris_yolu` |

`hesap_turu`: `isletme`, `musteri` ya da boş. Boşsa iki tür de denenir, isletme önceliklidir (v1 ile aynı).

## Flutter geçişinde yapılacaklar

| v1 | v2 |
|---|---|
| `POST /api/v1/login` `cep_telefon` | `POST /api/v2/oturum/giris` `kimlik` |
| `appBundle`, `cihazBilgi`, `bildirimId` | `app_bundle`, `cihaz_bilgi`, `bildirim_id` |
| `body['message']['token' / 'user' / 'musteri' / 'user_type']` | `body['veri'][...]`, aynı adlarla |
| `body['message']['success']` | `body['basarili']` |
| `body['message']['message']` | `body['mesaj']` |

`Kullanici.fromJson` ve `MusteriDanisan.fromJson` değişmeden çalışır: eksik alanlar (`password`, `dogrulama_kodu`) zaten `.toString()` ile okunuyor. `user.yetkili_olunan_isletmeler[].salonlar` açık alan listesiyle döner (`YetkiliHesabi::SALON_ALANLARI`); Flutter yeni bir salon alanı okuyacaksa oraya eklenir.

Şifre ekranı değişmek zorunda: v1 tek adımda yeni şifre üretip gönderiyordu, v2 iki adımlıdır. `sifremi_unuttum.dart` kod ve yeni şifre adımını eklemelidir.

## Eski davranıştan bilinçli farklar

| Konu | v2 | Eski |
|---|---|---|
| Şifre sıfırlama (web) | Kod telefona bağlı, hash'li, 10 dk, 5 deneme, tek kullanım | 4 haneli kodla tüm tablodan yetkili aranıyordu; kod tablodan silinmiyordu |
| Şifre sıfırlama (v1) | İki adım | Numarayı bilen herkes şifreyi anında değiştirebiliyordu |
| Kayıtlı olmayan numara | Aynı cevap | "Kullanıcı bulunamadı" |
| Numara hem müşteri hem yetkili | Yetkili sıfırlanır (giriş önceliğiyle aynı) | Müşteri sıfırlanıyordu |
| Şifre kanalı (mobil) | Yalnız SMS | Önce WhatsApp, sonra SMS |
| Aktif salonu olmayan yetkili (web) | Giriş reddedilir | Giriyor, boş panele düşüyordu |
| Web "Beni Hatırla" | Kaldırıldı; token zaten 1 yıl | Vardı |
| Giriş denemesi | 10 yanlış / 10 dk, kimlik + IP | Web 5/dk, mobil sınırsız |
| Deneme SMS'i | IP başına 5/saat, numara başına 1/dk | Sınırsız |
| Deneme işletme türü | `salon_turu` tablosundan, id ile | Sabit liste, ad ile eşleşme |
| Deneme örnek verisi | Yetim randevu yazılmaz, boş alanlar personelle dolar | Salonsuz randevu + randevusuz hizmet satırı, başka salonun oda/cihaz id'si |
| "Biz Sizi Arayalım" | Telefon bağlantısı | WordPress iletişim formu (mirror'da başka alan adına gidiyordu) |

## Kurulum

`.env` (sunucu):

```
PLATFORM_SMS_KULLANICI=   # eski koddaki platform VoiceTelekom hesabi
PLATFORM_SMS_SIFRE=
```

Tanımlı değilse SMS gitmez: deneme kaydı 422 döner, şifre kodu sessizce gitmez ve log'a yazılır. `DENEME_V2_YAZMA_AKTIF=false` deneme kaydını kapatır (409).

Eski monolit tarafı (outbox, canlıya taşınmış olmalı): `sistem_bildirim` (9ef06b9a), `sifre_degisti` (57b23a94). Yoksa kayıt hata vermez ama sistem sahibi bildirimi ve eski panel oturumunun kapanması olmaz.

## Sınırlar

- v2 girişi eski panel oturumu açmaz. v2'den eski panele giden linkler (ödeme, form PDF) eski giriş ekranını gösterir.
- Token'da hesap tipi yok (bkz. `strangler-musteri-sitesi.md` güvenlik bulgusu); `hesap_turu=isletme` yalnız girişi korur.
