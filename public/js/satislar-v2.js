/**
 * Satış Takibi (v2) — build'siz island (sade ES module).
 *
 * Eski panel "Satış Takibi" (isletmeadmin/adisyonlar) ekranının v2 karşılığı.
 * Veri + yetki /api/v2/satislar'dan gelir (web + Flutter AYNI uç → TEK KAYNAK).
 * Buton görünürlüğü API'nin `yetkiler` bloğundan (eski kurallarla birebir):
 *   Tahsil Et / Ödeme Bilgisi / Düzenle → /v2/tahsilat/{id}
 *   Fatura işaretle  → yalnız hesap sahibi, stüdyo değil
 *   Sil              → satis.adisyon_sil
 *   Stüdyo Ödeme Alındı / Geri Al → satis.tahsilat_al (stüdyo modunda)
 * Eski panele yönlendirme YOK.
 */

const BASE = '/api/v2';
const _qp = new URLSearchParams(location.search);
if (_qp.get('token')) localStorage.setItem('rc_access_token', _qp.get('token'));
const token = () => localStorage.getItem('rc_access_token') || '';

async function api(yol, { method = 'GET', params = null, body = null } = {}) {
  let url = BASE + yol;
  if (params) {
    const qs = new URLSearchParams(
      Object.fromEntries(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''))
    ).toString();
    if (qs) url += (url.includes('?') ? '&' : '?') + qs;
  }
  const r = await fetch(url, {
    method,
    cache: 'no-store',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: 'Bearer ' + token() },
    body: body ? JSON.stringify(body) : undefined,
  });
  const t = await r.text();
  let govde = null;
  try { govde = t ? JSON.parse(t) : null; } catch (_) { govde = null; }
  return { ok: r.ok, kod: r.status, govde };
}

const el = (s) => document.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const toast = (m, tip) => (window.rcPanel && window.rcPanel.toast ? window.rcPanel.toast(m, tip) : alert(m));
const onay = (m, o) => (window.rcPanel && window.rcPanel.onay ? window.rcPanel.onay(m, o) : Promise.resolve(confirm(m)));

const kok = document.getElementById('rc-satislar');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(_qp.get('sube'), 10) || 0;
if (salonId && window.rcPanel) window.rcPanel.salonAyarla(salonId);

const durum = { tur: 0, satisDurumu: '', personelId: '', musteriId: '', tarih1: '', tarih2: '', page: 1 };
let sonVeri = null;   // son liste cevabı (yetkiler/studyo için)
let studyo = false;

const SEKMELER = [
  { tur: 0, ad: 'Tümü', ikon: 'bi-grid-3x3-gap-fill' },
  { tur: 1, ad: 'Hizmetler/İşlemler', ikon: 'bi-gear' },
  { tur: 3, ad: 'Ürünler', ikon: 'bi-box', studyoGizle: true },
  { tur: 2, ad: 'Paketler', ikon: 'bi-archive' },
];

const pad = (n) => String(n).padStart(2, '0');
const ts = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

// Eski paneldeki "Zaman Aralığı" seçenekleri birebir.
function aralik(deger) {
  const b = new Date(), y = b.getFullYear(), m = b.getMonth();
  switch (deger) {
    case 'bugun':     return [ts(b), ts(b)];
    case 'dun':       { const d = new Date(y, m, b.getDate() - 1); return [ts(d), ts(d)]; }
    case 'bu_ay':     return [ts(new Date(y, m, 1)), ts(new Date(y, m + 1, 0))];
    case 'gecen_ay':  return [ts(new Date(y, m - 1, 1)), ts(new Date(y, m, 0))];
    case 'bu_yil':    return [`${y}-01-01`, `${y}-12-31`];
    case 'gecen_yil': return [`${y - 1}-01-01`, `${y - 1}-12-31`];
    default:          return [durum.tarih1, durum.tarih2];
  }
}

// Satış Durumu select'i: açık/kapalı + faturalı/faturasız (eski panelde tek select).
function durumParam() {
  switch (durum.satisDurumu) {
    case 'acik': return { acik_kapali: '1' };
    case 'kapali': return { acik_kapali: '0' };
    case 'faturali': return { fatura: '1' };
    case 'faturasiz': return { fatura: '0' };
    default: return {};
  }
}

