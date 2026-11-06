/** Duyuru listesi. */
import { el, esc, onay } from '/js/lib/v2-ortak.js?v=2';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=2';
import { ekran, syApi, syVeri, syYaz, tarih } from '/js/lib/v2-sistem.js?v=1';
import { aktifRozet, etiketRozet, sayfaBaslik } from '/js/sistem/ortak.js?v=1';

const KOK = '/sistemyonetim/duyuru';

async function baslat() {
  const s = await syVeri('/duyuru/secenekler');
  if (!s) return;

  ekran().innerHTML = `
    ${sayfaBaslik('Duyurular', 'Salonlara bilgi, uyarı, kampanya, bakım duyurusu gönderin',
    s.yazabilir ? `<a href="${KOK}/yeni" class="sy-btn sy-btn-primary"><span class="mdi mdi-plus"></span> Yeni Duyuru</a>` : '')}
    <div class="sy-card"><div class="sy-card-body" id="dTablo"></div></div>`;

  const kap = el('#dTablo');
  const tablo = veriTablosu(kap, {
    anahtar: 'sy_duyuru',
    varsayilanBoy: 25,
    birim: 'duyuru',
    araIpucu: 'Başlık, içerik, oluşturan...',
    bosMesaj: 'Duyuru yok',
    sirala: { alan: 'tarih', yon: 'desc' },
    kolonlar: [
      { baslik: 'Başlık', sirala: 'baslik' }, { baslik: 'Tip', sirala: 'tip' }, { baslik: 'Hedef', sirala: 'hedef' },
      { baslik: 'Geçerlilik', sirala: 'baslangic' }, { baslik: 'Durum', sirala: 'durum' }, { baslik: 'Oluşturan', sirala: 'olusturan' },
      ...(s.yazabilir ? [{ baslik: 'İşlem', sinif: 'rc-sag' }] : []),
    ],
    satir: (d) => `<tr>
      <td>
        <a href="${KOK}/${d.id}" class="sy-fw-600">${esc(d.baslik)}</a>
        ${d.sticky ? '<span class="sy-badge sy-badge-warning"><span class="mdi mdi-pin"></span></span>' : ''}
        <div class="sy-text-muted sy-fs-12">${esc(d.ozet)}</div>
      </td>
      <td>${etiketRozet(d.tip)}</td>
      <td class="sy-fs-13">${esc(d.hedef.etiket)}</td>
      <td class="sy-fs-12 rc-nowrap">${esc(tarih(d.baslangic_tarihi))}<br>${d.bitis_tarihi ? esc(tarih(d.bitis_tarihi)) : 'süresiz'}</td>
      <td>${aktifRozet(d.aktif)}</td>
      <td class="sy-fs-13">${esc(d.olusturan_user_name || '—')}</td>
      ${s.yazabilir ? `<td class="rc-sag">
        <a href="${KOK}/${d.id}/duzenle" class="sy-btn sy-btn-sm sy-btn-soft" title="Düzenle"><span class="mdi mdi-pencil"></span></a>
        <button type="button" class="sy-btn sy-btn-sm sy-btn-danger" data-sil="${d.id}" title="Sil"><span class="mdi mdi-delete"></span></button>
      </td>` : ''}
    </tr>`,
    kaynak: (p) => syApi('GET', '/duyuru', { params: p }),
  });

  kap.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-sil]');
    if (!b || !await onay('Bu duyuru silinsin mi?', { baslik: 'Duyuruyu Sil', tamam: 'Sil' })) return;
    const { tamam } = await syYaz('DELETE', `/duyuru/${b.dataset.sil}`);
    if (tamam) tablo.yenile(false);
  });
  tablo.yenile();
}

baslat();
