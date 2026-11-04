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
| Randevu / takvim | ~25 | 🟡 | takvim, form, oluştur/güncelle, durumlar, telafi, saat kapama, taşı, randevu **listesi** (`GET /randevu/liste` + `/liste/secenekler`: zaman/kaynak/durum/hizmet filtresi, satır `aksiyonlar`) var; randevu-sonrası not, sesli randevu, grup dersi yok |
| Satış / adisyon / tahsilat / alacak | ~30 | 🟡 | liste, adisyon, kalem, tahsilat, alacak var; taksit, senet, kupon/kampanya kodu, müşteri indirim türü yok |
| Katalog (hizmet/ürün/paket listeleri) | ~8 | 🟡 | `GET /katalog` var; sayfalı yönetim listeleri ve CRUD yok |
| Müşteri | ~15 | 🟡 | liste+sayaç+seçici, detay, randevu notları, randevular, ekle/güncelle/sil, kara liste, sağlık, ölçüm var (yazma `MUSTERI_V2_YAZMA_AKTIF`); resimler, seanslar, toplu içe aktarma, anket/WhatsApp, harici tahsilat yok |
| Kasa / masraf / gün sonu | ~8 | ✅ | `/kasa/*`, `/masraf/*` — eşleme aşağıda; yazma `KASA_V2_YAZMA_AKTIF` arkasında |
| Senet | ~5 | ❌ | |
| Raporlar (satış/personel/müşteri listeleri) | ~8 | ✅ | `/rapor/*` (Satış Raporları + İşletme Raporları) — eşleme aşağıda |
| Seans takibi | ~4 | 🟡 | liste + kartlar + seans ekle/durum + lazer cihaz bilgisi var (yazma `SEANS_V2_YAZMA_AKTIF`); dökümü PDF eski panelde, müşteri paneli (`musteriMi`) v1'de |
| Stok | ~20 | ✅ | `/stok/*` — eşleme aşağıda; yazma `STOK_V2_YAZMA_AKTIF` arkasında |
| Arşiv / form / sözleşme | ~12 | ❌ | |
| Ayar (salon, saatler, cihaz, oda, hizmet, renk) | ~30 | 🟡 | `/ayar/*` Faz 1: işletme bilgileri + logo/galeri, çalışma/mola saatleri, randevu ayarları + takvim rengi, online randevu saatleri, müşteri indirimleri, tema; Faz 2: cihazlar + odalar; Faz 3: hizmetler + şubeler (salt okuma) — eşleme aşağıda; yazma `AYAR_V2_YAZMA_AKTIF`. Google entegrasyonu (en son), yeni şube açma yok |
| Öngörüşme | ~6 | ✅ | `/ongorusme/*` — liste+sayaç, detay, form verisi, ekle/düzenle (çakışma), satış yapıldı/yapılmadı; v1 alan adları kabul; eşleme docs/strangler-ongorusme.md; yazma `ONGORUSME_V2_YAZMA_AKTIF` |
| Ajanda | 3 | ❌ | |
| Kampanya / anket / çark / reklam / yorum | ~40 | ❌ | |
| Bildirim reklamları (yönetici + müşteri uygulaması) | 12 | ✅ | `/bildirim-reklam/*` (liste+sayaç, form verisi, kaydet, durum, sil, görsel base64/multipart, alıcı sayısı, push gönder → outbox) + `/musteri-uygulama/reklamlar*` (`auth:api`); v1 alan adları aynı; eşleme docs/strangler-bildirim-reklam.md; yazma `BILDIRIM_REKLAM_V2_YAZMA_AKTIF` |
| SMS | 13 | ✅ | `docs/strangler-sms-yonetimi.md` (`/api/v2/sms/*`) |
| WhatsApp | ~7 | ❌ | |
| E-asistan (Asistanım) | 2 | ✅ | `/asistan/*` — eşleme aşağıda |
| Dashboard / hatırlatma / bildirim | ~13 | ◐ | Özet `GET /ozet/acilis`, `/ozet/kart/{kod}`, `/ozet/sekme/{kod}` ✅ (v1 `dashboard` + `dashboardKarsilastirma`; eşleme docs/strangler-ozet.md) — bildirim, hatırlatma, `easistandatadashboard`, boş saat kampanyası henüz v1 |
| Hesabım / profil | ~5 | ❌ | (v1'de Bearer'lı) |
| Çağrı merkezi / santral | ~30 | ✅ | `/cagri-merkezi/*` (çalışma ekranı, arama randevuları, çağrı kayıtları, performans, liste yönetimi — tek sekmeli `/v2/cagri-merkezi`) + `/santral/*`; eşleme docs/strangler-cagri-merkezi.md; yazma `CAGRI_MERKEZI_V2_YAZMA_AKTIF`. liste oluşturma (filtre motoru), script/kategori tanımı, WhatsApp manuel, ajanda dahil |

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

