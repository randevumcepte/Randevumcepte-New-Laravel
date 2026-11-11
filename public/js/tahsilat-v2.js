/**
 * Satış & Tahsilat Düzenleme (v2) — build'siz island (sade ES module).
 *
 * Düzen: SOL sütun = kalemler + ekleme + Yapılan Tahsilatlar + Planlanan Alacaklar;
 * SAĞ sütun = Ödeme/Özet paneli. /api/v2 uçlarını Bearer token ile tüketir
 * (web + ileride Flutter AYNI uçlar). Yetenekler:
 *   - Adisyon özeti + kalemleri HER DURUMDA (kapalı dahil) adıyla listeler.
 *   - Katalogdan hizmet/ürün/paket EKLE, ürün ADEDİ, kalem SİL; hizmet veren / satıcı
 *     personel seçimi (ekleme + satırda değiştirme; zorunluluk kuralı sunucuda).
 *   - Elle FİYAT (ekleme + satırda; katalog.fiyat_degistir yetkisine göre açık).
 *   - Hizmette opsiyonel SEANS (2+ → API paket satışı sayar, seansli alanı); üründe ADET
 *     (fiyat birim üzerinden adede yansır, sunucuda).
 *   - Pakette hizmet çıkar / aramalı ekle, bölge başına seans, sınırsız rozeti ve süre
 *     (ortak bileşen lib/v2-paket-satis.js; satışa özel kopya sunucuda).
 *   - Tahsil Et: Önizle (dry-run) → Onayla; tahsilat DÜZENLE / İADE.
 *   - Alacak (vade) OLUŞTUR / DÜZENLE / İPTAL; alacak satırında TAHSİL ET → alacak ödeme
 *     panelinde kalem olarak listelenir, tahsilat kaydında ödenen kadar kapanır/azalır (sunucu).
 *   - Tarihler flatpickr (TR) ile seçilir.
 *   - YENİ SATIŞ modu (/v2/yeni-satis, data-adisyon=0): önce müşteri seçilir; ilk kalem
 *     POST /adisyon ile adisyonu açar, ekran aynı sayfada normal akışa geçer (eski
 *     /yenitahsilat ile aynı: boş adisyon oluşmaz). Ayrı form kodu yok.
 *
 * Token: localStorage['rc_access_token'] (eski panel köprüsünde #token=; ?token= kabul edilmez). Menü+header shell'den
 * (panel-kabuk.js); bu betik yalnızca içerik alanını yönetir.
 */

import { api, el, esc, TL, mesaj, onay, qp } from '/js/lib/v2-ortak.js?v=7';
import { musteriSecici } from '/js/lib/v2-secici.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=8';
import { dersPlani } from '/js/lib/v2-ders.js?v=7';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';
import { kodluIstek } from '/js/lib/v2-onay-kodu.js?v=8';
import { paketSatisKalemi, paketSatisBagla, paketSatisGirdisi } from '/js/lib/v2-paket-satis.js?v=7';

// Ekrandaki tablolar ortak veri tablosu (YEREL mod: kayıtlar adisyon cevabında hazır).
const tablolar = {};
const yerelTablo = (id, opt) => (tablolar[id] ||= veriTablosu(el('#' + id), { anahtar: 'tahsilat-' + id, ...opt }));
const TUR_AD = { hizmet: 'Hizmet', urun: 'Ürün', paket: 'Paket' };

const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'id-' + Date.now() + '-' + Math.random().toString(16).slice(2));

// Ödeme yöntemi id → ad (Yapılan Tahsilatlar "Tür" sütunu)
const YONTEM = { 1: 'Nakit', 2: 'Kredi Kartı', 3: 'Havale/EFT' };
const yontemAd = (id) => YONTEM[parseInt(id, 10)] || 'Diğer';

const kok = document.getElementById('rc-tahsilat');
let adisyonId = parseInt(kok.dataset.adisyon, 10) || 0;   // 0 = Yeni Satış modu
let yeniMusteri = null;                                    // Yeni Satış: seçilen müşteri
// Yeni Satış ekranı (eski /yenitahsilat): adisyon ilk kalemle arka planda açılır; başlık,
// adres ve yerleşim DEĞİŞMEZ — kalemler peş peşe eklenir, tahsilat aynı ekranda alınır.
const yeniMod = !adisyonId;
let adisyon = null;
let katalog = null;
let salonId = null;
let tutarManuel = false;        // kullanıcı Tahsilat Tutarı'na elle yazdı mı?
let duzenlenenTahsilat = null;  // düzenlenen tahsilat id (null = yeni tahsilat)
let duzenlenenAlacak = null;    // düzenlenen alacak id (null = yeni alacak)
let indirimler = null;          // GET /adisyon/{id}/indirimler (müşteri %, kuponlar, kampanya kodları)
let musteriIndirimAcik = true;  // aktif/sadık müşteri indirimi kutusu
let indirimManuel = false;      // kullanıcı İndirim'e elle yazdı mı?

/* ───────────── Datepicker (flatpickr, TR) ───────────── */
function datepickerBagla() {
  if (!window.flatpickr) return;
  ['#t-tarih', '#t-alacak-tarih', '#t-taksit-tarih', '#t-studyo-tarih', '#t-satis-tarih'].forEach((sel) => {
    const i = el(sel);
    if (i && !i._fp) i._fp = flatpickr(i, { dateFormat: 'Y-m-d', locale: 'tr', allowInput: true, disableMobile: true });
  });
}
function tarihAyarla(sel, val) {
  const i = el(sel);
  if (!i) return;
  if (i._fp) i._fp.setDate(val || null, false);
  else i.value = val || '';
}
function tarihDeger(sel) {
  const i = el(sel);
  return i ? (i.value || '') : '';
}

