# Flutter (işletme paneli) ↔ /api/v2 uyum yol haritası

Kaynak: `/uygulamalar/randevumcepte/lib/yonetici` (297 Dart dosyası) salt-okuma envanteri, 2 Kas 2026.
Amaç: v2 backend'i, Flutter işletme ekranlarının kullandığı **her alanı ve işlemi** karşılayacak şekilde
tamamlamak. **Flutter'a geçiş en sona kadar yapılmaz**; Flutter canlıda v1'i kullanmaya devam eder.

## Kurallar

1. **Flutter'a dokunulmaz.** Ekran kodu geçişte de değişmez; yalnızca HTTP katmanı değişir (aşağıda).
2. **v2 güvenli kalır.** Her uç `auth:isletmeyonetim-api` (Bearer) + `GuvenlikKapisi` (kapsam + yetki).
   v1'in kimliksiz/`user_id`-gövdeli kalıbı v2'ye taşınmaz.
3. **Alan adları Flutter modelleriyle birebir** (`lib/Models/*.fromJson`). Tek fark zarf: v2 cevabı
   `{basarili, veri, mesaj}`; v1'in döndürdüğü gövde `veri` içindedir.
4. **Sayfalama**: `data[]`, `current_page`, `last_page`, `total` (int — Flutter `int`'e atar).
5. **İş mantığı tek yerde**: v2 servisleri. Yan etkiler (SMS/WA/push/oturum) v2'de üretilmez;
   `v2_bildirim_kuyrugu` → eski `v2:bildirim-isle` **mevcut** kodla gönderir (bkz. `BildirimKuyrugu`).

## Geçiş günü Flutter'da değişecek tek katman

Ekranlar HTTP'yi şuradan yapar: `lib/Backend/backend.dart` (serbest fonksiyonlar),
`lib/Frontend/sfdatatable.dart` (DataSource sınıfları), `lib/network_utils/api_client.dart`,
`lib/Backend/yetki.dart`, birkaç ekranda satır içi `http.get/post`. Geçişte bu fonksiyonlar:

| Konu | v1 bugün | v2 |
|---|---|---|
| Kimlik | ~%97 çağrıda **token yok**; kimlik gövdede `user_id` / `olusturan` / `userId` | `Authorization: Bearer <token>` (prefs `token`) |
| Taban | `https://app.randevumcepte.com.tr/api/v1` (bazı yerlerde `//api/v1`) | `/api/v2` |
| Salon parametresi | `sube`, `salon_id`, `salonid`, `salonId`, `Salonid`, `isletme_id`, `id`, path segment | `salon_id` (query/gövde) ya da kaydın kendisinden çözülür |
| Cevap | çıplak gövde / çıplak liste | `govde.veri` |
| Hata | çoğunlukla yalnız `statusCode==200` | `basarili=false` + `mesaj`; 401/403/404/409/422 |

## Domain durumu

✅ v2'de var · 🟡 kısmi · ❌ yok (v1 uç sayıları envanterdendir, yaklaşık)

| Domain | v1 uç | v2 | Not |
|---|---|---|---|
| Personel (CRUD, saat, yetki) | 15 | ✅ | aşağıda detay; yazma `PERSONEL_V2_YAZMA_AKTIF` arkasında |
| Personel prim / hak ediş | 7 | ❌ | Faz 3 (`primHakedisToplu`, `personelPrimHesaplaAyYil`, `primOde*`, `primHareket*`) |
| Randevu / takvim | ~25 | 🟡 | takvim, form, oluştur/güncelle, durumlar, telafi, saat kapama, taşı var; randevu **listesi**, randevu-sonrası not, sesli randevu, grup dersi yok |
| Satış / adisyon / tahsilat / alacak | ~30 | 🟡 | liste, adisyon, kalem, tahsilat, alacak var; taksit, senet, kupon/kampanya kodu, müşteri indirim türü yok |
| Katalog (hizmet/ürün/paket listeleri) | ~8 | 🟡 | `GET /katalog` var; sayfalı yönetim listeleri ve CRUD yok |
| Müşteri | ~15 | ❌ | liste/arama (Bearer'lı tek v1 grubu), ekle/güncelle, detay, kara liste, sağlık, ölçüm |
| Kasa / masraf / gün sonu | ~8 | ❌ | |
| Senet | ~5 | ❌ | |
| Raporlar (satış/personel/müşteri listeleri) | ~8 | ❌ | |
| Seans takibi | ~4 | ❌ | |
| Stok | ~20 | ✅ | `/stok/*` — eşleme aşağıda; yazma `STOK_V2_YAZMA_AKTIF` arkasında |
| Arşiv / form / sözleşme | ~12 | ❌ | |
| Ayar (salon, saatler, cihaz, oda, hizmet, renk) | ~30 | ❌ | |
| Öngörüşme | ~6 | ❌ | |
| Ajanda | 3 | ❌ | |
| Kampanya / anket / çark / reklam / yorum | ~40 | ❌ | |
| SMS / WhatsApp | ~20 | ❌ | |
| E-asistan (Asistanım) | 2 | ✅ | `/asistan/*` — eşleme aşağıda |
| Dashboard / hatırlatma / bildirim | ~13 | ❌ | (dashboard `easistandatadashboard` henüz v1) |
| Hesabım / profil | ~5 | ❌ | (v1'de Bearer'lı) |
| Çağrı merkezi / santral | ~30 | ❌ | (v1'de Bearer'lı `CagriApi`) |

## Personel — v1 → v2 eşlemesi

| v1 (Flutter) | v2 | Durum |
|---|---|---|
| `POST /benimYetkilerim {sube}` | `GET /personel-yetki/benim?salon_id=` | ✅ aynı alanlar (`ayarlar, salon_sahibi, personel_rolunde, sablon`) |
| `POST /personelgetir/{salon}?page {baslik}` | `GET /personel?salon_id=&baslik=&page=` | ✅ + `takvimde_gorunsun` dahil, + `ozet` |
| `GET /personeller/{salon}` (10+ ekranda seçici) | `GET /personel?salon_id=&tumu=1` | ✅ salonun her üyesi; `liste_gor` yoksa telefon/maaş boş |
| `POST /personelekleduzenle` (7 gün `calisiyorN`… alanları) | `POST /personel`, `PATCH /personel/{id}` (`calisma_saatleri[]`, `mola_saatleri[]`) | ✅ HTTP katmanı N-alanlarını diziye çevirir |
| `GET /personelcalismasaatleri/{id}`, `/personelmolasaatleri/{id}` | `GET /personel/{id}/calisma-saatleri`, `/mola-saatleri` | ✅ |
| `POST /personelsifregonder {personelid}` | `POST /personel/{id}/sifre-gonder` | ✅ kuyruk → eski `personelSifreUretVeGonder` |
| `POST /personelaktifyap`, `/personelpasifyap` | `PATCH /personel/{id}/durum {aktif}` | ✅ pasifte oturum sonlandırma kuyruktan |
| `POST /personelTakvimdeGorunsunToggle` | `PATCH /personel/{id}/takvim-gorunur` | ✅ `takvimde_gorunsun` sayısal |
| `POST /personelSiralamaKaydir {delta}` | `PATCH /personel/{id}/siralama {delta}` | ✅ |
| `POST /personelGelecekHizmetler` | `GET /personel/{id}/gelecek-hizmetler` | ✅ |
| `POST /personelArsivle {transferler}` | `DELETE /personel/{id} {transferler}` | ✅ + oturum sonlandırma |
| `GET /personelYetkiSema` | `GET /personel-yetki/sema` | ✅ |
| `POST /personelYetkiGetir` | `GET /personel/{id}/yetki` | ✅ |
| `POST /personelYetkiKaydet` | `PATCH /personel/{id}/yetki` | ✅ force_logout kuyruktan |
| `POST /personelPrimKalemleri` | `GET /personel/{id}/prim-kalemleri` | ✅ |
| (sabit hesap türü listesi 1,2,3,4,5,7,8) | `GET /personel/form-verisi` | ✅ `roles` tablosundan |
| `POST /satislar?page {personel_id…}` (personelsatislari) | `GET /satislar?personel_id=` | ✅ |
| `POST /primHakedisToplu/{salon}` | — | ❌ Faz 3 (her satır `Personel.fromJson` alanlarını da taşımalı, id = `id` veya `personel_id`) |
| `POST /personelPrimHesaplaAyYil` | — | ❌ Faz 3 (`*_toplam(_numeric)`, `*_hakedis(_numeric)`, `adisyonlar[]`) |
| `POST /primOde`, `/primOdemeListesi`, `/primOdemeSil`, `/primHareketEkle`, `/primHareketSil` | — | ❌ Faz 3 (`odemeler[].id`, `hareketler[].id` JSON sayı) |

## E-asistan (Asistanım) — v1 → v2 eşlemesi

| v1 (Flutter) | v2 | Durum |
|---|---|---|
| `GET /easistandata/{ms_timestamp}/{salon}?page` (`EAsistan.fromJson`) | `GET /asistan/gorevler?salon_id=&tarih=Y-m-d&page=&per_page=` | ✅ aynı alanlar (`baslik, mesaj, sonuc, durum, saat, tur, iptal_edilebilir, iptal_edildi, randevu_id/alacak_id/kampanya_id`) + `id, durum_kod, durum_etiket, musteri_ad`; zarf `veri.{data,current_page,last_page,total}` |
| `POST /gorev-iptal-et {randevu_id\|alacak_id\|kampanya_id}` | `POST /asistan/gorev/{tur}/{id}/iptal` | ✅ + kapsam + sunucuda iptal-edilebilirlik kontrolü (422) |
| (Flutter'da ayarlar sekmesi yok) | `GET /asistan/ayarlar`, `PATCH /asistan/ayarlar {ayarlar:{anahtar:bool}, tekrar_arama_saat}` | ✅ ayar listesi API'den (sabit liste yok) |

Notlar: v1 `baslik` müşteri adıydı (avatar baş harfleri) → v2'de `musteri_ad`; `baslik` görev türü başlığıdır. v1 kampanyayı D+1'de arıyordu, v2 web kanoniği D (asistan_tarih_saat günü). v1 `durum` için `'Ulaşıldı<'` (fazla karakter) dönüyordu; v2 temiz.

## Stok — v1 → v2 eşlemesi

v1 `services/stok_api.dart` → `https://…/api/stok/*` (form-urlencoded, Bearer yok, salon path'te).
v2: `GET` uçlarında `salon_id` query'de, `POST` gövdesinde; kayıt id'li uçlarda salon kayıttan çözülür.
Alan adları Flutter modelleriyle (`Urun`, `Depo`, `Tedarikci`, `UrunKategorisi`, `StokHareketi`)
AYNI; sayılar ham `int/float`, boş id'ler `null` (modeller `toString()` ile okuduğundan uyumlu).
Ek alanlar: `stok_seviyesi` (`tukendi|kritik|dusuk|normal`) + `stok_renk`, `tip_etiket`,
`birim_etiket`, hareketlerde `hareket_etiket/hareket_renk/urun_adi/depo_adi` — istemci yeniden hesaplamaz.

| v1 (Flutter) | v2 | Not |
|---|---|---|
| `GET /ozet/{salon}` | `GET /stok/ozet?salon_id=` | `dusuk_stok` artık kritik sınırı da sayar (rozetle tutarlı) |
| `GET /dusuk-stok/{salon}` | `GET /stok/dusuk-stok?salon_id=` | seviye kritik/düşük olanlar |
| `POST /urunler/{salon} {arama,kategori_id,tip}` | `GET /stok/urunler?salon_id=&ara=&kategori_id=&tip=&seviye=&tumu=1` | `tumu=1` → sayfasız; yoksa `{data, current_page, last_page, per_page, total}`; `depo_id=` → `depo_stok` |
| `GET /urun/{id}` | `GET /stok/urunler/{id}` | + `depo_stoklari[]` |
| `POST /urun-barkod/{salon} {barkod}` | `GET /stok/urunler/barkod?salon_id=&barkod=` | bulunamazsa 404 (eski `status:bulunamadi`) |
| `POST /urun-kaydet/{salon}` (id=0 yeni) | `POST /stok/urunler` · `PATCH /stok/urunler/{id}` | PATCH kısmi; aynı salonda tekrarlı barkod 422 |
| `POST /urun-sil {urun_id}` | `DELETE /stok/urunler/{id}` | pasif |
| `GET /urun-satis-raporu/{urun}` | `GET /stok/urunler/{id}/satis-raporu?baslangic=&bitis=` | |
| `GET /kategoriler/{salon}` · `POST /kategori-kaydet` · `POST /kategori-sil {id}` | `GET/POST /stok/kategoriler` · `PATCH/DELETE /stok/kategoriler/{id}` | depolar / tedarikciler / receteler AYNI kalıp |
| `GET /depolar/{salon}` … `POST /depo-sil {id}` | `/stok/depolar[/{id}]` | varsayılan / stoklu depo silinemez (422) |
| `GET /tedarikciler/{salon}` … | `/stok/tedarikciler[/{id}]` | yazma `urun.tedarikci_yonet` |
| `GET /receteler/{salon}?hizmet_id=&hizmet_tipi=` … | `/stok/receteler[/{id}]` | ürün + hizmet salona ait olmalı |
| `POST /hareketler/{salon} {urun_id,…,limit}` | `GET /stok/hareketler?salon_id=&urun_id=&depo_id=&hareket_tipi=&baslangic=&bitis=&page=&per_page=` | sayfalı (`limit` yok) |
| `POST /manuel-hareket/{salon}` | `POST /stok/hareketler` | tip: `fire|manuel|iade|sarf` |
| `POST /alis-girisi/{salon} {kalemler: JSON}` | `POST /stok/alis {kalemler: []}` | tek transaction; JSON-metin `kalemler` de kabul |
| `POST /transfer/{salon}` | `POST /stok/transfer` | kaynak depoda yetersiz stok 422 |
| `POST /sayim-uygula/{salon}` | `POST /stok/sayim {depo_id, kalemler}` | tek transaction |
| `POST /hizli-satis/{salon} {sepet: JSON}` | `POST /stok/hizli-satis` | **`idempotency_key` zorunlu**; `miktar` tam sayı; `birim_fiyat` yok sayılır (katalog fiyatı); peşinde `satis.tahsilat_al` + `TAHSILAT_V2_YAZMA_AKTIF` |
| — | `GET /stok/form-verisi?salon_id=` | kategoriler, depolar, tedarikçiler, birimler, tipler, hareket tipleri, ödeme yöntemleri, `yetkiler`, yazma bayrakları |
| — | `GET /stok/musteri-ara?salon_id=&q=` | hızlı satış müşteri seçici (`urun.sat`) |

v1'de düzeltilen açıklar: id'li uçlar (`urun-sil`, `kategori-sil`, `depo-sil`…) salon denetimi yapmıyordu
(başka işletmenin kaydı değiştirilebiliyordu); `_yetkiVar` kimliksiz isteği yetkili sayıyordu; depo-kaydet
ve transfer/manuel hareket mobilde yetkisizdi; stok önbelleği eşzamanlı yazımda kayıp veriyordu; sayım
seçilen deponun değil ürünün toplam stoğuyla fark hesaplıyordu (web).

## Bilinen v1 tuhaflıkları (geçişte HTTP katmanında karşılanacak / v2'de düzeltildi)

- `personelekleduzenle` mola anahtarlarını (`mola1..7`) `calisiyor1..7`'den kopyalıyor → mola açık/kapalı fiilen yok sayılıyor. v2 doğru alanları kullanır.
- `personeller.dart` sayfalı listede `takvimde_gorunsun` olmadığı için ikinci bir `/personeller` çağrısıyla birleştiriyor. v2 tek liste her alanı döner.
- `/hizmet-musteri-listes` (sonda "i" eksik), `musterilistegetirSayfali`'nin `&seciliMusteri?{id}` bozuk sorgusu, `paketMusteriListesiGetir`'de `personelId = paketId` hatası.
- `/tahsilat-*-sil` `basarili: "1"` (string) bekliyor; `tahsilatekle` müşteriyi `ad_soyad` anahtarıyla, `taksitekleguncelle` `tahsilat_musteri_id` ile gönderiyor; `SenetVade/TaksitVade.toJson` `dogurlama_kodu` yazım hatası.
- `/randevuIcinGerekliVeriler` her liste anahtarını (`musteriler, paketler, urunler, hizmetler, odalar, cihazlar, sehirler, formlar, personeller`) **zorunlu** döndürmeli — Flutter hepsini `List` olarak cast eder.
- Avatar / ek dosya yolları **göreli** dönmeli (`resources[].avatar`, `img_src`, `arsiv.uzanti`); Flutter başına `https://app.randevumcepte.com.tr/` ekliyor.
- Para alanları hem TR biçimli metin hem `_numeric` olarak okunuyor (`_parseTrNumber`).
- Sabit salon id'li ölü kod (114, 182): `ajandaduzenle`, `okunmus`, `okunmamıs`, `bildirimler_services`, `hesapkaldirmaisletme` — geçişte kapsam dışı.

## Sıradaki adımlar (öneri)

1. Personel Faz 3: prim / hak ediş / ödeme (Flutter `prim_hakedis`, `hakedislerim`, `personeldetay`).
2. Randevu boşlukları: randevu listesi (`/randevular?page`), randevu-sonrası not, grup dersi.
3. Satış boşlukları: taksit, senet, kupon/kampanya kodu, müşteri indirim türü.
4. Müşteri → Kasa/Rapor → Ayar → Pazarlama/SMS/WhatsApp → Dashboard → Çağrı merkezi.
5. En son: Flutter HTTP katmanı geçişi (kullanıcı "geçiş" dediğinde).
