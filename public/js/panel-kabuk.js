/**
 * Panel kabuğu (shell) — sol menü + header'ı /api/v2/kabuk ile doldurur.
 *
 * Tüm v2 ekranları bu betiği paylaşır (tek kaynak). Veri Bearer token ile gelir
 * (localStorage['rc_access_token']; pilotta ?token=). Menü linkleri YALNIZCA
 * aktif v2 sayfalarına gider; v2 karşılığı olmayan öğe pasif ("Yakında") çizilir.
 * Şube değişimi aynı v2 sayfasında ?sube= ile yapılır. Eski panele yönlendirme YOK.
 *
 * Dışa açılan kanca: window.rcPanel.veri + 'rc:kabuk' olayı (kabuk verisi yüklendi).
 * Dışa açılan kanca: window.rcPanel.salonAyarla(salonId) — sayfa (örn. tahsilat)
 * adisyondan salon'u öğrenince kabuğu o salona göre tazeler.
 */

// Token tohumlama (?token= ve eski panel koprusunun #token= fragment'i) ortak modulde
// tek yerde yapilir; modul tekil oldugu icin hangi ekran once yuklenirse yuklensin
// tum istekler tokeni bulur.
import { api, el, esc } from '/js/lib/v2-ortak.js?v=1';

const kabukGetir = (salonId) => api('GET', '/kabuk', { params: { salon_id: salonId } });

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
    if (!m.url) {
      // v2 sayfası henüz yok → tıklanamaz, eski panele gitmez.
      html += `<span class="rc-link rc-pasif" title="${esc(m.etiket)} — yakında" aria-disabled="true">
        <span class="rc-ikon ${esc(m.ikon)}"></span>
        <span class="rc-menu-etiket">${esc(m.etiket)}</span><span class="rc-yakinda">Yakında</span></span>`;
      continue;
    }
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

  // Ayarlar kısayolu: yalnızca v2 Ayarlar sayfası varsa görünür.
  const ayar = (veri.menu || []).find((m) => m.slug === 'ayarlar');
  const ayarBtn = el('#rcAyarBtn');
  if (ayarBtn) {
    if (ayar && ayar.url) { ayarBtn.setAttribute('href', ayar.url); ayarBtn.style.display = ''; }
    else ayarBtn.style.display = 'none';
  }

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
    .map((s) => `<option value="${esc(s.id)}"${s.aktif ? ' selected' : ''}>${esc(s.ad)}</option>`)
    .join('');
  kutu.innerHTML = `<select id="rcSubeSelect" aria-label="İşletme / Şube">${opsiyonlar}</select>`;
  el('#rcSubeSelect').addEventListener('change', (e) => { if (e.target.value) location.href = subeUrl(e.target.value); });
}

// Aynı v2 sayfasında şube değiştir: liste sayfalarında (/v2/x/20) path'teki şube atılır,
// ?sube= ile yeniden açılır. Adisyona bağlı sayfada (tahsilat) şube listesine dönülür.
function subeUrl(subeId) {
  const yol = location.pathname.replace(/\/+$/, '');
  if (/^\/v2\/tahsilat\//.test(yol)) return '/v2/satislar?sube=' + encodeURIComponent(subeId);
  const temel = yol.replace(/^(\/v2\/[a-z-]+)\/\d+$/, '$1');
  return temel + '?sube=' + encodeURIComponent(subeId);
}

async function yukle(salonId) {
  const { ok, govde } = await kabukGetir(salonId);
  if (!ok || !govde || !govde.veri) {
    el('#rcMenu').innerHTML = '<div class="rc-menu-iskele">Menü yüklenemedi</div>';
    return;
  }
  const veri = govde.veri;
  const aktifSlug = document.body.dataset.aktif || '';
  _kabukSalon = veri.salon?.id || null;
  menuCiz(veri.menu, aktifSlug);
  headerCiz(veri);
  // Sayfalar kabuk verisini (kullanici adi, salon) ikinci istek atmadan kullanabilsin.
  window.rcPanel.veri = veri;
  document.dispatchEvent(new CustomEvent('rc:kabuk', { detail: veri }));
}

/* ───────────── Header müşteri araması (GET /musteri/ara → /v2/musteri/{id}) ───────────── */
let _kabukSalon = null;
function aramaBagla() {
  const girdi = el('#rcAra');
  if (!girdi) return;
  const kutu = document.createElement('div');
  kutu.className = 'rc-ara-sonuc';
  kutu.hidden = true;
  girdi.closest('.rc-ara').appendChild(kutu);
  let zaman, no = 0;
  const kapat = () => { kutu.hidden = true; };
  girdi.addEventListener('input', () => {
    clearTimeout(zaman);
    const q = girdi.value.trim();
    if (q.length < 2 || !_kabukSalon) { kapat(); return; }
    zaman = setTimeout(async () => {
      const benim = ++no;
      const r = await api('GET', '/musteri/ara', { params: { salon_id: _kabukSalon, q, limit: 8 } });
      if (benim !== no) return;
      const liste = r.ok && r.govde?.basarili ? r.govde.veri : [];
      kutu.innerHTML = liste.length
        ? liste.map((m) => `<a href="/v2/musteri/${m.id}?sube=${_kabukSalon}"><b>${esc(m.ad)}</b><small>${esc(m.telefon || '')}</small></a>`).join('')
        : `<div class="rc-ara-bos">${esc(r.govde && !r.govde.basarili ? (r.govde.mesaj || 'Sonuç yok') : 'Sonuç yok')}</div>`;
      kutu.hidden = false;
    }, 300);
  });
  girdi.addEventListener('keydown', (e) => { if (e.key === 'Escape') kapat(); });
  document.addEventListener('click', (e) => { if (!e.target.closest('.rc-ara')) kapat(); });
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
      // Masaüstü: menüyü açık SABİTLE / hover-ray moduna geri al.
      const sabit = document.body.classList.toggle('rc-sabit');
      localStorage.setItem('rc_menu_sabit', sabit ? '1' : '0');
    }
  });
  el('#rcOverlay').addEventListener('click', () => {
    document.body.classList.remove('rc-ac-menu');
    el('#rcOverlay').classList.remove('acik');
  });
  if (localStorage.getItem('rc_menu_sabit') === '1') document.body.classList.add('rc-sabit');
}

