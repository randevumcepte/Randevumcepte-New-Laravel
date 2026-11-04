/**
 * v2 FORM GÖNDER modalı — TEK KAYNAK: Form Yönetimi / müşteri detayı arşivi (v2-form.js) ve header
 * "+" menüsü aynı modalı açar. Form başına ek alanlar sunucudan (`ek_alanlar`). Stil: css/formlar-v2.css.
 * Import yolu her yerde AYNI olmalı:  import { formGonderModali } from '/js/lib/v2-form-gonder.js?v=1';
 */
import { api, el, esc, mesaj, yazSonuc } from '/js/lib/v2-ortak.js?v=1';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { musteriSecici } from '/js/lib/v2-secici.js?v=2';

/** "1.234,56" görünümü (tr-TR); boş/NaN → '' */
export const tlYaz = (n) => (n === null || n === undefined || n === '' || isNaN(Number(n)) ? ''
  : Number(n).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
/** tr-TR para girdisini sayıya çevir (sunucu da aynı biçimi kabul eder; önizleme için). */
export const tlOku = (s) => {
  const v = String(s ?? '').trim();
  if (!v) return null;
  const n = Number(v.includes(',') ? v.replace(/\./g, '').replace(',', '.') : v);
  return isNaN(n) ? null : n;
};

/** Para alanları: odaktan çıkınca tr-TR biçimi. */
export function tlBagla(kap) {
  kap.querySelectorAll('.f-tl').forEach((i) => {
    i.addEventListener('blur', () => { const n = tlOku(i.value); i.value = n === null ? '' : tlYaz(n); });
  });
}

/** Müşteri alanı: müşteri verildiyse sabit seçili (Değiştir yok), yoksa aramalı; telefon alanını doldurur. */
export const formMusteriSecici = (salonId, musteri, kapEl, telEl) => musteriSecici(kapEl, {
  salonId, yol: '/form/musteri-ara', baslangic: musteri, sabit: !!musteri,
  onSec: telEl ? async (m) => {
    telEl.value = '';
    if (!m) return;
    const t = await api('GET', `/form/musteri/${m.id}`, { params: { salon_id: salonId } });
    if (t.ok && t.govde?.basarili) telEl.value = t.govde.veri.telefon || '';
  } : undefined,
});

/** @param {{salonId:number, veri?:object, musteri?:object, onGonderildi?:Function}} opt veri yoksa API'den alınır */
export async function formGonderModali({ salonId, veri = null, musteri = null, onGonderildi = () => {} }) {
  if (!veri) {
    const fv = await api('GET', '/form/form-verisi', { params: { salon_id: salonId } });
    if (!yazSonuc(fv)) return;
    veri = fv.govde.veri;
  }
  const t = veri.taslaklar || [];
  const secenekler = t.length
    ? t.map((f) => `<option value="${f.id}">${esc(f.form_adi)}</option>`).join('')
    : '<option value="" disabled selected>Henüz form oluşturmadınız — Form Şablonları sekmesinden ekleyin</option>';
  const hizmetler = (veri.hizmetler || []).map((h) => `<option value="${h.hizmet_id}">${esc(h.ad)}</option>`).join('');

  const govde = `
    <div class="rc-form-izgara">
      <div class="rc-alan rc-tam"><label>Form/Sözleşme Türü</label><select id="fgForm">${secenekler}</select></div>
      <div class="rc-alan"><label>Müşteri</label><div id="fgMusteri"></div></div>
      <div class="rc-alan"><label>Cep Telefon</label><input type="tel" id="fgTel" autocomplete="off"></div>
      <div class="rc-alan rc-tam" id="fgHizmetAlan" hidden><label>Hizmet</label><select id="fgHizmet"><option value="">Hizmet seçin</option>${hizmetler}</select></div>
      <div class="rc-alan" data-ucret hidden><label>Toplam Ücret (₺)</label><input id="fgToplam" class="f-tl" inputmode="decimal" placeholder="0,00"></div>
      <div class="rc-alan" data-ucret hidden><label>Kapora (₺)</label><input id="fgKapora" class="f-tl" inputmode="decimal" placeholder="0,00"></div>
    </div>`;
  const alt = `<button class="rc-btn rc-btn-ikincil" id="fgIptal">Vazgeç</button>
    <button class="rc-btn rc-btn-yesil" id="fgGonder"><i class="bi bi-send"></i> Gönder</button>`;
  const ov = modalAc('Form Gönder', govde, alt, { ikon: 'send', dar: true });
  tlBagla(ov);
  const s = formMusteriSecici(salonId, musteri, el('#fgMusteri', ov), el('#fgTel', ov));

  // Form başına ek alanlar sunucudan (eski 9/10/12 kuralları — SabitFormlar).
  const ekAlanlar = () => {
    const f = t.find((x) => String(x.id) === el('#fgForm', ov).value);
    const k = (f && f.ek_alanlar) || {};
    el('#fgHizmetAlan', ov).hidden = !k.hizmet;
    ov.querySelectorAll('[data-ucret]').forEach((a) => { a.hidden = !k.ucret; });
    return k;
  };
  el('#fgForm', ov).addEventListener('change', ekAlanlar);
  ekAlanlar();

  el('#fgIptal', ov).onclick = modalKapat;
  el('#fgGonder', ov).onclick = async (e) => {
    const m = s.secili();
    if (!el('#fgForm', ov).value) return mesaj('Form/sözleşme türünü seçiniz.', 'hata');
    if (!m) return mesaj('Müşteri/danışan seçiniz.', 'hata');
    const k = ekAlanlar();
    const b = e.currentTarget;
    b.disabled = true;
    const r = await api('POST', '/form/arsiv', { body: {
      salon_id: salonId,
      form_id: Number(el('#fgForm', ov).value),
      user_id: m.id,
      telefon: el('#fgTel', ov).value.trim(),
      hizmet_id: k.hizmet ? (el('#fgHizmet', ov).value || null) : null,
      toplam_ucret: k.ucret ? el('#fgToplam', ov).value : null,
      kapora: k.ucret ? el('#fgKapora', ov).value : null,
    } });
    if (yazSonuc(r, r.govde?.mesaj)) { modalKapat(); onGonderildi(); } else b.disabled = false;
  };
}
