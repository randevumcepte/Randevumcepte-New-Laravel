/**
 * Satış Takibi (v2) — build'siz island (sade ES module).
 *
 * Eski panel "Satış Takibi" (adisyon listesi) ekranının v2 karşılığı. Veriyi
 * /api/v2/satislar ucundan Bearer token ile çeker (web + Flutter AYNI uç → tek
 * kaynak). Sekme (Tümü/Hizmet/Paket/Ürün), tarih aralığı + müşteri + durum
 * filtresi, üst özet kutuları (açık/kapalı sayısı, ödenen, kalan) ve sayfalı
 * tablo. Menü/header shell'den (panel-kabuk.js); bu betik yalnızca içerik alanı.
 *
 * Token: localStorage['rc_access_token'] (pilotta ?token=). salon_id data-salon
 * (blade ?sube=) ya da ?sube= query'sinden.
 */

const BASE = '/api/v2';
const _qp = new URLSearchParams(location.search);
if (_qp.get('token')) localStorage.setItem('rc_access_token', _qp.get('token'));
const token = () => localStorage.getItem('rc_access_token') || '';

async function api(yol, params) {
  let url = BASE + yol;
  if (params) {
    const qs = new URLSearchParams(
      Object.fromEntries(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''))
    ).toString();
    if (qs) url += (url.includes('?') ? '&' : '?') + qs;
  }
  const r = await fetch(url, {
    cache: 'no-store',
    headers: { Accept: 'application/json', Authorization: 'Bearer ' + token() },
  });
  const t = await r.text();
  return { ok: r.ok, kod: r.status, govde: t ? JSON.parse(t) : null };
}

const el = (s) => document.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

const kok = document.getElementById('rc-satislar');
let salonId = parseInt(kok.dataset.salon, 10) || parseInt(_qp.get('sube'), 10) || 0;

// Durum: kullanici secimi  '' hepsi / '1' acik / '0' kapali
const durum = {
  tur: 0,          // 0 tumu / 1 hizmet / 2 paket / 3 urun (sekme)
  acikKapali: '',  // '' / '1' / '0'
  musteriId: '',
  tarih1: '',
  tarih2: '',
  page: 1,
};

const SEKMELER = [
  { tur: 0, ad: 'Tümü' },
  { tur: 1, ad: 'Hizmet' },
  { tur: 2, ad: 'Paket' },
  { tur: 3, ad: 'Ürün' },
];

function ayBasiSonu() {
  const d = new Date();
  const p = (n) => String(n).padStart(2, '0');
  const bas = `${d.getFullYear()}-${p(d.getMonth() + 1)}-01`;
  const son = new Date(d.getFullYear(), d.getMonth() + 1, 0);
  const sonStr = `${son.getFullYear()}-${p(son.getMonth() + 1)}-${p(son.getDate())}`;
  return [bas, sonStr];
}

function iskeletCiz() {
  const [b, s] = ayBasiSonu();
  durum.tarih1 = b;
  durum.tarih2 = s;
  kok.innerHTML = `
    <div class="s-kabuk">
      <div class="s-baslik"><h1>Satış Takibi</h1></div>

      <div class="s-ozet" id="sOzet"></div>

      <div class="s-sekmeler" id="sSekmeler">
        ${SEKMELER.map((x) => `<button class="s-sekme${x.tur === durum.tur ? ' aktif' : ''}" data-tur="${x.tur}">${x.ad}</button>`).join('')}
      </div>

      <div class="s-filtre">
        <div class="s-f-grup">
          <label>Tarih</label>
          <input type="text" id="sTarih1" value="${b}" placeholder="Başlangıç">
          <span class="s-f-ara">–</span>
          <input type="text" id="sTarih2" value="${s}" placeholder="Bitiş">
        </div>
        <div class="s-f-grup">
          <label>Durum</label>
          <select id="sDurum">
            <option value="">Tümü</option>
            <option value="1">Açık</option>
            <option value="0">Kapalı</option>
          </select>
        </div>
        <div class="s-f-grup s-f-ara-kutu">
          <label>Müşteri</label>
          <input type="search" id="sMusteri" placeholder="Müşteri ID (ops.)">
        </div>
      </div>

      <div class="s-tablo-sar">
        <table class="s-tablo">
          <thead>
            <tr>
              <th>Durum</th><th>Tarih</th><th>Müşteri</th><th>İçerik</th>
              <th class="s-sag">Toplam</th><th class="s-sag">Ödenen</th><th class="s-sag">Kalan</th>
            </tr>
          </thead>
          <tbody id="sGovde"><tr><td colspan="7" class="s-bos">Yükleniyor…</td></tr></tbody>
        </table>
      </div>

      <div class="s-sayfalama" id="sSayfalama"></div>
    </div>`;

  // Sekmeler
  el('#sSekmeler').addEventListener('click', (e) => {
    const b = e.target.closest('.s-sekme');
    if (!b) return;
    durum.tur = parseInt(b.dataset.tur, 10);
    durum.page = 1;
    document.querySelectorAll('.s-sekme').forEach((x) => x.classList.toggle('aktif', x === b));
    yukle();
  });

  // Filtreler
  el('#sDurum').addEventListener('change', (e) => { durum.acikKapali = e.target.value; durum.page = 1; yukle(); });
  el('#sMusteri').addEventListener('change', (e) => { durum.musteriId = e.target.value.trim(); durum.page = 1; yukle(); });

  // Datepicker (flatpickr shell'den gelir)
  if (window.flatpickr) {
    ['#sTarih1', '#sTarih2'].forEach((sel, i) => {
      flatpickr(el(sel), {
        dateFormat: 'Y-m-d', locale: 'tr', allowInput: true, disableMobile: true,
        onChange: (_d, str) => { durum[i === 0 ? 'tarih1' : 'tarih2'] = str; durum.page = 1; yukle(); },
      });
    });
  } else {
    el('#sTarih1').addEventListener('change', (e) => { durum.tarih1 = e.target.value; durum.page = 1; yukle(); });
    el('#sTarih2').addEventListener('change', (e) => { durum.tarih2 = e.target.value; durum.page = 1; yukle(); });
  }
}

