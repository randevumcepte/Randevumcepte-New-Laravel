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

// Takvim turu (kaynak tipi) — eski sistemdeki randevu_takvim_turu degerleri.
const TURLER = [
  { deger: '1', ad: 'Personel' },
  { deger: '0', ad: 'Hizmet Kategorisi' },
  { deger: '2', ad: 'Cihaz' },
  { deger: '3', ad: 'Oda' },
];
const turAnahtar = () => 'rc_takvim_turu_' + salonId;
function kayitliTur() {
  try { return localStorage.getItem(turAnahtar()) || ''; } catch { return ''; }
}

const durum = {
  takvimTuru: kayitliTur(), // '' = salonun varsayilani (sunucu cozer)
  kaynaklar: [],      // sunucudan gelen tum kaynaklar
  secili: '',         // personel/kaynak filtresi ('' = tumu)
  seciliRandevu: null, // cekmecede acik randevu (vurgu)
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
        <div class="t-ust-sag">
          <a class="t-klasik" href="${ESKI_PANEL}/isletmeyonetim/randevular?sube=${salonId}" title="Eski takvime dön"><i class="bi bi-clock-history"></i> Klasik takvim</a>
          <button class="t-yeni-btn" id="tYeni"><i class="bi bi-plus-lg"></i> Randevu Oluştur</button>
        </div>
      </div>

      <div class="t-arac">
        <select id="tTur" class="t-sec t-sec-tur" title="Takvim türü">
          ${TURLER.map((t) => `<option value="${t.deger}">${t.ad}</option>`).join('')}
        </select>
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
        <button class="t-yenile t-kapat-btn" id="tSaatKapat"><i class="bi bi-lock"></i> Saat Kapat</button>
      </div>

      <div class="t-lejant">
        <span><i class="t-nokta" style="background:#008000"></i> Geldi</span>
        <span><i class="t-nokta" style="background:#ff0000"></i> Gelmedi</span>
        <span><i class="t-nokta" style="background:#ffc107"></i> Onay bekliyor</span>
        <span><i class="t-nokta t-cizgili"></i> Kapalı saat</span>
        <span><i class="bi bi-cash-coin"></i> Ödendi</span>
        <span><i class="bi bi-arrow-repeat"></i> Seans telafisi</span>
        <span><i class="bi bi-bandaid"></i> Telafi randevusu</span>
      </div>

      <div class="t-kart"><div id="tTakvim"></div><div class="t-yukleniyor" id="tYuk"><i class="bi bi-arrow-repeat"></i></div></div>
    </div>
    <aside class="t-cekmece" id="tCekmece" aria-hidden="true"></aside>`;
}

/* ───────────────────────── Veri ───────────────────────── */

async function olaylariGetir(info) {
  const bitis = new Date(info.end.getTime() - 1); // end haric
  const r = await api('GET', '/randevu/takvim', {
    params: { salon_id: salonId, tarih1: tarihStr(info.start), tarih2: tarihStr(bitis), takvim_turu: durum.takvimTuru },
  });
  if (!r.ok || !r.govde?.basarili) {
    panel().toast(r.govde?.mesaj || `Takvim yüklenemedi (${r.kod})`, 'hata');
    return [];
  }
  const v = r.govde.veri;
  durum.sonVeri = v;
  // Sunucunun cozdugu tur (varsayilan dahil) seciciye yansisin.
  if (v.takvim_turu !== undefined) el('#tTur').value = String(v.takvim_turu);
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
      // Birlesik paket bari tek kaleme karsilik gelmez → tasinamaz/boyutlanamaz.
      editable: !(e.birlesik > 1) && d.durum !== '2',
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

// Takvim turune gore kaynak ikonu (personel → resim/harf). Bootstrap Icons 1.11.
const KAYNAK_IKON = {
  kategori: 'bi-scissors',   // hizmet kategorisi (Satis Takibi'ndeki Hizmet ikonu ile ayni)
  cihaz: 'bi-cpu',
  oda: 'bi-door-open',
};

function kaynakEtiketi(info) {
  const k = info.resource.extendedProps || {};
  const ad = String(k.name || info.resource.title || '');
  const r = renk(k.bgcolor);
  // Personel: harf altta, profil resmi varsa ustune biner (yuklenemezse gizlenir).
  // Kategori / cihaz / oda: ture uygun ikon (bu kaynaklarin resmi yok).
  const ikon = KAYNAK_IKON[k.tur];
  const src = !ikon && k.avatar ? (/^https?:/.test(k.avatar) ? k.avatar : ESKI_PANEL + '/' + String(k.avatar).replace(/^\/+/, '')) : '';
  const avatar = ikon
    ? `<span class="t-av-harf t-av-ikon"><i class="bi ${ikon}"></i></span>`
    : `<span class="t-av-harf">${esc(ad.charAt(0) || '?')}</span>`
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
    d.telafi ? '<i class="bi bi-arrow-repeat" title="Seans telafisi (hak saklandı)"></i>' : '',
    d.telafiRandevusu ? '<i class="bi bi-bandaid" title="Telafi randevusu (ücretsiz düzeltme)"></i>' : '',
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
    editable: true,
    selectable: true,
    eventDrop: (info) => tasimaYap(info),
    eventResize: (info) => tasimaYap(info),
    // Bos slota TEK TIK → randevu formu (tiklanan saat + sutunun kaynagi on dolu).
    // Surukleyerek aralik secimi (>=5px) → Randevu / Saat kapat secimi.
    dateClick: (info) => {
      if (info.allDay) return;
      randevuFormu({ start: info.date, kaynakId: info.resource?.id });
    },
    select: (info) => {
      durum.ec.unselect();
      slotSecimi({ start: info.start, end: info.end, kaynakId: info.resource?.id });
    },
    eventClick: (info) => {
      const e = info.event.extendedProps || {};
      if (e.kapali) return kapaliSaatPenceresi(e);
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
  el('#tSaatKapat').onclick = () => saatKapamaFormu({});
  el('#tYeni').onclick = () => randevuFormu({});
  el('#tKaynak').onchange = (e) => { durum.secili = e.target.value; kaynakFiltresiUygula(); };
  el('#tTur').onchange = (e) => {
    durum.takvimTuru = e.target.value;
    try { localStorage.setItem(turAnahtar(), durum.takvimTuru); } catch { /* ozel mod */ }
    durum.secili = '';
    durum.kaynaklar = [];
    durum.ec.setOption('resources', []); // eski turun sutunlari kalmasin
    durum.ec.refetchEvents();
  };
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
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') cekmeceKapat(); });
}

/* ───────────────────────── Detay çekmecesi + durum aksiyonlari ───────────────────────── */

const DURUM_ETIKET = { 0: ['Onay bekliyor', 'turuncu'], 1: ['Onaylı', 'mavi'], 2: ['İptal', 'kirmizi'], 3: ['Reddedildi', 'kirmizi'] };

// (2) Secili randevu vurgusu — cekmece acikken takvimde belirgin.
function seciliAyarla(randevuId) {
  durum.seciliRandevu = randevuId ? String(randevuId) : null;
  durum.ec?.setOption('eventClassNames', (info) => (
    durum.seciliRandevu && String(info.event.extendedProps?.randevu_id ?? info.event.extendedProps?.id) === durum.seciliRandevu
      ? ['t-secili'] : []
  ));
}

function cekmeceKapat() {
  el('#tCekmece').classList.remove('acik');
  seciliAyarla(null);
}

async function cekmeceAc(randevuId) {
  const c = el('#tCekmece');
  c.innerHTML = '<div class="t-ck-yuk"><i class="bi bi-arrow-repeat"></i></div>';
  c.classList.add('acik');
  seciliAyarla(randevuId);

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
  const geldiMi = isaretli && String(v.randevuya_geldi) === '1';
  const gelmediMi = isaretli && String(v.randevuya_geldi) === '0';

  const btn = (islem, ikon, etiket, sinif = '') => `<button class="t-aks ${sinif}" data-islem="${islem}"><i class="bi ${ikon}"></i> ${etiket}</button>`;
  const butonlar = [
    v.durum === 0 ? btn('onayla', 'bi-check2-all', 'Onayla', 'mavi') : '',
    aktif ? btn('duzenle', 'bi-pencil-square', 'Düzenle') : '',
    aktif && !geldiMi ? btn('geldi', 'bi-check-circle', 'Geldi', 'yesil') : '',
    aktif && !gelmediMi ? btn('gelmedi', 'bi-x-circle', 'Gelmedi', 'kirmizi') : '',
    aktif && isaretli ? btn('kaldir', 'bi-eraser', 'İşareti Kaldır') : '',
    aktif && v.seans_var && !v.seans_telafisi ? btn('seans-telafisi', 'bi-arrow-repeat', 'Seans Telafisi', 'mor') : '',
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
      ${v.seans_telafisi ? '<span class="t-rozet turuncu"><i class="bi bi-arrow-repeat"></i> Seans telafisi (hak saklı)</span>' : ''}
    </div>
    <div class="t-ck-zaman"><i class="bi bi-clock"></i> ${esc(new Date(v.tarih + 'T00:00').toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', weekday: 'long' }))} · ${esc(String(v.saat || '').slice(0, 5))}</div>
    <div class="t-ck-kalemler">
      ${(v.hizmetler || []).map((h) => `
        <div class="t-ck-kalem">
          <div class="t-ck-kalem-ust">
            <div><b>${esc(h.hizmet_adi || 'Hizmet')}</b><span>${esc(h.personel_adi || '—')}</span></div>
            <div class="t-ck-kalem-sag"><span>${esc(String(h.saat || '').slice(0, 5))} · ${esc(h.sure_dk ?? '')} dk</span>${h.fiyat != null ? `<b>${Number(h.fiyat).toLocaleString('tr-TR', { minimumFractionDigits: 2 })} ₺</b>` : ''}</div>
          </div>
          ${h.telafi_kaynak_rh_id ? '<span class="t-rozet mor"><i class="bi bi-bandaid"></i> Telafi randevusu (ücretsiz)</span>'
            : h.telafi_devir_yapildi ? '<span class="t-rozet gri"><i class="bi bi-check2"></i> Telafi randevusu verildi</span>'
            : (aktif ? `<button class="t-kalem-telafi" data-telafi-rh="${h.id}"><i class="bi bi-calendar-plus"></i> Telafi randevusu oluştur</button>` : '')}
        </div>`).join('')}
    </div>
    ${v.personel_notu ? `<div class="t-ck-not"><i class="bi bi-sticky"></i> ${esc(v.personel_notu)}</div>` : ''}
    <div class="t-ck-aks">${butonlar || '<div class="t-ck-bos">Bu randevuda yapılabilecek işlem yok.</div>'}</div>
    <div id="tOnizleme"></div>
    <a class="t-ck-eski" href="${ESKI_PANEL}/isletmeyonetim/randevular?sube=${salonId}" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Tahsilat (eski panel)</a>`;

  el('.t-ck-kapat').onclick = cekmeceKapat;
  el('#tCekmece').querySelectorAll('[data-islem]').forEach((b) => {
    b.onclick = () => (b.dataset.islem === 'duzenle' ? randevuFormu({ randevuId: v.id }) : islemYap(v, b.dataset.islem, {}));
  });
  el('#tCekmece').querySelectorAll('[data-telafi-rh]').forEach((b) => {
    const h = (v.hizmetler || []).find((x) => String(x.id) === b.dataset.telafiRh);
    b.onclick = () => telafiFormu(v, h);
  });
}

