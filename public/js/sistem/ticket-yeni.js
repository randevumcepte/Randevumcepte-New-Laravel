/** Yeni destek talebi: salon adina ya da iceriden. */
import { el, esc, qp } from '/js/lib/v2-ortak.js?v=7';
import { ekran, syVeri, syYaz } from '/js/lib/v2-sistem.js?v=7';
import { geriLink, kilitli, salonSecici, sayfaBaslik, secenekHtml } from '/js/sistem/ortak.js?v=1';

const LISTE = '/sistemyonetim/ticket';

async function baslat() {
  const s = await syVeri('/talep/secenekler', { salon_id: qp.get('salon_id') });
  if (!s) return;

  ekran().innerHTML = `
    ${sayfaBaslik('Yeni Destek Talebi', 'Salon adına ya da içeriden talep aç', geriLink(LISTE))}
    <div class="sy-card" style="max-width:880px"><div class="sy-card-body">
      <div class="sy-form-group"><label>Konu *</label><input type="text" id="tKonu" class="sy-input" maxlength="250" placeholder="Kısa özet"></div>
      <div class="sy-form-row">
        <div class="sy-form-group"><label>İlgili Salon <span class="sy-text-muted">(boşsa iç talep)</span></label><div id="tSalon"></div></div>
        <div class="sy-form-group"><label>Kategori *</label><select id="tKategori" class="sy-select">${secenekHtml(s.kategoriler, s.varsayilan_kategori)}</select></div>
      </div>
      <div class="sy-form-row">
        <div class="sy-form-group"><label>Öncelik *</label><select id="tOncelik" class="sy-select">${secenekHtml(s.oncelikler, s.varsayilan_oncelik)}</select></div>
        <div class="sy-form-group"><label>İletişim — Ad</label><input type="text" id="tIletisimAd" class="sy-input" maxlength="120"></div>
      </div>
      <div class="sy-form-row">
        <div class="sy-form-group"><label>İletişim — Telefon</label><input type="text" id="tIletisimTel" class="sy-input" maxlength="30"></div>
        <div class="sy-form-group"><label>İletişim — E-posta</label><input type="email" id="tIletisimEposta" class="sy-input" maxlength="191"></div>
      </div>
      <div class="sy-form-group"><label>Açıklama</label><textarea id="tAciklama" class="sy-textarea" rows="6" placeholder="Detaylar..."></textarea></div>
      <div class="sy-flex-row" style="justify-content:flex-end">
        <a href="${esc(LISTE)}" class="sy-btn">İptal</a>
        ${s.yazabilir ? '<button type="button" class="sy-btn sy-btn-primary" id="tKaydet"><span class="mdi mdi-content-save"></span> Talep Oluştur</button>' : ''}
      </div>
    </div></div>`;

  const salon = salonSecici(el('#tSalon'), { baslangic: s.secili_salon ? [s.secili_salon] : [] });
  el('#tKaydet')?.addEventListener('click', (e) => kilitli(e.currentTarget, async () => {
    const { tamam, veri } = await syYaz('POST', '/talep', {
      konu: el('#tKonu').value.trim(),
      salon_id: salon.tekId(),
      kategori: el('#tKategori').value,
      oncelik: el('#tOncelik').value,
      iletisim_ad: el('#tIletisimAd').value.trim(),
      iletisim_telefon: el('#tIletisimTel').value.trim(),
      iletisim_email: el('#tIletisimEposta').value.trim(),
      aciklama: el('#tAciklama').value,
    });
    if (tamam) location.href = `${LISTE}/${veri.id}`;
  }));
}

baslat();
