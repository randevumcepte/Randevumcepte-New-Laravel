/**
 * Satış & Tahsilat Düzenleme (v2) — build'siz island (sade ES module).
 *
 * Eski panelin "Satış & Tahsilat Düzenleme" ekranının v2 karşılığı. /api/v2 uçlarını
 * Bearer token ile tüketir (web + ileride Flutter AYNI uçlar). Yetenekler:
 *   - Adisyon özeti + kalemleri HER DURUMDA (kapalı dahil) adıyla listeler.
 *   - Katalogdan hizmet/ürün/paket EKLE, ürün ADEDİ (kaybolmayan input), kalem SİL.
 *   - Tahsil Et: Önizle (dry-run) → Onayla (gerçek; flag kapalıysa 409).
 *
 * Token: localStorage['rc_access_token'] (pilotta ?token=...).
 */

const BASE = '/api/v2';
const _qp = new URLSearchParams(location.search);
if (_qp.get('token')) localStorage.setItem('rc_access_token', _qp.get('token'));
const token = () => localStorage.getItem('rc_access_token') || '';

async function api(method, yol, body) {
  const r = await fetch(BASE + yol, {
    method,
    cache: 'no-store', // bayat GET (ekleme sonrası eski toplam) önlemek için
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: 'Bearer ' + token() },
    body: body ? JSON.stringify(body) : undefined,
  });
  const t = await r.text();
  return { ok: r.ok, kod: r.status, govde: t ? JSON.parse(t) : null };
}

const TL = (n) => (Number(n) || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺';
const el = (s) => document.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'id-' + Date.now() + '-' + Math.random().toString(16).slice(2));

const kok = document.getElementById('rc-tahsilat');
const adisyonId = parseInt(kok.dataset.adisyon, 10);
let adisyon = null;
let katalog = null;
let salonId = null;

function mesaj(metin, tip = 'bilgi') {
  const renk = tip === 'hata' ? '#b00020' : tip === 'ok' ? '#0a7d2c' : '#334';
  el('#rc-mesaj').innerHTML = metin ? `<div class="rc-msg" style="color:${renk}">${esc(metin)}</div>` : '';
}

