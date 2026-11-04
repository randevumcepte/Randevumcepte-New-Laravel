# Strangler — Profil, Hesabım & Faturalar, Header "+" Menüsü (v2)

Eski `/isletmeyonetim/profil`, `/isletmeyonetim/hesabim` ve layout header'ındaki "+" menüsünün v2 karşılığı.
Web (`/v2/profil`, `/v2/hesabim`) ve Flutter **aynı** `/api/v2` uçlarını kullanır. Flutter henüz bağlanmadı.

## Uç sözleşmesi

| Uç | İş |
|---|---|
| `GET /api/v2/profil` | Kendi profilim: `id, name, unvan, cinsiyet, email, gsm1, sms_gonderimi, profil_resim, profil_resim_url` |
| `PUT /api/v2/profil` | `{name, gsm1, email?, unvan?, cinsiyet?, sms_gonderimi?, password?, salon_id?}`; cevap aynı profil |
| `POST /api/v2/profil/resim` | multipart `resim` (v1 adı `folderPath` da olur), `salon_id?`; cevap aynı profil |
| `GET /api/v2/hesabim?salon_id=` | `sekmeler, isletme, kullanici, hizmetler, faturalar, sms_siparisleri, sms_paketleri, uyelik_url` |
| `PUT /api/v2/hesabim/fatura-bilgisi` | `{salon_id, vergi_adi, vergi_no, vergi_adresi, kdv_orani?}` |
| `GET /api/v2/kabuk` | Ek alanlar: `hizli_ekle[]`, `kullanici_menu[]` (`{kod, etiket, ikon, url}`), `salon.musteri_etiket` |

Yazma kill-switch: `HESABIM_V2_YAZMA_AKTIF` (varsayılan açık).

## Katmanlar

| Katman | Dosya | Sorumluluk |
|---|---|---|
| Domain (saf) | `Domain/Hesabim/UyelikKatalogu` | paket / periyot adları |
| Domain (saf) | `Domain/Hesabim/HizmetKarti`, `WhatsappHizmetKarti` | kart değer nesnesi, WhatsApp kuralı |
| Strateji | `Services/Hesabim/Hizmet/*` (`HizmetKaynagi`) | her hizmet grubu ayrı kaynak; yeni hizmet = yeni sınıf + `HesabimHizmetleri` kurucusu |
| Okuma | `HesabimServisi`, `OdemeGecmisi` | ekran verisi, ödeme kayıtları, duyuru paketleri |
| Yazma | `Services/Profil/ProfilServisi`; fatura: `Ayar/IsletmeBilgiServisi::kaydet` | profil, resim; fatura bilgisi Ayarlar ile tek yazma yolu |
| Menü | `Support/PanelKisayollar`, `Services/Panel/MenuGorunurluk` | "+" ve kullanıcı menüsü; sol menüyle aynı görünürlük kuralı |
| Web | `profil-v2.js`, `hesabim-v2.js`, `hizli-ekle.js`, `css/hesabim-v2.css` | island'lar |
| Ortak form | `lib/v2-kasa-form.js`, `lib/v2-form-gonder.js` | masraf + form gönder modalları (ekranlardan çıkarıldı) |

## Eski davranıştan bilinçli farklar

- **Üyelik ödemeleri artık görünür.** Eski kod `salon_uyelik_odemeleri`'ni olmayan kolonlarla (`odeme_tarihi`, `tutar`, `durum`, `dosya_url`) okuyordu; hata yutulduğu için liste hep boştu. v2 gerçek kolonları (`created_at`, `ucret`) okur. Tabloda fatura dosyası kolonu yok, `dosya_url` hep null.
- **Hesabım personele (rol 5) kapalı** (okuma + yazma). Eskide denetim yoktu; Flutter menüsü yalnız hesap sahibine gösteriyor. Rol 2/3/4 eski web gibi erişir.
- **Profil personele açık.** Eski web personeli yönlendiriyordu; Flutter'da açık olduğu için tek API davranışı seçildi. Herkes yalnız kendi profilini değiştirir; gövdedeki `yetkili_id` yok sayılır.
- **Telefon (gsm1) çakışması engellenir.** gsm1 giriş kimliği; başka hesapta olan numara 409 döner.
- **Şifre değişiminde** diğer oturumlar kapanır: outbox `sifre_degisti` → eski `OturumServisi` (token iptali, web oturumu damgası, force_logout push). İşlemi yapan oturum korunur. Web oturumu damgası eskide yoktu; eski panel oturumları da kapanır.
- **`sms_gonderimi` korunur.** Eski web formu alanı göndermediği için değeri NULL'a çekiyordu.
- **"+" öğeleri formun API yetkisine bağlı.** Eskide Yeni Randevu / Ön Görüşme / Form Gönder yetkisiz kullanıcıya da görünüyordu.

## Eski panelde kalanlar

- Ödeme: paket yükseltme (`/isletmeyonetim/uyelik`), duyuru paketi satın alma (`smspaketsatinal`, PayTR). Linkler API'den gelir; Flutter göstermez (satın alma yok).
- Çıkış: `POST /api/v2/oturum/cikis` token'ı sunucuda iptal eder, ardından `/v2/giris`'e dönülür (bkz. `strangler-oturum.md`).

## Flutter geçişi için notlar

- `GET /hesabim` cevabının `veri` alanı `HesabimVeri.fromJson` ile uyumlu. İstek parametresi `sube` değil `salon_id`; fatura bilgisi `POST` değil `PUT`; zarf `{basarili, mesaj}` (eski `success`).
- `bilgiguncelle` cevabı tüm kullanıcıyı `yetkili_olunan_isletmeler` ile dönüyordu ve Flutter bunu `user` olarak saklıyordu. v2 yalnız profil döner: Flutter mevcut `user` üzerine birleştirmeli.
- `profil_resim_url` mutlak adrestir. `hizli_ekle[].kod` Flutter ekranlarına eşlenir.
