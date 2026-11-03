<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 | Tahsilat v2 — web paneli island host sayfasi (ilk tuketici, pilot).
 | Sayfa yalnizca HTML iskelet; veri /api/v2 (Bearer) ile yuklenir. Token
 | pilotta ?token=... ile saglanir (uretimde eski panel enjekte eder).
 */
Route::get('/v2/tahsilat/{adisyon}', function ($adisyon) {
    return view('v2.tahsilat', ['adisyonId' => (int) $adisyon]);
})->whereNumber('adisyon');

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
 */
foreach (['ozet' => 'v2.ozet', 'raporlar' => 'v2.raporlar', 'isletme-raporlari' => 'v2.isletme-raporlari', 'kasa' => 'v2.kasa', 'paketler' => 'v2.paketler', 'ongorusmeler' => 'v2.ongorusmeler', 'log-hareketleri' => 'v2.log-hareketleri'] as $yol => $gorunum) {
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
