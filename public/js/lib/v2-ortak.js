/**
 * v2 island'larinin ORTAK yardimcilari — TEK KAYNAK (ekranlarda kopyalanmaz).
 *
 * Build'siz: her ekran `import { ... } from '/js/lib/v2-ortak.js?v=1'` ile alir.
 * ONEMLI: import yolu (?v=N dahil) TUM ekranlarda AYNI olmali — farkli URL, tarayicida
 * ayri modul ornegi demektir (token tohumlama iki kez calisir). Bu dosya degisince
 * tum import satirlarindaki ?v= birlikte artirilir.
 */

import { ilkAlanHatasi } from '/js/lib/v2-hata.js?v=1';

export const BASE = '/api/v2';
export const qp = new URLSearchParams(location.search);

// Token tohumlama (modul tekil → bir kez):
//  - ?token=  : pilot/dogrudan link
//  - #token=  : eski panel koprusu (/isletmeyonetim/v2-gecis) — sunucu loglarina ve
//               Referer'a dusmez; saklandiktan sonra adres cubugundan silinir.
if (qp.get('token')) localStorage.setItem('rc_access_token', qp.get('token'));
const _hash = new URLSearchParams(location.hash.slice(1));
if (_hash.get('token')) {
  localStorage.setItem('rc_access_token', _hash.get('token'));
  history.replaceState(null, '', location.pathname + location.search);
}
export const token = () => localStorage.getItem('rc_access_token') || '';

/**
 * /api/v2 istegi. Donus: {ok, kod, govde} — govde ApiCevap zarfi
 * ({basarili, veri, mesaj}) ya da JSON degilse null.
 */
export async function api(method, yol, { params, body } = {}) {
  let url = BASE + yol;
  if (params) {
    const qs = new URLSearchParams(
      Object.fromEntries(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== '')),
    ).toString();
    if (qs) url += (url.includes('?') ? '&' : '?') + qs;
  }
  const headers = { Accept: 'application/json', Authorization: 'Bearer ' + token() };
  const govdeVar = body !== undefined && body !== null;
  if (govdeVar) headers['Content-Type'] = 'application/json';
  const r = await fetch(url, { method, cache: 'no-store', headers, body: govdeVar ? JSON.stringify(body) : undefined });
  const t = await r.text();
  let govde = null;
  try { govde = t ? JSON.parse(t) : null; } catch (_) { govde = null; }
  return { ok: r.ok, kod: r.status, govde };
}

export const el = (s, k = document) => k.querySelector(s);
export const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
export const pad = (n) => String(n).padStart(2, '0');
export const tarihStr = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
export const TL = (n) => (Number(n) || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺';

/** Kabugun (panel-kabuk.js) toast/onay'i; kabuk yoksa tarayici yedegi. */
export const panel = () => window.rcPanel || { toast: (m) => alert(m), onay: async (m) => confirm(m) };
export const mesaj = (metin, tip = 'bilgi') => panel().toast(metin, tip);
export const onay = (metin, opt) => panel().onay(metin, opt);

/** Yazma cevabi: basariliysa true (opsiyonel basari toast'i); degilse hata toast'i + false (409 kill-switch dahil). */
export function yazSonuc({ ok, kod, govde }, basariMsg) {
  if (ok && govde && govde.basarili) { if (basariMsg) mesaj(basariMsg, 'ok'); return true; }
  mesaj(ilkAlanHatasi(govde) || (govde && govde.mesaj) || `Hata (${kod})`, 'hata');
  return false;
}

/**
 * Sayfalama (Laravel paginator: current_page/last_page/total) — kap'a cizer,
 * tiklanan sayfa no'su onSayfa(no) ile bildirilir. Stil: panel.css .rc-sayfa*.
 */
export function sayfalamaCiz(kap, v, onSayfa, birim = 'kayıt') {
  const son = v.last_page || 1, su = v.current_page || 1;
  if (son <= 1) { kap.innerHTML = `<div class="rc-sayfa-bilgi">${v.total ?? 0} ${birim}</div>`; kap.onclick = null; return; }
  const btn = (p, ad, pasif) => `<button class="rc-sayfa${pasif ? ' pasif' : ''}${p === su ? ' aktif' : ''}" data-p="${p}" ${pasif ? 'disabled' : ''}>${ad}</button>`;
  let h = btn(su - 1, '‹', su <= 1);
  const bas = Math.max(1, Math.min(su - 2, son - 4)), bit = Math.min(son, bas + 4);
  for (let p = bas; p <= bit; p++) h += btn(p, p, false);
  h += btn(su + 1, '›', su >= son);
  kap.innerHTML = `<div class="rc-sayfa-bilgi">Sayfa ${su} / ${son} · ${v.total ?? 0} ${birim}</div>` + h;
  kap.onclick = (e) => {
    const b = e.target.closest('.rc-sayfa');
    if (!b || b.disabled) return;
    onSayfa(parseInt(b.dataset.p, 10));
  };
}