function iskeletCiz() {
  [durum.tarih1, durum.tarih2] = aralik('bu_ay');

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
      </div>

      <div class="s-ozet" id="sOzet"></div>

      <div class="s-sekmeler" id="sSekmeler"></div>

      <div class="s-filtre-kart">
        <div class="s-filtre">
          <div class="s-f-grup">
            <label>Zaman Aralığı</label>
            <select id="sAralik">
              <option value="bugun">Bugün</option>
              <option value="dun">Dün</option>
              <option value="bu_ay" selected>Bu ay</option>
              <option value="gecen_ay">Geçen ay</option>
              <option value="bu_yil">Bu yıl</option>
              <option value="gecen_yil">Geçen yıl</option>
              <option value="ozel">Özel</option>
            </select>
          </div>
          <div class="s-f-grup s-ozel" style="display:none">
            <label>Başlangıç Tarihi</label>
            <input type="text" id="sTarih1" value="${durum.tarih1}">
          </div>
          <div class="s-f-grup s-ozel" style="display:none">
            <label>Bitiş Tarihi</label>
            <input type="text" id="sTarih2" value="${durum.tarih2}">
          </div>
          <div class="s-f-grup" id="sPersonelGrup" style="display:none">
            <label>Personele Göre Filtrele</label>
            <select id="sPersonel"><option value="">Tümü</option></select>
          </div>
          <div class="s-f-grup">
            <label>Satış Durumu</label>
            <select id="sDurum"></select>
          </div>
          <div class="s-f-grup">
            <label>Müşteri ID</label>
            <input type="search" id="sMusteri" placeholder="Opsiyonel">
          </div>
          <div class="s-f-grup s-f-ek" id="sFaturaGizleGrup" style="display:none">
            <label>&nbsp;</label>
            <button type="button" class="s-fatura-gizle" id="sFaturaGizle" title="Faturasız işlemleri gizle / göster">
              <i class="bi bi-file-earmark-text"></i> <span>Faturasızları gizle</span>
            </button>
          </div>
        </div>
      </div>

      <div class="s-tablo-sar">
        <table class="s-tablo">
          <thead id="sBaslik"></thead>
          <tbody id="sGovde"><tr><td colspan="10" class="s-bos">Yükleniyor…</td></tr></tbody>
        </table>
      </div>

      <div class="s-sayfalama" id="sSayfalama"></div>
    </div>`;

  sekmeCiz();
  durumSecenekCiz();

  el('#sSekmeler').addEventListener('click', (e) => {
    const b = e.target.closest('.s-sekme');
    if (!b) return;
    durum.tur = parseInt(b.dataset.tur, 10);
    durum.page = 1;
    sekmeCiz();
    yukle();
  });

  el('#sAralik').addEventListener('change', (e) => {
    const v = e.target.value;
    document.querySelectorAll('.s-ozel').forEach((x) => { x.style.display = v === 'ozel' ? '' : 'none'; });
    if (v === 'ozel') return;
    [durum.tarih1, durum.tarih2] = aralik(v);
    tarihSet('#sTarih1', durum.tarih1); tarihSet('#sTarih2', durum.tarih2);
    durum.page = 1; yukle();
  });
  el('#sDurum').addEventListener('change', (e) => { durum.satisDurumu = e.target.value; durum.page = 1; yukle(); });
  el('#sMusteri').addEventListener('change', (e) => { durum.musteriId = e.target.value.trim(); durum.page = 1; yukle(); });
  el('#sPersonel').addEventListener('change', (e) => { durum.personelId = e.target.value; durum.page = 1; yukle(); });
  el('#sFaturaGizle').addEventListener('click', faturasizGizleTikla);

  ['#sTarih1', '#sTarih2'].forEach((sel, i) => {
    const alan = i === 0 ? 'tarih1' : 'tarih2';
    const degisti = (str) => { durum[alan] = str; durum.page = 1; yukle(); };
    if (window.flatpickr) {
      el(sel)._fp = flatpickr(el(sel), { dateFormat: 'Y-m-d', locale: 'tr', allowInput: true, disableMobile: true, onChange: (_d, str) => degisti(str) });
    } else {
      el(sel).addEventListener('change', (e) => degisti(e.target.value));
    }
  });

  // Satır işlemleri (delegasyon)
  el('#sGovde').addEventListener('click', satirIslem);
}

function sekmeCiz() {
  el('#sSekmeler').innerHTML = SEKMELER
    .filter((x) => !(studyo && x.studyoGizle))
    .map((x) => `<button class="s-sekme${x.tur === durum.tur ? ' aktif' : ''}" data-tur="${x.tur}"><i class="bi ${x.ikon}"></i> ${x.ad}</button>`)
    .join('');
}

function durumSecenekCiz() {
  const kapaliGizli = !!(sonVeri && sonVeri.yetkiler && sonVeri.yetkiler.kapali_gizli);
  const sec = [['', 'Tüm Satışlar'], ['acik', 'Açık Satışlar']];
  if (!kapaliGizli) sec.push(['kapali', 'Kapalı Satışlar']);
  if (!studyo) sec.push(['faturali', 'Faturalı İşlemler'], ['faturasiz', 'Faturasız İşlemler']);
  if (!sec.some(([v]) => v === durum.satisDurumu)) durum.satisDurumu = '';
  el('#sDurum').innerHTML = sec.map(([v, a]) => `<option value="${v}"${v === durum.satisDurumu ? ' selected' : ''}>${a}</option>`).join('');
}

function tarihSet(sel, val) {
  const i = el(sel);
  if (!i) return;
  if (i._fp) i._fp.setDate(val || null, false); else i.value = val || '';
}

// Personel dropdown (eski: stüdyo modunda yok). personel.liste_gor yoksa 403 → gizli kalır.
async function personelleriYukle() {
  const { ok, govde } = await api('/personel', { params: { salon_id: salonId, per_page: 100 } });
  if (!ok || !govde || !govde.basarili) return;
  const list = (govde.veri && govde.veri.data) || [];
  if (!list.length) return;
  const sec = el('#sPersonel');
  sec.innerHTML = '<option value="">Tümü</option>' + list
    .map((p) => `<option value="${esc(p.id)}">${esc(p.personel_adi || p.ad || ('#' + p.id))}</option>`).join('');
  personelGrupGoster();
}
let personelVar = false;
function personelGrupGoster() {
  personelVar = personelVar || el('#sPersonel').options.length > 1;
  const y = sonVeri && sonVeri.yetkiler;
  // tum_satis_gor yoksa kullanıcı zaten yalnız kendi satışlarını görür → filtre anlamsız.
  el('#sPersonelGrup').style.display = personelVar && !studyo && (!y || y.tum_satis_gor) ? '' : 'none';
}

function kart(ikon, etiket, deger, tip) {
  return `<div class="s-kutu s-${tip}">
    <div class="s-k-ikon"><i class="bi ${ikon}"></i></div>
    <div class="s-k-ic"><span class="s-k-ad">${etiket}</span><span class="s-k-deger">${deger}</span></div>
  </div>`;
}

function ozetCiz(v) {
  const kapaliGizli = v.yetkiler && v.yetkiler.kapali_gizli;
  // Eski panel: stüdyo modunda tutar kartları gösterilmez.
  el('#sOzet').innerHTML =
    (studyo ? '' :
      kart('bi-bag', 'Toplam Satış', esc(v.toplam ?? '0,00') + ' ₺', 'toplam') +
      kart('bi-check2-circle', 'Ödenen', esc(v.odenen ?? '0,00') + ' ₺', 'odenen') +
      kart('bi-exclamation-triangle', 'Kalan', esc(v.kalan ?? '0,00') + ' ₺', 'kalan')) +
    kart('bi-folder2-open', 'Açık', (v.acik_sayisi ?? 0), 'acik') +
    (kapaliGizli ? '' : kart('bi-archive', 'Kapalı', (v.kapali_sayisi ?? 0), 'kapali'));
}

function baslikCiz() {
  const para = studyo ? '' : '<th class="s-sag">İndirim</th><th class="s-sag">Toplam</th><th class="s-sag">Ödenen</th><th class="s-sag">Kalan</th>';
  const st = studyo ? '<th>Ödeme Tarihi</th>' : '';
  el('#sBaslik').innerHTML = `<tr>
    <th class="s-k-durum">Durum</th><th>Satış Tarihi</th><th>Müşteri</th><th>Yaklaşan Ödeme</th><th>İçerik</th>
    ${para}${st}<th class="s-orta s-k-islem">İşlemler</th></tr>`;
}
const kolonSayisi = () => (studyo ? 7 : 10);

// Stüdyo: açık/kapalı 'odendi' bayrağından (eski web listesi), diğerlerinde kalan.
const kapaliMi = (a) => (studyo ? Number(a.odendi) === 1 : a.durum !== 'acik');

function islemlerCiz(a) {
  const y = (sonVeri && sonVeri.yetkiler) || {};
  const tahsilatUrl = `/v2/tahsilat/${a.id}`;
  const b = [];
  if (studyo) {
    if (y.tahsilat_al) {
      b.push(kapaliMi(a)
        ? `<button class="s-ib s-ib-uyari" data-islem="odeme-geri" data-id="${a.id}" title="Ödemeyi Geri Al"><i class="bi bi-arrow-counterclockwise"></i></button>`
        : `<button class="s-ib s-ib-yesil" data-islem="odeme-al" data-id="${a.id}" title="Ödeme Alındı"><i class="bi bi-check-lg"></i></button>`);
    }
  } else if (kapaliMi(a)) {
    b.push(`<a class="s-ib s-ib-mor" href="${tahsilatUrl}" title="Ödeme Bilgileri"><i class="bi bi-eye"></i></a>`);
  } else {
    b.push(`<a class="s-ib-metin" href="${tahsilatUrl}" title="Tahsil Et"><i class="bi bi-cash-coin"></i> Tahsil Et</a>`);
  }
  b.push(`<a class="s-ib s-ib-gri" href="${tahsilatUrl}" title="Düzenle"><i class="bi bi-pencil-square"></i></a>`);
  if (y.fatura_isaretle) {
    const fk = Number(a.fatura_kesildi) === 1;
    b.push(`<button class="s-ib ${fk ? 's-ib-yesil' : 's-ib-soluk'}" data-islem="fatura" data-id="${a.id}" title="${fk ? 'Faturalı (kaldırmak için tıkla)' : 'Faturasız (işaretlemek için tıkla)'}"><i class="bi bi-file-earmark-text"></i></button>`);
  }
  if (y.adisyon_sil) {
    b.push(`<button class="s-ib s-ib-kirmizi" data-islem="sil" data-id="${a.id}" data-musteri="${esc(a.musteri)}" title="Adisyonu Sil"><i class="bi bi-trash3"></i></button>`);
  }
  return `<div class="s-islemler">${b.join('')}</div>`;
}

function satirCiz(a) {
  const rozet = kapaliMi(a)
    ? '<span class="s-rozet s-r-kapali">Kapalı</span>'
    : '<span class="s-rozet s-r-acik">Açık</span>';
  const icerik = esc(a.icerikKisaltilmis || a.icerik || '').trim().replace(/\r?\n/g, '<br>');
  const para = studyo ? '' : `
    <td class="s-sag">${esc(a.indirim || '0,00')} ₺</td>
    <td class="s-sag">${esc(a.toplam)} ₺</td>
    <td class="s-sag">${esc(a.odenen)} ₺</td>
    <td class="s-sag s-kalan${kapaliMi(a) ? ' s-sifir' : ''}">${esc(a.kalan_tutar)} ₺</td>`;
  const st = studyo ? `<td class="s-nowrap">${esc(a.odendi_tarihi || '-')}</td>` : '';
  return `<tr>
    <td>${rozet}</td>
    <td class="s-nowrap">${esc(a.acilis_tarihi)}${a.acilis_saati ? ' <small>' + esc(a.acilis_saati) + '</small>' : ''}</td>
    <td>${esc(a.musteri)}</td>
    <td class="s-nowrap">${esc(a.planlanan_alacak_tarihi || '-')}</td>
    <td class="s-icerik">${icerik}</td>
    ${para}${st}
    <td class="s-orta">${islemlerCiz(a)}</td>
  </tr>`;
}

async function satirIslem(e) {
  const b = e.target.closest('button[data-islem]');
  if (!b) return;
  const id = b.dataset.id;
  const islem = b.dataset.islem;
  let sonuc;
  if (islem === 'sil') {
    const evet = await onay(`${b.dataset.musteri || ''} — Adisyon #${id} ve tüm kalemleri, tahsilatları, taksit/senet ve alacak kayıtları silinecek. Bu işlem geri alınamaz.`,
      { baslik: 'Adisyonu sil', tamam: 'Sil', tehlike: true });
    if (!evet) return;
    b.disabled = true;
    sonuc = await api(`/adisyon/${id}`, { method: 'DELETE' });
  } else if (islem === 'fatura') {
    b.disabled = true;
    sonuc = await api(`/adisyon/${id}/fatura`, { method: 'PATCH' });
  } else if (islem === 'odeme-al' || islem === 'odeme-geri') {
    const al = islem === 'odeme-al';
    if (!al && !(await onay('Bu adisyonun tüm tahsilatları kaldırılacak ve adisyon tekrar açık olacak.', { baslik: 'Ödemeyi geri al', tamam: 'Geri Al' }))) return;
    b.disabled = true;
    sonuc = await api(`/adisyon/${id}/odeme`, { method: 'PATCH', body: { alindi: al } });
  } else {
    return;
  }
  if (!sonuc.ok || !sonuc.govde || !sonuc.govde.basarili) {
    b.disabled = false;
    toast((sonuc.govde && sonuc.govde.mesaj) || `İşlem başarısız (${sonuc.kod})`, 'hata');
    return;
  }
  toast(sonuc.govde.mesaj || 'İşlem tamamlandı', 'ok');
  yukle();
}