/* ───────────── Modern bildirim (toast) + onay modalı (confirm yerine) ───────────── */
function toastKap() {
  let k = document.getElementById('rcToastKap');
  if (!k) { k = document.createElement('div'); k.id = 'rcToastKap'; k.className = 'rc-toast-kap'; document.body.appendChild(k); }
  return k;
}
function toast(metin, tip = 'bilgi', sure = 3200) {
  if (!metin) return;
  const ikon = tip === 'ok' ? 'bi-check-circle-fill' : tip === 'hata' ? 'bi-exclamation-octagon-fill' : 'bi-info-circle-fill';
  const t = document.createElement('div');
  t.className = 'rc-toast ' + tip;
  t.innerHTML = `<i class="bi ${ikon}"></i><span>${esc(metin)}</span>`;
  toastKap().appendChild(t);
  requestAnimationFrame(() => t.classList.add('gir'));
  setTimeout(() => { t.classList.remove('gir'); setTimeout(() => t.remove(), 260); }, sure);
}
function onay(mesaj, opt = {}) {
  const { baslik = 'Onay', tamam = 'Evet', iptal = 'Vazgeç', tehlike = true } = opt;
  return new Promise((coz) => {
    const ov = document.createElement('div');
    ov.className = 'rc-onay-overlay';
    ov.innerHTML = `<div class="rc-onay-kart" role="dialog" aria-modal="true">
      <div class="rc-onay-ikon ${tehlike ? 'tehlike' : ''}"><i class="bi ${tehlike ? 'bi-trash' : 'bi-question-lg'}"></i></div>
      <h3>${esc(baslik)}</h3><p>${esc(mesaj)}</p>
      <div class="rc-onay-btns">
        <button class="rc-onay-iptal">${esc(iptal)}</button>
        <button class="rc-onay-tamam ${tehlike ? 'tehlike' : ''}">${esc(tamam)}</button>
      </div></div>`;
    document.body.appendChild(ov);
    requestAnimationFrame(() => ov.classList.add('acik'));
    const kapat = (sonuc) => { ov.classList.remove('acik'); setTimeout(() => ov.remove(), 220); document.removeEventListener('keydown', tus); coz(sonuc); };
    const tus = (e) => { if (e.key === 'Escape') kapat(false); };
    ov.querySelector('.rc-onay-iptal').onclick = () => kapat(false);
    ov.querySelector('.rc-onay-tamam').onclick = () => kapat(true);
    ov.onclick = (e) => { if (e.target === ov) kapat(false); };
    document.addEventListener('keydown', tus);
  });
}

// Dışa açılan kanca: sayfa salon'u sonradan öğrenirse kabuğu tazele + ortak UI.
let _aktifSalon = null;
window.rcPanel = {
  salonAyarla(salonId) {
    salonId = parseInt(salonId, 10) || null;
    if (!salonId || salonId === _aktifSalon) return;
    _aktifSalon = salonId;
    yukle(salonId);
  },
  toast,
  onay,
};

etkilesimBagla();
aramaBagla();
yukle(null); // önce varsayılan salon; sayfa isterse salonAyarla ile tazeler
