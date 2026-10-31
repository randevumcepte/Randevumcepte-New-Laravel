# Strangler Geçiş Yol Haritası — Randevu Domain'i

> Durum: Faz 1 başladı (salt okuma). Tarih: 2026-10-29.
> Hedef: Eski monolit `randevumcepte-yeni` (Laravel 5.6/PHP 7.4) randevu
> işlevlerini, aynı MySQL'i paylaşarak, strangler pattern ile peyderpey
> `Randevumcepte-New-Laravel` (Laravel 13/PHP 8.3) içine taşımak. İkinci domain:
> **randevu** (tahsilat domaininden sonra, aynı desenle).

## Kilitli kararlar
- **İstemci stratejisi:** **Gölge önce** (tahsilat ile aynı). Flutter işletme
  randevu ekranları CANLI kalır, `/api/v1` monolite konuşmaya devam eder, app
  release GEREKMEZ. v2 domaini önce salt-okuma gölge olarak doğrulanır; yazma
  ve kesme sonraki fazlarda.
- **İlk kapsam:** **Salt okuma** — takvim yükü + tek randevu detayı.
- **Yönlendirme:** `/api/v2/*` nginx'te yeni app'e; geri kalan her şey (tüm v1
  randevu uçları dahil) eski monolite.

## Mimari ilkeler (tüm fazlarda)
1. **Migration yalnızca eski klasörden — İSTİSNASIZ.** Yeni app `migrate`
   ÇALIŞTIRMAZ; tablolara yalnızca `protected $table` + `$guarded=[]` ile
   map'lenir (`Randevular`, `RandevuHizmetler`, `AdisyonPaketSeanslar`).
2. **API-first.** Her işlev `/api/v2/...`; web (Vue) ve Flutter aynı servisi
   tüketir. İş mantığı **Service** katmanında, controller ince
   (kimlik + `GuvenlikKapisi` kapsam/yetki + servis + `ApiCevap` zarfı).
3. **KVKK veri minimizasyonu.** Ham model dökümü YOK; telefon `musteri.telefon_gor`
   yoksa maskeli. `notes` serbest-metin bloku v2 okumada DÖNDÜRÜLMEZ.
4. **Additive + geri-dönüşlü.** Eski monolit canlıyken hiçbir tablo/kolon bozulmaz.

## Yetki anahtarları (zaten tanımlı — `YetkiSabitleri`)
`randevu.takvim_gor`, `randevu.tum_personel_gor`, `randevu.olustur`,
`randevu.onayla`, `randevu.duzenle_iptal`, `randevu.kapanis_blok_ekle`,
`randevu.online_ayar`. **Yeni anahtar EKLENMEZ** (eklenirse eski monolitte de
tanım+şablon+`mobilGizliAnahtarlar`a eklenmeli; yoksa Flutter sahte "zorla çıkış").

## Temel tablolar (paylaşılan DB)
- `randevular` (başlık; durum 0 bekliyor / 1 onaylı / 2 iptal / 3 reddedildi;
  takvim durum<2). Kapalı-saat sentineli `user_id=2012`.
- `randevu_hizmetler` (kalemler; kaynak bağı personel/oda/cihaz + saat/saat_bitis).
  Kapalı-saat sentineli `hizmet_id=463`.
- `adisyon_paket_seanslar` (APS; seans düşümü + telafi slotu `geldi=2`).
- İlişkili: `adisyon_hizmetler`, `on_gorusmeler`, `salon_personelleri`, `users`.

## Eski monolit referansları
- Takvim: `ApiController@randevular` (`randevumcepte-yeni` ApiController.php:1927),
  `/randevular/{salon}/0` (POST). Çıktı Syncfusion-şekilli; yük-taşıyan
  `durum` string'i: `durum-geldi-satisOlustu-odemeYapildi-rhId-telafiVar-telafiKaynak-telafiVerildi`.
- Detay: `randevudetay` (12213). Oluştur/güncelle: `randevuekleguncelle` (12371).
- Durum: `randevugeldiisaretle` (16060), `randevuyagelmedi` (16413),
  `randevuiptalet` (16480), `randevuonayla`, `randevuTelafiIsaretle` (16327).
- Sürükle-bırak: `surukleBirakRandevuGuncelle` (30521). Saat kapama: `saatkapamaekle` (9151).
- Yetki (opsiyonel, body-tabanlı): `_yetkiVar`/`_patronAsistanSalonId`.

---

## Fazlar

