/**
 * Ayarlar (v2) sekme modüllerinin ORTAK parçaları — TEK KAYNAK (sekmelerde kopyalanmaz).
 * Çekirdek yardımcılar lib/v2-ortak.js'te; burada yalnız Ayarlar ekranına özgü kart/alan/
 * kaydet kalıpları var. Stil: css/ayarlar-v2.css (.ay-*) + v2-bilesen.css (.rc-*).
 *
 * Her sekme modülü `export async function ciz(kap, ctx)` sunar; ctx = {salonId}.
 */
import { api, esc, yazSonuc } from '/js/lib/v2-ortak.js?v=1';

/** GET /ayar/<yol>?salon_id= → veri; hata olursa kap'a mesaj çizer ve null döner. */
export async function yukle(kap, yol, salonId) {
  kap.innerHTML = '<div class="rc-yukleniyor">Yükleniyor…</div>';
  const r = await api('GET', yol, { params: { salon_id: salonId } });
  if (r.ok && r.govde && r.govde.basarili) return r.govde.veri;
  kap.innerHTML = `<div class="ay-bos">${esc((r.govde && r.govde.mesaj) || `Yüklenemedi (${r.kod})`)}</div>`;
  return null;
}

/** Yazma isteği + toast (başarı mesajı sunucudan). Başarılıysa veri, değilse null. */
export async function kaydet(method, yol, body) {
  const r = await api(method, yol, { body });
  return yazSonuc(r, r.govde && r.govde.mesaj) ? r.govde.veri : null;
}

/** Başlıklı kart. */
export const kart = (ikon, baslik, govde, alt = '') => `
  <section class="ay-kart">
    <header class="ay-kart-bas"><i class="bi ${esc(ikon)}"></i><h3>${esc(baslik)}</h3></header>
    ${alt ? `<p class="ay-kart-alt">${esc(alt)}</p>` : ''}
    <div class="ay-kart-govde">${govde}</div>
  </section>`;

/** Etiketli metin alanı (rc-alan). opt: {tip, tam, ipucu, maks, salt, ek} */
export function alan(ad, etiket, deger, opt = {}) {
  const ortak = `name="${esc(ad)}" ${opt.maks ? `maxlength="${opt.maks}"` : ''} ${opt.salt ? 'readonly' : ''} ${opt.ek || ''}`;
  const girdi = opt.tip === 'textarea'
    ? `<textarea ${ortak} rows="${opt.satir || 4}">${esc(deger)}</textarea>`
    : `<input type="${opt.tip || 'text'}" ${ortak} value="${esc(deger)}">`;
  return `<div class="rc-alan${opt.tam ? ' rc-tam' : ''}"><label>${esc(etiket)}</label>${girdi}${opt.ipucu ? `<span class="rc-ipucu">${esc(opt.ipucu)}</span>` : ''}</div>`;
}

/** Seçim kutusu. secenekler: [{kod, ad}] */
export const secim = (ad, etiket, deger, secenekler, opt = {}) => `
  <div class="rc-alan${opt.tam ? ' rc-tam' : ''}"><label>${esc(etiket)}</label>
    <select name="${esc(ad)}">${opt.bos ? `<option value="">${esc(opt.bos)}</option>` : ''}${secenekler
      .map((s) => `<option value="${esc(s.kod)}"${String(s.kod) === String(deger ?? '') ? ' selected' : ''}>${esc(s.ad)}</option>`).join('')}</select>
  </div>`;

/** Açıklamalı aç/kapa satırı. */
export const anahtar = (ad, etiket, acik, ipucu = '') => `
  <label class="ay-anahtar">
    <span class="rc-switch"><input type="checkbox" name="${esc(ad)}"${acik ? ' checked' : ''}><span class="rc-kaydir"></span></span>
    <span class="ay-anahtar-metin"><b>${esc(etiket)}</b>${ipucu ? `<small>${esc(ipucu)}</small>` : ''}</span>
  </label>`;

/** Sabit kaydet çubuğu. */
export const kaydetCubugu = (metin = 'Kaydet') =>
  `<div class="ay-kaydet"><button type="submit" class="rc-btn rc-btn-birincil"><i class="bi bi-check2"></i> ${esc(metin)}</button></div>`;

/** Form gönderimini bağlar: istek sürerken buton kilitlenir; isle(form) sunucu cevabını döndürür. */
export function gonderBagla(form, isle) {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('[type=submit]');
    if (btn) btn.disabled = true;
    try { await isle(form); } finally { if (btn) btn.disabled = false; }
  });
}

/** flatpickr saat seçici (24 saat); yoksa düz metin kalır. */
export function saatBagla(kok) {
  if (!window.flatpickr) return;
  kok.querySelectorAll('input[data-saat]').forEach((g) => flatpickr(g, {
    enableTime: true, noCalendar: true, dateFormat: 'H:i', time_24hr: true, minuteIncrement: 5, allowInput: true, disableMobile: true,
  }));
}

/** flatpickr tarih seçici (Y-m-d) — native date input KULLANILMAZ. */
export function tarihBagla(kok) {
  if (!window.flatpickr) return;
  kok.querySelectorAll('input[data-tarih]').forEach((g) => flatpickr(g, {
    dateFormat: 'Y-m-d', locale: 'tr', allowInput: true, disableMobile: true, minDate: 'today',
  }));
}

export const isaretli = (form, ad) => !!form.querySelector(`[name="${ad}"]`)?.checked;
export const deger = (form, ad) => form.querySelector(`[name="${ad}"]`)?.value ?? '';
