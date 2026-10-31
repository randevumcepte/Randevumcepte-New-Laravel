/**
 * Randevu Takvimi (v2) — build'siz island (sade ES module).
 *
 * Takvim motoru: EventCalendar (vkurko/calendar, MIT, ucretsiz) — global
 * window.EventCalendar (blade'de surumu sabit CDN). Gorunum: personel / oda /
 * cihaz / kategori SUTUNLU gunluk (resourceTimeGridDay), haftalik ve liste.
 *
 * Veri TEK KAYNAK: GET /api/v2/randevu/takvim (Flutter ile ayni uc → kod tekrari
 * yok). Durum aksiyonlari: POST /api/v2/randevu/{id}/geldi|gelmedi|iptal|onayla,
 * DELETE /api/v2/randevu/{id}/geldi-isareti. Sunucu yazmasi kapaliysa (409)
 * ekran ayni ucun DRY-RUN planini "Onizleme" olarak gosterir.
 *
 * Yuk-tasiyan akislar eski mobil ile ayni: geldi → hatali 2 (dogrulama kodu),
 * 3 (KVKK kodu), 4 (seans secimi); gelmedi → seansDusmeOnayi.
 *
 * Token: localStorage['rc_access_token'] (pilotta ?token=). salon: data-salon / ?sube=.
 */

const BASE = '/api/v2';
const ESKI_PANEL = 'https://app.randevumcepte.com.tr';
const _qp = new URLSearchParams(location.search);
if (_qp.get('token')) localStorage.setItem('rc_access_token', _qp.get('token'));
const token = () => localStorage.getItem('rc_access_token') || '';

const panel = () => window.rcPanel || { toast: (m) => alert(m), onay: async (m) => confirm(m) };
const el = (s, k = document) => k.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const pad = (n) => String(n).padStart(2, '0');
const tarihStr = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

async function api(yontem, yol, { params, govde } = {}) {
  let url = BASE + yol;
  if (params) {
    const qs = new URLSearchParams(Object.fromEntries(
      Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''),
    )).toString();
    if (qs) url += '?' + qs;
  }
  const r = await fetch(url, {
    method: yontem,
    cache: 'no-store',
    headers: {
      Accept: 'application/json',
      Authorization: 'Bearer ' + token(),
      ...(govde ? { 'Content-Type': 'application/json' } : {}),
    },
    body: govde ? JSON.stringify(govde) : undefined,
  });
  const t = await r.text();
  let j = null;
  try { j = t ? JSON.parse(t) : null; } catch { /* HTML hata sayfasi */ }
  return { ok: r.ok, kod: r.status, govde: j };
}

