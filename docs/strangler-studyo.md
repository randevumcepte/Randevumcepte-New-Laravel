# Stüdyo modu + Grup dersi (v2)

Pilates/kurs işletmeleri için eski monolitteki stüdyo modunun API-first karşılığı.
Web (`/v2/*`) ve Flutter aynı `/api/v2` uçlarını kullanır. Flutter henüz bağlanmadı; v1 çalışmaya devam eder.

## Katmanlar

| Katman | Sınıf | Sorumluluk |
|---|---|---|
| Domain (saf) | `Domain\Studyo\StudyoPolitikasi` | Bayraklardan kurallar: fiyat gizli, ödeme modu, no-show, yayın tavanı, gizli özellikler |
| | `Domain\Ders\KatilimciDurumu` | Durum sözlüğü, kontenjan kuralı, yeni kayıt rezerve/bekleme |
| | `Domain\Ders\SeansEtkisi` | Durum → seans düş / iade kararı |
| | `Domain\Ders\YayinAraligi` | "Programı Yayınla" tarih hesabı |
| | `Domain\Ders\DersRaporu`, `DersMesaji` | Rapor toplama, bildirim metinleri |
| Servis | `Services\Seans\SeansHavuzu` | Randevu + ders ORTAK: aday seçimi, bekleyen telafi, kalan, kaynaklar |
| | `Services\Ders\DersSeansServisi` | Düşüm / iade / telafi bağlama |
| | `Services\Ders\DersKatilimServisi` | Ekle, çıkar, durum, terfi (oturum kilidi altında) |
| | `DersOturumServisi`, `DersProgramServisi`, `DersTekrarliServisi`, `DersRaporServisi`, `DanisanDersServisi`, `DersReferansServisi` | Tek sorumluluklu işlemler |
| | `DersBildirim` | Outbox `ders_bildirim` (gönderim eski monolitte) |
| HTTP | `Ders*Controller`, `MusteriDersController`, `StudyoController` | İnce; yetki `DersTabanController`'da |
| Web | `lib/v2-ders.js` | Ders formu, oturum modalı, otomatik plan, müşteri sekmesi (tüm ekranlar ortak) |

Tablolar eski sistemle paylaşılır, migration yoktur. `ders:hatirlat` ve `ders:katilim-sor` cron'ları eski monolitte kalır; aynı tabloları okuduğu için v2 kayıtlarını da işler.

## Yetki

`OKU = randevu.takvim_gor`, `YAZ = randevu.olustur`, `SIL = randevu.duzenle_iptal` (eski panelle aynı). Salonda `grup_dersi_aktif` kapalıysa 403. Yazmalar `DERS_V2_YAZMA_AKTIF` arkasında (kapalıyken 409). Otomatik plan yalnız stüdyoda.

## v1 → v2 eşlemesi

Yanıt zarfı v2 standardıdır (`{basarili, veri, mesaj}`); `veri` içindeki alan adları Flutter modelleriyle (`GrupDersOturum`, `GrupDersKatilimci`, `GrupDersSablon`) aynıdır. Salon v1'de gövdeden, v2'de `salon_id` veya kayıttan gelir; kimlik token'dandır.

| v1 (`/api/v1`) | v2 (`/api/v2`) | Not |
|---|---|---|
| `POST /ders-programi-liste` | `GET /ders/program?salon_id=` | `personeller, hizmetler, sablon, eslesme` |
| `POST /ders-sablon-kaydet` | `POST /ders/sablon`, `PATCH /ders/sablon/{id}` | `{sablon_id, senkron}` |
| `POST /ders-sablon-sil` | `DELETE /ders/sablon/{id}` | `{temizlenen, korunan}` |
| `POST /ders-programi-yayinla` | `POST /ders/yayinla` | `{olusan, atlanan, hafta}` |
| `POST /ders-gun-liste` | `GET /ders/oturum?salon_id=&tarih=` | ayrıca `tarih1`, `tarih2`, `personel_id`; rol-5 kendi dersleri |
| `POST /ders-oturum-getir` | `GET /ders/oturum/{id}` | `oturum, katilimcilar, durumlar`; telefon `musteri.telefon_gor` yoksa maskeli |
| `POST /ders-oturum-kaydet` | `POST /ders/oturum`, `PATCH /ders/oturum/{id}` | `{oturum_id}` |
| `POST /ders-oturum-sil` | `DELETE /ders/oturum/{id}` | soft iptal + iade + bildirim |
| `POST /ders-katilimci-ekle` | `POST /ders/oturum/{id}/katilimci {musteri_id}` | `{katilimci_id, katilimci_durum, telafi_baglandi}` |
| `POST /ders-katilimci-cikar` | `DELETE /ders/katilimci/{id}` | bekleyen terfi eder |
| `POST /ders-katilimci-durum` | `PATCH /ders/katilimci/{id}/durum {yeni_durum}` | `dusum`: `dusuldu, hak_yok, hizmet_bagli_degil, iade, telafi_korundu, null` |
| `ders-musteri-ara` (web) | `GET /ders/musteri-ara?salon_id=&q=` | `[{id, ad, telefon}]` |
| `POST /danisan-grup-derslerim` (panelden) | `GET /ders/musteri/{id}/dersler?salon_id=` | |
| `POST /ders-tekrarli-kaynaklar` | `GET /ders/tekrarli/kaynaklar?salon_id=&musteri_id=` | `kaynaklar, sablonlar` |
| `POST /ders-tekrarli-liste` | `GET /ders/tekrarli?salon_id=&musteri_id=` | |
| `POST /ders-tekrarli-kaydet` | `POST /ders/tekrarli`, `PATCH /ders/tekrarli/{id}` | `musteri_id`; `sablonlar/gunler/saatler` JSON metni değil dizi |
| `POST /ders-tekrarli-sil` | `DELETE /ders/tekrarli/{id}` | `{temizlenen}` |
| `POST /grup-dersi-rapor` | `GET /ders/rapor?salon_id=&tarih1=&tarih2=` | `ozet, egitmen, ders` |
| `POST /adisyon-odeme-isaretle` | `PATCH /adisyon/{id}/odeme {alindi}` | zaten vardı |
| `adisyon-odeme-tarihi-guncelle` (web) | `PATCH /adisyon/{id}/odeme-tarihi {tarih}` | |
| `isletmebilgi["studyo_modu"]` yorumu | `GET /studyo/politika?salon_id=` | `fiyat_gizli, odeme_modu, gizli_menuler, gizli_ozellikler, yayin_maks_hafta, katilimci_durumlari…` |
| takvimde `ders-{id}` randevuları | `GET /randevu/takvim` → `dersler[]`, `grup_dersi_aktif` | randevu listesinden ayrı anahtar |

