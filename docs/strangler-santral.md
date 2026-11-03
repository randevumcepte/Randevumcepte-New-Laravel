# Santral — v2 (API-first)

Eski web "Santral Sistemi & Aramalar" (`StoreAdminController@santral` + `santral_raporlari`,
`cdr_rapor_filtre`, `santral_ayar_kaydet`, `santral_ozel_menu_kaydet`) ve Flutter
`yonetici/santral/santralraporlari.dart` (`/api/v1/cdrraporson` → `ApiController@cdrRaporLatest`)
artık **tek uç kümesinden** beslenir. Web ekranı: `/v2/santral?sube=`.

## Katmanlar
| Katman | Dosya | Sorumluluk |
|---|---|---|
| Domain (saf) | `Domain/Santral/CdrSiniflandirici` | dcontext/disposition → durum kodu, kategori, etiket, renk |
| Domain (saf) | `Domain/Santral/SantralErisim` | rol kapsamı: yönetici tümü, personel kendi dahilisi + sonuçsuz |
| Domain (saf) | `Domain/Santral/OzelKarsilamaMenusu` | tuş menüsü normalize + anons metni (eski `Salonlar::santralOzelMenu*` ile birebir) |
| Servis | `SantralIstemcisi` | FreePBX izleme API'si (HTTP + önbellek + hata yutma) — tek dış I/O noktası |
| Servis | `SantralErisimServisi` | kimlik + kapsam + `santral_aktif` + rol/dahili çözümü |
| Servis | `CagriRaporServisi` | CDR listesi: sınıflandır → kapsam → toplu müşteri/personel eşle → özet → filtre → sayfala |
| Servis | `DakikaServisi` | giden dakika havuzu (eski `DakikaHesap` ile aynı hesap) |
| Servis | `SantralAyarServisi` | hatırlatma araması + özel karşılama menüsü (okuma/yazma + form seçenekleri) |
| Controller | `Api/V2/SantralController` | ince; yazmalar `SANTRAL_V2_YAZMA_AKTIF` kill-switch arkasında (kapalıyken 409) |

## Uçlar (`auth:isletmeyonetim-api`, zarf `{basarili, veri, mesaj}`)
| Yöntem | Yol | Not |
|---|---|---|
| GET | `/api/v2/santral/ozet?salon_id=` | `erisim{yonetici, ayar_yonet, telefon_gor, kendi_dahili}` + `dakika` (personelde kendi dahilisi; dahilisi yoksa `null`) |
| GET | `/api/v2/santral/cagrilar?salon_id=&tarih1=&tarih2=&kategori=&ara=&page=&per_page=` | `kategori`: `gelen\|giden\|cevapsiz\|ulasilamadi`; tarih varsayılanı bugün; `ListeIstegi` sayfalama meta'sı + `ozet` + `kesildi` + `hata` |
| GET | `/api/v2/santral/ayarlar?salon_id=` | yalnız yönetici; `hatirlatma{aktif, saat_once, saat_secenekleri}` + `ozel_menu{aktif, secenekler, metin, tuslar, tipler, etiket_maks, hedef_onerileri}` |
| PUT | `/api/v2/santral/ayarlar/hatirlatma` | `{salon_id, aktif, saat_once}` → güncel `hatirlatma` |
| PUT | `/api/v2/santral/ayarlar/ozel-menu` | `{salon_id, aktif, secenekler[{tus,etiket,tip,hedef}]}` → güncel `ozel_menu` (anons metni dahil) |

## Çağrı satırı (Flutter `Cdr.fromJson` uyumlu)
Mevcut alanlar **aynı adla** korunur: `tarih, saat, musteri, gorusmeyiyapan, telefon, durum, seskaydi, avatar, kayitli`.
`durum` kodları Flutter'daki switch ile aynı: `0` Cevapsız · `1` Ulaşılamadı · `2` Giden · `3` Gelen ·
`4` Sonuçsuz · `5` Yol Tarifi · `6` Kampanya · `7` Randevu Alma · `8` Randevu Güncelleme · `9` Randevu İptali.
Ek yapısal alanlar: `zaman` (Y-m-d H:i:s), `yon`, `kategori`, `etiket`, `renk` (UI token), `dahili_arama`,
`musteri_adi`, `musteri_id`, `arama_numarasi` (yalnız `telefon_gor` — maskeli numara aranamaz), `sure_sn`.

