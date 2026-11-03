/**
 * Çark ÇİZİMİ + test ÇEVİRME + kutlama (eski carkifelek.blade renderWheel / testSpin /
 * startCelebration birebir). Yalnız görsel: veri/kurallar API'de, metinler `tipler` sözlüğünden.
 *
 *   carkCiz(svg, dilimler, kazananIdx, tipler)
 *   await cevir(svg, dilimler.length, kazananIdx)   // ~9 sn, tık sesiyle; kazanan dilimde durur
 *   kutlama() / kutlamaDurdur()
 */
const CX = 150, CY = 150, R = 130;
const NS = 'http://www.w3.org/2000/svg';
const svgEl = (tag, attr = {}) => { const e = document.createElementNS(NS, tag); Object.entries(attr).forEach(([k, v]) => e.setAttribute(k, v)); return e; };

/** Dilimin değer metni: "100" · "%20" · "150₺" (sunucu DilimTipi::degerMetni ile aynı biçim). */
export function degerMetni(d, tip) {
  if (!tip?.degerli || d.deger === null || d.deger === undefined || d.deger === '') return '';
  const n = String(Number(d.deger));
  if (!tip.kupon) return n;
  return d.indirim_tipi === 'tutar' ? `${n}₺` : `%${n}`;
}

/** Tam ödül açıklaması ("22 Puan", "%20 Hizmet İnd.") — kazanan paneli / sonuç modalı. */
export function tamAd(d, tip) {
  const deger = degerMetni(d, tip);
  return deger ? `${deger} ${d.name || tip.kisa}` : (d.name || tip?.kisa || 'Ödül');
}

function satirlara(str, max) {
  const satirlar = [];
  let satir = '';
  for (const w of String(str).split(' ')) {
    if (w.length > max) { if (satir) { satirlar.push(satir); satir = ''; } for (let j = 0; j < w.length; j += max) satirlar.push(w.slice(j, j + max)); }
    else if ((satir ? `${satir} ${w}` : w).length <= max) satir = satir ? `${satir} ${w}` : w;
    else { if (satir) satirlar.push(satir); satir = w; }
  }
  if (satir) satirlar.push(satir);
  return satirlar;
}

function yazi(g, x, y, metin, { fs, fw = '700', fill = 'white', stroke = 'rgba(0,0,0,.55)', sw = 2.5 }) {
  const t = svgEl('text', { x, y, 'text-anchor': 'middle', 'dominant-baseline': 'middle', 'font-size': fs, 'font-weight': fw, fill,
    'paint-order': 'stroke', stroke, 'stroke-width': sw, 'stroke-linejoin': 'round' });
  t.textContent = metin;
  g.appendChild(t);
}

