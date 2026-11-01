/**
 * v2 island'larinin ORTAK UI bilesenleri (cekirdek yardimcilar v2-ortak.js'te).
 * Stil: panel.css (.rc-ozet / .rc-kutu). Import yolu her yerde AYNI olmali:
 *   import { ozetKart } from '/js/lib/v2-bilesen.js?v=1';
 */
import { esc } from '/js/lib/v2-ortak.js?v=1';

/**
 * Ozet (istatistik) karti. renk: mor | yesil | turuncu | mavi | turkuaz.
 * Kartlari bir .rc-ozet kapsayicisina koyun.
 */
export const ozetKart = (ikon, etiket, deger, renk = 'mor') => `
  <div class="rc-kutu rc-k-${esc(renk)}">
    <div class="rc-k-ikon"><i class="bi ${esc(ikon)}"></i></div>
    <div class="rc-k-ic"><span class="rc-k-ad">${esc(etiket)}</span><span class="rc-k-deger">${esc(deger)}</span></div>
  </div>`;
