/**
 * v2 KASA FORM parçaları — TEK KAYNAK: masraf modalı (Kasa Raporu + header "+" menüsü) ve kasa
 * formlarının ortak yardımcıları. Stil: css/rapor-v2.css.
 * Import yolu her yerde AYNI olmalı:  import { masrafModal } from '/js/lib/v2-kasa-form.js?v=1';
 */
import { api, el, esc, tarihStr, yazSonuc } from '/js/lib/v2-ortak.js?v=2';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';

export const secim = (liste, deger, ad, secili, bos = 'Seçiniz') => `<option value="">${esc(bos)}</option>`
  + liste.map((o) => `<option value="${o[deger]}"${String(o[deger]) === String(secili ?? '') ? ' selected' : ''}>${esc(o[ad])}</option>`).join('');

const personelSozu = {};
const masrafFormSozu = {};

/** Salonun aktif personeli (sayfa ömrünce önbellekli). */
export function personeller(salonId) {
  personelSozu[salonId] ||= api('GET', '/personel', { params: { salon_id: salonId, tumu: 1 } })
    .then((r) => (r.ok && r.govde?.basarili ? (r.govde.veri.data || []).filter((p) => Number(p.aktif) === 1) : []));
  return personelSozu[salonId];
}

function masrafFormu(salonId) {
  masrafFormSozu[salonId] ||= api('GET', '/masraf/form-verisi', { params: { salon_id: salonId } })
    .then((r) => (r.ok && r.govde?.basarili ? r.govde.veri : { kategoriler: [], odeme_yontemleri: [] }));
  return masrafFormSozu[salonId];
}

export function tarihSecici(girdi) {
  if (window.flatpickr) window.flatpickr(girdi, { dateFormat: 'Y-m-d', altInput: true, altFormat: 'd.m.Y', locale: 'tr', disableMobile: true });
}

/** Form alanlarını düz nesneye çevirir (boş → null, sayılar sayı). */
export function formOku(ov, alanlar) {
  const v = {};
  alanlar.forEach(([ad, tip]) => {
    const x = el(`[name="${ad}"]`, ov);
    const deger = x ? x.value.trim() : '';
    v[ad] = deger === '' ? null : (tip === 'sayi' ? Number(deger.replace(',', '.')) : deger);
  });
  return v;
}

export async function masrafModal({ salonId, id = null, onKaydet = () => {} }) {
  const [fv, pers, mevcut] = await Promise.all([
    masrafFormu(salonId), personeller(salonId),
    id ? api('GET', `/masraf/${id}`).then((r) => (r.ok && r.govde?.basarili ? r.govde.veri : null)) : null,
  ]);
  if (id && !mevcut) { yazSonuc({ ok: false, kod: 404, govde: { mesaj: 'Masraf bulunamadı' } }); return; }
  const m = mevcut || { tarih: tarihStr(new Date()), personel_gideri: false };
  // Silinmiş/pasif harcayan seçili kalsın.
  const liste = m.harcayan_id && !pers.some((p) => p.id === m.harcayan_id) ? [...pers, { id: m.harcayan_id, personel_adi: m.harcayan_adi || '-' }] : pers;

  const ov = modalAc(id ? 'Masraf Düzenleme' : 'Yeni Masraf', `
    <div class="rc-sekmeler rp-segment">
      <button type="button" class="rc-sekme${m.personel_gideri ? '' : ' aktif'}" data-mod="0"><i class="bi bi-receipt"></i> Masraf</button>
      <button type="button" class="rc-sekme${m.personel_gideri ? ' aktif' : ''}" data-mod="1"><i class="bi bi-person-badge"></i> Personel Gideri</button>
    </div>
    <div class="rp-uyari" data-gideri-not ${m.personel_gideri ? '' : 'hidden'}><i class="bi bi-info-circle"></i> Personel gideri kasadan düşülür ve personelin net hak edişinden düşülür.</div>
    <div class="rc-form-izgara">
      <div class="rc-alan"><label>Harcayan</label><select name="harcayan_id">${secim(liste, 'id', 'personel_adi', m.harcayan_id)}</select></div>
      <div class="rc-alan"><label>Tutar (₺)</label><input name="tutar" type="number" min="0" step="0.01" inputmode="decimal" value="${m.tutar ?? ''}"></div>
      <div class="rc-alan"><label>Tarih</label><input name="tarih" value="${esc(m.tarih)}"></div>
      <div class="rc-alan"><label>Ödeme Yöntemi</label><select name="odeme_yontemi_id">${secim(fv.odeme_yontemleri, 'id', 'odeme_yontemi', m.odeme_yontemi_id)}</select></div>
      <div class="rc-alan" data-kategori ${m.personel_gideri ? 'hidden' : ''}><label>Kategori</label><select name="masraf_kategori_id">${secim(fv.kategoriler, 'id', 'kategori', m.masraf_kategori_id)}</select></div>
      <div class="rc-alan rc-tam"><label>Açıklama</label><textarea name="aciklama" rows="2">${esc(m.aciklama || '')}</textarea></div>
    </div>`,
  `<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-birincil" id="ksMasrafKaydet"><i class="bi bi-check2"></i> Kaydet</button>`,
  { ikon: 'receipt-cutoff', dar: true });
  tarihSecici(el('[name="tarih"]', ov));
  el('[data-kapat]', ov).onclick = modalKapat;

  let gideri = !!m.personel_gideri;
  el('.rp-segment', ov).onclick = (e) => {
    const b = e.target.closest('[data-mod]');
    if (!b) return;
    gideri = b.dataset.mod === '1';
    ov.querySelectorAll('[data-mod]').forEach((x) => x.classList.toggle('aktif', x === b));
    el('[data-kategori]', ov).hidden = gideri;
    el('[data-gideri-not]', ov).hidden = !gideri;
  };

  el('#ksMasrafKaydet', ov).onclick = async (e) => {
    const govde = { salon_id: salonId, personel_gideri: gideri,
      ...formOku(ov, [['harcayan_id', 'sayi'], ['tutar', 'sayi'], ['tarih'], ['odeme_yontemi_id', 'sayi'], ['masraf_kategori_id', 'sayi'], ['aciklama']]) };
    if (gideri) govde.masraf_kategori_id = null;
    const b = e.currentTarget;
    b.disabled = true;
    const r = await api(id ? 'PATCH' : 'POST', id ? `/masraf/${id}` : '/masraf', { body: govde });
    b.disabled = false;
    if (yazSonuc(r, r.govde?.mesaj)) { modalKapat(); onKaydet(); }
  };
}
