/** Risk altindaki salonlar. Skor ve kapsam sunucuda; destek rolu yalniz kendi salonlarini alir. */
import { el, esc } from '/js/lib/v2-ortak.js?v=2';
import { ekran } from '/js/lib/v2-sistem.js?v=1';
import { rozet, sayfaBaslik, syTablo } from '/js/sistem/ortak.js?v=1';

ekran().innerHTML = `
  ${sayfaBaslik('Risk Altındaki Salonlar', "Skor 50'nin altındaki salonlar (otomatik tespit)")}
  <div class="sy-card sy-mt-12">
    <div class="sy-card-body">
      <div class="sy-fs-13 sy-text-muted">
        <strong>Skor faktörleri:</strong> son giriş zamanı, son randevu zamanı, 30 günlük randevu hacmi, açık talep sayısı, son 30 gün şikayet, askıda durumu.
        <strong>Durum:</strong> ${rozet('success', 'iyi')} ≥75 · ${rozet('info', 'orta')} 50-74 · ${rozet('warning', 'riskli')} 25-49 · ${rozet('danger', 'kritik')} &lt;25
      </div>
    </div>
  </div>
  <div class="sy-card sy-mt-18">
    <div class="sy-card-head"><h3 id="riskBaslik">Risk altındaki salonlar</h3></div>
    <div class="sy-card-body tight" id="riskTablo"></div>
  </div>`;

const satir = (r) => `<tr>
  <td><div class="sy-fw-600">${esc(r.salon_adi)}</div><div class="sy-text-muted sy-fs-12">ID: ${r.salon_id}</div></td>
  <td>
    <div style="display:flex;align-items:center;gap:8px">
      <strong style="font-size:18px;color:var(--sy-${esc(r.durum_renk)})">${r.skor}</strong>
      <div class="sy-progress" style="flex:1;width:60px"><div class="fill" style="width:${r.skor}%;background:var(--sy-${esc(r.durum_renk)})"></div></div>
    </div>
  </td>
  <td>${rozet(r.durum_renk, r.durum)}</td>
  <td class="sy-fs-13"><ul style="margin:0;padding-left:16px">${r.sebepler.map((s) => `<li>${esc(s)}</li>`).join('')}</ul></td>
  <td class="sy-fs-13">${esc(r.mt_ad || '—')}</td>
  <td class="sy-text-right nowrap">
    <a href="/sistemyonetim/salon/${r.salon_id}" class="sy-btn sy-btn-sm sy-btn-soft"><span class="mdi mdi-information-outline"></span> Detay</a>
    <a href="/sistemyonetim/ticket/yeni?salon_id=${r.salon_id}" class="sy-btn sy-btn-sm sy-btn-primary"><span class="mdi mdi-phone"></span> İletişim Aç</a>
  </td>
</tr>`;

syTablo(el('#riskTablo'), {
  yol: '/risk',
  anahtar: 'sy_risk',
  birim: 'salon',
  araIpucu: 'Salon, temsilci, sebep…',
  bosMesaj: 'Hepsi sağlıklı! Risk altında salon yok.',
  sirala: { alan: 'skor', yon: 'asc' },
  kolonlar: [
    { baslik: 'Salon', sirala: 'salon' }, { baslik: 'Skor', sirala: 'skor' }, { baslik: 'Durum', sirala: 'durum' }, { baslik: 'Sebepler' },
    { baslik: 'MT', sirala: 'mt' }, { baslik: 'Aksiyon', sinif: 'sy-text-right' },
  ],
  satir,
  cizildi: (_, v) => { el('#riskBaslik').textContent = `${v?.total ?? 0} salon risk altında`; },
});