async function adisyonYukle() {
  const { ok, kod, govde } = await api('GET', '/adisyon/' + adisyonId);
  if (!ok) { mesaj(`Adisyon yüklenemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  await adisyonCiz(govde.veri);
  await indirimYukle();
}

/** Adisyon verisini ekrana yazar (istek atmaz). Kalem ekleme cevabi guncel adisyonu getirir; yeniden yukleme gerekmez. */
async function adisyonCiz(veri) {
  adisyon = veri;
  salonId = adisyon.adisyon.salon_id;
  if (window.rcPanel && salonId) window.rcPanel.salonAyarla(salonId);
  if (!katalog) await katalogYukle(); // kalem satırlarındaki personel seçimi katalogdan
  basligiGoster();
  ozetGoster();
  kalemlerGoster();
  gecmisGoster();
  alacakGoster();
  studyoOdemeGoster();
  if (tahsilAlacaklar.size) tahsilAlacakCiz();
  tutarOner();
}

/* ───────────── Tahsilat Tutarı / İndirim önerisi (tek yer) ─────────────
   Tutar güncel Kalan'ı takip eder; aktif/sadık müşteri indirimi açıksa brüt Kalan'ın %'si İndirim'e
   yazılır, Tutar = Kalan − İndirim. Elle yazılınca, düzenleme modunda ve alacak tahsilinde ezilmez. */
function tutarOner() {
  if (!adisyon || duzenlenenTahsilat || tahsilAlacaklar.size) return;
  const kalan = Math.max(0, Number(adisyon.hesap.kalan) || 0);
  const m = indirimler?.musteri_indirimi;
  if (!indirimManuel) el('#t-indirim').value = m && musteriIndirimAcik && kalan > 0 ? Math.round(kalan * m.yuzde) / 100 : 0;
  if (tutarManuel) return;
  const ind = parseFloat(el('#t-indirim').value || '0') || 0;
  const t = Math.round((kalan - ind) * 100) / 100;
  el('#t-tutar').value = t > 0 ? t : '';
}

/* ───────────── Kazanılmış indirimler: müşteri %, çark kuponu, kampanya kodu ───────────── */
async function indirimYukle() {
  if (!adisyonId) { indirimler = null; indirimCiz(); return; }
  const { ok, govde } = await api('GET', `/adisyon/${adisyonId}/indirimler`);
  indirimler = ok ? govde.veri : null;
  indirimCiz();
  tutarOner();
}

function indirimCiz() {
  const kap = el('#t-indirimler');
  const v = indirimler;
  if (!v) { kap.hidden = true; kap.innerHTML = ''; return; }
  const m = v.musteri_indirimi;
  const satir = [];
  if (m) {
    satir.push(`<label class="t-ind-musteri"><input type="checkbox" id="t-ind-musteri"${musteriIndirimAcik ? ' checked' : ''}>
      <span><i class="bi bi-award"></i> ${esc(m.etiket)} indirimi <b>%${esc(String(m.yuzde))}</b></span></label>`);
  }
  const uyg = [
    ...(v.uygulanan?.kuponlar || []).map((k) => `${esc(k.baslik)} <code>${esc(k.kod)}</code>`),
    ...(v.uygulanan?.kampanya ? [`Kampanya kodu <code>${esc(v.uygulanan.kampanya.kod)}</code> · ${esc(v.uygulanan.kampanya.metin || '')}`] : []),
  ];
  uyg.forEach((x) => satir.push(`<div class="t-ind-uyg"><i class="bi bi-check-circle-fill"></i><span>${x} uygulandı</span></div>`));
  const kuponlar = [
    ...(v.cark_kuponlari || []).map((k) => ({ tur: 'kupon', kod: k.kod, ad: k.baslik, ek: k.gecerlilik_tarihi ? 'Son: ' + String(k.gecerlilik_tarihi).slice(0, 10) : '' })),
    ...(v.kampanya_kodlari || []).map((k) => ({ tur: 'kampanya', kod: k.kod, ad: k.indirim || 'Kampanya indirimi', ek: 'Kampanya kodu' })),
  ];
  kuponlar.forEach((k) => satir.push(`<div class="t-ind-kupon"><i class="bi ${k.tur === 'kupon' ? 'bi-gift' : 'bi-megaphone'}"></i>
      <span><b>${esc(k.ad)}</b> <code>${esc(k.kod)}</code>${k.ek ? `<small>${esc(k.ek)}</small>` : ''}</span>
      <button type="button" class="t-btn-mini" data-ind-tur="${k.tur}" data-ind-kod="${esc(k.kod)}">Uygula</button></div>`));
  satir.push(`<div class="t-ind-kod"><input id="t-ind-kod" type="text" placeholder="Kupon / kampanya kodu" autocomplete="off">
      <button type="button" class="t-btn-mini" id="t-ind-kod-btn">Uygula</button></div>`);
  kap.innerHTML = '<div class="t-ind-baslik">İndirimler</div>' + satir.join('');
  kap.hidden = false;
  const cb = el('#t-ind-musteri');
  if (cb) cb.onchange = () => { musteriIndirimAcik = cb.checked; indirimManuel = false; tutarOner(); };
  kap.querySelectorAll('[data-ind-tur]').forEach((b) => (b.onclick = () => indirimKoduUygula(b.dataset.indKod, b.dataset.indTur, b)));
  const kodGir = el('#t-ind-kod');
  el('#t-ind-kod-btn').onclick = () => indirimKoduUygula(kodGir.value, null, el('#t-ind-kod-btn'));
  kodGir.onkeydown = (e) => { if (e.key === 'Enter') { e.preventDefault(); el('#t-ind-kod-btn').click(); } };
}

// tur: 'kupon' | 'kampanya' | null (elle girilen kod: listede yoksa önce çark kuponu, bulunamazsa kampanya kodu denenir).
async function indirimKoduUygula(kod, tur, buton) {
  kod = String(kod || '').trim();
  if (!kod) { mesaj('Kod girin.', 'bilgi'); return; }
  if (!adisyonId) { mesaj('Önce en az bir kalem ekleyin.', 'bilgi'); return; }
  if (!tur) {
    const v = indirimler || {};
    if ((v.kampanya_kodlari || []).some((k) => String(k.kod) === kod)) tur = 'kampanya';
    else if ((v.cark_kuponlari || []).some((k) => String(k.kod).toUpperCase() === kod.toUpperCase())) tur = 'kupon';
  }
  const istek = (t) => api('POST', `/adisyon/${adisyonId}/${t === 'kampanya' ? 'kampanya-kodu' : 'cark-kuponu'}`, { body: { kod, idempotency_key: uuid() } });
  if (buton) buton.disabled = true;
  let r = await istek(tur || 'kupon');
  if (!tur && r.kod === 404) r = await istek('kampanya');
  if (buton) buton.disabled = false;
  if (!r.ok) { mesaj(r.govde?.mesaj || `Kod uygulanamadı (${r.kod}).`, 'hata'); return; }
  mesaj(r.govde?.mesaj || 'İndirim uygulandı.', 'ok');
  await adisyonYukle();
}

async function katalogYukle() {
  const { ok, govde } = await api('GET', '/katalog?salon_id=' + salonId);
  if (ok) { katalog = govde.veri; studyoModuUygula(); }
}

/* ───────────── Stüdyo (MODEL B): fiyat gizli, ikili ödeme ───────────── */
const studyo = () => !!katalog?.studyo_modu;

// Tutar makinesi, kalem fiyat/personel sütunları ve ürün CSS ile gizlenir (.t-studyo, değerler korunur).
function studyoModuUygula() {
  kok.classList.toggle('t-studyo', studyo());
  if (studyo()) el('#t-baslik-h1').textContent = 'Satış';
}

function studyoOdemeGoster() {
  if (!studyo()) return;
  const a = adisyon.adisyon;
  const odendi = a.odendi === 1;
  el('#t-studyo-rozet').innerHTML = odendi
    ? '<span class="rc-rozet rc-rozet-yesil"><i class="bi bi-check-lg"></i> Ödeme Alındı</span>'
    : '<span class="rc-rozet rc-rozet-gri">Ödeme Alınmadı</span>';
  tarihAyarla('#t-studyo-tarih', a.odendi_tarihi ? String(a.odendi_tarihi).slice(0, 10) : '');
}

async function studyoOdeme(alindi) {
  if (!adisyonId) { mesaj('Önce en az bir kalem ekleyin.', 'bilgi'); return; }
  // Geri almada (tahsilat silinir) SMS ayarı 23: hesap sahibi onayı.
  const r = await kodluIstek('PATCH', `/adisyon/${adisyonId}/odeme`, { body: { alindi } });
  if (!r) return;
  const { ok, kod, govde } = r;
  if (!ok) { mesaj(`Kaydedilemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  mesaj('Kaydedildi.', 'ok');
  await adisyonYukle();
}

async function studyoOdemeTarihi() {
  if (!adisyonId) { mesaj('Önce en az bir kalem ekleyin.', 'bilgi'); return; }
  const { ok, kod, govde } = await api('PATCH', `/adisyon/${adisyonId}/odeme-tarihi`, { body: { tarih: tarihDeger('#t-studyo-tarih') || null } });
  if (!ok) { mesaj(`Kaydedilemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  mesaj('Ödeme tarihi kaydedildi.', 'ok');
  await adisyonYukle();
}

// Satış tarihi (eski adisyon-tarih-guncelle): sunucu tahsilat ödeme tarihlerini de kaydırır.
async function satisTarihiKaydet() {
  const yeni = tarihDeger('#t-satis-tarih');
  const eski = adisyon?.adisyon?.tarih ? String(adisyon.adisyon.tarih).slice(0, 10) : '';
  if (!yeni || yeni === eski) return;
  const { ok, kod, govde } = await api('PATCH', `/adisyon/${adisyonId}/tarih`, { body: { tarih: yeni } });
  if (ok) mesaj('Satış tarihi güncellendi.', 'ok');
  else mesaj(`Satış tarihi güncellenemedi (${kod}): ${govde?.mesaj || ''}`, 'hata');
  await adisyonYukle(); // tahsilat tarihleri de değişti; hata durumunda eski tarihe döner
}

function basligiGoster() {
  const a = adisyon.adisyon;
  if (!yeniMod) el('#t-musteri').textContent = a.musteri_ad || 'Müşteri'; // yeni satışta müşteri kartta
  el('#t-adisyon-no').textContent = '#' + adisyonId;
  tarihAyarla('#t-satis-tarih', a.tarih ? String(a.tarih).slice(0, 10) : '');
  // Stüdyoda fiyat pratikte 0: kapalılık kalandan değil, odendi bayrağından.
  const kapali = studyo() ? a.odendi === 1 : adisyon.hesap.durum === 'kapali';
  const d = el('#t-durum');
  d.textContent = kapali ? 'KAPALI' : 'AKTİF';
  d.className = 't-durum ' + (kapali ? 'kapali' : 'acik');
}

function ozetGoster() {
  const h = adisyon.hesap;
  const kapali = h.durum === 'kapali';
  el('#t-ozet-serit').innerHTML = `
    <div class="t-cip"><span>Ara Toplam</span><b>${TL(h.brut ?? h.net)}</b></div>
    <div class="t-cip"><span>İndirim</span><b>${TL(h.indirim || 0)}</b></div>
    <div class="t-cip vurgu-yesil"><span>Ödenen</span><b>${TL(h.odenen)}</b></div>
    <div class="t-cip ${kapali ? 'vurgu-yesil' : 'vurgu-kirmizi'}"><span>Kalan (Alacak)</span><b>${TL(h.kalan)}</b></div>`;
  const buyuk = el('#t-kalan-buyuk');
  buyuk.textContent = TL(h.kalan);
  buyuk.className = kapali ? 'kapali' : '';
  el('#t-ozet-detay').innerHTML = `
    <div class="sat"><span>Ara Toplam</span><b>${TL(h.brut ?? h.net)}</b></div>
    <div class="sat"><span>İndirim</span><b>${TL(h.indirim || 0)}</b></div>
    <div class="sat"><span>Net Tutar</span><b>${TL(h.net)}</b></div>
    <div class="sat"><span>Ödenen</span><b style="color:#16a34a">${TL(h.odenen)}</b></div>
    <div class="sat"><span>Kalan</span><b style="color:${kapali ? '#16a34a' : '#dc2626'}">${TL(h.kalan)}</b></div>`;
}

function kalemlerGoster() {
  const v = adisyon;
  const ortak = (x) => ({ id: x.id, fiyat: x.fiyat, indirim: x.indirim_tutari, personel_id: x.personel_id, personel_adi: x.personel_adi });
  const k = [
    ...(v.hizmetler || []).map((h) => ({ ...ortak(h), tip: 'hizmet', ad: h.ad || 'Hizmet #' + h.hizmet_id, seans: h.seans_sayisi, seansli: h.seansli, randevudan: h.randevudan })),
    ...(v.paketler || []).map((p) => ({ ...ortak(p), tip: 'paket', ad: p.ad || 'Paket #' + p.paket_id, yukseltme: p.yukseltme_kaynak, seans: p.seans_sayisi, sinirsiz: p.sinirsiz })),
    ...(v.urunler || []).map((u) => ({ ...ortak(u), tip: 'urun', ad: u.ad || 'Ürün #' + u.urun_id, adet: u.adet })),
  ];
  yerelTablo('t-kalemler', {
    birim: 'kalem',
    bosMesaj: 'Henüz kalem yok. Yukarıdan hizmet, ürün veya paket ekleyin.',
    kolonlar: [
      { baslik: 'Kalem', sirala: 'ad', deger: (x) => x.ad }, { baslik: 'Tür', sirala: 'tip', deger: turAd },
      { baslik: 'Adet / Seans', sinif: 'rc-orta' },
      { baslik: 'Personel', sirala: 'personel', deger: (x) => x.personel_adi || '' },
      { baslik: 'Fiyat', sinif: 'rc-sag', sirala: 'fiyat', deger: (x) => Number(x.fiyat) || 0 },
      { baslik: 'İndirim', sinif: 'rc-sag', sirala: 'indirim', deger: (x) => Number(x.indirim) || 0 }, { baslik: '' },
    ],
    araMetni: (x) => `${x.ad} ${turAd(x)} ${x.personel_adi || ''}`,
    satir,
    cizildi: kalemOlaylariBagla,
  }).kayitlarAyarla(k);
}

const personelEtiket = (tip) => (tip === 'hizmet' ? 'Hizmet Veren' : 'Satıcı');

// Personel <option>'ları (katalog: aktif personel). Listede olmayan (pasif) mevcut personel de korunur.
function personelSecenekleri(seciliId, seciliAd, bosEtiket) {
  const liste = [...(katalog?.personeller || [])];
  if (seciliId && !liste.some((p) => p.id === seciliId)) liste.unshift({ id: seciliId, ad: seciliAd || '#' + seciliId });
  return (bosEtiket ? `<option value="">${esc(bosEtiket)}</option>` : '')
    + liste.map((p) => `<option value="${p.id}"${p.id === seciliId ? ' selected' : ''}>${esc(p.ad)}</option>`).join('');
}

function personelSecim(x) {
  if (!katalog) return esc(x.personel_adi || '—');
  // Zorunlu hizmette atanmış personel boşaltılamaz; atanmamış eski kalemde seçim istenir.
  const bos = x.tip === 'hizmet' && katalog.hizmet_personel_zorunlu && x.personel_id ? null : 'Seçilmedi';
  return `<select class="t-kalem-personel" data-tip="${x.tip}" data-id="${x.id}" aria-label="${personelEtiket(x.tip)}">${personelSecenekleri(x.personel_id, x.personel_adi, bos)}</select>`;
}

function fiyatHucre(tip, id, fiyat) {
  if (!katalog?.fiyat_degistir) return TL(fiyat);
  const ipucu = tip === 'urun' ? 'Satır toplamı (₺)' : 'Fiyat (₺)';
  return `<input type="number" step="0.01" min="0" class="t-kalem-fiyat" data-tip="${tip}" data-id="${id}" value="${esc(fiyat ?? 0)}" title="${ipucu}" aria-label="${ipucu}">`;
}

// Seanslı hizmet paket satışı sayılır (karar API'de: seansli).
const turAd = (x) => (x.seansli ? TUR_AD.paket : TUR_AD[x.tip]);

// Üründe adet, randevudan gelmeyen hizmette opsiyonel seans, pakette bölge başına seans (sınırsızda yok).
function miktarHucre(x) {
  if (x.tip === 'urun') return `<input type="number" min="1" value="${x.adet || 1}" class="t-adet-inp" data-id="${x.id}" title="Adet" aria-label="Adet">`;
  if (x.tip === 'paket') {
    return x.sinirsiz
      ? '<span class="t-seans-yok" title="Sınırsız paket">Sınırsız</span>'
      : `<input type="number" min="1" value="${x.seans ?? ''}" placeholder="Seans" class="t-adet-inp t-seans-inp" data-tip="paket" data-id="${x.id}" title="Bölge başına seans hakkı" aria-label="Seans sayısı">`;
  }
  if (x.tip === 'hizmet' && !x.randevudan) return `<input type="number" min="0" value="${x.seans || ''}" placeholder="Seans" class="t-adet-inp t-seans-inp" data-tip="hizmet" data-id="${x.id}" title="Boş: tek hizmet · 2 ve üzeri: paket satışı" aria-label="Seans sayısı">`;
  if (x.tip === 'hizmet') return '<span class="t-seans-yok" title="Randevudan gelen hizmet — seans paket satışı sayılmadığı için değiştirilemez">—</span>';
  return '—';
}

// Yükseltilmiş paket rozeti (eski 765f3853 / 3ad88e65): "Yükseltildi (Eski Paket)".
const yukseltmeRozeti = (x) => (x.yukseltme
  ? ` <span class="t-rozet yukseltme" title="${esc(x.yukseltme.paket_adi)} paketinden yükseltildi"><i class="bi bi-arrow-up"></i> Yükseltildi (${esc(x.yukseltme.paket_adi)})</span>`
  : '');

function satir(x) {
  return `<tr>
    <td><span class="t-kalem-ad">${esc(x.ad)}</span>${yukseltmeRozeti(x)}</td>
    <td><span class="t-rozet ${x.seansli ? 'paket' : x.tip}">${turAd(x)}</span></td>
    <td class="rc-orta">${miktarHucre(x)}</td>
    <td>${personelSecim(x)}</td>
    <td class="rc-sag">${fiyatHucre(x.tip, x.id, x.fiyat)}</td>
    <td class="rc-sag">${TL(x.indirim || 0)}</td>
    <td class="rc-sag t-islem">${x.tip === 'paket' ? `<button class="t-duzen t-yukselt" data-id="${x.id}" title="Paketi Yükselt"><i class="bi bi-arrow-up"></i></button>` : ''}<button class="t-sil" data-tip="${x.tip}" data-id="${x.id}" title="Kalemi sil"><i class="bi bi-trash"></i></button></td></tr>`;
}

function kalemOlaylariBagla() {
  document.querySelectorAll('#t-kalemler .t-sil').forEach((b) => (b.onclick = () => kalemSil(b.dataset.tip, b.dataset.id)));
  document.querySelectorAll('#t-kalemler .t-yukselt').forEach((b) => (b.onclick = () => paketYukselt(parseInt(b.dataset.id, 10))));
  document.querySelectorAll('#t-kalemler .t-kalem-fiyat').forEach((i) => (i.onchange = () => kalemFiyatDegistir(i)));
  document.querySelectorAll('#t-kalemler .t-kalem-personel').forEach((sel) => (sel.onchange = () => kalemPersonelDegistir(sel)));
  document.querySelectorAll('#t-kalemler .t-seans-inp').forEach((i) => (i.onchange = () => seansDegistir(i)));
  document.querySelectorAll('#t-kalemler .t-adet-inp:not(.t-seans-inp)').forEach((inp) => {
    let zaman;
    inp.oninput = () => {
      clearTimeout(zaman);
      const deger = inp.value;
      if (!(parseInt(deger, 10) >= 1)) return; // yazarken boş/0: bekle
      zaman = setTimeout(() => adetDegistir(inp.dataset.id, deger), 600);
    };
  });
}

/* ───────────── Paketi Yükselt (iki adım: aday seç → fiyat onayla) ───────────── */
// Satılmış paket yerinde kapsayan pakete dönüşür; seans ve tahsilat kayıtları korunur (sunucu: PaketYukseltServisi).
// Kalan yalnız gösterimdir; asıl kalan tahsilat özetinden gelir.
async function paketYukselt(kalemId) {
  const { ok, govde } = await api('GET', `/kalem/paket/${kalemId}/yukseltme-adaylari`);
  if (!ok) { mesaj(govde?.mesaj || 'Yükseltme adayları alınamadı', 'hata'); return; }
  const v = govde.veri;
  const ozet = `<div class="t-yk-ozet"><b>${esc(v.mevcut_paket_adi)}</b>${studyo() ? '' : ` · ${TL(v.mevcut_fiyat)} · ödenen ${TL(v.mevcut_odenen)}`}</div>`;
  if (!v.adaylar.length) {
    modalAc('Paketi Yükselt', `${ozet}<p class="t-yk-bos">Bu paketin tüm hizmetlerini kapsayan ve ek hizmeti olan aktif paket yok.</p>`, '<button type="button" class="rc-btn" data-kapat>Kapat</button>', { ikon: 'arrow-up-circle' });
    el('#rcModal [data-kapat]').onclick = modalKapat;
    return;
  }
  const seansMetni = (a) => (a.sinirsiz ? `Sınırsız${a.gecerlilik_metni ? ' · ' + esc(a.gecerlilik_metni) : ''}` : `${a.seans_per} seans / bölge`);
  const ov = modalAc('Paketi Yükselt', `${ozet}
    <div class="t-yk-liste">${v.adaylar.map((a) => `
      <label class="t-yk-aday"><input type="radio" name="tYkAday" value="${a.paket_id}">
        <span><b>${esc(a.paket_adi)}</b>${studyo() ? '' : ` · ${TL(a.fiyat)}`} · ${seansMetni(a)}
        <small>+ ${a.ek_hizmetler.map((h) => esc(h.hizmet_adi)).join(', ')}</small></span></label>`).join('')}</div>`,
  '<button type="button" class="rc-btn" data-kapat>Vazgeç</button><button type="button" class="rc-btn rc-btn-birincil" data-ileri disabled>İleri</button>',
  { ikon: 'arrow-up-circle', sabit: true });
  el('[data-kapat]', ov).onclick = modalKapat;
  ov.addEventListener('change', () => { el('[data-ileri]', ov).disabled = !el('input[name="tYkAday"]:checked', ov); });
  el('[data-ileri]', ov).onclick = () => {
    const a = v.adaylar.find((x) => String(x.paket_id) === el('input[name="tYkAday"]:checked', ov).value);
    if (a) paketYukseltOnay(kalemId, v, a);
  };
}

function paketYukseltOnay(kalemId, v, a) {
  const fiyatAcik = !!katalog?.fiyat_degistir && !studyo();
  const kalanMetni = (f) => TL(Math.max(0, Math.round((Number(f) - v.mevcut_odenen) * 100) / 100));
  const ov = modalAc('Paketi Yükselt', `
    <div class="t-yk-ozet"><b>${esc(v.mevcut_paket_adi)}</b> <i class="bi bi-arrow-right"></i> <b>${esc(a.paket_adi)}</b></div>
    <div class="t-yk-ek"><label>Eklenecek hizmetler</label>
      <ul>${a.ek_hizmetler.map((h) => `<li>${esc(h.hizmet_adi)}${!a.sinirsiz && h.seans ? ` <small>(${h.seans} seans)</small>` : ''}</li>`).join('')}</ul>
      <small>${a.sinirsiz ? 'Sınırsız paket' : `Bölge başına ${a.seans_per} seans`} · mevcut seans kayıtları korunur</small></div>
    ${studyo() ? '' : `<div class="rc-form-izgara">
      <div class="rc-alan"><label>Yeni Fiyat (₺)</label><input type="number" min="0" step="0.01" id="tYkFiyat" value="${esc(a.fiyat)}" ${fiyatAcik ? '' : 'readonly title="Fiyat değiştirme yetkiniz yok"'}></div>
      <div class="rc-alan"><label>Önceden Ödenen</label><input readonly value="${esc(TL(v.mevcut_odenen))}"></div>
      <div class="rc-alan"><label>Kalan</label><input readonly id="tYkKalan" value="${esc(kalanMetni(a.fiyat))}"></div>
    </div>`}`,
  '<button type="button" class="rc-btn" data-geri>Geri</button><button type="button" class="rc-btn rc-btn-birincil" data-yukselt><i class="bi bi-arrow-up"></i> Yükselt</button>',
  { ikon: 'arrow-up-circle', sabit: true });
  const fiyat = el('#tYkFiyat', ov);
  if (fiyat) fiyat.oninput = () => { el('#tYkKalan', ov).value = kalanMetni(fiyat.value); };
  el('[data-geri]', ov).onclick = () => paketYukselt(kalemId);
  const anahtar = uuid(); // çift tıklama ikinci kez dönüştürmesin
  const btn = el('[data-yukselt]', ov);
  btn.onclick = async () => {
    btn.disabled = true;
    try {
      const body = { hedef_paket_id: a.paket_id, idempotency_key: anahtar };
      if (fiyat && fiyatAcik && fiyat.value !== '') body.fiyat = Number(fiyat.value);
      const r = await api('POST', `/kalem/paket/${kalemId}/yukselt`, { body });
      if (!r.ok) { mesaj(r.govde?.mesaj || 'Paket yükseltilemedi', 'hata'); return; }
      modalKapat();
      mesaj(r.govde?.mesaj || 'Paket yükseltildi.', 'ok');
      await adisyonYukle();
    } finally { btn.disabled = false; }
  };
}

/* ───────────── Yapılan tahsilatlar (tür + düzenle/sil) ───────────── */
function gecmisGoster() {
  yerelTablo('t-gecmis', {
    birim: 'tahsilat',
    bosMesaj: 'Henüz tahsilat yapılmamış.',
    kolonlar: [
      { baslik: 'Tarih', sirala: 'tarih', deger: (o) => o.odeme_tarihi || '' }, { baslik: 'Tür', sirala: 'tur', deger: (o) => yontemAd(o.odeme_yontemi_id) },
      { baslik: 'Tutar', sinif: 'rc-sag', sirala: 'tutar', deger: (o) => Number(o.tutar) || 0 }, { baslik: '' },
    ],
    araMetni: (o) => `${o.odeme_tarihi || ''} ${yontemAd(o.odeme_yontemi_id)} ${o.tutar}`,
    satir: (o) => `<tr>
          <td>${esc(o.odeme_tarihi || '-')}</td>
          <td><span class="t-rozet yontem">${esc(yontemAd(o.odeme_yontemi_id))}</span></td>
          <td class="rc-sag" style="color:#16a34a;font-weight:700">${TL(o.tutar)}</td>
          <td class="rc-sag t-islem">
            <button class="t-duzen" data-tah="${o.id}" title="Düzenle"><i class="bi bi-pencil"></i></button>
            <button class="t-sil"   data-tah="${o.id}" title="İade / Sil"><i class="bi bi-trash"></i></button>
          </td></tr>`,
    cizildi: () => {
      document.querySelectorAll('#t-gecmis .t-duzen[data-tah]').forEach((b) => (b.onclick = () => tahsilatDuzenle(parseInt(b.dataset.tah, 10))));
      document.querySelectorAll('#t-gecmis .t-sil[data-tah]').forEach((b) => (b.onclick = () => tahsilatSil(parseInt(b.dataset.tah, 10))));
    },
  }).kayitlarAyarla(adisyon.tahsilatlar || []);
}

/* ───────────── Planlanan alacaklar (düzenle/sil) ───────────── */
function alacakGoster() {
  yerelTablo('t-alacaklar', {
    birim: 'alacak',
    bosMesaj: 'Planlanan alacak yok.',
    kolonlar: [
      { baslik: 'Vade', sirala: 'vade', deger: (x) => x.planlanan_odeme_tarihi || '' }, { baslik: 'Not', sirala: 'not', deger: (x) => x.aciklama || '' },
      { baslik: 'Tutar', sinif: 'rc-sag', sirala: 'tutar', deger: (x) => Number(x.tutar) || 0 }, { baslik: '' },
    ],
    araMetni: (x) => `${x.planlanan_odeme_tarihi || ''} ${x.aciklama || ''} ${x.tutar}`,
    satir: (x) => `<tr>
          <td><i class="bi bi-calendar-event" style="color:#6b7194;margin-right:6px"></i>${esc(x.planlanan_odeme_tarihi || '-')}</td>
          <td>${esc(x.aciklama || '')}</td>
          <td class="rc-sag" style="font-weight:700">${TL(x.tutar)}</td>
          <td class="rc-sag t-islem">
            <button class="t-tahsil" data-alt="${x.id}" title="Tahsil Et"><i class="bi bi-cash-coin"></i></button>
            ${x.taksit_vade_id ? '' : `<button class="t-duzen" data-ald="${x.id}" title="Düzenle"><i class="bi bi-pencil"></i></button>
            <button class="t-sil"   data-al="${x.id}"  title="İptal"><i class="bi bi-trash"></i></button>`}
          </td></tr>`,
    cizildi: () => {
      document.querySelectorAll('#t-alacaklar .t-tahsil[data-alt]').forEach((b) => (b.onclick = () => alacakTahsilEkle(parseInt(b.dataset.alt, 10))));
      document.querySelectorAll('#t-alacaklar .t-duzen[data-ald]').forEach((b) => (b.onclick = () => alacakDuzenle(parseInt(b.dataset.ald, 10))));
      document.querySelectorAll('#t-alacaklar .t-sil[data-al]').forEach((b) => (b.onclick = () => alacakSil(b.dataset.al)));
    },
  }).kayitlarAyarla(adisyon.alacaklar || []);
}

/* ───────────── Kalem ekleme / adet / sil ───────────── */
function ekleAc(tip) {
  if (!katalog) { mesaj('Katalog yükleniyor, tekrar deneyin.', 'bilgi'); katalogYukle(); return; }
  const panel = el('#t-ekle-panel');
  panel.hidden = false;
  el('#t-ekle-tip').value = tip;
  el('#t-ekle-adet').value = 1;
  katalogDoldur(tip);
  el('#t-ekle-paket').hidden = tip !== 'paket';
  el('#t-ekle-adet-kutu').style.display = tip === 'urun' ? '' : 'none';
  el('#t-ekle-seans').style.display = tip === 'hizmet' ? '' : 'none';
  el('#t-ekle-seans').value = '';
  el('#t-ekle-baslik').textContent = tip === 'hizmet' ? 'Hizmet Ekle' : tip === 'urun' ? 'Ürün Ekle' : 'Paket Ekle';
  const zorunlu = tip === 'hizmet' && katalog.hizmet_personel_zorunlu;
  el('#t-ekle-personel').innerHTML = personelSecenekleri(null, null, `${personelEtiket(tip)} seçin${zorunlu ? ' *' : ''}`);
  el('#t-ekle-katalog').focus();
}

function katalogDoldur(tip) {
  const liste = tip === 'hizmet' ? katalog.hizmetler : tip === 'urun' ? katalog.urunler : katalog.paketler;
  const idAlan = tip === 'hizmet' ? 'hizmet_id' : tip === 'urun' ? 'urun_id' : 'paket_id';
  el('#t-ekle-katalog').innerHTML = (liste || []).map((x) => `<option value="${x[idAlan]}" data-fiyat="${esc(x.fiyat ?? 0)}">${esc(x.ad)}${studyo() ? '' : ` — ${TL(x.fiyat)}`}</option>`).join('');
  el('#t-ekle-fiyat').readOnly = !katalog.fiyat_degistir;
  ekleFiyatOner();
}

// Fiyat alanı: seçilen kalemin katalog fiyatı (ürün: birim × adet); elle değiştirilebilir.
function ekleFiyatOner() {
  const op = el('#t-ekle-katalog').selectedOptions[0];
  const adet = el('#t-ekle-tip').value === 'urun' ? (parseInt(el('#t-ekle-adet').value, 10) || 1) : 1;
  el('#t-ekle-fiyat').value = op ? Math.round((Number(op.dataset.fiyat) || 0) * adet * 100) / 100 : '';
  ekleSonAdet = adet;
  paketKalemiCiz();
}

// Paket: seçilen paketin hizmetleri (çıkar / aramalı ekle), bölge başına seans ya da sınırsız rozeti, süre.
// Ortak bileşen (Paket Yönetimi ile aynı); fiyat bu paneldeki #t-ekle-fiyat alanında.
const katalogPaketi = (id) => (katalog?.paketler || []).find((p) => String(p.paket_id) === String(id));
function paketKalemiCiz() {
  const kap = el('#t-ekle-paket');
  if (!kap) return;
  const p = el('#t-ekle-tip').value === 'paket' ? katalogPaketi(el('#t-ekle-katalog').value) : null;
  kap.innerHTML = p ? paketSatisKalemi(p, { fiyat: false }) : '';
}

// Adet değişince fiyat, alandaki (elle girilmiş olabilir) birim üzerinden yansır.
let ekleSonAdet = 1;
function ekleAdetDegisti() {
  const adet = parseInt(el('#t-ekle-adet').value, 10);
  if (!(adet >= 1)) return;
  const fiyat = Number(el('#t-ekle-fiyat').value) || 0;
  el('#t-ekle-fiyat').value = Math.round((fiyat / ekleSonAdet) * adet * 100) / 100;
  ekleSonAdet = adet;
}

async function kalemEkle() {
  const tip = el('#t-ekle-tip').value;
  const katalogId = parseInt(el('#t-ekle-katalog').value, 10);
  if (!katalogId) { mesaj('Katalogdan seçim yapın', 'hata'); return; }
  const body = { tip, katalog_id: katalogId };
  if (tip === 'urun') body.adet = parseInt(el('#t-ekle-adet').value || '1', 10);
  if (tip === 'hizmet' && el('#t-ekle-seans').value !== '') body.seans = parseInt(el('#t-ekle-seans').value, 10);
  const paketKalemi = tip === 'paket' ? el('#t-ekle-paket .pk-satis-kalem') : null;
  if (paketKalemi) {
    const g = paketSatisGirdisi(paketKalemi, { fiyat: false });
    body.hizmetler = g.hizmetler;
    if (g.seans !== null) body.seans = g.seans;
  }
  if (katalog.fiyat_degistir && el('#t-ekle-fiyat').value !== '') body.fiyat = Number(el('#t-ekle-fiyat').value);
  const personelId = parseInt(el('#t-ekle-personel').value, 10);
  if (personelId) body.personel_id = personelId;
  const btn = el('#t-ekle-btn');
  btn.disabled = true;
  try {
    if (!adisyonId) { await yeniSatisAc(body); return; }
    const { ok, kod, govde } = await api('POST', '/adisyon/' + adisyonId + '/kalem', { body });
    if (!ok) { mesaj(`Kalem eklenemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
    mesaj('Kalem eklendi.', 'ok');
    el('#t-ekle-panel').hidden = true;
    // Cevaptaki guncel adisyonla yalniz ekran guncellenir (indirimler kalemden etkilenmez, yeniden cekilmez).
    if (govde?.veri?.adisyon) await adisyonCiz(govde.veri.adisyon); else await adisyonYukle();
  } finally { btn.disabled = false; }
}

/* ───────────── Yeni Satış modu ───────────── */
function yeniSatisBaslat() {
  salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
  if (!salonId) { mesaj('Şube seçili değil (?sube=... bekleniyor).', 'hata'); return; }
  if (window.rcPanel) window.rcPanel.salonAyarla(salonId);
  el('#t-baslik-h1').textContent = 'Yeni Satış';
  el('#t-adisyon-no').textContent = '';
  document.querySelectorAll('.t-yeni-gizle').forEach((x) => { x.hidden = true; });
  // Adisyon açılana dek ödeme özeti / tahsilat / alacak boş görünür (eski formda da hep görünürdü).
  adisyon = { adisyon: {}, hesap: { brut: 0, indirim: 0, net: 0, odenen: 0, kalan: 0, durum: 'acik' }, hizmetler: [], urunler: [], paketler: [], tahsilatlar: [], alacaklar: [] };
  ozetGoster();
  gecmisGoster();
  alacakGoster();
  el('#t-kalemler').innerHTML = '<div class="t-yeni-ipucu"><i class="bi bi-person-check"></i> Önce müşteri seçin, ardından hizmet / ürün / paket ekleyin.</div>';
  yeniButonlar(false);
  katalogYukle();
  musteriSecici(el('#t-musteri-sec'), {
    salonId, yol: '/musteri/ara', ipucu: 'Müşteri adı veya telefon…',
    onSec: (m) => { yeniMusteri = m; yeniButonlar(!!m); },
  });
}

// Eski formdaki gibi: müşteri seçilmeden kalem butonları kapalı.
function yeniButonlar(acik) {
  document.querySelectorAll('.t-ekle-btns [data-tip]').forEach((b) => { b.disabled = !acik; });
  if (!acik) el('#t-ekle-panel').hidden = true;
}

// İlk kalem: adisyonu arka planda açar (POST /adisyon). Sayfa/başlık değişmez; müşteri kilitlenir.
async function yeniSatisAc(kalem) {
  if (!yeniMusteri) { mesaj('Önce müşteri seçin', 'hata'); return; }
  const { ok, kod, govde } = await api('POST', '/adisyon', {
    body: { ...kalem, salon_id: salonId, musteri_id: yeniMusteri.id, idempotency_key: uuid() },
  });
  if (!ok || !govde?.basarili) { mesaj(`Satış açılamadı (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  adisyonId = govde.veri.adisyon_id;
  // Adres sayfa yenilenmeden satisin kendi adresine doner: yenilense de ayni satis acilir.
  history.replaceState(null, '', `/v2/tahsilat/${adisyonId}?sube=${salonId}`);
  musteriSecici(el('#t-musteri-sec'), { salonId, yol: '/musteri/ara', baslangic: yeniMusteri, sabit: true });
  document.querySelectorAll('.t-yeni-gizle').forEach((x) => { x.hidden = false; });
  el('#t-ekle-panel').hidden = true;
  mesaj('Kalem eklendi.', 'ok');
  if (govde.veri.adisyon) { await adisyonCiz(govde.veri.adisyon); await indirimYukle(); } else await adisyonYukle();
}

async function kalemFiyatDegistir(i) {
  const { ok, kod, govde } = await api('PATCH', `/kalem/${i.dataset.tip}/${i.dataset.id}/fiyat`, { body: { fiyat: i.value } });
  if (ok) mesaj('Fiyat güncellendi.', 'ok');
  else mesaj(`Fiyat güncellenemedi (${kod}): ${govde?.mesaj || ''}`, 'hata');
  await adisyonYukle(); // hata durumunda eski fiyata döner
}

async function kalemPersonelDegistir(sel) {
  const { ok, kod, govde } = await api('PATCH', `/kalem/${sel.dataset.tip}/${sel.dataset.id}/personel`, { body: { personel_id: parseInt(sel.value, 10) || null } });
  if (ok) mesaj('Personel güncellendi.', 'ok');
  else mesaj(`Personel güncellenemedi (${kod}): ${govde?.mesaj || ''}`, 'hata');
  await adisyonYukle(); // hata durumunda eski seçime döner
}

async function adetDegistir(kalemId, adet) {
  const { ok, kod, govde } = await api('PATCH', '/kalem/urun/' + kalemId, { body: { adet: parseInt(adet, 10) } });
  if (ok) mesaj('Adet güncellendi.', 'ok');
  else mesaj(`Adet güncellenemedi (${kod}): ${govde?.mesaj || ''}`, 'hata');
  await adisyonYukle(); // yeni fiyat çizilir; hata durumunda eski adete döner
}

async function seansDegistir(i) {
  const { ok, kod, govde } = await api('PATCH', `/kalem/${i.dataset.tip || 'hizmet'}/${i.dataset.id}/seans`, { body: { seans: i.value } });
  if (ok) mesaj('Seans güncellendi.', 'ok');
  else mesaj(`Seans güncellenemedi (${kod}): ${govde?.mesaj || ''}`, 'hata');
  await adisyonYukle();
}

async function kalemSil(tip, kalemId) {
  const onaylandi = await onay('Bu kalemi adisyondan silmek istediğinize emin misiniz?', { baslik: 'Kalemi Sil', tamam: 'Sil', iptal: 'Vazgeç' });
  if (!onaylandi) return;
  const r = await kodluIstek('DELETE', '/kalem/' + tip + '/' + kalemId); // SMS ayarı 23: hesap sahibi onayı
  if (!r) return;
  const { ok, kod, govde } = r;
  if (!ok) { mesaj(`Kalem silinemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  mesaj('Kalem silindi.', 'ok');
  await adisyonYukle();
}

/* ───────────── Tahsilat: yeni / düzenle / iade ───────────── */
/* ───────────── Alacağa karşılık tahsilat (eski: alacak satırında "Tahsil Et") ───────────── */
const tahsilAlacaklar = new Map(); // alacak_id → alacak

function alacakTahsilEkle(id) {
  const a = (adisyon.alacaklar || []).find((x) => x.id === id);
  if (!a || duzenlenenTahsilat) return;
  // Taksit vadesi vade ödemesiyle, serbest alacak kalem tahsilatıyla kapanır: aynı tahsilatta karışmaz.
  for (const [k, v] of tahsilAlacaklar) if (!!v.taksit_vade_id !== !!a.taksit_vade_id) tahsilAlacaklar.delete(k);
  tahsilAlacaklar.set(id, a);
  tahsilAlacakCiz();
  el('#t-tutar').focus();
}

// Ödeme panelinde "Tahsil edilecek alacaklar" kalemleri; Tahsilat Tutarı = seçilenlerin toplamı.
function tahsilAlacakCiz() {
  const kap = el('#t-tahsil-alacak');
  // Silinmiş/kapanmış alacak seçimden düşer.
  for (const id of [...tahsilAlacaklar.keys()]) if (!(adisyon.alacaklar || []).some((x) => x.id === id)) tahsilAlacaklar.delete(id);
  if (!tahsilAlacaklar.size) { kap.innerHTML = ''; kap.hidden = true; if (adisyon) { tutarManuel = false; tutarOner(); } return; }
  let toplam = 0;
  kap.innerHTML = '<div class="t-ta-baslik">Tahsil edilecek alacaklar</div>' + [...tahsilAlacaklar.values()].map((a) => {
    toplam += Number(a.tutar) || 0;
    return `<div class="t-ta-kalem"><i class="bi bi-wallet2"></i><span>${esc(a.planlanan_odeme_tarihi || '')}${a.aciklama ? ' · ' + esc(a.aciklama) : ''}</span>`
      + `<b>${TL(a.tutar)}</b><button type="button" data-cikar="${a.id}" title="Çıkar" aria-label="Çıkar"><i class="bi bi-x-lg"></i></button></div>`;
  }).join('');
  kap.hidden = false;
  kap.querySelectorAll('[data-cikar]').forEach((b) => (b.onclick = () => { tahsilAlacaklar.delete(parseInt(b.dataset.cikar, 10)); tahsilAlacakCiz(); }));
  tutarManuel = true;
  indirimManuel = false;
  el('#t-indirim').value = 0;
  el('#t-tutar').value = Math.round(toplam * 100) / 100;
}

function girdi(dryRun) {
  const secilen = [...tahsilAlacaklar.values()];
  return {
    alacak_id: secilen.filter((a) => !a.taksit_vade_id).map((a) => a.id),
    taksit_vade_id: secilen.filter((a) => a.taksit_vade_id).map((a) => a.taksit_vade_id),
    salon_id: salonId, adisyon_id: adisyonId,
    tutar: parseFloat(el('#t-tutar').value || '0'),
    indirim: parseFloat(el('#t-indirim').value || '0'),
    odeme_yontemi_id: parseInt(el('#t-yontem').value, 10),
    odeme_tarihi: tarihDeger('#t-tarih'),
    notlar: el('#t-not').value || null,
    dry_run: dryRun,
  };
}

// Ödeme panelindeki ana buton: düzenleme modundaysa güncelle, değilse önizle.
function odemeButon() {
  if (!adisyonId) { mesaj('Önce en az bir kalem ekleyin.', 'bilgi'); return; }
  if (duzenlenenTahsilat) tahsilatGuncelleKaydet();
  else onizle();
}

let idemKey = null;
async function onizle() {
  el('#t-plan').innerHTML = '';
  idemKey = uuid();
  const { ok, kod, govde } = await api('POST', '/tahsilat', { body: girdi(true) });
  if (!ok) { mesaj(`Önizleme hatası (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  mesaj('Önizleme hazır — kontrol edip onaylayın.', 'bilgi');
  const p = govde.veri;
  const sat = (p.kalem_tahsilatlari || []).map((k) => `<li>${esc(k.tip)} #${k.adisyon_hizmet_id || k.adisyon_urun_id || k.adisyon_paket_id} → <b>${TL(k.tutar)}</b></li>`).join('');
  const ind = (p.indirim_uygulamalari || []).map((i) => `<li>indirim ${esc(i.tip)} → ${TL(i.delta)}</li>`).join('');
  const uy = (p.uyarilar || []).map((u) => `<li style="color:#b00020">${esc(u)}</li>`).join('');
  el('#t-plan').innerHTML = `<div class="t-plan"><h4>Önizleme (henüz yazılmadı)</h4>
    <div>Ödeme: <b>${TL(p.parent_tahsilat?.tutar)}</b></div>
    <div style="margin-top:6px">Kalemlere dağıtım:</div><ul>${sat || '<li>-</li>'}</ul>
    ${ind ? '<div>İndirim:</div><ul>' + ind + '</ul>' : ''}${uy ? '<div>Uyarılar:</div><ul>' + uy + '</ul>' : ''}
    <button id="t-onayla" class="t-btn yesil">Onayla ve Kaydet</button></div>`;
  el('#t-onayla').onclick = kaydet;
}
async function kaydet() {
  el('#t-onayla').disabled = true;
  const g = girdi(false); g.idempotency_key = idemKey || uuid();
  const { ok, kod, govde } = await api('POST', '/tahsilat', { body: g });
  if (!ok) { mesaj(`Kaydedilemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); el('#t-onayla').disabled = false; return; }
  mesaj('Tahsilat kaydedildi' + (govde.veri?.replay ? ' (zaten işlenmişti)' : '') + '. Tahsilat #' + (govde.veri?.tahsilat_id ?? '-'), 'ok');
  el('#t-plan').innerHTML = '';
  tutarManuel = false;
  indirimManuel = false;
  tahsilAlacaklar.clear();
  await adisyonYukle();
  tahsilAlacakCiz();
}

function tahsilatDuzenle(id) {
  const o = (adisyon.tahsilatlar || []).find((x) => x.id === id);
  if (!o) return;
  tahsilAlacaklar.clear(); tahsilAlacakCiz(); // düzenleme yeni alacak kapatmaz
  duzenlenenTahsilat = id;
  tutarManuel = true;
  el('#t-tutar').value = o.tutar;
  el('#t-yontem').value = String(o.odeme_yontemi_id || 1);
  tarihAyarla('#t-tarih', o.odeme_tarihi);
  el('#t-indirim').value = '0';
  el('#t-plan').innerHTML = '';
  el('#t-onizle').textContent = 'Değişikliği Kaydet';
  el('#t-duzen-vazgec').hidden = false;
  mesaj('Tahsilat #' + id + ' düzenleniyor. Tutarı/yöntemi değiştirip kaydedin.', 'bilgi');
  el('#t-tutar').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function tahsilatDuzenVazgec() {
  duzenlenenTahsilat = null;
  tutarManuel = false;
  el('#t-onizle').textContent = 'Tahsil Et (Önizle)';
  el('#t-duzen-vazgec').hidden = true;
  indirimManuel = false;
  tutarOner();
}

async function tahsilatGuncelleKaydet() {
  const g = girdi(false); g.idempotency_key = uuid();
  const id = duzenlenenTahsilat;
  const r = await kodluIstek('PATCH', '/tahsilat/' + id, { body: g }); // tutar düşerse SMS ayarı 23: hesap sahibi onayı
  if (!r) return;
  const { ok, kod, govde } = r;
  if (!ok) { mesaj(`Güncellenemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  mesaj('Tahsilat güncellendi.', 'ok');
  tahsilatDuzenVazgec();
  await adisyonYukle();
}

async function tahsilatSil(id) {
  const onaylandi = await onay('Bu tahsilatı iade edip silmek istediğinize emin misiniz?', { baslik: 'Tahsilatı İade Et', tamam: 'İade Et', iptal: 'Vazgeç' });
  if (!onaylandi) return;
  const r = await kodluIstek('DELETE', '/tahsilat/' + id); // SMS ayarı 23: hesap sahibi onayı
  if (!r) return;
  const { ok, kod, govde } = r;
  if (!ok) { mesaj(`İade edilemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  mesaj('Tahsilat iade edildi.', 'ok');
  if (duzenlenenTahsilat === id) tahsilatDuzenVazgec();
  await adisyonYukle();
}

/* ───────────── Alacak: düzenle / iptal ─────────────
   Yeni alacak "Taksit Yap" ile açılır (kullanıcı 2026-11-10: ayrı "Alacak (Vade) Oluştur" kaldırıldı). Form yalnız
   taksite bağlı olmayan eski alacakları düzenlemek için satırdaki kalemle açılır. */
function alacakFormKapat() {
  el('#t-alacak-form').hidden = true;
  duzenlenenAlacak = null;
}
function alacakDuzenle(id) {
  const x = (adisyon.alacaklar || []).find((a) => a.id === id);
  if (!x) return;
  el('#t-taksit-form').hidden = true;
  duzenlenenAlacak = id;
  el('#t-alacak-form').hidden = false;
  el('#t-alacak-tutar').value = x.tutar;
  el('#t-alacak-not').value = x.aciklama || '';
  tarihAyarla('#t-alacak-tarih', x.planlanan_odeme_tarihi);
  el('#t-alacak-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
}
async function alacakKaydet() {
  if (!duzenlenenAlacak) return;
  const tutar = parseFloat(el('#t-alacak-tutar').value || '0');
  if (!(tutar > 0)) { mesaj('Alacak tutarı girin', 'hata'); return; }
  const { ok, kod, govde } = await api('PATCH', '/alacak/' + duzenlenenAlacak, { body: {
    tutar,
    planlanan_odeme_tarihi: tarihDeger('#t-alacak-tarih') || null,
    aciklama: el('#t-alacak-not').value || null,
  } });
  if (!ok) { mesaj(`Alacak kaydedilemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  mesaj('Alacak güncellendi.', 'ok');
  alacakFormKapat();
  await adisyonYukle();
}
async function alacakSil(alacakId) {
  const onaylandi = await onay('Bu planlanan alacağı iptal etmek istediğinize emin misiniz?', { baslik: 'Alacağı İptal Et', tamam: 'İptal Et', iptal: 'Vazgeç' });
  if (!onaylandi) return;
  const { ok, kod, govde } = await api('DELETE', '/alacak/' + alacakId);
  if (!ok) { mesaj(`Alacak iptal edilemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  mesaj('Alacak iptal edildi.', 'ok');
  await adisyonYukle();
}

/* ───────────── Taksit Yap (eski "Yeni Taksitli Tahsilat"): ön ödeme + kalan aylık taksit ───────────── */
function taksitKalan() {
  const on = parseFloat(el('#t-taksit-on').value || '0') || 0;
  const k = Math.round(((Number(adisyon?.taksitlenebilir) || 0) - on) * 100) / 100;
  el('#t-taksit-kalan').value = k > 0 ? k : 0;
}
function taksitFormAc() {
  if (!adisyonId) { mesaj('Önce en az bir kalem ekleyin.', 'bilgi'); return; }
  const f = el('#t-taksit-form');
  if (!f.hidden) { f.hidden = true; return; }
  if (!(Number(adisyon?.taksitlenebilir) > 0)) { mesaj('Taksitlendirilecek kalan borç yok.', 'bilgi'); return; }
  alacakFormKapat();
  f.hidden = false;
  el('#t-taksit-on').value = 0;
  el('#t-taksit-sayi').value = '';
  tarihAyarla('#t-taksit-tarih', new Date().toISOString().slice(0, 10));
  taksitKalan();
}
let taksitKey = null;
async function taksitKaydet() {
  const vade = parseInt(el('#t-taksit-sayi').value, 10);
  if (!(vade >= 1)) { mesaj('Taksit sayısını girin', 'hata'); return; }
  const baslangic = tarihDeger('#t-taksit-tarih');
  if (!baslangic) { mesaj('Ödeme başlangıç tarihini girin', 'hata'); return; }
  const btn = el('#t-taksit-kaydet');
  btn.disabled = true;
  taksitKey ||= uuid();
  const { ok, kod, govde } = await api('POST', `/adisyon/${adisyonId}/taksit`, { body: {
    on_odeme: parseFloat(el('#t-taksit-on').value || '0') || 0,
    on_odeme_yontemi_id: parseInt(el('#t-taksit-yontem').value, 10),
    odeme_tarihi: tarihDeger('#t-tarih') || null,
    vade, vade_baslangic_tarihi: baslangic,
    notlar: el('#t-not').value || null,
    idempotency_key: taksitKey,
  } });
  btn.disabled = false;
  if (!ok) { mesaj(`Taksit oluşturulamadı (${kod}): ${govde?.mesaj || ''}`, 'hata'); if (kod >= 400 && kod < 500 && kod !== 409) taksitKey = null; return; }
  taksitKey = null;
  mesaj('Taksitli alacak kayıtları oluşturuldu.', 'ok');
  el('#t-taksit-form').hidden = true;
  await adisyonYukle();
}

/* ───────────── İskelet ───────────── */
function iskelet() {
  kok.innerHTML = `
    <div class="t-baslik">
      <div>
        <h1 id="t-baslik-h1">Satış &amp; Tahsilat Düzenleme</h1>
        <div class="t-alt"><b id="t-musteri"></b> <span id="t-adisyon-no"></span>
          <label class="t-satis-tarih t-yeni-gizle"><i class="bi bi-calendar3"></i> Satış Tarihi
            <input id="t-satis-tarih" type="text" class="t-date" placeholder="YYYY-AA-GG" autocomplete="off"></label></div>
        <div id="t-musteri-sec" class="t-musteri-sec"></div>
      </div>
      <span class="t-durum t-yeni-gizle" id="t-durum"></span>
    </div>

    <div class="t-ozet-serit" id="t-ozet-serit"></div>

    <div class="t-kolon">
      <!-- SOL: kalemler + ekleme + tahsilatlar + alacaklar -->
      <div class="t-sol-grup">
        <section class="t-kart">
          <div class="t-kart-bas">
            <h3>Kalemler</h3>
            <div class="t-ekle-btns">
              <button class="t-mini mor"     data-tip="hizmet">+ Hizmet</button>
              <button class="t-mini kirmizi" data-tip="urun">+ Ürün</button>
              <button class="t-mini koyu"    data-tip="paket">+ Paket</button>
            </div>
          </div>
          <div class="t-ekle-panel" id="t-ekle-panel" hidden>
            <label id="t-ekle-baslik">Kalem Ekle</label>
            <div class="t-ekle-satir">
              <select id="t-ekle-tip" data-arama="yok" style="display:none"><option value="hizmet">Hizmet</option><option value="urun">Ürün</option><option value="paket">Paket</option></select>
              <select id="t-ekle-katalog" class="t-katalog"></select>
              <select id="t-ekle-personel" class="t-personel" aria-label="Personel"></select>
              <span id="t-ekle-adet-kutu"><input id="t-ekle-adet" class="t-adet" type="number" min="1" value="1" title="Adet"></span>
              <input id="t-ekle-seans" class="t-adet t-ekle-seans" type="number" min="0" placeholder="Seans (ops.)" title="Boş: tek hizmet · 2 ve üzeri: paket satışı" aria-label="Seans sayısı">
              <input id="t-ekle-fiyat" class="t-adet" type="number" step="0.01" min="0" placeholder="Fiyat ₺" title="Fiyat (₺)" aria-label="Fiyat">
              <button id="t-ekle-btn" class="t-mini mor">Ekle</button>
              <button id="t-ekle-iptal" class="t-mini" style="background:#8a90ad">Vazgeç</button>
            </div>
            <div id="t-ekle-paket" class="t-ekle-paket" hidden></div>
          </div>
          <div class="t-kart-govde" id="t-kalemler"></div>
        </section>

        <section class="t-kart t-gecmis">
          <div class="t-kart-bas"><h3>Yapılan Tahsilatlar</h3></div>
          <div class="t-kart-govde" id="t-gecmis"></div>
        </section>

        <section class="t-kart t-alacaklar">
          <div class="t-kart-bas"><h3>Planlanan Alacaklar</h3></div>
          <div class="t-kart-govde" id="t-alacaklar"></div>
        </section>
      </div>

      <!-- SAĞ: ödeme / özet -->
      <aside class="t-kart t-sag">
        <div class="t-odeme-bas">Ödeme</div>
        <div class="t-studyo-panel">
          <div id="t-studyo-rozet"></div>
          <button id="t-studyo-alindi" class="t-btn mor"><i class="bi bi-check-lg"></i> Ödeme Alındı</button>
          <button id="t-studyo-alinmadi" class="t-btn ikincil">Ödeme Alınmadı</button>
          <label>Ödeme Alınma Tarihi</label>
          <input id="t-studyo-tarih" type="text" class="t-date" placeholder="GG.AA.YYYY">
          <button id="t-studyo-tarih-kaydet" class="t-btn ikincil">Tarihi Kaydet</button>
          <button id="t-studyo-plan" class="t-btn ikincil"><i class="bi bi-arrow-repeat"></i> Otomatik Ders Planı</button>
        </div>
        <div class="t-alacak"><span>Kalan Alacak (₺)</span><b id="t-kalan-buyuk">—</b></div>
        <div class="t-ozet-detay" id="t-ozet-detay"></div>
        <div class="t-form">
          <div id="t-tahsil-alacak" class="t-tahsil-alacak" hidden></div>
          <div id="t-indirimler" class="t-indirimler" hidden></div>
          <div class="grid">
            <div><label>Tahsilat Tutarı (₺)</label><input id="t-tutar" type="number" step="0.01" min="0"></div>
            <div><label>İndirim (₺)</label><input id="t-indirim" type="number" step="0.01" min="0" value="0"></div>
            <div><label>Ödeme Yöntemi</label><select id="t-yontem"><option value="1">Nakit</option><option value="2">Kredi Kartı</option><option value="3">Havale/EFT</option></select></div>
            <div><label>Tarih</label><input id="t-tarih" type="text" class="t-date" placeholder="GG.AA.YYYY"></div>
          </div>
          <label>Not (opsiyonel)</label>
          <textarea id="t-not" rows="2"></textarea>
          <button id="t-onizle" class="t-btn mor">Tahsil Et (Önizle)</button>
          <button id="t-duzen-vazgec" class="t-btn ikincil" hidden>Düzenlemeden Vazgeç</button>

          <!-- Alacak düzenleme (satırdaki kalemle açılır) -->
          <div id="t-alacak-form" class="t-alacak-form" hidden>
            <div class="grid">
              <div><label>Alacak Tutarı (₺)</label><input id="t-alacak-tutar" type="number" step="0.01" min="0"></div>
              <div><label>Planlanan Ödeme Tarihi</label><input id="t-alacak-tarih" type="text" class="t-date" placeholder="GG.AA.YYYY"></div>
            </div>
            <label>Not (opsiyonel)</label>
            <input id="t-alacak-not" type="text" placeholder="örn. 2. taksit">
            <button id="t-alacak-kaydet" class="t-btn mor">Değişikliği Kaydet</button>
          </div>

          <!-- Taksit Yap: ön ödeme + kalan aylık taksit -->
          <button id="t-taksit-ac" class="t-btn ikincil"><i class="bi bi-calendar3-range"></i> Taksit Yap</button>
          <div id="t-taksit-form" class="t-alacak-form" hidden>
            <div class="grid">
              <div><label>Ön Ödeme Tutarı (₺)</label><input id="t-taksit-on" type="number" step="0.01" min="0" value="0"></div>
              <div><label>Ön Ödeme Türü</label><select id="t-taksit-yontem"><option value="1">Nakit</option><option value="2">Kredi Kartı</option><option value="3">Havale/EFT</option></select></div>
              <div><label>Kalan Ödeme Tutarı (₺)</label><input id="t-taksit-kalan" type="number" disabled></div>
              <div><label>Taksit Sayısı (Ay)</label><input id="t-taksit-sayi" type="number" min="1" max="60" step="1"></div>
              <div><label>Ödeme Başlangıç Tarihi</label><input id="t-taksit-tarih" type="text" class="t-date" placeholder="GG.AA.YYYY"></div>
            </div>
            <button id="t-taksit-kaydet" class="t-btn mor">Taksitleri Oluştur</button>
          </div>
        </div>
        <div class="t-mesaj" id="t-mesaj"></div>
        <div id="t-plan"></div>
      </aside>
    </div>`;

  datepickerBagla();
  el('#t-satis-tarih').onchange = satisTarihiKaydet;
  tarihAyarla('#t-tarih', new Date().toISOString().slice(0, 10));
  el('#t-tutar').addEventListener('input', () => { if (!duzenlenenTahsilat) tutarManuel = true; });
  el('#t-indirim').addEventListener('input', () => { if (!duzenlenenTahsilat) { indirimManuel = true; tutarOner(); } });
  el('#t-onizle').onclick = odemeButon;
  el('#t-studyo-alindi').onclick = () => studyoOdeme(true);
  el('#t-studyo-alinmadi').onclick = () => studyoOdeme(false);
  el('#t-studyo-tarih-kaydet').onclick = studyoOdemeTarihi;
  el('#t-studyo-plan').onclick = () => dersPlani({ salonId, musteriId: adisyon.adisyon.user_id });
  el('#t-duzen-vazgec').onclick = tahsilatDuzenVazgec;
  el('#t-ekle-btn').onclick = kalemEkle;
  el('#t-ekle-katalog').onchange = ekleFiyatOner;
  el('#t-ekle-adet').oninput = ekleAdetDegisti;
  el('#t-ekle-iptal').onclick = () => { el('#t-ekle-panel').hidden = true; };
  paketSatisBagla(el('#t-ekle-paket'), { paketBul: katalogPaketi, hizmetler: () => katalog?.hizmetler, fiyatAlani: () => el('#t-ekle-fiyat') });
  document.querySelectorAll('.t-ekle-btns [data-tip]').forEach((b) => (b.onclick = () => ekleAc(b.dataset.tip)));
  el('#t-alacak-kaydet').onclick = alacakKaydet;
  el('#t-taksit-ac').onclick = taksitFormAc;
  el('#t-taksit-on').oninput = taksitKalan;
  el('#t-taksit-kaydet').onclick = taksitKaydet;
}

iskelet();
if (adisyonId) adisyonYukle(); else yeniSatisBaslat();
