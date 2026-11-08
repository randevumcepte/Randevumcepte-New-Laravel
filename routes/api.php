<?php

use App\Http\Controllers\Api\V2\AdisyonController;
use App\Domain\Asistan\GorevTuru;
use App\Http\Controllers\Api\V2\AlacakController;
use App\Http\Controllers\Api\V2\ArsivController;
use App\Http\Controllers\Api\V2\AsistanController;
use App\Http\Controllers\Api\V2\FormSablonController;
use App\Http\Controllers\Api\V2\KabukController;
use App\Http\Controllers\Api\V2\KabukDurumController;
use App\Http\Controllers\Api\V2\KatalogController;
use App\Http\Controllers\Api\V2\PersonelController;
use App\Http\Controllers\Api\V2\PersonelYetkiController;
use App\Http\Controllers\Api\V2\RandevuController;
use App\Http\Controllers\Api\V2\RandevuDurumController;
use App\Http\Controllers\Api\V2\RandevuFormController;
use App\Http\Controllers\Api\V2\RandevuKayitController;
use App\Http\Controllers\Api\V2\RandevuTakvimController;
use App\Http\Controllers\Api\V2\RandevuTelafiController;
use App\Http\Controllers\Api\V2\SatisController;
use App\Http\Controllers\Api\V2\SantralController;
use App\Http\Controllers\Api\V2\SoftphoneController;
use App\Http\Controllers\Api\V2\SatisKalemController;
use App\Http\Controllers\Api\V2\TahsilatController;
use App\Http\Controllers\Api\V2\TelefonController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v2 — Strangler Geçiş Uçları
|--------------------------------------------------------------------------
|
| KVKK / veri koruma ilkesi (ZORUNLU):
|   - KİŞİSEL VERİ içeren HER uç 'auth:isletmeyonetim-api' (legacy-passport)
|     arkasindadir + controller icinde GuvenlikKapisi ile KAPSAM (isletme/sube)
|     + YETKI denetimi yapar. Varsayilan KORUMALI; yeni uclar korumali gruba
|     eklenir. Yalnizca kisisel veri ICERMEYEN uclar (health) public olabilir.
|   - Veri minimizasyonu: Resource/servis yalnizca gereken alanlari dondurur
|     (ham model dokumu YOK). Telefon vb. hassas alan maskelenir (musteri.telefon_gor).
|
| Hem web paneli (Vue) hem Flutter AYNI uclari tuketir; is mantigi servislerde.
|
*/

