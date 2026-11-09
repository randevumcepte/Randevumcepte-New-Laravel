/**
 * v2 MÜŞTERİ ortak modülü — liste (/v2/musteriler) ve detay (/v2/musteri/{id}) ekranları
 * AYNI ekle/düzenle formunu ve yardımcıları kullanır (kod tekrarı yok).
 * Import yolu her yerde AYNI olmalı:
 *   import { musteriFormuAc, formVerisi, segmentRozeti, tarihTr } from '/js/lib/v2-musteri.js?v=7';
 *
 * Form seçenekleri (referans, cinsiyet, cilt tipi, sağlık soruları) API'den gelir
 * (GET /musteri/form-verisi) — UI'da sabit liste yok. Alan adları eski "Müşteri Bilgileri"
 * modalı ile aynı: ad_soyad*, telefon*, tc_kimlik_no, doğum günü (gün/ay/yıl — yıl
 * opsiyonel), email, cinsiyet, musteri_tipi (referans), ozel_notlar.
 */
import { api, el, esc, yazSonuc } from '/js/lib/v2-ortak.js?v=7';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';

let _formVeri = null;

/** GET /musteri/form-verisi — sayfa başına bir kez. */
export async function formVerisi(salonId) {
  if (_formVeri) return _formVeri;
  const r = await api('GET', '/musteri/form-verisi', { params: { salon_id: salonId } });
  _formVeri = r.ok && r.govde?.basarili ? r.govde.veri : { referanslar: [], cinsiyetler: [], cilt_tipleri: [], saglik_sorulari: [] };
  return _formVeri;
}

/** "2026-11-03" | "2026-11-03 10:20:00" → "03.11.2026" (boşsa —). */
export const tarihTr = (s) => {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
  return m ? `${m[3]}.${m[2]}.${m[1]}` : '—';
};

const SEGMENT_RENK = { 0: 'kirmizi', 1: 'yesil', 2: 'turuncu' };
/** Segment rozeti — sunucunun {kod, etiket} kararı (UI yeniden hesaplamaz). */
export const segmentRozeti = (s) => (s ? `<span class="rc-rozet rc-rozet-${SEGMENT_RENK[s.kod] || 'gri'}">${esc(s.etiket)}</span>` : '');

const AYLAR = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

const secim = (ad, liste, secili, bos = 'Seçiniz') => `<select name="${ad}">
  <option value="">${esc(bos)}</option>
  ${liste.map((o) => `<option value="${esc(o.id)}"${String(o.id) === String(secili ?? '') ? ' selected' : ''}>${esc(o.ad)}</option>`).join('')}
</select>`;

/**
 * Ekle / düzenle modalı. m: detay DTO'su (düzenleme) ya da null (yeni).
 * Kaydedilince onKaydet(sonuc) — sonuc: {id, name, yeni, portfoye_eklendi}.
 */
export async function musteriFormuAc({ salonId, m = null, etiket = 'Müşteri', onKaydet = () => {} }) {
  const fv = await formVerisi(salonId);
  const d = m?.dogum_tarihi_parca || {};
  const gunler = Array.from({ length: 31 }, (_, i) => ({ id: i + 1, ad: i + 1 }));
  const aylar = AYLAR.map((ad, i) => ({ id: i + 1, ad }));
  const buYil = new Date().getFullYear();
  const yillar = Array.from({ length: 100 }, (_, i) => ({ id: buYil - i, ad: buYil - i }));
  // Maskeli telefon (telefon_gor yetkisi yok) düzenlemede değiştirilmezse numara korunur (sunucu).
  const maskeli = m && m.cep_telefon && m.cep_telefon.includes('*');

  const govde = `
    <form id="mfForm" class="rc-form-izgara" autocomplete="off">
      <div class="rc-alan"><label>Ad Soyad *</label><input name="ad_soyad" required maxlength="255" value="${esc(m?.name || '')}"></div>
      <div class="rc-alan"><label>Telefon ${m ? '' : '*'}</label>
        <input name="telefon" type="tel" maxlength="30" ${m ? '' : 'required'} placeholder="5XX XXX XX XX" value="${esc(m?.cep_telefon || '')}">
        ${maskeli ? '<span class="rc-ipucu">Numara gizli; değiştirmezseniz mevcut numara korunur.</span>' : ''}</div>
      <div class="rc-alan"><label>TC Kimlik No</label><input name="tc_kimlik_no" inputmode="numeric" maxlength="11" pattern="\\d{11}" value="${esc(m?.tc_kimlik_no || '')}"></div>
      <div class="rc-alan"><label>E-posta</label><input name="email" type="email" maxlength="255" value="${esc(m?.email || '')}"></div>
      <div class="rc-alan rc-tam"><label>Doğum Tarihi <small>(yıl opsiyonel)</small></label>
        <div class="mf-dogum">${secim('dogum_tarihi_gun', gunler, d.gun, 'Gün')}${secim('dogum_tarihi_ay', aylar, d.ay, 'Ay')}${secim('dogum_tarihi_yil', yillar, d.yil, 'Yıl')}</div></div>
      <div class="rc-alan"><label>Cinsiyet</label>${secim('cinsiyet', fv.cinsiyetler, m?.cinsiyet)}</div>
      <div class="rc-alan"><label>Referans</label>${secim('musteri_tipi', fv.referanslar, m?.musteri_tipi)}</div>
      <div class="rc-alan rc-tam"><label>Notlar</label><textarea name="ozel_notlar" rows="3" maxlength="5000">${esc(m?.ozel_notlar || '')}</textarea></div>
    </form>`;
  const alt = `<button class="rc-btn rc-btn-ikincil" type="button" id="mfVazgec">Vazgeç</button>
    <button class="rc-btn rc-btn-birincil" type="submit" form="mfForm" id="mfKaydet"><i class="bi bi-check2"></i> Kaydet</button>`;

  modalAc(m ? `${m.name} — Bilgileri Düzenle` : `Yeni ${etiket}`, govde, alt, { ikon: m ? 'pencil-square' : 'person-plus', dar: true, sabit: true });
  el('#mfVazgec').onclick = modalKapat;

  el('#mfForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const body = Object.fromEntries(new FormData(e.target).entries());
    body.salon_id = salonId;
    if (maskeli && body.telefon === m.cep_telefon) delete body.telefon;
    const btn = el('#mfKaydet');
    btn.disabled = true;
    const r = await api(m ? 'PATCH' : 'POST', m ? `/musteri/${m.id}` : '/musteri', { body });
    btn.disabled = false;
    if (yazSonuc(r, r.govde?.mesaj)) { modalKapat(); onKaydet(r.govde.veri); }
  });
}
