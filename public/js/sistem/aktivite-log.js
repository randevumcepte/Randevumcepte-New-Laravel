/** Aktivite Logu: islem, kullanici ve tarih filtreli denetim kayitlari + CSV. Secenekler /sistem/aktivite/secenekler. */
import { el, esc } from '/js/lib/v2-ortak.js?v=7';
import { ekran, syIndir, syVeri } from '/js/lib/v2-sistem.js?v=7';
import { sayfaBaslik, syTablo } from '/js/sistem/ortak.js?v=1';

const satir = (l) => `<tr>
  <td class="nowrap sy-fs-12"><div>${esc(l.zaman)}</div><div class="sy-text-muted">${esc(l.zaman_once)}</div></td>
  <td><div class="sy-fw-600">${esc(l.kullanici)}</div><div class="sy-text-muted sy-fs-12">${esc(l.rol || '')}</div></td>
  <td><span class="sy-badge">${esc(l.islem)}</span></td>
  <td>${l.hedef_tipi ? `<span class="sy-text-muted sy-fs-12">${esc(l.hedef_tipi)}#${esc(l.hedef_id ?? '')}</span><br>` : ''}<strong>${esc(l.hedef_etiket || '—')}</strong></td>
  <td class="sy-fs-13">${esc(l.aciklama || '—')}${l.meta ? `
    <details style="margin-top:4px">
      <summary class="sy-text-muted sy-fs-12" style="cursor:pointer">Detay</summary>
      <pre style="background:#f7f4ff;border:1px solid #e3d6f7;border-radius:6px;padding:8px;font-size:11.5px;margin-top:4px;max-width:380px;overflow:auto">${esc(JSON.stringify(l.meta, null, 2))}</pre>
    </details>` : ''}</td>
  <td class="sy-text-muted sy-fs-12 nowrap">${esc(l.ip || '')}</td>
</tr>`;

async function baslat() {
  const s = await syVeri('/aktivite/secenekler');
  if (!s) return;
  ekran().innerHTML = `
    ${sayfaBaslik('Aktivite Logu', 'Tüm sistem yöneticilerinin yaptığı işlemler — kim, ne, ne zaman', '<button class="sy-btn" type="button" id="akCsv"><span class="mdi mdi-file-download"></span> CSV İndir</button>')}
    <div class="sy-filters">
      <div class="sy-form-group" style="max-width:200px"><label>İşlem</label>
        <select id="akIslem" class="sy-select"><option value="">Hepsi</option>${s.aksiyonlar.map((a) => `<option value="${esc(a)}">${esc(a)}</option>`).join('')}</select></div>
      <div class="sy-form-group" style="max-width:200px"><label>Kullanıcı</label>
        <select id="akKullanici" class="sy-select"><option value="">Hepsi</option>${s.kullanicilar.map((k) => `<option value="${k.id}">${esc(k.ad)}</option>`).join('')}</select></div>
      <div class="sy-form-group" style="max-width:160px"><label>Tarih</label><input id="akTarih" class="sy-input" autocomplete="off"></div>
      <button class="sy-btn" type="button" id="akSifirla">Sıfırla</button>
    </div>
    <div class="sy-card"><div class="sy-card-body tight" id="akTablo"></div></div>`;

  const filtre = { tarih: '' };
  const tablo = syTablo(el('#akTablo'), {
    yol: '/aktivite',
    params: () => ({ action: el('#akIslem').value, user_id: el('#akKullanici').value, tarih: filtre.tarih }),
    anahtar: 'sy_aktivite',
    araIpucu: 'Hedef, açıklama, kullanıcı…',
    sirala: { alan: 'zaman', yon: 'desc' },
    kolonlar: [
      { baslik: 'Zaman', sirala: 'zaman' }, { baslik: 'Kullanıcı', sirala: 'kullanici' }, { baslik: 'İşlem', sirala: 'islem' },
      { baslik: 'Hedef', sirala: 'hedef' }, { baslik: 'Açıklama' }, { baslik: 'IP', sirala: 'ip' },
    ],
    satir,
  });

  const takvim = window.flatpickr?.(el('#akTarih'), {
    dateFormat: 'Y-m-d', altInput: true, altFormat: 'd.m.Y', locale: 'tr', allowInput: false, disableMobile: true,
    onChange: (_, deger) => { filtre.tarih = deger; tablo.yenile(); },
  });
  el('#akIslem').addEventListener('change', () => tablo.yenile());
  el('#akKullanici').addEventListener('change', () => tablo.yenile());
  el('#akSifirla').addEventListener('click', () => {
    el('#akIslem').value = '';
    el('#akKullanici').value = '';
    filtre.tarih = '';
    takvim?.clear(false);
    tablo.yenile();
  });
  el('#akCsv').addEventListener('click', () => syIndir('/aktivite/csv', null, 'aktivite-log.csv'));
}

baslat();
