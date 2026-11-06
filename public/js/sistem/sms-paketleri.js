/** Interaktif duyuru (SMS) paketleri: liste + ekle/duzenle penceresi. */
import { el, esc, onay } from '/js/lib/v2-ortak.js?v=2';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=2';
import { ekran, para, syApi, syVeri, syYaz } from '/js/lib/v2-sistem.js?v=1';
import { kilitli, etiketRozet, sayfaBaslik, sayi, secenekHtml } from '/js/sistem/ortak.js?v=1';

let s = null;
let tablo = null;
let satirlar = [];

function formAc(p) {
  const govde = `
    <div class="rc-form-izgara">
      <div class="rc-alan"><label>Paket Başlığı</label><input id="pAd" maxlength="255" placeholder="örn. 10.000 SMS" value="${esc(p?.paket_adi || '')}">
        <span class="rc-ipucu">Boş bırakılırsa "{SMS adedi} SMS" gösterilir.</span></div>
      <div class="rc-alan"><label>Alt Başlık</label><input id="pAlt" maxlength="255" placeholder="İnteraktif Duyuru Paketi" value="${esc(p?.alt_baslik || '')}">
        <span class="rc-ipucu">Boş bırakılırsa "İnteraktif Duyuru Paketi" gösterilir.</span></div>
      <div class="rc-alan"><label>SMS Adedi *</label><input type="number" id="pAdet" min="1" step="1" placeholder="1000" value="${p?.sms_adet ?? ''}"></div>
      <div class="rc-alan"><label>Tutar — KDV/Tüm Vergiler Dahil (₺) *</label><input type="number" id="pUcret" min="0" step="0.01" placeholder="500.00" value="${p?.ucret ?? ''}">
        <span class="rc-ipucu">Müşteriye gösterilen toplam tutar. KDV bu tutarın içindedir.</span></div>
      <div class="rc-alan rc-tam"><label>Kart Rengi (tema)</label><select id="pRenk">${secenekHtml(s.kart_renkleri, p?.class.kod ?? s.varsayilan_renk)}</select>
        <span class="rc-ipucu">Salon panelindeki paket kartının rengini belirler.</span></div>
    </div>`;
  const alt = `<button type="button" class="rc-btn rc-btn-ikincil" id="pVazgec">İptal</button>
    <button type="button" class="rc-btn rc-btn-birincil" id="pKaydet"><i class="bi bi-check-lg"></i> Kaydet</button>`;
  const ov = modalAc(p ? `${p.sms_adet} SMS — Düzenle` : 'Yeni İnteraktif Duyuru Paketi', govde, alt, {
    ikon: 'box-seam', sabit: true, altbaslik: 'Salon panelinde "SATIN AL" olarak gözükür · Fiyat tüm vergiler dahil girilir',
  });
  el('#pVazgec', ov).onclick = modalKapat;
  el('#pKaydet', ov).onclick = (e) => kilitli(e.currentTarget, async () => {
    const { tamam } = await syYaz(p ? 'PUT' : 'POST', p ? `/sms-paket/${p.id}` : '/sms-paket', {
      paket_adi: el('#pAd', ov).value.trim(),
      alt_baslik: el('#pAlt', ov).value.trim(),
      sms_adet: el('#pAdet', ov).value,
      ucret: el('#pUcret', ov).value,
      class: el('#pRenk', ov).value,
    });
    if (tamam) { modalKapat(); tablo.yenile(false); }
  });
}

async function sil(p) {
  if (!await onay('Bu paket silinsin mi?', { baslik: 'Paketi Sil', tamam: 'Sil' })) return;
  const { tamam } = await syYaz('DELETE', `/sms-paket/${p.id}`);
  if (tamam) tablo.yenile(false);
}

async function baslat() {
  s = await syVeri('/sms-paket/secenekler');
  if (!s) return;

  ekran().innerHTML = `
    ${sayfaBaslik('İnteraktif Duyuru Paketleri', 'Salonlara satılan SMS (duyuru) paketleri · Fiyatlar tüm vergiler dahildir',
    s.yazabilir ? '<button type="button" class="sy-btn sy-btn-primary" id="pYeni"><span class="mdi mdi-plus"></span> Yeni Paket</button>' : '')}
    <div class="sy-card"><div class="sy-card-body" id="pTablo"></div></div>`;

  const kap = el('#pTablo');
  tablo = veriTablosu(kap, {
    anahtar: 'sy_sms_paket',
    varsayilanBoy: 25,
    birim: 'paket',
    araIpucu: 'Başlık, SMS adedi...',
    bosMesaj: 'Henüz paket yok',
    sirala: { alan: 'sms_adet', yon: 'asc' },
    kolonlar: [
      { baslik: 'Paket', sirala: 'sms_adet' }, { baslik: 'Tutar (KDV dahil)', sirala: 'ucret', sinif: 'rc-sag' }, { baslik: 'Matrah', sinif: 'rc-sag' },
      { baslik: `KDV (%${s.kdv_yuzde})`, sinif: 'rc-sag' }, { baslik: 'Kart Rengi', sirala: 'class' },
      ...(s.yazabilir ? [{ baslik: 'İşlem', sinif: 'rc-sag' }] : []),
    ],
    satir: (p) => `<tr>
      <td class="sy-fw-600">
        ${esc(p.paket_adi || `${sayi(p.sms_adet)} SMS`)}
        ${p.paket_adi ? `<div class="sy-text-muted sy-fs-12">${sayi(p.sms_adet)} SMS</div>` : ''}
        ${p.alt_baslik ? `<div class="sy-text-muted sy-fs-12">${esc(p.alt_baslik)}</div>` : ''}
      </td>
      <td class="rc-sag sy-fw-600">${para(p.ucret)} ₺</td>
      <td class="rc-sag sy-fs-13">${para(p.matrah)} ₺</td>
      <td class="rc-sag sy-fs-13">${para(p.kdv)} ₺</td>
      <td>${etiketRozet({ ...p.class, etiket: p.class.kod })}</td>
      ${s.yazabilir ? `<td class="rc-sag">
        <button type="button" class="sy-btn sy-btn-sm sy-btn-soft" data-duzenle="${p.id}" title="Düzenle"><span class="mdi mdi-pencil"></span></button>
        <button type="button" class="sy-btn sy-btn-sm sy-btn-danger" data-sil="${p.id}" title="Sil"><span class="mdi mdi-delete"></span></button>
      </td>` : ''}
    </tr>`,
    kaynak: (p) => syApi('GET', '/sms-paket', { params: p }),
    cizildi: (liste) => { satirlar = liste; },
  });

  kap.addEventListener('click', (e) => {
    const b = e.target.closest('[data-duzenle],[data-sil]');
    if (!b) return;
    const p = satirlar.find((x) => String(x.id) === (b.dataset.duzenle ?? b.dataset.sil));
    if (b.dataset.duzenle) formAc(p); else sil(p);
  });
  el('#pYeni')?.addEventListener('click', () => formAc(null));
  tablo.yenile();
}

baslat();
