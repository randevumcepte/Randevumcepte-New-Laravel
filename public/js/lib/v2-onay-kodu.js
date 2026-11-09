/**
 * Iki adimli KODLU istek — TEK KAYNAK (silmede hesap sahibi onayi, seans "geldi" musteri dogrulama kodu).
 *   import { kodluIstek } from '/js/lib/v2-onay-kodu.js?v=7';
 *
 * Sunucu sozlesmesi (OnayKoduGerekliException): 428 {veri: {dogrulama_gerekli: true, kod_gonderildi}, mesaj}.
 * Kod sorulur, ayni istek kodla (govdede `alan`) tekrarlanir; "Yeni Kod" bos kodla tekrarlar (sunucu yeni kod
 * yollar). Vazgecilirse null doner; aksi halde son cevap ({ok, kod, govde}) — cagiran her zamanki gibi isler.
 * Flutter ayni uclari ayni alanlarla kullanir.
 */
import { api, el, esc } from '/js/lib/v2-ortak.js?v=7';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';

export const kodGerekli = ({ kod, govde }) => kod === 428 && !!govde?.veri?.dogrulama_gerekli;

/**
 * @param {string} method
 * @param {string} yol
 * @param {{params?:object, body?:object}} istek
 * @param {{alan?:string, baslik?:string}} opt  alan: onay_kodu (silme) | dogrulama_kodu (seans geldi)
 */
export async function kodluIstek(method, yol, { params, body } = {}, { alan = 'onay_kodu', baslik = 'Onay Kodu' } = {}) {
  let r = await api(method, yol, { params, body });
  while (kodGerekli(r)) {
    const kod = await kodSor(baslik, r.govde.mesaj || 'Doğrulama kodunu girin.');
    if (kod === null) return null;
    r = await api(method, yol, { params, body: { ...(body || {}), [alan]: kod } });
  }
  return r;
}

/** Kod penceresi: girilen kod | '' (yeni kod iste) | null (vazgec / kapat). */
function kodSor(baslik, mesaj) {
  return new Promise((coz) => {
    let bitti = false;
    const bitir = (deger) => { if (bitti) return; bitti = true; gozcu.disconnect(); modalKapat(); coz(deger); };
    const ov = modalAc(baslik, `<p>${esc(mesaj)}</p>
      <div class="rc-alan"><label>Kod</label><input name="kod" inputmode="numeric" autocomplete="one-time-code" maxlength="8" autofocus></div>`,
    `<button class="rc-btn rc-btn-ikincil" data-islem="vazgec">Vazgeç</button>
      <button class="rc-btn rc-btn-ikincil" data-islem="yeni">Yeni Kod</button>
      <button class="rc-btn rc-btn-birincil" data-islem="onay"><i class="bi bi-check2"></i> Onayla</button>`, { ikon: 'shield-lock', dar: true, sabit: true });
    // Esc / kapat dugmesi modali DOM'dan siler: vazgecilmis sayilir.
    const gozcu = new MutationObserver(() => { if (!document.body.contains(ov)) bitir(null); });
    gozcu.observe(document.body, { childList: true });
    const girdi = el('[name="kod"]', ov);
    el('[data-islem="vazgec"]', ov).onclick = () => bitir(null);
    el('[data-islem="yeni"]', ov).onclick = () => bitir('');
    el('[data-islem="onay"]', ov).onclick = () => { const k = girdi.value.trim(); if (k) bitir(k); else girdi.focus(); };
    girdi.addEventListener('keydown', (e) => { if (e.key === 'Enter') el('[data-islem="onay"]', ov).click(); });
  });
}
