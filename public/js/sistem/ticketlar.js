/** Destek talepleri listesi. */
import { el, esc, tarihStr } from '/js/lib/v2-ortak.js?v=2';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=2';
import { ekran, syApi, syIndir, syVeri, tarihSaat } from '/js/lib/v2-sistem.js?v=1';
import { kisalt, etiketRozet, sayfaBaslik, secenekHtml } from '/js/sistem/ortak.js?v=1';

const ALT = (n) => `${n} talep — gerçek zamanlı çalışma alanı`;

async function baslat() {
  const s = await syVeri('/talep/secenekler');
  if (!s) return;

  const atananlar = [...s.atanan_filtreleri, ...s.ekip.map((e) => ({ kod: e.id, etiket: e.ad }))];
  ekran().innerHTML = `
    ${sayfaBaslik('Destek Talepleri', ALT('—'), `
      ${s.csv ? '<button type="button" class="sy-btn" id="tCsv"><span class="mdi mdi-file-download"></span> CSV</button>' : ''}
      ${s.yazabilir ? '<a href="/sistemyonetim/ticket/yeni" class="sy-btn sy-btn-primary"><span class="mdi mdi-plus"></span> Yeni Talep</a>' : ''}`)}
    <div class="sy-filters">
      <div class="sy-form-group" style="max-width:170px"><label>Durum</label>
        <select id="fDurum" class="sy-select">${secenekHtml(s.durum_filtreleri, s.varsayilan_filtre)}</select></div>
      <div class="sy-form-group" style="max-width:140px"><label>Öncelik</label>
        <select id="fOncelik" class="sy-select"><option value="">Hepsi</option>${secenekHtml(s.oncelikler)}</select></div>
      <div class="sy-form-group" style="max-width:180px"><label>Atanan</label>
        <select id="fAtanan" class="sy-select"><option value="">Hepsi</option>${secenekHtml(atananlar)}</select></div>
    </div>
    <div class="sy-card"><div class="sy-card-body" id="tTablo"></div></div>`;

  const kap = el('#tTablo');
  const tablo = veriTablosu(kap, {
    anahtar: 'sy_talep',
    varsayilanBoy: 25,
    birim: 'talep',
    araIpucu: 'Numara, konu, salon...',
    bosMesaj: 'Talep yok',
    kolonlar: [
      { baslik: '#', sirala: 'numara' }, { baslik: 'Konu', sirala: 'konu' }, { baslik: 'Salon', sirala: 'salon' },
      { baslik: 'Kategori', sirala: 'kategori' }, { baslik: 'Öncelik', sirala: 'oncelik' }, { baslik: 'Durum', sirala: 'durum' },
      { baslik: 'Atanan', sirala: 'atanan' }, { baslik: 'Açılış', sirala: 'tarih' },
    ],
    satir: (t) => `<tr data-id="${t.id}" style="cursor:pointer">
      <td class="rc-nowrap"><strong>${esc(t.numara)}</strong></td>
      <td><div class="sy-fw-600">${esc(kisalt(t.konu, 50))}</div></td>
      <td class="sy-fs-13">${esc(t.salon_adi || '—')}</td>
      <td>${etiketRozet(t.kategori)}</td>
      <td>${etiketRozet(t.oncelik)}</td>
      <td>${etiketRozet(t.durum)}</td>
      <td class="sy-fs-13">${esc(t.atanan_user_name || '—')}</td>
      <td class="rc-nowrap sy-text-muted sy-fs-12">${esc(tarihSaat(t.acilis))}<br>${esc(t.acilis_goreli || '')}</td>
    </tr>`,
    kaynak: (p) => syApi('GET', '/talep', { params: {
      ...p, durum: el('#fDurum').value, oncelik: el('#fOncelik').value, atanan: el('#fAtanan').value,
    } }),
    cizildi: (_, v) => { el('.sy-page-head .subtitle').textContent = ALT(v?.total ?? 0); },
  });

  kap.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (tr) location.href = `/sistemyonetim/ticket/${tr.dataset.id}`;
  });
  ['#fDurum', '#fOncelik', '#fAtanan'].forEach((id) => el(id).addEventListener('change', () => tablo.yenile()));
  el('#tCsv')?.addEventListener('click', () => syIndir('/talep/csv', null, `ticketlar-${tarihStr(new Date())}.csv`));
  tablo.yenile();
}

baslat();