async function adisyonYukle() {
  const { ok, kod, govde } = await api('GET', '/adisyon/' + adisyonId);
  if (!ok) { mesaj(`Adisyon yüklenemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  adisyon = govde.veri;
  salonId = adisyon.adisyon.salon_id;
  ozetGoster();
  kalemlerGoster();
  if (!katalog) katalogYukle();
  const h = adisyon.hesap;
  if (h.kalan > 0 && !el('#rc-tutar').value) el('#rc-tutar').value = h.kalan;
}

async function katalogYukle() {
  const { ok, govde } = await api('GET', '/katalog?salon_id=' + salonId);
  if (ok) { katalog = govde.veri; katalogSelectDoldur(); }
}

function ozetGoster() {
  const h = adisyon.hesap;
  el('#rc-ozet').innerHTML = `
    <div class="rc-kutu"><div><span>Ara Toplam</span><b>${TL(h.net)}</b></div>
      <div><span>Ödenen</span><b>${TL(h.odenen)}</b></div>
      <div><span>Kalan (Alacak)</span><b style="color:${h.kalan > 0 ? '#b00020' : '#0a7d2c'}">${TL(h.kalan)}</b></div>
      <div><span>Durum</span><b>${esc(h.durum)}</b></div></div>`;
}

function kalemlerGoster() {
  const v = adisyon;
  const rows = [];
  (v.hizmetler || []).forEach((h) => rows.push(satir('hizmet', h.id, h.ad || 'Hizmet #' + h.hizmet_id, 'Hizmet' + (h.seans_sayisi ? ' · ' + h.seans_sayisi + ' seans' : ''), h.fiyat, h.indirim_tutari, null)));
  (v.paketler || []).forEach((p) => rows.push(satir('paket', p.id, p.ad || 'Paket #' + p.paket_id, 'Paket', p.fiyat, p.indirim_tutari, null)));
  (v.urunler || []).forEach((u) => rows.push(satir('urun', u.id, u.ad || 'Ürün #' + u.urun_id, 'Ürün', u.fiyat, u.indirim_tutari, u.adet)));
  const gec = (v.tahsilatlar || []).map((t) => `<tr><td colspan="3" style="color:#0a7d2c">Ödeme · ${esc(t.odeme_tarihi || '')}</td><td class="r" style="color:#0a7d2c">${TL(t.tutar)}</td><td></td></tr>`).join('');
  el('#rc-kalemler').innerHTML = `
    <table class="rc-tbl"><thead><tr><th>Kalem</th><th>Tür / Miktar</th><th class="r">Fiyat</th><th class="r">İndirim</th><th></th></tr></thead>
      <tbody>${rows.join('') || '<tr><td colspan="5" style="color:#889">Kalem yok</td></tr>'}</tbody>
      ${gec ? '<tfoot>' + gec + '</tfoot>' : ''}</table>`;
  kalemOlaylariBagla();
}

function satir(tip, id, ad, tur, fiyat, indirim, adet) {
  const miktar = tip === 'urun'
    ? `<input type="number" min="1" value="${adet || 1}" class="rc-adet" data-id="${id}" style="width:64px"> adet`
    : esc(tur);
  return `<tr>
    <td>${esc(ad)}</td><td>${miktar}</td>
    <td class="r">${TL(fiyat)}</td><td class="r">${TL(indirim || 0)}</td>
    <td class="r"><button class="rc-sil" data-tip="${tip}" data-id="${id}" title="Kalemi sil">✕</button></td></tr>`;
}

function kalemOlaylariBagla() {
  document.querySelectorAll('.rc-sil').forEach((b) => (b.onclick = () => kalemSil(b.dataset.tip, b.dataset.id)));
  document.querySelectorAll('.rc-adet').forEach((inp) => {
    let zaman;
    inp.oninput = () => {
      clearTimeout(zaman);
      const deger = inp.value;
      zaman = setTimeout(() => adetDegistir(inp.dataset.id, deger), 600);
    };
  });
}

function katalogSelectDoldur() {
  const tip = el('#rc-ekle-tip').value;
  const liste = tip === 'hizmet' ? katalog.hizmetler : tip === 'urun' ? katalog.urunler : katalog.paketler;
  const idAlan = tip === 'hizmet' ? 'hizmet_id' : tip === 'urun' ? 'urun_id' : 'paket_id';
  el('#rc-ekle-katalog').innerHTML = (liste || []).map((x) => `<option value="${x[idAlan]}">${esc(x.ad)} — ${TL(x.fiyat)}</option>`).join('');
  el('#rc-ekle-adet').style.display = tip === 'urun' ? '' : 'none';
}

async function kalemEkle() {
  const tip = el('#rc-ekle-tip').value;
  const katalogId = parseInt(el('#rc-ekle-katalog').value, 10);
  if (!katalogId) { mesaj('Katalogdan seçim yapın', 'hata'); return; }
  const body = { tip, katalog_id: katalogId };
  if (tip === 'urun') body.adet = parseInt(el('#rc-ekle-adet').value || '1', 10);
  const { ok, kod, govde } = await api('POST', '/adisyon/' + adisyonId + '/kalem', body);
  if (!ok) { mesaj(`Kalem eklenemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  mesaj('Kalem eklendi.', 'ok');
  await adisyonYukle();
}

async function adetDegistir(kalemId, adet) {
  const { ok, kod, govde } = await api('PATCH', '/kalem/urun/' + kalemId, { adet: parseInt(adet, 10) });
  if (!ok) { mesaj(`Adet güncellenemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  mesaj('Adet güncellendi.', 'ok');
  await adisyonYukle();
}

async function kalemSil(tip, kalemId) {
  if (!confirm('Kalemi silmek istediğinize emin misiniz?')) return;
  const { ok, kod, govde } = await api('DELETE', '/kalem/' + tip + '/' + kalemId);
  if (!ok) { mesaj(`Kalem silinemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  mesaj('Kalem silindi.', 'ok');
  await adisyonYukle();
}

// --- Tahsilat (ödeme) ---
function girdi(dryRun) {
  return {
    salon_id: salonId, adisyon_id: adisyonId,
    tutar: parseFloat(el('#rc-tutar').value || '0'),
    indirim: parseFloat(el('#rc-indirim').value || '0'),
    odeme_yontemi_id: parseInt(el('#rc-yontem').value, 10),
    odeme_tarihi: el('#rc-tarih').value,
    notlar: el('#rc-not').value || null,
    dry_run: dryRun,
  };
}
let idemKey = null;
async function onizle() {
  el('#rc-plan').innerHTML = '';
  idemKey = uuid();
  const { ok, kod, govde } = await api('POST', '/tahsilat', girdi(true));
  if (!ok) { mesaj(`Önizleme hatası (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  mesaj('Önizleme hazır — kontrol edip onaylayın.', 'bilgi');
  const p = govde.veri;
  const sat = (p.kalem_tahsilatlari || []).map((k) => `<li>${esc(k.tip)} #${k.adisyon_hizmet_id || k.adisyon_urun_id || k.adisyon_paket_id} → <b>${TL(k.tutar)}</b></li>`).join('');
  const ind = (p.indirim_uygulamalari || []).map((i) => `<li>indirim ${esc(i.tip)} → ${TL(i.delta)}</li>`).join('');
  const uy = (p.uyarilar || []).map((u) => `<li style="color:#b00020">${esc(u)}</li>`).join('');
  el('#rc-plan').innerHTML = `<div class="rc-plan"><h4>Önizleme (yazılmadı)</h4>
    <div>Ödeme: <b>${TL(p.parent_tahsilat?.tutar)}</b></div>
    <div>Kalemlere dağıtım:</div><ul>${sat || '<li>-</li>'}</ul>
    ${ind ? '<div>İndirim:</div><ul>' + ind + '</ul>' : ''}${uy ? '<div>Uyarılar:</div><ul>' + uy + '</ul>' : ''}
    <button id="rc-onayla" class="rc-btn rc-btn-ok">Onayla ve Kaydet</button></div>`;
  el('#rc-onayla').onclick = kaydet;
}
async function kaydet() {
  el('#rc-onayla').disabled = true;
  const g = girdi(false); g.idempotency_key = idemKey || uuid();
  const { ok, kod, govde } = await api('POST', '/tahsilat', g);
  if (!ok) { mesaj(`Kaydedilemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); el('#rc-onayla').disabled = false; return; }
  mesaj('Tahsilat kaydedildi' + (govde.veri?.replay ? ' (zaten işlenmişti)' : '') + '. Tahsilat #' + (govde.veri?.tahsilat_id ?? '-'), 'ok');
  el('#rc-plan').innerHTML = '';
  await adisyonYukle();
}

function iskelet() {
  kok.innerHTML = `
    <style>
      #rc-tahsilat{font-family:system-ui,Segoe UI,Roboto,sans-serif;max-width:720px;margin:14px auto;color:#222;padding:0 12px}
      .rc-head{background:linear-gradient(90deg,#6b1f8f,#5C008E);color:#fff;padding:14px 16px;border-radius:12px;font-weight:700;font-size:16px;margin-bottom:12px}
      .rc-kutu{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px}
      .rc-kutu>div{background:#f4f4f8;border-radius:8px;padding:10px;text-align:center}
      .rc-kutu span{display:block;font-size:12px;color:#667}
      .rc-tbl{width:100%;border-collapse:collapse;margin:10px 0;font-size:13px;background:#fff;border-radius:10px;overflow:hidden}
      .rc-tbl th,.rc-tbl td{padding:8px 10px;border-bottom:1px solid #eef;text-align:left}
      .rc-tbl th{background:#f4f4f8;color:#556;font-weight:600}
      .rc-tbl td.r,.rc-tbl th.r{text-align:right}
      .rc-tbl tfoot td{background:#f6fff8;font-weight:600}
      .rc-adet{padding:5px;border:1px solid #ccd;border-radius:6px}
      .rc-sil{background:#fde8e8;color:#b00020;border:0;border-radius:6px;padding:4px 9px;cursor:pointer;font-weight:700}
      .rc-ekle{display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:#faf8fe;border:1px solid #ece6f6;border-radius:10px;padding:10px;margin:8px 0 16px}
      .rc-ekle select,.rc-ekle input{padding:8px;border:1px solid #ccd;border-radius:8px}
      .rc-ekle select#rc-ekle-katalog{flex:1;min-width:180px}
      .rc-form label{display:block;font-size:13px;margin:8px 0 2px;color:#445}
      .rc-form input,.rc-form select,.rc-form textarea{width:100%;padding:9px;border:1px solid #ccd;border-radius:8px;box-sizing:border-box}
      .rc-grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
      .rc-btn{margin-top:12px;padding:10px 16px;border:0;border-radius:8px;font-weight:700;cursor:pointer}
      .rc-btn-mor{background:#5C008E;color:#fff}.rc-btn-ok{background:#0a7d2c;color:#fff;margin-top:10px}
      .rc-plan{margin-top:14px;padding:12px;border:1px solid #e0e0ea;border-radius:10px;background:#fbfbfe}
      .rc-plan h4{margin:0 0 8px;color:#5C008E}.rc-plan ul{margin:4px 0 10px;padding-left:18px}
      .rc-msg{padding:10px 12px;border-radius:8px;background:#f4f4f8;font-weight:600;margin:8px 0}
      .rc-sec{font-weight:700;color:#5C008E;margin:14px 0 4px}
    </style>
    <div class="rc-head">Satış &amp; Tahsilat Düzenleme — Adisyon #${adisyonId}</div>
    <div id="rc-ozet"></div>
    <div class="rc-sec">Kalemler</div>
    <div id="rc-kalemler"></div>
    <div class="rc-ekle">
      <select id="rc-ekle-tip"><option value="hizmet">Hizmet</option><option value="urun">Ürün</option><option value="paket">Paket</option></select>
      <select id="rc-ekle-katalog"></select>
      <input id="rc-ekle-adet" type="number" min="1" value="1" style="width:70px;display:none" title="Adet">
      <button id="rc-ekle-btn" class="rc-btn rc-btn-mor" style="margin:0">+ Ekle</button>
    </div>
    <div class="rc-sec">Tahsilat (Ödeme)</div>
    <div class="rc-form">
      <div class="rc-grid2">
        <div><label>Tahsilat tutarı (₺)</label><input id="rc-tutar" type="number" step="0.01" min="0"></div>
        <div><label>İndirim (₺, opsiyonel)</label><input id="rc-indirim" type="number" step="0.01" min="0" value="0"></div>
        <div><label>Ödeme yöntemi</label><select id="rc-yontem"><option value="1">Nakit</option><option value="2">Kredi Kartı</option><option value="3">Havale/EFT</option></select></div>
        <div><label>Tarih</label><input id="rc-tarih" type="date"></div>
      </div>
      <label>Not (opsiyonel)</label><textarea id="rc-not" rows="2"></textarea>
      <button id="rc-onizle" class="rc-btn rc-btn-mor">Önizle</button>
    </div>
    <div id="rc-mesaj"></div>
    <div id="rc-plan"></div>`;
  el('#rc-tarih').value = new Date().toISOString().slice(0, 10);
  el('#rc-onizle').onclick = onizle;
  el('#rc-ekle-btn').onclick = kalemEkle;
  el('#rc-ekle-tip').onchange = katalogSelectDoldur;
}

iskelet();
adisyonYukle();
