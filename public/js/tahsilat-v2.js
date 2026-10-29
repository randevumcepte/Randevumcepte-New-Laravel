/**
 * Tahsilat v2 — build'siz island (sade ES module).
 *
 * /api/v2 uçlarını Bearer token ile tüketir (hem web paneli hem ileride Flutter
 * AYNI uçlar). Akış: adisyonu yükle → tutar/indirim gir → ÖNİZLE (dry-run, hiçbir
 * şey yazmaz) → ONAYLA (gerçek yazma; flag kapalıysa sunucu 409 döner).
 *
 * Token: localStorage['rc_access_token']. Panel bu sayfayı ?token=... ile açarsa
 * token saklanır (pilot). Üretimde eski panel token'ı enjekte eder.
 */

const BASE = '/api/v2';

// ?token= geldiyse sakla (pilot kolaylığı).
const _qp = new URLSearchParams(location.search);
if (_qp.get('token')) {
  localStorage.setItem('rc_access_token', _qp.get('token'));
}

function token() {
  return localStorage.getItem('rc_access_token') || '';
}

async function api(method, yol, body) {
  const r = await fetch(BASE + yol, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: 'Bearer ' + token(),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const metin = await r.text();
  const veri = metin ? JSON.parse(metin) : null;
  return { ok: r.ok, kod: r.status, govde: veri };
}

const TL = (n) => (Number(n) || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺';
const el = (sel) => document.querySelector(sel);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

const kok = document.getElementById('rc-tahsilat');
const adisyonId = parseInt(kok.dataset.adisyon, 10);
let adisyon = null;

function uuid() {
  return (crypto.randomUUID && crypto.randomUUID()) || ('id-' + Date.now() + '-' + Math.random().toString(16).slice(2));
}

function mesajGoster(metin, tip = 'bilgi') {
  const renk = tip === 'hata' ? '#b00020' : tip === 'ok' ? '#0a7d2c' : '#334';
  el('#rc-mesaj').innerHTML = `<div style="padding:10px 12px;border-radius:8px;background:#f4f4f8;color:${renk};font-weight:600">${esc(metin)}</div>`;
}

async function adisyonYukle() {
  const { ok, kod, govde } = await api('GET', '/adisyon/' + adisyonId);
  if (!ok) {
    mesajGoster(`Adisyon yüklenemedi (${kod}): ${govde?.mesaj || ''}`, 'hata');
    return;
  }
  adisyon = govde.veri;
  const h = adisyon.hesap;
  el('#rc-ozet').innerHTML = `
    <div class="rc-kutu">
      <div><span>Net</span><b>${TL(h.net)}</b></div>
      <div><span>Ödenen</span><b>${TL(h.odenen)}</b></div>
      <div><span>Kalan</span><b style="color:${h.kalan > 0 ? '#b00020' : '#0a7d2c'}">${TL(h.kalan)}</b></div>
      <div><span>Durum</span><b>${esc(h.durum)}</b></div>
    </div>`;
  // Kalan varsa tutar alanına öner.
  if (h.kalan > 0 && !el('#rc-tutar').value) el('#rc-tutar').value = h.kalan;
}

function planGoster(plan) {
  const sat = (plan.kalem_tahsilatlari || []).map((k) => `<li>${esc(k.tip)} #${k.adisyon_hizmet_id || k.adisyon_urun_id || k.adisyon_paket_id} → <b>${TL(k.tutar)}</b></li>`).join('');
  const ind = (plan.indirim_uygulamalari || []).map((i) => `<li>indirim ${esc(i.tip)} → ${TL(i.delta)}</li>`).join('');
  const uy = (plan.uyarilar || []).map((u) => `<li style="color:#b00020">${esc(u)}</li>`).join('');
  el('#rc-plan').innerHTML = `
    <div class="rc-plan">
      <h4>Önizleme (yazılmadı)</h4>
      <div>Ödeme: <b>${TL(plan.parent_tahsilat?.tutar)}</b>${plan.parent_tahsilat?.komisyon_tutari > 0 ? ' · komisyon ' + TL(plan.parent_tahsilat.komisyon_tutari) : ''}</div>
      <div>Kalemlere dağıtım:</div><ul>${sat || '<li>-</li>'}</ul>
      ${ind ? '<div>İndirim:</div><ul>' + ind + '</ul>' : ''}
      ${uy ? '<div>Uyarılar:</div><ul>' + uy + '</ul>' : ''}
      <button id="rc-onayla" class="rc-btn rc-btn-ok">Onayla ve Kaydet</button>
    </div>`;
  el('#rc-onayla').onclick = kaydet;
}

function girdiTopla(dryRun) {
  return {
    salon_id: adisyon.adisyon.salon_id,
    adisyon_id: adisyonId,
    tutar: parseFloat(el('#rc-tutar').value || '0'),
    indirim: parseFloat(el('#rc-indirim').value || '0'),
    odeme_yontemi_id: parseInt(el('#rc-yontem').value, 10),
    odeme_tarihi: el('#rc-tarih').value,
    notlar: el('#rc-not').value || null,
    dry_run: dryRun,
  };
}

let sonIdemKey = null;

async function onizle() {
  el('#rc-plan').innerHTML = '';
  sonIdemKey = uuid(); // bu önizlemeye bağlı tek anahtar
  const { ok, kod, govde } = await api('POST', '/tahsilat', girdiTopla(true));
  if (!ok) {
    mesajGoster(`Önizleme hatası (${kod}): ${govde?.mesaj || ''}`, 'hata');
    return;
  }
  mesajGoster('Önizleme hazır — kontrol edip onaylayın.', 'bilgi');
  planGoster(govde.veri);
}

async function kaydet() {
  el('#rc-onayla').disabled = true;
  const g = girdiTopla(false);
  g.idempotency_key = sonIdemKey || uuid();
  const { ok, kod, govde } = await api('POST', '/tahsilat', g);
  if (!ok) {
    mesajGoster(`Kaydedilemedi (${kod}): ${govde?.mesaj || ''}`, 'hata');
    el('#rc-onayla').disabled = false;
    return;
  }
  const replay = govde.veri?.replay ? ' (zaten işlenmişti)' : '';
  mesajGoster('Tahsilat kaydedildi' + replay + '. Tahsilat #' + (govde.veri?.tahsilat_id ?? '-'), 'ok');
  el('#rc-plan').innerHTML = '';
  await adisyonYukle();
}

function iskelet() {
  kok.innerHTML = `
    <style>
      #rc-tahsilat{font-family:system-ui,Segoe UI,Roboto,sans-serif;max-width:560px;margin:16px auto;color:#222}
      #rc-tahsilat h3{margin:0 0 12px}
      .rc-kutu{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px}
      .rc-kutu>div{background:#f4f4f8;border-radius:8px;padding:10px;text-align:center}
      .rc-kutu span{display:block;font-size:12px;color:#667}
      .rc-form label{display:block;font-size:13px;margin:8px 0 2px;color:#445}
      .rc-form input,.rc-form select,.rc-form textarea{width:100%;padding:9px;border:1px solid #ccd;border-radius:8px;box-sizing:border-box}
      .rc-btn{margin-top:12px;padding:10px 16px;border:0;border-radius:8px;font-weight:700;cursor:pointer}
      .rc-btn-onizle{background:#5C008E;color:#fff}
      .rc-btn-ok{background:#0a7d2c;color:#fff;margin-top:10px}
      .rc-plan{margin-top:14px;padding:12px;border:1px solid #e0e0ea;border-radius:10px;background:#fbfbfe}
      .rc-plan h4{margin:0 0 8px;color:#5C008E}
      .rc-plan ul{margin:4px 0 10px;padding-left:18px}
    </style>
    <h3>Tahsilat (v2) — Adisyon #${adisyonId}</h3>
    <div id="rc-ozet"></div>
    <div class="rc-form">
      <label>Tahsilat tutarı (₺)</label><input id="rc-tutar" type="number" step="0.01" min="0" />
      <label>İndirim (₺, opsiyonel)</label><input id="rc-indirim" type="number" step="0.01" min="0" value="0" />
      <label>Ödeme yöntemi</label>
      <select id="rc-yontem"><option value="1">Nakit</option><option value="2">Kredi Kartı</option><option value="3">Havale/EFT</option></select>
      <label>Tarih</label><input id="rc-tarih" type="date" />
      <label>Not (opsiyonel)</label><textarea id="rc-not" rows="2"></textarea>
      <button id="rc-onizle" class="rc-btn rc-btn-onizle">Önizle</button>
    </div>
    <div id="rc-mesaj" style="margin-top:12px"></div>
    <div id="rc-plan"></div>`;
  el('#rc-tarih').value = new Date().toISOString().slice(0, 10);
  el('#rc-onizle').onclick = onizle;
}

iskelet();
adisyonYukle();