### Faz 0 — Altyapı (tahsilat ile ortak, TAMAM)
Health, auth köprüsü (legacy-passport guard), `GuvenlikKapisi`/`KapsamServisi`/
`YetkiServisi`, `ApiCevap`, tipli exception'lar. Randevu için ek iş yok.

### Faz 1 — Salt okuma (BU FAZ)
- Modeller: `Randevular`, `RandevuHizmetler`, `AdisyonPaketSeanslar` (map'lenmiş).
- Servis: `App\Services\Randevu\RandevuOkumaServisi`
  - `takvim(salonId, tarih1, tarih2, personelId?, takvimTuru?, callerYetkiliId?)` →
    `{randevular, resources, baslangic, bitis}`. Yapısal çekirdek: satır kümesi
    (durum<2 + tarih + salon), rol-5 kapsam + `tum_personel_gor` bypass, her
    satır start/end + resourceId (takvim türüne göre) + packed `durum` string'i +
    paket-kaynağı/resource/personel MERGE.
  - `detay(randevuId, telGor)` → tek randevu (telefon maskeli).
- Controller: `Api\V2\RandevuController@takvim` + `@goster`.
  Uçlar: `GET /api/v2/randevu/takvim`, `GET /api/v2/randevu/{id}`
  (`auth:isletmeyonetim-api` + `GuvenlikKapisi(randevu.takvim_gor|olustur|duzenle_iptal)`).
- Gölge doğrulama: `php artisan randevu:shadow-takvim {salon} [--tarih1 --tarih2 --personel --takvim-turu]`
  — v2 pre-merge satır kümesi + start/end + resourceId'yi eski `randevular()`
  WHERE/zaman/kaynak mantığının BAĞIMSIZ ham-SQL replikasıyla kıyaslar.

**Faz 1'de BİLİNÇLİ ERTELENEN (kozmetik, cutover'da):** salon renk-sırası/
tek-renk ince ayarı, "(PAKET)/(KARMA)" etiketleri, `notes` serbest-metin bloku
(KVKK), resource sayaç rozetleri, kategori/cihaz/oda modu `resources` listesi,
`start/end` string formatının Flutter ile birebir karşılaştırması.

### Faz 1 sonucu
`randevu:shadow-takvim` salon 20: tüm kişiler (rol 0/1) ve rol-5 (personel=34)
senaryolarında **PARİTE TAM** (satır kümesi + start/end + resourceId).

### Faz 2a — Durum yazma çekirdeği (YAPILDI, kill-switch KAPALI)
- Saf kural: `App\Domain\Randevu\SeansDurumGecisi` (+ `tests/Unit/SeansDurumGecisiTest`).
- Servis: `RandevuDurumServisi` — geldi / gelmedi / isaretKaldir / iptal / onayla.
  Her biri DRY-RUN `plan` döner; yazma tek transaction + `AktiviteLog`
  (eski `salon_aktivite_log`, meta.kaynak=v2).
- `DakikaPaketServisi` — dakika paketi düşüm/iade (aynı tablolar/hareket türleri).
- Uçlar (`RandevuDurumController`, varsayılan `dry_run=true`):
  `POST /randevu/{id}/geldi|gelmedi|iptal|onayla`, `DELETE /randevu/{id}/geldi-isareti`.
  Yetki: `randevu.duzenle_iptal` (onayla: `randevu.onayla`).
- `config/randevu.php` → `RANDEVU_V2_YAZMA_AKTIF` (varsayılan false → 409).
- Doğrulama: `php artisan randevu:durum-plan {randevu} {geldi|gelmedi|kaldir|iptal|onayla}`.
- Yük-taşıyan kodlar korunur: `hatali` 0/2/3/4 (+ `seanslar` listesi), `seansDusmeOnayi`.

