<?php

use Illuminate\Support\Facades\Route;

// Kök: salonun kendi alan adındaysa o salonun sitesi, değilse panel girişi (SiteController::kok).
Route::get('/', [\App\Http\Controllers\SiteController::class, 'kok']);

/*
 | Giris, sifremi unuttum, ucretsiz deneme — kabuksuz sayfalar; tum is /api/v2/oturum + /api/v2/deneme.
 | docs/strangler-oturum.md
 */
Route::view('/v2/giris', 'oturum.giris');

/*
 | Sistem Yonetimi — eski /sistemyonetim/v2 karsiligi. Tum ekranlar tek kabuk gorunumu + ekran
 | modulu (public/js/sistem/<modul>.js); liste App\\Support\\SistemSayfalari, veri /api/v2/sistem/*.
 */
Route::view('/sistemyonetim/giris', 'sistem.giris');
Route::redirect('/sistemyonetim', '/sistemyonetim/dashboard');
foreach (\App\Support\SistemSayfalari::liste() as $yol => $sayfa) {
    Route::get('/sistemyonetim/'.$yol, fn ($id = null) => view('sistem.ekran', ['sayfa' => $sayfa, 'kayitId' => $id]))
        ->whereNumber('id');
}
Route::view('/v2/sifremi-unuttum', 'oturum.sifremi-unuttum');
Route::view('/ucretsiz-denemenizi-baslatin', 'deneme.baslat');
Route::view('/ucretsiz-deneme', 'deneme.baslat');

/*
 | Tahsilat v2 — web paneli island host sayfasi (ilk tuketici, pilot).
 | Sayfa yalnizca HTML iskelet; veri /api/v2 (Bearer) ile yuklenir. Token girisle
 | ya da eski panel koprusunun #token= fragment'iyla gelir (?token= kabul edilmez: lib/v2-ortak.js).
 */
Route::get('/v2/tahsilat/{adisyon}', function ($adisyon) {
    return view('v2.tahsilat', ['adisyonId' => (int) $adisyon]);
})->whereNumber('adisyon');

/*
 | Yeni Satış v2 (eski /isletmeyonetim/yenitahsilat) — AYNI tahsilat ekranı, "yeni" modda:
 | önce müşteri seçilir, ilk kalemle adisyon açılır (POST /api/v2/adisyon), sonra
 | ekran normal Satış & Tahsilat akışına geçer. Ayrı form kodu YOK.
 */
Route::get('/v2/yeni-satis/{sube?}', function ($sube = null) {
    return view('v2.tahsilat', ['adisyonId' => 0, 'salonId' => (int) ($sube ?: request('sube', 0))]);
})->whereNumber('sube');

/*
 | Satis Takibi v2 — adisyon/satis listesi host sayfasi (ayni island deseni).
 | Veri /api/v2/satislar (Bearer) ile yuklenir; salon_id ?sube= query'sinden
 | gelir (kabuk sube secimiyle de uyumlu). Eski panel "Satis Takibi" karsiligi.
 */
Route::get('/v2/satislar/{sube?}', function ($sube = null) {
    // sube hem path (/v2/satislar/20) hem query (?sube=20) ile kabul edilir.
    $salonId = (int) ($sube ?: request('sube', 0));

    return view('v2.satislar', ['salonId' => $salonId]);
})->whereNumber('sube');

/*
 | Personel Yonetimi v2 — personel listesi + ekle/duzenle + yetki editoru host
 | sayfasi (ayni island deseni). Veri /api/v2/personel* (Bearer) ile yuklenir;
 | yazma uclari PERSONEL_V2_YAZMA_AKTIF kapaliyken 409 doner (UI toast gosterir).
 */
Route::get('/v2/personel/{sube?}', function ($sube = null) {
    $salonId = (int) ($sube ?: request('sube', 0));

    return view('v2.personel', ['salonId' => $salonId]);
})->whereNumber('sube');

/*
 | Randevu Takvimi v2 — personel/oda/cihaz/kategori sutunlu gunluk takvim host
 | sayfasi (ayni island deseni). Takvim motoru EventCalendar (MIT, ucretsiz).
 | Veri /api/v2/randevu/takvim; durum aksiyonlari /api/v2/randevu/{id}/* —
 | RANDEVU_V2_YAZMA_AKTIF kapaliyken 409 (UI onizleme/DRY-RUN plani gosterir).
 */
