# Strangler — Reklam Yönetimi (kampanya) v2

Eski web `/isletmeyonetim/kampanya_yonetimi` (liste + reklam-ekle-modal + reklam-detay-modal + Senaryo
Sihirbazı) ve mobil v1 `kampanya*` uçlarının v2 karşılığı. Web paneli (`/v2/reklam-yonetimi`) ve Flutter
**aynı** `/api/v2/kampanya/*` uçlarını kullanır. Yetki: `pazarlama.kampanya_yonet` (okuma + yazma; menü ayrıca
`uyelik_turu > 2`). Açık telefon: `musteri.telefon_gor`. Yazmalar `KAMPANYA_V2_YAZMA_AKTIF` (varsayılan açık)
arkasında; kapalıyken 409.

**Gönderim v2'de DEĞİL.** Kayıt yalnız planlar (kanal bayrağı + `*_tarih_saat`); gönderimi eski cron'lar yapar:
arama → `kampanyaarama:yap` (+ E-Asistan `ayar_id=8`), SMS → `kampanyasms:gonder`. Yeni kampanyada personele
"reklam oluşturuldu" push'u outbox `kampanya_olustur` → eski `v2:bildirim-isle` → `KampanyaYonetimi::olusturmaBildirimiGonder`
(eski web kaydı da aynı metodu çağırır).

## Katmanlar

