/**
 * Sistem yonetimi ekranlarinin ortak gorunum parcalari (sy-* siniflari) — TEK KAYNAK. Veri kurallari API'de.
 *   import { rozet, etiketRozet, sayfaBaslik, metrik, syTablo, ... } from '/js/sistem/ortak.js?v=1';
 */
import { esc, el } from '/js/lib/v2-ortak.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=8';
import { syApi, syYaz } from '/js/lib/v2-sistem.js?v=7';
import { impersonationBaslat } from '/js/lib/v2-sistem-bant.js?v=7';

export const kisalt = (metin, boy) => {
  const m = String(metin ?? '');
  return m.length > boy ? `${m.slice(0, boy)}…` : m;
};

export const rozet = (renk, metin) => `<span class="sy-badge sy-badge-${esc(renk)}">${esc(metin)}</span>`;

/** API'nin {etiket, renk} sozluk ogesi. */
export const etiketRozet = (d) => rozet(d?.renk || 'muted', d?.etiket ?? '—');

export const aktifRozet = (aktif) => (aktif ? rozet('success', 'Aktif') : rozet('muted', 'Pasif'));

export const secenekHtml = (liste, secili) => liste.map((s) => `<option value="${esc(s.kod)}"${String(s.kod) === String(secili) ? ' selected' : ''}>${esc(s.etiket)}</option>`).join('');

/** altHtml ve sag ham HTML: cagiran kacirir. */
export const sayfaBaslik = (baslik, altHtml = '', sag = '') => `
  <div class="sy-page-head">
    <div><h2>${esc(baslik)}</h2><div class="subtitle">${altHtml}</div></div>
    <div class="sy-flex-row">${sag}</div>
  </div>`;

export const geriLink = (href, metin = 'Geri') => `<a href="${esc(href)}" class="sy-btn"><span class="mdi mdi-arrow-left"></span> ${esc(metin)}</a>`;

export const bos = (ikon, baslik, alt = '') => `<div class="sy-empty">${ikon ? `<div class="icon mdi ${esc(ikon)}"></div>` : ''}<div class="baslik">${esc(baslik)}</div>${alt ? `<div>${esc(alt)}</div>` : ''}</div>`;

/** deger ve alt metindir; ek ham HTML (ilerleme cubugu gibi). */
export const metrik = ({ renk = '', ikon, etiket, deger, alt = '', ek = '' }) => `
  <div class="sy-metric ${esc(renk)}">
    <div class="icon-bg mdi ${esc(ikon)}"></div>
    <div class="label">${esc(etiket)}</div>
    <div class="value">${esc(deger)}</div>
    ${alt ? `<div class="delta">${esc(alt)}</div>` : ''}${ek}
  </div>`;

/** Bas harf yuvarlagi. */
export const avatar = (ad, boy = 30) => `<div style="width:${boy}px;height:${boy}px;border-radius:50%;background:var(--sy-primary-soft);color:var(--sy-primary);display:flex;align-items:center;justify-content:center;font-weight:600">${esc(String(ad ?? '').charAt(0))}</div>`;

/** Giris Loglari ve Salon Hesabi Girisleri ekranlari arasi gecis. */
export const guvenlikSekmeleri = (aktif) => [
  ['girisler', 'Girişler'], ['impersonation', 'Salon Hesabı Girişleri'],
].map(([yol, ad]) => `<a href="/sistemyonetim/guvenlik/${yol}" class="sy-btn${yol === aktif ? ' sy-btn-soft' : ''}">${ad}</a>`).join('');

/** Sunucu kaynakli tablo: yol + istege bagli filtre parametreleri. Ilk yuklemeyi de yapar. */
export function syTablo(kap, { yol, params = () => ({}), ...opt }) {
  const tablo = veriTablosu(kap, {
    tabloSinif: 'sy-table',
    varsayilanBoy: 50,
    kaynak: (p) => syApi('GET', yol, { params: { ...p, ...params() } }),
    ...opt,
  });
  tablo.yenile();
  return tablo;
}

/** Satir sonlarini koruyan duz metin. */
export const metinHtml = (m) => `<div style="white-space:pre-wrap;word-break:break-word">${esc(m)}</div>`;

export const sayi = (n) => (Number(n) || 0).toLocaleString('tr-TR');

