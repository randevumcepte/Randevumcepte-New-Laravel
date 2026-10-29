/**
 * Satış Takibi (v2) — build'siz island (sade ES module).
 *
 * Eski panel "Satış Takibi" (adisyon listesi) ekranının v2 karşılığı. Veriyi
 * /api/v2/satislar ucundan Bearer token ile çeker (web + Flutter AYNI uç → TEK
 * KAYNAK, kod tekrarı yok). Gradient özet kartları (filtre geneli Toplam/Ödenen/
 * Kalan + Açık/Kapalı), sekme (Tümü/Hizmet/Paket/Ürün), zaman aralığı + personel
 * + durum + fatura + müşteri filtresi, İndirim + İşlemler kolonlu sayfalı tablo.
 *
 * Token: localStorage['rc_access_token'] (pilotta ?token=). salon_id data-salon
 * (blade ?sube=/path) ya da ?sube= query'sinden.
 */

const BASE = '/api/v2';
const ESKI_PANEL = 'https://app.randevumcepte.com.tr';
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
const tokenQS = () => (token() ? '&token=' + encodeURIComponent(token()) : '');

const kok = document.getElementById('rc-satislar');
let salonId = parseInt(kok.dataset.salon, 10) || parseInt(_qp.get('sube'), 10) || 0;

const durum = {
  tur: 0, acikKapali: '', fatura: '', personelId: '', musteriId: '',
  tarih1: '', tarih2: '', page: 1,
};

const SEKMELER = [
  { tur: 0, ad: 'Tümü', ikon: 'bi-grid-3x3-gap-fill' },
  { tur: 1, ad: 'Hizmet', ikon: 'bi-scissors' },
  { tur: 2, ad: 'Paket', ikon: 'bi-box-seam' },
  { tur: 3, ad: 'Ürün', ikon: 'bi-bag' },
];

function pad(n) { return String(n).padStart(2, '0'); }
function tarihStr(d) { return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; }

// Zaman aralığı preset → [tarih1, tarih2]
function araligHesap(deger) {
  const d = new Date(_bugun());
  const y = d.getFullYear(), m = d.getMonth();
  switch (deger) {
    case 'bu_ay':    return [`${y}-${pad(m + 1)}-01`, tarihStr(new Date(y, m + 1, 0))];
    case 'son_3_ay': return [tarihStr(new Date(y, m - 2, 1)), tarihStr(new Date(y, m + 1, 0))];
    case 'bu_yil':   return [`${y}-01-01`, `${y}-12-31`];
    case 'tum':      return ['1970-01-01', tarihStr(new Date(y, m + 1, 0))];
    default:         return [durum.tarih1, durum.tarih2];
  }
}
// Not: new Date kullanımı tarayıcıda serbest (yalnızca client). Bugünü sabitle.
function _bugun() { return new Date(); }
function gecenAy() {
  const d = _bugun(); const y = d.getFullYear(), m = d.getMonth();
  const bas = new Date(y, m - 1, 1), son = new Date(y, m, 0);
  return [tarihStr(bas), tarihStr(son)];
}