// 0xFFRRGGBB (mobil format) → #RRGGBB
function renk(v, varsayilan = '#8a8fb0') {
  if (!v) return varsayilan;
  const s = String(v).trim();
  if (/^0x[0-9a-f]{8}$/i.test(s)) return '#' + s.slice(4);
  if (/^#[0-9a-f]{6}$/i.test(s)) return s;
  return varsayilan;
}
const yari = (hex, a) => hex + Math.round(a * 255).toString(16).padStart(2, '0');

// Paketli durum: durum-geldi-satisOlustu-odemeYapildi-rhId-telafiVar-telafiKaynak-telafiVerildi
function durumCoz(s) {
  const p = String(s || '').split('-');
  return {
    durum: p[0], geldi: p[1], satis: p[2] === '1', odendi: p[3] === '1',
    rhId: p[4], telafi: p[5] === '1', telafiRandevusu: p[6] === '1',
  };
}

const kok = document.getElementById('rc-takvim');
let salonId = parseInt(kok.dataset.salon, 10) || parseInt(_qp.get('sube'), 10) || 0;

const VIEWLER = [
  { ad: 'Günlük', view: 'resourceTimeGridDay' },
  { ad: 'Haftalık', view: 'resourceTimeGridWeek' },
  { ad: 'Liste', view: 'listDay' },
];

const durum = {
  kaynaklar: [],      // sunucudan gelen tum kaynaklar
  secili: '',         // personel/kaynak filtresi ('' = tumu)
  sonVeri: null,
  ec: null,
};

function iskeletCiz() {
  kok.innerHTML = `
    <div class="t-kabuk">
      <div class="t-ust">
        <div class="t-baslik">
          <span class="t-baslik-ikon"><i class="bi bi-calendar4-week"></i></span>
          <div>
            <h1>Randevu Takvimi</h1>
            <div class="t-alt">Önizleme (v2) · durum işlemleri sunucu yazması açılana kadar plan olarak gösterilir</div>
          </div>
        </div>
        <a class="t-yeni-btn" id="tYeni" href="${ESKI_PANEL}/isletmeyonetim/randevular?sube=${salonId}">
          <i class="bi bi-plus-lg"></i> Randevu Oluştur
        </a>
      </div>

      <div class="t-arac">
        <select id="tKaynak" class="t-sec" title="Personel / kaynak"><option value="">Tümü</option></select>
        <div class="t-segment" id="tView">
          ${VIEWLER.map((v, i) => `<button data-view="${v.view}" class="${i === 0 ? 'aktif' : ''}">${v.ad}</button>`).join('')}
        </div>
        <div class="t-nav">
          <button class="t-yuv" id="tGeri" title="Önceki"><i class="bi bi-chevron-left"></i></button>
          <button class="t-tarih" id="tTarih" title="Tarih seç"></button>
          <button class="t-yuv" id="tIleri" title="Sonraki"><i class="bi bi-chevron-right"></i></button>
          <button class="t-bugun" id="tBugun">Bugün</button>
        </div>
        <button class="t-yenile" id="tYenile"><i class="bi bi-arrow-clockwise"></i> Yenile</button>
      </div>

      <div class="t-lejant">
        <span><i class="t-nokta" style="background:#008000"></i> Geldi</span>
        <span><i class="t-nokta" style="background:#ff0000"></i> Gelmedi</span>
        <span><i class="t-nokta" style="background:#ffc107"></i> Onay bekliyor</span>
        <span><i class="t-nokta t-cizgili"></i> Kapalı saat</span>
        <span><i class="bi bi-cash-coin"></i> Ödendi</span>
        <span><i class="bi bi-arrow-repeat"></i> Telafi</span>
      </div>

      <div class="t-kart"><div id="tTakvim"></div><div class="t-yukleniyor" id="tYuk"><i class="bi bi-arrow-repeat"></i></div></div>
    </div>
    <aside class="t-cekmece" id="tCekmece" aria-hidden="true"></aside>
    <div class="t-cekmece-ov" id="tCekmeceOv"></div>`;
}

/* ───────────────────────── Veri ───────────────────────── */

async function olaylariGetir(info) {
  const bitis = new Date(info.end.getTime() - 1); // end haric
  const r = await api('GET', '/randevu/takvim', {
    params: { salon_id: salonId, tarih1: tarihStr(info.start), tarih2: tarihStr(bitis) },
  });
  if (!r.ok || !r.govde?.basarili) {
    panel().toast(r.govde?.mesaj || `Takvim yüklenemedi (${r.kod})`, 'hata');
    return [];
  }
  const v = r.govde.veri;
  durum.sonVeri = v;
  kaynaklariAyarla(v.resources || []);

  return (v.randevular || []).map((e, i) => {
    const d = durumCoz(e.durum);
    const kaynak = durum.kaynaklar.find((k) => String(k.id) === String(e.resourceId));
    const ana = e.kapali ? '#5b5f73' : renk(e.bgcolor, renk(kaynak?.bgcolor));
    return {
      id: `${e.id}-${i}`,
      start: String(e.start).slice(0, 19).replace('T', ' '),
      end: String(e.end).slice(0, 19).replace('T', ' '),
      resourceIds: [String(e.resourceId ?? '')],
      title: e.musteri || '',
      backgroundColor: e.kapali ? '#eceef4' : yari(ana, 0.16),
      textColor: '#2b2f45',
      classNames: e.kapali ? ['t-kapali'] : [],
      extendedProps: { ...e, ana, d },
    };
  });
}

function kaynaklariAyarla(liste) {
  durum.kaynaklar = liste;
  const sec = el('#tKaynak');
  const onceki = sec.value;
  sec.innerHTML = '<option value="">Tümü</option>' + liste.map((k) => `<option value="${k.id}">${esc(k.name)}</option>`).join('');
  sec.value = liste.some((k) => String(k.id) === onceki) ? onceki : '';
  durum.secili = sec.value;
  kaynakFiltresiUygula();
}

function kaynakFiltresiUygula() {
  const gosterilen = durum.kaynaklar
    .filter((k) => !durum.secili || String(k.id) === durum.secili)
    .map((k) => ({ id: String(k.id), title: k.name, extendedProps: k }));
  const simdiki = JSON.stringify(durum.ec?.getOption('resources')?.map((r) => [r.id, r.extendedProps?.sayi]) || []);
  const yeni = JSON.stringify(gosterilen.map((r) => [r.id, r.extendedProps.sayi]));
  if (durum.ec && simdiki !== yeni) durum.ec.setOption('resources', gosterilen);
}

/* ───────────────────────── Takvim ───────────────────────── */

function kaynakEtiketi(info) {
  const k = info.resource.extendedProps || {};
  const ad = String(k.name || info.resource.title || '');
  const r = renk(k.bgcolor);
  // Harf her zaman altta; resim varsa ustune biner, yuklenemezse gizlenir.
  const src = k.avatar ? (/^https?:/.test(k.avatar) ? k.avatar : ESKI_PANEL + '/' + String(k.avatar).replace(/^\/+/, '')) : '';
  const avatar = `<span class="t-av-harf">${esc(ad.charAt(0) || '?')}</span>`
    + (src ? `<img src="${esc(src)}" alt="" loading="lazy" onerror="this.remove()">` : '');
  return {
    html: `<div class="t-kaynak" title="${esc(ad)}">
      <div class="t-av" style="--k:${r}">${avatar}<b class="t-sayi">${k.sayi ?? 0}</b></div>
      <div class="t-kaynak-ad">${esc(ad)}</div>
    </div>`,
  };
}

function olayIcerik(info) {
  const e = info.event.extendedProps || {};
  if (e.kapali) {
    return { html: `<div class="t-olay t-olay-kapali"><i class="bi bi-lock"></i> Kapalı Saat<span>${esc(info.timeText)}</span></div>` };
  }
  const d = e.d || {};
  const ikonlar = [
    d.geldi === '1' ? '<i class="bi bi-check-circle-fill" title="Geldi"></i>' : '',
    d.geldi === '0' ? '<i class="bi bi-x-circle-fill" title="Gelmedi"></i>' : '',
    d.durum === '0' ? '<i class="bi bi-hourglass-split" title="Onay bekliyor"></i>' : '',
    d.odendi ? '<i class="bi bi-cash-coin" title="Ödendi"></i>' : (d.satis ? '<i class="bi bi-receipt" title="Satış oluştu"></i>' : ''),
    d.telafi || d.telafiRandevusu ? '<i class="bi bi-arrow-repeat" title="Telafi"></i>' : '',
  ].join('');
  return {
    html: `<div class="t-olay" style="--a:${e.ana}">
      <div class="t-olay-ust"><b>${esc(e.musteri || '')}</b><span class="t-ikonlar">${ikonlar}</span></div>
      ${e.hizmet ? `<div class="t-olay-hizmet">${esc(e.hizmet)}</div>` : ''}
      <div class="t-olay-saat">${esc(info.timeText)}</div>
    </div>`,
  };
}

function takvimKur() {
  durum.ec = window.EventCalendar.create(el('#tTakvim'), {
    view: 'resourceTimeGridDay',
    locale: 'tr',
    firstDay: 1,
    headerToolbar: { start: '', center: '', end: '' },
    allDaySlot: false,
    nowIndicator: true,
    slotMinTime: '07:00',
    slotMaxTime: '23:00',
    scrollTime: '09:00',
    slotDuration: '00:15',
    slotHeight: 22,
    slotLabelInterval: '00:30',
    height: 'calc(100vh - 280px)',
    // Sutun en az 100px; genis ekranda esit yayilir, dar ekranda yatay kaydirma.
    columnWidth: 'minmax(100px, 1fr)',
    datesAboveResources: true,
    resources: [],
    eventTimeFormat: { hour: '2-digit', minute: '2-digit' },
    slotLabelFormat: { hour: '2-digit', minute: '2-digit' },
    dayHeaderFormat: { weekday: 'short', day: 'numeric', month: 'short' },
    // v5: 'events' yalnizca DIZI kabul eder; fonksiyonla cekim eventSources ile.
    eventSources: [{ events: (info) => olaylariGetir(info) }],
    resourceLabelContent: kaynakEtiketi,
    eventContent: olayIcerik,
    eventClick: (info) => {
      const e = info.event.extendedProps || {};
      if (e.kapali) return panel().toast('Kapalı saat blokları eski panelden yönetilir (Faz 3).', 'bilgi');
      cekmeceAc(e.randevu_id || e.id);
    },
    loading: (y) => el('#tYuk').classList.toggle('acik', y),
    datesSet: (info) => {
      const d = info.start;
      const v = info.view.type;
      const bitis = new Date(info.end.getTime() - 1);
      el('#tTarih').textContent = v === 'resourceTimeGridWeek'
        ? `${d.toLocaleDateString('tr-TR', { day: 'numeric', month: 'short' })} – ${bitis.toLocaleDateString('tr-TR', { day: 'numeric', month: 'short', year: 'numeric' })}`
        : d.toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', weekday: 'long' });
    },
  });
}

function aracBagla() {
  el('#tGeri').onclick = () => durum.ec.prev();
  el('#tIleri').onclick = () => durum.ec.next();
  el('#tBugun').onclick = () => durum.ec.setOption('date', new Date());
  el('#tYenile').onclick = () => durum.ec.refetchEvents();
  el('#tKaynak').onchange = (e) => { durum.secili = e.target.value; kaynakFiltresiUygula(); };
  el('#tView').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-view]');
    if (!b) return;
    el('#tView').querySelectorAll('button').forEach((x) => x.classList.toggle('aktif', x === b));
    durum.ec.setOption('view', b.dataset.view);
  });
  if (window.flatpickr) {
    const fp = window.flatpickr(document.body.appendChild(Object.assign(document.createElement('input'), { type: 'hidden' })), {
      locale: 'tr',
      positionElement: el('#tTarih'),
      onChange: ([d]) => d && durum.ec.setOption('date', d),
    });
    el('#tTarih').onclick = () => fp.open();
  }
  el('#tCekmeceOv').onclick = cekmeceKapat;
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') cekmeceKapat(); });
}

