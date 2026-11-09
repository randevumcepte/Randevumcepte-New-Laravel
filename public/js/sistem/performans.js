/** Ekip performansi ve SLA ozeti. Veri /sistem/performans; gun secenekleri API'den. */
import { el, esc } from '/js/lib/v2-ortak.js?v=7';
import { ekran } from '/js/lib/v2-sistem.js?v=7';
import { avatar, metrik, rozet, sayfaBaslik, syTablo } from '/js/sistem/ortak.js?v=1';

const sure = (deger, birim) => (deger !== null && deger !== undefined ? `${Math.round(deger)} ${birim}` : '—');

const satir = (p) => `<tr>
  <td><div class="sy-flex-row">${avatar(p.ad)}<strong>${esc(p.ad)}</strong></div></td>
  <td>${rozet('muted', p.rol_etiket)}</td>
  <td class="sy-text-right"><strong>${p.toplam}</strong></td>
  <td class="sy-text-right"><strong style="color:var(--sy-success)">${p.cozulen}</strong>${p.toplam > 0 ? `<div class="sy-text-muted sy-fs-12">%${Math.round(p.cozulen / p.toplam * 100)}</div>` : ''}</td>
  <td class="sy-text-right">${p.acik > 0 ? p.acik : '—'}</td>
  <td class="sy-text-right sy-fs-13">${sure(p.ort_yanit_dk, 'dk')}</td>
  <td class="sy-text-right sy-fs-13">${sure(p.ort_cozum_saat, 'sa')}</td>
  <td class="sy-text-right">${p.mesaj_sayisi}</td>
  <td class="sy-text-right">${p.imp_sayisi}</td>
  <td class="sy-text-right">${p.aktivite_sayisi}</td>
</tr>`;

function ozetCiz(v) {
  const g = v.genel;
  el('.sy-page-head .subtitle', ekran()).textContent = `Son ${v.gun} günde ekibin yükü ve SLA göstergeleri`;
  const gunSec = el('#perfGun');
  if (!gunSec.options.length) gunSec.innerHTML = v.gunler.map((d) => `<option value="${d}"${d === v.gun ? ' selected' : ''}>Son ${d} gün</option>`).join('');
  el('#perfMetrik').innerHTML = [
    { ikon: 'mdi-lifebuoy', etiket: 'Toplam Talep', deger: g.toplam },
    { renk: 'success', ikon: 'mdi-check-all', etiket: 'Çözülen', deger: g.cozulen, alt: `%${g.cozum_orani} çözüm oranı` },
    { renk: 'info', ikon: 'mdi-clock-fast', etiket: 'Ort. İlk Yanıt', deger: sure(g.ort_yanit_dk, 'dk') },
    { renk: 'warning', ikon: 'mdi-progress-clock', etiket: 'Ort. Çözüm', deger: sure(g.ort_cozum_saat, 'sa') },
  ].map(metrik).join('');
}

ekran().innerHTML = `
  ${sayfaBaslik('Ekip Performansı', '', '<select id="perfGun" class="sy-select"></select>')}
  <div class="sy-metric-grid" id="perfMetrik"></div>
  <div class="sy-card">
    <div class="sy-card-head"><h3>Personel Bazlı</h3></div>
    <div class="sy-card-body tight" id="perfTablo"></div>
  </div>
  <div class="sy-card sy-mt-18">
    <div class="sy-card-body"><div class="sy-fs-12 sy-text-muted">
      <strong>İlk Yanıt:</strong> ticket açılışından ekipten ilk mesaja geçen süre. <strong>Çözüm:</strong> ticket açılışından durum 'çözümlendi'ye geçişe kadar. <strong>Salon Girişi:</strong> impersonation sayısı. <strong>Aktivite:</strong> audit log kaydı sayısı.
    </div></div>
  </div>`;

const tablo = syTablo(el('#perfTablo'), {
  yol: '/performans',
  params: () => ({ gun: el('#perfGun').value }),
  anahtar: 'sy_performans',
  varsayilanBoy: 25,
  birim: 'üye',
  araIpucu: 'Üye, rol…',
  sirala: { alan: 'ad', yon: 'asc' },
  kolonlar: [
    { baslik: 'Üye', sirala: 'ad' }, { baslik: 'Rol', sirala: 'rol' },
    ...[['Atanan', 'toplam'], ['Çözülen', 'cozulen'], ['Açık', 'acik'], ['İlk Yanıt', 'ilk_yanit'], ['Çözüm', 'cozum'], ['Mesaj', 'mesaj'], ['Salon Girişi', 'salon_girisi'], ['Aktivite', 'aktivite']]
      .map(([baslik, alan]) => ({ baslik, sirala: alan, sinif: 'sy-text-right' })),
  ],
  satir,
  cizildi: (_, v) => { if (v) ozetCiz(v); },
});
el('#perfGun').addEventListener('change', () => tablo.yenile());
