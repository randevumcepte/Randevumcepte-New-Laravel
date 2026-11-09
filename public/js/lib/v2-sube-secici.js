/**
 * v2 ÇOKLU ŞUBE SEÇİCİ — "Hangi şubelere uygulansın?" (eski partials/sube_secici) TEK KAYNAK.
 * Import yolu her yerde AYNI olmalı:  import { subeSecici } from '/js/lib/v2-sube-secici.js?v=7';
 *
 * Şube listesi API'den gelir (SubeGrubu::secenekler → [{id, ad, secili}]); tek şubede liste boş
 * gelir ve hiçbir şey çizilmez. Gönderimde `salon_ids: s.secili()` — sunucu yetkili şubelerle
 * kesiştirir (SubeGrubu::hedefler); boş dizi = yalnız mevcut şube.
 *
 *   const s = subeSecici(el('#kap'), acilis.subeler);
 *   s.secili()      // [12, 15]
 *   s.goster(false) // düzenleme modunda gizle (grup korunur)
 */
import { esc } from '/js/lib/v2-ortak.js?v=7';

export function subeSecici(kap, subeler = [], { baslik = 'Hangi şubelere uygulansın?' } = {}) {
  if (!kap || (subeler || []).length < 2) {
    if (kap) kap.innerHTML = '';
    return { secili: () => [], goster: () => {} };
  }
  kap.innerHTML = `
    <div class="rc-sube-secici">
      <div class="rc-sube-secici-bas"><i class="bi bi-shop"></i> ${esc(baslik)}</div>
      <label class="rc-sube-cip"><input type="checkbox" data-tumu> <b>Tümü</b></label>
      ${subeler.map((s) => `<label class="rc-sube-cip"><input type="checkbox" data-sube="${s.id}" ${s.secili ? 'checked' : ''}> ${esc(s.ad)}</label>`).join('')}
    </div>`;
  const tumu = kap.querySelector('[data-tumu]');
  const kutular = [...kap.querySelectorAll('[data-sube]')];
  const aktif = subeler.find((s) => s.secili)?.id;
  const esitle = () => { tumu.checked = kutular.every((k) => k.checked); };

  tumu.addEventListener('change', () => {
    kutular.forEach((k) => { k.checked = tumu.checked || Number(k.dataset.sube) === aktif; });   // en az mevcut şube kalır
    esitle();
  });
  kutular.forEach((k) => k.addEventListener('change', () => {
    if (!kutular.some((x) => x.checked)) k.checked = true;   // en az bir şube seçili kalsın
    esitle();
  }));
  esitle();

  return {
    secili: () => kutular.filter((k) => k.checked).map((k) => Number(k.dataset.sube)),
    goster: (v) => { kap.hidden = !v; },
  };
}