/* ───────────────────────── Detay çekmecesi + durum aksiyonlari ───────────────────────── */

const DURUM_ETIKET = { 0: ['Onay bekliyor', 'turuncu'], 1: ['Onaylı', 'mavi'], 2: ['İptal', 'kirmizi'], 3: ['Reddedildi', 'kirmizi'] };

function cekmeceKapat() {
  el('#tCekmece').classList.remove('acik');
  el('#tCekmeceOv').classList.remove('acik');
}

async function cekmeceAc(randevuId) {
  const c = el('#tCekmece');
  c.innerHTML = '<div class="t-ck-yuk"><i class="bi bi-arrow-repeat"></i></div>';
  c.classList.add('acik');
  el('#tCekmeceOv').classList.add('acik');

  const r = await api('GET', `/randevu/${randevuId}`);
  if (!r.ok || !r.govde?.basarili) {
    c.innerHTML = `<div class="t-ck-hata">${esc(r.govde?.mesaj || 'Randevu yüklenemedi')}</div>`;
    return;
  }
  cekmeceCiz(r.govde.veri);
}

function cekmeceCiz(v) {
  const [dEt, dRenk] = DURUM_ETIKET[v.durum] || ['—', 'gri'];
  const geldi = v.randevuya_geldi === null || v.randevuya_geldi === undefined
    ? ['Beklemede', 'gri'] : (String(v.randevuya_geldi) === '1' ? ['Geldi', 'yesil'] : ['Gelmedi', 'kirmizi']);
  const aktif = v.durum < 2;
  const isaretli = v.randevuya_geldi !== null && v.randevuya_geldi !== undefined;

  const btn = (islem, ikon, etiket, sinif = '') => `<button class="t-aks ${sinif}" data-islem="${islem}"><i class="bi ${ikon}"></i> ${etiket}</button>`;
  const butonlar = [
    v.durum === 0 ? btn('onayla', 'bi-check2-all', 'Onayla', 'mavi') : '',
    aktif ? btn('geldi', 'bi-check-circle', 'Geldi', 'yesil') : '',
    aktif ? btn('gelmedi', 'bi-x-circle', 'Gelmedi', 'kirmizi') : '',
    aktif && isaretli ? btn('kaldir', 'bi-eraser', 'İşareti Kaldır') : '',
    aktif ? btn('iptal', 'bi-slash-circle', v.durum === 0 ? 'Reddet' : 'İptal Et', 'kirmizi-cizgi') : '',
  ].join('');

  el('#tCekmece').innerHTML = `
    <div class="t-ck-ust">
      <div>
        <div class="t-ck-musteri">${esc(v.musteri_ad || 'Müşteri')}</div>
        <div class="t-ck-tel"><i class="bi bi-telephone"></i> ${esc(v.telefon || '—')}</div>
      </div>
      <button class="t-ck-kapat" title="Kapat"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="t-ck-rozetler">
      <span class="t-rozet ${dRenk}">${dEt}</span>
      <span class="t-rozet ${geldi[1]}">${geldi[0]}</span>
      ${v.seans_var ? '<span class="t-rozet mor"><i class="bi bi-box-seam"></i> Paket seansı</span>' : ''}
    </div>
    <div class="t-ck-zaman"><i class="bi bi-clock"></i> ${esc(new Date(v.tarih + 'T00:00').toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', weekday: 'long' }))} · ${esc(String(v.saat || '').slice(0, 5))}</div>
    <div class="t-ck-kalemler">
      ${(v.hizmetler || []).map((h) => `
        <div class="t-ck-kalem">
          <div><b>${esc(h.hizmet_adi || 'Hizmet')}</b><span>${esc(h.personel_adi || '—')}</span></div>
          <div class="t-ck-kalem-sag"><span>${esc(String(h.saat || '').slice(0, 5))} · ${esc(h.sure_dk ?? '')} dk</span>${h.fiyat != null ? `<b>${Number(h.fiyat).toLocaleString('tr-TR', { minimumFractionDigits: 2 })} ₺</b>` : ''}</div>
        </div>`).join('')}
    </div>
    ${v.personel_notu ? `<div class="t-ck-not"><i class="bi bi-sticky"></i> ${esc(v.personel_notu)}</div>` : ''}
    <div class="t-ck-aks">${butonlar || '<div class="t-ck-bos">Bu randevuda yapılabilecek işlem yok.</div>'}</div>
    <div id="tOnizleme"></div>
    <a class="t-ck-eski" href="${ESKI_PANEL}/isletmeyonetim/randevular?sube=${salonId}" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Düzenle / Tahsilat (eski panel)</a>`;

  el('.t-ck-kapat').onclick = cekmeceKapat;
  el('#tCekmece').querySelectorAll('[data-islem]').forEach((b) => {
    b.onclick = () => islemYap(v, b.dataset.islem, {});
  });
}

