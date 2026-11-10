/**
 * Özet (dashboard) v2 — build'siz island (sade ES module).
 *
 * Görünüm eski isletmeadmin/dashboard.blade.php ile BİREBİR (rmc-* işaretleme + css/ozet-v2.css).
 * HANGİ kart/sekme/eylemin çizileceği, dönem düğmeleri, tablo kolonları ve tüm sayılar
 * /api/v2/ozet/*'tan gelir — burada yetki kuralı ya da hesap YOK (Flutter aynı uçları kullanır).
 * Bu dosyadaki sabitler yalnız görünüştür (yerleşim, ton, renk). Eski ekranda olmayan
 * "karsilastirma" kartı Flutter içindir; web çizmez.
 */

import { qp, api, el, esc } from '/js/lib/v2-ortak.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=8';
import { trTarih } from '/js/lib/v2-donem.js?v=7';
import { halka, grafikleriTemizle } from '/js/lib/v2-grafik.js?v=7';

const kok = document.getElementById('rc-ozet');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
if (salonId && window.rcPanel) window.rcPanel.salonAyarla(salonId);

const USTTEKILER = ['kasa', 'anket', 'randevu', 'cark'];
const ALTTAKILER = ['akis', 'personel'];
const TON = { kasa: 'green', anket: 'violet', randevu: 'orange', cark: 'mix', akis: 'pink', personel: 'blue' };
const GECIKME = { kasa: 1, anket: 2, randevu: 3, cark: 4, akis: 6, personel: 7 };
const ALT_YAZI = { akis: 'Randevu + Tahsilat', personel: 'Bugün' };
const EYLEM_RENK = { yeni_randevu: 1, yeni_satis: 2, yeni_paket: 3, yeni_hizmet: 4, isletme_raporlari: 5, musteri_listesi: 6, seans_takibi: 7 };
const KOVA_RENK = { nakit: '#1fbf6f', kredikarti: '#6e4bff', havale: '#ff8a3d', online: '#1e9fff', diger: '#9097ad' };

