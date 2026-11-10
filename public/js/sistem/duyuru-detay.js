/** Duyuru detayi: icerik, hedef/okunma ozeti, okuyanlar. */
import { el, esc } from '/js/lib/v2-ortak.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=8';
import { ekran, kayitId, syApi, syVeri, tarihSaat } from '/js/lib/v2-sistem.js?v=7';
import { aktifRozet, geriLink, metinHtml, etiketRozet, sayfaBaslik } from '/js/sistem/ortak.js?v=1';

const KOK = '/sistemyonetim/duyuru';
const id = kayitId();

/** Eski kayitlarda dogrulanmamis link olabilir: yalniz panel yolu ve http(s) tiklanir. */
const guvenliLink = (l) => /^(\/(?!\/)|https?:\/\/)/i.test(l || '');

async function baslat() {
  const [v, s] = await Promise.all([syVeri(`/duyuru/${id}`), syVeri('/duyuru/secenekler')]);
  if (!v || !s) return;
  const d = v.duyuru;

  ekran().innerHTML = `
    ${sayfaBaslik(d.baslik, `${etiketRozet(d.tip)} ${etiketRozet(d.hedef)} · ${esc(d.olusturan_user_name || '—')} tarafından ${esc(tarihSaat(d.olusturma))}`, `
      ${geriLink(KOK, 'Liste')}
      ${s.yazabilir ? `<a href="${KOK}/${id}/duzenle" class="sy-btn sy-btn-primary"><span class="mdi mdi-pencil"></span> Düzenle</a>` : ''}`)}
    <div class="sy-grid-2-1">
      <div class="sy-card"><div class="sy-card-head"><h3>İçerik</h3></div><div class="sy-card-body">
        <div style="font-size:14px;line-height:1.7">${metinHtml(d.icerik)}</div>
        ${d.cta_metin && guvenliLink(d.cta_link) ? `<div class="sy-mt-18"><a href="${esc(d.cta_link)}" class="sy-btn sy-btn-primary" target="_blank" rel="noopener">${esc(d.cta_metin)}</a></div>` : ''}
      </div></div>
      <div class="sy-stack">
        <div class="sy-metric-grid" style="grid-template-columns:1fr;gap:12px">
          <div class="sy-metric"><div class="icon-bg mdi mdi-target"></div><div class="label">Hedef Salon</div><div class="value">${v.hedef_sayisi}</div></div>
          <div class="sy-metric success"><div class="icon-bg mdi mdi-eye-check"></div><div class="label">Okundu</div><div class="value">${v.okundu_sayisi}</div>
            <div class="delta">${v.okundu_orani !== null ? `%${v.okundu_orani}` : ''}</div></div>
        </div>
        <div class="sy-card"><div class="sy-card-head"><h3>Geçerlilik</h3></div><div class="sy-card-body"><div class="sy-fs-13">
          <strong>Başlangıç:</strong> ${esc(tarihSaat(d.baslangic_tarihi))}<br>
          <strong>Bitiş:</strong> ${d.bitis_tarihi ? esc(tarihSaat(d.bitis_tarihi)) : 'süresiz'}<br>
          <strong>Durum:</strong> ${aktifRozet(d.aktif)}
          ${d.sticky ? '<span class="sy-badge sy-badge-warning"><span class="mdi mdi-pin"></span> Sabit</span>' : ''}
        </div></div></div>
      </div>
    </div>
    <div class="sy-card sy-mt-24">
      <div class="sy-card-head"><h3>Okuyanlar (${v.okundu_sayisi})</h3></div>
      <div class="sy-card-body" id="dOkuyanlar"></div>
    </div>`;

  veriTablosu(el('#dOkuyanlar'), {
    anahtar: 'sy_duyuru_okuyan',
    varsayilanBoy: 25,
    birim: 'okuyan',
    araIpucu: 'Salon, yetkili...',
    bosMesaj: 'Henüz okuyan yok',
    sirala: { alan: 'tarih', yon: 'desc' },
    kolonlar: [{ baslik: 'Salon', sirala: 'salon' }, { baslik: 'Yetkili', sirala: 'yetkili' }, { baslik: 'Okundu', sirala: 'tarih' }],
    satir: (o) => `<tr>
      <td>${esc(o.salon_adi || '—')}</td>
      <td>${esc(o.yetkili_adi || '—')}</td>
      <td class="sy-text-muted sy-fs-13">${esc(tarihSaat(o.okundu_tarihi))}</td>
    </tr>`,
    kaynak: (p) => syApi('GET', `/duyuru/${id}/okuyanlar`, { params: p }),
  }).yenile();
}

baslat();
