/**
 * v2 ORTAK sunucu-aramalı MÜŞTERİ SEÇİCİ — TEK KAYNAK (stil: css/v2-bilesen.css .rc-ms-*).
 * Import yolu her yerde AYNI olmalı:
 *   import { musteriSecici } from '/js/lib/v2-secici.js?v=1';
 *
 * En az 2 karakterde (300 ms gecikmeli) `yol`a {salon_id, q} ile sorar; cevap
 * [{id, ad, telefon}] (RandevuFormServisi::musteriAra sözleşmesi — telefon yetkiye göre
 * maskeli gelir). Seçilince ad + telefon kartı ve "Değiştir" gösterir.
 *
 *   const s = musteriSecici(el('#kap'), { salonId, yol: '/form/musteri-ara', onSec: (m) => {} });
 *   s.secili()   // {id, ad, telefon} | null
 *   s.temizle()
 */
import { api, esc } from '/js/lib/v2-ortak.js?v=1';

export function musteriSecici(kap, { salonId, yol, onSec = () => {}, ipucu = 'Ad veya telefon ile arayın' }) {
  let secili = null;
  let zaman, istekNo = 0;

  function ciz() {
    if (secili) {
      kap.innerHTML = `<div class="rc-ms-secili"><span class="rc-ms-avatar">${esc((secili.ad || '?').charAt(0))}</span>
        <div><b>${esc(secili.ad)}</b><small>${esc(secili.telefon || '')}</small></div>
        <button type="button" class="rc-ms-degistir">Değiştir</button></div>`;
      kap.querySelector('.rc-ms-degistir').onclick = () => { secili = null; ciz(); onSec(null); };
      return;
    }
    kap.innerHTML = `<div class="rc-ms-ara"><i class="bi bi-search"></i><input placeholder="${esc(ipucu)}" autocomplete="off"></div><div class="rc-ms-sonuclar"></div>`;
    const girdi = kap.querySelector('input');
    const sonuc = kap.querySelector('.rc-ms-sonuclar');
    girdi.oninput = () => {
      clearTimeout(zaman);
      const q = girdi.value.trim();
      if (q.length < 2) { sonuc.innerHTML = ''; return; }
      zaman = setTimeout(async () => {
        const no = ++istekNo;
        sonuc.innerHTML = '<div class="rc-ms-bos"><span class="rc-yukleniyor"></span></div>';
        const r = await api('GET', yol, { params: { salon_id: salonId, q } });
        if (no !== istekNo) return;
        const liste = r.ok && r.govde?.basarili ? r.govde.veri : [];
        sonuc.innerHTML = liste.length
          ? liste.map((m) => `<button type="button" data-id="${m.id}"><span class="rc-ms-avatar">${esc((m.ad || '?').charAt(0))}</span><b>${esc(m.ad)}</b><small>${esc(m.telefon || '')}</small></button>`).join('')
          : `<div class="rc-ms-bos">${esc((r.govde && !r.govde.basarili && r.govde.mesaj) || 'Sonuç yok')}</div>`;
        sonuc.querySelectorAll('[data-id]').forEach((b) => {
          b.onclick = () => { secili = liste.find((m) => String(m.id) === b.dataset.id); ciz(); onSec(secili); };
        });
      }, 300);
    };
  }
  ciz();

  return {
    secili: () => secili,
    temizle() { secili = null; ciz(); },
    odakla() { kap.querySelector('input')?.focus(); },
  };
}
