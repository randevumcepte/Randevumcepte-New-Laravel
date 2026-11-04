/**
 * v2 ORTAK GRAFİK — Chart.js 4 (window.Chart, sayfa <script> ile yükler) ince sarmalayıcı.
 * Marka paleti, TR sayı biçimi, ₺ ipucu ve "aynı tuvale yeniden çizince eskisini yok et"
 * tek yerde. Import yolu her yerde AYNI olmalı:
 *   import { cubuk, halka, cizgi, grafikleriTemizle } from '/js/lib/v2-grafik.js?v=1';
 * (v=2: yiginCubuk eklendi — kullanan ekran v=2 ile import eder; diğerleri için v=1 de çalışır.)
 */

export const RENK = {
  mor: '#5C008E', lila: '#9D5DC8', yesil: '#069471', gul: '#c83361', kehribar: '#c47214', camgobegi: '#0f7e96', gri: '#9aa3bd',
};
const PALET = [RENK.mor, RENK.yesil, RENK.gul, RENK.lila, RENK.kehribar, RENK.camgobegi, RENK.gri];

const tr = (n, b = 0) => (Number(n) || 0).toLocaleString('tr-TR', { minimumFractionDigits: b, maximumFractionDigits: b });
const ornekler = new Map();

function ciz(tuval, config) {
  if (!tuval || !window.Chart) return null;
  ornekler.get(tuval)?.destroy();
  const g = new window.Chart(tuval, config);
  ornekler.set(tuval, g);
  return g;
}

/** Sekme değişince eski grafikleri bırak (bellek / tuval sızıntısı olmasın). */
export function grafikleriTemizle() {
  ornekler.forEach((g) => g.destroy());
  ornekler.clear();
}

const ipucu = (para, ek) => ({
  callbacks: {
    label: (c) => {
      const v = c.parsed.y ?? c.parsed.x ?? c.parsed;
      return ` ${para ? tr(v, 2) + ' ₺' : tr(v) + (ek || '')}`;
    },
  },
});

/** Çubuk grafik. yatay=true: indexAxis 'y' (uzun ad listeleri). para=true: ₺ eksen/ipucu. */
export function cubuk(tuval, etiketler, degerler, { yatay = false, para = false, renk = RENK.mor, ek = '' } = {}) {
  const eksen = { ticks: { callback: (v) => (para ? tr(v) + ' ₺' : tr(v)) }, grid: { color: 'rgba(0,0,0,.05)' } };
  return ciz(tuval, {
    type: 'bar',
    data: { labels: etiketler, datasets: [{ data: degerler, backgroundColor: renk, borderRadius: 6, maxBarThickness: 34 }] },
    options: {
      indexAxis: yatay ? 'y' : 'x', responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: ipucu(para, ek) },
      scales: yatay ? { x: eksen, y: { grid: { display: false } } } : { y: eksen, x: { grid: { display: false } } },
    },
  });
}

/** Yığılmış çubuk — aynı eksende birden çok seri. seriler: [{ad, veri, renk}] */
export function yiginCubuk(tuval, etiketler, seriler) {
  return ciz(tuval, {
    type: 'bar',
    data: { labels: etiketler, datasets: seriler.map((s) => ({ label: s.ad, data: s.veri, backgroundColor: s.renk, maxBarThickness: 34 })) },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'top' } },
      scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(0,0,0,.05)' } } },
    },
  });
}

/** Halka (doughnut) — dağılım; tüm değerler 0 ise çizmez (null döner). */
export function halka(tuval, etiketler, degerler, { para = false, renkler = PALET, lejant = true, kesit = '68%', ipucuAcik = true } = {}) {
  if (!degerler.some((v) => Number(v) > 0)) return null;
  return ciz(tuval, {
    type: 'doughnut',
    data: { labels: etiketler, datasets: [{ data: degerler, backgroundColor: renkler, borderWidth: 0 }] },
    options: { cutout: kesit, responsive: true, maintainAspectRatio: false, plugins: { legend: { display: lejant, position: 'bottom' }, tooltip: { enabled: ipucuAcik, ...ipucu(para) } } },
  });
}

/** Çizgi (alan dolgulu) — zaman dağılımı. */
export function cizgi(tuval, etiketler, degerler, { renk = RENK.lila, ek = '' } = {}) {
  return ciz(tuval, {
    type: 'line',
    data: { labels: etiketler, datasets: [{ data: degerler, borderColor: renk, backgroundColor: renk + '2e', fill: true, tension: 0.4, pointRadius: 3 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: ipucu(false, ek) }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } } },
  });
}
