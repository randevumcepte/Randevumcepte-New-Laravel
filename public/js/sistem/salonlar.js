/** Salon listesi: filtreler, hizli filtre sekmeleri, toplu islem. Veri /api/v2/sistem/salon. */
import { el, esc, onay, mesaj } from '/js/lib/v2-ortak.js?v=7';
import { syApi, syYaz, syIndir, ekran, tarih } from '/js/lib/v2-sistem.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=8';
import { hesabinaGir } from '/js/sistem/ortak.js?v=1';
import { salonSecenekleri, secenekler, telLink } from '/js/sistem/salon-ortak.js?v=1';

const kok = ekran();
const filtre = { durum: 'hepsi', mt: '', tip: 'tumu' };
const SEKMELER = [['tumu', 'Tümü'], ['aktif', 'Aktif'], ['demo', 'Demo']];
const TOPLU_METIN = { mt_ata: 'müşteri temsilcisi atanacak', askiya_al: 'askıya alınacak', aktif_et: 'aktif edilecek' };

let tablo = null;
let izin = {};

function iskelet(sec) {
  kok.innerHTML = `
    <div class="sy-page-head">
      <div><h2>Salonlar</h2><div class="subtitle" id="slAlt">&nbsp;</div></div>
      <div class="sy-flex-row">
        ${izin.duzenle ? `<button type="button" class="sy-btn" id="slCsv"><span class="mdi mdi-file-download"></span> CSV</button>
        <a href="/sistemyonetim/salon-ekle" class="sy-btn sy-btn-primary"><span class="mdi mdi-plus"></span> Yeni Salon</a>` : ''}
      </div>
    </div>
    <div class="sy-filters">
      <div class="sy-form-group sl-dar"><label>Durum</label>
        <select id="slDurum" class="sy-select"><option value="hepsi">Hepsi</option><option value="aktif">Aktif</option><option value="askida">Askıda</option></select></div>
      <div class="sy-form-group sl-orta"><label>Müşteri Temsilcisi</label>
        <select id="slMt" class="sy-select">${secenekler(sec.temsilciler, 'id', 'name', '', 'Hepsi')}</select></div>
      <button type="button" class="sy-btn" id="slSifirla">Sıfırla</button>
    </div>
    ${izin.duzenle ? `<div class="sy-bulk-bar" id="slToplu">
      <span class="count" id="slSayi">0 salon seçildi</span>
      <select id="slIslem" class="sy-select sl-orta"><option value="">İşlem seç...</option>
        <option value="mt_ata">Müşteri Temsilcisi Ata</option><option value="askiya_al">Askıya Al</option><option value="aktif_et">Aktif Et</option></select>
      <select id="slTopluMt" class="sy-select sl-orta" hidden>${secenekler(sec.temsilciler, 'id', 'name', '', 'Atama yok')}</select>
      <input type="text" id="slSebep" class="sy-input sl-genis" placeholder="Askıya alma sebebi" hidden>
      <button type="button" class="sy-btn sy-btn-primary" id="slUygula"><span class="mdi mdi-arrow-right"></span> Uygula</button>
      <button type="button" class="sy-btn" id="slVazgec">Vazgeç</button>
    </div>` : ''}
    <div class="sl-sekmeler" id="slSekmeler"></div>
    <div class="sy-card"><div class="sy-card-body tight" id="slTablo"></div></div>`;
}

function sekmeCiz(sayilar) {
  el('#slSekmeler').innerHTML = SEKMELER.map(([kod, ad]) => `<button type="button" data-tip="${kod}" class="sy-btn ${filtre.tip === kod ? 'sy-btn-primary' : 'sy-btn-soft'}">${ad}<span class="sl-sayi">${sayilar[kod] ?? 0}</span></button>`).join('');
}

function satir(s) {
  const u = s.uyelik;
  const konum = `${esc(s.il_adi || '—')} / ${esc(s.ilce_adi || '—')}`;
  return `<tr>
    ${izin.duzenle ? `<td class="sy-row-check"><input type="checkbox" class="slSec" value="${s.id}"></td>` : ''}
    <td><div class="sy-fw-600">${esc(s.salon_adi)}</div><div class="sy-text-muted sy-fs-12">${esc(s.telefon_1 || '—')}</div></td>
    <td class="sy-text-muted sy-fs-13">${konum}</td>
    <td><div>${esc(s.yetkili || '—')}</div>${s.telefon ? `<div class="sy-text-muted sy-fs-12"><a href="tel:${esc(telLink(s.telefon))}" class="sl-tel">${esc(s.telefon)}</a></div>` : ''}</td>
    <td class="sy-text-muted sy-fs-13">${esc(s.temsilci || '—')}</td>
    <td class="sy-text-muted sy-fs-12 nowrap">${tarih(s.kayit)}</td>
    <td class="sy-fs-12 nowrap">${u.bitis ? `<span class="sl-bitis" style="color:var(--sy-${esc(u.renk)})">${tarih(u.bitis)}</span><div class="sy-text-muted sl-kalan">${esc(u.metin)}</div>` : '<span class="sy-text-muted">—</span>'}</td>
    <td><span class="sy-badge sy-badge-${esc(s.rozet.renk)}">${esc(s.rozet.etiket)}</span></td>
    <td class="sy-text-right nowrap">
      <a href="/sistemyonetim/salon/${s.id}" class="sy-btn sy-btn-sm sy-btn-soft" title="Detay"><span class="mdi mdi-information-outline"></span></a>
      ${izin.hesaba_gir ? `<button type="button" class="sy-btn sy-btn-sm sy-btn-primary" data-gir="${s.id}" title="Salonun hesabına gir"${s.askida ? ' disabled' : ''}><span class="mdi mdi-login"></span> Hesabına Gir</button>` : ''}
    </td></tr>`;
}

