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
| `GET /api/v2/health` | Hayır (servis/DB durumu) | — (public) | — | — |
| `GET /api/v2/adisyon/{id}` | Evet | ✅ auth:isletmeyonetim-api | ✅ GuvenlikKapisi (kapsam + `satis.tum_satis_gor`\|`adisyon_olustur`\|`tahsilat_al`) | ✅ açık alanlar, notlar/PII hariç |

> Geçici test ucu `/whoami` KALDIRILDI (yüzey azaltma). `/health` public kalır (kişisel veri yok).

## 7. Eski (v1) not
Eski monolit v1 uçlarının bir kısmı korumasız (ör. mobil `tahsilatekle` yetki-gate'siz). Bunlar **v2'ye taşınırken** bu modelle kapatılır; v1 legacy olarak emekliye ayrılacak.
