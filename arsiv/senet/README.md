# Senet (arşiv)

Senet sistemi şu an kullanılmıyor (kullanıcı kararı, 2026-11-08). Mobil senet yazma kodu burada saklanır; çalışmaz, autoload dışındadır.

| Dosya | Eski yeri |
|---|---|
| `MobilSenetServisi.php` | `app/Services/Tahsilat/Mobil/` |
| `TahsilatMobilController-senet.php` | `TahsilatMobilController` metotları ve `routes/v2/mobil-para.php` satırları |

Flutter tarafı: `lib/moduller/tahsilat/tahsilat_api.dart` içindeki `senetCevabi`, `senetOdeCevabi`, `senetVadeGuncelleCevabi` ağa çıkmaz; asıl kod yorum satırı olarak orada durur.

Etkin kalanlar: Alacaklar ekranındaki Senetler sekmesinin liste okuması (`GET /mobil-para/senetler`), kayıtlı senet verisini okuyan raporlar ve tahsilat hesabı.

## Geri açmak
1. `MobilSenetServisi.php` eski yerine taşınır, ilk yorum satırı silinir.
2. Metotlar controller'a, rota satırları `routes/v2/mobil-para.php`'ye geri eklenir.
3. Flutter'daki üç fonksiyonun yorum satırındaki kodu geri açılır.
4. Bekleyen kararlar: ön ödemeli senet (eski kod 500 veriyordu), paket seansında sabit `personel_id=183` (yalnız senet akışında).