## Müşteri — v1 → v2 eşlemesi

Tüm uçlar `salon_id` ister; müşteri o salonun aktif portföyünde ve kullanıcının portföy kapsamında
(`musteri.tum_portfoy_gor` yoksa yalnız kendi müşterileri) değilse 404. DTO = `MusteriDanisan.fromJson` üst kümesi
(`id, name, cep_telefon, email, dogum_tarihi, cinsiyet, tc_kimlik_no, musteri_tipi, ozel_notlar, kayit_tarihi,
randevu_sayisi, son_randevu_tarihi, odeme_sayisi, profil_resim` + `segment{kod,etiket}`, `kara_liste`, `toplam_odenen`).

| v1 (Flutter) | v2 | Durum |
|---|---|---|
| `POST /musterilistegetir/{salon}?page {arama, durum}` | `GET /musteri?salon_id=&durum=0..4&ara=&page=` | ✅ `durum` aynı kodlar (+4 yeni eklenen); cevapta `sayilar` |
| `GET /musteri_sayilari_getir/{salon}` | `GET /musteri/sayilar?salon_id=` (liste cevabında da `sayilar`) | ✅ aynı anahtarlar (int) + `yeni_musteriler` |
| `GET /musteriler/{salon}?search&limit&offset` (lazy seçici) | `GET /musteri?ara=&per_page=&page=` veya `GET /musteri/ara?q=&limit=` | ✅ HTTP katmanı `offset/limit` → `page/per_page` |
| `GET /musteriler/{salon}` (tam liste, 8+ ekran) | `GET /musteri/ara` (sunucu arama) | 🟡 tam liste dökümü bilinçli olarak yok (sayfalı/aramalı) |
| `GET /kullaniciBilgiGetir/{id}`, `POST /musteritahsilat?userId=`, `GET /musteri-detay/{id}` | `GET /musteri/{id}?salon_id=` | ✅ tek uç; portföy alanları salona göre, `profil_resim` göreli |
| `GET /musteri-randevulari/{id}` | `GET /musteri/{id}/randevu-notlari` | ✅ `{id, tarih, hizmetler[{hizmet_adi}], personel_notu, randevu_sonrasi_not}` sayfalı |
| `POST /randevular?page {musteri_id…}` (müşteri sekmesi) | `GET /musteri/{id}/randevular` | ✅ `durum{kod,etiket,renk}`, `telafi`, `hizmetler[]`, `kaynaklar[]` |
| `POST /satislar?page {musteriid…}` | `GET /satislar?musteri_id=` | ✅ (mevcut uç; `musteri.gecmis_satis_gor` istisnası) |
| `POST /musteriekleguncelle/{salon}` (ekle) | `POST /musteri` | ✅ aynı alan adları; mükerrer telefon → 409 (v1 `status:'warning'`) |
| `POST /musteriekleguncelle/{salon}` (`musteri_id` ile) | `PATCH /musteri/{id}` | ✅ maskeli telefon gelirse numara korunur |
| `POST /musterisil {salonid, portfoy_id}` | `DELETE /musteri/{id}?salon_id=` | ✅ müşteri (user) id ile; soft delete |
| `POST /musteri-karaliste-durum`, `/musteri-karaliste-ayari` | detayda `kara_liste`; `PATCH /musteri/{id}/kara-liste {kara_liste}` | ✅ bildirim outbox `kara_liste_bildirim` |
| `POST /saglikbilgilerigir` | `PATCH /musteri/{id}/saglik` | ✅ + iki açıklama alanı (v1 düşürüyordu); sorular `GET /musteri/form-verisi` |
| `POST /musteri-olcum-liste / -ekle / -sil` | `GET/POST /musteri/{id}/olcumler`, `DELETE /musteri/olcum/{id}` | ✅ VKİ sunucuda; `vki_sinif` dahil |
| `POST /musteri-danisan-turunu-getir` (ham "1"/"2") | detayda `segment{kod,etiket}` | ✅ |
| `POST /musteriresimleri`, `/musteriresimekle…`, `/check_phone`, `/anket-hizli-gonder`, WhatsApp uçları | — | ❌ sonraki faz |

