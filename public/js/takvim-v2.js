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

import { qp, api, el, esc, pad, tarihStr, TL, panel } from '/js/lib/v2-ortak.js?v=1';
import { randevuIslemleri, saatStr } from '/js/lib/v2-randevu.js?v=2';

const ESKI_PANEL = 'https://app.randevumcepte.com.tr';

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
let salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;

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

let R = null; // randevuIslemleri() — çekmece, durum aksiyonları, randevu formu (lib/v2-randevu.js)

const durum = {
  takvimTuru: kayitliTur(), // '' = salonun varsayilani (sunucu cozer)
  kaynaklar: [],      // sunucudan gelen tum kaynaklar
  secili: '',         // personel/kaynak filtresi ('' = tumu)
  seciliRandevu: null, // cekmecede acik randevu (vurgu)
  etkilesim: false,    // surukle/boyutlandir suruyor (otomatik yenileme beklesin)
  sessiz: false,       // otomatik yenileme: yukleniyor gostergesi yanip sonmesin
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
  kategori: 'bi-clipboard2-check',   // hizmet kategorisi (Satis Takibi'ndeki Hizmet ikonu ile ayni)
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
      R.randevuFormu({ start: info.date, kaynakId: info.resource?.id });
    },
    select: (info) => {
      durum.ec.unselect();
      R.randevuFormu({ start: info.start, end: info.end, kaynakId: info.resource?.id });
    },
    eventClick: (info) => {
      const e = info.event.extendedProps || {};
      if (e.kapali) return kapaliSaatPenceresi(e);
      R.cekmeceAc(e.randevu_id || e.id);
    },
    loading: (y) => { if (!durum.sessiz) el('#tYuk').classList.toggle('acik', y); if (!y) durum.sessiz = false; },
    eventDragStart: () => { durum.etkilesim = true; },
    eventDragStop: () => { durum.etkilesim = false; },
    eventResizeStart: () => { durum.etkilesim = true; },
    eventResizeStop: () => { durum.etkilesim = false; },
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

/**
 * Otomatik yenileme — 15 sn'de bir (diger kullanicilarin / Flutter'in yaptigi
 * degisiklikler gorunsun). Sekme arka plandayken, surukleme/boyutlandirma
 * surerken ya da bir form/pencere aciktayken ATLANIR (kullanicinin isini bozmasin).
 * Sekmeye donulunce hemen yenilenir. Gosterge yanip sonmez (sessiz).
 */
const OTOMATIK_YENILEME_MS = 15000;
function sessizYenile() {
  if (!durum.ec || document.hidden || durum.etkilesim) return;
  if (document.querySelector('.rc-onay-overlay, .rs-panel, .flatpickr-calendar.open')) return;
  durum.sessiz = true;
  durum.ec.refetchEvents();
}
function otomatikYenilemeKur() {
  setInterval(sessizYenile, OTOMATIK_YENILEME_MS);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) sessizYenile(); });
}

function aracBagla() {
  el('#tGeri').onclick = () => durum.ec.prev();
  el('#tIleri').onclick = () => durum.ec.next();
  el('#tBugun').onclick = () => durum.ec.setOption('date', new Date());
  el('#tYenile').onclick = () => durum.ec.refetchEvents();
  el('#tSaatKapat').onclick = () => saatKapamaFormu({});
  el('#tYeni').onclick = () => R.randevuFormu({});
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
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') R.cekmeceKapat(); });
}

// (2) Secili randevu vurgusu — cekmece acikken takvimde belirgin.
function seciliAyarla(randevuId) {
  durum.seciliRandevu = randevuId ? String(randevuId) : null;
  durum.ec?.setOption('eventClassNames', (info) => (
    durum.seciliRandevu && String(info.event.extendedProps?.randevu_id ?? info.event.extendedProps?.id) === durum.seciliRandevu
      ? ['t-secili'] : []
  ));
}

/* ───────────────────────── Sürükle-bırak / boyutlandırma ───────────────────────── */

const dtStr = (d) => `${tarihStr(d)} ${pad(d.getHours())}:${pad(d.getMinutes())}:00`;
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

  let r = await api('PATCH', `/randevu/kalem/${e.rh_id}/tasi`, { body: { ...govde, dry_run: false } });
  if (r.kod === 409 && /kapal/i.test(r.govde?.mesaj || '')) {
    r = await api('PATCH', `/randevu/kalem/${e.rh_id}/tasi`, { body: { ...govde, dry_run: true } });
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


// Saat kapama: randevu formunun "Saat Kapama" sekmesi (eski web modali gibi tek modal).
function saatKapamaFormu(secim) { return R.randevuFormu({ ...secim, sekme: 'kapama' }); }

function kapaliSaatPenceresi(e) {
  const { ov, kapat } = R.modal(`
    <div class="rc-onay-ikon"><i class="bi bi-lock"></i></div>
    <h3>Kapalı saat</h3>
    <p>${esc(String(e.start).slice(11, 16))}–${esc(String(e.end).slice(11, 16))}${e.personelId ? ' · ' + esc(kaynakBul(e.resourceId)?.name || '') : ''}</p>
    <div id="kpOnizleme"></div>
    <div class="rc-onay-btns"><button class="rc-onay-iptal">Kapat</button><button class="rc-onay-tamam tehlike">Kapamayı kaldır</button></div>`);
  el('.rc-onay-iptal', ov).onclick = kapat;
  el('.rc-onay-tamam', ov).onclick = async () => {
    const s = await R.yazVeyaOnizle('DELETE', `/randevu/saat-kapama/${e.randevu_id || e.id}`, {});
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
  R = randevuIslemleri({
    salonId, cekmece: el('#tCekmece'),
    kaynaklar: () => durum.kaynaklar,
    gun: () => durum.ec?.getView().currentStart,
    yenile: () => durum.ec?.refetchEvents(),
    secili: seciliAyarla,
  });
  takvimKur();
  aracBagla();
  otomatikYenilemeKur();
}

baslat();
