/** Duyuru formu: kayit id'si varsa duzenleme, yoksa yeni. */
import { el, esc } from '/js/lib/v2-ortak.js?v=7';
import { ekran, kayitId, syVeri, syYaz } from '/js/lib/v2-sistem.js?v=7';
import { geriLink, kilitli, salonSecici, sayfaBaslik, secenekHtml, tarihSaatBagla } from '/js/sistem/ortak.js?v=1';

const LISTE = '/sistemyonetim/duyuru';
const id = kayitId();

function formCiz(s, d) {
  const iller = s.iller.map((il) => `<option value="${il.id}"${d?.il_ids.includes(il.id) ? ' selected' : ''}>${esc(il.il_adi)}</option>`).join('');

  ekran().innerHTML = `
    ${sayfaBaslik(d ? `${d.baslik} — Düzenle` : 'Yeni Duyuru', 'Salonlara duyurulacak içerik · Banner olarak işletme panelinde gözükür', geriLink(LISTE))}
    <div class="sy-grid-2-1">
      <div class="sy-card"><div class="sy-card-head"><h3>İçerik</h3></div><div class="sy-card-body">
        <div class="sy-form-group"><label>Başlık *</label><input type="text" id="fBaslik" class="sy-input" maxlength="200" value="${esc(d?.baslik || '')}"></div>
        <div class="sy-form-group"><label>İçerik *</label><textarea id="fIcerik" class="sy-textarea" rows="6"></textarea>
          <div class="sy-text-muted sy-fs-12 sy-mt-12">HTML kullanılabilir. Banner'da gösterilir.</div></div>
        <div class="sy-form-row">
          <div class="sy-form-group"><label>Eylem Buton Metni (opsiyonel)</label><input type="text" id="fCtaMetin" class="sy-input" maxlength="80" placeholder="Detayları Gör" value="${esc(d?.cta_metin || '')}"></div>
          <div class="sy-form-group"><label>Eylem Linki</label><input type="text" id="fCtaLink" class="sy-input" maxlength="250" placeholder="/isletmeyonetim/..." value="${esc(d?.cta_link || '')}"></div>
        </div>
      </div></div>
      <div class="sy-stack">
        <div class="sy-card"><div class="sy-card-head"><h3>Yayın Ayarları</h3></div><div class="sy-card-body">
          <div class="sy-form-group"><label>Tip *</label><select id="fTip" class="sy-select">${secenekHtml(s.tipler, d?.tip ?? s.varsayilan_tip)}</select>
            <div class="sy-text-muted sy-fs-12 sy-mt-12"><strong>🚀 Güncelleme / Yenilik</strong> tipi, salon paneline girişte <strong>ortada kaliteli bir popup</strong> olarak açılır ve salon "Anladım" deyince bir daha çıkmaz. Diğer tipler sağ üstte köşe bildirimi olarak gösterilir.</div></div>
          <div class="sy-form-row">
            <div class="sy-form-group"><label>Başlangıç</label><input type="text" id="fBaslangic" class="sy-input" autocomplete="off"></div>
            <div class="sy-form-group"><label>Bitiş</label><input type="text" id="fBitis" class="sy-input" autocomplete="off"></div>
          </div>
          <div class="sy-form-group">
            <label class="sy-flex-row" style="align-items:center"><input type="checkbox" id="fAktif"${d && !d.aktif ? '' : ' checked'}> Aktif</label>
            <label class="sy-flex-row" style="align-items:center"><input type="checkbox" id="fSticky"${d?.sticky ? ' checked' : ''}> Sabit (her panele girişte gözüksün)</label>
          </div>
        </div></div>
        <div class="sy-card"><div class="sy-card-head"><h3>Hedef</h3></div><div class="sy-card-body">
          <div class="sy-form-group"><label>Hedef Tipi</label><select id="fHedef" class="sy-select">${secenekHtml(s.hedef_tipleri, d?.hedef_tipi ?? s.varsayilan_hedef)}</select></div>
          <div class="sy-form-group" id="fIlKap"><label>İl(ler)</label><select id="fIller" class="sy-select" multiple size="6" style="height:auto">${iller}</select>
            <div class="sy-text-muted sy-fs-12">Ctrl/Cmd ile çoklu seçim</div></div>
          <div class="sy-form-group" id="fSalonKap"><label>Salonlar</label><div id="fSalonlar"></div></div>
        </div></div>
        <div class="sy-card"><div class="sy-card-body sy-flex-row" style="justify-content:flex-end">
          <a href="${LISTE}" class="sy-btn">İptal</a>
          <button type="button" class="sy-btn sy-btn-primary" id="fKaydet"><span class="mdi mdi-bullhorn"></span> Kaydet</button>
        </div></div>
      </div>
    </div>`;

  el('#fIcerik').value = d?.icerik || '';
  tarihSaatBagla(el('#fBaslangic'), d?.baslangic_tarihi);
  tarihSaatBagla(el('#fBitis'), d?.bitis_tarihi);
  const salon = salonSecici(el('#fSalonlar'), { coklu: true, baslangic: d?.secili_salonlar || [] });

  const hedefGoster = () => {
    const v = el('#fHedef').value;
    el('#fSalonKap').style.display = v === 'secili' ? 'block' : 'none';
    el('#fIlKap').style.display = v === 'il' ? 'block' : 'none';
  };
  el('#fHedef').addEventListener('change', hedefGoster);
  hedefGoster();

  el('#fKaydet').addEventListener('click', (e) => kilitli(e.currentTarget, async () => {
    const { tamam } = await syYaz(id ? 'PUT' : 'POST', id ? `/duyuru/${id}` : '/duyuru', {
      baslik: el('#fBaslik').value.trim(),
      icerik: el('#fIcerik').value,
      tip: el('#fTip').value,
      hedef_tipi: el('#fHedef').value,
      salon_ids: salon.idler(),
      il_ids: [...el('#fIller').selectedOptions].map((o) => parseInt(o.value, 10)),
      baslangic_tarihi: el('#fBaslangic').value || null,
      bitis_tarihi: el('#fBitis').value || null,
      aktif: el('#fAktif').checked,
      sticky: el('#fSticky').checked,
      cta_metin: el('#fCtaMetin').value.trim(),
      cta_link: el('#fCtaLink').value.trim(),
    });
    if (tamam) location.href = LISTE;
  }));
}

async function baslat() {
  const s = await syVeri('/duyuru/secenekler');
  if (!s) return;
  if (!s.yazabilir) {
    ekran().innerHTML = `${sayfaBaslik('Duyuru', '', geriLink(LISTE))}<div class="sy-alert sy-alert-warning">Duyuru düzenleme yetkiniz yok.</div>`;
    return;
  }
  const d = id ? await syVeri(`/duyuru/${id}/form`) : null;
  if (id && !d) return;
  formCiz(s, d);
}

baslat();