Route::get('/v2/takvim/{sube?}', function ($sube = null) {
    $salonId = (int) ($sube ?: request('sube', 0));

    return view('v2.takvim', ['salonId' => $salonId]);
})->whereNumber('sube');

/*
 | Asistanım (E-Asistan) v2 — bugunku/yarinki gorevler + ayarlar host sayfasi
 | (ayni island deseni). Veri /api/v2/asistan/* (Bearer); yazmalar
 | ASISTAN_V2_YAZMA_AKTIF kapaliyken 409 (UI toast gosterir).
 */
Route::get('/v2/asistan/{sube?}', function ($sube = null) {
    $salonId = (int) ($sube ?: request('sube', 0));

    return view('v2.asistan', ['salonId' => $salonId]);
})->whereNumber('sube');

/*
 | Raporlar v2 — Satis Raporlari, Isletme Raporlari (Performans Panosu), Kasa Raporu host
 | sayfalari (ayni island deseni). Veri /api/v2/rapor/*, /api/v2/kasa/*, /api/v2/masraf/*
 | (Bearer); kasa yazmalari KASA_V2_YAZMA_AKTIF kapaliyken 409 (UI toast gosterir).
 | Paket Yonetimi v2 — paket tanimi tablosu + ekle/duzenle/sil + "Satis Yap"; veri
 | /api/v2/paket/* (Bearer); yazmalar PAKET_V2_YAZMA_AKTIF kapaliyken 409.
 | On Gorusmeler v2 — liste (durum sekmeleri) + ekle/duzenle + satis yapildi/yapilmadi; veri
 | /api/v2/ongorusme/* (Bearer); yazmalar ONGORUSME_V2_YAZMA_AKTIF kapaliyken 409.
 | Log Hareketleri v2 — salon aktivite logu (salt okuma); veri /api/v2/hareketler* (Bearer).
 | Uygulama Afisi v2 — QR'li indirme afisi onizleme + PDF + paylasim linki (salt okuma); /api/v2/uygulama-afisi*.
 | Cagri Merkezi v2 — eski 4 sayfa tek sekmeli sayfa (?p=calisma|takvim|kayitlar|performans|listeler);
 | veri /api/v2/cagri-merkezi/* (Bearer); yazmalar CAGRI_MERKEZI_V2_YAZMA_AKTIF kapaliyken 409.
 | Carkifelek v2 — eski 4 sayfa (cark, kazananlar, puan odulleri, hatirlatma) tek sekmeli sayfa
 | (?p=cark|kazananlar|puan|hatirlatma); veri /api/v2/cark/* + /api/v2/sadakat/* (Bearer).
 | Memnuniyet Anketi v2 — sonuclar + sablonlar sekmeli sayfa (?p=sonuclar|sablonlar); veri /api/v2/anket/* (Bearer).
 | Ders Programi + Grup Dersi Raporu v2 — stüdyo/grup dersi; veri /api/v2/ders/* (Bearer);
 | yazmalar DERS_V2_YAZMA_AKTIF kapaliyken 409.
 */
foreach (['ozet' => 'v2.ozet', 'raporlar' => 'v2.raporlar', 'isletme-raporlari' => 'v2.isletme-raporlari', 'kasa' => 'v2.kasa', 'paketler' => 'v2.paketler', 'ongorusmeler' => 'v2.ongorusmeler', 'log-hareketleri' => 'v2.log-hareketleri', 'cagri-merkezi' => 'v2.cagri-merkezi', 'uygulama-afisi' => 'v2.uygulama-afisi', 'carkifelek' => 'v2.carkifelek', 'memnuniyet-anketi' => 'v2.memnuniyet-anketi','ders-programi' => 'v2.ders-programi', 'grup-dersi-raporu' => 'v2.grup-dersi-raporu', 'whatsapp' => 'v2.whatsapp'] as $yol => $gorunum) {
    Route::get('/v2/'.$yol.'/{sube?}', fn ($sube = null) => view($gorunum, ['salonId' => (int) ($sube ?: request('sube', 0))]))
        ->whereNumber('sube');
}

/*
 | Form Yonetimi v2 — arsiv (form/sozlesme/harici belge) listesi + Form Gonder /
 | Sozlesme Olustur / Belge Ekle modallari + Form Sablonlari olusturucu host sayfasi
 | (ayni island deseni). Veri /api/v2/form/* — FORM_V2_YAZMA_AKTIF kapaliyken 409.
 */