**Müşteri uygulaması** (`auth:api`; müşteri token'dan, gövdedeki `user_id` yok sayılır):

| v1 | v2 |
|---|---|
| `GET /grup-dersleri/{salonid}` | `GET /musteri-uygulama/grup-dersleri?salon_id=` |
| `POST /grup-dersi-rezervasyon` | `POST /musteri-uygulama/grup-dersleri/{oturumId}/rezervasyon {salon_id}` |
| `POST /danisan-grup-derslerim` | `GET /musteri-uygulama/derslerim?salon_id=` |
| `POST /danisan-ders-katilim`, `/danisan-ders-geldim` | `POST /musteri-uygulama/derslerim/{katilimciId}/katilim {durum}` |
| `POST /danisan-olcumlerim` | `GET /musteri-uygulama/olcumlerim?salon_id=` |

Vücut ölçümü panel uçları `routes/v2/musteri.php` içindedir. Mini-site rezervasyon sayfası (`/grup-dersleri`) eski monolitte kalır.

## Eskiden bilinçli farklar

Eski web ve mobil kopyaları birbirinden farklıydı; web davranışı kanonik alındı. Ek düzeltmeler:

1. **Telafi korunur.** Eski kod ders iptalinde ve Rezerve'ye dönüşte telafi seansını siliyordu. v2'de hak bekleyene döner (`dusum: telafi_korundu`). Yanlışlıkla işaretlenen telafi de böylece bekleyen kalır; Seans Takibi'nden "Beklemede" ile kaldırılır.
2. **Danışan no-show'u.** Eski danışan ucu stüdyoda gelmedi'yi `geldi=1` yazıyordu; artık `geldi=0`.
3. **Mobil iptal.** Eski mobil uç iptalde hak iadesi yapmıyordu; artık tek akış var.
4. **Kiracı doğrulaması.** Eğitmen, hizmet ve müşteri salona ait olmalı. Eski uçlar denetlemiyordu.
5. **Bekleme satırı.** Bekleme/iptal satırı işaretlenemez (kapasite aşılmasın).
6. **Paket kalanı.** Kaynak listesinde kalan hizmet bazlı; eski ortalama, gerçekte olmayan seans gösterebiliyordu.
7. **Düşüm hatası.** Eski kod hatayı yutup durumu kaydediyordu; v2 işlemi geri alır.
8. **Terfi.** Plan silinince/değişince ve kapasite artınca boşalan yerlere bekleyenler terfi eder; iptal edilmiş derste terfi olmaz.
9. **Telafi çift kullanımı.** Eski kodda derse bağlı telafi, sonraki derse de bağlanabiliyordu. Bağ `ders_katilimcilar.not = telafi_bagli` ile tutulur; eski kayıtlarda bu işaret yoktur.
10. **Danışan işareti.** Danışan yalnız `rezerve` kaydını işaretler; işletmenin işaretini değiştiremez (kendine seans iadesi yapamaz).
11. **Paket aşımı.** Düşümde müşterinin adisyonları kilitlenir; eşzamanlı iki ders son seansı birlikte düşemez.

## Test

Saf Domain testleri `tests/Unit/Domain/Ders`, `tests/Unit/Domain/Studyo`. Akış testi izole sqlite ile (prod DB'ye dokunmaz):

```
php artisan test --filter='DersAkisTest|DersKurallariTest|StudyoPolitikasiTest'
```

## Mobil yolu (`mobil=1`)

Flutter işletme ekranları `/ders/*` uçlarını `mobil=1` ile çağırır; web parametreyi göndermez, davranışı değişmez. Parametre mobilin bugünkü davranışını korur:

- Yetki: yetki anahtarı ve modül kapısı yerine kimlik + salon kapsamı.
- Liste: `/ders/oturum` rol 5 için de tüm salon.
- İptal: hak iadesi yok, bekleme listesine mesaj yok.
- Çıkarma: tek kişi terfi eder, metin `rezervasyonunuz onaylandı`.
- Durum: bekleme satırı işaretlenebilir, Rezerve'ye dönüşte telafi de silinir (`iade`).
- Düzenleme ve otomatik plan: terfi yok; `saat_bitis` için `after:saat` aranmaz.
- Yayın: şablon rengi oturuma kopyalanmaz.
- Otomatik plan: paket kalanı ortalama, paket bitişi sınır değil, sınırsızda `yerlesmeyen` sayılır.
