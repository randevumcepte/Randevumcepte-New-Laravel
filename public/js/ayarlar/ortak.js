/**
 * Ayarlar (v2) sekme modüllerinin ORTAK parçaları — TEK KAYNAK (sekmelerde kopyalanmaz).
 * Çekirdek yardımcılar lib/v2-ortak.js'te; burada yalnız Ayarlar ekranına özgü kart/alan/
 * kaydet kalıpları var. Stil: css/ayarlar-v2.css (.ay-*) + v2-bilesen.css (.rc-*).
 *
 * Her sekme modülü `export async function ciz(kap, ctx)` sunar; ctx = {salonId}.
 */
import { api, esc, yazSonuc } from '/js/lib/v2-ortak.js?v=1';

/** Yükleniyor görünümü (dönen çember + sabit metin) — rc-yukleniyor yalnız çemberdir, içine metin konmaz. */
export const YUKLENIYOR = '<div class="ay-bekle"><span class="rc-yukleniyor"></span> Yükleniyor…</div>';

/** GET /ayar/<yol>?salon_id= → veri; hata olursa kap'a mesaj çizer ve null döner. */
export async function yukle(kap, yol, salonId) {
  kap.innerHTML = YUKLENIYOR;
  const r = await api('GET', yol, { params: { salon_id: salonId } });
  if (r.ok && r.govde && r.govde.basarili) return r.govde.veri;
  kap.innerHTML = `<div class="ay-bos">${esc((r.govde && r.govde.mesaj) || `Yüklenemedi (${r.kod})`)}</div>`;
  return null;
}

/** Yazma isteği + toast (başarı mesajı sunucudan). Başarılıysa veri, değilse null. */
export async function kaydet(method, yol, body) {
  const r = await api(method, yol, { body });
  return yazSonuc(r, r.govde && r.govde.mesaj) ? r.govde.veri : null;
}

/** Başlıklı kart. */
export const kart = (ikon, baslik, govde, alt = '') => `
  <section class="ay-kart">
    <header class="ay-kart-bas"><i class="bi ${esc(ikon)}"></i><h3>${esc(baslik)}</h3></header>
    ${alt ? `<p class="ay-kart-alt">${esc(alt)}</p>` : ''}
    <div class="ay-kart-govde">${govde}</div>
  </section>`;

/** Etiketli metin alanı (rc-alan). opt: {tip, tam, ipucu, maks, salt, ek} */
export function alan(ad, etiket, deger, opt = {}) {
  const ortak = `name="${esc(ad)}" ${opt.maks ? `maxlength="${opt.maks}"` : ''} ${opt.salt ? 'readonly' : ''} ${opt.ek || ''}`;
  const girdi = opt.tip === 'textarea'
    ? `<textarea ${ortak} rows="${opt.satir || 4}">${esc(deger)}</textarea>`
    : `<input type="${opt.tip || 'text'}" ${ortak} value="${esc(deger)}">`;
  return `<div class="rc-alan${opt.tam ? ' rc-tam' : ''}"><label>${esc(etiket)}</label>${girdi}${opt.ipucu ? `<span class="rc-ipucu">${esc(opt.ipucu)}</span>` : ''}</div>`;
}

/** Seçim kutusu. secenekler: [{kod, ad}] */
export const secim = (ad, etiket, deger, secenekler, opt = {}) => `
  <div class="rc-alan${opt.tam ? ' rc-tam' : ''}"><label>${esc(etiket)}</label>
    <select name="${esc(ad)}">${opt.bos ? `<option value="">${esc(opt.bos)}</option>` : ''}${secenekler
      .map((s) => `<option value="${esc(s.kod)}"${String(s.kod) === String(deger ?? '') ? ' selected' : ''}>${esc(s.ad)}</option>`).join('')}</select>
  </div>`;

/** Açıklamalı aç/kapa satırı. */
export const anahtar = (ad, etiket, acik, ipucu = '') => `
  <label class="ay-anahtar">
    <span class="rc-switch"><input type="checkbox" name="${esc(ad)}"${acik ? ' checked' : ''}><span class="rc-kaydir"></span></span>
    <span class="ay-anahtar-metin"><b>${esc(etiket)}</b>${ipucu ? `<small>${esc(ipucu)}</small>` : ''}</span>
  </label>`;

/** Sabit kaydet çubuğu. */
export const kaydetCubugu = (metin = 'Kaydet') =>
  `<div class="ay-kaydet"><button type="submit" class="rc-btn rc-btn-birincil"><i class="bi bi-check2"></i> ${esc(metin)}</button></div>`;

/** Form gönderimini bağlar: istek sürerken buton kilitlenir; isle(form) sunucu cevabını döndürür. */
export function gonderBagla(form, isle) {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('[type=submit]');
    if (btn) btn.disabled = true;
    try { await isle(form); } finally { if (btn) btn.disabled = false; }
  });
}

/** flatpickr saat seçici (24 saat); yoksa düz metin kalır. */
export function saatBagla(kok) {
  if (!window.flatpickr) return;
  kok.querySelectorAll('input[data-saat]').forEach((g) => flatpickr(g, {
    enableTime: true, noCalendar: true, dateFormat: 'H:i', time_24hr: true, minuteIncrement: 5, allowInput: true, disableMobile: true,
  }));
}