## Eskiye göre bilinçli farklar
- **Tek sınıflandırma kuralı** (web santral sayfasınınki): mobil eskiden bilinmeyen bağlamları "Gelen" sayıyordu, `ext-local BUSY`'yi gelen sayıyordu; artık ikisi de web ile aynı.
- **Müşteri eşleme yalnız bu salonun portföyünden** (web eskiden genel `users`'a düşüp başka salonun müşteri adını gösterebiliyordu). `kayitli` = aktif portföy kaydı.
- **Telefon maskesi web'de de uygulanır** (`musteri.telefon_gor`; eskiden yalnız mobilde).
- **Personel**: kendi dahilisi (normal + WebRTC) + sonuçsuz çağrılar; dahilisi yoksa yalnız sonuçsuz (eski mobil filtresiz tüm listeyi veriyordu).
- Salonun hiç dahilisi ve sabit numarası yoksa santrale istek **atılmaz** (filtresiz istek başka salonların CDR'ını döndürebilirdi).
- Sayaçlar: `gelen_arama, giden_arama, cevapsiz_arama, basarisiz_arama` (eski anahtarlar) + `toplam`.

## Flutter geçişi (kullanıcı "geçiş" deyince — şimdilik Flutter'a dokunulmuyor)
`backend.dart::santralraporlari` → `ApiClient` (Bearer) ile `GET /api/v2/santral/cagrilar`; liste `veri.data`,
`has_more` yerine `veri.current_page < veri.last_page`. `user_id` parametresi gereksiz (kimlik token'dan).

## Header rozetleri: WebRTC softphone + kalan SMS + WhatsApp durumu
Eski `layout_isletmeadmin` header'ındaki üç buton (`#webtelefon` + `webphone.js`, `.kalansms`, `#whatsappDurumKutu`)
v2 panel kabuğuna taşındı; tüm v2 ekranlarında görünür. Görünürlük kuralları **sunucuda** (veri `null` → çizilmez).

| Yöntem | Yol | Cevap / kural |
|---|---|---|
| GET | `/api/v2/softphone?salon_id=` | `{dahili, sifre, sip_sunucu, wss_url, arama_yapabilir, sesler{zil,geri_zil}}` · santral etkin + kullanıcının bu salonda `dahili_no_webrtc`'si varsa; yoksa `null`. `arama_yapabilir=false` → personel rolü (5), eskisi gibi yalnız gelen çağrı |
| GET | `/api/v2/softphone/dakika?salon_id=` | `{kalan}` salon havuzu (`DakikaServisi::havuzKalan`, eski `DakikaHesap` kalan'ı) |
| GET | `/api/v2/softphone/arayan?salon_id=&numara=` | `{ad}` salon portföyünden müşteri adı (eski `/isletmeyonetim/musteriarama`) |
| GET | `/api/v2/kabuk/sms?salon_id=` | `{kalan, url}` · personel rolüne `null`; VoiceTelekom (`yeni_sms=1`) / Efetech, 120 sn önbellek |
| GET | `/api/v2/kabuk/whatsapp?salon_id=` | `{bagli, numara, kontor, kontor_kritik, url}` · personel rolüne `null`; kurallar `Domain/Whatsapp/WhatsappDurumu` |

Web: `public/js/header-araclar.js` (rozetler, softphone tembel yükleme) → `softphone/softphone-panel.js` (UI) +
`softphone/sip-hat.js` (SIP bağlantı/çağrı, otomatik yeniden bağlanma) + `softphone/zil.js`; sip.js `public/js/vendor/`.
Diğer ekranlar: `window.rcSoftphone.ara(numara)` / `.bagliMi()` (eski adlar `webphoneAra`/`webphoneBagliMi` da var),
çağrı aşamaları `document` üzerinde `rc:softphone` olayı.

Eskiye göre farklar: SIP şifresi her sayfanın HTML'ine gömülmüyor (yalnız sahibine API'den); SMS bakiyesi her
sayfa açılışında sağlayıcıya gitmiyor (önbellek, 5 sn zaman aşımı; eskisi 120 sn bekleyebiliyordu); görüşme
sırasında tuş takımı DTMF gönderir (sesli menüler); santrali kapalı salonda telefon butonu çizilmez; no-op
`dahilibaglandi` çağrısı kaldırıldı; rozet linkleri yalnız v2 karşılığı olan ekrana gider (şimdilik bilgi rozeti).
