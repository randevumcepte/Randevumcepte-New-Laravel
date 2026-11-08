# Toplu test ve geçiş

Sıra önemlidir: önce testler ayrı klonda geçer, sonra canlı klasör güncellenir, sonra salt okunur tutar karşılaştırması, en son mesaj kuyruğu açılır.

Yollar: yeni Laravel `/var/www/www-root/data/www/randevumcepteyeni`, eski Laravel `/var/www/www-root/data/www/randevumcepte`, PHP `/opt/php83/bin/php`. Komutlar `www-root` kullanıcısıyla çalışır.

```sh
YENI=/var/www/www-root/data/www/randevumcepteyeni
ESKI=/var/www/www-root/data/www/randevumcepte
PHP=/opt/php83/bin/php
```

## 1. Laravel testleri (ayrı klon, canlıya dokunmaz)

Testler bellek içi SQLite kullanır, canlı veritabanına bağlanmaz.

```sh
cd ~ && git clone git@github.com:randevumcepte/Randevumcepte-New-Laravel.git rc-toplu-test-$(date +%m%d)
cd ~/rc-toplu-test-$(date +%m%d)
$PHP $(command -v composer) install
cp .env.example .env && $PHP artisan key:generate
$PHP artisan test 2>&1 | tee ~/toplu-test.log
```

Önce bu alanlar (bu turda yazıldı, hiç koşmadı):

```sh
$PHP artisan test tests/Unit/TahsilatDagitimTest.php tests/Unit/MobilTahsilatHesabiTest.php tests/Unit/Domain/Bildirim tests/Feature/Tahsilat tests/Feature/Konsol
```

Kırmızı varsa `~/toplu-test.log` dosyasının sonunu gönderin; canlıya geçmeyin.

## 2. Canlı klasörü güncelleme

Bu turda yeni migration yok; `migrate --pretend` boş dönmelidir.

```sh
cd $YENI && git pull
$PHP $(command -v composer) install --no-dev --optimize-autoloader
$PHP artisan migrate --pretend
$PHP artisan config:clear && $PHP artisan route:clear && $PHP artisan view:clear
```

`.env` kontrolü: `APP_ENV=production`, `APP_DEBUG=false`.

## 3. Tutar karşılaştırması (salt okuma, salon 20)

Eski mobil uç ile yeni uç aynı girdilerle çağrılır, veritabanına yazılmaz. Token kabuk geçmişine girmez.

```sh
cd $YENI
TEL=5XXXXXXXXX   # salon 20 işletme yetkilisi
curl -s -X POST https://api.randevumcepte.com.tr/api/v2/oturum/kod-iste -H 'Content-Type: application/json' \
  -d "{\"cep_telefon\":\"$TEL\",\"hesap_turu\":\"isletme\",\"app_bundle\":\"com.randevumcepte.randevumcepteprivate\"}"
read -s -p 'Gelen kod: ' KOD; echo
export MOBIL_KARSILASTIR_TOKEN=$(curl -s -X POST https://api.randevumcepte.com.tr/api/v2/oturum/kod-dogrula \
  -H 'Content-Type: application/json' \
  -d "{\"cep_telefon\":\"$TEL\",\"kod\":\"$KOD\",\"hesap_turu\":\"isletme\",\"app_bundle\":\"com.randevumcepte.randevumcepteprivate\"}" \
  | $PHP -r 'echo json_decode(stream_get_contents(STDIN), true)["veri"]["token"] ?? "";')
[ -n "$MOBIL_KARSILASTIR_TOKEN" ] && echo token-alindi || echo TOKEN-YOK

$PHP artisan mobil:tutar-karsilastir 20 --bas=2026-10-01 --bit=2026-10-31 \
  --sunucu=https://api.randevumcepte.com.tr \
  --deger=musteri_id=<id> --deger=personel_id=<id> --deger=adisyon_id=<id> 2>&1 | tee ~/tutar-karsilastir.log
unset MOBIL_KARSILASTIR_TOKEN
```

`<id>` değerleri salon 20'de verisi olan bir müşteri, personel ve adisyondur. Beklenen sonuç:
- Okuma kontrolleri (liste, rapor, kasa, prim, özet) eşit çıkmalı.
- "bilinçli fark: web kuralı" diye işaretli kontrollerde fark beklenir: eski mobilin kuruş, kalan sınırı ve vade hataları düzeltildi.
- "ATLANDI" eşitlik kanıtı değildir; nedeni satırda yazar.
- Eski uç 401 verirse token eski sistemde geçmiyordur; çıktıyı gönderin.

## 4. Mesaj kuyruğu geçişi