function iskeletCiz() {
  const [b, s] = araligHesap('bu_ay');
  durum.tarih1 = b; durum.tarih2 = s;

  kok.innerHTML = `
    <div class="s-kabuk">
      <div class="s-ust">
        <div class="s-baslik">
          <span class="s-baslik-ikon"><i class="bi bi-receipt"></i></span>
          <div>
            <h1>Satış Takibi</h1>
            <div class="s-alt">Adisyon / satış listesi ve tahsilat durumu</div>
          </div>
        </div>
        <a class="s-yeni-btn" href="${ESKI_PANEL}/isletmeyonetim/adisyonlar?sube=${salonId}">
          <i class="bi bi-plus-lg"></i> Yeni Satış &amp; Tahsilat
        </a>
      </div>

      <div class="s-ozet" id="sOzet"></div>

      <div class="s-sekmeler" id="sSekmeler">
        ${SEKMELER.map((x) => `<button class="s-sekme${x.tur === durum.tur ? ' aktif' : ''}" data-tur="${x.tur}"><i class="bi ${x.ikon}"></i> ${x.ad}</button>`).join('')}
      </div>

      <div class="s-filtre-kart">
        <div class="s-filtre">
          <div class="s-f-grup">
            <label>Zaman Aralığı</label>
            <select id="sAralik">
              <option value="bu_ay">Bu ay</option>
              <option value="gecen_ay">Geçen ay</option>
              <option value="son_3_ay">Son 3 ay</option>
              <option value="bu_yil">Bu yıl</option>
              <option value="tum">Tüm zamanlar</option>
              <option value="ozel">Özel</option>
            </select>
          </div>
          <div class="s-f-grup">
            <label>Başlangıç</label>
            <input type="text" id="sTarih1" value="${b}">
          </div>
          <div class="s-f-grup">
            <label>Bitiş</label>
            <input type="text" id="sTarih2" value="${s}">
          </div>
          <div class="s-f-grup" id="sPersonelGrup" style="display:none">
            <label>Personel</label>
            <select id="sPersonel"><option value="">Tümü</option></select>
          </div>
          <div class="s-f-grup">
            <label>Satış Durumu</label>
            <select id="sDurum">
              <option value="">Tüm Satışlar</option>
              <option value="1">Açık</option>
              <option value="0">Kapalı</option>
            </select>
          </div>
          <div class="s-f-grup">
            <label>Fatura</label>
            <select id="sFatura">
              <option value="">Tümü</option>
              <option value="1">Faturalı</option>
              <option value="0">Faturasız</option>
            </select>
          </div>
          <div class="s-f-grup">
            <label>Müşteri ID</label>
            <input type="search" id="sMusteri" placeholder="Opsiyonel">
          </div>
        </div>
      </div>

      <div class="s-tablo-sar">
        <table class="s-tablo">
          <thead>
            <tr>
              <th>Durum</th><th>Tarih</th><th>Müşteri</th><th>Yaklaşan Ödeme</th><th>İçerik</th>
              <th class="s-sag">İndirim</th><th class="s-sag">Toplam</th><th class="s-sag">Ödenen</th><th class="s-sag">Kalan</th>
              <th class="s-orta">İşlemler</th>
            </tr>
          </thead>
          <tbody id="sGovde"><tr><td colspan="10" class="s-bos">Yükleniyor…</td></tr></tbody>
        </table>
      </div>

      <div class="s-sayfalama" id="sSayfalama"></div>
    </div>`;

  el('#sSekmeler').addEventListener('click', (e) => {
    const b = e.target.closest('.s-sekme');
    if (!b) return;
    durum.tur = parseInt(b.dataset.tur, 10);
    durum.page = 1;
    document.querySelectorAll('.s-sekme').forEach((x) => x.classList.toggle('aktif', x === b));
    yukle();
  });

  el('#sAralik').addEventListener('change', (e) => {
    const v = e.target.value;
    if (v === 'ozel') return;
    const [t1, t2] = v === 'gecen_ay' ? gecenAy() : araligHesap(v);
    durum.tarih1 = t1; durum.tarih2 = t2; durum.page = 1;
    tarihSet('#sTarih1', t1); tarihSet('#sTarih2', t2);
    yukle();
  });
  el('#sDurum').addEventListener('change', (e) => { durum.acikKapali = e.target.value; durum.page = 1; yukle(); });
  el('#sFatura').addEventListener('change', (e) => { durum.fatura = e.target.value; durum.page = 1; yukle(); });
  el('#sMusteri').addEventListener('change', (e) => { durum.musteriId = e.target.value.trim(); durum.page = 1; yukle(); });
  el('#sPersonel').addEventListener('change', (e) => { durum.personelId = e.target.value; durum.page = 1; yukle(); });

  if (window.flatpickr) {
    ['#sTarih1', '#sTarih2'].forEach((sel, i) => {
      el(sel)._fp = flatpickr(el(sel), {
        dateFormat: 'Y-m-d', locale: 'tr', allowInput: true, disableMobile: true,
        onChange: (_d, str) => { durum[i === 0 ? 'tarih1' : 'tarih2'] = str; durum.page = 1; el('#sAralik').value = 'ozel'; yukle(); },
      });
    });
  } else {
    ['#sTarih1', '#sTarih2'].forEach((sel, i) => el(sel).addEventListener('change', (e) => {
      durum[i === 0 ? 'tarih1' : 'tarih2'] = e.target.value; durum.page = 1; el('#sAralik').value = 'ozel'; yukle();
    }));
  }
}

function tarihSet(sel, val) {
  const i = el(sel);
  if (!i) return;
  if (i._fp) i._fp.setDate(val || null, false); else i.value = val || '';
}

// Personel dropdown (best-effort; personel.liste_gor yoksa 403 → gizli kalır).
async function personelleriYukle() {
  const { ok, govde } = await api('/personel', { salon_id: salonId, per_page: 200 });
  if (!ok || !govde || !govde.basarili) return;
  const list = (govde.veri && govde.veri.data) || [];
  if (!list.length) return;
  const sec = el('#sPersonel');
  list.forEach((p) => {
    const ad = p.personel_adi || p.ad || p.isim || ('#' + p.id);
    const o = document.createElement('option');
    o.value = p.id; o.textContent = ad;
    sec.appendChild(o);
  });
  el('#sPersonelGrup').style.display = '';
}

function kart(ikon, etiket, deger, tip) {
  return `<div class="s-kutu s-${tip}">
    <div class="s-k-ikon"><i class="bi ${ikon}"></i></div>
    <div class="s-k-ic"><span class="s-k-ad">${etiket}</span><span class="s-k-deger">${deger}</span></div>
  </div>`;
}

