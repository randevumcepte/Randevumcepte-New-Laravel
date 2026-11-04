/**
 * Ödül alanları (kupon / puan) — şablon formu ('odul_') ve Google ayarı ('google_odul_') TEK bileşen.
 * Alan adları kolon adlarıyla aynı; tip / indirim sözlükleri API'den (form-verisi).
 */
import { el, esc } from '/js/lib/v2-ortak.js?v=1';
import { alan, secenekler } from '/js/lib/v2-ongorusme.js?v=1';

const girdi = (ad, deger, ek = '') => `<input data-odul="${ad}" value="${esc(deger ?? '')}" ${ek}>`;

/** @param {object} k  kayıt (şablon / ayar) — yeni kayıtta {} */
export function odulAlanlari(onek, k, fv) {
  const gun = k[onek + 'tipi'] === 'kupon' ? k[onek + 'kupon_gecerlilik_gun'] : fv.varsayilan.kupon_gecerlilik_gun;
  return `
    <div class="an-odul rc-form-izgara" data-odul-onek="${esc(onek)}">
      ${alan('Ödül Tipi', `<select data-odul="tipi" data-arama="yok">${secenekler(fv.odul_tipleri, k[onek + 'tipi'] || 'yok', null, 'kod')}</select>`)}
      ${alan('İndirim Tipi', `<select data-odul="kupon_indirim_tipi" data-arama="yok">${secenekler(fv.indirim_tipleri, k[onek + 'kupon_indirim_tipi'] || 'yuzde', null, 'kod')}</select>`, 'an-odul-kupon')}
      ${alan('İndirim Değeri <small data-odul-birim></small>', girdi('kupon_deger', k[onek + 'kupon_deger'], 'type="number" min="0" step="0.01" placeholder="Örn: 10"'), 'an-odul-kupon')}
      ${alan('Geçerlilik (gün)', girdi('kupon_gecerlilik_gun', gun, 'type="number" min="0" max="3650" placeholder="Boş = süresiz"'), 'an-odul-kupon')}
      ${alan('Verilecek Puan', girdi('puan', k[onek + 'puan'], 'type="number" min="0" step="0.01" placeholder="Örn: 50"'), 'an-odul-puan')}
      ${alan('Ödül Başlığı <small>(opsiyonel)</small>', girdi('baslik', k[onek + 'baslik'], 'maxlength="200" placeholder="Boş bırakırsan otomatik oluşturulur"'), 'an-odul-baslik rc-tam')}
    </div>`;
}

/** Tip / indirim değişince ilgili alanları gösterir; oku() istek gövdesi alanlarını döner. */
export function odulBagla(kap) {
  const k = el('[data-odul-onek]', kap);
  const onek = k.dataset.odulOnek;
  const f = (ad) => el(`[data-odul="${ad}"]`, k);
  const tazele = () => {
    const tip = f('tipi').value;
    k.querySelectorAll('.an-odul-kupon').forEach((e) => { e.hidden = tip !== 'kupon'; });
    k.querySelectorAll('.an-odul-puan').forEach((e) => { e.hidden = tip !== 'puan'; });
    k.querySelectorAll('.an-odul-baslik').forEach((e) => { e.hidden = tip === 'yok'; });
    el('[data-odul-birim]', k).textContent = f('kupon_indirim_tipi').value === 'tutar' ? '(₺)' : '(%)';
  };
  f('tipi').addEventListener('change', tazele);
  f('kupon_indirim_tipi').addEventListener('change', tazele);
  tazele();
  return {
    oku: () => Object.fromEntries(['tipi', 'kupon_indirim_tipi', 'kupon_deger', 'kupon_gecerlilik_gun', 'puan', 'baslik']
      .map((ad) => [onek + ad, f(ad).value.trim() === '' ? null : f(ad).value.trim()])),
  };
}