**Eski davranıştan bilinçli farklar (v2 düzeltmeleri):**
1. Telafi slotu (APS `geldi=2`) gelmedi / işaret-kaldır / iptal'de korunur
   (eski: kaldır null'luyor, iptal siliyordu → bekleyen telafi hakkı kayboluyordu).
2. Dakika paketi NET hesap (kullanım − iade): eski kodda geldi→gelmedi→geldi
   zincirinde ikinci geldi hiç düşmüyordu.
3. `gelmedi` eski uçta yetki kontrolü yoktu; v2'de `randevu.duzenle_iptal`.
4. iptal yalnızca aktif (durum<2), onayla yalnızca bekleyen (durum=0) randevuda (409).
5. Yetim seans temizliği yalnızca gerçek yazmada (eski: kod kapısında dönse bile siliyordu).

### Faz 2b — Bildirim köprüsü (YAPILDI — outbox)
Karar: bildirim altyapısı TAŞINMADI; paylaşılan DB'de **outbox** + eski monolitte
işleyici. Böylece bildirim kodu TEK yerde kalır (kod tekrarı yok).
- Tablo: `v2_bildirim_kuyrugu` (migration ESKİ klasörde:
  `2026_10_31_120000_create_v2_bildirim_kuyrugu`).
- v2 (`RandevuDurumServisi::kuyruga`) yan etkiyi DURUM DEĞİŞİKLİĞİYLE AYNI
  transaction'da yazar → durum yazıldıysa bildirim kaybolmaz, yazılmadıysa
  hayalet bildirim gitmez. Türler: `randevu_iptal` (payload.red), `randevu_onay`,
  `dogrulama_kodu`, `kvkk_kodu`, `seans_kullanim`.
- Eski monolit: `php artisan v2:bildirim-isle` (Kernel everyMinute +
  withoutOverlapping; ~55 sn 2 sn aralıkla yoklar). Atomik talep (durum 0→3),
  3 deneme sonra kalıcı hata (2), 5 dk takılı kalan geri alınır.
- Monolitte TAŞIMA refactor'ü (mantık aynı): `randevuiptalet`/`randevuonayla`
  bildirim blokları → `randevuIptalBildirimleri` / `randevuOnayBildirimleri`,
  KVKK SMS'i → `kvkkOnayKoduGonder`. v1 uçları ve işleyici AYNI metodları çağırır.
  Tek fark: `$hemen=true` (CLI) WA/SMS'i shutdown'a atmadan hemen gönderir.

### Faz 2c — Telafi (YAPILDI, aynı kill-switch)
- **Telafi işaretle:** `POST /randevu/{id}/telafi` — tüm seanslar `geldi=2`
  (telafi slotu), `randevuya_geldi=0`, kalemler `seansa_geldi=0`. Yalnızca
  paket/seans randevusunda (yoksa 422). Eski `randevuTelafiIsaretle` ile aynı.
- **Telafi randevusu (A tipi):** `GET /randevu/telafi-secenekler`,
  `POST /randevu/kalem/{rh}/telafi-randevusu` (yetki `randevu.olustur`).
  Servis `TelafiRandevuServisi` (v2), para kuralı saf `Domain\Randevu\TelafiPrim`
  (+ `tests/Unit/TelafiPrimTest`). Eski `App\Services\TelafiRandevuServisi` ile
  aynı kayıt seçimi (satış kalemi → fallback A/B; paket seansı) ve geri devir kuralı.
  Farklar: orijinal kalem kilitli + bayrak yeniden denetimi (çift telafi yok),
  personel/oda/cihaz salon doğrulaması, DRY-RUN prim planı, aktivite logu.
  Geçiş dönemi: eski web/v1 kendi kopyasını kullanır; Faz 4'te tek kaynak v2.
- Doğrulama: `php artisan randevu:telafi-plan {rh} {Y-m-d} {H:i} --personel=`,
  `php artisan randevu:durum-plan {randevu} telafi`.

### Kill-switch açma kontrol listesi
1. Eski klasörde migration uygulandı mı (`v2_bildirim_kuyrugu` var mı)?
2. `php artisan v2:bildirim-isle --tek` hatasız mı?
3. Test salonunda `randevu:durum-plan` planları eski davranışla tutarlı mı?
4. New-Laravel `.env` → `RANDEVU_V2_YAZMA_AKTIF=true`; test salonunda
   iptal/onay/geldi(kod) akışlarında SMS/push geldiğini doğrula.

### Faz 3 — Oluştur/güncelle + çakışma + telafi + saat kapama
`randevuekleguncelle` çekirdeği: güncelle = `randevu_hizmetler` sil+yeniden-yaz,
APS havuz uzlaştırma, çakışma (`kaynak_cakisma_kontrol`), telafi (A-tipi),
saat kapama sentinelleri. Dönüş: `cakismavar` + `cakisanunsurlar`.

### Faz 4 — Kesme (cutover)
v1 randevu path'lerini nginx'te yeni app'e yönlendir (sözleşme-koruyan adaptör)
VEYA Flutter'ı v2'ye taşı. Eski `randevular()` ve kardeşlerini emekliye ayır.