const ISLEM_UC = {
  geldi: ['POST', 'geldi'], gelmedi: ['POST', 'gelmedi'], kaldir: ['DELETE', 'geldi-isareti'],
  iptal: ['POST', 'iptal'], onayla: ['POST', 'onayla'],
};

async function islemYap(v, islem, ek, dryRun = false) {
  if (!dryRun && islem === 'iptal') {
    const tamam = await panel().onay(`${v.musteri_ad || 'Bu'} randevusu ${v.durum === 0 ? 'reddedilsin' : 'iptal edilsin'} mi? Paket seansları bakiyeye geri döner.`,
      { baslik: v.durum === 0 ? 'Talebi reddet' : 'Randevuyu iptal et', tamam: 'Evet' });
    if (!tamam) return;
  }

  const [yontem, yol] = ISLEM_UC[islem];
  const govde = { dry_run: dryRun, ...(islem === 'geldi' ? { seans_secim_destek: true } : {}), ...ek };
  const r = await api(yontem, `/randevu/${v.id}/${yol}`, { govde });
  const g = r.govde;

  // Sunucu yazmasi kapali → ayni ucun DRY-RUN planini onizleme olarak goster.
  if (r.kod === 409 && !dryRun && /kapal/i.test(g?.mesaj || '')) {
    panel().toast('Sunucu yazması henüz kapalı — işlem önizleme olarak gösteriliyor.', 'bilgi', 4200);
    return islemYap(v, islem, ek, true);
  }
  if (!r.ok || !g?.basarili) {
    return panel().toast(g?.mesaj || `İşlem başarısız (${r.kod})`, 'hata');
  }

  const s = g.veri || {};

  // Yuk-tasiyan ara adimlar (eski mobil ile ayni kodlar)
  if (s.hatali === '2' || s.hatali === '3') {
    const alan = s.hatali === '2' ? 'dogrulama_kodu' : 'kvkk_kodu';
    const kod = await kodSor(s.mesaj, s.kod_gonderildi === false
      ? 'Önizleme modunda kod gönderilmez; daha önce gönderilmiş bir kod varsa girebilirsiniz.' : '');
    if (kod) return islemYap(v, islem, { ...ek, [alan]: kod }, dryRun);
    return;
  }
  if (s.hatali === '4') {
    const secim = await seansSec(s);
    if (secim) return islemYap(v, islem, { ...ek, ...secim }, dryRun);
    return;
  }
  if (s.seansDusmeOnayi) {
    const dus = await panel().onay(s.mesaj, { baslik: 'Seans düşümü', tamam: 'Evet, düş', iptal: 'Hayır', tehlike: false });
    return islemYap(v, islem, { ...ek, seans_dusumu: dus }, dryRun);
  }

  if (dryRun) return onizlemeCiz(islem, s);

  panel().toast(s.mesaj || 'İşlem tamamlandı', 'ok');
  durum.ec.refetchEvents();
  cekmeceAc(v.id);
}

