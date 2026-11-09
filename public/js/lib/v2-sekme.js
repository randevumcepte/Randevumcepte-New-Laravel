/**
 * v2 SEKMELİ SAYFA — TEK KAYNAK (stil: css/v2-bilesen.css .rc-sekmeler / .rc-sekme).
 * Import yolu her yerde AYNI olmalı:  import { sekmeliSayfa } from '/js/lib/v2-sekme.js?v=7';
 *
 * Sekme LİSTESİ ve görünürlüğü SUNUCUDAN gelir (web + Flutter aynı listeyi çizer); burada yalnız
 * çizim + tembel modül yükleme + adres çubuğu (?p=) durumu. Yeni sekme = sunucu listesine tek satır
 * + `moduller` haritasına tek satır; sayfa kodu değişmez.
 *
 *   const s = sekmeliSayfa(navKap, icerikKap, {
 *     sekmeler: [{kod, ad, ikon}],             // API'den
 *     moduller: { kod: () => import('/js/x.js?v=1') },   // modül: export async function ciz(kap, ctx, params)
 *     ctx: {...},                               // modüllere geçen ortak bağlam
 *     param: 'p',                               // adres çubuğu anahtarı
 *   });
 *   s.ac('takvim', { tarih: '2026-11-03' })    // sekmeler arası geçiş (params modüle iletilir)
 *   s.rozet('takvim', 3)                       // sekme sayacı (0/null → gizli)
 *
 * Modül ciz() bir {birak()} dönebilir: sekmeden çıkılırken çağrılır (zamanlayıcı/olay temizliği).
 * Her açılış kendi kabına çizer → hızlı sekme değişiminde geç gelen cevap ayrılmış kaba düşer.
 */
import { qp, esc } from '/js/lib/v2-ortak.js?v=7';

const YUKLENIYOR = '<div class="rc-sekme-yuk"><span class="rc-yukleniyor"></span></div>';

export function sekmeliSayfa(nav, icerik, { sekmeler, moduller, ctx = {}, param = 'p' }) {
  const rozetler = {};
  let aktif = null;
  let aktifModul = null;

  function ciz() {
    nav.setAttribute('role', 'tablist');
    nav.innerHTML = sekmeler.map((s) => `
      <button class="rc-sekme${s.kod === aktif ? ' aktif' : ''}" data-kod="${esc(s.kod)}" role="tab" aria-selected="${s.kod === aktif}" type="button">
        <i class="bi ${esc(s.ikon || 'bi-dot')}"></i> ${esc(s.ad)}${rozetler[s.kod] ? ` <span class="rc-sayac">${esc(rozetler[s.kod])}</span>` : ''}
      </button>`).join('');
  }

  async function ac(kod, params = {}) {
    const sekme = sekmeler.find((s) => s.kod === kod) || sekmeler[0];
    if (!sekme) return;
    try { aktifModul?.birak?.(); } catch (_) { /* yok say */ }
    aktifModul = null;
    aktif = sekme.kod;
    ciz();
    const url = new URL(location.href);
    url.searchParams.set(param, aktif);
    history.replaceState(null, '', url);

    const kap = document.createElement('div');
    kap.innerHTML = YUKLENIYOR;
    icerik.replaceChildren(kap);
    const yukle = moduller[aktif];
    if (!yukle) { kap.innerHTML = `<div class="rc-sekme-bos">${esc(sekme.ad)} henüz hazır değil.</div>`; return; }
    try {
      const m = await yukle();
      if (aktif !== sekme.kod) return;   // bu arada başka sekmeye geçildi
      aktifModul = (await m.ciz(kap, ctx, params)) || null;
    } catch (e) {
      kap.innerHTML = `<div class="rc-sekme-bos">Bölüm yüklenemedi: ${esc(e.message || e)}</div>`;
    }
  }

  nav.addEventListener('click', (e) => {
    const b = e.target.closest('[data-kod]');
    if (b && b.dataset.kod !== aktif) ac(b.dataset.kod);
  });

  ac(sekmeler.some((s) => s.kod === qp.get(param)) ? qp.get(param) : sekmeler[0]?.kod);

  return {
    ac,
    aktif: () => aktif,
    rozet(kod, n) { rozetler[kod] = n || null; ciz(); },
  };
}