Route::get('/v2/formlar/{sube?}', function ($sube = null) {
    $salonId = (int) ($sube ?: request('sube', 0));

    return view('v2.formlar', ['salonId' => $salonId]);
})->whereNumber('sube');

/*
 | Santral v2 — cagri raporu (gelen/giden/cevapsiz) + dakika karti + santral ayarlari
 | host sayfasi (ayni island deseni). Veri /api/v2/santral/* (Bearer) ile yuklenir;
 | ayar yazma SANTRAL_V2_YAZMA_AKTIF kapaliyken 409 doner (UI toast gosterir).
 */
Route::get('/v2/santral/{sube?}', function ($sube = null) {
    $salonId = (int) ($sube ?: request('sube', 0));

    return view('v2.santral', ['salonId' => $salonId]);
})->whereNumber('sube');

/*
 | Stok Yonetimi v2 — urun tablosu + ozet + alis/sayim/transfer/hizli satis +
 | kategori/depo/tedarikci host sayfasi (ayni island deseni). Veri /api/v2/stok/*
 | (Bearer); yazmalar STOK_V2_YAZMA_AKTIF kapaliyken 409 (UI toast gosterir).
 */
Route::get('/v2/stok/{sube?}', function ($sube = null) {
    $salonId = (int) ($sube ?: request('sube', 0));

    return view('v2.stok', ['salonId' => $salonId]);
})->whereNumber('sube');

/*
 | Musteri Yonetimi v2 — musteri listesi (Tumu/Sadik/Aktif/Pasif/Yeni sekmeleri) ve
 | musteri detay (Genel, Randevular, Olcum, Satislar, Saglik) host sayfalari (ayni
 | island deseni). Veri /api/v2/musteri/* (Bearer); yazmalar MUSTERI_V2_YAZMA_AKTIF
 | kapaliyken 409 (UI toast gosterir).
 */
Route::get('/v2/musteriler/{sube?}', function ($sube = null) {
    $salonId = (int) ($sube ?: request('sube', 0));

    return view('v2.musteriler', ['salonId' => $salonId]);
})->whereNumber('sube');

Route::get('/v2/musteri/{musteri}', function ($musteri) {
    return view('v2.musteri-detay', ['musteriId' => (int) $musteri, 'salonId' => (int) request('sube', 0)]);
})->whereNumber('musteri');

/*
 | Randevular v2 (eski /isletmeyonetim/randevular-liste) — filtreli randevu listesi; satir
 | islemleri + randevu formu takvimle ortak (lib/v2-randevu.js). Veri /api/v2/randevu/liste.
 */
Route::get('/v2/randevular/{sube?}', function ($sube = null) {
    return view('v2.randevular', ['salonId' => (int) ($sube ?: request('sube', 0))]);
})->whereNumber('sube');

/*
 | Seans Takibi v2 — seansli paket/hizmet satislari tablosu + hizmet kartlari (seans
 | daireleri) + lazer cihaz bilgisi host sayfasi (ayni island deseni). Ayni bilesen
 | musteri detay "Seanslar" sekmesinde de kullanilir. Veri /api/v2/seans/* (Bearer);
 | yazmalar SEANS_V2_YAZMA_AKTIF kapaliyken 409 (UI toast gosterir).
 */
Route::get('/v2/seans-takibi/{sube?}', function ($sube = null) {
    $salonId = (int) ($sube ?: request('sube', 0));

    return view('v2.seans-takibi', ['salonId' => $salonId]);
})->whereNumber('sube');
/*
 | Ayarlar v2 — Isletme Bilgileri (logo, galeri, SEO), Calisma Saatleri, Randevu Ayarlari,
 | Online Randevu Saatleri, Musteri Indirimleri host sayfasi (ayni island deseni). Sekme
 | listesi /api/v2/ayar/ozet'ten; henuz tasinmamis sekmeler eski panele link verir.
 | Yazmalar AYAR_V2_YAZMA_AKTIF kapaliyken 409 (UI toast gosterir).
 */
Route::get('/v2/ayarlar/{sube?}', function ($sube = null) {
    $salonId = (int) ($sube ?: request('sube', 0));
    return view('v2.ayarlar', ['salonId' => $salonId]);
})->whereNumber('sube');

