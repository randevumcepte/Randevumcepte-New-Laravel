/**
 * Ayarlar → Müşteri İndirimleri (v2). Eski #musteriindirimleri formuyla birebir: Sadık ve
 * Aktif müşteri için aç/kapa + yüzde. Kapalı = %0 (sunucu kuralı). Uçlar: GET/PUT /ayar/musteri-indirimleri.
 */
import { esc } from '/js/lib/v2-ortak.js?v=1';
import { yukle, kaydet, kart, anahtar, kaydetCubugu, gonderBagla, isaretli, deger } from '/js/ayarlar/ortak.js?v=4';

const TURLER = [
  { kod: 'sadik', ad: 'Sadık müşteri indirimi', ikon: 'bi-heart', ipucu: 'Sadık segmentteki müşterilerin satışlarına otomatik uygulanır.' },
  { kod: 'aktif', ad: 'Aktif müşteri indirimi', ikon: 'bi-lightning', ipucu: 'Aktif segmentteki müşterilerin satışlarına otomatik uygulanır.' },
];

export async function ciz(kap, ctx) {
  const v = await yukle(kap, '/ayar/musteri-indirimleri', ctx.salonId);
  if (v) formCiz(kap, ctx, v);
}

function formCiz(kap, ctx, v) {
  kap.innerHTML = `<form class="ay-form" novalidate>
    ${TURLER.map((t) => kart(t.ikon, t.ad, `
      ${anahtar(`${t.kod}_acik`, 'İndirim uygulansın', v[t.kod].acik, t.ipucu)}
      <div class="rc-form-izgara ay-dar" data-goster="${t.kod}_acik"><div class="rc-alan"><label>İndirim oranı (%)</label>
        <input type="number" name="${t.kod}_yuzde" min="0" max="100" step="0.01" value="${esc(v[t.kod].acik ? v[t.kod].yuzde : '')}"></div></div>`)).join('')}
    ${kaydetCubugu()}
  </form>`;

  const form = kap.querySelector('form');
  const gorunurluk = () => form.querySelectorAll('[data-goster]').forEach((b) => { b.hidden = !isaretli(form, b.dataset.goster); });
  gorunurluk();
  form.addEventListener('change', gorunurluk);
  gonderBagla(form, async () => {
    const body = { salon_id: ctx.salonId };
    TURLER.forEach((t) => { body[t.kod] = { acik: isaretli(form, `${t.kod}_acik`), yuzde: Number(deger(form, `${t.kod}_yuzde`)) || 0 }; });
    const yeni = await kaydet('PUT', '/ayar/musteri-indirimleri', body);
    if (yeni) formCiz(kap, ctx, yeni);
  });
}
