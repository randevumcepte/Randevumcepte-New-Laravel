# Strangler — Çarkıfelek + Sadakat (v2)

Eski web'deki 4 ayrı sayfanın (`/isletmeyonetim/carkifelek`, `carkkazananlar`, `puanodulleri`,
`carkhatirlatma`) ve mobil v1 `carkAdmin/*` uçlarının v2 karşılığı. Web paneli tek sekmeli
`/v2/carkifelek` sayfasıdır (`?p=cark|kazananlar|puan|hatirlatma`). Web ve Flutter **aynı**
`/api/v2/cark/*` + `/api/v2/sadakat/*` uçlarını kullanır. Yazmalar `CARK_V2_YAZMA_AKTIF`
(varsayılan açık) arkasındadır; kapalıyken 409 döner.

Müşteri tarafı (çarkı çevirme, kupon/puan ödüllerim, sadakat hub'ı) **eski monolitte kalır**. Bu
doküman yalnız işletme paneli tarafını kapsar.

## Katmanlar

| Katman | Dosya | Sorumluluk |
|---|---|---|
| Domain (saf) | `Domain/Cark/DilimTipi.php` | dilim/ödül tipleri, değerli/kupon, indirim birimi (% / ₺), ödül metni |
| Domain (saf) | `Domain/Cark/DilimSeti.php` | kaydedilecek dilimlerin TEK kuralı: 6–12 dilim, tam bir kazanan (100), tip/değer/renk normalleştirme |
| Domain (saf) | `Domain/Cark/KuponDurumu.php` | gecerli / kullanildi / sure_doldu hesabı + filtre sözlüğü |
| Domain (saf) | `Domain/Cark/PuanOdulTipi.php` | puan merdiveni basamak tipleri (hizmet/ürün/paket indirimi, hediye) |
| Domain (saf) | `Domain/Cark/CarkSekmeleri.php` | sayfa sekme kayıt defteri (web + Flutter aynı liste) |
| Çoklu şube | `Services/Yetki/SubeGrubu.php` | şube seçenekleri, hedef şubeler (kapsam ∩ yetki), kupon şube grubu (kardeş şubeler) |
| Ayar | `Services/Cark/CarkAyarServisi.php` | çark oku/kaydet (atomik, çok şube), aktiflik, duyuru outbox |
| Kupon | `Services/Cark/CarkKuponServisi.php` | kupon listesi + sayaçlar, doğrula, kullanıldı/geri al (satır kilidi), müşterinin geçerli kuponları |
| Geçmiş | `Services/Cark/CarkGecmisServisi.php` | özet kartları + çevirme geçmişi (sayfalı) |
| Hatırlatma | `Services/Cark/CarkHatirlatmaServisi.php` | 3 push slotu + atlanan günler (gönderim eski cron'da) |
| Sadakat | `Services/Sadakat/PuanOdulServisi.php` · `SadakatKazanimServisi.php` | puan merdiveni CRUD + örnekler · puan kazanma kuralı |
| HTTP | `CarkController`, `CarkKuponController`, `SadakatController`, `Requests/Cark/*`, `Requests/Sadakat/*`, `routes/v2/cark.php` | ince controller, doğrulama, eski alan adı eşleme |
| Web | `views/v2/carkifelek.blade.php`, `js/carkifelek-v2.js`, `js/carkifelek/{cark,cark-cizim,kazananlar,puan,hatirlatma}.js`, `js/lib/v2-sube-secici.js`, `css/carkifelek-v2.css` | sekmeli island; tablolar `veriTablosu` |

Ortak kullanılanlar (kopya yok): `SalonIdListesi` (gecerli_salonlar ayrıştırma), `KapsamServisi`,
`YetkiServisi`, `ListeIstegi`, `AktiviteLog` (+ `HareketKatalogu` etiketleri), `BildirimKuyrugu`,
`KvkkMaske`, web'de `v2-sekme` / `v2-tablo` / `v2-modal` / `v2-bilesen`.

## Uçlar

| Uç | Açıklama |
|---|---|
| `GET /cark?salon_id=` | açılış: `sistem`, `dilimler`, `sekmeler`, `sozluk{dilim_tipleri, kupon_durumlari, renkler, dilim_min/max, varsayilan_dilimler}`, `subeler`, `yazabilir` |
| `PUT /cark` | `{dilimler[{name,color,probability,tip,deger,indirim_tipi}], aktifmi, kurallar, cevirme_araligi_gun, kupon_cark_gecerlilik_gun, kupon_puan_gecerlilik_gun, salon_ids[]}` |
| `PATCH /cark/aktif` | `{aktifmi}`; şube grubuna yansır; çark kaydedilmemişse 422 |
| `POST /cark/duyuru` | `{baslik?, mesaj?}` → outbox `cark_duyuru` (çark aktif değilse 422) |
| `GET/PUT /cark/hatirlatma` | `{aktif, slotlar[{no,aktif,baslik,altyazi,saat,mesaj}], atlanan_gunler[]}` |
| `GET /cark/ozet` | toplam / bugün çevirme, bekleyen / kullanılmış kupon |
| `GET /cark/kuponlar?durum=` | + ListeIstegi; satırda `musteri{id,ad,telefon}`; `sayaclar` |
| `GET /cark/cevirmeler` | + ListeIstegi |
| `POST /cark/kupon/dogrula` | `{kod}` |
| `POST /cark/kupon/{id}/kullan` · `/geri-al` | satır kilidi; geçersiz durumda 409 |
| `GET /cark/musteri-kuponlari?user_id=` | tahsilat ekranı için |
| `GET /sadakat` | `odul_seviyeleri[]`, `kazanim{}`, `sozluk.odul_tipleri`, `subeler` |
| `POST /sadakat/puan-odulu` · `PATCH/DELETE /sadakat/puan-odulu/{id}` · `POST /sadakat/puan-odulu/ornekler` | puan merdiveni |
| `PUT /sadakat/kazanim` | `{aktif, harcama_tutari, harcama_puani, ziyaret_puani}` |

Yetki: `pazarlama.cark_yonet`. Kupon doğrulama, kullanım işaretleme ve müşteri kuponları kasada
da yapıldığı için bu uçlar `pazarlama.cark_yonet` **veya** `satis.tahsilat_al` ile açılır. Açık
telefon için `musteri.telefon_gor` gerekir; yoksa numara maskelenir.

## v1 → v2 eşlemesi (Flutter geçişi)

| v1 (mobil) | v2 | Not |
|---|---|---|
| `GET carkAdmin/sistem/{salon}` → `{sistem, dilimler}` | `GET /cark?salon_id=` | `sistem` + `dilimler` alanları aynı; ek: kurallar, sözlük, şubeler |
| `POST carkAdmin/dilim-kaydet/{salon}` | `PUT /cark` | gövde alanları aynı (`dilimler[].probability` vb.) |
| `POST carkAdmin/aktif-toggle/{salon}` | `PATCH /cark/aktif` | |
| `POST carkAdmin/bildirim-gonder/{salon}` → `{gonderildi, hata, hedef}` | `POST /cark/duyuru` → `{kuyrukta: true}` | **asenkron**: sayılar dönmez, gönderim birkaç sn içinde |
| `GET carkAdmin/kazananlar/{salon}?filtre=` → `{loglar, odulluler, users, ozet}` | `/cark/ozet` + `/cark/kuponlar?durum=` + `/cark/cevirmeler` | `filtre` adı da kabul; `users` haritası yerine satırda `musteri{}`; sayfalı |
| `POST carkAdmin/kupon-dogrula/{salon}` → `{odul}` | `POST /cark/kupon/dogrula` → `veri` | `gecerlilik` yerine `gecerlilik_tarihi` (Y-m-d, süresizse null) |
| `POST carkAdmin/kupon-kullan/{salon} {odul_id, aksiyon}` | `POST /cark/kupon/{id}/kullan` · `/geri-al` | cevap güncel kupon satırı |
| `GET carkAdmin/kuponlar/{salon}?user_id=` | `GET /cark/musteri-kuponlari?user_id=` | |
| `GET/POST carkAdmin/hatirlatma/{salon}` → `{ayar, bugun}` | `GET/PUT /cark/hatirlatma` | düz `saat_1/mesaj_1/aktif_1…` + `gonderim_gunleri` gövdesi de kabul |
| `GET carkAdmin/puan-odul/{salon}` → `{odulSeviyeleri}` | `GET /sadakat` → `odul_seviyeleri` | |
| `POST carkAdmin/puan-odul/{salon}` (id=0 ekle, id>0 düzenle) | `POST /sadakat/puan-odulu` · `PATCH /sadakat/puan-odulu/{id}` | |
| `POST carkAdmin/puan-odul-sil/{salon} {id}` | `DELETE /sadakat/puan-odulu/{id}` | |
| `GET/POST carkAdmin/puan-kazanim/{salon}` | `GET /sadakat` (`kazanim`) · `PUT /sadakat/kazanim` | |

Flutter geçişi: `backend.dart`'ta taban `/api/v2`, Bearer token ve `govde.veri` okuma. Kural
ve sözlükler artık API'den gelir; ekran içi sabit tip listeleri kaldırılabilir. **Flutter'a
dokunulmadı.**

## Eskiden bilinçli farklar

- **Yetki ve kapsam:** v1 `carkAdmin/*` uçlarında kimlik/kapsam/yetki denetimi yoktu (salon id değiştirilerek başka salonun çarkı yazılabiliyordu). v2'de her uç `GuvenlikKapisi` ile korunur.
- **Okuma yan etkisi yok:** eski GET'ler `firstOrCreate` ile satır açıyor, `Schema::create` ile tablo kuruyordu. v2 okuma hiçbir şey yazmaz; varsayılanlar `config/cark.php`'den gelir.
- **Kupon işaretleme kilitli:** zaten kullanılmış kupon tekrar "kullanıldı" yapılamaz, süresi dolmuş kupon kullanılamaz, kullanılmamış kupon geri alınamaz (409). Eski uç her isteği körlemesine uyguluyordu.
- **Başka işletmenin kuponu:** kod başka işletmeye aitse "kayıtlı değil" (404) döner, kuponun varlığı sızdırılmaz. Kullanıcının kendi diğer şubesine aitse "bu şubede geçerli değil" (422) döner.
- **Hedef şubeler** kullanıcının erişebildiği ve `pazarlama.cark_yonet` yetkisi olan şubelerle sınırlanır (eski web yalnız erişimi, mobil yalnız kardeş grubu kontrol ediyordu).
- **Aktif/pasif** artık tüm dilimleri yeniden yazmadan yalnız durumu değiştirir ve şube grubuna yansır (eski web tüm çarkı yeniden kaydediyordu).
- **Duyuru** web'e de eklendi (eskiden yalnız mobildeydi) ve asenkron çalışır: outbox `cark_duyuru` → monolit `CarkDuyuruGonderJob` → `CarkDuyuruServisi` (v1 uç da aynı servisi kullanır).
- **Puan ödülü tipleri** dört seçenektir (paket indirimi web formunda yoktu, mobilde vardı).
- **Kazanım kuralı doğrulaması** sunucuda yapılır (açıkken en az bir puan değeri zorunlu; eskiden yalnız tarayıcıda vardı).
- Kupon listesinde e-posta gösterilmez (veri minimizasyonu). Liste ve çevirme geçmişi 500 satır sınırı yerine sayfalıdır.

## Kalan

- Flutter ekranı (`lib/yonetici/diger/menu/cark/cark_yonetimi.dart`) v1'de kaldı (Flutter donduruldu).
- Şube seçici artık iki ekranda (Çarkıfelek, Bildirim Reklamları) kullanılıyor; `.rc-sube-*` stili `carkifelek-v2.css`'ten `v2-bilesen.css`'e taşınmalı (panel layout'u başka oturumda değiştiği için bu işte yapılmadı).
- Sunucu duman testi yapılmadı (New-Laravel yerelde çalışmaz).
