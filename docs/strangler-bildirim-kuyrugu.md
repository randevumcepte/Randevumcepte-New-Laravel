# v2 bildirim kuyruğu geçişi

Yeni Laravel `v2_bildirim_kuyrugu` satırlarını kendi `v2:bildirim-isle` komutuyla gönderir. Eski monolite dokunulmaz.

| `durum` | Kim işler |
|---|---|
| 0, 3 | Eski monolit (bayrak kapalıyken yazılan satırlar) |
| 6, 7 | Yeni Laravel (bayrak açıkken yazılan satırlar) |
| 4, 5 | `sms:kuyruk-isle` |
| 1, 2 | Sonuç: gönderildi, kalıcı hata |

Google Takvim (`google_takvim`) bayrak açıkken de eski monolitte kalır (`eski_isleyicide_kalan`); entegrasyon en sona bırakıldı. Bayrak kapalıyken davranış bugünle aynıdır. Geçiş bayrağı açmaktır; geri dönüş kapatmaktır. Bayrak kapatılınca 6'da bekleyen satırlar bayrak yeniden açılana kadar bekler.

## Açmadan önce
1. Firebase JSON dosyaları `storage/app/firebase` altına kopyalanır (git'e girmez).
2. `.env`: `SMS_EFETECH_SISTEM_APIKEY`, `WHATSAPP_SERVICE_TOKEN`, `DB_QUEUE_RETRY_AFTER=1800`. Değerler eski sunucudan alınır.
3. Cron: `* * * * * php artisan schedule:run`.
4. Worker: `php artisan queue:work --queue=v2_bildirim --tries=1 --timeout=1700`. `hatirlatmalar` kullanılmaz; o kuyruğu eski worker dinler.
5. `php artisan v2:bildirim-isle --kontrol` temiz dönmelidir. Eksik varsa bayrak açık olsa da komut hiçbir satırı işlemez.

## Açma
`.env`: `BILDIRIM_ISLEYICI_AKTIF=true`, ardından `php artisan config:clear` ve `php artisan queue:restart`.

## Eskiden bilerek farklı olanlar
- Kara liste telefondan aranırken `905…`, `05…` ve `5…` biçimlerinin üçü de aranır (eskisi `5…` kayıtlıyı kaçırıyordu).
- Mükerrer SMS yok: sağlayıcı mesajı kabul ettiyse ya da zaman aşımı olduysa rapor hatası yeniden denetmez. WhatsApp'tan giden mesaj ayrıca SMS olarak gitmez.
- Şifre: `salon_personelleri.sifre` yazılmaz. SMS raporunda şifre yazılırken, WhatsApp kaydında sonuçlandıktan sonra maskelenir (`v2:sifre-maskele`, saatlik).
- Personel metinleri: onayda "yarın" yerine randevu günü; kayıtta "... tarafından oluşturulmuştur." (müşteri cümlesi yerine).
- Müşteri fotoğrafı: bayrak açıkken personele push gider (v1 gibi).

## Geçmiş kayıtlar (bir kez, elle, geri alınamaz)
`php artisan v2:sifre-maskele --gecmis`: tüm WhatsApp ve SMS kayıtlarındaki eski şifreleri maskeler, `salon_personelleri.sifre` kolonunu boşaltır.