function onizlemeCiz(islem, s) {
  const p = s.plan || {};
  const seans = p.seans || { guncelle: {}, sil: [] };
  const gnc = Object.entries(seans.guncelle || {});
  const satirlar = [
    p.randevu ? `Randevu: ${Object.entries(p.randevu).map(([k, d]) => `${k} → ${d === null ? 'boş' : d}`).join(', ')}` : '',
    gnc.length ? `Seans güncelle: ${gnc.map(([id, d]) => `#${id} (${Object.entries(d).map(([k, x]) => `${k}=${x === null ? 'boş' : x}`).join(', ')})`).join('; ')}` : '',
    seans.sil?.length ? `Seans sil (bakiyeye döner): ${seans.sil.map((x) => '#' + x).join(', ')}` : '',
    p.yetim_sil?.length ? `Yetim seans temizliği: ${p.yetim_sil.map((x) => '#' + x).join(', ')}` : '',
    p.dakika_dusum?.length ? `Dakika paketi düşümü: ${p.dakika_dusum.map((x) => `${x.dakika} dk`).join(', ')}` : '',
    p.dakika_iade?.length ? `Dakika paketi iadesi: ${p.dakika_iade.map((x) => `${x.dakika} dk`).join(', ')}` : '',
    s.bildirimler?.length ? `Kuyruğa yazılacak bildirim: ${s.bildirimler.join(', ')}` : '',
  ].filter(Boolean);
  el('#tOnizleme').innerHTML = `
    <div class="t-onizleme">
      <div class="t-onizleme-bas"><i class="bi bi-eye"></i> Önizleme — <b>${esc(islem)}</b> (hiçbir şey yazılmadı)</div>
      <ul>${satirlar.map((x) => `<li>${esc(x)}</li>`).join('') || '<li>Değişiklik yok</li>'}</ul>
    </div>`;
}