export function carkCiz(svg, dilimler, kazananIdx, tipler) {
  svg.innerHTML = '';
  const n = dilimler.length;
  if (!n) return;
  const ang = 360 / n;
  const kucuk = n <= 8;

  dilimler.forEach((d, i) => {
    const tip = tipler[d.tip] || tipler.bos;
    const sa = i * ang, ea = (i + 1) * ang;
    const sr = (sa - 90) * Math.PI / 180, er = (ea - 90) * Math.PI / 180;
    const x1 = CX + R * Math.cos(sr), y1 = CY + R * Math.sin(sr), x2 = CX + R * Math.cos(er), y2 = CY + R * Math.sin(er);
    const lg = ang > 180 ? 1 : 0;
    svg.appendChild(svgEl('path', { d: `M ${CX} ${CY} L ${x1} ${y1} A ${R} ${R} 0 ${lg} 1 ${x2} ${y2} Z`, fill: d.color, stroke: 'rgba(255,255,255,.7)', 'stroke-width': 2 }));

    const tAng = sa + ang / 2;
    const tRad = (tAng - 90) * Math.PI / 180;
    const okunur = tAng <= 180 ? tAng - 90 : tAng - 270;   // her zaman okunabilir radyal
    const kazanan = i === kazananIdx;
    const deger = degerMetni(d, tip);

    if (deger) {
      // Rakam dış kenarda teğet; ad iç bölgede radyal (eski çizimle aynı)
      const nd = R - (kucuk ? 16 : 13);
      const nx = CX + nd * Math.cos(tRad), ny = CY + nd * Math.sin(tRad);
      const ng = svgEl('g', { transform: `rotate(${tAng}, ${nx}, ${ny})` });
      yazi(ng, nx, ny, deger, { fs: kucuk ? 17 : 14, fw: '900', stroke: 'rgba(0,0,0,.75)', sw: 3.5 });
      svg.appendChild(ng);

      const fs = kucuk ? 11 : 9;
      const cd = kucuk ? 68 : 60;
      const cx = CX + cd * Math.cos(tRad), cy = CY + cd * Math.sin(tRad);
      const max = Math.max(4, Math.floor(55 / (fs * 0.62)));
      const ad = d.name || tip.kisa;
      let sat = satirlara(ad, max);
      if (sat.length > 2) sat = [`${ad.slice(0, max - 1)}…`];
      const g = svgEl('g', { transform: `rotate(${okunur}, ${cx}, ${cy})` });
      sat.forEach((s, k) => yazi(g, cx, cy - ((sat.length - 1) * (fs + 2)) / 2 + k * (fs + 2), s, { fs, fill: 'rgba(255,255,255,.92)', stroke: 'rgba(0,0,0,.5)', sw: 2 }));
      svg.appendChild(g);
    } else {
      const fs = kucuk ? 12 : 10;
      const dist = kucuk ? 76 : 68;
      const max = Math.max(6, Math.floor(80 / (fs * 0.6)));
      const ad = tip.degerli ? (d.name || tip.kisa) : tip.kisa;
      let sat = satirlara(ad, max);
      if (sat.length > 2) sat = [ad.length > max ? `${ad.slice(0, max - 1)}…` : ad];
      const tx = CX + dist * Math.cos(tRad), ty = CY + dist * Math.sin(tRad);
      const g = svgEl('g', { transform: `rotate(${okunur}, ${tx}, ${ty})` });
      sat.forEach((s, k) => yazi(g, tx, ty - ((sat.length - 1) * (fs + 3)) / 2 + k * (fs + 3), s,
        { fs, fill: kazanan ? '#FFD700' : 'white', stroke: kazanan ? 'rgba(100,55,0,.9)' : 'rgba(0,0,0,.55)' }));
      svg.appendChild(g);
    }

    if (kazanan) {
      svg.appendChild(svgEl('path', { d: `M ${x1} ${y1} A ${R} ${R} 0 ${lg} 1 ${x2} ${y2}`, fill: 'none', stroke: '#FFD700', 'stroke-width': 5,
        filter: 'drop-shadow(0 0 3px rgba(255,215,0,.8))' }));
    }
  });
}

// ───────────────────────────── ses ─────────────────────────────
let ses = null;
const sesBaglam = () => { try { ses = ses || new (window.AudioContext || window.webkitAudioContext)(); if (ses.state === 'suspended') ses.resume(); } catch (_) { ses = null; } return ses; };

function tik() {
  const c = sesBaglam(); if (!c) return;
  const len = Math.floor(c.sampleRate * 0.055);
  const buf = c.createBuffer(1, len, c.sampleRate);
  const d = buf.getChannelData(0);
  for (let i = 0; i < len; i++) d[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / len, 4) * 0.45;
  const src = c.createBufferSource(); src.buffer = buf; src.connect(c.destination); src.start();
}

function fanfar() {
  const c = sesBaglam(); if (!c) return;
  const now = c.currentTime;
  [[0, 523], [280, 659], [520, 784], [720, 880], [880, 1047]].forEach(([ms, f]) => {
    const o = c.createOscillator(), g = c.createGain();
    o.type = 'sine'; o.frequency.value = f;
    const t0 = now + ms / 1000;
    g.gain.setValueAtTime(0, t0); g.gain.linearRampToValueAtTime(0.2, t0 + 0.06); g.gain.exponentialRampToValueAtTime(0.001, t0 + 0.5);
    o.connect(g); g.connect(c.destination); o.start(t0); o.stop(t0 + 0.6);
  });
}

// ───────────────────────────── çevirme ─────────────────────────────
const donus = new WeakMap();

/** Kazanan dilimde duran test çevirmesi. Bittiğinde çözülür. */
export function cevir(svg, n, kazananIdx, { pim = null, sureMs = 9000 } = {}) {
  sesBaglam();
  const ang = 360 / n;
  const durak = (kazananIdx + 0.5) * ang + (Math.random() - 0.5) * ang * 0.6;
  const hedef = ((360 - durak) % 360 + 360) % 360;
  const su = donus.get(svg) || 0;
  let fark = hedef - (((su % 360) + 360) % 360);
  if (fark < 0) fark += 360;
  const yeni = su + (12 + Math.floor(Math.random() * 5)) * 360 + fark;
  donus.set(svg, yeni);

  svg.style.transition = `transform ${sureMs / 1000}s cubic-bezier(0.17, 0.67, 0.12, 0.99)`;
  svg.style.transform = `rotate(${yeni}deg)`;

  let son = -1, calisiyor = true;
  const kare = () => {
    if (!calisiyor) return;
    const m = new DOMMatrix(getComputedStyle(svg).transform);
    const aci = (Math.atan2(m.b, m.a) * 180 / Math.PI + 360) % 360;
    const idx = Math.floor(((360 - aci) % 360) / ang) % n;
    if (idx !== son) {
      son = idx; tik();
      if (pim) { pim.classList.remove('ck-tik'); void pim.offsetWidth; pim.classList.add('ck-tik'); }
    }
    requestAnimationFrame(kare);
  };
  requestAnimationFrame(kare);
  return new Promise((ok) => setTimeout(() => { calisiyor = false; ok(); }, sureMs + 200));
}

