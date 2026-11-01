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
const normalTR = (x) => String(x ?? '').toLocaleLowerCase('tr').replace(/ı/g, 'i').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
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

// Bos slot: tek tik VE surukleyerek secim ayni formu acar (Randevu / Saat Kapama sekmeli,
// eski web modali ile ayni). Secimde bitis saati kapama sekmesine tasinir.
function slotSecimi(secim) { return randevuFormu(secim); }

// Saat secimi eski web modali gibi 15 dk adimli LISTE (07:00–23:45); listede olmayan
// (surukle-sec ile gelen) saat de eklenir. Liste arama-select ile aranabilir olur.
function saatSecenekleri(secili, bos = '') {
  const l = [];
  for (let t = 7 * 60; t < 24 * 60; t += 15) l.push(`${pad(Math.floor(t / 60))}:${pad(t % 60)}`);
  if (secili && !l.includes(secili)) { l.push(secili); l.sort(); }
  return (bos ? `<option value="">${bos}</option>` : '') + l.map((x) => `<option value="${x}" ${x === secili ? 'selected' : ''}>${x}</option>`).join('');
}
function tarihSecici(girdi) {
  if (!window.flatpickr) return;
  window.flatpickr(girdi, {
    locale: 'tr', dateFormat: 'Y-m-d', altInput: true, altFormat: 'j F Y, l', altInputClass: girdi.className,
    monthSelectorType: 'static', disableMobile: true, position: 'auto left',
  });
}
const dkEkle = (saat, dk) => { const [h, m] = saat.split(':').map(Number); const t = Math.min(h * 60 + m + dk, 23 * 60 + 59); return `${pad(Math.floor(t / 60))}:${pad(t % 60)}`; };

const paraTL = (n) => `${Number(n || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ₺`;

/**
 * Randevu formu — eski web "randevu-ekle-modal-v2" akisinin birebir karsiligi:
 *  - Satirda personel HER ZAMAN; cihaz takvim turu 1/3 degilse; oda turu 1/2 degilse.
 *  - Personel/cihaz/oda secimi HIZMET listesini daraltir (izinli listelerin kesisimi;
 *    o tipe hic atanmamis hizmetler her zaman gecer; kesisim bossa tum hizmetler).
 *  - Oda secimi PERSONEL listesini daraltir (oda_personel_map).
 *  - Satirda birden cok hizmet secilebilir → her biri ayri kalem, satir icinde sirali.
 *  - "Ustteki ile birlestir" (2. satirdan itibaren) → paralel baslangic.
 *  - Elle girilen sure/fiyat satir TOPLAMIDIR; hizmetlere katalog oraninda dagitilir.
 *  - Isletme panelinden olusan randevu ONAYLIDIR (durum alani yok).
 */
