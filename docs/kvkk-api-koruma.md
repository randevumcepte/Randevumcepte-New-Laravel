# KVKK / API Koruma Modeli — /api/v2

> Zorunlu sözleşme. Kişisel veri işleyen HER v2 ucu bu kurallara uymadan yayına alınmaz.

## 1. Üç kapı (her kişisel-veri ucunda)
1. **Kimlik (authN):** `auth:isletmeyonetim-api` (legacy-passport). routes/api.php'de kişisel-veri uçları varsayılan KORUMALI gruptadır; yalnızca kişisel veri İÇERMEYEN uçlar (health) public olabilir.
2. **Kapsam (tenant izolasyonu):** `GuvenlikKapisi` → kullanıcı kaydın `salon_id`'sine bağlı mı? (Başka işletmenin verisine erişim yok.) Kayıt yoksa/kapsam dışıysa 404/403.
3. **Yetki (authZ):** `GuvenlikKapisi` → gereken `satis.*` / `rapor.*` anahtarı (kanonik `YetkiServisi`). Fail-closed.

## 2. Veri minimizasyonu
- **Ham model dökümü YASAK.** Servis/Resource yalnızca gereken alanları döndürür (açık liste).
- Serbest-metin PII (ör. `tahsilat.notlar`), gereksiz kimlik alanları (ör. ödeme listesinde `user_id`/`olusturan_id`) **döndürülmez**.
- Telefon/kimlik gibi hassas alanlar gösterildiğinde `musteri.telefon_gor` yetkisine göre `YetkiSabitleri::telefonMaskele` ile **maskelenir**.

## 3. Loglama
- İstek logunda: kim (yetkili_id), hangi işletme/şube, uç, sonuç, süre.
- Telefon, token, müşteri adı/kimlik gibi veriler log'a **açık yazılmaz**.

## 4. Hata sözleşmesi (tutarlı, istemci-dostu)
`{ basarili, veri, mesaj }` + HTTP: 401 kimlik, 403 yetki/kapsam, 404 bulunamadı, 422 doğrulama. (bootstrap/app.php `ApiException`/AuthenticationException/NotFound/Validation eşlemesi.)

