# Strangler — Uygulama İndirme Afişi (v2)

Eski web `/isletmeyonetim/uygulama-afisi` + `/uygulama-afisi-pdf` (`StoreAdminController@uygulamaAfisi`,
`@uygulamaAfisPdf`, GD yardımcıları `afis*Ciz/Yaz`, `uygulama_afisi.blade`, `afis_pdf.blade`) ekranının
v2 karşılığı. **Salt okuma.** Web (`/v2/uygulama-afisi`) ve Flutter **aynı** `/api/v2/uygulama-afisi*`
uçlarını kullanır. Menü: `PanelMenu` → `v2_rota` (görünürlük `pazarlama.kampanya_yonet`).

## Uç sözleşmesi

| Uç | Dönüş |
|---|---|
| `GET /api/v2/uygulama-afisi?salon_id=` | `{salon{id,ad}, afis_hazir, link_hazir, magazalar{android,ios,huawei}, eksik_magazalar[], indir_linki, paylasim_metni, whatsapp_url, dosya_adi, uclar{onizleme,pdf}}` |
| `GET /api/v2/uygulama-afisi/onizleme?salon_id=` | `image/jpeg` (1588x2246, A4 oranı) — `afis_hazir` değilse 422 |
| `GET /api/v2/uygulama-afisi/pdf?salon_id=` | `application/pdf` ek (A4, kenarsız) — `afis_hazir` değilse 422 |

- `afis_hazir` = Android **ve** iOS linki tanımlı; `link_hazir` = ikisinden **biri** (eski kural birebir; Huawei şart değil).
- Erişim: yalnız kapsam (salonun her üyesi) — eski controller'daki gibi; afiş herkese açık QR'ı gösteren zararsız çıktı.
- Görseller Bearer ister; web `lib/v2-dosya.js` → `apiBlob` + `blobIndir` ile alır (Flutter: `http.get` bytes).
- Eski ekrandaki `lisanssurebitti` yönlendirmesi v2 kalıbında yok (diğer v2 ekranları gibi; lisans günü kabukta).

## Katmanlar

| Katman | Dosya | Sorumluluk |
|---|---|---|
| Domain (saf) | `Domain/Afis/Qr/*` | bağımlılıksız QR kodlayıcı: `QrKodlayici` (akış), `QrSurumTablosu` (EC-M, sürüm 1–10), `ReedSolomon`, `QrYerlesim` (desen/biçim/veri), `QrMaskeCezasi`, `QrMatris` |
| Domain (saf) | `Domain/Afis/MagazaLinkleri.php` | mağaza linkleri + hazır olma kuralı (tek kaynak) |
| Domain (saf) | `Domain/Afis/AfisIcerigi.php` | basılacak içerik (ad, link, logo), dosya adı, baş harf, önbellek parmak izi |
| Okuma | `Services/Afis/AfisIcerikServisi.php` | `salonlar` → içerik + linkler; logo yolu `ayar.eski_public_kok`'e göre |
| Üretim | `Services/Afis/AfisUretici.php` | arka plan + katmanlar → JPEG (file cache) ; PDF = `Support/Pdf/GorselPdf` |
| Katmanlar | `Services/Afis/Katman/*` | `AfisKatmani` arayüzü: `QrKatmani`, `LogoKatmani`, `BaslikKatmani`, `TelefonAdiKatmani`; ortak metin `AfisYazi` |
| Sunum | `Services/Afis/AfisSunumu.php` | JSON sözleşmesi |
| HTTP | `UygulamaAfisiController`, `routes/v2/uygulama-afisi.php` | ince controller |
| Web | `public/js/uygulama-afisi-v2.js`, `css/uygulama-afisi-v2.css`, `views/v2/uygulama-afisi.blade.php` | island |

## Notlar

- **QR hedefi değişmez:** `config('afis.indir_taban')/indir/{salon}` — akıllı yönlendirme eski monolitte
  (`StoreAdminController@uygulamaIndir`, herkese açık). Basılı afişler bu adrese gittiği için taşınmaz.
- QR kodlayıcı BaconQrCode (eski afişin kütüphanesi) ile **modül modül aynı** matrisi üretir (sürüm 1–10,
  426 içerikle karşılaştırıldı; `tests/Unit/Domain/Afis/AfisDomainTest.php` referans özetleri).
- Varlıklar `resources/afis/` (web'e kapalı). Geometri değişirse ilgili katman sabitini düzelt ve
  `AfisUretici::RENDER_SURUMU`'nu artır (önbellek düşer). Yeni öğe = yeni `AfisKatmani` + üretici kurucusuna ekle.
- Önbellek `file` store'unda (MB'lık ikili veri paylaşılan DB'nin `cache` tablosuna yazılmaz), anahtar içerik
  parmak izi (ad, link, logo yolu + mtime, arka plan mtime) → ad/logo değişince kendiliğinden yenilenir.
- Sunucu şartı: PHP GD + FreeType (`imagettftext`); yoksa görsel uçları 422 + açıklayıcı mesaj.
- Eski koda göre düzeltmeler: paletli (GIF/8-bit PNG) logoda kırpma bozuluyordu; logo karesi görsel dışına
  taşınca siyah bant çıkıyordu.