const ISLEM_UC = {
  geldi: ['POST', 'geldi'], gelmedi: ['POST', 'gelmedi'], kaldir: ['DELETE', 'geldi-isareti'],
  iptal: ['POST', 'iptal'], onayla: ['POST', 'onayla'], 'seans-telafisi': ['POST', 'seans-telafisi'],
};

async function islemYap(v, islem, ek, dryRun = false) {
  if (!dryRun && islem === 'seans-telafisi') {
    const tamam = await panel().onay('Bu randevunun paket seansları telafi hakkına alınsın mı? Seans düşmez; hak bir sonraki randevuya bağlanır. (Ücretsiz düzeltme randevusu için hizmetin altındaki "Telafi randevusu oluştur"u kullanın.)',
      { baslik: 'Seans telafisi', tamam: 'Evet', tehlike: false });
    if (!tamam) return;
  }
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
  if (s.hata) return panel().toast(s.mesaj || 'İşlem yapılamadı', 'hata');

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

/* ─────────── Telafi RANDEVUSU (A tipi: ucretsiz duzeltme + prim devri) ───────────
   Seans telafisinden (paket seansini telafi hakkina alma) AYRI bir islemdir. */

let _telafiSecenek = null;
async function telafiSecenekleri() {
  if (_telafiSecenek) return _telafiSecenek;
  const r = await api('GET', '/randevu/telafi-secenekler', { params: { salon_id: salonId } });
  if (!r.ok || !r.govde?.basarili) throw new Error(r.govde?.mesaj || 'Seçenekler yüklenemedi');
  return (_telafiSecenek = r.govde.veri);
}

async function telafiFormu(v, h) {
  if (!h) return;
  let sec;
  try { sec = await telafiSecenekleri(); } catch (e) { return panel().toast(e.message, 'hata'); }
  const opt = (liste, secili, bos) => (bos ? `<option value="">${bos}</option>` : '')
    + (liste || []).map((x) => `<option value="${x.id}" ${String(x.id) === String(secili ?? '') ? 'selected' : ''}>${esc(x.ad)}</option>`).join('');

  const { ov, kapat } = modal(`
    <div class="rc-onay-ikon"><i class="bi bi-calendar-plus"></i></div>
    <h3>Telafi randevusu</h3>
    <p>${esc(h.hizmet_adi || 'Hizmet')} için <b>ücretsiz düzeltme/garanti</b> randevusu. Personel değişirse prim yeni personele devredilir. (Paket seansı telafisi değildir.)</p>
    <div class="t-form">
      <label>Tarih<input id="tfTarih" placeholder="Tarih seçin" readonly></label>
      <label>Saat<input id="tfSaat" placeholder="Saat" readonly></label>
      <label class="t-form-gen">Personel<select id="tfPersonel">${opt(sec.personeller, h.personel_id)}</select></label>
      <label>Oda<select id="tfOda">${opt(sec.odalar, h.oda_id, '—')}</select></label>
      <label>Cihaz<select id="tfCihaz">${opt(sec.cihazlar, h.cihaz_id, '—')}</select></label>
      <label>Süre (dk)<input id="tfSure" type="number" min="5" step="5" value="${esc(h.sure_dk || 30)}"></label>
      <label class="t-form-gen">Not<input id="tfNot" maxlength="200" placeholder="İsteğe bağlı"></label>
    </div>
    <div id="tfOnizleme"></div>
    <div class="rc-onay-btns"><button class="rc-onay-iptal">Vazgeç</button><button class="rc-onay-tamam">Oluştur</button></div>`);
  ov.querySelector('.rc-onay-kart').classList.add('t-modal-genis');

  if (window.flatpickr) {
    window.flatpickr(el('#tfTarih', ov), { locale: 'tr', dateFormat: 'Y-m-d', altInput: true, altFormat: 'j F Y, l', minDate: 'today' });
    window.flatpickr(el('#tfSaat', ov), { enableTime: true, noCalendar: true, dateFormat: 'H:i', time_24hr: true, minuteIncrement: 5 });
  }
  el('.rc-onay-iptal', ov).onclick = kapat;
  el('.rc-onay-tamam', ov).onclick = async () => {
    const govde = {
      tarih: el('#tfTarih', ov).value, saat: el('#tfSaat', ov).value,
      personel_id: el('#tfPersonel', ov).value, oda_id: el('#tfOda', ov).value || null,
      cihaz_id: el('#tfCihaz', ov).value || null, sure_dk: el('#tfSure', ov).value, not: el('#tfNot', ov).value,
    };
    if (!govde.tarih || !govde.saat) return panel().toast('Tarih ve saat seçin.', 'hata');
    const sonuc = await telafiGonder(h.id, govde);
    if (!sonuc) return;
    if (sonuc.yazildi) {
      kapat();
      panel().toast(sonuc.mesaj || 'Telafi randevusu oluşturuldu', 'ok');
      if (sonuc.prim?.uyari) panel().toast(sonuc.prim.uyari, 'bilgi', 5000);
      durum.ec.refetchEvents();
      cekmeceAc(v.id);
    } else {
      el('#tfOnizleme', ov).innerHTML = telafiOnizleme(sonuc, sec);
    }
  };
}

async function telafiGonder(rhId, govde, dryRun = false) {
  const r = await api('POST', `/randevu/kalem/${rhId}/telafi-randevusu`, { govde: { ...govde, dry_run: dryRun } });
  if (r.kod === 409 && !dryRun && /kapal/i.test(r.govde?.mesaj || '')) {
    panel().toast('Sunucu yazması henüz kapalı — telafi önizleme olarak gösteriliyor.', 'bilgi', 4200);
    return telafiGonder(rhId, govde, true);
  }
  if (!r.ok || !r.govde?.basarili) {
    panel().toast(r.govde?.mesaj || `İşlem başarısız (${r.kod})`, 'hata');
    return null;
  }
  return r.govde.veri;
}

function telafiOnizleme(s, sec) {
  const y = s.plan?.yeni_randevu || {};
  const p = s.plan?.prim || {};
  const ad = (liste, id) => (liste || []).find((x) => String(x.id) === String(id))?.ad || '—';
  const para = (t) => `${Math.abs(Number(t)).toLocaleString('tr-TR', { minimumFractionDigits: 2 })} ₺`;
  const satirlar = [
    `Yeni randevu: ${y.tarih} ${y.saat}–${y.saat_bitis} · ${ad(sec.personeller, y.personel_id)} · ücretsiz`,
    p.satis_devri?.length ? `Satış kalemi yeni personele devredilir: ${p.satis_devri.length} kalem` : '',
    p.paket_devri?.length ? `Paket seansı yeni personele devredilir: ${p.paket_devri.length} seans` : '',
    ...(p.geri_devir || []).map((g) => `Ödenmiş prim geri devri: ${ad(sec.personeller, g.personel_id)} −${para(g.tutar)}`),
    !p.satis_devri?.length && !p.paket_devri?.length && !p.ozet?.uyari ? 'Personel aynı — prim devri yok' : '',
    p.ozet?.uyari || '',
  ].filter(Boolean);
  return `<div class="t-onizleme"><div class="t-onizleme-bas"><i class="bi bi-eye"></i> Önizleme — hiçbir şey yazılmadı</div>
    <ul>${satirlar.map((x) => `<li>${esc(x)}</li>`).join('')}</ul></div>`;
}

/* ───────────────────────── Randevu formu (oluştur / düzenle) ───────────────────────── */

function slotSecimi(secim) {
  const { ov, kapat } = modal(`
    <div class="rc-onay-ikon"><i class="bi bi-calendar-plus"></i></div>
    <h3>${esc(saatStr(secim.start))}–${esc(saatStr(secim.end))}</h3>
    <p>${esc(kaynakBul(secim.kaynakId)?.name || '')}</p>
    <div class="rc-onay-btns"><button class="rc-onay-iptal" data-s="kapat"><i class="bi bi-lock"></i> Saat kapat</button><button class="rc-onay-tamam" data-s="randevu"><i class="bi bi-plus-lg"></i> Randevu oluştur</button></div>`);
  ov.querySelectorAll('[data-s]').forEach((b) => {
    b.onclick = () => { kapat(); (b.dataset.s === 'randevu' ? randevuFormu : saatKapamaFormu)(secim); };
  });
}

async function randevuFormu({ randevuId, start, kaynakId }) {
  const r = await api('GET', '/randevu/form-verisi', { params: { salon_id: salonId, randevu_id: randevuId } });
  if (!r.ok || !r.govde?.basarili) return panel().toast(r.govde?.mesaj || 'Form yüklenemedi', 'hata');
  const fv = r.govde.veri;
  const mevcut = fv.randevu;
  const harita = fv.haritalar || {};
  const hizmetBul = (id) => fv.hizmetler.find((h) => String(h.hizmet_id) === String(id));
  const izinli = (map, anahtar, hizmetId) => (map?.[anahtar] || []).map(String).includes(String(hizmetId));

  // On doldurma: duzenleme → mevcut; secilen slot → tarih/saat + kaynak
  const kaynak = kaynakBul(kaynakId);
  const ilkSatir = { hizmet_id: '', personel_id: kaynak?.tur === 'personel' ? kaynak.id : '', oda_id: kaynak?.tur === 'oda' ? kaynak.id : '',
    cihaz_id: kaynak?.tur === 'cihaz' ? kaynak.id : '', sure_dk: '', fiyat: '', birlestir: '' };
  const f = {
    musteri: mevcut ? { id: mevcut.user_id, ad: mevcut.musteri_ad, telefon: mevcut.telefon } : null,
    tarih: mevcut?.randevu_tarihi || (start ? tarihStr(start) : tarihStr(durum.ec.getView().currentStart || new Date())),
    saat: mevcut?.randevu_saati || (start ? saatStr(start) : ''),
    durum: mevcut ? String(mevcut.durum) : '1',
    notlar: mevcut?.notlar || '',
    satirlar: mevcut ? mevcut.hizmetler.map((h) => ({ ...h })) : [ilkSatir],
    yardimcilar: mevcut?.yardimcipersoneller || [],
  };

  const { ov, kapat } = modal(`
    <h3 class="t-rf-baslik"><i class="bi bi-calendar-plus"></i> ${mevcut ? 'Randevuyu düzenle' : 'Yeni randevu'}</h3>
    <div class="t-rf">
      <label class="t-rf-musteri">Müşteri
        <div id="rfMusteri"></div>
      </label>
      <div class="t-rf-ust">
        <label>Tarih<input id="rfTarih" value="${esc(f.tarih)}" readonly></label>
        <label>Saat<input id="rfSaat" value="${esc(f.saat)}" placeholder="Saat" readonly></label>
        <label>Durum<select id="rfDurum"><option value="1">Onaylı</option><option value="0">Onay bekliyor</option></select></label>
      </div>
      <div class="t-rf-satirlar" id="rfSatirlar"></div>
      <button class="t-kalem-telafi" id="rfEkle"><i class="bi bi-plus-lg"></i> Hizmet ekle</button>
      ${mevcut ? '' : `<div class="t-rf-ust">
        <label>Tekrar<select id="rfSiklik">${TEKRARLAR.map((t) => `<option value="${t.deger}">${t.ad}</option>`).join('')}</select></label>
        <label>Tekrar sayısı<input id="rfSayi" type="number" min="1" max="52" value="1"></label>
      </div>`}
      <label>Not<input id="rfNot" maxlength="500" value="${esc(f.notlar)}" placeholder="Personel notu (isteğe bağlı)"></label>
    </div>
    <div id="rfSonuc"></div>
    <div class="rc-onay-btns"><button class="rc-onay-iptal">Vazgeç</button><button class="rc-onay-tamam" id="rfKaydet">Kaydet</button></div>`);
  ov.querySelector('.rc-onay-kart').classList.add('t-modal-xl');
  el('#rfDurum', ov).value = f.durum;
  if (window.flatpickr) {
    window.flatpickr(el('#rfTarih', ov), { locale: 'tr', dateFormat: 'Y-m-d', altInput: true, altFormat: 'j F Y, l' });
    window.flatpickr(el('#rfSaat', ov), { enableTime: true, noCalendar: true, dateFormat: 'H:i', time_24hr: true, minuteIncrement: 5 });
  }

  // ── Musteri secimi (arama) ──
  const musteriCiz = () => {
    const k = el('#rfMusteri', ov);
    if (f.musteri) {
      k.innerHTML = `<div class="t-rf-secili"><i class="bi bi-person-check"></i><b>${esc(f.musteri.ad)}</b><span>${esc(f.musteri.telefon || '')}</span>
        ${mevcut ? '' : '<button type="button" class="t-rf-degistir">Değiştir</button>'}</div>`;
      const d = el('.t-rf-degistir', k);
      if (d) d.onclick = () => { f.musteri = null; musteriCiz(); };
      return;
    }
    k.innerHTML = '<input id="rfAra" placeholder="Ad veya telefon ile arayın (en az 2 karakter)" autocomplete="off"><div class="t-rf-sonuclar" id="rfSonuclar"></div>';
    let zaman;
    el('#rfAra', k).oninput = (e) => {
      clearTimeout(zaman);
      const q = e.target.value.trim();
      if (q.length < 2) { el('#rfSonuclar', k).innerHTML = ''; return; }
      zaman = setTimeout(async () => {
        const s = await api('GET', '/randevu/musteri-ara', { params: { salon_id: salonId, q } });
        const liste = s.ok && s.govde?.basarili ? s.govde.veri : [];
        el('#rfSonuclar', k).innerHTML = liste.length
          ? liste.map((m) => `<button type="button" data-id="${m.id}"><b>${esc(m.ad)}</b><span>${esc(m.telefon || '')}</span></button>`).join('')
          : '<div class="t-rf-bos">Sonuç yok</div>';
        el('#rfSonuclar', k).querySelectorAll('[data-id]').forEach((b) => {
          b.onclick = () => { f.musteri = liste.find((m) => String(m.id) === b.dataset.id); musteriCiz(); };
        });
      }, 300);
    };
    setTimeout(() => el('#rfAra', k)?.focus(), 60);
  };
  musteriCiz();

  // ── Hizmet satirlari ──
  const secenek = (liste, secili, bos) => (bos !== undefined ? `<option value="">${bos}</option>` : '')
    + liste.map((x) => `<option value="${x.id}" ${String(x.id) === String(secili ?? '') ? 'selected' : ''}>${esc(x.ad)}</option>`).join('');
  const filtrele = (liste, kosul) => { const s = liste.filter(kosul); return s.length ? s : liste; }; // eslesme yoksa hepsi

  const satirlariCiz = () => {
    el('#rfSatirlar', ov).innerHTML = f.satirlar.map((s, i) => {
      const hz = s.hizmet_id;
      const personeller = hz ? filtrele(fv.personeller, (p) => izinli(harita.personel_hizmet_map, p.id, hz)) : fv.personeller;
      const cihazlar = hz ? filtrele(fv.cihazlar, (c) => izinli(harita.cihaz_hizmet_map, c.id, hz)) : fv.cihazlar;
      const odalar = hz ? filtrele(fv.odalar, (o) => izinli(harita.oda_hizmet_map, o.id, hz) || String(o.personel_id) === String(s.personel_id)) : fv.odalar;
      return `<div class="t-rf-satir" data-i="${i}">
        <label class="t-rf-hizmet">Hizmet<select data-k="hizmet_id"><option value="">Hizmet seçin</option>${fv.hizmetler.map((h) => `<option value="${h.hizmet_id}" ${String(h.hizmet_id) === String(hz) ? 'selected' : ''}>${esc(h.ad)}${h.aktif ? '' : ' (pasif)'}</option>`).join('')}</select></label>
        <label>Personel<select data-k="personel_id">${secenek(personeller, s.personel_id, '—')}</select></label>
        ${fv.odalar.length ? `<label>Oda<select data-k="oda_id">${secenek(odalar, s.oda_id, 'Otomatik')}</select></label>` : ''}
        ${fv.cihazlar.length ? `<label>Cihaz<select data-k="cihaz_id">${secenek(cihazlar, s.cihaz_id, '—')}</select></label>` : ''}
        <label class="t-rf-kucuk">Süre (dk)<input type="number" min="5" step="5" data-k="sure_dk" value="${esc(s.sure_dk)}"></label>
        <label class="t-rf-kucuk">Fiyat (₺)<input type="number" min="0" step="0.01" data-k="fiyat" value="${esc(s.fiyat)}"></label>
        <label class="t-rf-onay" title="Sonraki hizmet bununla aynı anda başlasın"><input type="checkbox" data-k="birlestir" ${s.birlestir === '1' ? 'checked' : ''}> Sonraki ile aynı anda</label>
        ${f.satirlar.length > 1 ? '<button type="button" class="t-rf-sil" title="Hizmeti çıkar"><i class="bi bi-trash"></i></button>' : ''}
      </div>`;
    }).join('');
    el('#rfSatirlar', ov).querySelectorAll('.t-rf-satir').forEach((sat) => {
      const i = +sat.dataset.i;
      sat.querySelectorAll('[data-k]').forEach((inp) => {
        inp.onchange = () => {
          const k = inp.dataset.k;
          f.satirlar[i][k] = inp.type === 'checkbox' ? (inp.checked ? '1' : '') : inp.value;
          if (k === 'hizmet_id') { // varsayilan sure/fiyat
            const h = hizmetBul(inp.value);
            if (h) { f.satirlar[i].sure_dk = h.sure_dk ?? f.satirlar[i].sure_dk; f.satirlar[i].fiyat = h.fiyat ?? f.satirlar[i].fiyat; }
          }
          if (k === 'hizmet_id' || k === 'personel_id') satirlariCiz();
        };
      });
      const sil = el('.t-rf-sil', sat);
      if (sil) sil.onclick = () => { f.satirlar.splice(i, 1); f.yardimcilar = []; satirlariCiz(); };
    });
  };
  satirlariCiz();
  el('#rfEkle', ov).onclick = () => { f.satirlar.push({ ...ilkSatir, personel_id: f.satirlar.at(-1)?.personel_id || ilkSatir.personel_id }); satirlariCiz(); };

  el('.rc-onay-iptal', ov).onclick = kapat;
  const kaydet = async (cakismaGec = false) => {
    if (!f.musteri) return panel().toast('Müşteri seçin.', 'hata');
    const govde = {
      salon_id: salonId, user_id: f.musteri.id,
      randevu_tarihi: el('#rfTarih', ov).value, randevu_saati: el('#rfSaat', ov).value,
      durum: el('#rfDurum', ov).value, notlar: el('#rfNot', ov).value,
      hizmetler: f.satirlar.map((s) => ({ ...s, oda_id: s.oda_id || null, cihaz_id: s.cihaz_id || null, personel_id: s.personel_id || null })),
      yardimcipersoneller: f.yardimcilar,
      cakisanrandevuekle: cakismaGec ? '1' : '',
    };
    if (!mevcut) {
      const siklik = el('#rfSiklik', ov).value;
      Object.assign(govde, { tekrarlayan: !!siklik, tekrar_sikligi: siklik || null, tekrar_sayisi: siklik ? parseInt(el('#rfSayi', ov).value, 10) || 1 : 1 });
    }
    if (!govde.randevu_saati) return panel().toast('Saat seçin.', 'hata');
    if (govde.hizmetler.some((s) => !s.hizmet_id || !s.sure_dk)) return panel().toast('Her satırda hizmet ve süre seçin.', 'hata');

    const sonuc = await yazVeyaOnizle(mevcut ? 'PATCH' : 'POST', mevcut ? `/randevu/${mevcut.randevu_id}` : '/randevu', govde);
    if (!sonuc) return;
    const kutu = el('#rfSonuc', ov);
    if (sonuc.cakismavar === '1') {
      kutu.innerHTML = `<div class="t-rf-cakisma ${sonuc.sert ? 'sert' : ''}"><b><i class="bi bi-exclamation-triangle"></i> ${sonuc.sert ? 'Kaydedilemez — oda dolu' : 'Çakışma uyarısı'}</b>
        <div>${esc(String(sonuc.cakisanunsurlar)).replace(/\\n/g, '<br>')}</div>
        ${sonuc.sert ? '' : '<button type="button" class="t-rf-yine">Yine de kaydet</button>'}</div>`;
      const yine = el('.t-rf-yine', kutu);
      if (yine) yine.onclick = () => kaydet(true);
      return;
    }
    if (sonuc.yazildi) {
      kapat();
      panel().toast(mevcut ? 'Randevu güncellendi' : 'Randevu oluşturuldu', 'ok');
      durum.ec.refetchEvents();
      if (mevcut) cekmeceAc(mevcut.randevu_id);
      return;
    }
    kutu.innerHTML = randevuOnizleme(sonuc.plan, fv);
  };
  el('#rfKaydet', ov).onclick = () => kaydet(false);
}

function randevuOnizleme(plan, fv) {
  const ad = (liste, id, alan = 'id') => (liste || []).find((x) => String(x[alan]) === String(id))?.ad;
  const seansMetni = (s) => !s ? '' : ({
    havuz_guncelle: ' · paket seansı yeni saate taşınır',
    telafi_bagla: ' · bekleyen seans telafisi bağlanır (seans düşmez)',
    yeni_seans: ` · paket seansı düşülür (seans #${s.seans_no})`,
  })[s.islem] || '';
  const satirlar = [];
  (plan?.gunler || []).forEach((g) => g.kalemler.forEach((k) => satirlar.push(
    `${g.tarih} ${k.saat}–${k.saat_bitis} · ${ad(fv.hizmetler, k.hizmet_id, 'hizmet_id') || 'Hizmet'} · ${ad(fv.personeller, k.personel_id) || '—'}`
    + (k.oda_id ? ` · ${ad(fv.odalar, k.oda_id) || 'Oda'}${k.oda_otomatik ? ' (otomatik)' : ''}` : '')
    + seansMetni(k.seans) + (k.adisyon_fiyat ? ` · adisyon fiyatı ${k.adisyon_fiyat.eski} → ${k.adisyon_fiyat.yeni}` : ''),
  )));
  if (plan?.seans_serbest?.length) satirlar.push(`Çıkarılan hizmetin ${plan.seans_serbest.length} seansı serbest bırakılır`);
  return `<div class="t-onizleme"><div class="t-onizleme-bas"><i class="bi bi-eye"></i> Önizleme — hiçbir şey yazılmadı</div>
    <ul>${satirlar.map((x) => `<li>${esc(x)}</li>`).join('')}</ul></div>`;
}

/* ───────────────────────── Sürükle-bırak / boyutlandırma ───────────────────────── */

const dtStr = (d) => `${tarihStr(d)} ${pad(d.getHours())}:${pad(d.getMinutes())}:00`;
const saatStr = (d) => `${pad(d.getHours())}:${pad(d.getMinutes())}`;
const TUR_KOD = { personel: 1, cihaz: 2, oda: 3, kategori: 0 };
const kaynakBul = (id) => durum.kaynaklar.find((k) => String(k.id) === String(id));

async function tasimaYap(info) {
  const ev = info.event;
  const e = ev.extendedProps || {};
  // Gun degisikligi surukle-birak ile yapilmaz (randevu duzenleme ile).
  if (tarihStr(ev.start) !== tarihStr(info.oldEvent.start) || tarihStr(new Date(ev.end.getTime() - 1)) !== tarihStr(ev.start)) {
    info.revert();
    return panel().toast('Sürükle-bırak ile gün değiştirilemez; günü değiştirmek için randevuyu düzenleyin.', 'bilgi', 4200);
  }
  const kaynakId = info.newResource?.id ?? ev.resourceIds?.[0];
  const kaynak = kaynakBul(kaynakId);
  const govde = {
    baslangic: dtStr(ev.start), bitis: dtStr(ev.end),
    takvim_turu: TUR_KOD[kaynak?.tur] ?? '', resource_id: info.newResource ? kaynakId : '',
  };
  const ozet = `${e.musteri || 'Kayıt'} → ${saatStr(ev.start)}–${saatStr(ev.end)}${info.newResource ? ' · ' + (kaynak?.name || '') : ''}`;

  let r = await api('PATCH', `/randevu/kalem/${e.rh_id}/tasi`, { govde: { ...govde, dry_run: false } });
  if (r.kod === 409 && /kapal/i.test(r.govde?.mesaj || '')) {
    r = await api('PATCH', `/randevu/kalem/${e.rh_id}/tasi`, { govde: { ...govde, dry_run: true } });
    info.revert();
    if (r.ok && r.govde?.basarili) return panel().toast(`Önizleme (yazılmadı): ${ozet}`, 'bilgi', 4500);
    return panel().toast(r.govde?.mesaj || `Taşınamadı (${r.kod})`, 'hata');
  }
  if (!r.ok || !r.govde?.basarili) {
    info.revert();
    return panel().toast(r.govde?.mesaj || `Taşınamadı (${r.kod})`, 'hata');
  }
  panel().toast(`Güncellendi: ${ozet}`, 'ok');
  durum.ec.refetchEvents();
}

/* ───────────────────────── Saat kapama ───────────────────────── */

const TEKRARLAR = [
  { deger: '', ad: 'Tekrar yok' }, { deger: '+1 day', ad: 'Her gün' }, { deger: '+1 week', ad: 'Her hafta' },
  { deger: '+2 weeks', ad: '2 haftada bir' }, { deger: '+1 month', ad: 'Her ay' },
];
const TUR_KOLON = { personel: 'personel_id', cihaz: 'cihaz_id', oda: 'oda_id' };

function saatKapamaFormu({ start, end, kaynakId }) {
  const kaynaklar = durum.kaynaklar.filter((k) => TUR_KOLON[k.tur]);
  if (!kaynaklar.length) {
    return panel().toast('Hizmet kategorisi görünümünde saat kapatılamaz; personel, oda veya cihaz görünümüne geçin.', 'bilgi', 4500);
  }
  const gun = start ? tarihStr(start) : tarihStr(durum.ec.getView().currentStart || new Date());
  const { ov, kapat } = modal(`
    <div class="rc-onay-ikon"><i class="bi bi-lock"></i></div>
    <h3>Saat kapat</h3>
    <p>Seçilen kaynak bu aralıkta randevuya kapatılır.</p>
    <div class="t-form">
      <label class="t-form-gen">Kaynak<select id="skKaynak">${kaynaklar.map((k) => `<option value="${k.id}" ${String(k.id) === String(kaynakId ?? '') ? 'selected' : ''}>${esc(k.name)}</option>`).join('')}</select></label>
      <label class="t-form-gen">Tarih<input id="skTarih" value="${gun}" readonly></label>
      <label>Başlangıç<input id="skBas" value="${start ? saatStr(start) : ''}" placeholder="Çalışma başı" readonly></label>
      <label>Bitiş<input id="skBit" value="${end ? saatStr(end) : ''}" placeholder="Çalışma sonu" readonly></label>
      <label>Tekrar<select id="skSiklik">${TEKRARLAR.map((t) => `<option value="${t.deger}">${t.ad}</option>`).join('')}</select></label>
      <label>Tekrar sayısı<input id="skSayi" type="number" min="1" max="52" value="1"></label>
      <label class="t-form-gen">Not<input id="skNot" maxlength="200" placeholder="İsteğe bağlı (örn. izin, eğitim)"></label>
    </div>
    <div id="skOnizleme"></div>
    <div class="rc-onay-btns"><button class="rc-onay-iptal">Vazgeç</button><button class="rc-onay-tamam">Kapat</button></div>`);
  ov.querySelector('.rc-onay-kart').classList.add('t-modal-genis');
  if (window.flatpickr) {
    window.flatpickr(el('#skTarih', ov), { locale: 'tr', dateFormat: 'Y-m-d', altInput: true, altFormat: 'j F Y, l' });
    ['#skBas', '#skBit'].forEach((s) => window.flatpickr(el(s, ov), { enableTime: true, noCalendar: true, dateFormat: 'H:i', time_24hr: true, minuteIncrement: 5 }));
  }
  el('.rc-onay-iptal', ov).onclick = kapat;
  el('.rc-onay-tamam', ov).onclick = async () => {
    const kaynak = kaynakBul(el('#skKaynak', ov).value);
    const siklik = el('#skSiklik', ov).value;
    const govde = {
      salon_id: salonId, tarih: el('#skTarih', ov).value,
      saat: el('#skBas', ov).value || null, saat_bitis: el('#skBit', ov).value || null,
      [TUR_KOLON[kaynak.tur]]: kaynak.id, not: el('#skNot', ov).value,
      tekrar_sikligi: siklik || null, tekrar_sayisi: siklik ? parseInt(el('#skSayi', ov).value, 10) || 1 : 1,
    };
    const s = await yazVeyaOnizle('POST', '/randevu/saat-kapama', govde);
    if (!s) return;
    if (s.yazildi) {
      kapat();
      panel().toast(s.mesaj || 'Saat kapatıldı', 'ok');
      durum.ec.refetchEvents();
    } else {
      const b = s.plan?.bloklar || [];
      el('#skOnizleme', ov).innerHTML = `<div class="t-onizleme"><div class="t-onizleme-bas"><i class="bi bi-eye"></i> Önizleme — ${b.length} blok (yazılmadı)</div>
        <ul>${b.slice(0, 8).map((x) => `<li>${esc(x.tarih)} · ${esc(x.saat.slice(0, 5))}–${esc(x.saat_bitis.slice(0, 5))}</li>`).join('')}${b.length > 8 ? `<li>… +${b.length - 8}</li>` : ''}</ul></div>`;
    }
  };
}

function kapaliSaatPenceresi(e) {
  const { ov, kapat } = modal(`
    <div class="rc-onay-ikon"><i class="bi bi-lock"></i></div>
    <h3>Kapalı saat</h3>
    <p>${esc(String(e.start).slice(11, 16))}–${esc(String(e.end).slice(11, 16))}${e.personelId ? ' · ' + esc(kaynakBul(e.resourceId)?.name || '') : ''}</p>
    <div id="kpOnizleme"></div>
    <div class="rc-onay-btns"><button class="rc-onay-iptal">Kapat</button><button class="rc-onay-tamam tehlike">Kapamayı kaldır</button></div>`);
  el('.rc-onay-iptal', ov).onclick = kapat;
  el('.rc-onay-tamam', ov).onclick = async () => {
    const s = await yazVeyaOnizle('DELETE', `/randevu/saat-kapama/${e.randevu_id || e.id}`, {});
    if (!s) return;
    if (s.yazildi) {
      kapat();
      panel().toast('Saat kapama kaldırıldı', 'ok');
      durum.ec.refetchEvents();
    } else {
      el('#kpOnizleme', ov).innerHTML = '<div class="t-onizleme"><div class="t-onizleme-bas"><i class="bi bi-eye"></i> Önizleme — kapama kaldırılacak (yazılmadı)</div></div>';
    }
  };
}

/** Yazmayi dene; sunucu yazmasi kapaliysa (409) ayni ucun DRY-RUN sonucunu don. */
async function yazVeyaOnizle(yontem, yol, govde) {
  let r = await api(yontem, yol, { govde: { ...govde, dry_run: false } });
  if (r.kod === 409 && /kapal/i.test(r.govde?.mesaj || '')) {
    panel().toast('Sunucu yazması henüz kapalı — önizleme gösteriliyor.', 'bilgi', 3500);
    r = await api(yontem, yol, { govde: { ...govde, dry_run: true } });
  }
  if (!r.ok || !r.govde?.basarili) {
    panel().toast(r.govde?.mesaj || `İşlem başarısız (${r.kod})`, 'hata');
    return null;
  }
  return r.govde.veri;
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