async function randevuFormu({ randevuId, start, end, kaynakId, sekme = 'randevu' }) {
  const r = await api('GET', '/randevu/form-verisi', { params: { salon_id: salonId, randevu_id: randevuId } });
  if (!r.ok || !r.govde?.basarili) return panel().toast(r.govde?.mesaj || 'Form yüklenemedi', 'hata');
  const fv = r.govde.veri;
  const mevcut = fv.randevu;
  const turu = Number(fv.takvim_turu || 0);
  const odaVar = ![1, 2].includes(turu) && fv.odalar.length > 0;
  const cihazVar = ![1, 3].includes(turu) && fv.cihazlar.length > 0;
  const harita = fv.haritalar || {};
  const hizmetBul = (id) => fv.hizmetler.find((h) => String(h.hizmet_id) === String(id));

  // ── Eski v2FilterHizmetler ──
  const birlesim = (m) => { const s = new Set(); Object.values(m || {}).forEach((l) => l.forEach((v) => s.add(String(v)))); return s; };
  const atanmis = { p: birlesim(harita.personel_hizmet_map), c: birlesim(harita.cihaz_hizmet_map), o: birlesim(harita.oda_hizmet_map) };
  const izinliHizmetler = (s) => {
    const tum = fv.hizmetler;
    const kisitlar = [];
    const ekle = (map, id, at) => {
      const l = id ? (map?.[id] || []) : [];
      if (l.length) kisitlar.push(new Set([...l.map(String), ...tum.map((h) => String(h.hizmet_id)).filter((x) => !at.has(x))]));
    };
    ekle(harita.personel_hizmet_map, s.personel_id, atanmis.p);
    if (cihazVar) ekle(harita.cihaz_hizmet_map, s.cihaz_id, atanmis.c);
    if (odaVar) ekle(harita.oda_hizmet_map, s.oda_id, atanmis.o);
    if (!kisitlar.length) return tum;
    const kes = tum.filter((h) => kisitlar.every((k) => k.has(String(h.hizmet_id))));
    return kes.length ? kes : tum;
  };
  // ── Eski v2RefreshPersonelByOda ──
  const izinliPersoneller = (s) => {
    const l = odaVar && s.oda_id ? (harita.oda_personel_map?.[s.oda_id] || []).map(String) : [];
    return l.length ? fv.personeller.filter((p) => l.includes(String(p.id))) : fv.personeller;
  };

  // ── Form durumu ──
  const kaynak = kaynakBul(kaynakId);
  const yeniSatir = () => ({
    hizmetler: [], personel_id: kaynak?.tur === 'personel' ? String(kaynak.id) : '',
    oda_id: kaynak?.tur === 'oda' ? String(kaynak.id) : '', cihaz_id: kaynak?.tur === 'cihaz' ? String(kaynak.id) : '',
    sure: '', fiyat: '', elleSure: false, elleFiyat: false, ustBirlestir: false,
  });
  const f = {
    sekme: 'randevu',
    musteri: mevcut ? { id: mevcut.user_id, ad: mevcut.musteri_ad, telefon: mevcut.telefon } : null,
    // Duzenleme: her kalem bir satir (mevcut sure/fiyat elle girilmis sayilir → korunur).
    satirlar: mevcut
      ? mevcut.hizmetler.map((h, i, a) => ({
        hizmetler: [String(h.hizmet_id)], personel_id: h.personel_id ? String(h.personel_id) : '',
        oda_id: h.oda_id ? String(h.oda_id) : '', cihaz_id: h.cihaz_id ? String(h.cihaz_id) : '',
        sure: String(h.sure_dk), fiyat: String(h.fiyat), elleSure: true, elleFiyat: true,
        ustBirlestir: i > 0 && a[i - 1].birlestir === '1',
      }))
      : [yeniSatir()],
    yardimcilar: mevcut?.yardimcipersoneller || [],
  };
  const satirToplam = (s) => {
    const kat = s.hizmetler.map(hizmetBul).filter(Boolean);
    return {
      sure: s.elleSure ? Number(s.sure || 0) : kat.reduce((t, h) => t + Number(h.sure_dk || 0), 0),
      fiyat: s.elleFiyat ? Number(String(s.fiyat).replace(',', '.') || 0) : kat.reduce((t, h) => t + Number(h.fiyat || 0), 0),
    };
  };

  const gun = mevcut?.randevu_tarihi || (start ? tarihStr(start) : tarihStr(durum.ec.getView().currentStart || new Date()));
  const saat = mevcut?.randevu_saati || (start ? saatStr(start) : '');
  const ov = document.createElement('div');
  ov.className = 'rc-onay-overlay';
  ov.innerHTML = `
    <div class="rf-kart" role="dialog" aria-modal="true">
      <div class="rf-ust">
        <div class="rf-ust-sol"><span class="rf-ust-ikon"><i class="bi bi-calendar-plus"></i></span>
          <div><h3>${mevcut ? 'Randevuyu Düzenle' : 'Yeni Randevu'}</h3><small>${esc(new Date(gun + 'T00:00').toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', weekday: 'long' }))}${saat ? ' · ' + esc(saat) : ''}</small></div></div>
        <button class="rf-kapat" title="Kapat"><i class="bi bi-x-lg"></i></button>
      </div>
      ${mevcut ? '' : `<div class="rf-sekmeler">
        <button class="rf-sekme" data-sekme="randevu"><i class="bi bi-calendar-check"></i> Randevu</button>
        <button class="rf-sekme" data-sekme="kapama"><i class="bi bi-lock"></i> Saat Kapama</button></div>`}
      <div class="rf-govde" data-pane="randevu">
        <div class="rf-alan"><label class="rf-etiket"><i class="bi bi-person"></i> ${esc(fv.musteri_etiket)}</label><div id="rfMusteri"></div></div>
        <div class="rf-iki">
          <div class="rf-alan"><label class="rf-etiket"><i class="bi bi-calendar3"></i> Tarih</label><input class="rf-girdi" id="rfTarih" value="${esc(gun)}" readonly></div>
          <div class="rf-alan"><label class="rf-etiket"><i class="bi bi-clock"></i> Saat</label><select class="rf-girdi" id="rfSaat">${saatSecenekleri(saat, 'Saat seçin')}</select></div>
        </div>
        <div class="rf-bolum"><span>Hizmetler</span></div>
        <div id="rfSatirlar"></div>
        <button type="button" class="rf-ekle" id="rfEkle"><i class="bi bi-plus-lg"></i> Hizmet ekle</button>
        <div class="rf-ozet" id="rfOzet"></div>
        ${mevcut ? '' : `<label class="rf-onay-kutu"><input type="checkbox" id="rfTekrar"> Tekrarlayan randevu</label>
        <div class="rf-iki rf-tekrar" id="rfTekrarAlan" hidden>
          <div class="rf-alan"><label class="rf-etiket">Sıklık</label><select class="rf-girdi" id="rfSiklik">${TEKRARLAR.filter((t) => t.deger).map((t) => `<option value="${t.deger}">${t.ad}</option>`).join('')}</select></div>
          <div class="rf-alan"><label class="rf-etiket">Tekrar sayısı</label><input class="rf-girdi" id="rfSayi" type="number" min="2" max="52" value="4"></div>
        </div>`}
        <div class="rf-alan"><label class="rf-etiket"><i class="bi bi-sticky"></i> Not</label><textarea class="rf-girdi rf-not" id="rfNot" rows="2" maxlength="500" placeholder="Personel notu (isteğe bağlı)">${esc(mevcut?.notlar || '')}</textarea></div>
        <div id="rfSonuc"></div>
      </div>
      ${mevcut ? '' : kapamaPaneli()}
      <div class="rf-alt"><button class="rf-vazgec">Vazgeç</button><button class="rf-kaydet" id="rfKaydet"></button></div>
    </div>`;
  document.body.appendChild(ov);
  requestAnimationFrame(() => ov.classList.add('acik'));
  const kapat = () => { hzListeKapat(); ov.classList.remove('acik'); setTimeout(() => ov.remove(), 220); };
  ov.querySelector('.rf-kapat').onclick = kapat;
  ov.querySelector('.rf-vazgec').onclick = kapat;
  const sekmeAc = (ad) => {
    ov.querySelectorAll('.rf-sekme').forEach((b) => b.classList.toggle('aktif', b.dataset.sekme === ad));
    ov.querySelectorAll('[data-pane]').forEach((p) => { p.hidden = p.dataset.pane !== ad; });
    ov.querySelector('.rf-kart').classList.toggle('rf-kapama-modu', ad === 'kapama');
    el('#rfKaydet', ov).innerHTML = ad === 'kapama' ? '<i class="bi bi-lock"></i> Saati Kapat' : `<i class="bi bi-check2"></i> ${mevcut ? 'Kaydet' : 'Randevu Oluştur'}`;
    el('#rfKaydet', ov).dataset.sekme = ad;
  };
  ov.querySelectorAll('.rf-sekme').forEach((b) => { b.onclick = () => sekmeAc(b.dataset.sekme); });
  tarihSecici(el('#rfTarih', ov));
  const tekrar = el('#rfTekrar', ov);
  if (tekrar) tekrar.onchange = () => { el('#rfTekrarAlan', ov).hidden = !tekrar.checked; };

  // ── Musteri arama ──
  const musteriCiz = () => {
    const k = el('#rfMusteri', ov);
    if (f.musteri) {
      k.innerHTML = `<div class="rf-musteri-secili"><span class="rf-avatar">${esc((f.musteri.ad || '?').charAt(0))}</span><div><b>${esc(f.musteri.ad)}</b><small>${esc(f.musteri.telefon || '')}</small></div>
        ${mevcut ? '' : '<button type="button" class="rf-degistir">Değiştir</button>'}</div>`;
      const d = el('.rf-degistir', k);
      if (d) d.onclick = () => { f.musteri = null; musteriCiz(); };
      return;
    }
    k.innerHTML = `<div class="rf-arama"><i class="bi bi-search"></i><input id="rfAra" placeholder="Ad veya telefon ile arayın" autocomplete="off"></div><div class="rf-sonuclar" id="rfSonuclar"></div>`;
    let zaman;
    el('#rfAra', k).oninput = (e) => {
      clearTimeout(zaman);
      const q = e.target.value.trim();
      if (q.length < 2) { el('#rfSonuclar', k).innerHTML = ''; return; }
      zaman = setTimeout(async () => {
        const s = await api('GET', '/randevu/musteri-ara', { params: { salon_id: salonId, q } });
        const liste = s.ok && s.govde?.basarili ? s.govde.veri : [];
        el('#rfSonuclar', k).innerHTML = liste.length
          ? liste.map((m) => `<button type="button" data-id="${m.id}"><span class="rf-avatar">${esc((m.ad || '?').charAt(0))}</span><b>${esc(m.ad)}</b><small>${esc(m.telefon || '')}</small></button>`).join('')
          : '<div class="rf-bos">Sonuç yok</div>';
        el('#rfSonuclar', k).querySelectorAll('[data-id]').forEach((b) => {
          b.onclick = () => { f.musteri = liste.find((m) => String(m.id) === b.dataset.id); musteriCiz(); };
        });
      }, 300);
    };
    setTimeout(() => el('#rfAra', k)?.focus(), 80);
  };
  musteriCiz();

  // ── Hizmet coklu secici (satir basina; aranabilir) ──
  let hzListe = null;
  function hzListeKapat() { if (hzListe) { hzListe.remove(); hzListe = null; document.removeEventListener('mousedown', hzDis, true); } }
  function hzDis(e) { if (hzListe && !hzListe.contains(e.target) && !e.target.closest('.rf-hz')) hzListeKapat(); }
  const hzListeAc = (i, girdi) => {
    hzListeKapat();
    const s = f.satirlar[i];
    hzListe = document.createElement('div');
    hzListe.className = 'rs-panel rf-hz-panel';
    document.body.appendChild(hzListe);
    const ciz = () => {
      const q = normalTR(girdi.value.trim());
      const liste = izinliHizmetler(s).filter((h) => !s.hizmetler.includes(String(h.hizmet_id)) && (!q || normalTR(h.ad).includes(q)));
      hzListe.innerHTML = `<div class="rs-liste">${liste.length ? liste.map((h, k) => `<div class="rs-sec${k === 0 ? ' aktif' : ''}" data-id="${h.hizmet_id}"><div>${esc(h.ad)}${h.aktif ? '' : ' <small>(pasif)</small>'}</div><small class="rf-hz-meta">${[h.sure_dk ? h.sure_dk + ' dk' : '', h.fiyat ? paraTL(h.fiyat) : ''].filter(Boolean).join(' · ')}</small></div>`).join('') : '<div class="rs-yok">Hizmet bulunamadı</div>'}</div>`;
      const r2 = girdi.getBoundingClientRect();
      Object.assign(hzListe.style, { left: r2.left + 'px', top: (r2.bottom + 4) + 'px', minWidth: Math.max(r2.width, 260) + 'px' });
    };
    hzListe.addEventListener('mousedown', (e) => e.preventDefault());
    hzListe.addEventListener('click', (e) => {
      const d = e.target.closest('.rs-sec');
      if (!d) return;
      s.hizmetler.push(d.dataset.id);
      girdi.value = '';
      satirlariCiz(i);
    });
    girdi.oninput = ciz;
    girdi.onkeydown = (e) => {
      const aktifEl = hzListe?.querySelector('.rs-sec.aktif');
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        const tum = [...hzListe.querySelectorAll('.rs-sec')];
        let k = tum.indexOf(aktifEl) + (e.key === 'ArrowDown' ? 1 : -1);
        k = Math.max(0, Math.min(k, tum.length - 1));
        tum.forEach((x) => x.classList.remove('aktif'));
        tum[k]?.classList.add('aktif'); tum[k]?.scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter') { e.preventDefault(); aktifEl?.click(); }
      else if (e.key === 'Escape') { e.stopPropagation(); hzListeKapat(); }
      else if (e.key === 'Backspace' && !girdi.value && s.hizmetler.length) { s.hizmetler.pop(); satirlariCiz(i); }
    };
    document.addEventListener('mousedown', hzDis, true);
    ciz();
  };

  // ── Satirlar ──
  const secenek = (liste, secili, bos) => `<option value="">${bos}</option>`
    + liste.map((x) => `<option value="${x.id}" ${String(x.id) === String(secili ?? '') ? 'selected' : ''}>${esc(x.ad)}</option>`).join('');
  function satirlariCiz(odakSatir = null) {
    hzListeKapat();
    el('#rfSatirlar', ov).innerHTML = f.satirlar.map((s, i) => {
      const t = satirToplam(s);
      const hz = s.hizmetler.map((id) => `<span class="rf-chip">${esc(hizmetBul(id)?.ad || 'Hizmet')}<button type="button" data-cikar="${id}" title="Çıkar">×</button></span>`).join('');
      return `<div class="rf-satir" data-i="${i}">
        <div class="rf-satir-bas">
          <span class="rf-no">Hizmet #${i + 1}</span>
          <span class="rf-meta">${t.sure ? t.sure + ' dk' : ''}${t.sure && t.fiyat ? ' · ' : ''}${t.fiyat ? paraTL(t.fiyat) : ''}</span>
          ${i > 0 ? `<label class="rf-birlestir" title="Bu hizmet üstteki ile aynı saatte (paralel) başlasın"><input type="checkbox" data-k="ustBirlestir" ${s.ustBirlestir ? 'checked' : ''}> Üsttekiyle birleştir</label>` : '<span class="rf-bosluk"></span>'}
          <button type="button" class="rf-sil" title="Satırı sil" ${f.satirlar.length > 1 ? '' : 'disabled'}><i class="bi bi-trash"></i></button>
        </div>
        <div class="rf-satir-1">
          <div class="rf-hz">${hz}<input class="rf-hz-ara" placeholder="${s.hizmetler.length ? 'Hizmet ekle…' : 'Hizmet seçin…'}" autocomplete="off"></div>
          <input class="rf-girdi" type="number" min="5" step="5" data-k="sure" value="${esc(s.elleSure ? s.sure : (t.sure || ''))}" placeholder="Süre (dk)">
          <input class="rf-girdi" type="text" inputmode="decimal" data-k="fiyat" value="${esc(s.elleFiyat ? s.fiyat : (t.fiyat || ''))}" placeholder="Fiyat (₺)">
        </div>
        <div class="rf-satir-2">
          <select class="rf-girdi" data-k="personel_id">${secenek(izinliPersoneller(s), s.personel_id, 'Personel…')}</select>
          ${cihazVar ? `<select class="rf-girdi" data-k="cihaz_id">${secenek(fv.cihazlar, s.cihaz_id, 'Cihaz…')}</select>` : ''}
          ${odaVar ? `<select class="rf-girdi" data-k="oda_id">${secenek(fv.odalar, s.oda_id, 'Oda… (otomatik)')}</select>` : ''}
        </div>
      </div>`;
    }).join('');

    el('#rfSatirlar', ov).querySelectorAll('.rf-satir').forEach((sat) => {
      const i = +sat.dataset.i;
      const s = f.satirlar[i];
      sat.querySelectorAll('select[data-k]').forEach((sel) => {
        sel.onchange = () => {
          s[sel.dataset.k] = sel.value;
          // Oda personeli daraltir; secili personel listede kalmadiysa temizlenir.
          if (sel.dataset.k === 'oda_id' && s.personel_id && !izinliPersoneller(s).some((p) => String(p.id) === s.personel_id)) s.personel_id = '';
          // Kaynak hizmeti daraltir; listede kalmayan secili hizmetler duser.
          const izin = new Set(izinliHizmetler(s).map((h) => String(h.hizmet_id)));
          s.hizmetler = s.hizmetler.filter((id) => izin.has(id));
          satirlariCiz();
        };
      });
      sat.querySelectorAll('input[data-k="sure"], input[data-k="fiyat"]').forEach((inp) => {
        inp.oninput = () => {
          const k = inp.dataset.k;
          s[k] = inp.value;
          s[k === 'sure' ? 'elleSure' : 'elleFiyat'] = inp.value.trim() !== '';
          ozetCiz();
          const t = satirToplam(s);
          sat.querySelector('.rf-meta').textContent = [t.sure ? t.sure + ' dk' : '', t.fiyat ? paraTL(t.fiyat) : ''].filter(Boolean).join(' · ');
        };
      });
      const bir = sat.querySelector('[data-k="ustBirlestir"]');
      if (bir) bir.onchange = () => { s.ustBirlestir = bir.checked; ozetCiz(); };
      sat.querySelectorAll('[data-cikar]').forEach((b) => {
        b.onclick = () => { s.hizmetler = s.hizmetler.filter((x) => x !== b.dataset.cikar); satirlariCiz(i); };
      });
      const ara = sat.querySelector('.rf-hz-ara');
      ara.onfocus = () => hzListeAc(i, ara);
      sat.querySelector('.rf-hz').onclick = (e) => { if (!e.target.closest('[data-cikar]')) ara.focus(); };
      sat.querySelector('.rf-sil').onclick = () => { f.satirlar.splice(i, 1); f.yardimcilar = []; satirlariCiz(); };
    });
    ozetCiz();
    if (odakSatir !== null) el(`.rf-satir[data-i="${odakSatir}"] .rf-hz-ara`, ov)?.focus();
  }
  // Ozet: toplam hizmet / sure / bitis / fiyat (paralel satirlar sureyi uzatmaz).
  function ozetCiz() {
    let adet = 0; let fiyat = 0; let bitis = 0; let bas = 0;
    f.satirlar.forEach((s, i) => {
      const t = satirToplam(s);
      adet += s.hizmetler.length; fiyat += t.fiyat;
      const satirBas = i > 0 && s.ustBirlestir ? bas : bitis;
      bas = satirBas; bitis = Math.max(bitis, satirBas + t.sure);
    });
    const s0 = el('#rfSaat', ov).value;
    let aralik = '';
    if (s0 && bitis) { const [h, m] = s0.split(':').map(Number); const t2 = h * 60 + m + bitis; aralik = ` · ${s0}–${pad(Math.floor(t2 / 60) % 24)}:${pad(t2 % 60)}`; }
    el('#rfOzet', ov).innerHTML = adet ? `<i class="bi bi-receipt"></i> ${adet} hizmet · ${bitis} dk${aralik}<b>${paraTL(fiyat)}</b>` : '';
  }
  el('#rfSaat', ov).addEventListener('change', ozetCiz);
  satirlariCiz();
  el('#rfEkle', ov).onclick = () => {
    const onceki = f.satirlar.at(-1);
    f.satirlar.push({ ...yeniSatir(), personel_id: onceki?.personel_id || '', oda_id: onceki?.oda_id || '', cihaz_id: onceki?.cihaz_id || '' });
    satirlariCiz(f.satirlar.length - 1);
  };

  // ── Gonderim: satirlar → kalemler (Flutter editoru ile ayni govde) ──
  const dagit = (toplam, agirliklar, ondalik) => {
    const tw = agirliklar.reduce((a, b) => a + b, 0);
    const k = 10 ** ondalik;
    const parca = agirliklar.map((w) => Math.floor((tw > 0 ? toplam * w / tw : toplam / agirliklar.length) * k) / k);
    parca[parca.length - 1] = Math.round((toplam - parca.slice(0, -1).reduce((a, b) => a + b, 0)) * k) / k;
    return parca;
  };
  const kalemler = () => {
    const out = [];
    f.satirlar.forEach((s, i) => {
      const kat = s.hizmetler.map(hizmetBul);
      const sureler = s.elleSure ? dagit(Number(s.sure || 0), kat.map((h) => Number(h?.sure_dk || 0)), 0) : kat.map((h) => Number(h?.sure_dk || 0));
      const fiyatlar = s.elleFiyat ? dagit(Number(String(s.fiyat).replace(',', '.') || 0), kat.map((h) => Number(h?.fiyat || 0)), 2) : kat.map((h) => Number(h?.fiyat || 0));
      if (i > 0 && s.ustBirlestir && out.length) out[out.length - 1].birlestir = '1'; // onceki kalem ile paralel
      s.hizmetler.forEach((id, j) => out.push({
        hizmet_id: id, personel_id: s.personel_id || null, oda_id: odaVar ? (s.oda_id || null) : null,
        cihaz_id: cihazVar ? (s.cihaz_id || null) : null, sure_dk: sureler[j], fiyat: fiyatlar[j], birlestir: '',
      }));
    });
    return out;
  };

  const kaydet = async (cakismaGec = false) => {
    const hatalar = [];
    if (!f.musteri) hatalar.push(`${fv.musteri_etiket} seçin`);
    if (!el('#rfSaat', ov).value) hatalar.push('Saat seçin');
    if (f.satirlar.some((s) => !s.hizmetler.length)) hatalar.push('Her satırda hizmet seçin');
    // Eski kural: personel takviminde personel zorunlu; digerlerinde personel/cihaz/oda'dan biri.
    if (f.satirlar.some((s) => (turu === 1 ? !s.personel_id : !(s.personel_id || (cihazVar && s.cihaz_id) || (odaVar && s.oda_id))))) {
      hatalar.push(turu === 1 ? 'Her satıra personel seçin' : 'Her satıra personel, cihaz veya oda seçin');
    }
    const k = hatalar.length ? [] : kalemler();
    if (k.some((x) => !x.sure_dk || x.sure_dk < 5)) hatalar.push('Her hizmetin süresi en az 5 dk olmalı');
    if (hatalar.length) return panel().toast(hatalar.join(' · '), 'hata', 4500);

    const govde = {
      salon_id: salonId, user_id: f.musteri.id,
      randevu_tarihi: el('#rfTarih', ov).value, randevu_saati: el('#rfSaat', ov).value,
      notlar: el('#rfNot', ov).value, hizmetler: k, yardimcipersoneller: f.yardimcilar,
      cakisanrandevuekle: cakismaGec ? '1' : '',
    };
    if (!mevcut && tekrar?.checked) {
      Object.assign(govde, { tekrarlayan: true, tekrar_sikligi: el('#rfSiklik', ov).value, tekrar_sayisi: parseInt(el('#rfSayi', ov).value, 10) || 2 });
    }
    const btn = el('#rfKaydet', ov); btn.disabled = true;
    const sonuc = await yazVeyaOnizle(mevcut ? 'PATCH' : 'POST', mevcut ? `/randevu/${mevcut.randevu_id}` : '/randevu', govde);
    btn.disabled = false;
    if (!sonuc) return;
    const kutu = el('#rfSonuc', ov);
    if (sonuc.cakismavar === '1') {
      kutu.innerHTML = `<div class="t-rf-cakisma ${sonuc.sert ? 'sert' : ''}"><b><i class="bi bi-exclamation-triangle"></i> ${sonuc.sert ? 'Kaydedilemez — oda dolu' : 'Çakışma uyarısı'}</b>
        <div>${esc(String(sonuc.cakisanunsurlar)).replace(/\\n/g, '<br>')}</div>
        ${sonuc.sert ? '' : '<button type="button" class="t-rf-yine">Yine de kaydet</button>'}</div>`;
      const yine = el('.t-rf-yine', kutu);
      if (yine) yine.onclick = () => kaydet(true);
      kutu.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
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
    kutu.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  };
  // ── Saat kapama sekmesi ──
  function kapamaPaneli() {
    const kaynaklar = durum.kaynaklar.filter((k) => TUR_KOLON[k.tur]);
    if (!kaynaklar.length) {
      return '<div class="rf-govde" data-pane="kapama" hidden><div class="rf-bilgi"><i class="bi bi-info-circle"></i> Hizmet kategorisi görünümünde saat kapatılamaz; personel, oda veya cihaz görünümüne geçin.</div></div>';
    }
    const kBas = start ? saatStr(start) : '';
    const kBit = end ? saatStr(end) : (kBas ? dkEkle(kBas, 60) : '');
    return `<div class="rf-govde" data-pane="kapama" hidden>
      <div class="rf-alan"><label class="rf-etiket"><i class="bi bi-person-badge"></i> Kaynak</label>
        <select class="rf-girdi" id="kpKaynak">${kaynaklar.map((k) => `<option value="${k.id}" ${String(k.id) === String(kaynakId ?? '') ? 'selected' : ''}>${esc(k.name)}</option>`).join('')}</select></div>
      <div class="rf-uc">
        <div class="rf-alan"><label class="rf-etiket"><i class="bi bi-calendar3"></i> Tarih</label><input class="rf-girdi" id="kpTarih" value="${esc(gun)}" readonly></div>
        <div class="rf-alan"><label class="rf-etiket">Başlangıç</label><select class="rf-girdi" id="kpBas">${saatSecenekleri(kBas, 'Seçin')}</select></div>
        <div class="rf-alan"><label class="rf-etiket">Bitiş</label><select class="rf-girdi" id="kpBit">${saatSecenekleri(kBit, 'Seçin')}</select></div>
      </div>
      <label class="rf-anahtar"><span><i class="bi bi-sun"></i> Tüm gün <small>(çalışma saatleri boyunca)</small></span><input type="checkbox" id="kpTumGun"><i class="rf-anahtar-top"></i></label>
      <label class="rf-onay-kutu"><input type="checkbox" id="kpTekrar"> Tekrarla</label>
      <div class="rf-iki" id="kpTekrarAlan" hidden>
        <div class="rf-alan"><label class="rf-etiket">Sıklık</label><select class="rf-girdi" id="kpSiklik">${TEKRARLAR.filter((t) => t.deger).map((t) => `<option value="${t.deger}">${t.ad}</option>`).join('')}</select></div>
        <div class="rf-alan"><label class="rf-etiket">Tekrar sayısı</label><input class="rf-girdi" id="kpSayi" type="number" min="2" max="52" value="4"></div>
      </div>
      <div class="rf-alan"><label class="rf-etiket"><i class="bi bi-sticky"></i> Not</label><textarea class="rf-girdi rf-not" id="kpNot" rows="2" maxlength="200" placeholder="Örn. izin, eğitim"></textarea></div>
      <div id="kpSonuc"></div>
    </div>`;
  }
  if (el('#kpTarih', ov)) {
    tarihSecici(el('#kpTarih', ov));
    const tumGun = el('#kpTumGun', ov);
    tumGun.onchange = () => { ['#kpBas', '#kpBit'].forEach((x) => { el(x, ov).disabled = tumGun.checked; }); };
    el('#kpTekrar', ov).onchange = (e) => { el('#kpTekrarAlan', ov).hidden = !e.target.checked; };
    el('#kpBas', ov).addEventListener('change', (e) => {
      const b = el('#kpBit', ov);
      if (e.target.value && (!b.value || b.value <= e.target.value)) { b.innerHTML = saatSecenekleri(dkEkle(e.target.value, 60), 'Seçin'); }
    });
  }
  const kapamaKaydet = async () => {
    const kaynak = kaynakBul(el('#kpKaynak', ov)?.value);
    if (!kaynak) return panel().toast('Kaynak seçin.', 'hata');
    const tum = el('#kpTumGun', ov).checked;
    const bas = el('#kpBas', ov).value; const bit = el('#kpBit', ov).value;
    if (!tum && (!bas || !bit)) return panel().toast('Başlangıç ve bitiş saati seçin ya da "Tüm gün"ü açın.', 'hata');
    if (!tum && bit <= bas) return panel().toast('Bitiş saati başlangıçtan sonra olmalı.', 'hata');
    const tekrarla = el('#kpTekrar', ov).checked;
    const govde = {
      salon_id: salonId, tarih: el('#kpTarih', ov).value, saat: tum ? null : bas, saat_bitis: tum ? null : bit,
      [TUR_KOLON[kaynak.tur]]: kaynak.id, not: el('#kpNot', ov).value,
      tekrar_sikligi: tekrarla ? el('#kpSiklik', ov).value : null, tekrar_sayisi: tekrarla ? (parseInt(el('#kpSayi', ov).value, 10) || 2) : 1,
    };
    const btn = el('#rfKaydet', ov); btn.disabled = true;
    const sn = await yazVeyaOnizle('POST', '/randevu/saat-kapama', govde);
    btn.disabled = false;
    if (!sn) return;
    if (sn.yazildi) { kapat(); panel().toast(sn.mesaj || 'Saat kapatıldı', 'ok'); durum.ec.refetchEvents(); return; }
    const b = sn.plan?.bloklar || [];
    el('#kpSonuc', ov).innerHTML = `<div class="t-onizleme"><div class="t-onizleme-bas"><i class="bi bi-eye"></i> Önizleme — ${b.length} blok (yazılmadı)</div>
      <ul>${b.slice(0, 8).map((x) => `<li>${esc(x.tarih)} · ${esc(x.saat.slice(0, 5))}–${esc(x.saat_bitis.slice(0, 5))}</li>`).join('')}${b.length > 8 ? `<li>… +${b.length - 8}</li>` : ''}</ul></div>`;
  };

  el('#rfKaydet', ov).onclick = () => (el('#rfKaydet', ov).dataset.sekme === 'kapama' ? kapamaKaydet() : kaydet(false));
  sekmeAc(mevcut ? 'randevu' : sekme);
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

// Saat kapama: randevu formunun "Saat Kapama" sekmesi (eski web modali gibi tek modal).
function saatKapamaFormu(secim) { return randevuFormu({ ...secim, sekme: 'kapama' }); }

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
