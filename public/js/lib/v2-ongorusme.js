/**
 * v2 ÖN GÖRÜŞME FORMU — TEK KAYNAK. Ön Görüşmeler ekranı (ongorusmeler-v2.js) ve Çağrı Merkezi
 * "Ön Görüşme Randevusu" sonucu aynı formu kullanır (kopya yok).
 * Import yolu her yerde AYNI olmalı:  import { ongorusmeFormu } from '/js/lib/v2-ongorusme.js?v=8';
 *
 *   ongorusmeFormu({ salonId, fv, kayit, musteri, onKaydet })
 *     fv       : GET /ongorusme/form-verisi cevabı (verilmezse çekilir — formVerisi())
 *     kayit    : düzenlenecek ön görüşme (null → yeni)
 *     musteri  : {id, ad, telefon} → kayıtlı müşteri SEÇİLİ ve SABİT başlar (çağrı merkezi dönüşümü)
 *     onKaydet : (cevap.veri, yeniMi) → kayıt sonrası (liste tazele / çağrıyı işaretle)
 * Çakışma iş sonucudur (veri.cakismavar='1') → onayla "yine de kaydet". Stil: css/ongorusmeler-v2.css (.og-*).
 */
import { api, el, esc, tarihStr, mesaj, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=7';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';
import { musteriSecici } from '/js/lib/v2-secici.js?v=7';
import { kilitle } from '/js/lib/v2-bilesen.js?v=7';

/* ─────────────── form yardımcıları (ön görüşme + satış modalları ortak) ─────────────── */

export const secenekler = (liste, secili, bos = null, deger = 'id', ad = 'ad') =>
  (bos !== null ? `<option value="">${esc(bos)}</option>` : '')
  + liste.map((x) => `<option value="${esc(x[deger])}"${String(secili ?? '') === String(x[deger]) ? ' selected' : ''}>${esc(x[ad])}</option>`).join('');
export const alan = (etiket, html, sinif = '') => `<div class="rc-alan ${sinif}"><label>${etiket}</label>${html}</div>`;
export const tarihBagla = (inp, ek = {}) => { if (window.flatpickr) window.flatpickr(inp, { locale: 'tr', dateFormat: 'Y-m-d', altInput: true, altFormat: 'd.m.Y', allowInput: true, disableMobile: true, ...ek }); };
export const sayiVeyaNull = (v) => (String(v ?? '').trim() === '' ? null : Number(String(v).replace(',', '.')));

/** Neden kataloğu düz liste ({anahtar, tur, id, ad, fiyat, seans, grup}). */
export const nedenListesi = (fv) => (fv?.nedenler || []).flatMap((g) => g.secenekler.map((s) => ({ ...s, grup: g.etiket })));

/** Form verisi (salon başına bellekte; ekran ömrü boyunca tek istek). */
const fvOnbellek = new Map();
export async function formVerisi(salonId) {
  if (!fvOnbellek.has(salonId)) {
    fvOnbellek.set(salonId, api('GET', '/ongorusme/form-verisi', { params: { salon_id: salonId } }).then((r) => {
      if (r.ok && r.govde?.basarili) return r.govde.veri;
      fvOnbellek.delete(salonId);
      throw new Error(r.govde?.mesaj || `Form verisi alınamadı (${r.kod})`);
    }));
  }
  return fvOnbellek.get(salonId);
}

/** Saat seçenekleri sunucudaki aralıktan (eski modal: 07:00–23:45, 15 dk). */
function saatSecenekleri(fv, secili) {
  const { baslangic = '07:00', bitis = '23:45', adim_dk: adim = 15 } = fv.saat_araligi || {};
  const dk = (s) => { const [h, m] = s.split(':').map(Number); return h * 60 + m; };
  const out = [];
  for (let t = dk(baslangic); t <= dk(bitis); t += adim) out.push(`${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`);
  if (secili && !out.includes(secili)) out.push(secili);
  return '<option value="">Seçiniz</option>' + out.map((s) => `<option${s === secili ? ' selected' : ''}>${s}</option>`).join('');
}

export async function ongorusmeFormu({ salonId, fv = null, kayit: k = null, musteri = null, onKaydet = () => {} }) {
  try { fv = fv || await formVerisi(salonId); } catch (e) { mesaj(e.message, 'hata'); return; }
  const nedenler = nedenListesi(fv);
  const etiket = esc(fv.musteri_etiket || 'Müşteri');
  const bolum = (ikon, baslik, icerik) => `<div class="og-bolum"><div class="og-bolum-baslik"><i class="bi ${ikon}"></i> ${baslik}</div><div class="rc-form-izgara">${icerik}</div></div>`;
  const nedenSec = '<option value="">Seçiniz</option>' + nedenler
    .map((s) => `<option value="${esc(s.anahtar)}"${k?.neden?.anahtar === s.anahtar ? ' selected' : ''}>${esc(s.grup)} : ${esc(s.ad)}</option>`).join('');
  const personel = k?.personel_id ?? fv.varsayilan_personel_id;
  const ilk = musteri || (k?.musteri ? { id: k.musteri.id, ad: k.musteri.name, telefon: k.cep_telefon } : null);
  const govde = `
    <form id="ogForm" autocomplete="off">
      ${bolum('bi-person', `${etiket} Bilgileri`, `
        ${alan(`Mevcut ${etiket} <small>(kayıtlıysa)</small>`, `<div id="fMusteri"></div>${musteri ? '' : '<span class="rc-ipucu">Daha önce kaydedilmişse buradan seçin, aksi halde aşağıdaki alanları doldurun.</span>'}`, 'rc-tam')}
        ${alan('Ad Soyad *', `<input id="fAd" required maxlength="255" value="${esc(ilk?.ad || k?.ad_soyad || '')}">`)}
        ${alan('Telefon *', `<input id="fTel" type="tel" value="${esc(ilk?.telefon || k?.cep_telefon || '')}">`)}
        ${alan('E-mail <small>(opsiyonel)</small>', `<input id="fEmail" type="email" maxlength="255" value="${esc(k?.email || '')}">`)}
        ${alan('Cinsiyet', `<select id="fCinsiyet">${secenekler(fv.cinsiyetler, k?.cinsiyet, 'Seçiniz')}</select>`)}`)}
      ${bolum('bi-clipboard2-check', 'Görüşme Detayı', `
        ${alan('Ön Görüşme Sebebi *', `<select id="fNeden" required>${nedenSec}</select>`)}
        ${alan('Referans', `<select id="fReferans">${secenekler(fv.referanslar, k?.musteri_tipi, 'Yok')}</select>`)}`)}
      ${bolum('bi-calendar-event', 'Tarih &amp; Personel', `
        ${alan('Tarih *', `<input id="fTarih" required value="${esc(k?.hatirlatma_tarihi || tarihStr(new Date()))}">`)}
        ${alan('Saat *', `<select id="fSaat" required>${saatSecenekleri(fv, k?.on_gorusme_saati)}</select>`)}
        ${fv.oda_zorunlu ? alan('Oda *', `<select id="fOda" required>${secenekler(fv.odalar, k?.oda_id, 'Seçiniz')}</select>`) : ''}
        ${alan('Görüşmeyi Yapan *', `<select id="fPersonel" required>${secenekler(fv.personeller, personel, 'Seçiniz')}</select>`)}
        ${alan('Açıklama <small>(opsiyonel)</small>', `<textarea id="fAciklama" rows="2" maxlength="2000" placeholder="Görüşme ile ilgili kısa notlar…">${esc(k?.aciklama || '')}</textarea>`, 'rc-tam')}`)}
    </form>`;
  const ov = modalAc(k ? 'Ön Görüşme Düzenleme' : 'Yeni Ön Görüşme', govde,
    '<button class="rc-btn rc-btn-ikincil" type="button" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-birincil" type="submit" form="ogForm" id="fKaydet"><i class="bi bi-check-lg"></i> Kaydet</button>',
    { ikon: 'person-plus', sabit: true, genis: true, altbaslik: k ? k.ad_soyad : 'Müşteri ile yapılacak ön görüşmeyi planlayın.' });
  el('[data-kapat]', ov).onclick = modalKapat;
  tarihBagla(el('#fTarih', ov));

  // Mevcut müşteri seçilince ad / telefon + (arama cevabında varsa) e-mail, cinsiyet, referans ondan
  // dolar; "Değiştir" serbest girişe döner. Başlangıç seçiminde (düzenleme) olmayan alana dokunulmaz.
  const doldur = (m) => {
    if (!m) return;
    el('#fAd', ov).value = m.ad || '';
    el('#fTel', ov).value = m.telefon || '';
    if ('email' in m) el('#fEmail', ov).value = m.email || '';
    if ('cinsiyet' in m) el('#fCinsiyet', ov).value = m.cinsiyet ?? '';
    if ('musteri_tipi' in m) el('#fReferans', ov).value = m.musteri_tipi ?? '';
  };
  const secici = musteriSecici(el('#fMusteri', ov), {
    salonId, yol: '/ongorusme/musteri-ara', baslangic: ilk, sabit: !!musteri, onSec: doldur,
  });

  el('#ogForm', ov).addEventListener('submit', (e) => {
    e.preventDefault();
    const neden = nedenler.find((s) => s.anahtar === el('#fNeden', ov).value);
    const eksik = [!neden && 'Ön görüşme sebebini seçiniz.', !el('#fPersonel', ov).value && 'Ön görüşmeyi gerçekleştirecek personeli seçiniz.',
      fv.oda_zorunlu && !el('#fOda', ov)?.value && 'Oda seçiniz.', !el('#fSaat', ov).value && 'Saat seçiniz.'].filter(Boolean);
    if (eksik.length) return mesaj(eksik.join(' '), 'hata');
    const d = {
      musteri_id: secici.secili()?.id || null, ad_soyad: el('#fAd', ov).value.trim(), telefon: el('#fTel', ov).value.trim(),
      email: el('#fEmail', ov).value.trim() || null, cinsiyet: sayiVeyaNull(el('#fCinsiyet', ov).value),
      musteri_tipi: sayiVeyaNull(el('#fReferans', ov).value), neden_tur: neden.tur, neden_id: neden.id,
      tarih: el('#fTarih', ov).value, saat: el('#fSaat', ov).value, oda_id: sayiVeyaNull(el('#fOda', ov)?.value),
      personel_id: Number(el('#fPersonel', ov).value), aciklama: el('#fAciklama', ov).value.trim() || null,
    };
    kilitle(el('#fKaydet', ov), 'Kaydediliyor…', () => kaydet(salonId, k, d, onKaydet));
  });
}

/** Kaydet; çakışma iş sonucudur (veri.cakismavar='1') → onayla "yine de kaydet". */
async function kaydet(salonId, k, d, onKaydet) {
  const gonder = (ek = {}) => api(k ? 'PATCH' : 'POST', k ? `/ongorusme/${k.id}` : '/ongorusme', { body: { ...d, ...ek, salon_id: salonId } });
  let r = await gonder();
  if (r.ok && r.govde?.veri?.cakismavar === '1') {
    if (!await onay(`${r.govde.veri.cakisanunsurlar} Yine de kaydetmek istiyor musunuz?`, { baslik: 'Çakışma Var', tamam: 'Yine de kaydet' })) return;
    r = await gonder({ cakisanrandevuekle: 1 });
  }
  if (yazSonuc(r, r.govde?.mesaj)) { modalKapat(); await onKaydet(r.govde.veri, !k); }
}
