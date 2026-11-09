/**
 * v2 ORTAK modal — TEK KAYNAK (stil: css/v2-bilesen.css .rc-modal*). Personel'den taşındı.
 * Import yolu her yerde AYNI olmalı:
 *   import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';
 *
 * Aynı anda tek modal açıktır (yeni modalAc öncekini kapatır). Esc / arka plana tık kapatır;
 * opt.sabit=true ise arka plan tıkı kapatmaz (uzun formlar — eski data-backdrop="static").
 */
import { el, esc } from '/js/lib/v2-ortak.js?v=7';

const ID = 'rcModal';

/**
 * @param {string} baslik
 * @param {string} govdeHtml
 * @param {string} altHtml   alt buton satırı (boşsa çizilmez)
 * @param {{ikon?:string, altbaslik?:string, dar?:boolean, genis?:boolean, sabit?:boolean}} opt
 * @returns {HTMLElement} overlay
 */
export function modalAc(baslik, govdeHtml, altHtml, opt = {}) {
  modalKapat();
  const ov = document.createElement('div');
  ov.className = 'rc-modal-overlay';
  ov.id = ID;
  const altb = opt.altbaslik ? `<div class="rc-modal-altbaslik">${esc(opt.altbaslik)}</div>` : '';
  const boy = opt.dar ? ' rc-dar' : (opt.genis ? ' rc-genis' : '');
  ov.innerHTML = `
    <div class="rc-modal${boy}" role="dialog" aria-modal="true">
      <div class="rc-modal-bas">
        <div class="rc-modal-bas-ic">
          <span class="rc-modal-ikon"><i class="bi bi-${esc(opt.ikon || 'person')}"></i></span>
          <div><h2>${esc(baslik)}</h2>${altb}</div>
        </div>
        <button class="rc-kapat" type="button" aria-label="Kapat">&times;</button>
      </div>
      <div class="rc-modal-govde">${govdeHtml}</div>
      ${altHtml ? `<div class="rc-modal-alt">${altHtml}</div>` : ''}
    </div>`;
  document.body.appendChild(ov);
  if (!opt.sabit) ov.addEventListener('click', (e) => { if (e.target === ov) modalKapat(); });
  el('.rc-kapat', ov).addEventListener('click', modalKapat);
  return ov;
}

export function modalKapat() {
  const m = document.getElementById(ID);
  if (m) m.remove();
}

document.addEventListener('keydown', (e) => { if (e.key === 'Escape') modalKapat(); });