const trSayi = (n) => (Number(n) || 0).toLocaleString('tr-TR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
const yuzde = (v, t) => (t > 0 ? Math.round((v / t) * 1000) / 10 : 0);
const virgul = (n) => String(n).replace('.', ',');
const iskelet = (adet) => Array.from({ length: adet }, (_, i) => `<div class="rmc-skel rmc-skel-line" style="width:${90 - i * 8}%;margin:12px 0;"></div>`).join('');

/** Sayıyı 0'dan hedefe yumuşakça götürür (eski kartlardaki animasyon). */
function canlandir(hedefEl, hedef, bicim = trSayi) {
  if (!hedefEl) return;
  const bas = performance.now();
  const adim = (t) => {
    const k = Math.min(1, (t - bas) / 1100);
    hedefEl.textContent = bicim(hedef * (1 - Math.pow(1 - k, 4)));
    if (k < 1) requestAnimationFrame(adim);
  };
  requestAnimationFrame(adim);
}

// ───────────────────────── İskelet ─────────────────────────

async function baslat() {
  if (!salonId) { kok.innerHTML = '<div class="rmc-tab-empty">Şube seçili değil (?sube=... bekleniyor).</div>'; return; }
  kok.innerHTML = `<div class="rmc-dash-wrap">${iskelet(6)}</div>`;

  const r = await api('GET', '/ozet/acilis', { params: { salon_id: salonId } });
  if (!r.ok || !r.govde?.basarili) { kok.innerHTML = `<div class="rmc-tab-empty rc-hata">${esc(r.govde?.mesaj || `Hata (${r.kod})`)}</div>`; return; }
  const v = r.govde.veri;
  const kartlar = (kodlar) => v.kartlar.filter((k) => kodlar.includes(k.kod));
  const ust = kartlar(USTTEKILER);
  const alt = kartlar(ALTTAKILER);

  kok.innerHTML = `
    <div class="rmc-dash-wrap">
      ${heroHtml(v)}
      ${v.eylemler.length ? `<div class="rmc-quick-actions">${v.eylemler.map(eylemHtml).join('')}</div>` : ''}
      ${ust.length ? `<div class="rmc-grid-top">${ust.map(kartHtml).join('')}</div>` : ''}
      ${v.sekmeler.length ? `<div class="rmc-card tone-mix rmc-tabs-card delay-5"><div class="rmc-tabs-nav" id="ozSekmeler"></div><div class="rmc-tab-content" id="ozSekmeIcerik"></div></div>` : ''}
      ${alt.length ? `<div class="rmc-grid-bottom">${alt.map(kartHtml).join('')}</div>` : ''}
      ${!ust.length && !alt.length && !v.sekmeler.length ? '<div class="rmc-tab-empty">Bu ekrandaki bilgiler için yetkiniz yok.</div>' : ''}
    </div>`;

  selamla(window.rcPanel?.veri);
  document.addEventListener('rc:kabuk', (e) => selamla(e.detail));
  [...ust, ...alt].forEach((k) => { donemBagla(k); kartYukle(k.kod, k.varsayilan_donem); });
  if (v.sekmeler.length) sekmeleriKur(v.sekmeler);
  canlandir(el('#ozHeroRandevu'), v.bugun?.randevu ?? 0);
  canlandir(el('#ozHeroGelir'), v.bugun?.gelir ?? 0);
}

function heroHtml(v) {
  const b = v.bugun || {};
  const simdi = new Date();
  const tarih = `${simdi.toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', year: 'numeric' })} · ${simdi.toLocaleDateString('tr-TR', { weekday: 'long' })}`;
  const sayi = (id, deger, etiket) => `<div class="rmc-hero-stat"><div class="v"${id ? ` id="${id}"` : ''}>${deger}</div><div class="l">${esc(etiket)}</div></div>`;
  const lisans = v.salon.lisans_gun;

  return `
    <div class="rmc-hero">
      <div class="rmc-hero-text">
        <div class="rmc-hero-date">${esc(tarih)}</div>
        <div class="rmc-hero-greet" id="ozSelam">${esc(selam())} 👋</div>
        <div class="rmc-hero-sub">${esc(v.salon.ad)} — Bugünün özetine göz at, aşağıdaki kartlardan her şeyi tek tıkla yönet.</div>
      </div>
      <div class="rmc-hero-stats">
        ${b.randevu !== null && b.randevu !== undefined ? sayi('ozHeroRandevu', '—', 'Bugün Randevu') : ''}
        ${b.gelir !== null && b.gelir !== undefined ? sayi('ozHeroGelir', '—', 'Bugün Gelir ₺') : ''}
        ${lisans !== null && lisans >= 0 ? sayi('', esc(lisans), 'Üyelik Gün') : ''}
      </div>
    </div>`;
}

function selam() {
  const s = new Date().getHours();
  return s < 6 ? 'İyi geceler' : s < 12 ? 'Günaydın' : s < 18 ? 'İyi günler' : 'İyi akşamlar';
}

function selamla(kabuk) {
  const ad = String(kabuk?.kullanici?.ad || '').trim().split(/\s+/)[0];
  const h = el('#ozSelam');
  if (h && ad) h.textContent = `${selam()}, ${ad} 👋`;
}

function eylemHtml(e) {
  const ic = `<div class="rmc-qa-icon"><i class="bi ${esc(e.ikon)}"></i></div><div class="rmc-qa-text">${esc(e.etiket)}</div>`;
  const sinif = `rmc-qa var-${EYLEM_RENK[e.kod] || 1}`;
  return e.url
    ? `<a class="${sinif}" href="${esc(e.url)}">${ic}</a>`
    : `<span class="${sinif}" aria-disabled="true" title="Bu ekran yeni panelde henüz yok">${ic}</span>`;
}

function kartHtml(k) {
  const sag = k.donemler.length
    ? `<div class="rmc-period">${k.donemler.map((d) => `<button type="button" data-donem="${esc(d.kod)}"${d.kod === k.varsayilan_donem ? ' class="is-active"' : ''}>${esc(d.ad)}</button>`).join('')}</div>`
    : `<span style="font-size:11px;color:var(--dash-text-muted);font-weight:600;">${esc(ALT_YAZI[k.kod] || '')}</span>`;
  return `
    <div class="rmc-card tone-${TON[k.kod]} delay-${GECIKME[k.kod]}" data-kart="${esc(k.kod)}">
      <div class="rmc-card-head">
        <div class="rmc-card-title"><span class="rmc-title-icon"><i class="bi ${esc(k.ikon)}"></i></span> ${esc(k.baslik)}</div>
        ${sag}
      </div>
      <div class="oz-govde">${iskelet(4)}</div>
    </div>`;
}

function donemBagla(k) {
  const kap = el(`[data-kart="${k.kod}"] .rmc-period`);
  if (!kap) return;
  kap.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-donem]');
    if (!b || b.classList.contains('is-active')) return;
    kap.querySelectorAll('button').forEach((x) => x.classList.toggle('is-active', x === b));
    kartYukle(k.kod, b.dataset.donem);
  });
}