## 5. Kararlı sözleşme → Flutter modülerliği (gelecek)
Flutter geçişi EN SON yapılacak; o güne kadar backend kararlı kalmalı:
- **snake_case** alan adları (Flutter mevcut `fromJson`'larıyla uyum → drop-in).
- **Versiyonlu** yol (`/api/v2`), kırıcı değişiklik yeni sürümle.
- Servis = iş mantığı, Resource/açık-dizi = JSON sözleşmesi (seam ayrık → bakım kolay).
- Sayısal alanlar: `_kurus` (kanonik int) + görünüm string ayrı.

## 6. Uç denetimi (güncel)
| Uç | Kişisel veri | Kimlik | Kapsam+Yetki | Minimizasyon |
|---|---|---|---|---|
| `GET /api/v2/health` | Hayır (yalnız servis canlılığı; veritabanı açmaz) | — (public, genel istek sınırı) | — | — |
| `GET /api/v2/adisyon/{id}` | Evet | ✅ auth:isletmeyonetim-api | ✅ GuvenlikKapisi (kapsam + `satis.tum_satis_gor`\|`adisyon_olustur`\|`tahsilat_al`) | ✅ açık alanlar, notlar/PII hariç |

> Geçici test ucu `/whoami` KALDIRILDI (yüzey azaltma). `/health` public kalır (kişisel veri yok).

## 7. Eski (v1) not
Eski monolit v1 uçlarının bir kısmı korumasız (ör. mobil `tahsilatekle` yetki-gate'siz). Bunlar **v2'ye taşınırken** bu modelle kapatılır; v1 legacy olarak emekliye ayrılacak.

## Güvenlik kapıları (denetim düzeltmeleri)

- **Kodlu işlem sözleşmesi (web + mobil aynı):** kod gerekirse `428 {basarili:false, veri:{dogrulama_gerekli:true, kod_gonderildi}, mesaj}`; istemci kodu sorar, aynı isteği kodla tekrarlar. Kod üretme/saklama/doğrulama tek yerde (`App\Support\OnayKodu::zorunlu`), web penceresi tek yerde (`public/js/lib/v2-onay-kodu.js`).
  - SMS ayarı 23 (`HesapSahibiOnayi`): hesap sahibi olmayan kullanıcı tahsilat (`DELETE /tahsilat/{id}`, `DELETE /kasa/gelir/{id}`), satış kalemi (`DELETE /kalem/{tip}/{id}`) ve adisyon (`DELETE /adisyon/{id}`) silerken hesap sahibine kod gider; alan `onay_kodu`.
  - SMS ayarı 16 (`MusteriDogrulamaKodu`): seans "geldi" (`PATCH /seans/{id}`, `POST /seans`, `geldi=1`) müşteri kodu ister; alan `dogrulama_kodu`. Randevu geldi akışı aynı ayar ve metni kullanır.
  - Kod mesajları kuyruk `sms` türü, `dogrulama=true`: WhatsApp öncelikli, acil, gidemezse SMS.
  - Tahsilat düzenlemede (`PATCH /tahsilat/{id}`) azaltma NET üzerinden (`TahsilatNet`: yeni `tutar - komisyon` < eski `tahsilatlar.tutar`); komisyonu artırmak da `satis.tahsilat_sil` + ayar 23 onayı ister, kod yeni net tutara bağlıdır.
  - Kayıtta düz tutulan müşteri kodları (randevu geldi `randevular.dogrulama`, SMS ayarı 22 KVKK `musteri_portfoy.onay_kodu`) tek kapıdan (`KayitKodKapisi`): `hash_equals`, boş kayıtlı kod hiç geçmez, yanlış deneme dry-run dahil sayılır (10 dk'da 5 → kod ölür/yenilenir; günlük sınır), gönderim yeniden gönderim beklemesi + günlük kod sınırıyla; KVKK'da yanlış kodda yeniden SMS gitmez.
- **Hesap sahibi ve giriş hesabı:** sahibin rolü, telefonu, şifresi, pasif/arşiv ve yetki ayarları (`PATCH /personel/{id}/yetki`) başkasınca değişmez (`HesapSahibiKorumasi`; rol ve yetkide sistem yönetimi istisna). Sahip tanımı tek: personel `role_id=1` VEYA `model_has_roles` rol 1 (`HesapSahibiDenetimi`; yetki motoru da aynı). Personel ekle/düzenlemede telefonla bulunan hesap başka işletmede personel/rol satırına sahipse bağlanmaz, `gsm1` başka hesabın numarasına çevrilmez, başka salonlarda da kayıtlı hesabın telefonunu yalnız kendisi değiştirir (`GirisTelefonu`, profille ortak; 422).
- **Pasif personel:** kapsam ve yetki yalnız `aktif=1` personel satırını sayar; tüm personel satırları pasif hesabın token'ı guard'da her istekte reddedilir (kuyruk beklenmez).
- **İstek sınırı:** tüm `/api/v2/*` uçlarında IP + kimlik başına dakikada 300 (`throttle:v2-genel`, eski Kernel ile aynı); kimliksiz yazma uçlarında ayrıca kendi sıkı sınırı ya da imza. `RotaKorumaTest` denetler.
- **Online randevu:** IP başına saatte 5, müşteri başına salon başına günde 3; web formunda bal küpü + 3 sn (`OnlineRandevuSiniri`, `BotKapisi`).
- **Kayıt / profil:** kayıt cevabı numaranın kayıtlı olup olmadığını söylemez (uygulama kaydı: kayıtlı numaraya "hesabınız var" mesajı gider); kayıtlı numara denemesi de sınıra işlenir; profilde dolu numara genel mesajla reddedilir, değişim denemesi günde sınırlı.
- **Token adreste:** web paneli yalnız `#token=` (fragment) kabul eder ve okuduktan sonra adresten siler; `?token=` kabul edilmez. Eski panel köprüsü (`/isletmeyonetim/v2-gecis`) zaten `#token=` kullanır, uyumluluk değişikliği gerekmez.
- **Salon hesabına giriş:** sistem kullanıcısı çıkış yapınca ya da pasife alınınca açık `imp_` token'ları iptal edilir, loglar kapanır.
