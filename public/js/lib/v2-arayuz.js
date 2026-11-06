/** Toast + onay kutusu — TEK KAYNAK (isletme paneli ve sistem yonetimi kabuklari paylasir). Stil: panel.css .rc-toast / .rc-onay. */
import { esc } from '/js/lib/v2-ortak.js?v=2';

function toastKap() {
  let k = document.getElementById('rcToastKap');
  if (!k) { k = document.createElement('div'); k.id = 'rcToastKap'; k.className = 'rc-toast-kap'; document.body.appendChild(k); }
  return k;
}
export function toast(metin, tip = 'bilgi', sure = 3200) {
  if (!metin) return;
  const ikon = tip === 'ok' ? 'bi-check-circle-fill' : tip === 'hata' ? 'bi-exclamation-octagon-fill' : 'bi-info-circle-fill';
  const t = document.createElement('div');
  t.className = 'rc-toast ' + tip;
  t.innerHTML = `<i class="bi ${ikon}"></i><span>${esc(metin)}</span>`;
  toastKap().appendChild(t);
  requestAnimationFrame(() => t.classList.add('gir'));
  setTimeout(() => { t.classList.remove('gir'); setTimeout(() => t.remove(), 260); }, sure);
}
export function onay(mesaj, opt = {}) {
  const { baslik = 'Onay', tamam = 'Evet', iptal = 'Vazgeç', tehlike = true } = opt;
  return new Promise((coz) => {
    const ov = document.createElement('div');
    ov.className = 'rc-onay-overlay';
    ov.innerHTML = `<div class="rc-onay-kart" role="dialog" aria-modal="true">
      <div class="rc-onay-ikon ${tehlike ? 'tehlike' : ''}"><i class="bi ${tehlike ? 'bi-trash' : 'bi-question-lg'}"></i></div>
      <h3>${esc(baslik)}</h3><p>${esc(mesaj)}</p>
      <div class="rc-onay-btns">
        <button class="rc-onay-iptal">${esc(iptal)}</button>
        <button class="rc-onay-tamam ${tehlike ? 'tehlike' : ''}">${esc(tamam)}</button>
      </div></div>`;
    document.body.appendChild(ov);
    requestAnimationFrame(() => ov.classList.add('acik'));
    const kapat = (sonuc) => { ov.classList.remove('acik'); setTimeout(() => ov.remove(), 220); document.removeEventListener('keydown', tus); coz(sonuc); };
    const tus = (e) => { if (e.key === 'Escape') kapat(false); };
    ov.querySelector('.rc-onay-iptal').onclick = () => kapat(false);
    ov.querySelector('.rc-onay-tamam').onclick = () => kapat(true);
    ov.onclick = (e) => { if (e.target === ov) kapat(false); };
    document.addEventListener('keydown', tus);
  });
}