const istekler = {};
async function kartYukle(kod, donem) {
  const govde = el(`[data-kart="${kod}"] .oz-govde`);
  const no = (istekler[kod] = (istekler[kod] || 0) + 1);
  const r = await api('GET', `/ozet/kart/${kod}`, { params: { salon_id: salonId, ...(donem ? { donem } : {}) } });
  if (no !== istekler[kod]) return; // dönem hızlı değiştiyse eski cevabı çizme
  if (!r.ok || !r.govde?.basarili) { govde.innerHTML = `<div class="rmc-tab-empty">${esc(r.govde?.mesaj || 'Veri yüklenemedi.')}</div>`; return; }
  CIZICILER[kod](govde, r.govde.veri);
}

// ───────────────────────── Kartlar ─────────────────────────

/** Son 7 gün çizgisi (eski ekrandaki satır içi SVG). */
function kivilcim(noktalar) {
  const pts = noktalar.map((p) => Number(p.tutar) || 0);
  if (!pts.length) return '';
  const max = Math.max(...pts, 1);
  const w = 200, h = 36, adim = pts.length > 1 ? w / (pts.length - 1) : w;
  const yol = pts.map((v, i) => `${i === 0 ? 'M' : 'L'}${(i * adim).toFixed(1)},${(h - (v / max) * (h - 4) - 2).toFixed(1)}`).join(' ');
  return `<svg class="rmc-kasa-spark" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none">
    <defs><linearGradient id="rmcSparkG" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="#1fbf6f" stop-opacity=".40"/><stop offset="100%" stop-color="#1fbf6f" stop-opacity="0"/></linearGradient></defs>
    <path d="${yol} L${w},${h} L0,${h} Z" fill="url(#rmcSparkG)"/>
    <path d="${yol}" fill="none" stroke="#1fbf6f" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
}

const CIZICILER = {
  kasa(g, v) {
    const toplam = v.toplam || 0;
    const kova = v.odeme_etiketleri;
    g.innerHTML = `
      <div class="rmc-kasa-body">
        <div class="rmc-kasa-chart-box"><canvas></canvas></div>
        <div class="rmc-kasa-legend">${kova.map((e) => `
          <div class="rmc-kasa-row"><span class="rmc-dot" style="background:${KOVA_RENK[e.kod] || '#9097ad'}"></span> ${esc(e.ad)}
            <span style="margin-left:auto;color:#9097ad;font-weight:600;" title="${esc(trSayi(v.kovalar[e.kod]))} ₺">${virgul(yuzde(v.kovalar[e.kod] || 0, toplam))} %</span></div>`).join('')}
        </div>
      </div>
      <div class="rmc-kasa-total">
        <div class="lbl">Toplam Gelir${v.personel_kapsami ? ' (kendi satışlarınız)' : ''}</div>
        <div class="val"><span class="oz-kasa-toplam">0</span> ₺</div>
        ${kivilcim(v.son7_gun)}
      </div>`;
    grafikleriTemizle();
    const tuval = el('canvas', g);
    const secenek = { lejant: false, kesit: '70%' };
    if (toplam > 0) {
      halka(tuval, kova.map((e) => e.ad), kova.map((e) => v.kovalar[e.kod] || 0), { ...secenek, para: true, renkler: kova.map((e) => KOVA_RENK[e.kod] || '#9097ad') });
    } else {
      halka(tuval, [''], [1], { ...secenek, renkler: ['#eef0f6'], ipucuAcik: false });
    }
    canlandir(el('.oz-kasa-toplam', g), toplam);
  },

  anket(g, v) {
    const n = v.nps;
    const genislik = (x) => (n.toplam > 0 ? (x / n.toplam) * 100 : 0);
    const yorumlar = v.son_yorumlar.map((y) => {
      const sinif = y.nps_skoru === null ? '' : y.nps_skoru >= 9 ? 'hi' : y.nps_skoru <= 6 ? 'lo' : '';
      const skor = y.nps_skoru !== null ? `NPS ${y.nps_skoru}` : y.csat_skoru !== null ? `CSAT ${y.csat_skoru}` : '';
      const metin = y.yorum.length > 110 ? y.yorum.slice(0, 110) + '…' : y.yorum;
      return `<div class="rmc-yorum ${sinif}"><div style="flex:1;"><div class="y-txt">"${esc(metin)}"</div>
        <div class="y-meta">${esc([y.ad, skor, trTarih(y.cevap_zamani)].filter(Boolean).join(' · '))}</div></div></div>`;
    }).join('');
    g.innerHTML = `
      <div class="rmc-anket-body">
        <div class="rmc-nps-block">
          <div class="rmc-nps-skor"><span id="rmc-anket-nps">—</span><span class="rmc-nps-lbl">NPS</span></div>
          <div class="rmc-nps-bar" title="Promoter / Pasif / Detractor">
            <div class="seg seg-prom"></div><div class="seg seg-pas"></div><div class="seg seg-det"></div>
          </div>
          <div class="rmc-nps-legend">
            <span><i class="dot prom"></i> Promoter <b>${esc(n.promoter)}</b></span>
            <span><i class="dot pas"></i> Pasif <b>${esc(n.pasif)}</b></span>
            <span><i class="dot det"></i> Detractor <b>${esc(n.detractor)}</b></span>
          </div>
        </div>
        <div class="rmc-anket-mini">
          <div class="mini-stat"><div class="mini-val">${v.csat_ort === null ? '—' : esc(virgul(Number(v.csat_ort).toFixed(2)))}</div><div class="mini-lbl">CSAT / 5</div></div>
          <div class="mini-stat"><div class="mini-val">${esc(virgul(v.cevap_orani))}%</div><div class="mini-lbl">Cevap Oranı</div><div class="mini-sub">${esc(v.toplam_cevap)} / ${esc(v.toplam_gonderim)}</div></div>
        </div>
      </div>
      <div class="rmc-anket-yorumlar">${yorumlar || '<div class="rmc-yorum-bos"><i class="bi bi-chat-quote" style="font-size:20px;color:#dde1ec;display:block;margin-bottom:4px;"></i>Henüz yorum yok.</div>'}</div>`;
    requestAnimationFrame(() => {
      el('.seg-prom', g).style.width = genislik(n.promoter) + '%';
      el('.seg-pas', g).style.width = genislik(n.pasif) + '%';
      el('.seg-det', g).style.width = genislik(n.detractor) + '%';
    });
    if (n.skor !== null) canlandir(el('#rmc-anket-nps', g), n.skor, (x) => (n.skor >= 0 ? '+' : '') + Math.round(x));
  },

  randevu(g, v) {
    const oran = Number(v.doluluk) || 0;
    const derece = Math.round(oran * 3.6);
    const c1 = oran >= 70 ? '#1fbf6f' : oran >= 40 ? '#ff8a3d' : '#ff5c8a';
    const c2 = oran >= 70 ? '#46d68b' : oran >= 40 ? '#ffac6e' : '#ff8aae';
    const satir = (ikon, ton, sinif, etiket) => `
      <div class="rmc-randevu-item"><div class="ico bg-soft-${ton}"><i class="bi ${ikon}"></i></div>
        <div class="txt"><div class="num ${sinif}">0</div><div class="lbl">${etiket}</div></div></div>`;
    g.innerHTML = `
      <div class="rmc-randevu-body">
        <div class="rmc-randevu-list">
          ${satir('bi-alarm', 'violet', 'oz-r1', 'Oluşturulanlar')}
          ${satir('bi-check-circle-fill', 'green', 'oz-r2', 'Sonuçlananlar')}
          ${satir('bi-x-circle-fill', 'pink', 'oz-r3', 'Sonuçlanmayanlar')}
        </div>
        <div>
          <div class="rmc-doluluk-ring" style="background:conic-gradient(from -90deg, ${c1} 0deg, ${c2} ${derece}deg, #eef0f6 ${derece}deg)">
            <div class="rmc-doluluk-ring-inner"><div class="rmc-doluluk-pct">0%</div><div class="rmc-doluluk-lbl">Anlık Doluluk<br>Oranı</div></div>
          </div>
        </div>
      </div>`;
    canlandir(el('.oz-r1', g), v.olusturulan);
    canlandir(el('.oz-r2', g), v.sonuclanan);
    canlandir(el('.oz-r3', g), v.sonuclanmayan);
    canlandir(el('.rmc-doluluk-pct', g), oran, (x) => Math.round(x) + '%');
  },

  cark(g, v) {
    const oran = Number(v.kazanma_orani) || 0;
    const cevre = 276.46;
    const satirlar = v.son_kazananlar.map((k) => `
      <div class="rmc-cark-kazanan-row"><i class="bi bi-trophy-fill" style="color:#f7c948"></i><span class="ad">${esc(k.ad)}</span><span class="od">${esc(k.odul)}</span></div>`).join('');
    g.innerHTML = `
      <div class="rmc-cark-body">
        <div class="rmc-cark-wheel">
          <svg viewBox="0 0 100 100" width="120" height="120">
            <circle cx="50" cy="50" r="44" fill="none" stroke="#eef0f6" stroke-width="10"/>
            <circle class="oz-cark-halka" cx="50" cy="50" r="44" fill="none" stroke="url(#rmcCarkGrad)" stroke-width="10" stroke-linecap="round"
              stroke-dasharray="${cevre}" stroke-dashoffset="${cevre}" transform="rotate(-90 50 50)" style="transition:stroke-dashoffset 1.2s cubic-bezier(.16,1,.3,1);"/>
            <defs><linearGradient id="rmcCarkGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#22c4cb"/><stop offset="100%" stop-color="#6e4bff"/></linearGradient></defs>
            <text class="oz-cark-oran" x="50" y="52" text-anchor="middle" font-size="18" font-weight="800" fill="#181b2c">0%</text>
            <text x="50" y="66" text-anchor="middle" font-size="6.5" font-weight="700" fill="#9097ad">KAZANMA</text>
          </svg>
        </div>
        <div class="rmc-cark-stats">
          <div class="cark-row"><span class="lbl">Toplam Çevrim</span><b>${esc(trSayi(v.toplam_cevrim))}</b></div>
          <div class="cark-row"><span class="lbl"><i class="dot" style="background:#1fbf6f"></i> Kazanan</span><b>${esc(trSayi(v.kazanan))}</b></div>
          <div class="cark-row"><span class="lbl"><i class="dot" style="background:#9097ad"></i> Boş</span><b>${esc(trSayi(v.bos))}</b></div>
          <div class="cark-row cark-row-sep"><span class="lbl">Kupon (verilen/kullanılan)</span><b>${esc(v.kupon.verilen)} / ${esc(v.kupon.kullanilan)}</b></div>
          <div class="cark-row"><span class="lbl">Kupon Kullanım</span><b>${esc(v.kupon.orani)}%</b></div>
        </div>
      </div>
      <div class="rmc-cark-yorumlar">${satirlar || '<div class="rmc-yorum-bos"><i class="bi bi-trophy" style="font-size:20px;color:#dde1ec;display:block;margin-bottom:4px;"></i>Henüz kazanan yok.</div>'}</div>`;
    requestAnimationFrame(() => el('.oz-cark-halka', g).setAttribute('stroke-dashoffset', cevre * (1 - oran / 100)));
    canlandir(el('.oz-cark-oran', g), oran, (x) => Math.round(x) + '%');
  },

  akis(g, v) {
    if (!v.liste.length) { g.innerHTML = '<div class="rmc-tab-empty">Bugün için kayıt yok.</div>'; return; }
    const simdi = `<div class="rmc-timeline-now"><span>Şimdi · ${esc(v.simdi)}</span></div>`;
    let eklendi = false;
    const satirlar = v.liste.map((o) => {
      const once = !eklendi && o.saat > v.simdi ? ((eklendi = true), simdi) : '';
      const baslik = o.tip === 'tahsilat' ? `${esc(o.baslik)} — ${esc(trSayi(o.tutar))} ₺` : esc(o.baslik);
      return `${once}<div class="rmc-timeline-item ${o.tip === 'tahsilat' ? 'tahsilat' : ''}"><div class="t-saat">${esc(o.saat)}</div><div class="t-baslik">${baslik}</div></div>`;
    }).join('');
    g.innerHTML = `<div class="rmc-timeline">${satirlar}${eklendi ? '' : simdi}</div>`;
  },

  personel(g, v) {
    if (!v.personeller.length) { g.innerHTML = '<div class="rmc-tab-empty">Aktif personel yok.</div>'; return; }
    g.innerHTML = `<div class="rmc-personel-list">${v.personeller.map((p) => `
      <div class="rmc-personel-row" title="${esc(p.ad)}: ${esc(p.randevu)} randevu">
        <div class="ad">${esc(p.ad)}</div><div class="bar"><div class="bar-fill" data-pct="${esc(p.doluluk)}"></div></div><div class="pct">${esc(p.doluluk)}%</div>
      </div>`).join('')}</div>`;
    setTimeout(() => g.querySelectorAll('.bar-fill').forEach((b) => { b.style.width = b.dataset.pct + '%'; }), 50);
  },
};

// ───────────────────────── Liste sekmeleri ─────────────────────────

/** Kolon tipine göre hücre — kolonlar API'den (OzetSekmesi::kolonlar). */
const HUCRE = {
  tarih: (d) => esc(trTarih(d) || '-'),
  saat: (d) => esc(d || ''),
  para: (d) => `${esc(trSayi(d))} ₺`,
  metin: (d) => esc(d ?? '-'),
};
const siralamaDegeri = (kolon, r) => (kolon.tip === 'para' ? Number(r[kolon.alan]) || 0 : r[kolon.alan]);

function hucre(kolon, r) {
  const h = (HUCRE[kolon.tip] || HUCRE.metin)(r[kolon.alan]);
  if (kolon.alan === 'musteri' && r.musteri_id) return `<a href="/v2/musteri/${esc(r.musteri_id)}?sube=${salonId}">${h}</a>`;
  if (kolon.alan === 'kalan') return `<span class="rmc-kalan">${h}</span>`;
  return h;
}

function sekmeleriKur(sekmeler) {
  let aktif = sekmeler[0];
  const nav = el('#ozSekmeler');
  const icerik = el('#ozSekmeIcerik');

  const ciz = () => {
    nav.innerHTML = sekmeler.map((s) => `<button type="button" class="rmc-tab${s.kod === aktif.kod ? ' is-active' : ''}" data-kod="${esc(s.kod)}"><i class="bi ${esc(s.ikon)}"></i> ${esc(s.ad)}${s.yeni ? ' <span class="badge-new">YENİ</span>' : ''}</button>`).join('');
  };

  const yukle = async () => {
    const s = aktif;
    icerik.innerHTML = `<div style="padding:20px 12px;">${iskelet(3)}</div>`;
    const r = await api('GET', `/ozet/sekme/${s.kod}`, { params: { salon_id: salonId } });
    if (s !== aktif) return;
    if (!r.ok || !r.govde?.basarili) { icerik.innerHTML = `<div class="rmc-tab-empty">${esc(r.govde?.mesaj || 'Veri yüklenemedi.')}</div>`; return; }
    const liste = r.govde.veri.liste;
    if (!liste.length) {
      icerik.innerHTML = `<div class="rmc-tab-empty"><i class="bi bi-emoji-smile" style="font-size:32px;color:#dde1ec;display:block;margin-bottom:8px;"></i>${esc(s.bos_mesaj)}</div>`;
      return;
    }
    // Liste en çok birkaç onlarca satır: arama / sayfa boyu / sıralama ortak tabloda, tarayıcıda.
    veriTablosu(icerik, {
      anahtar: 'ozet',
      tabloSinif: 'rmc-tab-table',
      bosMesaj: s.bos_mesaj,
      kolonlar: s.kolonlar.map((k) => ({ baslik: k.baslik, sirala: k.alan, deger: (x) => siralamaDegeri(k, x) })),
      araMetni: (x) => s.kolonlar.map((k) => (k.tip === 'tarih' ? trTarih(x[k.alan]) : x[k.alan])).join(' '),
      satir: (x) => `<tr${s.kod === 'acik_adisyon' ? ` class="rmc-tablo-satir" data-git="/v2/tahsilat/${esc(x.id)}"` : ''}>${s.kolonlar.map((k) => `<td>${hucre(k, x)}</td>`).join('')}</tr>`,
    }).kayitlarAyarla(liste);
  };

  nav.addEventListener('click', (e) => {
    const b = e.target.closest('.rmc-tab');
    if (!b || b.dataset.kod === aktif.kod) return;
    aktif = sekmeler.find((x) => x.kod === b.dataset.kod);
    ciz();
    yukle();
  });
  // Açık adisyon satırı → tahsilat ekranı (müşteri linkine tıklama hariç).
  icerik.addEventListener('click', (e) => {
    if (e.target.closest('a')) return;
    const tr = e.target.closest('tr[data-git]');
    if (tr) location.href = tr.dataset.git;
  });
  ciz();
  yukle();
}

baslat();
