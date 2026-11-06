/**
 * v2 island'larinin ORTAK UI bilesenleri (cekirdek yardimcilar v2-ortak.js'te).
 * Stil: panel.css (.rc-ozet / .rc-kutu). Import yolu her yerde AYNI olmali:
 *   import { ozetKart, kilitle } from '/js/lib/v2-bilesen.js?v=2';
 * (v=2: kilitle eklendi — kilitle kullanan ekran v=2 ile import eder; ozetKart icin v=1 de calisir.)
 */
import { api, esc, yazSonuc } from '/js/lib/v2-ortak.js?v=2';

/**
 * Satış (adisyon) açan modalların alt butonları — stüdyo modunda (fiyat gizli, MODEL B)
 * "Ödeme Alınmadı / Ödeme Alındı", normalde "Vazgeç / Tahsilata Git". data-odeme değeri
 * satisSonrasi'na aktarılır ('1' = ödeme alındı). Paket Yönetimi + Ön Görüşme ortak.
 */
export const satisAltButonlari = (studyo, onay = 'Tahsilata Git') => (studyo
  ? '<button class="rc-btn rc-btn-ikincil" data-odeme="0">Ödeme Alınmadı</button><button class="rc-btn rc-btn-yesil" data-odeme="1"><i class="bi bi-check-lg"></i> Ödeme Alındı</button>'
  : `<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-yesil" data-odeme=""><i class="bi bi-arrow-right"></i> ${esc(onay)}</button>`);

/**
 * Satış açıldıktan sonra: normalde tahsilat ekranı (/v2/tahsilat/{adisyon}); stüdyoda
 * "Ödeme Alındı" ise mevcut adisyon ödeme ucu (tamOde), ardından Satış Takibi.
 */
export async function satisSonrasi(salonId, adisyonId, { studyo = false, odemeAlindi = false } = {}) {
  if (!studyo) { location.href = `/v2/tahsilat/${adisyonId}?sube=${salonId}`; return; }
  if (odemeAlindi) yazSonuc(await api('PATCH', `/adisyon/${adisyonId}/odeme`, { body: { alindi: true } }));
  location.href = `/v2/satislar?sube=${salonId}`;
}

/**
 * Buton kilidi + "isleniyor" metni; is bitince (hata dahil) buton eski haline doner.
 * Cift tiklama / ag tekrari ikinci istegi gondermesin diye TUM yazma butonlari kullanir.
 *   el('#kaydet').onclick = (e) => kilitle(e.currentTarget, 'Kaydediliyor…', async () => { ... });
 */
export async function kilitle(btn, metin, is) {
  const eski = btn.innerHTML;
  btn.disabled = true; btn.innerHTML = `<span class="rc-yukleniyor"></span> ${esc(metin)}`;
  try { return await is(); } finally { btn.disabled = false; btn.innerHTML = eski; }
}

/**
 * Ozet (istatistik) karti. renk: mor | yesil | turuncu | mavi | turkuaz.
 * Kartlari bir .rc-ozet kapsayicisina koyun.
 */
export const ozetKart = (ikon, etiket, deger, renk = 'mor') => `
  <div class="rc-kutu rc-k-${esc(renk)}">
    <div class="rc-k-ikon"><i class="bi ${esc(ikon)}"></i></div>
    <div class="rc-k-ic"><span class="rc-k-ad">${esc(etiket)}</span><span class="rc-k-deger">${esc(deger)}</span></div>
  </div>`;
