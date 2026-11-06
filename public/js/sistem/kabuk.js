/** Sistem yonetimi kabugu: menu, rozet, bildirim zili, genel arama (Ctrl+K), kullanici menusu, cikis. Veri /api/v2/sistem. */
import { el, esc } from '/js/lib/v2-ortak.js?v=2';
import { toast, onay } from '/js/lib/v2-arayuz.js?v=1';
import { syApi, syToken, syTokenSil, girisGit } from '/js/lib/v2-sistem.js?v=1';

// Ekran modulleri mesaj()/onay() icin bu kancayi kullanir (v2-ortak panel()).
window.rcPanel = { toast, onay };

if (!syToken()) girisGit();

function menuCiz(menu, rozetler) {
  const aktif = document.body.dataset.aktif;
  let bolum = null;
  el('#syMenu').innerHTML = menu.map((m) => {
    const bas = m.bolum !== bolum ? `<div class="sy-nav-section">${esc(m.bolum)}</div>` : '';
    bolum = m.bolum;
    const sayi = m.rozet ? rozetler[m.rozet] : 0;
    return `${bas}<a href="${esc(m.url)}" class="sy-nav-item${m.kod === aktif ? ' active' : ''}">
      <span class="icon mdi ${esc(m.ikon)}"></span>${esc(m.etiket)}${sayi > 0 ? `<span class="badge">${sayi}</span>` : ''}</a>`;
  }).join('');
}

async function kabukYukle() {
  const { ok, kod, govde } = await syApi('GET', '/kabuk');
  if (kod === 401) { syTokenSil(); girisGit(); return; }
  if (!ok || !govde?.basarili) return;
  const v = govde.veri;
  menuCiz(v.menu, v.rozetler);
  el('#syKullaniciAd').textContent = v.kullanici.ad;
  el('#syKullaniciRol').textContent = '· ' + v.kullanici.rol_etiket;
  el('#syAvatar').textContent = (v.kullanici.ad || '?').charAt(0);
  window.rcPanel.veri = v;
  document.dispatchEvent(new CustomEvent('sy:kabuk', { detail: v }));
}

function aramaBagla() {
  const girdi = el('#syGlobalAra');
  const sonuc = el('#syGlobalAraSonuc');
  const bolum = (ad, liste, satir) => (liste.length ? `<div class="sy-search-section">${ad} (${liste.length})</div>${liste.map(satir).join('')}` : '');
  const oge = (href, ad, alt) => `<a class="sy-search-item" href="${href}"><div class="name">${ad}</div><div class="meta">${alt}</div></a>`;

  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); girdi.focus(); }
    if (e.key === 'Escape') { sonuc.classList.remove('visible'); girdi.blur(); }
  });
  let zaman = null;
  let no = 0;
  girdi.addEventListener('input', () => {
    clearTimeout(zaman);
    const q = girdi.value.trim();
    if (q.length < 2) { sonuc.classList.remove('visible'); sonuc.innerHTML = ''; return; }
    zaman = setTimeout(async () => {
      const benim = ++no;
      const { ok, govde } = await syApi('GET', '/ara', { params: { q } });
      if (benim !== no || !ok) return;
      const d = govde.veri;
      const html = bolum('Salonlar', d.salon, (s) => oge(`/sistemyonetim/salon/${s.id}`,
        esc(s.salon_adi) + (Number(s.askiya_alindi) ? ' <span class="sy-badge sy-badge-danger">askıda</span>' : ''),
        [s.yetkili_adi, s.telefon_1].filter(Boolean).map(esc).join(' · ')))
        + bolum('Talepler', d.ticket, (t) => oge(`/sistemyonetim/ticket/${t.id}`, `${esc(t.numara)} — ${esc(t.konu)}`,
          [t.salon_adi, t.durum, t.oncelik].filter(Boolean).map(esc).join(' · ')))
        + bolum('Ekip', d.ekip, (u) => oge('/sistemyonetim/ekip', esc(u.name), `${esc(u.email)} · ${esc(u.rol)}`));
      sonuc.innerHTML = html || '<div class="sy-text-muted" style="padding:20px;text-align:center">Sonuç yok</div>';
      sonuc.classList.add('visible');
    }, 200);
  });
  document.addEventListener('click', (e) => { if (!sonuc.contains(e.target) && e.target !== girdi) sonuc.classList.remove('visible'); });
}

function zilBagla() {
  const zil = el('#syBell');
  const pano = el('#syBellPanel');
  const rozet = el('#syBellBadge');
  const liste = el('#syBellList');
  const yukle = async () => {
    const { ok, govde } = await syApi('GET', '/bildirim');
    if (!ok) return;
    const d = govde.veri;
    rozet.textContent = d.sayi;
    rozet.style.display = d.sayi > 0 ? 'inline-block' : 'none';
    liste.innerHTML = d.liste.length
      ? d.liste.map((b) => `<a class="sy-bell-item" href="${esc(b.link)}">
          <div class="baslik"><span class="mdi ${esc(b.ikon)}" style="color:var(--sy-${esc(b.renk)})"></span> ${esc(b.baslik)}</div>
          <div class="meta">${esc(b.aciklama)} · ${esc(b.zaman)}</div></a>`).join('')
      : '<div class="sy-empty"><div class="icon mdi mdi-check-all"></div><div class="baslik">Bildirim yok</div></div>';
  };
  zil.addEventListener('click', (e) => {
    e.stopPropagation();
    if (pano.classList.toggle('visible')) yukle();
  });
  document.addEventListener('click', (e) => { if (!pano.contains(e.target)) pano.classList.remove('visible'); });
  yukle();
  setInterval(yukle, 60000);
}

function menuleriBagla() {
  const menu = el('#syUserMenu');
  el('#syUserMenuTrigger').addEventListener('click', (e) => { e.stopPropagation(); menu.classList.toggle('visible'); });
  document.addEventListener('click', (e) => { if (!menu.contains(e.target)) menu.classList.remove('visible'); });
  el('#syMenuTetik').addEventListener('click', () => el('#sySidebar').classList.toggle('open'));
  document.addEventListener('click', async (e) => {
    if (!e.target.closest('[data-cikis]')) return;
    e.preventDefault();
    try { await syApi('POST', '/oturum/cikis'); } catch (_) { /* token yine silinir */ }
    syTokenSil();
    location.href = '/sistemyonetim/giris';
  });
}

menuleriBagla();
aramaBagla();
zilBagla();
kabukYukle();
