/**
 * Ayarlar → Odalar (v2). Tablo + işlemler kaynak.js'te (cihazlar ile ortak); burada oda formu
 * (ad + personeller + hizmetler — eski #yeniodabilgiekle / #odabilgiduzenle) ve personel/hizmet
 * kolonları. Seçenekler GET /ayar/odalar/form-verisi'nden.
 */
import { esc, el } from '/js/lib/v2-ortak.js?v=1';
import { kaynakSekmesi } from '/js/ayarlar/kaynak.js?v=3';
import { secimListesi, secimFiltreBagla, seciliIdler } from '/js/ayarlar/ortak.js?v=3';

const rozetler = (liste, alan) => (liste.length
  ? `<div class="ay-rozetler">${liste.map((x) => `<span class="rc-rozet rc-rozet-mor">${esc(x[alan])}</span>`).join('')}</div>`
  : '<span class="ay-soluk">—</span>');

export const { ciz } = kaynakSekmesi({
  yol: '/ayar/odalar',
  etiket: 'Oda',
  ikon: 'door-closed',
  anahtar: 'ayar-odalar',
  ekKolonlar: [
    { baslik: 'Personeller', hucre: (k) => rozetler(k.personeller || [], 'personel_adi') },
    { baslik: 'Hizmetler', hucre: (k) => rozetler(k.hizmetler || [], 'hizmet_adi') },
  ],
  formGovde: (d, f) => `
    <div class="rc-alan rc-tam"><label>Oda Adı *</label><input type="text" name="oda_adi" maxlength="255" value="${esc(d?.oda_adi || '')}"></div>
    ${secimListesi('personel_ids', 'Odada çalışan personeller', f.personeller, 'personel_adi', d?.personel_ids || [])}
    ${secimListesi('hizmet_ids', 'Odada verilen hizmetler', f.hizmetler, 'hizmet_adi', d?.hizmet_ids || [])}`,
  formBagla: secimFiltreBagla,
  formOku: (ov) => ({
    oda_adi: el('[name=oda_adi]', ov).value.trim(),
    personel_ids: seciliIdler(ov, 'personel_ids'),
    hizmet_ids: seciliIdler(ov, 'hizmet_ids'),
  }),
});