async function faturasizGizleTikla() {
  const b = el('#sFaturaGizle');
  b.disabled = true;
  const r = await api('/satislar/faturasiz-gizle', { method: 'PATCH', params: { salon_id: salonId } });
  b.disabled = false;
  if (!r.ok || !r.govde || !r.govde.basarili) {
    toast((r.govde && r.govde.mesaj) || `İşlem başarısız (${r.kod})`, 'hata');
    return;
  }
  toast(r.govde.veri.faturasiz_gizle ? 'Faturasız işlemler gizlendi' : 'Faturasız işlemler gösteriliyor', 'ok');
  durum.page = 1;
  yukle();
}

function ekKontrollerCiz(v) {
  const y = v.yetkiler || {};
  el('#sFaturaGizleGrup').style.display = y.faturasiz_gizle ? '' : 'none';
  const btn = el('#sFaturaGizle');
  btn.classList.toggle('aktif', !!v.faturasiz_gizle_aktif);
  btn.querySelector('span').textContent = v.faturasiz_gizle_aktif ? 'Faturasızlar gizli' : 'Faturasızları gizle';
}

function sayfalamaCiz(v) {
  const son = v.last_page || 1, su = v.current_page || 1;
  const bilgi = `<div class="s-sayfa-bilgi">${v.total ?? 0} kayıt</div>`;
  if (son <= 1) { el('#sSayfalama').innerHTML = bilgi; return; }
  const btn = (p, ad, pasif) => `<button class="s-sayfa${pasif ? ' pasif' : ''}${p === su ? ' aktif' : ''}" data-p="${p}" ${pasif ? 'disabled' : ''}>${ad}</button>`;
  let h = btn(su - 1, '‹', su <= 1);
  const bas = Math.max(1, Math.min(su - 2, son - 4)), bit = Math.min(son, bas + 4);
  for (let p = bas; p <= bit; p++) h += btn(p, p, false);
  h += btn(su + 1, '›', su >= son);
  el('#sSayfalama').innerHTML = `<div class="s-sayfa-bilgi">Sayfa ${su} / ${son} · ${v.total ?? 0} kayıt</div>` + h;
  el('#sSayfalama').onclick = (e) => {
    const b = e.target.closest('.s-sayfa');
    if (!b || b.disabled) return;
    durum.page = parseInt(b.dataset.p, 10); yukle();
  };
}