| Katman | Dosya | Sorumluluk |
|---|---|---|
| Domain (saf) | `Domain/Kampanya/Kanal.php` | 1 Arama · 2 SMS · 3 Bildirim · 4 Bilgilendirme; etiket, ikon, indirimli mi, bayrak/zaman kolonu |
| Domain (saf) | `Domain/Kampanya/HedefKitle.php` | hedef_filtre/grup/cinsiyet değer nesnesi, preset sözlüğü, `musteri_turu` etiketi, eşitlik |
| Domain (saf) | `Domain/Kampanya/Indirim.php` | `%N İndirim` / `X Al Y Öde` metni (kupon regex'i ile uyumlu), yer tutucu değerleri |
| Domain (saf) | `Domain/Kampanya/KalemSecimi.php`, `SablonKimligi.php` | `hizmet-12` / `senaryo-5` / `sablon-3` / `11` önekli değerler |
| Domain (saf) | `Domain/Kampanya/MesajMetni.php` | yer tutucu iki aşaması, arama selamı (cron ile birebir), ilk cümle atma |
| Domain (saf) | `Domain/Kampanya/KatilimDurumu.php` | rapor sekmeleri 1..7 (SQL koşulu) + satır rozeti önceliği |
| Domain (saf) | `Domain/Kampanya/Senaryo.php` | hazır senaryolar, adım/aksiyon sözlüğü, konuşma önizlemesi |
| Okuma | `Services/Kampanya/KampanyaSorgusu.php` + `KampanyaSunumu.php` | liste + kanal sayaçları, detay/prefill, JSON sözleşmesi |
| Kitle | `Services/Kampanya/HedefKitleServisi.php` | canlı sayaç + katılımcı listesi (tek sorgu) |
| Mesaj | `Services/Kampanya/KampanyaMesajServisi.php` | şablon kataloğu, şablon metni, kayıt metni, önizleme, `{gün}` |
| Yazma | `Services/Kampanya/KampanyaYazServisi.php` | ekle/düzenle/pasif — transaction, katılımcı kurulumu, ayar 8, outbox, log |
| Rapor | `Services/Kampanya/KatilimciServisi.php` | katılımcı listesi + sayaçlar, ekle/çıkar, tekrar ara, personele ata |
| Senaryo | `Services/Kampanya/SenaryoServisi.php` | liste/kaydet/sil/önizle |
| Form | `Services/Kampanya/KampanyaFormServisi.php` | tüm sözlükler + katalog + şablonlar + personel |
| HTTP | `KampanyaController`, `KampanyaKatilimciController`, `KampanyaSenaryoController` (taban `KampanyaTabanController`), `Requests/Kampanya/*`, `routes/v2/kampanya.php` | ince controller, doğrulama, eski alan adı eşleme |
| Web | `views/v2/reklam-yonetimi.blade.php`, `js/reklam-yonetimi-v2.js`, `js/reklam/{ortak,rapor,senaryo}.js`, `css/reklam-yonetimi-v2.css` | build'siz island |

Yeniden kullanılanlar (kopya yok): `MusteriOkumaServisi::temelSorgu` (aktif portföy + personel kapsamı),
`Segment::sqlKosulu` (sadık/aktif/pasif = müşteri listesi sekmeleriyle aynı kural), `KatalogServisi` (hizmet/ürün/paket),
`RandevuFormServisi` (müşteri arama, personel listesi), `AsistanAyarServisi` (ayar 8), `ListeOlusturmaServisi::listeYaz`
(Çağrı Merkezi arama listesi — "Personele Ata" ile ortak), `KvkkMaske`, `AktiviteLog`, `BildirimKuyrugu`.

## Uçlar

| Uç | Not |
|---|---|
| `GET /kampanya/form-verisi?salon_id=` | `kanallar`, `hedefler` (preset), `cinsiyetler`, `gruplar`, `hizmetler/urunler/paketler` `{value,label}`, `sablonlar` `{value,label,tur,kanal}`, `yer_tutucular`, `indirim{turler,varsayilan}`, `planlama`, `katilim_durumlari`, `personeller`, `senaryo{adimlar,aksiyonlar,presetler}`, `yetkiler` |
| `GET /kampanya?salon_id=&kanal=&ara=&page=&per_page=&sirala=&yon=` | `{data[], …meta, sayaclar{tumu,1,2,3,4}}` |
| `GET /kampanya/{id}` | satır alanları + `mesaj`, `hizmet_urun_paket`, `hedef_*`, `hedef_preset`, `indirim`, `yuzde_mi/yuzde/xal/yode`, `randevu_olustur`, `arama{ayar_acik,planlanan,sunucu_zamani}` |
| `POST /kampanya/kitle-sayisi` | `{toplam, etiket}` |
| `POST /kampanya/mesaj-onizle` | `{sablon_metni, kayit_metni, onizleme, ornek_musteri, karakter}` |
| `POST /kampanya` · `PATCH /kampanya/{id}` | `{id, katilimci_sayisi, katilimcilar_yenilendi, kampanya}` |
| `DELETE /kampanya/{id}` | pasif (`aktifmi=0`) |
| `GET /kampanya/{id}/katilimcilar?durum=` | `{data[], …meta, sayaclar{tumu, indirim_kullanan, indirim_kullanmayan, beklenen, katilan, katilmayan, ulasilamadi}}` |
| `POST /kampanya/{id}/katilimcilar {user_id}` · `DELETE /kampanya/{id}/katilimcilar/{kid}` | ekle / çıkar |
| `POST /kampanya/{id}/tekrar-ara` · `POST /kampanya/{id}/personele-ata {personel_id, durum}` | arama kanalı / Çağrı Merkezi |
| `GET/POST /kampanya/senaryo` · `GET/PATCH/DELETE /kampanya/senaryo/{id}` · `POST /kampanya/senaryo/onizle` | Senaryo Sihirbazı |
| `GET /kampanya/musteri-ara?salon_id=&q=` | katılımcı ekleme |

## v1 → v2 eşlemesi (Flutter geçişi)

| v1 (Flutter `backend.dart`) | v2 | Not |
|---|---|---|
| `GET /kampanyaFormVerileri/{salon}` | `GET /kampanya/form-verisi?salon_id=` | `success` → zarf `basarili`; alanlar `veri` içinde, aynı `{value,label}` |
| `POST /kampanyaKitleSayisi/{salon} {filtre,cinsiyet,grup}` | `POST /kampanya/kitle-sayisi` | eski adlar kabul; `toplam` aynı |
| `POST /kampanyaMesajOnizle/{salon} {seciliSablonId, hizmetUrunPaket, kampanyaIndirim}` | `POST /kampanya/mesaj-onizle` | v1 `mesaj` → `onizleme`; düzenlenebilir metin `sablon_metni` |
| `POST /kampanyaekleduzenle/{salon}` (`kampanya_id` boş/dolu) | `POST /kampanya` · `PATCH /kampanya/{id}` | tüm eski alan adları kabul (`gorevTuru`, `gelenGelmeyenMusteri`, `katilimciTuru`, `musteriGruplari`, `hizmetUrunPaket`, `seciliSablonId`, `kampanya_sms`, `kampanyaKodu`, `indirimTuru` bool, `Xal/Yode/kampanyaIndirim`, `asistan_tarih/saat`, `kampanyaGecerlilikTarihi`, `hedefDegisti`) |
| `POST /kampanyaDuzenleGetir/{salon} {kampanya_id}` | `GET /kampanya/{id}` | aynı anahtarlar (`gorev_turu`, `mesaj`, `indirim_kodu`, `yuzde_mi`, `yuzde`, `xal`, `yode`, `baslangic_tarihi`, `bitis_tarihi`, `hedef_*`); v1 camelCase `hizmetUrunPaket` → `hizmet_urun_paket`; ek `gonderim_saati` (v1'de yoktu) |
| `POST /kampanyalar/{salon} {page, arama}` | `GET /kampanya?salon_id=&page=&ara=` | `kampanya_katilimcilari[]` dizisi yerine `katilimci_sayisi` (tüm katılımcıları taşımaz) |
| `POST /kampanyaDetay/{salon} {kampanya_id, katilimDurumu, page, search}` | `GET /kampanya/{id}` (başlık) + `GET /kampanya/{id}/katilimcilar?durum=&page=&ara=` | satırda `durum` yine Türkçe metin; ek `durum_kodu`, `durum_renk`; 7 sekme + sayaçlar |
| `POST /kampanyapasifet {kampanya_id}` | `DELETE /kampanya/{id}` | |
| `kampanyaAdmin/kod-kullan`, `kampanyaKoduAdisyonDurum`, `kampanyaAdmin/kodlar` | — | **taşınmadı** (tahsilat ekranının kupon akışı; bkz. Kalan) |

Flutter geçişi = `backend.dart`'ta taban `/api/v2` + Bearer + `govde.veri` okuma. Tek davranış farkı: v1 istemci
`kampanya_sms`'e ÖRNEK müşteriyle çözülmüş önizlemeyi koyuyordu; v2 bunu şablon seçiliyse yok sayar (eski web
davranışı: şablon kazanır), şablonsuzsa `mesaj` olarak alır — Flutter `mesaj`'a `sablon_metni`'ni göndermeli.

## Eskiden bilinçli farklar

- **Kapsam/yetki her uçta** (eski v1 uçlarında hiç yoktu; web detay/sil salon kontrolsüzdü — IDOR).
- **Kitle**: telefonsuz, **kara listedeki** ve pasif portföy kaydı kitleye girmez; DISTINCT (çift satır yok).
  Sadık/aktif/pasif müşteri listesi segmentiyle aynı kural (`Segment`). Preset açıklamaları gerçek SQL'i anlatır.
  Personel kapsamı (`musteri.tum_portfoy_gor` yoksa kendi portföyü). `hastagrup-` (reçete grubu) hiçbir arayüzde yoktu → kaldırıldı.
- **Katılımcı listesi yalnız hedef kitle DEĞİŞİNCE** (değer karşılaştırması) ya da `hedef_yenile=1` ile yeniden kurulur;
  arama/kupon ilerlemesi korunur. Hizmet değişmesi artık listeyi sıfırlamaz.
- **Kanal değişince** eski kanalın gönderim bayrağı kapanır (eski: iki kanaldan birden gidebiliyordu).
- **Kalem değişince** diğer `*_id` NULL (eski web: kupon eski kaleme düşüyordu).
- **Arama kampanyasında E-Asistan ayar 8** kapalıysa açılır (eski yalnız mobilde).
- **İndirim kodu değişirse** kullanılmamış katılımcıların kodu da güncellenir; kupon kullanmış katılımcı çıkarılamaz (409).
- **Yer tutucular**: `{indirim}` X-Al-Y-Öde'de "2 Al 1 Öde" (eski: boş); `{indirim_veya_x_al_y_ode}` çözülür (eski: hiç);
  `{ürün_hizmet_paket}` paket için de çözülür (eski: `paket-X` çözülmüyordu).
- **Liste**: katılımcısız kampanya "1" görünmüyor; paket adı Hizmet/Ürün kolonunda; kampanya adı istenirse elle verilebilir (`ad`).
- Sunucu doğrulaması (tarih sırası, indirim aralığı, mesaj zorunlu, boş kitle 422).
- Telefon araması rapor listesinde çalışıyor; telefon maskesi rol 5 yerine `musteri.telefon_gor` yetkisiyle.

## Kalan

- **Kupon kullanımı** (`kod-kullan`, adisyon kupon durumu, müşterinin kodları) tahsilat domainine ait; v2 tahsilat
  harici indirim + kupon çift-sayım koruması var, kupon uygulama ucu ayrı iş.
- **SMS taslağı ekle/sil** (eski sablon-ekle-modal) ve **Grup SMS yönetimi** (aynı sayfadaydı) taşınmadı — SMS Yönetimi domainiyle birlikte.
- Eski sihirbazdaki sesli (TTS) önizleme taşınmadı.
- **Eski sistemde de açık olan gönderim boşlukları** (v2 değiştirmedi): `kampanyasms:gonder` zamanlaması
  `Kernel.php`'de yorum satırında; Bildirim (3) / Bilgilendirme (4) kanalları için gönderici kod yok.
- Flutter ekranları v1'de (Flutter'a dokunulmadı).
- Sunucu testi (gerçek token + DB) yapılmadı; domain birim testleri: `tests/Unit/Domain/Kampanya/KampanyaDomainTest.php`.
