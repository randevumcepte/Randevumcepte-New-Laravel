/**
 * Panel hatırlatma kartları — eski salon_hatirlatma.js (layout'taki partials/hatirlatma_popup) v2 karşılığı.
 * Veri GET /api/v2/hatirlatma-feed (kartlar, kapsam ve rol kuralı sunucuda; link yeni panel sayfası).
 *
 * Eskinin etkin davranışı aynen: kartlar sağ altta durur (kendiliğinden kapanmaz); tıklanınca ilgili sayfaya gider,
 * × ile kapatılır; tıklanan/kapatılan kart oturum boyu geri gelmez, sayacı değişince yeniden çıkar. Liste 60 sn'de bir
 * (arka plan sekmesinde değil) ve sekmeye dönünce tazelenir.
 * Tam ekran popup ve zil eskide kaldırılmıştı, alınmadı.
 */
import { api, esc } from '/js/lib/v2-ortak.js?v=7';

const POLL_MS = 60000;
const KONFETI_RENK = ['#ec4899', '#f59e0b', '#10b981', '#3b82f6', '#a855f7', '#ef4444', '#fbbf24'];

const d = { salon: null, gosterilen: new Set() };
const anahtarOf = (h) => `${h.id}:${h.sayac || 0}`;
const kapatKey = () => `sht_toast_kapatildi_${d.salon}`;

function kapatilanlar() {
  try { return JSON.parse(sessionStorage.getItem(kapatKey()) || '[]'); } catch { return []; }
}
function kapatildiIsaretle(anahtar) {
  try {
    const arr = kapatilanlar();
    if (!arr.includes(anahtar)) { arr.push(anahtar); sessionStorage.setItem(kapatKey(), JSON.stringify(arr)); }
  } catch { /* gizli pencere */ }
}

function tepsi() {
  let t = document.getElementById('salon-hatirlatma-toaster');
  if (!t) {
    t = document.createElement('div');
    t.id = 'salon-hatirlatma-toaster';
    t.setAttribute('aria-live', 'polite');
    document.body.appendChild(t);
  }
  return t;
}

function kartGoster(h) {
  const anahtar = anahtarOf(h);
  const t = tepsi();
  t.querySelectorAll('.sht-toast').forEach((x) => { if (x.dataset.id === String(h.id)) x.remove(); });
  const kart = document.createElement('div');
  kart.className = `sht-toast tema-${h.tema || 'default'}`;
  kart.dataset.id = String(h.id);
  kart.innerHTML = `<div class="sht-emoji">${esc(h.emoji || '🔔')}</div>
    <div class="sht-body">
      <p class="sht-baslik">${esc(h.baslik)}${h.sayac ? `<span class="sht-sayac">${esc(h.sayac)}</span>` : ''}</p>
      <p class="sht-mesaj">${esc(h.mesaj)}</p>
    </div>
    <button type="button" class="sht-kapat" aria-label="Kapat">&times;</button>`;
  kart.addEventListener('click', (e) => {
    if (e.target.closest('.sht-kapat')) {
      e.stopPropagation();
      kapatildiIsaretle(anahtar);
      kart.classList.remove('show');
      setTimeout(() => kart.remove(), 400);
      return;
    }
    if (h.tema === 'konfeti-parti') konfetiPatlat(kart);
    if (h.link) { kapatildiIsaretle(anahtar); window.location.href = h.link; }
  });
  t.appendChild(kart);
  if (h.tema === 'konfeti-parti') setTimeout(() => konfetiPatlat(kart), 600);
  setTimeout(() => kart.classList.add('show'), 30);
}

async function yenile() {
  const salon = d.salon;
  if (!salon) return;
  const r = await api('GET', '/hatirlatma-feed', { params: { salon_id: salon } });
  if (salon !== d.salon || !r.ok || !r.govde?.basarili) return;
  const kapali = kapatilanlar();
  let sira = 0;
  (r.govde.veri.hatirlatmalar || []).forEach((h) => {
    const anahtar = anahtarOf(h);
    if (d.gosterilen.has(anahtar) || kapali.includes(anahtar)) return;
    d.gosterilen.add(anahtar);
    setTimeout(() => kartGoster(h), sira++ * 350);
  });
}

function konfetiPatlat(kaynak) {
  const b = kaynak.getBoundingClientRect();
  const cx = b.left + b.width / 2;
  for (let i = 0; i < 25; i++) {
    setTimeout(() => {
      const p = document.createElement('div');
      p.className = 'sht-confetti';
      p.style.left = `${cx}px`;
      p.style.top = `${b.top}px`;
      p.style.background = KONFETI_RENK[Math.floor(Math.random() * KONFETI_RENK.length)];
      document.body.appendChild(p);
      const aci = Math.random() * Math.PI * 2;
      const mesafe = 80 + Math.random() * 120;
      const sure = 900 + Math.random() * 600;
      p.animate([
        { transform: 'translate(0,0) rotate(0)', opacity: 1 },
        { transform: `translate(${Math.cos(aci) * mesafe}px,${Math.sin(aci) * mesafe + 200}px) rotate(${Math.random() * 720}deg)`, opacity: 0 },
      ], { duration: sure, easing: 'cubic-bezier(.2,.7,.4,1)' });
      setTimeout(() => p.remove(), sure);
    }, i * 15);
  }
}

function salonKur(salonId) {
  salonId = parseInt(salonId, 10) || null;
  if (!salonId || salonId === d.salon) return;
  d.salon = salonId;
  d.gosterilen.clear();
  tepsi().innerHTML = '';
  yenile();
}

setInterval(() => { if (!document.hidden) yenile(); }, POLL_MS);
document.addEventListener('visibilitychange', () => { if (!document.hidden) yenile(); });
document.addEventListener('rc:kabuk', (e) => salonKur(e.detail?.salon?.id));
if (window.rcPanel?.veri) salonKur(window.rcPanel.veri.salon?.id);
