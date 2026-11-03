/**
 * Ayarlar → Cihazlar (v2). Tablo + işlemler kaynak.js'te (odalar ile ortak); burada yalnız
 * cihaz formu: ad + 7 günlük çalışma/mola planı (eski #yenicihazbilgiekle). Eskide düzenleme
 * yoktu ve plan hep "çalışmıyor" kaydediliyordu — v2'de düzenlenebilir.
 */
import { esc, el } from '/js/lib/v2-ortak.js?v=1';
import { kaynakSekmesi } from '/js/ayarlar/kaynak.js?v=1';
import { planTablosu, planBagla, planOku } from '/js/ayarlar/ortak.js?v=2';

export const { ciz } = kaynakSekmesi({
  yol: '/ayar/cihazlar',
  etiket: 'Cihaz',
  ikon: 'cpu',
  anahtar: 'ayar-cihazlar',
  genisForm: true,
  ekKolonlar: [],
  formGovde: (d, f) => `
    <div class="rc-alan rc-tam"><label>Cihaz Adı *</label><input type="text" name="cihaz_adi" maxlength="255" value="${esc(d?.cihaz_adi || '')}"></div>
    <h4 class="ay-ara-baslik">Çalışma ve Mola Saatleri</h4>
    ${planTablosu(d?.gunler || f.gunler)}`,
  formBagla: (ov) => planBagla(el('form', ov)),
  formOku: (ov) => ({ cihaz_adi: el('[name=cihaz_adi]', ov).value.trim(), gunler: planOku(ov) }),
});