/* ───────────────────────── Küçük modallar ───────────────────────── */

function modal(icerik) {
  const ov = document.createElement('div');
  ov.className = 'rc-onay-overlay';
  ov.innerHTML = `<div class="rc-onay-kart t-modal" role="dialog" aria-modal="true">${icerik}</div>`;
  document.body.appendChild(ov);
  requestAnimationFrame(() => ov.classList.add('acik'));
  const kapat = () => { ov.classList.remove('acik'); setTimeout(() => ov.remove(), 220); };
  return { ov, kapat };
}

function kodSor(mesaj, not) {
  return new Promise((coz) => {
    const { ov, kapat } = modal(`
      <div class="rc-onay-ikon"><i class="bi bi-shield-lock"></i></div>
      <h3>Kod gerekli</h3><p>${esc(mesaj)}</p>
      ${not ? `<p class="t-modal-not">${esc(not)}</p>` : ''}
      <input class="t-kod" inputmode="numeric" autocomplete="one-time-code" placeholder="Kod">
      <div class="rc-onay-btns"><button class="rc-onay-iptal">Vazgeç</button><button class="rc-onay-tamam">Devam</button></div>`);
    const inp = el('.t-kod', ov);
    setTimeout(() => inp.focus(), 50);
    const bitir = (d) => { kapat(); coz(d); };
    el('.rc-onay-iptal', ov).onclick = () => bitir(null);
    el('.rc-onay-tamam', ov).onclick = () => bitir(inp.value.trim() || null);
    inp.onkeydown = (e) => { if (e.key === 'Enter') bitir(inp.value.trim() || null); };
  });
}