function ozetCiz(v) {
  el('#sOzet').innerHTML =
    kart('bi-cash-stack', 'Toplam Satış', esc(v.toplam ?? '0,00') + ' ₺', 'toplam') +
    kart('bi-check2-circle', 'Ödenen', esc(v.odenen ?? '0,00') + ' ₺', 'odenen') +
    kart('bi-exclamation-triangle', 'Kalan', esc(v.kalan ?? '0,00') + ' ₺', 'kalan') +
    kart('bi-folder2-open', 'Açık', (v.acik_sayisi ?? 0), 'acik') +
    kart('bi-archive', 'Kapalı', (v.kapali_sayisi ?? 0), 'kapali');
}

function satirCiz(a) {
  const rozet = a.durum === 'acik'
    ? '<span class="s-rozet s-r-acik">Açık</span>'
    : '<span class="s-rozet s-r-kapali">Kapalı</span>';
  const icerik = esc(a.icerikKisaltilmis || a.icerik || '').replace(/\r\n/g, '<br>');
  const islem = a.durum === 'acik'
    ? `<a class="s-islem s-islem-tahsil" href="/v2/tahsilat/${a.id}?sube=${salonId}${tokenQS()}" title="Tahsil Et"><i class="bi bi-cash-coin"></i> Tahsil Et</a>`
    : `<a class="s-islem s-islem-detay" href="/v2/tahsilat/${a.id}?sube=${salonId}${tokenQS()}" title="Detay"><i class="bi bi-eye"></i> Detay</a>`;
  return `<tr>
    <td>${rozet}</td>
    <td class="s-nowrap">${esc(a.acilis_tarihi)}${a.acilis_saati ? ' <small>' + esc(a.acilis_saati) + '</small>' : ''}</td>
    <td>${esc(a.musteri)}</td>
    <td class="s-nowrap">${esc(a.planlanan_alacak_tarihi || '-')}</td>
    <td class="s-icerik">${icerik}</td>
    <td class="s-sag">${esc(a.indirim || '0,00')} ₺</td>
    <td class="s-sag">${esc(a.toplam)} ₺</td>
    <td class="s-sag">${esc(a.odenen)} ₺</td>
    <td class="s-sag s-kalan">${esc(a.kalan_tutar)} ₺</td>
    <td class="s-orta">${islem}</td>
  </tr>`;
}

function sayfalamaCiz(v) {
  const son = v.last_page || 1, su = v.current_page || 1;
  if (son <= 1) { el('#sSayfalama').innerHTML = ''; return; }
  const btn = (p, ad, pasif) => `<button class="s-sayfa${pasif ? ' pasif' : ''}${p === su ? ' aktif' : ''}" data-p="${p}" ${pasif ? 'disabled' : ''}>${ad}</button>`;
  let h = btn(su - 1, '‹', su <= 1);
  const bas = Math.max(1, su - 2), bit = Math.min(son, bas + 4);
  for (let p = bas; p <= bit; p++) h += btn(p, p, false);
  h += btn(su + 1, '›', su >= son);
  el('#sSayfalama').innerHTML = `<div class="s-sayfa-bilgi">Sayfa ${su} / ${son} · ${v.total ?? 0} kayıt</div>` + h;
  el('#sSayfalama').onclick = (e) => {
    const b = e.target.closest('.s-sayfa');
    if (!b || b.disabled) return;
    durum.page = parseInt(b.dataset.p, 10); yukle();
  };
}

async function yukle() {
  if (!salonId) {
    el('#sGovde').innerHTML = `<tr><td colspan="10" class="s-bos">Şube seçili değil (?sube=... bekleniyor).</td></tr>`;
    return;
  }
  el('#sGovde').innerHTML = `<tr><td colspan="10" class="s-bos"><span class="s-yukleniyor"></span> Yükleniyor…</td></tr>`;
  const { ok, kod, govde } = await api('/satislar', {
    salon_id: salonId, tarih1: durum.tarih1, tarih2: durum.tarih2,
    tur: durum.tur, acik_kapali: durum.acikKapali, fatura: durum.fatura,
    personel_id: durum.personelId, musteri_id: durum.musteriId, page: durum.page,
  });
  if (!ok || !govde || !govde.basarili) {
    const msg = (govde && govde.mesaj) || `Hata (${kod})`;
    el('#sGovde').innerHTML = `<tr><td colspan="10" class="s-bos s-hata">${esc(msg)}</td></tr>`;
    return;
  }
  const v = govde.veri;
  ozetCiz(v);
  const list = v.data || [];
  el('#sGovde').innerHTML = list.length ? list.map(satirCiz).join('')
    : `<tr><td colspan="10" class="s-bos">Kayıt bulunamadı.</td></tr>`;
  sayfalamaCiz(v);
}

iskeletCiz();
personelleriYukle();
yukle();
