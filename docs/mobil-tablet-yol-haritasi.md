# Mobil / Tablet Uyum Yol Haritası

> Amaç: v2 panel ekranlarının telefon ve tablette rahat kullanılması.
> **Kesin kural:** fareli masaüstü görünümü DEĞİŞMEZ. Her değişiklik yalnızca
> dar ekran (`max-width`) veya dokunmatik (`pointer: coarse`, `hover: none`)
> sorgularının içinde yapılır ve 1440/1280 px ekran görüntüleri HEAD ile
> piksel piksel karşılaştırılarak doğrulanır.

## Kırılım sözleşmesi (tek kaynak: `public/css/panel.css` "Responsive" başlığı)

| Ad | Sorgu | Ne olur |
|---|---|---|
| telefon | `(max-width: 600px)` | header sıkışır, arama ikona döner, tablolar karta döner |
| çekmece | `(max-width: 860px), (hover: none) and (pointer: coarse)` | sol menü gizli çekmece; tablette de (dokunmatik) |
| tablet | `(max-width: 1024px)` | ekran ızgaraları daralır |
| dokunmatik | `(pointer: coarse)` | ≥44px dokunma hedefi, iOS'ta 16px yazı alanı (zoom yok) |

JS karşılığı: `panel-kabuk.js` → `CEKMECE_SORGU` (CSS ile aynı metin).
Yeni CSS'te bu değerler dışında kırılım uydurulmaz.

## Ortak bileşen davranışları

- **Tablo (v2-tablo.js):** telefonda her satır kart. Kolon rolü `kolon.mobil`:
  `baslik` · `alt` · `gizle` · `etiketsiz`. Varsayılan: ilk anlamlı kolon başlık,
  "İşlem…" kolonu alt. Kart istemeyen tablo: `mobilKart: false` (yatay kayar).
- **Modal:** telefonda alttan açılır pencere, kaydet butonu yapışkan; yükseklik `dvh`.
- **Aranabilir select:** dokunmatikte arama kutusu otomatik odak almaz (klavye listeyi örtmez).
- **Viewport:** `interactive-widget=resizes-content` (Android klavyesi içeriği daraltır).

## Fazlar ve durum

| Faz | İçerik | Durum |
|---|---|---|
| 0 | Test düzeneği + kontrol listesi | ✅ |
| 1 | Kabuk: çekmece (tablet dahil), telefon header'ı, dokunma hedefi, iOS zoom, şube seçici çekmecede | ✅ |
| 2 | Ortak bileşenler: tablo kartı, modal dvh, select odak | ✅ |
| 3 | Ekran bazlı düzeltmeler: 32 ekran 360/390/600/820/1180 px'te tarandı, taşma yok | ✅ |
| 4 | Telefon kırılımları (560/575/640) → 600; ara tablet kırılımları istisna olarak kaldı | ✅ |

## Ekran kontrol listesi (her yeni/değişen ekran için)

Boyutlar: 360 · 390 (telefon) · 820 dikey · 1180 yatay (dokunmatik tablet) · 1280 · 1440 (masaüstü).

- [ ] 1440 ve 1280 görüntüsü değişiklik öncesiyle aynı
- [ ] Yatay sayfa taşması yok (`scrollWidth == innerWidth`)
- [ ] Menü çekmecesi açılıp kapanıyor, şube seçilebiliyor
- [ ] Tablo telefonda kart, sıralama çipleri çalışıyor
- [ ] Modal telefonda alttan açılıyor, kaydet görünür
- [ ] Butonlar parmakla basılabilir (≥44px), hover'a bağlı gizli işlem yok
- [ ] Form alanına odaklanınca iOS yakınlaştırmıyor

## Test düzeneği

Yerelde Laravel çalışmadığı için ekranlar küçük bir Node sunucusuyla açılır:
`public/` statik, `resources/views/v2/*.blade.php` basit blade dönüştürücüyle,
`/api/v2/*` sahte JSON ile. Headless Chrome (puppeteer-core) her profil için
ekran görüntüsü alır; masaüstü görüntüleri `git show HEAD:` sürümüyle
karşılaştırılır. (Düzenek proje dışında tutulur; gerekirse `scripts/`e alınabilir.)

## Bilinen istisnalar

- 680–820 px arası ekran özel kırılımlar (personel, ayarlar, rapor, özet, çağrı merkezi,
  reklam) korunuyor: 860'a çekmek iPad dikey görünümünü gereksiz daraltır. Yeni ekranlar
  bu değerleri kullanmaz; sözleşmedeki dört kırılımla yazılır.
- ≥900 px kırılımlara dokunulmadı (dar masaüstü pencereleri de etkilenir).
- 420 px (personel) çok küçük telefon ince ayarı olarak kaldı.

## Tuzaklar (taramada bulunanlar)

- Tek sütuna inen grid'de `1fr` değil `minmax(0, 1fr)`: `1fr` içeriğin en dar genişliğinin
  altına inmez, telefonu taşırır.
- Mobil emülasyonda taşma görünmez (tarayıcı sayfayı uzaklaştırır); kontrol `isMobile:false`
  ile `scrollWidth > innerWidth` ölçülerek yapılır.
- Gizli `<select>` (`.rs-gizli`) ekran CSS'indeki `min-width`'i almamalı.