/** Is bitene kadar butonu kilitler (cift tik). */
export async function kilitli(buton, is) {
  buton.disabled = true;
  try { return await is(); } finally { buton.disabled = false; }
}

/** flatpickr: gorunum d.m.Y H:i, deger Y-m-d H:i (input.value). */
export function tarihSaatBagla(girdi, deger) {
  if (deger) girdi.value = deger;
  if (!window.flatpickr) return;
  window.flatpickr(girdi, { locale: 'tr', enableTime: true, time_24hr: true, dateFormat: 'Y-m-d H:i', altInput: true, altFormat: 'd.m.Y H:i', disableMobile: true, allowInput: false });
}

/**
 * /salon-ara ile salon secici; destek rolunde sonuclar kendi salonlariyla sinirli.
 *   const s = salonSecici(kap, { coklu, baslangic: [{id, salon_adi}], ipucu });
 *   s.idler() → [id]   s.tekId() → id|null
 */
export function salonSecici(kap, { coklu = false, baslangic = [], ipucu = 'Salon adı ile ara...' } = {}) {
  let secili = [...baslangic];
  let zaman = null;
  let istekNo = 0;

  kap.innerHTML = `
    <input type="text" class="sy-input" placeholder="${esc(ipucu)}" autocomplete="off">
    <div data-sonuc style="display:none;max-height:180px;overflow:auto;margin-top:6px;border:1px solid var(--sy-border);border-radius:8px;background:var(--sy-surface)"></div>
    <div data-secili class="sy-flex-row sy-mt-12" style="flex-wrap:wrap"></div>`;
  const girdi = el('input', kap);
  const sonuc = el('[data-sonuc]', kap);
  const secimKap = el('[data-secili]', kap);

  const secimCiz = () => {
    secimKap.innerHTML = secili.map((s) => `<span class="sy-badge sy-badge-info" data-id="${s.id}" style="cursor:pointer" title="Kaldır">${esc(s.salon_adi)} ×</span>`).join('');
  };
  const kapat = () => { sonuc.style.display = 'none'; };

  girdi.addEventListener('input', () => {
    clearTimeout(zaman);
    const q = girdi.value.trim();
    if (q.length < 2) { kapat(); return; }
    zaman = setTimeout(async () => {
      const no = ++istekNo;
      const r = await syApi('GET', '/salon-ara', { params: { q } });
      if (no !== istekNo) return;
      const liste = r.ok && r.govde?.basarili ? r.govde.veri : [];
      sonuc.innerHTML = liste.length
        ? liste.map((s) => `<div class="sy-search-item" data-id="${s.id}" data-ad="${esc(s.salon_adi)}" style="cursor:pointer"><div class="name">${esc(s.salon_adi)}</div></div>`).join('')
        : '<div class="sy-text-muted sy-fs-12" style="padding:12px;text-align:center">Sonuç yok</div>';
      sonuc.style.display = 'block';
    }, 250);
  });
  sonuc.addEventListener('click', (e) => {
    const oge = e.target.closest('[data-id]');
    if (!oge) return;
    const id = parseInt(oge.dataset.id, 10);
    if (!secili.some((s) => s.id === id)) {
      const yeni = { id, salon_adi: oge.dataset.ad };
      secili = coklu ? [...secili, yeni] : [yeni];
    }
    girdi.value = '';
    kapat();
    secimCiz();
  });
  secimKap.addEventListener('click', (e) => {
    const c = e.target.closest('[data-id]');
    if (!c) return;
    secili = secili.filter((s) => s.id !== parseInt(c.dataset.id, 10));
    secimCiz();
  });
  document.addEventListener('click', (e) => { if (!kap.contains(e.target)) kapat(); });
  secimCiz();

  return { idler: () => secili.map((s) => s.id), tekId: () => secili[0]?.id ?? null };
}

/** Salon hesabina gir (kayit sunucuda tutulur); hedef verilmezse sunucunun acilis sayfasi. */
export async function hesabinaGir(salonId, sebep, { yetkiliId = null, ticketId = null, hedef = null } = {}) {
  const { tamam, veri } = await syYaz('POST', `/salon/${salonId}/hesabina-gir`, { sebep, yetkili_id: yetkiliId, ticket_id: ticketId }, '');
  if (tamam) impersonationBaslat(hedef ? { ...veri, hedef } : veri);
}
