/** Genel Bakis: metrikler, son hareketler, bekleyen talepler, trend grafikleri. Veri /sistem/dashboard ve /sistem/dashboard/grafik. */
import { el, esc } from '/js/lib/v2-ortak.js?v=7';
import { halka, grafikleriTemizle } from '/js/lib/v2-grafik.js?v=7';
import { ekran, syVeri } from '/js/lib/v2-sistem.js?v=7';
import { bos, kisalt, metrik, rozet, sayfaBaslik, sayi } from '/js/sistem/ortak.js?v=1';

const CHART_ADRESI = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
const KATEGORI_RENKLERI = ['#6d3aaa', '#8a5cc7', '#d9b3f5', '#5C008E', '#a872e8', '#7B2FB8', '#9D5DC8', '#bf91e6', '#5dd4d8', '#f3a8c8', '#d99a1f', '#d04d5e'];
const DURUM_RENKLERI = { acik: '#d04d5e', islemde: '#d99a1f', bekliyor: '#4a8bdc', cozumlendi: '#2cae71', kapali: '#777589' };

let trendGrafik = null;

const chartYukle = () => new Promise((tamam, hata) => {
  if (window.Chart) { tamam(); return; }
  const betik = document.createElement('script');
  betik.src = CHART_ADRESI;
  betik.onload = tamam;
  betik.onerror = hata;
  document.head.appendChild(betik);
});

const metrikler = (m) => `<div class="sy-metric-grid">${[
  { ikon: 'mdi-store', etiket: 'Toplam Salon', deger: sayi(m.toplam_salon), alt: `${m.aktif_salon} aktif · ${m.askida_salon} askıda` },
  { renk: 'success', ikon: 'mdi-account-plus', etiket: 'Bu Hafta Yeni Salon', deger: m.hafta_yeni_salon, alt: `Bugün: ${m.bugun_yeni_salon}` },
  { renk: 'info', ikon: 'mdi-calendar-check', etiket: 'Bugün Randevu', deger: sayi(m.bugun_randevu), alt: `Toplam: ${sayi(m.toplam_randevu)}` },
  { renk: 'warning', ikon: 'mdi-lifebuoy', etiket: 'Açık Talep', deger: m.acik_ticket, alt: `Acil: ${m.acil_ticket}` },
  { ikon: 'mdi-account-group', etiket: 'Aktif Ekip', deger: m.aktif_ekip, alt: `Yetkili: ${m.toplam_yetkili}` },
  { ikon: 'mdi-account-tie', etiket: 'Toplam Personel', deger: sayi(m.toplam_personel) },
].map(metrik).join('')}</div>`;

const aktiviteSatiri = (a) => `
  <div class="sy-timeline-item">
    <div class="meta"><strong>${esc(a.kullanici)}</strong>${a.rol ? ` <span class="sy-badge sy-badge-muted" style="margin-left:6px">${esc(a.rol)}</span>` : ''} · ${esc(a.zaman_once)}</div>
    <div class="body">
      <strong>${esc(a.islem)}</strong>${a.hedef_tipi ? ` → <span class="sy-text-muted">${esc(a.hedef_tipi)}</span>` : ''}${a.hedef_etiket ? `: ${esc(a.hedef_etiket)}` : ''}
      ${a.aciklama ? `<div class="sy-text-muted sy-fs-12">${esc(a.aciklama)}</div>` : ''}
    </div>
  </div>`;

const talepSatiri = (t) => `
  <a href="/sistemyonetim/ticket/${t.id}" style="display:block;padding:12px 18px;border-bottom:1px solid var(--sy-border);color:var(--sy-text)">
    <div class="sy-flex-row" style="justify-content:space-between"><strong>${esc(t.numara)}</strong>${rozet(t.oncelik_renk, t.oncelik)}</div>
    <div class="sy-fs-13" style="margin-top:3px">${esc(kisalt(t.konu, 60))}</div>
    <div class="sy-text-muted sy-fs-12" style="margin-top:2px">${esc(t.salon_adi || '—')} · ${esc(t.zaman_once)}</div>
  </a>`;

const girisSatiri = (g) => `
  <div style="padding:10px 18px;border-bottom:1px solid var(--sy-border);display:flex;justify-content:space-between;align-items:center">
    <div>
      <div class="sy-fs-13"><strong>${esc(kisalt(g.email, 28))}</strong></div>
      <div class="sy-text-muted sy-fs-12">${esc(g.ip)} · ${esc(g.zaman_once)}</div>
    </div>
    ${g.basarili ? rozet('success', 'OK') : rozet('danger', 'Hata')}
  </div>`;

