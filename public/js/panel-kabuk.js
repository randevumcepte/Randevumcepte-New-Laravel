/**
 * Panel kabuğu (shell) — sol menü + header'ı /api/v2/kabuk ile doldurur.
 *
 * Tüm v2 ekranları bu betiği paylaşır (tek kaynak). Veri Bearer token ile gelir
 * (localStorage['rc_access_token']; pilotta ?token=). Menü/şube linkleri eski
 * panele (app.*) gider → PARALEL kullanım.
 *
 * Dışa açılan kanca: window.rcPanel.salonAyarla(salonId) — sayfa (örn. tahsilat)
 * adisyondan salon'u öğrenince kabuğu o salona göre tazeler.
 */

const BASE = '/api/v2';
const _qp = new URLSearchParams(location.search);
if (_qp.get('token')) localStorage.setItem('rc_access_token', _qp.get('token'));
const token = () => localStorage.getItem('rc_access_token') || '';

const el = (s) => document.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

async function kabukGetir(salonId) {
  const yol = BASE + '/kabuk' + (salonId ? '?salon_id=' + salonId : '');
  const r = await fetch(yol, {
    cache: 'no-store',
    headers: { Accept: 'application/json', Authorization: 'Bearer ' + token() },
  });
  const t = await r.text();
  return { ok: r.ok, kod: r.status, govde: t ? JSON.parse(t) : null };
}

function menuCiz(menu, aktifSlug) {
  const kutu = el('#rcMenu');
  if (!menu || !menu.length) { kutu.innerHTML = '<div class="rc-menu-iskele">Menü öğesi yok</div>'; return; }
  let html = '';
  let sonBolum = null;
  for (const m of menu) {
    if (m.bolum && m.bolum !== sonBolum) {
      sonBolum = m.bolum;
      html += `<div class="rc-bolum">${esc(m.bolum)}</div>`;
    }
    const aktif = aktifSlug && String(m.slug) === String(aktifSlug) ? ' aktif' : '';
    html += `<a class="rc-link${aktif}" href="${esc(m.url)}" title="${esc(m.etiket)}">
      <span class="rc-ikon ${esc(m.ikon)}"></span>
      <span class="rc-menu-etiket">${esc(m.etiket)}</span></a>`;
  }
  kutu.innerHTML = html;
}

function headerCiz(veri) {
  // Kullanıcı
  el('#rcKullaniciAd').textContent = veri.kullanici.ad || 'Kullanıcı';
  const av = el('#rcAvatar');
  if (veri.kullanici.avatar) {
    av.style.backgroundImage = `url("${veri.kullanici.avatar}")`;
    av.textContent = '';
  } else {
    av.textContent = (veri.kullanici.ad || 'R').trim().charAt(0).toUpperCase();
  }

  // Lisans + salon adı
  if (veri.salon && veri.salon.ad) {
    el('#rcSalonAd').textContent = veri.salon.ad;
    const g = veri.salon.lisans_gun;
    el('#rcLisansGun').textContent = (g === null || g === undefined) ? '' : g + ' gün kaldı';
    el('#rcLisans').style.display = 'flex';
  }

  // Ayarlar kısayolu (menüdeki Ayarlar url'si)
  const ayar = (veri.menu || []).find((m) => m.slug === 'ayarlar');
  if (ayar) el('#rcAyarBtn').setAttribute('href', ayar.url);

  // Şube seçici
  subeCiz(veri.subeler || []);
}

function subeCiz(subeler) {
  const kutu = el('#rcSubeSec');
  if (!subeler.length) { kutu.innerHTML = ''; return; }
  if (subeler.length === 1) {
    // Tek şube → seçici yerine sabit etiket göstermeye gerek yok (lisans rozetinde var).
    kutu.innerHTML = '';
    return;
  }
  const opsiyonlar = subeler
    .map((s) => `<option value="${esc(s.url)}"${s.aktif ? ' selected' : ''}>${esc(s.ad)}</option>`)
    .join('');
  kutu.innerHTML = `<select id="rcSubeSelect" aria-label="İşletme / Şube">${opsiyonlar}</select>`;
  el('#rcSubeSelect').addEventListener('change', (e) => { if (e.target.value) location.href = e.target.value; });
}

async function yukle(salonId) {
  const { ok, govde } = await kabukGetir(salonId);
  if (!ok || !govde || !govde.veri) {
    el('#rcMenu').innerHTML = '<div class="rc-menu-iskele">Menü yüklenemedi</div>';
    return;
  }
  const veri = govde.veri;
  const aktifSlug = document.body.dataset.aktif || '';
  menuCiz(veri.menu, aktifSlug);
  headerCiz(veri);
}

/* ───────────── Sidebar aç/kapa (masaüstü daralt + mobil çekmece) ───────────── */
function etkilesimBagla() {
  const tetik = el('#rcMenuTetik');
  const mobil = () => window.matchMedia('(max-width: 860px)').matches;
  tetik.addEventListener('click', () => {
    if (mobil()) {
      document.body.classList.toggle('rc-ac-menu');
      el('#rcOverlay').classList.toggle('acik', document.body.classList.contains('rc-ac-menu'));
    } else {
      const dar = document.body.classList.toggle('rc-daralt');
      localStorage.setItem('rc_menu_dar', dar ? '1' : '0');
    }
  });
  el('#rcOverlay').addEventListener('click', () => {
    document.body.classList.remove('rc-ac-menu');
    el('#rcOverlay').classList.remove('acik');
  });
  if (localStorage.getItem('rc_menu_dar') === '1') document.body.classList.add('rc-daralt');
}

// Dışa açılan kanca: sayfa salon'u sonradan öğrenirse kabuğu tazele.
let _aktifSalon = null;
window.rcPanel = {
  salonAyarla(salonId) {
    salonId = parseInt(salonId, 10) || null;
    if (!salonId || salonId === _aktifSalon) return;
    _aktifSalon = salonId;
    yukle(salonId);
  },
};

etkilesimBagla();
yukle(null); // önce varsayılan salon; sayfa isterse salonAyarla ile tazeler