// ───────────────────────────── kutlama ─────────────────────────────
let tuval = null, raf = null;

export function kutlama() {
  if (!tuval) {
    tuval = document.createElement('canvas');
    tuval.className = 'ck-kutlama';
    document.body.appendChild(tuval);
  }
  const W = (tuval.width = innerWidth), H = (tuval.height = innerHeight);
  tuval.style.display = 'block';
  const ctx = tuval.getContext('2d');
  const renk = ['#FF6B6B', '#FFE66D', '#4ECDC4', '#A29BFE', '#FD79A8', '#6C5CE7', '#00B894', '#FDCB6E', '#E17055', '#74B9FF'];
  const rr = () => renk[Math.floor(Math.random() * renk.length)];
  const p = [];
  for (let i = 0; i < 120; i++) p.push({ t: 'k', x: Math.random() * W, y: -20 - Math.random() * 300, vx: (Math.random() - 0.5) * 3, vy: 3 + Math.random() * 5, r: Math.random() * 360, rs: (Math.random() - 0.5) * 9, w: 6 + Math.random() * 8, h: 3 + Math.random() * 5, c: rr(), a: 1 });
  for (let i = 0; i < 14; i++) p.push({ t: 'b', x: 40 + Math.random() * (W - 80), y: H + 30 + Math.random() * 120, vx: (Math.random() - 0.5) * 1.4, vy: -(2 + Math.random() * 2.8), s: Math.random() * 6.3, ss: 0.02 + Math.random() * 0.02, r: 18 + Math.random() * 14, c: rr(), a: 1 });
  for (let b = 0; b < 5; b++) {
    const bx = 80 + Math.random() * (W - 160), by = 60 + Math.random() * H * 0.45, bc = rr();
    setTimeout(() => { for (let k = 0; k < 32; k++) { const a = (k / 32) * Math.PI * 2, v = 3 + Math.random() * 6; p.push({ t: 's', x: bx, y: by, vx: Math.cos(a) * v, vy: Math.sin(a) * v, r: 3 + Math.random() * 3, c: bc, a: 1, l: 1 }); } }, b * 500);
  }
  const kare = () => {
    ctx.clearRect(0, 0, W, H);
    p.forEach((q) => {
      if (q.a <= 0.01) return;
      ctx.save(); ctx.globalAlpha = q.a;
      if (q.t === 'k') {
        q.x += q.vx; q.y += q.vy; q.vy += 0.09; q.r += q.rs;
        if (q.y > H + 20) q.a = 0;
        ctx.translate(q.x, q.y); ctx.rotate(q.r * Math.PI / 180); ctx.fillStyle = q.c; ctx.fillRect(-q.w / 2, -q.h / 2, q.w, q.h);
      } else if (q.t === 'b') {
        q.s += q.ss; q.x += q.vx + Math.sin(q.s) * 0.6; q.y += q.vy;
        if (q.y < -70) q.a = 0;
        ctx.translate(q.x, q.y);
        ctx.beginPath(); ctx.arc(0, 0, q.r, 0, Math.PI * 2); ctx.fillStyle = q.c; ctx.fill();
        ctx.beginPath(); ctx.arc(-q.r * 0.3, -q.r * 0.3, q.r * 0.27, 0, Math.PI * 2); ctx.fillStyle = 'rgba(255,255,255,.38)'; ctx.fill();
        ctx.beginPath(); ctx.moveTo(0, q.r); ctx.quadraticCurveTo(5, q.r + 14, -3, q.r + 28); ctx.strokeStyle = 'rgba(255,255,255,.55)'; ctx.lineWidth = 1.2; ctx.stroke();
      } else {
        q.x += q.vx; q.y += q.vy; q.vy += 0.18; q.l -= 0.022; q.a = Math.max(0, q.l);
        ctx.beginPath(); ctx.arc(q.x, q.y, Math.max(0.5, q.r * q.l), 0, Math.PI * 2); ctx.fillStyle = q.c; ctx.fill();
      }
      ctx.restore();
    });
    raf = requestAnimationFrame(kare);
  };
  if (raf) cancelAnimationFrame(raf);
  kare();
  fanfar();
}

export function kutlamaDurdur() {
  if (raf) { cancelAnimationFrame(raf); raf = null; }
  if (tuval) tuval.style.display = 'none';
}
