# v2 bildirim kuyruğu geçişi

Yeni Laravel `v2_bildirim_kuyrugu` satırlarını kendi `v2:bildirim-isle` komutuyla gönderir. Eski monolite dokunulmaz.

| `durum` | Kim işler |
|---|---|
| 0, 3 | Eski monolit (bayrak kapalıyken yazılan satırlar) |
| 6, 7 | Yeni Laravel (bayrak açıkken yazılan satırlar) |
| 4, 5 | `sms:kuyruk-isle` |
| 1, 2 | Sonuç: gönderildi, kalıcı hata |

Bayrak kapalıyken davranış bugünle aynıdır. Geçiş bayrağı açmaktır; geri dönüş kapatmaktır. Bayrak kapatılınca 6'da bekleyen satırlar bayrak yeniden açılana kadar bekler.

## Açmadan önce
1. Firebase JSON dosyaları `storage/app/firebase` altına kopyalanır (git'e girmez).
2. `.env`: `SMS_EFETECH_SISTEM_APIKEY`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `WHATSAPP_SERVICE_TOKEN`, `DB_QUEUE_RETRY_AFTER=1800`. Değerler eski sunucudan alınır.
3. Cron: `* * * * * php artisan schedule:run`.
4. Worker: `php artisan queue:work --queue=v2_bildirim --tries=1 --timeout=1700`. `hatirlatmalar` kullanılmaz; o kuyruğu eski worker dinler.
5. `php artisan v2:bildirim-isle --kontrol` temiz dönmelidir. Eksik varsa bayrak açık olsa da komut hiçbir satırı işlemez.

## Açma
`.env`: `BILDIRIM_ISLEYICI_AKTIF=true`, ardından `php artisan config:clear` ve `php artisan queue:restart`.

## Eski koddan korunan bilinçli hatalar
Metin ve yol birebir taşındı. Ayrıntı: kara liste telefon araması, rapor adımında mükerrer SMS, açık şifre kayıtları. Düzeltme kararı kullanıcıdadır.