function seansSec(s) {
  return new Promise((coz) => {
    const { ov, kapat } = modal(`
      <div class="rc-onay-ikon"><i class="bi bi-box-seam"></i></div>
      <h3>Seans seçimi</h3>
      <p>${esc(s.musteri_adi || '')} — düşülecek seansları ve miktarlarını seçin.</p>
      <div class="t-seanslar">
        ${(s.seanslar || []).map((x) => `
          <label class="t-seans">
            <input type="checkbox" value="${x.id}" ${x.kalan_seans > 0 ? 'checked' : ''}>
            <div><b>${esc(x.hizmet_adi)}</b><span>${esc(x.paket_adi || 'Hizmet seansı')} · kalan ${x.kalan_seans}/${x.toplam_seans}</span></div>
            <input type="number" min="1" value="${x.default_miktar || 1}" data-miktar="${x.id}" title="Düşülecek miktar">
          </label>`).join('')}
      </div>
      <div class="rc-onay-btns"><button class="rc-onay-iptal">Vazgeç</button><button class="rc-onay-tamam">Geldi işaretle</button></div>`);
    const bitir = (d) => { kapat(); coz(d); };
    el('.rc-onay-iptal', ov).onclick = () => bitir(null);
    el('.rc-onay-tamam', ov).onclick = () => {
      const secilen = [...ov.querySelectorAll('.t-seans input[type=checkbox]:checked')].map((c) => parseInt(c.value, 10));
      const miktar = {};
      secilen.forEach((id) => { miktar[id] = Math.max(1, parseInt(el(`[data-miktar="${id}"]`, ov).value, 10) || 1); });
      bitir({ secilen_seans_idler: secilen, seans_miktarlari: miktar });
    };
  });
}

/* ───────────────────────── Başlat ───────────────────────── */

function baslat() {
  if (!salonId) {
    kok.innerHTML = '<div class="t-bos">İşletme seçilmedi (?sube=...).</div>';
    return;
  }
  if (!window.EventCalendar) {
    kok.innerHTML = '<div class="t-bos">Takvim bileşeni yüklenemedi. Sayfayı yenileyin.</div>';
    return;
  }
  window.rcPanel?.salonAyarla(salonId);
  iskeletCiz();
  takvimKur();
  aracBagla();
}

baslat();