function ozetCiz(v) {
  el('#sOzet').innerHTML = `
    <div class="s-kutu s-acik"><span class="s-k-ad">Açık</span><span class="s-k-deger">${v.acik_sayisi ?? 0}</span></div>
    <div class="s-kutu s-kapali"><span class="s-k-ad">Kapalı</span><span class="s-k-deger">${v.kapali_sayisi ?? 0}</span></div>
    <div class="s-kutu"><span class="s-k-ad">Ödenen</span><span class="s-k-deger">${esc(v.odenen ?? '0,00')} ₺</span></div>
    <div class="s-kutu"><span class="s-k-ad">Kalan</span><span class="s-k-deger">${esc(v.kalan ?? '0,00')} ₺</span></div>`;
}

function satirCiz(a) {
  const rozet = a.durum === 'acik'
    ? '<span class="s-rozet s-r-acik">Açık</span>'
    : '<span class="s-rozet s-r-kapali">Kapalı</span>';
  const icerik = esc(a.icerikKisaltilmis || a.icerik || '').replace(/\r\n/g, '<br>');
  return `<tr>
    <td>${rozet}</td>
    <td>${esc(a.acilis_tarihi)}${a.acilis_saati ? ' <small>' + esc(a.acilis_saati) + '</small>' : ''}</td>
    <td>${esc(a.musteri)}</td>
    <td class="s-icerik">${icerik}</td>
    <td class="s-sag">${esc(a.toplam)} ₺</td>
    <td class="s-sag">${esc(a.odenen)} ₺</td>
    <td class="s-sag s-kalan">${esc(a.kalan_tutar)} ₺</td>
  </tr>`;
}

function sayfalamaCiz(v) {
  const son = v.last_page || 1;
  const su = v.current_page || 1;
  if (son <= 1) { el('#sSayfalama').innerHTML = ''; return; }
  const btn = (p, ad, pasif) => `<button class="s-sayfa${pasif ? ' pasif' : ''}${p === su ? ' aktif' : ''}" data-p="${p}" ${pasif ? 'disabled' : ''}>${ad}</button>`;
  let h = btn(su - 1, '‹', su <= 1);
  const bas = Math.max(1, su - 2);
  const bit = Math.min(son, bas + 4);
  for (let p = bas; p <= bit; p++) h += btn(p, p, false);
  h += btn(su + 1, '›', su >= son);
  el('#sSayfalama').innerHTML = h;
  el('#sSayfalama').onclick = (e) => {
    const b = e.target.closest('.s-sayfa');
    if (!b || b.disabled) return;
    durum.page = parseInt(b.dataset.p, 10);
    yukle();
  };
}

async function yukle() {
  if (!salonId) {
    el('#sGovde').innerHTML = `<tr><td colspan="7" class="s-bos">Şube seçili değil (?sube=... bekleniyor).</td></tr>`;
    return;
  }
  el('#sGovde').innerHTML = `<tr><td colspan="7" class="s-bos">Yükleniyor…</td></tr>`;
  const { ok, kod, govde } = await api('/satislar', {
    salon_id: salonId,
    tarih1: durum.tarih1,
    tarih2: durum.tarih2,
    tur: durum.tur,
    acik_kapali: durum.acikKapali,
    musteri_id: durum.musteriId,
    page: durum.page,
  });
  if (!ok || !govde || !govde.basarili) {
    const msg = (govde && govde.mesaj) || `Hata (${kod})`;
    el('#sGovde').innerHTML = `<tr><td colspan="7" class="s-bos s-hata">${esc(msg)}</td></tr>`;
    return;
  }
  const v = govde.veri;
  ozetCiz(v);
  const list = v.data || [];
  el('#sGovde').innerHTML = list.length
    ? list.map(satirCiz).join('')
    : `<tr><td colspan="7" class="s-bos">Kayıt bulunamadı.</td></tr>`;
  sayfalamaCiz(v);
}

iskeletCiz();
yukle();
