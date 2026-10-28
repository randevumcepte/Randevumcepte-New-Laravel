# Mimari Sözleşmesi — Randevumcepte-New-Laravel

> Bu belge yeni projedeki tüm kodun uyması gereken kuralları tanımlar.
> Amaç: **modüler, kod tekrarsız, reusable** bir sistem; tek API kümesi hem
> web panelini hem Flutter mobil uygulamasını besler.

## 1. Tek API, iki tüketici
- Her işlev **bir kez** `/api/v2/...` olarak yazılır.
- Tüketiciler: **web paneli (Vue island + `apiV2()` fetch)** ve **Flutter (`/uygulamalar/randevumcepte`)**.
- Aynı mantık mobil/web için ikiye bölünmez. Web'e özel bir ihtiyaç varsa aynı
  servisin bir metodu olarak eklenir, kopyalanmaz.
- v2 JSON sözleşmesi Flutter'ın mevcut model (`fromJson`) alan adlarıyla uyumlu
  tasarlanır; böylece mobil taraf minimum değişiklikle geçer.

## 2. Katmanlar (sorumluluk ayrımı)
```
routes/api.php        → yalnızca yönlendirme (v2 prefix)
Http/Controllers      → ince; FormRequest doğrula, Servisi çağır, Resource döndür
Http/Requests         → girdi doğrulama (FormRequest)
Http/Resources        → çıktı JSON sözleşmesi (tek biçim)
Services/*            → İŞ MANTIĞI burada (reusable, DB'li)
Domain/*              → saf hesap/değer nesneleri (DB'siz, birim-test edilebilir)
Models/*              → paylaşılan DB tablolarına Eloquent eşlemesi
```
- Controller'da iş mantığı YOK. Parasal hesap `Domain` altında **saf fonksiyon**
  (kuruş = integer) olarak yazılır ki yuvarlama test edilebilsin.

## 3. Paylaşılan veritabanı
- Aynı MySQL eski monolitle paylaşılır. **`php artisan migrate` ÇALIŞTIRILMAZ.**
- Şema değişikliği YALNIZCA eski klasörde (`randevumcepte-yeni`) migration +
  `Kernel.php` kaydı ile yapılır.
- Modeller: `protected $table`, `public $timestamps = false` (legacy tablolar),
  `$fillable` açık. charset/collation = utf8mb4 (eski sistemle aynı).
- `SESSION/CACHE/QUEUE` sürücüsü **database OLMAMALI** (migration yasak) →
  file/redis kullan.

## 4. Kimlik doğrulama
- Laravel Passport, eski monolitle **paylaşılan** `oauth_access_tokens` + ortak
  `oauth-public.key`. Çoklu guard (api / isletmeyetkilileri / sistemyoneticileri
  / satisortakligi). Flutter'ın mevcut tokenı yeni app'te de geçerli olur.

## 5. Kod stili
- Türkçe docblock + açıklayıcı isimler (okunabilirlik önceliği).
- jQuery yeni kodda YOK. AJAX = native `fetch` (`resources/js/lib/apiV2.js`).
- Canlı hesap içeren ekran → Vue 3 SFC bileşeni (island). Statik → vanilla.

## 6. v2 API sözleşmesi (Flutter akışından türetildi)
Flutter mevcut v1'de `https://apptest.randevumcepte.com.tr/api/v1`'e bağlanıyor;
alanlar **snake_case**, sayılar hem formatlı string (`"1.234,56"`) hem `_numeric`
double olarak geliyor. Önemli tespitler ve v2 kuralları:
- **Standart zarf:** `{ "basarili": bool, "veri": <payload>, "mesaj": string }`.
  (v1'de tutarsız: bazen `basarili:true`, bazen `durum:'basarili'` — v2'de tekleştir.)
- **Alan adları snake_case kalır** → Flutter `fromJson`'ları minimum değişir.
  Liste zarfları korunur: `satislar` → `{data[], acik_sayisi, kapali_sayisi, last_page}`.
- **Sayısal alanlar:** ham `_numeric` (double) KANONİK; formatlı string yalnızca
  gösterim için. Web (Vue) ham değeri kullanır; mobil ikisini de okuyabilir.
- **Ödeme yöntemi id konvansiyonu:** 1=Nakit, 2=Kredi Kartı, 3=Havale/EFT (korunur).
- **Auth ZORUNLU:** v1'deki tahsilat/satış/prim çağrıları `backend.dart`'ta Bearer
  GÖNDERMİYOR. v2 Passport ile korunacağı için, bir uç v2'ye taşınırken Flutter'da
  o çağrı `ApiClient.postJson`'a alınmalı (token gitsin). Yani her uç geçişi
  = backend.dart'ta base-url + Bearer düzeltmesi (planlı, uç-bazlı).

## 7. Finansal ilke (bkz. strangler-tahsilat-yol-haritasi.md)
- Tahsilat kalemlere BÖLÜNMEZ; tek `tahsilatlar` satırı.
- Prim = `kalem_prim_potansiyeli × (adisyon_ödenen / adisyon_toplam)` — oran bazlı,
  dinamik, yuvarlama-güvenli. Geçişte eski `tahsilat_*` dual-write ile beslenir.