function trendCiz(d) {
  trendGrafik?.destroy();
  const seri = (label, data, renk, ek = {}) => ({ label, data, borderColor: renk, backgroundColor: `${renk}1a`, tension: 0.35, fill: true, ...ek });
  trendGrafik = new window.Chart(el('#chartTrend'), {
    type: 'line',
    data: {
      labels: d.tarihler,
      datasets: [seri('Yeni Salon', d.salon, '#6d3aaa'), seri('Randevu', d.randevu, '#5dd4d8', { yAxisID: 'y1' }), seri('Talep', d.ticket, '#d99a1f')],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
      scales: {
        y: { beginAtZero: true, position: 'left' },
        y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false } },
      },
    },
  });
}

async function grafikleriYukle(gun) {
  const d = await syVeri('/dashboard/grafik', { gun });
  if (!d || !window.Chart) return;
  trendCiz(d);
  grafikleriTemizle();
  const kategoriler = Object.keys(d.kategori_dagilim);
  const durumlar = Object.keys(d.durum_dagilim);
  halka(el('#chartKategori'), kategoriler, Object.values(d.kategori_dagilim), { renkler: KATEGORI_RENKLERI });
  halka(el('#chartDurum'), durumlar, Object.values(d.durum_dagilim), { renkler: durumlar.map((k) => DURUM_RENKLERI[k] || '#777589') });
}

async function baslat() {
  const v = await syVeri('/dashboard');
  if (!v) return;
  ekran().innerHTML = `
    ${sayfaBaslik('Genel Bakış', 'Sistemin nabzı, son aktiviteler ve bekleyen işler.', `
      <a href="/sistemyonetim/salonlar" class="sy-btn sy-btn-soft"><span class="mdi mdi-store"></span> Salonlar</a>
      <a href="/sistemyonetim/ticket" class="sy-btn sy-btn-primary"><span class="mdi mdi-lifebuoy"></span> Destek</a>`)}
    ${metrikler(v.metrikler)}
    <div class="sy-grid-2-1">
      <div class="sy-card">
        <div class="sy-card-head"><h3>Son Aktiviteler</h3><a href="/sistemyonetim/aktivite-log" class="sy-btn sy-btn-sm sy-btn-soft">Tümü</a></div>
        <div class="sy-card-body">${v.son_aktiviteler.length ? `<div class="sy-timeline">${v.son_aktiviteler.map(aktiviteSatiri).join('')}</div>` : bos('mdi-timer-sand-empty', 'Henüz aktivite yok')}</div>
      </div>
      <div class="sy-stack">
        <div class="sy-card">
          <div class="sy-card-head"><h3>Bekleyen Talepler</h3><a href="/sistemyonetim/ticket" class="sy-btn sy-btn-sm sy-btn-soft">Tümü</a></div>
          <div class="sy-card-body tight">${v.bekleyen_talepler.length ? v.bekleyen_talepler.map(talepSatiri).join('') : bos('mdi-check-all', 'Tertemiz!', 'Bekleyen talep yok.')}</div>
        </div>
        <div class="sy-card">
          <div class="sy-card-head"><h3>Son Girişler</h3></div>
          <div class="sy-card-body tight">${v.son_girisler.length ? v.son_girisler.map(girisSatiri).join('') : bos('', 'Kayıt yok')}</div>
        </div>
      </div>
    </div>
    <div class="sy-grid-2 sy-mt-24">
      <div class="sy-card">
        <div class="sy-card-head"><h3>Trend</h3>
          <select id="chartGun" class="sy-select" style="width:auto;height:32px;padding:5px 10px;font-size:12px">
            ${v.grafik_gunleri.map((g) => `<option value="${g}"${g === v.grafik_varsayilan ? ' selected' : ''}>${g} gün</option>`).join('')}
          </select>
        </div>
        <div class="sy-card-body"><div class="sy-db-trend"><canvas id="chartTrend"></canvas></div></div>
      </div>
      <div class="sy-card">
        <div class="sy-card-head"><h3>Talep Dağılımı</h3></div>
        <div class="sy-card-body">
          <div class="sy-grid-2">
            <div><div class="sy-text-muted sy-fs-12 sy-text-center" style="margin-bottom:6px">Kategori</div><div class="sy-db-halka"><canvas id="chartKategori"></canvas></div></div>
            <div><div class="sy-text-muted sy-fs-12 sy-text-center" style="margin-bottom:6px">Durum</div><div class="sy-db-halka"><canvas id="chartDurum"></canvas></div></div>
          </div>
        </div>
      </div>
    </div>`;

  try { await chartYukle(); } catch (_) { return; }
  el('#chartGun').addEventListener('change', (e) => grafikleriYukle(e.target.value));
  grafikleriYukle(v.grafik_varsayilan);
}

baslat();