const seciliIdler = () => [...kok.querySelectorAll('.slSec:checked')].map((c) => Number(c.value));

function seciliSay() {
  const bar = el('#slToplu');
  if (!bar) return;
  const n = seciliIdler().length;
  bar.classList.toggle('visible', n > 0);
  el('#slSayi').textContent = `${n} salon seçildi`;
  if (!n) { const hepsi = el('#slHepsi'); if (hepsi) hepsi.checked = false; }
}

function tumunuSecKutusu() {
  if (!izin.duzenle) return;
  const th = el('#slTablo thead th');
  if (th) th.innerHTML = '<input type="checkbox" id="slHepsi">';
  seciliSay();
}

function tabloKur() {
  tablo = veriTablosu(el('#slTablo'), {
    anahtar: 'sy_salonlar', varsayilanBoy: 100, birim: 'salon', tabloSinif: 'sy-table',
    bosMesaj: 'Salon bulunamadı', araIpucu: 'Salon adı, telefon, yetkili...',
    sirala: { alan: 'id', yon: 'desc' },
    kolonlar: [
      ...(izin.duzenle ? [{ baslik: '', sinif: 'sy-row-check' }] : []),
      { baslik: 'Salon', sirala: 'salon' }, { baslik: 'Konum' }, { baslik: 'Yetkili' }, { baslik: 'Müşteri Temsilcisi' },
      { baslik: 'Kayıt', sirala: 'kayit' }, { baslik: 'Üyelik Bitiş', sirala: 'bitis' }, { baslik: 'Durum' }, { baslik: 'İşlemler', sinif: 'sy-text-right' },
    ],
    satir,
    kaynak: (p) => syApi('GET', '/salon', { params: { ...p, ...filtre } }),
    cizildi: (liste, v) => {
      sekmeCiz(v?.sayilar || {});
      const bas = liste.length ? (v.current_page - 1) * v.per_page + 1 : 0;
      el('#slAlt').textContent = `Toplam ${v?.total ?? 0} salon · ${bas}-${bas ? bas + liste.length - 1 : 0} arası`;
      tumunuSecKutusu();
    },
  });
  tablo.yenile();
}

async function topluUygula() {
  const ids = seciliIdler();
  const islem = el('#slIslem').value;
  if (!ids.length || !islem) { mesaj('Önce salon ve işlem seçin.', 'hata'); return; }
  if (!await onay(`${ids.length} salon ${TOPLU_METIN[islem]}. Onaylıyor musunuz?`, { tehlike: false })) return;
  const { tamam } = await syYaz('POST', '/salon/toplu-islem', {
    ids, islem,
    mt_id: islem === 'mt_ata' ? (el('#slTopluMt').value || null) : null,
    sebep: islem === 'askiya_al' ? (el('#slSebep').value.trim() || null) : null,
  });
  if (tamam) tablo.yenile(false);
}

function olaylariBagla() {
  el('#slDurum').addEventListener('change', (e) => { filtre.durum = e.target.value; tablo.yenile(); });
  el('#slMt').addEventListener('change', (e) => { filtre.mt = e.target.value; tablo.yenile(); });
  el('#slSifirla').addEventListener('click', () => {
    Object.assign(filtre, { durum: 'hepsi', mt: '', tip: 'tumu' });
    el('#slDurum').value = 'hepsi';
    el('#slMt').value = '';
    tablo.yenile();
  });
  el('#slCsv')?.addEventListener('click', () => syIndir('/salon/csv', { ...filtre, ara: tablo.durum.ara }, 'salonlar.csv'));
  el('#slIslem')?.addEventListener('change', (e) => {
    el('#slTopluMt').hidden = e.target.value !== 'mt_ata';
    el('#slSebep').hidden = e.target.value !== 'askiya_al';
  });
  el('#slUygula')?.addEventListener('click', topluUygula);
  el('#slVazgec')?.addEventListener('click', () => {
    kok.querySelectorAll('.slSec').forEach((c) => { c.checked = false; });
    seciliSay();
  });
  kok.addEventListener('change', (e) => {
    if (e.target.id === 'slHepsi') kok.querySelectorAll('.slSec').forEach((c) => { c.checked = e.target.checked; });
    if (e.target.id === 'slHepsi' || e.target.classList.contains('slSec')) seciliSay();
  });
  kok.addEventListener('click', (e) => {
    const sekme = e.target.closest('[data-tip]');
    if (sekme) { filtre.tip = sekme.dataset.tip; tablo.yenile(); return; }
    const gir = e.target.closest('[data-gir]');
    if (gir) hesabinaGir(Number(gir.dataset.gir), 'Destek girişi');
  });
}

const sec = await salonSecenekleri();
if (sec) {
  izin = sec.izinler;
  iskelet(sec);
  tabloKur();
  olaylariBagla();
}