## Seans takibi — v1 → v2 eşlemesi

v1 `Backend/backend.dart` (`seanslarigetir`, `seansEkleApi`, `seansGuncelleApi`, `seansCihazVeriGetir/Kaydet`)
→ `https://app…/api/v1/*` (kimliksiz JSON). v2: Bearer + `paket.seans_takip`; seans/kalem id'li uçlarda
salon kayıttan çözülür (başka salonun kaydı 403/404). Ekran: `yonetici/diger/menu/seanstakibi/seanstakibiyeni.dart`.

| v1 (Flutter) | v2 | Not |
|---|---|---|
| `POST /seanslar?page=` {salonId, arama, musteri_id} | `GET /seans?salon_id=&musteri_id=&ara=&page=&per_page=&sirala=tarih\|musteri\|ad\|baslangic&yon=` | ListeIstegi; arama ad/telefon/paket-hizmet adı |
| ↳ satır `id, adisyon, musteri{users satırı}, paket "(P)/(H)", tip, paketHizmetId` | `anahtar ("paket_12"), tur, id, adisyon_id, musteri{id, ad}, ad` | **KVKK:** müşteri yalnız `{id, ad}`; "(P)/(H)" eki yerine `tur` |
| ↳ `toplamSeansSayisi, bekleyen/gelinen/gelinmeyenSeansSayisi` | `sayac{toplam, bekleyen, kullanilan, kullanilmayan, telafi}` | web kuralı: paket toplamı = seans_sayisi × hizmet sayısı; telafi ayrı sayılır; v1 `bekleyen` eksi çıkıyordu |
| ↳ `seanslar[]` (düz, istemci hizmete göre grupluyordu) + `hizmetler[]` | `hizmetler[]{hizmet_id, ad, lazer, sayac, bos_hak, seanslar[]{id, seans_no, tarih, saat, geldi, durum}}` | gruplama + boş daire sayısı sunucuda (`_HizmetGroup` gereksiz) |
| istemci "lazer" tespiti + PDF parametreleri | `lazer`, `dokum_url` (yalnız cihaz verisi varsa dolu) | — |
| — | `durumlar[]{kod, geldi, etiket}`, `ayarlar.grup_dersi_aktif` | telafi sorusu ("derse katıldı mı / randevuya geldi mi") |
| `POST /seansEkle` {paketId, hizmetId, paket 0/1, geldi, seansTarihi} | `POST /seans` {tur: paket\|hizmet, kalem_id, hizmet_id, geldi 0\|1\|2, tarih Y-m-d} | hizmet satışta olmalı + hak dolmamış olmalı (422); cevapta güncel `satir` |
| `POST /seansGuncelle` {seansId, geldi} | `PATCH /seans/{id}` {geldi: 0\|1\|2\|null} | `null` = Beklemede: randevuya bağlıysa işaret kaldırılır, değilse satır silinir; cevapta `silindi` + `satir` |
| `POST /seansCihazVeriGetir` {seansId} | `GET /seans/{id}/cihaz` | `personeller[]{id, ad}` (v1 `personel_adi`); lazer değilse 422 |
| `POST /seansCihazVeriKaydet` {seansId, bolgeler(JSON metin)} | `PUT /seans/{id}/cihaz` {bolgeler: [{uygulama_bolgesi, enerji, hiz, ms, atis_sayisi, personel_id, notlar}]} | gövde JSON dizi; personel salonda olmalı |
| `GET /seansDokumuPdf` | satırdaki `dokum_url` | PDF üretimi eski panelde (v2'de PDF motoru yok) |

Kapsam dışı: müşteri uygulaması (`musteriMi=true`, users guard) v1'de kalır.

## Raporlar + Kasa — v1 → v2 eşlemesi

Ayrıntılı sözleşme ve formül kararları: `docs/strangler-rapor-kasa.md`. Dönem tüm uçlarda
`donem=bugun|dun|hafta|ay|gecen_ay|yil|gecen_yil|ozel` (+ `baslangic`/`bitis` Y-m-d); seçenek
listesi, sekmeler ve buton yetkileri `GET /rapor/secenekler?ekran=satis|isletme|kasa`'dan.

| v1 (Flutter) | v2 | Durum |
|---|---|---|
| `POST /hizmetRaporlari`, `/urunRaporlari`, `/paketRaporlari` {salonId, tarih1, tarih2, personel} | `GET /rapor/satis?salon_id=&sekme=hizmet\|urun\|paket\|tum&donem=ozel&baslangic=&bitis=&personel_id=` | ✅ satır `ad, adet, gelir, indirim, kazanc, alacak` (+ hizmette `birim_maliyet, toplam_maliyet, kar`), `veri.ozet` kartlar; seanslı hizmet `tur:'seans'` olarak paket sekmesinde (v1 ile aynı yer) |
| `POST /personelRaporlari` | `GET /rapor/satis/personel` | ✅ aynı anahtarlar (`personel_adi, hizmet_geliri, hizmet_primi, …`) + `toplam_gelir/prim`; prim Prim & Hak Ediş formülü (v1 yalnız düz %) |
| `POST /hizmet-musteri-listes`, `/urun-musteri-listesi`, `/paket-musteri-listesi` | `GET /rapor/satis/{hizmet\|urun\|paket\|seans}/{kalem}/musteriler` | ✅ satır başına `musteri, telefon (maskeli), personel, gelir, odenen, kalan`; personel filtresi KALEM personeli (v1 adisyon oluşturanı) |
| (rol 5 `SalesReportsPersonelPage`, personel id istemcide) | aynı uçlar | ✅ personel kapsamı SUNUCUDA zorlanır (`secenekler.personel_kapsami`) |
| (yok — web İşletme Raporları) | `GET /rapor/isletme/{ozet,hizmet,urun,personel,musteri,randevu}` | ✅ yeni ekran yapılabilir |
| `POST /kasaraporu/{salon}` {tarih1,tarih2,odemeyontemi} | `GET /kasa/ozet?salon_id=&donem=&odeme_yontemi_id=` | ✅ `gelir, gider, net, toplam_kazanc` (v1 `toplamgelir, toplamgider, toplamKasa, toplamCiro`) |
| `POST /tahsilatraporu/{salon}?page` (5/sayfa, ham model) | `GET /kasa/gelirler?…&page=&per_page=&ara=` | ✅ düz satır (`musteri, tahsil_eden, odeme_yontemi, banka, tutar, bagsiz, para_girisi, sil_turu`); kalemler `GET /kasa/gelir/{id}` |
| `POST /masrafraporu/{salon}?page` (Bearer yok, "Kasa" masrafları düşüyordu) | `GET /kasa/giderler` | ✅ harcayansız (Kasa) masraflar dahil, `rozet` |
| `POST /gunsonuraporu/{salon}` (Bearer yok) | `GET /kasa/gun-sonu?donem=` | ✅ aynı anahtarlar (`gelir{nakit,kredikarti,havale,online,diger}`, `satislar[]`, …) |
| `POST /devredenAylar` {salonId, yil} (ay sonu günü düşüyordu) | `GET /kasa/aylik?yil=` | ✅ `aylar[{ay, ay_adi, gelir, gider, net}]` (v1 `tahsilatlar/masraflar/donem_net_kar`) |
| `POST /masrafekleduzenle/{salon}`, `/masrafsil/{salon}`, `GET /masrafkategorileri` | `POST /masraf`, `PATCH/DELETE /masraf/{id}`, `GET /masraf/form-verisi` | ✅ salon kapsamlı; tutar sayı |
| (Flutter'da yok) | `POST /kasa/para-giris`, `POST /kasa/para-cikis/onay-kodu`, `POST /kasa/para-cikis`, `DELETE /kasa/gelir/{id}` | ✅ web Para Ekle / Çek / sil |

## Ayarlar — v1 → v2 eşlemesi

Ekran sekmeleri (ve hangisinin açık olduğu) `GET /ayar/ozet?salon_id=` → `{salon_adi, demo_hesabi, tema_duzenleyebilir, sekmeler[{kod, ad, ikon, v2, eski_url}]}`. Flutter `ayarlar.dart`'taki `Yetki.varMi` kart koşulları bu listeyle aynı eşlemedir (sunucuda tek yer: `AyarSozluk::SEKMELER`). v1'deki bu uçların neredeyse hiçbiri Bearer göndermiyordu; v2'de kimlik + kapsam + sekme yetkisi zorunlu.

| v1 (Flutter bugün) | v2 | Not |
|---|---|---|
| `POST /salonlar {salon_id}` (ham salon satırı) | `GET /ayar/isletme?salon_id=` | aynı kolon adları (`salon_adi, adres, telefon_1, whatsapp, aciklama, instagram_sayfa, facebook_sayfa, vergi_*, kdv_orani, domain, logo, salon_turu_id`) + `logo_url`, `site_url`, `anahtar_kelimeler[]`, `galeri`, `secenekler.salon_turleri` |
| `GET /salonturleri` | `GET /ayar/isletme` → `secenekler.salon_turleri[{id, ad}]` | |
| `POST /isletmebilgiguncelle` (`isletme_adi`, `isletme_telefon`, `vergi_tc_no`…) | `PATCH /ayar/isletme` | eski adlar da kabul edilir; **kısmi** — gönderilmeyen alan değişmez; SEO `meta_description` + `anahtar_kelimeler[]` (v1'de yoktu) |
| `POST /isletmelogoyukle` multipart `isletmelogo` | `POST /ayar/isletme/logo` multipart `logo` (veya `isletmelogo`) | cevap `{logo, logo_url}` |
| (yok — yalnız web) | `GET/POST /ayar/isletme/gorseller`, `DELETE /ayar/isletme/gorseller/{id}` | galeri en fazla 12; silme salon kapsamında (eskide yetkisizdi) |
| `GET /salonsaatleri/{id}` + `GET /salonmolasaatleri/{id}` | `GET /ayar/calisma-saatleri?salon_id=` | tek liste, 7 gün: `{haftanin_gunu, gun_adi, calisiyor, baslangic_saati, bitis_saati, mola_var, mola_baslangic_saati, mola_bitis_saati}` (IsletmeCalismaSaatleri alanları korunur) |
| `GET /calisma_saati_guncelle_ekle/{id}?calisiyor1..7&calismasaatibaslangic1..7&calismasaatibisis1..7` + `mola_saati_guncelle_ekle` | `PUT /ayar/calisma-saatleri {salon_id, gunler[7]}` | çalışma + mola tek istekte; bitiş ≤ başlangıç → 422 |
| `POST /randevuayarguncelle` | `PATCH /ayar/randevu` | **kısmi**; + `studyo_modu`, takvim renk alanları; `GET /ayar/randevu` seçenekleri (aralıklar, takvim türleri, palet) verir |
| `GET /takvimRenkGet/{id}` / `POST /takvimRenkSet/{id} {aktif, renk}` | `GET /ayar/randevu` (`takvim_tek_renk_aktif`, `takvim_tek_renk`, `secenekler.palet`) / `PATCH /ayar/randevu` | `{aktif, renk}` eski adları da kabul edilir |
| `GET /online_randevu_saatleri/{id}` | `GET /ayar/online-saatler?salon_id=` | v1 ile aynı anahtarlar (`kisitlama_aktif` artık bool) + `secenekler.istisna_tipleri` |
| `POST /online_randevu_saatleri_guncelle` | `PUT /ayar/online-saatler` | aynı gövde (`gunler {"1":[slot]}`, `istisnalar[]`) |
| `POST /musteriindirim_kaydet` | `GET/PUT /ayar/musteri-indirimleri` | `{sadik:{acik, yuzde}, aktif:{acik, yuzde}}`; eski form adları da kabul edilir |
| `GET /salonTemaGet/{id}` / `POST /salonTemaSet/{id}` | `GET /ayar/tema` / `PUT /ayar/tema` | aynı `{config, seed}`; yazma rol 1-3 (Flutter `canEditServer`) sunucuda zorunlu; `duzenleyebilir` bayrağı döner |

| `POST /cihazgetir/{id}?page {baslik}` | `GET /ayar/cihazlar?salon_id=&ara=&page=&per_page=` (`tumu=1` → sayfasız) | satır: `id, cihaz_adi, durum (1/0), aktifmi, aciklama` (Flutter Cihaz modeli) + `ad, musait, durum_etiket, takvim_sirasi, yukari, asagi` |
| `GET /cihazlar/{id}` (basit liste) | `GET /ayar/cihazlar?salon_id=&tumu=1` | |
| `GET /cihazekle/{id}?cihaz_adi&calisiyor1..7&cihaz_baslangicsaati1..7…` | `POST /ayar/cihazlar {salon_id, cihaz_adi, gunler[7]}`; düzenleme `PATCH /ayar/cihazlar/{id}` (v1'de yoktu) | plan biçimi çalışma saatleri ile aynı; varsayılanlar `GET /ayar/cihazlar/form-verisi` |
| `POST /cihazmusaitisaretle` / `cihazmusaitdegilisaretle {cihaz_id}` | `PATCH /ayar/cihazlar/{id}/durum {salon_id, musait, aciklama?}` | eskide yetkisiz/kapsamsızdı |
| `POST /cihaz_sil {cihaz_id}` (v1 hard delete!) | `DELETE /ayar/cihazlar/{id}?salon_id=` | pasife alır (web ile aynı), takvim rengi silinir, sıra yeniden numaralanır |
| web `cihazSiralamaArtir/Azalt` | `PATCH /ayar/cihazlar/{id}/sira {salon_id, delta: -1/1}` | cevap: güncel tam liste |
| `POST /odagetir/{id}?page {baslik}` | `GET /ayar/odalar?…` | + `personeller[{id, personel_adi}]` (Flutter Oda modeli), `hizmetler[{id, hizmet_adi}]` |
| `GET /oda_detay/{oda_id}?salon_id=` | `GET /ayar/odalar/{id}?salon_id=` | `personel_ids`, `hizmet_ids` artık int |
| `GET /oda_personel_listesi/{id}` + `GET /oda_hizmet_listesi/{id}` | `GET /ayar/odalar/form-verisi?salon_id=` → `{personeller, hizmetler}` | |
| `POST /odaekleduzenle/{id}` (form; `oda_personeli`/`oda_hizmetleri` JSON metni) | `POST /ayar/odalar`, `PATCH /ayar/odalar/{id}` `{oda_adi, personel_ids[], hizmet_ids[]}` | eski adlar + JSON metni kabul; başka salonun kimlikleri süzülür |
| `POST /odamusaitisaretle` / `odamusaitdegilisaretle`, `POST /oda_sil` | `PATCH /ayar/odalar/{id}/durum`, `DELETE /ayar/odalar/{id}`, `PATCH /ayar/odalar/{id}/sira` | cihaz ile aynı uç kümesi |

| `POST /hizmet_liste_getir/{id}?page {baslik}` | `GET /ayar/hizmetler?salon_id=&kategori_id=&ara=&page=` (`tumu=1`) | satır `id` = salon hizmet kaydı; Flutter Hizmet anahtarları (`hizmet_id, hizmet_adi, hizmet_kategorisi, sure_dk, fiyat, personel, cihaz, ozel_hizmet`) + `personeller[]`, `cihazlar[]`, `maliyet`, `cinsiyet`; + `ozet` |
| `GET /hizmetler/{id}` (basit liste) | `GET /ayar/hizmetler?salon_id=&tumu=1` | |
| `POST /seciliolmayanhizmetlerigetir`, `POST /hizmetkategorileri` | `GET /ayar/hizmetler/form-verisi` → `{kategoriler, personeller, cihazlar, cinsiyetler, havuz[{kategori_id, kategori_adi, hizmetler}]}` | havuz = sistem + salonun pasif özel hizmetleri |
| `POST /hizmetekleduzenle {yeniekleme, hizmetler, sureler, fiyatlar, secilipersoneller…}` | `POST /ayar/hizmetler/havuzdan {salon_id, hizmetler:[{hizmet_id, fiyat, sure_dk, personel_ids, cihaz_ids}]}` · düzenleme `PATCH /ayar/hizmetler/{id}` | paralel diziler yerine kalem nesneleri; eskide pasif kayıt salon filtresiz aranıyordu (başka salonun satırı taşınabiliyordu) |
| `POST /sistemeyenihizmetekle` | `POST /ayar/hizmetler {hizmet_adi, kategori_id, sure_dk, fiyat, maliyet, cinsiyet, personel_ids, cihaz_ids}` | eski `personeller=[12,"cihaz-5"]` karışık listesi de kabul |
| `POST /hizmetsil {hizmetId}` | `DELETE /ayar/hizmetler/{id}?salon_id=` | pasife alır |
| (web) `hizmet-yonetimi/kategori-ekle` | `POST /ayar/hizmet-kategorileri {salon_id, kategori_adi}` | kategoriler GLOBAL (eski şema); aynı ad varsa mevcut döner. Eski "kategori sil" hiçbir şey silmiyordu — v2'de yok |
| `GET subeler/{salonid}` (`subeler` tablosu) | `GET /ayar/subeler?salon_id=` | kullanıcının eriştiği salonlar (panel şube seçicisiyle aynı kaynak) + `kalan_gun`, `yeni_sube_url` (eski panel) |

Henüz taşınmayan: Google takvim entegrasyonu (en son), yeni şube açma (hesap + şifre SMS'i — outbox türü gerekir), şube aktif/pasif (eski düğme `subeler` tablosuna salon id'siyle yazıyordu; doğru anlamı netleşmeli).

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
4. Müşteri kalanları (resimler, seanslar, toplu içe aktarma) → Ayar → Pazarlama/SMS/WhatsApp → Dashboard → Çağrı merkezi.
5. En son: Flutter HTTP katmanı geçişi (kullanıcı "geçiş" dediğinde).