/*
 | Panel zili tarayici push'u: FCM service worker (uzantisiz yol: nginx statik .js kuralina takilmaz; kapsam /).
 | Ayar config/bildirim.php; tanimli degilse 404 ve web push kapali kalir.
 */
Route::get('/bildirim-sw', function () {
    $ayar = array_filter(\Illuminate\Support\Arr::except((array) config('bildirim.web_push'), ['vapidKey']));
    abort_if(empty($ayar['apiKey']), 404);

    return response()->view('v2.bildirim-sw', ['ayar' => $ayar])
        ->header('Content-Type', 'application/javascript; charset=utf-8')
        ->header('Cache-Control', 'no-cache');
});

/*
 | Bildirim Reklamlari v2 — reklam kartlari + olustur/duzenle + yayinla/duraklat/sil + push gonder; veri
 | /api/v2/bildirim-reklam/* (Bearer); yazmalar BILDIRIM_REKLAM_V2_YAZMA_AKTIF kapaliyken 409.
 */
Route::get('/v2/bildirim-reklamlari/{sube?}', fn ($sube = null) => view('v2.bildirim-reklamlari', ['salonId' => (int) ($sube ?: request('sube', 0))]))
    ->whereNumber('sube');

/*
 | SMS Yonetimi v2 (eski /isletmeyonetim/toplusms) — build'siz island; sekmeler ve veri
 | /api/v2/sms/* (Bearer); yazmalar SMS_V2_YAZMA_AKTIF kapaliyken 409.
 */
Route::get('/v2/sms-yonetimi/{sube?}', fn ($sube = null) => view('v2.sms-yonetimi', ['salonId' => (int) ($sube ?: request('sube', 0))]))
    ->whereNumber('sube');

/*
 | Reklam Yonetimi v2 — kampanya listesi (kanal sekmeleri) + olustur/duzenle sihirbazi + reklam raporu
 | (katilimci sekmeleri) + Senaryo Sihirbazi; veri /api/v2/kampanya/* (Bearer); yazmalar
 | KAMPANYA_V2_YAZMA_AKTIF kapaliyken 409.
 */
Route::get('/v2/reklam-yonetimi/{sube?}', fn ($sube = null) => view('v2.reklam-yonetimi', ['salonId' => (int) ($sube ?: request('sube', 0))]))
    ->whereNumber('sube');

/*
 | Profil + Hesabim v2 (eski /isletmeyonetim/profil, /hesabim) — build'siz island; veri
 | /api/v2/profil ve /api/v2/hesabim (Bearer); yazmalar HESABIM_V2_YAZMA_AKTIF kapaliyken 409.
 */
Route::get('/v2/profil/{sube?}', fn ($sube = null) => view('v2.profil', ['salonId' => (int) ($sube ?: request('sube', 0))]))
    ->whereNumber('sube');
Route::get('/v2/hesabim/{sube?}', fn ($sube = null) => view('v2.hesabim', ['salonId' => (int) ($sube ?: request('sube', 0))]))
    ->whereNumber('sube');

/*
 | Isletme sitesi + musteri paneli — sayfa SEO icin sunucuda cizilir, etkilesim /api/v2 (vitrin +
 | musteri-oturum + musteri-uygulama). Salonun kendi domain'ine gecis yapilana kadar onizleme yolu.
 | docs/strangler-musteri-sitesi.md
 */
Route::prefix('/site/{salonId}')->whereNumber('salonId')->group(function () {
    Route::get('/', [\App\Http\Controllers\SiteController::class, 'vitrin']);
    foreach (['giris' => 'giris', 'profilim' => 'profil', 'randevularim' => 'randevular', 'sifrem' => 'sifre'] as $yol => $sayfa) {
        Route::get('/'.$yol, [\App\Http\Controllers\SiteController::class, 'hesap'])->defaults('sayfa', $sayfa);
    }
});
// Aynı sayfalar salonun kendi alan adında kökten; alan adı salona ait değilse 404.
foreach (['giris' => 'giris', 'profilim' => 'profil', 'randevularim' => 'randevular', 'sifrem' => 'sifre'] as $yol => $sayfa) {
    Route::get('/'.$yol, [\App\Http\Controllers\SiteController::class, 'kokHesap'])->defaults('sayfa', $sayfa);
}
