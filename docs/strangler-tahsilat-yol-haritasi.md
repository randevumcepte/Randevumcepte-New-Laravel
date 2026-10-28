# Strangler Geçiş Yol Haritası — Tahsilat Domain'i

> Durum: Taslak (onaylı kararlarla). Tarih: 2026-10-28.
> Hedef: Eski monolit `randevumcepte-yeni` (Laravel 5.6/PHP 7.4) işlevlerini,
> aynı MySQL veritabanını paylaşarak, strangler pattern ile peyderpey
> `Randevumcepte-New-Laravel` (Laravel 13/PHP 8.3) içine taşımak. İlk domain: **tahsilat**.

## Kilitli kararlar
- **Yönlendirme:** `/api/v2/*` path prefix nginx'te yeni app'e; geri kalan her şey eski monolite.
- **Finansal model:** Önce **oran-bazlı prim** (parçalama tablolarına dokunmadan, shadow doğrulama). Ledger sadeleştirmesi sonraki fazlara.
- **Yazma stratejisi:** **Dual-write** (yeni servis hem yeni modele hem eski `tahsilat_*` tablolarına yazar; eski monolit bozulmaz).

## Mimari ilkeler (tüm fazlarda)
1. **Migration yalnızca eski klasörden — İSTİSNASIZ.** Her şema değişikliği `randevumcepte-yeni/database/migrations` içinde yazılır + `app/Console/Kernel.php` `$commands`/migration kaydına elle eklenir (auto-discovery yok). Yeni `Randevumcepte-New-Laravel` app'i KESİNLİKLE `migrate` çalıştırmaz (deploy.sh bunu zaten yasaklıyor); tablolara yalnızca `protected $table` + `$timestamps=false` ile map'lenir.
2. **API-first.** Her işlev önce `/api/v2/...`; web ve Flutter aynı servisi tüketir. İş mantığı **Service** katmanında, girdi **FormRequest**, çıktı **API Resource**, para **integer kuruş + DTO**.
3. **Katmanlı, açıklamalı, test edilebilir.** Controller ince; parasal mantık saf (DB'siz test edilebilir) fonksiyonlarda; Türkçe docblock.
4. **Additive + geri-dönüşlü.** Eski monolit canlıyken hiçbir mevcut tablo/kolon bozulmaz. Yeni kolonlar nullable+default.

## Mevcut durum — referanslar (eski monolit)
- Modeller `App\` kökünde (`app/Tahsilatlar.php`). Çekirdek tablolar migration'sız; yalnızca prim eklemeleri migration'da.
- Web tahsilat: `StoreAdminController@tahsilatekle` (StoreAdminController.php:13718). Kalem dağıtımı 13887–13942; taksit "tek havuz + kalan-cap" 13943–14032 (commit fbebbfd7).
- API: `ApiController@tahsilatekle` (17155), `satislar` (16037). Tümü `/api/v1/` prefix (`v1` grubu).
- Prim: `primHakedisVerisi` (StoreAdminController.php:39796, web), `primHakedisTopluApi` (ApiController.php:22583, API). **Prim zaten tahsilata bağlı** (`TahsilatHizmetler/Urunler/Paketler::sum('tutar')`). `personel_prim_hareketleri` yalnızca manuel bonus/kesinti + telafi_devir.
- Prim oranları: `personel_prim_oranlari` (detaylı mod) veya `salon_personelleri.*_prim_yuzde`.
- Canlı ekran: `isletmeadmin/tahsilat.blade.php`, route `/tahsilat/{m}/{a}` → `tahsilatekrani` (11278).
- Auth: **Laravel Passport (OAuth2)**, çoklu guard (api / isletmeyetkilileri / sistemyoneticileri / satisortakligi).

## Temel tablolar
- Satış: `adisyonlar`, `adisyon_hizmetler`, `adisyon_urunler`, `adisyon_paketler`, `adisyon_paket_seanslar` (APS).
- Tahsilat: `tahsilatlar` (header; kanonik olacak), `tahsilat_hizmetler/urunler/paketler` (parçalama → legacy), `taksitli_tahsilatlar`+`taksit_vadeleri`, `senetler`+`senet_vadeleri`, `alacaklar`.
- Prim: `personel_prim_hareketleri`, `personel_prim_oranlari`, `salon_personelleri`.

## Hedef finansal model
- `adisyonlar` + `adisyon_*` kalemleri = satış gerçeği (değişmez; gerekirse birleşik okuma görünümü).
- `tahsilatlar` = kasaya giren para (kanonik ledger header). Parçalama tablolarına yazma durur (dual-write geçiş süresi hariç).
- Oran-bazlı prim: `prim = kalem_prim_potansiyeli × (adisyon_ödenen / adisyon_toplam)`.

---

## Fazlar

### Faz 0 — Temel altyapı & birlikte yaşama (yazma yok)
- Boost kur; `app/Services`, `app/Domain/Tahsilat`, `app/Http/Resources`, `app/Http/Requests` iskeleti.
- DB bağlantısı: aynı MySQL, `strict=false`, charset/collation eski monolitle birebir (latin5 tuzağı).
- Auth köprüsü: Passport kur, `oauth-public.key` kopyala, aynı `oauth_access_tokens`, çoklu guard eşlemesi → Flutter'ın mevcut tokenı geçerli kalır.
- nginx: `/api/v2/*` → yeni app.
- **Çıktı:** `/api/v2/health` çalışır, token doğrulanır, bir tablo okunur. Canlıya etki yok.

### Faz 1 — Tahsilat'ı salt-okunur aynala (düşük risk)
- `AdisyonOkumaServisi`, `TahsilatGecmisiServisi`; `GET /api/v2/adisyon/{id}`, `GET /api/v2/tahsilat/gecmis/{adisyon}`.
- `PrimHesaplamaServisi` (saf, yazma yok); `GET /api/v2/prim/hakedis`.
- **Shadow doğrulama:** eski klasörde `prim:shadow-dogrula` artisan komutu, yeni vs `primHakedisTopluApi` kuruş farkı loglar. Çıktı: "%100 tutuyor" kanıtı.
- Flutter salt-okunur ekranlar v2'ye.

### Faz 2 — Yeni tahsilat yazma yolu (dual-write)
- Migration (eski klasör): gerekiyorsa `tahsilatlar`'a eksik kolon/indeks; opsiyonel `adisyon_kalemleri` okuma view'ı.
- `TahsilatKaydetServisi`: tek `tahsilatlar` satırı + dual-write `tahsilat_*` (tek yerde reconcile). Idempotent + transaction + kuruş-kesin.
- `POST /api/v2/tahsilat` (web + Flutter aynı servis). Taksit/senet/harici bağlanır.

### Faz 3 — Yazmayı devral (strangle)
- Canlı ekran + Flutter tahsilat-alma v2'ye tam geçer; eski `tahsilatekle` v2'ye proxy veya devre dışı.
- Prim kanonik olarak oran-bazlıya döner (shadow kanıtından sonra).
- `tahsilat_*` parçalama tabloları read-only legacy.

### Faz 4 — Temizlik
- Eski tahsilat kod yolları kaldırılır; parçalama tabloları emekli; `adisyon_kalemleri` (istenirse) fiziksel tabloya terfi; test + dok kapanışı.

## Flutter geçişi
- Ekran ekran: önce salt-okunur (Faz 1), sonra yazma (Faz 3). v1/v2 paralel; base-url aynı, sadece path farkı. Yetki anahtarı değişmez (backend-only).

## Riskler & önlemler
- Latin5/collation uyumsuzluğu → bağlantı ayarı birebir.
- Eski monolitin `tahsilat_*` okuması → dual-write ile korunur.
- Yuvarlama → integer kuruş + oran-bazlı prim (parçalama yok).
- Passport anahtar paylaşımı → shadow ortamda token doğrulama testi.