let istekNo = 0;
async function yukle() {
  if (!salonId) {
    el('#sGovde').innerHTML = `<tr><td colspan="10" class="s-bos">Şube seçili değil (?sube=... bekleniyor).</td></tr>`;
    return;
  }
  const no = ++istekNo;
  el('#sGovde').innerHTML = `<tr><td colspan="${kolonSayisi()}" class="s-bos"><span class="s-yukleniyor"></span> Yükleniyor…</td></tr>`;
  const r = await api('/satislar', {
    params: {
      salon_id: salonId, tarih1: durum.tarih1, tarih2: durum.tarih2, tur: durum.tur,
      personel_id: durum.personelId, musteri_id: durum.musteriId, page: durum.page, ...durumParam(),
    },
  });
  if (no !== istekNo) return; // daha yeni bir istek var — eski cevabı çizme
  if (!r.ok || !r.govde || !r.govde.basarili) {
    const msg = (r.govde && r.govde.mesaj) || `Hata (${r.kod})`;
    el('#sGovde').innerHTML = `<tr><td colspan="${kolonSayisi()}" class="s-bos s-hata">${esc(msg)}</td></tr>`;
    return;
  }
  const v = r.govde.veri;
  const studyoDegisti = studyo !== !!v.studyo_modu;
  sonVeri = v;
  studyo = !!v.studyo_modu;
  if (studyoDegisti) {
    if (studyo && durum.tur === 3) durum.tur = 0;
    sekmeCiz();
  }
  durumSecenekCiz();
  personelGrupGoster();
  ekKontrollerCiz(v);
  ozetCiz(v);
  baslikCiz();
  const list = v.data || [];
  el('#sGovde').innerHTML = list.length ? list.map(satirCiz).join('')
    : `<tr><td colspan="${kolonSayisi()}" class="s-bos">Kayıt bulunamadı.</td></tr>`;
  sayfalamaCiz(v);
}

iskeletCiz();
baslikCiz();
personelleriYukle();
yukle();
