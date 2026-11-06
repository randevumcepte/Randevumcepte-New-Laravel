/** Salon ekranlarinin ortak parcalari: secenekler, select cizimi, il-ilce bagi, form okuma, salon hesabina giris. */
import { esc } from '/js/lib/v2-ortak.js?v=2';
import { syVeri, syYaz } from '/js/lib/v2-sistem.js?v=1';

export const salonSecenekleri = () => syVeri('/salon/secenekler');

/** <option> listesi. bos verilirse basa bos secenek eklenir. */
export function secenekler(liste, deger, ad, secili, bos) {
  const ilk = bos === undefined ? '' : `<option value="">${esc(bos)}</option>`;
  return ilk + liste.map((s) => `<option value="${esc(s[deger])}"${String(s[deger]) === String(secili ?? '') ? ' selected' : ''}>${esc(s[ad])}</option>`).join('');
}

/** Il degisince ilceyi suzer; ilk cizimde mevcut ilce secili gelir. */
export function ilIlceBagla(ilSec, ilceSec, ilceler, seciliIlce) {
  const doldur = (secili) => {
    const il = Number(ilSec.value) || 0;
    ilceSec.innerHTML = secenekler(ilceler.filter((i) => Number(i.il_id) === il), 'id', 'ilce_adi', secili, 'Seçiniz');
  };
  ilSec.addEventListener('change', () => doldur(null));
  doldur(seciliIlce);
}

/** Form alanlari: bos metin null, isaretsiz kutu 0. */
export function formOku(form) {
  const veri = {};
  form.querySelectorAll('[name]').forEach((a) => {
    if (a.type === 'checkbox') veri[a.name] = a.checked ? 1 : 0;
    else veri[a.name] = a.value.trim() === '' ? null : a.value.trim();
  });
  return veri;
}

export const telLink = (tel) => String(tel ?? '').replace(/[^0-9+]/g, '');