/** flatpickr tarih seçici (Y-m-d) — native date input KULLANILMAZ. */
export function tarihBagla(kok) {
  if (!window.flatpickr) return;
  kok.querySelectorAll('input[data-tarih]').forEach((g) => flatpickr(g, {
    dateFormat: 'Y-m-d', locale: 'tr', allowInput: true, disableMobile: true, minDate: 'today',
  }));
}

export const isaretli = (form, ad) => !!form.querySelector(`[name="${ad}"]`)?.checked;
export const deger = (form, ad) => form.querySelector(`[name="${ad}"]`)?.value ?? '';

/**
 * 7 günlük çalışma + mola planı tablosu (salon çalışma saatleri + cihaz formu ORTAK).
 * gunler: API plan biçimi [{haftanin_gunu, gun_adi, calisiyor, baslangic_saati, ... mola_bitis_saati}].
 * Çizdikten sonra planBagla(kok) ile saat seçici + "çalışmıyor" soluklaştırma bağlanır.
 */
export function planTablosu(gunler) {
  const saat = (ad, d) => `<input type="text" name="${ad}" value="${esc(d)}" data-saat inputmode="numeric" maxlength="5">`;
  const tik = (ad, acik) => `<span class="rc-switch"><input type="checkbox" name="${ad}"${acik ? ' checked' : ''}><span class="rc-kaydir"></span></span>`;
  return `<div class="rc-tablo-sar"><table class="rc-tablo ay-saat-tablo">
    <thead><tr><th>Gün</th><th class="rc-orta">Çalışıyor</th><th>Başlangıç</th><th>Bitiş</th><th class="rc-orta">Mola</th><th>Mola Başlangıç</th><th>Mola Bitiş</th></tr></thead>
    <tbody>${gunler.map((g) => `
      <tr data-gun="${g.haftanin_gunu}" class="${g.calisiyor ? '' : 'ay-kapali'}">
        <th>${esc(g.gun_adi)}</th>
        <td class="rc-orta">${tik('calisiyor', g.calisiyor)}</td>
        <td>${saat('baslangic_saati', g.baslangic_saati)}</td>
        <td>${saat('bitis_saati', g.bitis_saati)}</td>
        <td class="rc-orta">${tik('mola_var', g.mola_var)}</td>
        <td>${saat('mola_baslangic_saati', g.mola_baslangic_saati)}</td>
        <td>${saat('mola_bitis_saati', g.mola_bitis_saati)}</td>
      </tr>`).join('')}</tbody>
  </table></div>`;
}

export function planBagla(kok) {
  saatBagla(kok);
  kok.addEventListener('change', (e) => {
    if (e.target.name === 'calisiyor') e.target.closest('tr').classList.toggle('ay-kapali', !e.target.checked);
  });
}

/** Tablodaki planı API gövdesine çevirir. */
export function planOku(kok) {
  return [...kok.querySelectorAll('tr[data-gun]')].map((tr) => {
    const d = (ad) => tr.querySelector(`[name="${ad}"]`);
    return {
      haftanin_gunu: Number(tr.dataset.gun),
      calisiyor: d('calisiyor').checked,
      baslangic_saati: d('baslangic_saati').value || null,
      bitis_saati: d('bitis_saati').value || null,
      mola_var: d('mola_var').checked,
      mola_baslangic_saati: d('mola_baslangic_saati').value || null,
      mola_bitis_saati: d('mola_bitis_saati').value || null,
    };
  });
}

/**
 * Aranabilir onay kutusu listesi (oda personel/hizmet, hizmet personel/cihaz seçimi ORTAK).
 * liste: [{id, <alan>}]; secili: id dizisi. Arama için secimFiltreBagla(kok) bir kez çağrılır.
 */
export const secimListesi = (ad, baslik, liste, alan, secili = []) => `
  <div class="rc-alan rc-tam"><label>${esc(baslik)}</label>
    ${liste.length > 8 ? `<input type="text" class="ay-filtre" data-filtre="${esc(ad)}" placeholder="Listede ara…">` : ''}
    <div class="ay-secim" data-liste="${esc(ad)}">${liste.map((x) => `
      <label data-ad="${esc(String(x[alan]).toLocaleLowerCase('tr'))}"><input type="checkbox" name="${esc(ad)}" value="${x.id}"${secili.includes(x.id) ? ' checked' : ''}> ${esc(x[alan])}</label>`).join('')
      || '<span class="ay-soluk">Kayıt yok</span>'}</div></div>`;

export function secimFiltreBagla(kok) {
  kok.addEventListener('input', (e) => {
    const ad = e.target.dataset.filtre;
    if (!ad) return;
    const q = e.target.value.trim().toLocaleLowerCase('tr');
    kok.querySelectorAll(`[data-liste="${ad}"] label`).forEach((l) => { l.hidden = !!q && !l.dataset.ad.includes(q); });
  });
}

export const seciliIdler = (kok, ad) => [...kok.querySelectorAll(`[name="${ad}"]:checked`)].map((i) => Number(i.value));