Route::prefix('v2')->group(function () {

    /*
     | PUBLIC — kisisel veri ICERMEZ (yalnizca servis + DB sagligi).
     */
    // Imzali bos form PDF linki (kisisel veri yok).
    require __DIR__.'/v2/form-acik.php';

    Route::get('/health', function () {
        $veritabani = 'down';

        try {
            DB::connection()->getPdo();
            $veritabani = 'up';
        } catch (\Throwable $e) {
            $veritabani = 'error';
        }

        return response()->json([
            'servis'     => 'randevumcepte-v2',
            'durum'      => $veritabani === 'up' ? 'ok' : 'degraded',
            'veritabani' => $veritabani,
            'surum'      => 'v2',
        ], $veritabani === 'up' ? 200 : 503);
    });

    // Telefon ulke kodlari + bicimleri (web + Flutter tek kaynak; kisisel veri yok).
    Route::get('/telefon/ulkeler', [TelefonController::class, 'ulkeler']);

    // ISLETME VITRINI + MUSTERI OTURUMU (kimliksiz, kisisel veri dondurmez) — routes/v2/vitrin.php
    require __DIR__.'/v2/vitrin.php';

    // SES DOSYASI (token yerine sureli imza; ses calar baslik gonderemez) — routes/v2/ses-dosyasi.php
    require __DIR__.'/v2/ses-dosyasi.php';

    // MOBIL KONTOR WEBVIEW (token yerine sureli imza) — routes/v2/whatsapp-kontor-sayfa.php
    require __DIR__.'/v2/whatsapp-kontor-sayfa.php';

    // GIRIS + SIFRE SIFIRLAMA + UCRETSIZ DENEME (web + Flutter tek uc) — routes/v2/oturum.php
    require __DIR__.'/v2/oturum.php';

    // SISTEM YONETIMI (ayri kimlik: sistemyoneticileri, guard sistemyonetim-api) — routes/v2/sistem.php
    require __DIR__.'/v2/sistem.php';

    /*
     | KORUMALI — kisisel veri iceren TUM uclar buraya. Kimlik ZORUNLU.
     | Kapsam + yetki her controller'da GuvenlikKapisi ile uygulanir.
     */
    Route::middleware('auth:isletmeyonetim-api')->group(function () {
        // Panel kabugu (sol menu + header verisi) — v2 ekranlarinin ortak shell'i.
        Route::get('/kabuk', [KabukController::class, 'goster']);
        // Header rozetleri: kalan SMS + WhatsApp durumu (personel rolune null) + WebRTC softphone.
        Route::get('/kabuk/sms', [KabukDurumController::class, 'sms']);
        Route::get('/kabuk/whatsapp', [KabukDurumController::class, 'whatsapp']);
        Route::get('/softphone', [SoftphoneController::class, 'goster']);
        Route::get('/softphone/dakika', [SoftphoneController::class, 'dakika']);
        Route::get('/softphone/arayan', [SoftphoneController::class, 'arayan']);
        Route::get('/adisyon/{id}', [AdisyonController::class, 'goster'])->whereNumber('id');

        // RANDEVU (Faz 1 — SALT OKUMA). Yazma yollari sonraki fazlarda.
        Route::get('/randevu/takvim', [RandevuController::class, 'takvim']);
        Route::get('/randevu/{id}', [RandevuController::class, 'goster'])->whereNumber('id');

        // RANDEVU DURUM (Faz 2) — varsayilan dry_run; gercek yazma
        // config('randevu.yazma_aktif') kill-switch arkasinda (kapaliyken 409).
        Route::post('/randevu/{id}/geldi', [RandevuDurumController::class, 'geldi'])->whereNumber('id');
        Route::post('/randevu/{id}/gelmedi', [RandevuDurumController::class, 'gelmedi'])->whereNumber('id');
        Route::delete('/randevu/{id}/geldi-isareti', [RandevuDurumController::class, 'isaretKaldir'])->whereNumber('id');
        // SEANS TELAFISI (paket seansi telafi hakkina) — telafi RANDEVUSU'ndan ayri.
        Route::post('/randevu/{id}/seans-telafisi', [RandevuDurumController::class, 'seansTelafisi'])->whereNumber('id');
        Route::post('/randevu/{id}/iptal', [RandevuDurumController::class, 'iptal'])->whereNumber('id');
        Route::post('/randevu/{id}/tahsilat', [RandevuDurumController::class, 'tahsilat'])->whereNumber('id');

        // TELAFI RANDEVUSU (A tipi: ucretsiz duzeltme + prim devri) — ayni kill-switch.
        Route::get('/randevu/telafi-secenekler', [RandevuTelafiController::class, 'secenekler']);
        Route::post('/randevu/kalem/{rh}/telafi-randevusu', [RandevuTelafiController::class, 'olustur'])->whereNumber('rh');

        // TAKVIM YAZMA (Faz 3a) — saat kapama + surukle-birak/boyutlandir; ayni kill-switch.
        Route::post('/randevu/saat-kapama', [RandevuTakvimController::class, 'saatKapamaEkle']);
        Route::delete('/randevu/saat-kapama/{id}', [RandevuTakvimController::class, 'saatKapamaSil'])->whereNumber('id');
        Route::patch('/randevu/kalem/{rh}/tasi', [RandevuTakvimController::class, 'tasi'])->whereNumber('rh');

        // RANDEVU OLUSTUR / GUNCELLE (Faz 3b, isletme) — cakisma + seans havuzu; ayni kill-switch.
        Route::get('/randevu/form-verisi', [RandevuFormController::class, 'formVerisi']);
        Route::get('/randevu/musteri-ara', [RandevuFormController::class, 'musteriAra']);
        Route::get('/randevu/musteri-paketleri', [RandevuFormController::class, 'musteriPaketleri']);
        Route::post('/randevu', [RandevuKayitController::class, 'olustur']);
        Route::patch('/randevu/{id}', [RandevuKayitController::class, 'guncelle'])->whereNumber('id');
        Route::post('/randevu/{id}/onayla', [RandevuDurumController::class, 'onayla'])->whereNumber('id');

        Route::get('/satislar', [SatisController::class, 'index']);
        // SATIS TAKIBI satir islemleri — yazmalar TAHSILAT_V2_YAZMA_AKTIF arkasinda (kapaliyken 409).
        Route::patch('/satislar/faturasiz-gizle', [SatisController::class, 'faturasizGizle']);
        Route::delete('/adisyon/{id}', [SatisController::class, 'sil'])->whereNumber('id');
        Route::patch('/adisyon/{id}/fatura', [SatisController::class, 'fatura'])->whereNumber('id');
        Route::patch('/adisyon/{id}/odeme', [SatisController::class, 'odeme'])->whereNumber('id');
        Route::patch('/adisyon/{id}/odeme-tarihi', [SatisController::class, 'odemeTarihi'])->whereNumber('id');
        Route::get('/katalog', [KatalogController::class, 'index']);
        // YAZMA — su an yalnizca DRY-RUN (controller gercek yazmayi reddeder).
        Route::post('/tahsilat', [TahsilatController::class, 'ekle']);
        // DUZENLE (iade+yeniden yaz, atomik) — flag korumali (satis.tahsilat_al).
        Route::patch('/tahsilat/{id}', [TahsilatController::class, 'guncelle'])->whereNumber('id');
        // IADE/SILME — flag korumali (satis.tahsilat_sil).
        Route::delete('/tahsilat/{id}', [TahsilatController::class, 'sil'])->whereNumber('id');

        // SATIS KALEM yonetimi (Satis & Tahsilat Duzenleme) — flag korumali.
        // YENİ SATIŞ: müşteri + ilk kalemle adisyon açar (boş adisyon yok) — satis.adisyon_olustur.
        Route::post('/adisyon', [SatisKalemController::class, 'adisyonAc']);
        Route::post('/adisyon/{adisyon}/kalem', [SatisKalemController::class, 'ekle'])->whereNumber('adisyon');
        Route::patch('/kalem/urun/{id}', [SatisKalemController::class, 'urunAdet'])->whereNumber('id');
        Route::patch('/kalem/hizmet/{id}/seans', [SatisKalemController::class, 'seans'])->whereNumber('id');
        Route::patch('/kalem/{tip}/{id}/fiyat', [SatisKalemController::class, 'fiyat'])->where('tip', 'hizmet|urun|paket')->whereNumber('id');
        Route::patch('/kalem/{tip}/{id}/personel', [SatisKalemController::class, 'personel'])->where('tip', 'hizmet|urun|paket')->whereNumber('id');
        Route::delete('/kalem/{tip}/{id}', [SatisKalemController::class, 'sil'])->where('tip', 'hizmet|urun|paket')->whereNumber('id');
        // Paketi Yükselt (yerinde dönüştürme) — web ve mobil aynı uçlar; yazma tahsilat bayrağı arkasında.
        Route::get('/kalem/paket/{id}/yukseltme-adaylari', [SatisKalemController::class, 'yukseltmeAdaylari'])->whereNumber('id');
        Route::post('/kalem/paket/{id}/yukselt', [SatisKalemController::class, 'paketYukselt'])->whereNumber('id');

        // PLANLANAN ALACAK (receivable) — flag korumali (finans.alacak_yonet).
        Route::post('/alacak', [AlacakController::class, 'ekle']);
        Route::patch('/alacak/{id}', [AlacakController::class, 'guncelle'])->whereNumber('id');
        Route::delete('/alacak/{id}', [AlacakController::class, 'sil'])->whereNumber('id');

        /*
         | PERSONEL YONETIMI (Faz 1 — SALT OKUMA). Her uc GuvenlikKapisi ile
         | kapsam + yetki denetler. Yetkiye gore parcali: liste_gor / yetki_yonet.
         | Yazma uclari (ekle/duzenle, durum, siralama, takvim, saat, arsivle,
         | yetki kaydet) Faz 2'de config('personel.yazma_aktif') kill-switch arkasinda.
         */
        Route::get('/personel', [PersonelController::class, 'liste']);
        Route::get('/personel/form-verisi', [PersonelController::class, 'formVerisi']);
        Route::get('/personel-yetki/sema', [PersonelYetkiController::class, 'sema']);
        Route::get('/personel-yetki/benim', [PersonelYetkiController::class, 'benimYetkilerim']);
        Route::get('/personel/{id}', [PersonelController::class, 'goster'])->whereNumber('id');
        Route::get('/personel/{id}/calisma-saatleri', [PersonelController::class, 'calismaSaatleri'])->whereNumber('id');
        Route::get('/personel/{id}/mola-saatleri', [PersonelController::class, 'molaSaatleri'])->whereNumber('id');
        Route::get('/personel/{id}/prim-kalemleri', [PersonelController::class, 'primKalemleri'])->whereNumber('id');
        Route::get('/personel/{id}/gelecek-hizmetler', [PersonelController::class, 'gelecekHizmetler'])->whereNumber('id');
        Route::get('/personel/{id}/yetki', [PersonelYetkiController::class, 'getir'])->whereNumber('id');

        /*
         | PERSONEL YAZMA (Faz 2 — config('personel.yazma_aktif') kill-switch arkasinda;
         | kapaliyken 409). ekle/duzenle -> personel.ekle_duzenle; arsivle -> personel.sil;
         | yetki kaydet -> personel.yetki_yonet.
         */
        Route::post('/personel', [PersonelController::class, 'ekleDuzenle']);
        Route::patch('/personel/{id}', [PersonelController::class, 'ekleDuzenle'])->whereNumber('id');
        Route::patch('/personel/{id}/durum', [PersonelController::class, 'durum'])->whereNumber('id');
        Route::post('/personel/{id}/sifre-gonder', [PersonelController::class, 'sifreGonder'])->whereNumber('id');
        Route::patch('/personel/{id}/siralama', [PersonelController::class, 'siralama'])->whereNumber('id');
        Route::patch('/personel/{id}/takvim-gorunur', [PersonelController::class, 'takvimToggle'])->whereNumber('id');
        Route::patch('/personel/{id}/saatler', [PersonelController::class, 'saatKaydet'])->whereNumber('id');
        Route::delete('/personel/{id}', [PersonelController::class, 'arsivle'])->whereNumber('id');
        Route::patch('/personel/{id}/yetki', [PersonelYetkiController::class, 'kaydet'])->whereNumber('id');

        // STOK YONETIMI (urun, tanim, hareket, alis, transfer, sayim, hizli satis) — routes/v2/stok.php
        // ORTAK SECIM LISTELERI (hizmet, cihaz, oda, sehir, salon ayari) — routes/v2/secici.php
        require __DIR__.'/v2/secici.php';

        // AJANDA NOTLARI (kullanicinin kendi notlari) — routes/v2/ajanda.php
        require __DIR__.'/v2/ajanda.php';

        // RANDEVU YARDIMCI UCLARI (islem sonrasi not, sesli randevu musaitlik) — routes/v2/randevu-yardimci.php
        require __DIR__.'/v2/randevu-yardimci.php';

        require __DIR__.'/v2/stok.php';

        // OZET (dashboard: kartlar, liste sekmeleri, hizli eylemler) — routes/v2/ozet.php
        require __DIR__.'/v2/ozet.php';

        // RAPORLAR (satis / isletme) + KASA + MASRAF — routes/v2/rapor.php
        require __DIR__.'/v2/rapor.php';

        // STUDYO + GRUP DERSI (politika, program, oturum, katilimci, otomatik plan, rapor) — routes/v2/ders.php
        require __DIR__.'/v2/ders.php';

        // PAKET YONETIMI (paket tanimi liste/ekle/duzenle/sil + secili paketleri sat) — routes/v2/paket.php
        require __DIR__.'/v2/paket.php';

        // SMS YONETIMI (raporlar, toplu/filtreli gonderim outbox, sablonlar, ayarlar, kara liste) — routes/v2/sms.php
        require __DIR__.'/v2/sms.php';

        // BILDIRIMLER (kutu: liste/sayac/okundu/sil + FCM cihaz kaydi + web push ayari) — routes/v2/bildirim.php
        require __DIR__.'/v2/bildirim.php';

        // BILDIRIM REKLAMLARI (yonetim: liste/form/kaydet/durum/sil/gorsel + push gonder outbox) — routes/v2/bildirim-reklam.php
        require __DIR__.'/v2/bildirim-reklam.php';

        // MEMNUNIYET ANKETI (ozet, gonderimler, sablonlar, gonderim outbox, Reputation ayarlari) — routes/v2/anket.php
        require __DIR__.'/v2/anket.php';

        // REKLAM YONETIMI (kampanya liste/sihirbaz/rapor + katilimci islemleri + senaryo sihirbazi) — routes/v2/kampanya.php
        require __DIR__.'/v2/kampanya.php';

        // ON GORUSME (liste + ekle/duzenle + satis yapildi / yapilmadi) — routes/v2/ongorusme.php
        require __DIR__.'/v2/ongorusme.php';

        // ESKI MOBIL PARA UCLARI (mobil=1, yalniz imzali mobil token) — routes/v2/mobil-para.php
        require __DIR__.'/v2/mobil-para.php';

        // MOBIL SATIS (eski mobil yazim ve cevap kurali; yazmalar `mobil` bayragi ister) — routes/v2/satis.php
        require __DIR__.'/v2/satis.php';

        // MUSTERI YONETIMI (liste, detay, ekle/duzenle, kara liste, saglik, olcum) — routes/v2/musteri.php
        require __DIR__.'/v2/musteri.php';

        // RANDEVULAR LISTESI (filtreli liste + filtre secenekleri) — routes/v2/randevu-liste.php
        require __DIR__.'/v2/randevu-liste.php';

        // AYARLAR (isletme bilgileri, logo/galeri, calisma saatleri, randevu + online saatler, indirim, tema) — routes/v2/ayar.php
        require __DIR__.'/v2/ayar.php';

        // SEANS TAKIBI (liste + seans ekle/durum + lazer cihaz bilgisi) — routes/v2/seans.php
        require __DIR__.'/v2/seans.php';

        // LOG HAREKETLERI (salon_aktivite_log salt okuma: liste + filtre secenekleri) — routes/v2/hareket.php
        require __DIR__.'/v2/hareket.php';

        // WHATSAPP YONETIMI (oturum/QR, kanal, linkler, kontor, gonderim raporlari) — routes/v2/whatsapp.php
        require __DIR__.'/v2/whatsapp.php';

        // UYGULAMA AFISI (salt okuma: durum/link + afis onizleme JPEG + PDF) — routes/v2/uygulama-afisi.php
        require __DIR__.'/v2/uygulama-afisi.php';

        // PROFİL + HESABIM (kendi profili, üyelik, hizmetler, faturalar).
        require __DIR__.'/v2/hesabim.php';

        // CAGRI MERKEZI (calisma ekrani, arama randevulari, cagri kayitlari, performans, liste yonetimi) — routes/v2/cagri-merkezi.php
        require __DIR__.'/v2/cagri-merkezi.php';

        // SALON HATIRLATMA KARTLARI (genel kartlar rol 1/2/3, arama randevusu atanan personele) — routes/v2/hatirlatma.php
        require __DIR__.'/v2/hatirlatma.php';

        // PATRON ASISTANI + METIN SESLENDIRME — routes/v2/yardimci.php
        require __DIR__.'/v2/yardimci.php';

        // MUSTERI YORUMU MODERASYONU — routes/v2/musteri-yorum.php
        require __DIR__.'/v2/musteri-yorum.php';

        // BUGUNUN DOGUM GUNLERI (mobil popup) — routes/v2/dogum-gunu.php
        require __DIR__.'/v2/dogum-gunu.php';

        // CARKIFELEK + SADAKAT (cark ayari, kupon/kazananlar, hatirlatma, puan odulleri, kazanim kurali) — routes/v2/cark.php
        require __DIR__.'/v2/cark.php';

        /*
         | FORM YONETIMI (arsiv / form sablonu / sozlesme). v1'de bu uclarin hicbirinde
         | kimlik/kapsam/yetki yoktu. Sayfa: form.olustur VEYA form.gonder; gonderim
         | (SMS) form.gonder. Yazmalar FORM_V2_YAZMA_AKTIF kill-switch arkasinda (409).
         | SMS/WA 'form_link' outbox'i ile eski monolitten gider. PDF uretimi eski panelde.
         */
        Route::get('/form/arsiv', [ArsivController::class, 'liste']);
        Route::get('/form/form-verisi', [ArsivController::class, 'formVerisi']);
        Route::get('/form/musteri-ara', [ArsivController::class, 'musteriAra']);
        Route::get('/form/musteri/{userId}', [ArsivController::class, 'musteriBilgi'])->whereNumber('userId');
        Route::post('/form/arsiv', [ArsivController::class, 'formGonder']);
        Route::post('/form/arsiv/harici', [ArsivController::class, 'hariciBelge']);
        Route::post('/form/arsiv/{id}/gonder', [ArsivController::class, 'tekrarGonder'])->whereNumber('id');
        Route::post('/form/arsiv/{id}/onayla', [ArsivController::class, 'onayla'])->whereNumber('id');
        Route::post('/form/arsiv/{id}/iptal', [ArsivController::class, 'iptal'])->whereNumber('id');
        Route::post('/form/sozlesme', [ArsivController::class, 'sozlesme']);
        Route::get('/form/sozlesme/odeme-plani', [ArsivController::class, 'odemePlani']);
        Route::get('/form/sozlesme-metni', [FormSablonController::class, 'sozlesmeMetni']);
        Route::put('/form/sozlesme-metni', [FormSablonController::class, 'sozlesmeMetniKaydet']);
        Route::get('/form/sablon', [FormSablonController::class, 'liste']);
        Route::get('/form/sablon/{id}', [FormSablonController::class, 'goster'])->whereNumber('id');
        Route::post('/form/sablon', [FormSablonController::class, 'kaydet']);
        Route::patch('/form/sablon/{id}', [FormSablonController::class, 'kaydet'])->whereNumber('id');
        Route::delete('/form/sablon/{id}', [FormSablonController::class, 'sil'])->whereNumber('id');
        Route::patch('/form/sablon/{id}/sira', [FormSablonController::class, 'sirala'])->whereNumber('id');
        require __DIR__.'/v2/form.php';

        /*
         | E-ASISTAN ("Asistanım") — gorev listesi + gorev iptal + ayarlar. Kimlik +
         | kapsam + rol 5 degil (controller). Yazmalar ASISTAN_V2_YAZMA_AKTIF arkasinda.
         */
        Route::get('/asistan/gorevler', [AsistanController::class, 'gorevler']);
        Route::post('/asistan/gorev/{tur}/{id}/iptal', [AsistanController::class, 'iptal'])
            ->whereIn('tur', GorevTuru::iptalEdilebilenler())->whereNumber('id');
        Route::get('/asistan/ayarlar', [AsistanController::class, 'ayarlar']);
        Route::patch('/asistan/ayarlar', [AsistanController::class, 'ayarKaydet']);

        /*
         | SANTRAL (eski "Santral Sistemi & Aramalar" + Flutter santralraporlari — tek uc kumesi).
         | Okuma her rolde (personel yalniz kendi dahilisi); ayarlar yalniz yonetici (rol 5 degil).
         | Ayar yazma config('santral.yazma_aktif') kill-switch arkasinda (kapaliyken 409).
         */
        Route::get('/santral/ozet', [SantralController::class, 'ozet']);
        Route::get('/santral/cagrilar', [SantralController::class, 'cagrilar']);
        Route::get('/santral/ayarlar', [SantralController::class, 'ayarlar']);
        Route::put('/santral/ayarlar/hatirlatma', [SantralController::class, 'hatirlatmaKaydet']);
        Route::put('/santral/ayarlar/ozel-menu', [SantralController::class, 'ozelMenuKaydet']);
    });

    /*
     | MUSTERI UYGULAMASI — musteri (users) token'i ZORUNLU ('auth:api', legacy-passport).
     | Kimlik token'dan; govdedeki user_id kullanilmaz. Isletme token'i bu gruba giremez.
     */
    Route::middleware('auth:api')->group(function () {
        require __DIR__.'/v2/form-musteri.php';
        require __DIR__.'/v2/musteri-uygulama.php';
        require __DIR__.'/v2/satis-musteri.php';
        require __DIR__.'/v2/kampanya-musteri.php';
    });

});
