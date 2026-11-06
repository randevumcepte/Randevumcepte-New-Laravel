/** Talep detayi: konusma, hazir cevap secici, durum/oncelik/atama, salon hesabina giris. */
import { el, esc, onay } from '/js/lib/v2-ortak.js?v=2';
import { ekran, kayitId, syApi, syVeri, syYaz, tarihSaat } from '/js/lib/v2-sistem.js?v=1';
import { geriLink, kilitli, kisalt, metinHtml, etiketRozet, secenekHtml, hesabinaGir } from '/js/sistem/ortak.js?v=1';

const id = kayitId();
const yol = `/talep/${id}`;
let s = null;
let d = null;

function baslikCiz() {
  const t = d.talep;
  el('#dBas').innerHTML = `
    <div>
      <h2>${esc(t.numara)} — ${esc(kisalt(t.konu, 70))}</h2>
      <div class="subtitle">
        ${etiketRozet({ ...t.oncelik, etiket: `Öncelik: ${t.oncelik.etiket}` })} ${etiketRozet(t.durum)} ${etiketRozet(t.kategori)}
        · Açılış: ${esc(tarihSaat(t.acilis))}
      </div>
    </div>
    ${geriLink('/sistemyonetim/ticket', 'Liste')}`;
}

function mesajlarCiz() {
  const kap = el('#dMesajlar');
  kap.innerHTML = d.mesajlar.length ? d.mesajlar.map((m) => `
    <div class="sy-msg ${m.user_tipi === 'salon' ? 'salon' : ''} ${m.ic_not ? 'ic-not' : ''}">
      <div class="avatar">${esc((m.user_name || '?').charAt(0))}</div>
      <div class="bubble">
        <div class="meta">
          <strong>${esc(m.user_name)}</strong>
          <span class="sy-badge sy-badge-muted">${esc(m.user_tipi)}</span>
          ${m.ic_not ? '<span class="sy-badge sy-badge-warning"><span class="mdi mdi-eye-off"></span> İç Not</span>' : ''}
          · ${esc(tarihSaat(m.tarih))}
        </div>
        ${metinHtml(m.mesaj)}
      </div>
    </div>`).join('') : '<div class="sy-empty"><div class="baslik">Henüz mesaj yok</div></div>';
  kap.scrollTop = kap.scrollHeight;
}

const bilgi = (etiket, deger) => (deger ? `<div><span class="sy-text-muted sy-fs-12">${etiket}</span><div>${esc(deger)}</div></div>` : '');

function detayCiz() {
  const t = d.talep;
  el('#dDetay').innerHTML = `
    ${d.salon ? `<div>
      <span class="sy-text-muted sy-fs-12">Salon</span>
      <div class="sy-fw-600"><a href="/sistemyonetim/salon/${d.salon.id}">${esc(d.salon.salon_adi)}</a></div>
      ${s.yazabilir ? '<button type="button" class="sy-btn sy-btn-sm sy-btn-primary sy-mt-12" id="dGir"><span class="mdi mdi-login"></span> Hesabına Gir</button>' : ''}
    </div>` : ''}
    <div><span class="sy-text-muted sy-fs-12">Açan</span><div>${esc(t.olusturan_user_name || '—')}</div></div>
    ${bilgi('İletişim Adı', t.iletisim_ad)}
    ${bilgi('Telefon', t.iletisim_telefon)}
    ${bilgi('E-posta', t.iletisim_email)}
    ${t.ilk_yanit_tarihi ? bilgi('İlk Yanıt', tarihSaat(t.ilk_yanit_tarihi)) : ''}`;
  el('#dGir')?.addEventListener('click', hesabaGir);
}

function ciz() { baslikCiz(); mesajlarCiz(); detayCiz(); }

async function hesabaGir() {
  const tamam = await onay(`${d.salon.salon_adi} hesabına giriş yapılacak. Bu işlem loglanır.`, { baslik: 'Hesabına Gir', tamam: 'Giriş Yap', tehlike: false });
  if (!tamam) return;
  await hesabinaGir(d.salon.id, `Ticket #${d.talep.numara}`, { ticketId: id });
}

/** Yazma sonrasi sunucu guncel detayi doner; taslak yanit bozulmasin diye form yeniden cizilmez. */
async function islem(buton, altYol, govde, sonra) {
  await kilitli(buton, async () => {
    const r = await syYaz('POST', `${yol}/${altYol}`, govde);
    if (!r.tamam) return;
    d = r.veri;
    ciz();
    if (sonra) sonra();
  });
}

