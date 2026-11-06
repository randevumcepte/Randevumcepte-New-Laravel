/**
 * Form Yönetimi (v2) — build'siz island (sade ES module).
 *
 * Eski /isletmeyonetim/arsivyonetimi ekranının v2 karşılığı. Arşiv sekmeleri + Form Gönder /
 * Sözleşme Oluştur / Belge Ekle ORTAK bileşenden (lib/v2-form.js — müşteri detay
 * "Sözleşmeler/Belgeler" sekmesi de aynısını kullanır); burada yalnız sayfa başlığı ve
 * Form Şablonları oluşturucu (eskiden iframe). Şablon eleman kataloğu API'den.
 * PDF görüntüle/indir eski paneldedir (oturumlu link).
 */

import { qp, api, el, esc, mesaj, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=2';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=2';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { formArsivi } from '/js/lib/v2-form.js?v=1';

const kok = document.getElementById('rc-formlar');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;

let arsiv = null;         // formArsivi() — veri (form-verisi), yetki(), veriTazele()
let sablonTablo = null;

const yetki = (k) => !!(arsiv && arsiv.yetki(k));

// ─────────────────────────── FORM ŞABLONLARI ───────────────────────────

let sablonlar = [];

async function sablonlariYukle() {
  const r = await api('GET', '/form/sablon', { params: { salon_id: salonId } });
  if (!r.ok || !r.govde?.basarili) { sablonTablo.mesaj(r.govde?.mesaj || `Hata (${r.kod})`); return; }
  sablonlar = r.govde.veri || [];
  sablonTablo.kayitlarAyarla(sablonlar);
}

function sablonSatir(s) {
  const i = sablonlar.findIndex((x) => x.id === s.id);
  const duzenle = yetki('olustur');
  const sira = duzenle ? `<span class="f-sira">
      <button class="rc-islem" data-aksiyon="yukari" data-id="${s.id}" ${i <= 0 ? 'disabled' : ''} title="Yukarı"><i class="bi bi-arrow-up"></i></button>
      <button class="rc-islem" data-aksiyon="asagi" data-id="${s.id}" ${i >= sablonlar.length - 1 ? 'disabled' : ''} title="Aşağı"><i class="bi bi-arrow-down"></i></button></span>` : '—';
  const islemler = [
    duzenle ? `<button class="rc-islem" data-aksiyon="duzenle" data-id="${s.id}"><i class="bi bi-pencil"></i> Düzenle</button>` : '',
    `<a class="rc-islem" href="${esc(s.bos_pdf_url)}" target="_blank" rel="noopener"><i class="bi bi-download"></i> PDF</a>`,
    duzenle ? `<button class="rc-islem rc-tehlike" data-aksiyon="sil" data-id="${s.id}"><i class="bi bi-trash"></i> Sil</button>` : '',
  ].join('');
  return `<tr>
    <td class="rc-orta">${sira}</td>
    <td>${esc(s.form_adi)}${s.is_sozlesme_tipi ? ' <span class="rc-rozet rc-rozet-mavi">Sözleşme</span>' : ''}</td>
    <td>${s.eleman_sayisi ? s.eleman_sayisi + ' eleman' : '-'}</td>
    <td class="rc-nowrap">${esc(s.tarih || '-')}</td>
    <td class="rc-orta"><span class="rc-islemler">${islemler}</span></td>
  </tr>`;
}

async function sablonAksiyon(e) {
  const b = e.target.closest('button[data-aksiyon]');
  if (!b) return;
  const s = sablonlar.find((x) => String(x.id) === b.dataset.id);
  if (!s) return;
  const a = b.dataset.aksiyon;
  if (a === 'duzenle') return sablonAc(s);
  if (a === 'sil') {
    if (!(await onay(`"${s.form_adi}" form şablonunu silmek istediğinize emin misiniz? Bu işlem geri alınamaz.`, { baslik: 'Formu Sil', tamam: 'Evet, Sil' }))) return;
    const r = await api('DELETE', `/form/sablon/${s.id}`, { params: { salon_id: salonId } });
    if (yazSonuc(r, 'Silindi')) { sablonlariYukle(); arsiv.veriTazele(); }
    return;
  }
  b.disabled = true;
  const r = await api('PATCH', `/form/sablon/${s.id}/sira`, { body: { salon_id: salonId, yon: a } });
  if (yazSonuc(r)) { sablonlar = r.govde.veri || []; sablonTablo.kayitlarAyarla(sablonlar); arsiv.veriTazele(); } else b.disabled = false;
}

/** Şablon oluşturucu / düzenleyici. Eleman tipleri, etiketler ve zorunluluk kataloğu API'den. */
function sablonAc(s) {
  const katalog = arsiv.veri.sablon_katalogu || { gruplar: [], tipler: [] };
  const tip = (t) => katalog.tipler.find((x) => x.tip === t) || { tip: t, etiket: t, renk: '#999', girdi: 'tek' };
  let elemanlar = (s ? s.sorular : []).map((x) => ({ tip: x.tip, soru: x.soru }));

  const govde = `
    <div class="rc-form-izgara">
      <div class="rc-alan"><label>Form Adı *</label><input id="sbAd" maxlength="200" placeholder="Örn: Lazer Epilasyon Onam Formu" value="${esc(s ? s.form_adi : '')}"></div>
      <div class="rc-alan"><label>Tip</label>
        <label class="f-onay"><span class="rc-switch"><input type="checkbox" id="sbSozlesme" ${s && s.is_sozlesme_tipi ? 'checked' : ''}><span class="rc-kaydir"></span></span> Hizmet Sözleşmesi</label></div>
      <div class="rc-alan rc-tam"><label>Form Başlık Açıklaması <small>(formun hemen üstünde gri kutuda gösterilir)</small></label>
        <textarea id="sbAciklama" rows="2" placeholder="Bu formdaki açıklamaların amacı...">${esc(s ? s.aciklama || '' : '')}</textarea></div>
    </div>
    <h3 class="f-alt-baslik">Form İçeriği</h3>
    <div class="rc-ipucu">Aşağıdaki butonlarla form elemanları ekleyin; ↑↓ ile sıralayın.</div>
    <div id="sbElemanlar" class="f-elemanlar"></div>
    <div id="sbGruplar"></div>`;
  const alt = `<button class="rc-btn rc-btn-ikincil" id="sbIptal">İptal</button>
    <button class="rc-btn rc-btn-birincil" id="sbKaydet"><i class="bi bi-save"></i> Kaydet</button>`;
  const ov = modalAc(s ? 'Formu Düzenle: ' + s.form_adi : 'Yeni Form Şablonu', govde, alt, { ikon: 'ui-checks', genis: true, sabit: true });

  const gruplarCiz = () => {
    const sozlesme = el('#sbSozlesme', ov).checked;
    el('#sbGruplar', ov).innerHTML = katalog.gruplar.filter((g) => !g.yalniz_sozlesme || sozlesme).map((g) => `
      <div class="f-grup"><div class="f-grup-baslik">${esc(g.etiket)}${g.not ? ` <small>(${esc(g.not)})</small>` : ''}</div>
        ${katalog.tipler.filter((t) => t.grup === g.grup && t.eklenebilir).map((t) => `<button type="button" class="f-tip-btn" data-ekle="${t.tip}" style="--tip:${esc(t.renk)}"><i class="bi ${esc(t.ikon)}"></i> ${esc(t.etiket)}</button>`).join('')}
      </div>`).join('');
  };

  const elemanlarCiz = () => {
    el('#sbElemanlar', ov).innerHTML = elemanlar.length ? elemanlar.map((x, i) => {
      const t = tip(x.tip);
      const girdi = t.girdi === null
        ? `<div class="f-oto">${esc(t.alt_not || 'Otomatik bilgi')}</div>`
        : (t.girdi === 'cok'
          ? `<textarea data-i="${i}" rows="3" placeholder="${esc(t.ipucu || '')}">${esc(x.soru)}</textarea>`
          : `<input data-i="${i}" placeholder="${esc(t.ipucu || '')}" value="${esc(x.soru)}">`)
          + (t.alt_not ? `<small class="rc-ipucu">${esc(t.alt_not)}</small>` : '');
      const zor = t.zorunlu === true ? '<span class="f-zorunlu">● Zorunlu</span>' : (t.zorunlu === false ? '<span class="rc-ipucu">Opsiyonel</span>' : '');
      return `<div class="f-eleman" style="--tip:${esc(t.renk)}">
        <span class="f-eleman-tip">${esc(t.etiket)}</span>
        <div class="f-eleman-girdi rc-alan">${girdi}</div>
        <div class="f-eleman-yan">${zor}
          <span class="rc-islemler">
            <button type="button" class="rc-islem" data-el="yukari" data-i="${i}" ${i === 0 ? 'disabled' : ''} title="Yukarı"><i class="bi bi-arrow-up"></i></button>
            <button type="button" class="rc-islem" data-el="asagi" data-i="${i}" ${i === elemanlar.length - 1 ? 'disabled' : ''} title="Aşağı"><i class="bi bi-arrow-down"></i></button>
            <button type="button" class="rc-islem" data-el="kopyala" data-i="${i}" title="Kopyala (altına ekle)"><i class="bi bi-copy"></i></button>
            <button type="button" class="rc-islem rc-tehlike" data-el="sil" data-i="${i}" title="Sil"><i class="bi bi-trash"></i></button>
          </span></div>
      </div>`;
    }).join('') : '<div class="rc-ipucu f-bos">Henüz eleman eklenmedi.</div>';
  };

  el('#sbElemanlar', ov).addEventListener('input', (e) => {
    const i = e.target.dataset.i;
    if (i !== undefined) elemanlar[+i].soru = e.target.value;
  });
  el('#sbElemanlar', ov).addEventListener('click', (e) => {
    const b = e.target.closest('[data-el]');
    if (!b) return;
    const i = +b.dataset.i;
    const takas = (j) => { [elemanlar[i], elemanlar[j]] = [elemanlar[j], elemanlar[i]]; };
    ({ yukari: () => takas(i - 1), asagi: () => takas(i + 1), kopyala: () => elemanlar.splice(i + 1, 0, { ...elemanlar[i] }), sil: () => elemanlar.splice(i, 1) })[b.dataset.el]();
    elemanlarCiz();
  });
  el('#sbGruplar', ov).addEventListener('click', (e) => {
    const b = e.target.closest('[data-ekle]');
    if (!b) return;
    const t = tip(b.dataset.ekle);
    elemanlar.push({ tip: t.tip, soru: t.girdi === null ? t.tip : '' });
    elemanlarCiz();
    ov.querySelector('.f-eleman:last-child input, .f-eleman:last-child textarea')?.focus();
  });
  el('#sbSozlesme', ov).addEventListener('change', gruplarCiz);
  gruplarCiz();
  elemanlarCiz();

  el('#sbIptal', ov).onclick = modalKapat;
  el('#sbKaydet', ov).onclick = async (e) => {
    const ad = el('#sbAd', ov).value.trim();
    if (!ad) return mesaj('Form adı zorunludur.', 'hata');
    if (!elemanlar.length) return mesaj('En az bir eleman ekleyin.', 'hata');
    e.currentTarget.disabled = true;
    const body = { salon_id: salonId, form_adi: ad, aciklama: el('#sbAciklama', ov).value, is_sozlesme: el('#sbSozlesme', ov).checked, sorular: elemanlar };
    const r = s ? await api('PATCH', `/form/sablon/${s.id}`, { body }) : await api('POST', '/form/sablon', { body });
    if (yazSonuc(r, r.govde?.mesaj)) { modalKapat(); sablonlariYukle(); arsiv.veriTazele(); } else e.currentTarget.disabled = false;
  };
}

// ─────────────────────────── BAŞLAT ───────────────────────────

(async function baslat() {
  if (!salonId) { kok.innerHTML = '<div class="rc-ipucu f-bos">Şube seçili değil.</div>'; return; }
  kok.innerHTML = `
    <div class="f-kabuk">
      <div class="rc-sayfa-ust">
        <div class="rc-sayfa-baslik">
          <span class="rc-sayfa-ikon"><i class="bi bi-folder2-open"></i></span>
          <div><h1>Form Yönetimi</h1><div class="rc-sayfa-alt">Form, sözleşme ve belge arşivi</div></div>
        </div>
        <div class="rc-sayfa-aksiyon" id="fAksiyon"></div>
      </div>
      <div id="fArsivKap"></div>
    </div>`;

  const panel = document.createElement('section');
  panel.hidden = true;
  panel.innerHTML = `
    <div class="f-sablon-ust">
      <span class="rc-ipucu">Dinamik onam / sözleşme şablonları. Sıra, Form Gönder listesindeki sırayı da belirler.</span>
      <span id="fYeniSablonKap"></span>
    </div>
    <div id="fSablonTablo"></div>`;

  arsiv = await formArsivi(el('#fArsivKap'), {
    salonId, aksiyonKap: el('#fAksiyon'),
    ekSekme: { ad: 'sablonlar', etiket: 'Form Şablonları', ikon: 'bi-ui-checks', panel, ac: sablonlariYukle },
  });
  if (!arsiv) return;

  if (yetki('olustur')) {
    el('#fYeniSablonKap', panel).innerHTML = '<button class="rc-btn rc-btn-yesil" id="fYeniSablon"><i class="bi bi-plus-lg"></i> Yeni Form Şablonu</button>';
    el('#fYeniSablon', panel).onclick = () => sablonAc(null);
  }
  sablonTablo = veriTablosu(el('#fSablonTablo', panel), {
    anahtar: 'form-sablon',
    birim: 'şablon',
    araIpucu: 'Form adı…',
    bosMesaj: 'Henüz form şablonu oluşturmadınız.',
    kolonlar: [
      { baslik: 'Sıra', sinif: 'rc-orta' }, { baslik: 'Form Adı' }, { baslik: 'Eleman Sayısı' },
      { baslik: 'Oluşturulma' }, { baslik: 'İşlemler', sinif: 'rc-orta' },
    ],
    araMetni: (s) => s.form_adi,
    satir: sablonSatir,
  });
  el('#fSablonTablo', panel).addEventListener('click', sablonAksiyon);
})();
