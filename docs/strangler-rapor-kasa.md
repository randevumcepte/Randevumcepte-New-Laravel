# Strangler — Raporlar + Kasa (v2)

Eski panelin **İşletme Raporları** (`isletmeyonetim/isletmeraporlari`), **Satış Raporları**
(`isletmeyonetim/raporlar`) ve **Kasa Raporu** (`isletmeyonetim/kasadefteri`) ekranları ile
mobil v1 rapor/kasa uçlarının tek v2 karşılığı. Web (`/v2/raporlar`, `/v2/isletme-raporlari`,
`/v2/kasa`) ve Flutter aynı `/api/v2` uçlarını tüketir. Flutter'a dokunulmadı; v1 → v2
eşlemesi `docs/flutter-uyum-yol-haritasi.md`.

## Kanonik formül kararları (kullanıcı, 3 Kas 2026)

| Konu | Eski durum | v2 |
|---|---|---|
| Satış tutarı | Satış Raporları + mobil brüt `SUM(fiyat)`; İşletme Raporları net | **Net** = `fiyat − indirim_tutari` (v2 Satış Takibi / AdisyonHesap ile aynı) |
| Satışın dönemi | İşletme: `adisyonlar.created_at`; Satış: `adisyonlar.tarih`; mobil: COALESCE | **Satış tarihi** = `tarih`, boş/sıfırsa `DATE(created_at)` |
| Seanslı hizmet (randevusuz, `seans_sayisi>0`) | web hizmette, mobil + hak ediş pakette | **Paket** kovasında (`tur: seans`) |
| Personel primi | 3 ayrı formül (web, mobil, hak ediş) | **Prim & Hak Ediş** formülü (`Domain/Rapor/PrimHesap`) |
| Ödeme yöntemi kovası | kasa id esaslı, İşletme/dashboard ad araması | **id esaslı** (`Domain/Rapor/OdemeKovasi`; 5=Senet → Diğer) |

Tahsil edilen ("kazanç") kalemin kalem-tahsilat toplamıdır, tahsilat tarihinden bağımsız —
"dönemde yapılan satışların tahsil durumu" (eski anlam). Kasa ise tahsilatı `odeme_tarihi`ne göre sayar.

## Katmanlar

- `Domain/Rapor/RaporDonemi` — tüm uçların dönem sözleşmesi; `OdemeKovasi`; `PrimHesap`.
- `Domain/Satis/KalemTuru` — hizmet/ürün/paket tablo + kolon eşlemesi (tek yer).
- `Services/Rapor/SatisKalemSorgusu` — satış kalemi toplamaları (iki rapor ekranı + prim ortak kaynağı).
- `Services/Rapor/SatisRaporServisi`, `IsletmeRaporServisi`, `RaporSecenekServisi`.
- `Services/Kasa/KasaTutar` (faturasız gizleme SQL ifadesi; eskiden 5 kopya), `KasaRaporServisi`,
  `GunSonuServisi`, `KasaYazServisi`, `MasrafServisi`.
- `Support/OnayKodu` (tek kullanımlık SMS kodu), `Support/YerelListe` (bellekteki listeye ListeIstegi),
  `BildirimKuyrugu::sms` → eski monolit `v2:bildirim-isle` `sms` türü.
- Controller: `RaporTabanController` → `RaporController`, `IsletmeRaporController`, `KasaController`, `MasrafController`.
  Rotalar `routes/v2/rapor.php`.

## Uçlar

| Uç | Yetki |
|---|---|
| `GET /rapor/secenekler?salon_id=&ekran=satis\|isletme\|kasa` | ekranın giriş yetkisi |
| `GET /rapor/satis?sekme=tum\|hizmet\|urun\|paket&personel_id=` (+ ListeIstegi) | `rapor.satis` |
| `GET /rapor/satis/personel` | `rapor.satis`, rol 5 değil |
| `GET /rapor/satis/{hizmet\|urun\|paket\|seans}/{kalem}/musteriler` | `rapor.satis` (telefon `musteri.telefon_gor`) |
| `GET /rapor/isletme/{ozet\|hizmet\|urun\|randevu}` | `rapor.satis`, rol 5 değil |
| `GET /rapor/isletme/personel` · `/musteri` | `rapor.personel_performans` · `rapor.musteri` |
| `GET /kasa/ozet`, `/kasa/gelirler`, `/kasa/gelir/{id}`, `/kasa/gun-sonu`, `/kasa/aylik?yil=` | `rapor.kasa` |
| `GET /kasa/giderler` | `rapor.kasa` + `finans.masraf_gor` |
| `POST /kasa/para-giris`, `/kasa/para-cikis/onay-kodu`, `/kasa/para-cikis` | `finans.kasa_giris_cikis` |
| `DELETE /kasa/gelir/{id}?onay_kodu=` (yalnız para girişi / bağımsız tahsilat) | `satis.tahsilat_sil` |
| `GET /masraf/form-verisi`, `GET /masraf/{id}` | `finans.masraf_gor` \| `finans.masraf_ekle` |
| `POST /masraf`, `PATCH /masraf/{id}`, `DELETE /masraf/{id}` | `finans.masraf_ekle` |