function hazirCevapBagla() {
  const panel = el('#hcPanel');
  const arama = el('#hcArama');
  const liste = el('#hcListe');
  const alan = el('#dYanit');
  let zaman = null;
  let no = 0;
  let sonuclar = [];

  const yukle = async (q) => {
    const benim = ++no;
    const v = await syVeri('/hazir-cevap/secim', { q });
    if (benim !== no || !v) return;
    sonuclar = v;
    liste.innerHTML = v.length ? v.map((c) => `
      <div class="hc-oge" data-id="${c.id}">
        <div class="sy-fw-600 sy-fs-13">${esc(c.baslik)}${c.kisayol ? ` <span class="sy-text-muted sy-fs-12">${esc(c.kisayol)}</span>` : ''}</div>
        <div class="sy-text-muted sy-fs-12">${esc(kisalt(c.icerik, 80))}</div>
      </div>`).join('') : '<div class="hc-bos sy-text-muted sy-fs-12">Sonuç yok</div>';
  };

  el('#hcAc').addEventListener('click', () => {
    panel.hidden = !panel.hidden;
    if (!panel.hidden) { arama.value = ''; arama.focus(); yukle(); }
  });
  arama.addEventListener('input', () => {
    clearTimeout(zaman);
    zaman = setTimeout(() => yukle(arama.value.trim()), 200);
  });
  liste.addEventListener('click', (e) => {
    const oge = e.target.closest('[data-id]');
    if (!oge) return;
    const c = sonuclar.find((x) => String(x.id) === oge.dataset.id);
    alan.value = alan.value.trim() ? `${alan.value}\n\n${c.icerik}` : c.icerik;
    panel.hidden = true;
    alan.focus();
    syApi('POST', `/hazir-cevap/${c.id}/kullan`);
  });
  document.addEventListener('click', (e) => {
    if (!panel.contains(e.target) && !e.target.closest('#hcAc')) panel.hidden = true;
  });
}

function formBagla() {
  hazirCevapBagla();
  el('#dYanitla').addEventListener('click', (e) => {
    islem(e.currentTarget, 'yanit', { mesaj: el('#dYanit').value, ic_not: el('#dIcNot').checked }, () => {
      el('#dYanit').value = '';
      el('#dIcNot').checked = false;
    });
  });
  el('#dDurumBtn').addEventListener('click', (e) => islem(e.currentTarget, 'durum', { durum: el('#dDurum').value }));
  el('#dOncelikBtn').addEventListener('click', (e) => islem(e.currentTarget, 'oncelik', { oncelik: el('#dOncelik').value }));
  el('#dAtaBtn').addEventListener('click', (e) => islem(e.currentTarget, 'ata', { atanan_user_id: el('#dAtanan').value || null }));
}

const yanitFormu = () => `
  <div class="sy-mt-18">
    <div class="sy-form-group hc-kap">
      <div class="sy-flex-row" style="justify-content:space-between;margin-bottom:6px">
        <label style="margin:0">Yanıt</label>
        <button type="button" class="sy-btn sy-btn-sm sy-btn-soft" id="hcAc"><span class="mdi mdi-message-text-fast"></span> Hazır Cevap</button>
      </div>
      <textarea id="dYanit" class="sy-textarea" rows="5" placeholder="Yanıt yaz... (hazır cevap için butonu kullan)"></textarea>
      <div id="hcPanel" class="hc-panel" hidden>
        <div class="hc-arama"><input type="text" id="hcArama" class="sy-input" placeholder="Başlık, kısayol veya içerik ara..."></div>
        <div id="hcListe"></div>
      </div>
    </div>
    <div class="sy-flex-row" style="justify-content:space-between">
      <label class="sy-flex-row sy-fs-13"><input type="checkbox" id="dIcNot"> İç not olarak ekle (sadece ekip görür)</label>
      <button type="button" class="sy-btn sy-btn-primary" id="dYanitla"><span class="mdi mdi-send"></span> Yanıtla</button>
    </div>
  </div>`;

const secimSatiri = (etiket, secimId, secenekler, btnId, btnMetin) => `
  <div class="sy-form-group"><label>${etiket}</label>
    <div class="sy-flex-row"><select id="${secimId}" class="sy-select">${secenekler}</select>
    <button type="button" class="sy-btn sy-btn-sm sy-btn-soft" id="${btnId}">${btnMetin}</button></div>
  </div>`;

const islemKarti = () => {
  const t = d.talep;
  const ekip = s.ekip.map((e) => ({ kod: e.id, etiket: e.ad }));
  return `<div class="sy-card"><div class="sy-card-head"><h3>İşlemler</h3></div><div class="sy-card-body">
    ${secimSatiri('Durum', 'dDurum', secenekHtml(s.durumlar, t.durum.kod), 'dDurumBtn', 'Güncelle')}
    ${secimSatiri('Öncelik', 'dOncelik', secenekHtml(s.oncelikler, t.oncelik.kod), 'dOncelikBtn', 'Güncelle')}
    ${secimSatiri('Atanan', 'dAtanan', `<option value="">— atanmamış —</option>${secenekHtml(ekip, t.atanan_user_id)}`, 'dAtaBtn', 'Ata')}
  </div></div>`;
};

async function baslat() {
  [s, d] = await Promise.all([syVeri('/talep/secenekler'), syVeri(yol)]);
  if (!s || !d) return;

  ekran().innerHTML = `
    <div class="sy-page-head" id="dBas"></div>
    <div class="sy-grid-2-1">
      <div class="sy-stack"><div class="sy-card">
        <div class="sy-card-head"><h3>Konuşma</h3></div>
        <div class="sy-card-body"><div class="sy-chat" id="dMesajlar"></div>${s.yazabilir ? yanitFormu() : ''}</div>
      </div></div>
      <div class="sy-stack">
        ${s.yazabilir ? islemKarti() : ''}
        <div class="sy-card"><div class="sy-card-head"><h3>Detay</h3></div>
          <div class="sy-card-body"><div class="sy-stack" style="gap:8px" id="dDetay"></div></div></div>
      </div>
    </div>`;
  ciz();
  if (s.yazabilir) formBagla();
}

baslat();
