# Strangler — SMS Yönetimi (v2)

Eski `/isletmeyonetim/toplusms` ekranının (`isletmeadmin/toplusmsgonder.blade`, `custom.js` parçaları,
`StoreAdminController::toplusmsgonderme|sms_ayar_kaydet|sms_raporlari_sayfali|…`) ve mobil v1
`/api/v1/sms-yonetim/*` uçlarının v2 karşılığı. Web paneli sekmeli `/v2/sms-yonetimi` sayfasıdır
(`?p=raporlar|toplu|ayarlar|kara_liste`). Web ve Flutter **aynı** `/api/v2/sms/*` uçlarını kullanır.
Yazmalar `SMS_V2_YAZMA_AKTIF` (varsayılan açık) arkasındadır; kapalıyken 409 döner.

Flutter'a dokunulmadı: uygulama v1 uçlarını kullanmaya devam eder, v1 uçları kaldırılmadı.

## Katmanlar

| Katman | Dosya | Sorumluluk |
|---|---|---|
| Domain (saf) | `Domain/Sms/SmsTuru.php` | tür ↔ `sms_iletim_raporlari.tur` ↔ sağlayıcı paket başlığı |
| Domain (saf) | `Domain/Sms/SmsDurumu.php` | paket + alıcı durum etiketleri, VoiceTelekom durum eşlemesi |
| Domain (saf) | `Domain/Sms/MesajUzunlugu.php` | karakter → SMS adedi (eşikler istemciye açılışta verilir) |
| Domain (saf) | `Domain/Sms/SmsAyarKatalogu.php` | 23 ayar kartı, hedefler, WhatsApp eşlenikleri, yazılacak kolon kuralı |
| Domain (saf) | `Domain/Sms/SmsSaglayici.php` | `yeni_sms` → sağlayıcı seçimi, hesap tanımlı mı |
| Domain (saf) | `Domain/Sms/SmsSekmeleri.php` | sekme kayıt defteri, görünürlük, yasal uyarı metni |
| Hesap | `Services/Sms/SmsHesapServisi.php` | salonun SMS satırı (kimlik alanları API'ye çıkmaz) |
| Bakiye | `Services/Sms/Bakiye/*` | sağlayıcı stratejisi + önbellek (header rozeti ile ortak) |
| Rapor | `Services/Sms/Rapor/*` | `SmsRaporSaglayici` stratejisi: `VoiceTelekomRapor`, `EfetechRapor`; `SmsRaporServisi` ortak zenginleştirme |
| Alıcı | `Services/Sms/SmsAliciServisi.php` | seçici listesi, tümünü seç, kimlik → gönderilebilir telefon |
| Gönderim | `Services/Sms/SmsGonderimServisi.php` | önizleme, tekrar kilidi, kuyruğa ekleme |
| Kuyruk | `Services/Sms/Gonderim/*`, `Console/Commands/SmsKuyrukIsle.php`, `Domain/Sms/SmsKuyrukDurumu.php` | `sms:kuyruk-isle`, VoiceTelekom gönderimi |
| Şablon | `Services/Sms/SmsSablonServisi.php` | `sms_taslaklari` (sistem şablonları salt okunur) |
| Ayar | `Services/Sms/SmsAyarServisi.php` | `salon_sms_ayarlari` + `randevu_sms_hatirlatma` |
| Kara liste | `Services/Sms/SmsKaraListeServisi.php` | liste; yazma `MusteriYazServisi::karaListe` (tek yazma yolu) |
| Açılış | `Services/Sms/SmsAcilisServisi.php` | sekmeler, yetkiler, sözlükler, bakiye, şablonlar, ayarlar |
| HTTP | `Controllers/Api/V2/Sms*Controller.php`, `routes/v2/sms.php` | taban: yetki kapıları + kill-switch |
| Web | `views/v2/sms-yonetimi.blade.php`, `public/js/sms-yonetimi-v2.js`, `public/js/sms/*.js`, `public/css/sms-yonetimi-v2.css` | build'siz island |
| Eski repo | payload MEDIUMTEXT migration (`sms_toplu` tüketici + `SmsTopluGonderJob` KULLANILMIYOR, bkz. Gönderim) | |

## Yetki

| İşlem | Kapı |
|---|---|
| Açılış, raporlar, şablonlar, kara liste | `pazarlama.sms_gonder` **veya** `pazarlama.toplu_sms` (eski sayfa kapısı) |
| Alıcı listesi, önizleme, gönderim | `pazarlama.toplu_sms` (eski `toplusmsgonderme`) |
| Ayarlar | hesap sahibi (rol 1, eski blade koşulu) |
| Açık telefon | `musteri.telefon_gor`; yoksa maskeli |
| Müşteri kapsamı | `musteri.tum_portfoy_gor` yoksa kendi portföyü |

## v1 → v2 eşleme (sonraki Flutter geçişi için)

Tüm cevaplar `{basarili, veri, mesaj}` zarfında; alan adları aşağıda belirtilmedikçe v1 ile aynı.

| v1 (`/api/v1`) | v2 (`/api/v2`) | Fark |
|---|---|---|
| `GET sms-yonetim/init/{salon}?userId=` | `GET sms/acilis?salon_id=` | `isAdmin` → `yetkiler.ayar` (token'dan); `ayarlar` + `randevu_sms_hatirlatma` → `ayar{}` (kart tanımları dahil); `yeni_sms` bool; `kalan_sms` okunamazsa null; ek: `sekmeler`, `rapor_turleri`, `parca_esikleri`, `yasal_uyari` |
| `POST sms-yonetim/musteri-listele/{salon}` `{page, perPage, search, cinsiyet}` | `GET sms/alicilar?salon_id=&page=&per_page=&ara=&cinsiyet=` | `customers` → `data`; ek alan `kara_liste` |
| aynı uçtaki `musteriIdler` | `GET sms/alicilar/idler` | yalnız "tümünü seç" anında çekilir |
| `POST sms-yonetim/toplu-gonder/{salon}` `{musteri_idler, smsmesaj}` | `POST sms/gonderim` `{salon_id, musteri_idler, mesaj, tur:'toplu'}` | 202 "kuyruğa alındı"; `status/title/text` → standart zarf + özet |
| `POST sms-yonetim/filtreli-gonder/{salon}` `{musteri_idler, filtre_sms}` | `POST sms/gonderim` `{…, tur:'filtre'}` | aynı |
| — | `POST sms/gonderim/onizle` | onay kutusu için kişi/kredi/bakiye |
| `POST sms-yonetim/taslak-kaydet/{salon}` | `POST sms/sablonlar` · `PATCH sms/sablonlar/{id}` | `veri.taslaklar`; satırda `salon_id` yerine `duzenlenebilir` |
| `POST sms-yonetim/taslak-sil` | `DELETE sms/sablonlar/{id}` | |
| `GET sms-yonetim/raporlar/{salon}` (6 tür tek cevapta) | `GET sms/raporlar?tur=&page=&per_page=&ara=&sirala=&yon=&yenile=` | tür başına sayfalı; satırda ek `toplam_kredi`, `durum_etiket`, `durum_renk`; `uyari` |
| `POST sms-yonetim/rapor-detay/{salon}` `{pkg_id}` | `GET sms/raporlar/{paket}` | `veri.kayitlar`, hata `veri.uyari` |
| `POST sms-yonetim/ayar-kaydet/{salon}` | `PATCH sms/ayarlar` | yalnız kartın hedefi yazılır; bilinmeyen `ayar_id` 422 |
| `GET sms-yonetim/karaliste/{salon}` | `GET sms/kara-liste` + ListeIstegi | `kayitlar` → `data` |
| `POST sms-yonetim/karaliste-ekle/{salon}` | `POST sms/kara-liste` `{user_id}` | portföyde yoksa 404 (v1 yeni portföy satırı açıyordu); "kara listeye alındınız" bildirimi gider (eski web gibi) |
| `POST sms-yonetim/karaliste-sil/{salon}` | `DELETE sms/kara-liste/{userId}` | |
| `GET sms-yonetim/bakiye/{salon}` | `GET sms/bakiye` | |

## Bilerek değişenler

- **Gönderim asenkron:** cevap "kuyruğa alındı"; ekran sonucu `GET /sms/gonderim/{id}` ile izleyip gösterir.
- **Kara liste her yolda elenir:** eski web `yeni_sms=1` yolunda elemiyordu (v1 API eliyordu).
- **Çift gönderim kilidi:** aynı mesaj + aynı alıcılar 120 sn içinde 409 (`sms.tekrar_kilit_sn`).
- **Raporlar önbellekli:** VoiceTelekom paketleri türe ayrılıp 5 dk önbelleklenir (eski kod her tablo
  sayfasında 20.000'e kadar paket indiriyordu). "Yenile" önbelleği boşaltır; yeni gönderim en geç 5 dk sonra görünür.
- **Sağlayıcı hatası görünür:** eski ekran boş tablo gösteriyordu; v2 `uyari` döner.
- **Rapor detayında telefon maskelenir** (`musteri.telefon_gor` yoksa); eski detay açık döndürüyordu.
- **Toplam kredi:** VoiceTelekom'da sağlayıcının kredisi doğrudan (eski: yuvarlanmış birim × adet).
- **Ayarlar `ayar_id` ile okunur:** eski blade satır sırasına (`$sms_ayarlari[15]`) bağlıydı.
- **Web'de şablon düzenle/sil eklendi** (uç ve mobil davranış zaten vardı).
- Toplu SMS formundaki işlevsiz "Şablon Adı" kutusu kaldırıldı (gönderimde kullanılmıyordu).

## Taşınmayanlar

- **Kara liste modülünü aç/kapat** (`kara_liste_aktif`): v1 `ayar-kaydet` destekliyor ama ne web ne Flutter
  ekranı çağırıyor; kapatınca tüm kara listeyi sildiği için ekransız taşınmadı. Açılış değeri okur.
- **Grup SMS yönetimi:** eski ekranda arayüzü yok; yalnız raporu gösteriliyor.
- **Filtreli SMS web sekmesi:** eski web'de yoktu. Uç (`tur=filtre`, `cinsiyet`) hazır; Flutter'daki
  `filtreli_sms_tab.dart` de şu an sayfaya bağlı değil.

## Gönderim

Canlı monolit (`randevumcepte`) donuk ve bu iş için dosyalarına **dokunulmaz** (kullanıcı kararı, 4 Kas 2026).
Bu yüzden gönderim v2'nin kendi işleyicisiyle yapılır: kayıtlar `v2_bildirim_kuyrugu` tablosuna `durum=4` ile
yazılır; eski `v2:bildirim-isle` yalnız 0 ve 3 ile çalıştığı için bu kayıtları görmez.

- Şimdilik **yalnız VoiceTelekom**. Efetech salonları 422 alır, eski panelden gönderir (WhatsApp önceliği eski kodda).
- Tekrar deneme yok; 30 dk içinde işlenmeyen kayıt iptal edilir, yarıda kalan kapatılır.
- Sonuç: `GET /sms/gonderim/{id}`, log `[SMS-V2]` → `randevumcepteyeni/storage/logs`.

Kurulum: supervisor (`www-root` kullanıcısıyla, root log dosyası yaratmasın). Komut ~55 sn'de bir çıkar,
`autorestart` yeniden başlatır → yeni kod kendiliğinden yüklenir. Tek süreç (`numprocs=1`); `stopwaitsecs`
gönderim zaman aşımından (120 sn) büyük olmalı, yoksa durdurma paketi yarıda keser.

```
[program:randevumcepte-sms-v2]
command=/opt/php83/bin/php artisan sms:kuyruk-isle
directory=/var/www/www-root/data/www/randevumcepteyeni
user=www-root
numprocs=1
autostart=true
autorestart=true
startsecs=5
stopwaitsecs=150
stdout_logfile=/var/www/www-root/data/www/randevumcepteyeni/storage/logs/sms-worker.log
redirect_stderr=true
```

Canlı güncellendiğinde karar verilecek: bu işleyici kalıcı mı olacak, yoksa eski repodaki `SmsTopluGonderJob` yoluna mı dönülecek.

## Test

- `tests/Unit/Domain/Sms/SmsDomainTest.php` (DB'siz).