Eski sunucuda hiçbir satır durdurulmaz. Bayrak kapalıyken davranış bugünle aynıdır.

```sh
# Firebase anahtarları (git'e girmez)
mkdir -p $YENI/storage/app/firebase
cp -p $ESKI/storage/app/firebase/*.json $YENI/storage/app/firebase/
chown -R www-root:www-root $YENI/storage/app/firebase && chmod 600 $YENI/storage/app/firebase/*.json
```

`.env` (değerler eski sunucudan; eski kodda sistem SMS anahtarı kaynakta sabit yazılıdır):

```
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=1800
BILDIRIM_KUYRUK_ADI=v2_bildirim
SMS_EFETECH_SISTEM_APIKEY=...
WHATSAPP_SERVICE_TOKEN=...
WHATSMEOW_SERVICE_URL=...      # eski .env ile aynı
BILDIRIM_ISLEYICI_AKTIF=false  # şimdilik kapalı
```

Zamanlayıcı (www-root crontab):

```
* * * * * cd /var/www/www-root/data/www/randevumcepteyeni && /opt/php83/bin/php artisan schedule:run >> /dev/null 2>&1
```

Toplu işler için worker (supervisor). `hatirlatmalar` kuyruğunu eski worker dinler, kullanılmaz:

```ini
[program:randevumcepte-bildirim-v2]
command=/opt/php83/bin/php artisan queue:work database --queue=v2_bildirim --tries=1 --timeout=1700 --sleep=3
directory=/var/www/www-root/data/www/randevumcepteyeni
user=www-root
autostart=true
autorestart=true
stopwaitsecs=1800
stdout_logfile=/var/www/www-root/data/www/randevumcepteyeni/storage/logs/bildirim-worker.log
redirect_stderr=true
```

```sh
supervisorctl reread && supervisorctl update && supervisorctl status   # randevumcepte-sms-v2 de RUNNING olmalı
cd $YENI && $PHP artisan config:clear && $PHP artisan v2:bildirim-isle --kontrol   # "Kurulum tamam." görülmeli
```

Açma:

```sh
sed -i 's/^BILDIRIM_ISLEYICI_AKTIF=.*/BILDIRIM_ISLEYICI_AKTIF=true/' $YENI/.env
cd $YENI && $PHP artisan config:clear && $PHP artisan queue:restart
```

İzleme (ilk saat):

```sh
tail -f $YENI/storage/logs/laravel.log | grep -i 'bildirim-isle\|SMS\|WA\]'
mysql -e "SELECT durum, tur, COUNT(*) FROM v2_bildirim_kuyrugu WHERE created_at > NOW() - INTERVAL 1 HOUR GROUP BY durum, tur" <veritabani>
```

`durum`: 6 bekliyor, 7 işleniyor, 1 gönderildi, 2 kalıcı hata (`hata` kolonunda neden). 0 ve 3 eski işleyicinindir.

Geri dönüş: `BILDIRIM_ISLEYICI_AKTIF=false`, `config:clear`. 6'da kalan satırlar bayrak yeniden açılınca gider.

## 5. Uygulama testleri (yerelde, Claude)

iPhone 17 Pro Max simülatörü, tablet yatay ve Android derlemesi. Salon 20'de, yazma testleri yalnız bu salonda.

- Giriş: işletme ve müşteri kodla giriş, inceleme hesapları, oturum yenileme.
- Takvim ve randevu: ekle, düzenle, taşı, onayla, iptal et, reddet; mesajın WhatsApp'tan, WhatsApp yoksa SMS'ten gelmesi.
- Müşteri: liste, detay, not (yetkili ve yetkisiz), sağlık formu, kara liste.
- Satış: Yeni Satış (hizmet, ürün, paket), Stok > Hızlı Satış (müşteri seçimi).
- Tahsilat: kalem tahsilatı, paketli taksit, taksit vadesi ödemesi (tam ve kısmi), kampanya kodu; tutarların kuruşu kuruşuna tutması.
- Kasa, raporlar, prim, ana ekran özeti.
- Bildirim: push gelişi (bayrak açıldıktan sonra), uygulama içi bildirim.
- Eski uca çağrı kalmadığının kontrolü (`test/core/eski_uc_taramasi_test.dart`).

## Toplu testten sonra

Eski şifre kayıtlarını maskeleme (`v2:sifre-maskele --gecmis`, geri alınamaz), tipsiz token kapısı (`OTURUM_TIPSIZ_TOKEN_KABUL=false`), tek adımlı şifre gönderimi, resim ve logo taşıma, Google Takvim, ana ekran notu, aynı telefonda çok kayıt, `ios_profil`, Flutter git bağlantısı.