Personel (rol 5) Satış Raporları'nda yalnız kendi satışlarını görür (sunucuda zorlanır).
`rapor.ciro_kar_gor` yoksa tahsil edilen + kâr **sunucuda** `null` döner (`maskeli: true`).
Yazmalar `KASA_V2_YAZMA_AKTIF` kapalıyken 409 (varsayılan kapalı).

## Eski koddan düzeltilenler

- **Güvenlik:** filtre uçlarında yetki / salon kontrolü yoktu (`salonId`/`sube` ile başka salonun
  raporu ve kasası okunabiliyordu); masraf detay/sil/düzenle salon kapsamsızdı; kasa satırları
  kaçışsız HTML'di (stored XSS); Para Çek kodu temizlenmiyor, `==` ile karşılaştırılıyor, hiç
  üretilmemişse boş kod geçiyordu.
- **Doğruluk:** İşletme Raporları toplamları ilk 20 satırdan; ürün adedi satır sayısı; Satış Raporları
  hizmet tablosu filtrede 9 yerine 6 kolon; maliyet/kâr kartları hiç yenilenmiyordu; personel
  adı yalnız hizmet satışı olanda doluydu; yazma sonrası kasa filtresi ay başına sıfırlanıyordu;
  Para Ekle/Çek kuruşu kaybediyordu; SMS yalnız son yetkiliye gidiyordu; onaylı-sonuçlanmamış
  randevular hiçbir kartta yoktu; iptal randevular personel/müşteri aktivitesine sayılıyordu;
  sayısal cinsiyet (0/1) "belirsiz"di; gün sonu personel cirosu / en çok satanlar faturasız
  gizlemeye uymuyordu; Devreden Aylar faturasız gizlemeyi yok sayıyordu.
- **Performans:** grup başına SUM (N+1), sayfa başına tüm adisyon ağacı PHP'de, 24 SUM'lık aylık
  özet, tahsilat başına 4 tembel sorgu → tek toplama sorguları + sunucu sayfalama.

## Bilinçli farklar (eskisinden farklı rakam çıkabilir)

- Net tutar + satış tarihi kuralı: indirimli / geçmişe dönük satışlarda eski rakamlardan farklı.
- İşletme Raporları Hizmet sekmesi seanslı hizmet satışlarını içermez (paket sayılır; Özet'te
  "Satılan Paket" kartı).
- Para Ekle/Çek kayıtları bugünse anlık saatle, geçmiş güne 12:00 ile yazılır (eskiden 00:00).
- Onay kodu SMS'i outbox ile gider (eski monolit `v2:bildirim-isle` cron'u) — birkaç saniye–1 dk gecikebilir.

## Sunucu

```
cd /var/www/www-root/data/www/randevumcepteyeni && php artisan route:clear && php artisan config:clear
php artisan test --filter='RaporDonemiTest|PrimHesapTest'

# Tutarlilik (GERCEK veri, salt okuma; tutmayan kontrol varsa cikis kodu 1):
php artisan rapor:tutarlilik 20                                   # bugun, ay, gecen_ay, yil
php artisan rapor:tutarlilik 20 432 --donem=bugun,dun,hafta,ay,gecen_ay,yil,gecen_yil --sadece-hata
php artisan rapor:tutarlilik 20 --donem=ozel --baslangic=2026-01-01 --bitis=2026-06-30
# yazmayı açmak (kontrollü test sonrası): .env KASA_V2_YAZMA_AKTIF=true → php artisan config:clear
```
