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

import { qp, api, el, esc, mesaj as toast, onay, tarihStr as ts } from '/js/lib/v2-ortak.js?v=1';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=2';

const kok = document.getElementById('rc-satislar');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
if (salonId && window.rcPanel) window.rcPanel.salonAyarla(salonId);

// Ekran filtreleri. Arama / sayfa / sayfa boyu / sıralama ortak tablo bileşeninde.
// musteri_id yalnız URL'den (?musteri=) — müşteri kartından gelen bağlantılar için.
const durum = { tur: 0, satisDurumu: '', personelId: '', musteriId: qp.get('musteri') || '', tarih1: '', tarih2: '' };
let sonVeri = null;   // son liste cevabı (yetkiler/studyo için)
let studyo = false;

const SEKMELER = [
  { tur: 0, ad: 'Tümü', ikon: 'bi-grid-3x3-gap-fill' },
  { tur: 1, ad: 'Hizmetler/İşlemler', ikon: 'bi-gear' },
  { tur: 3, ad: 'Ürünler', ikon: 'bi-box', studyoGizle: true },
  { tur: 2, ad: 'Paketler', ikon: 'bi-archive' },
];

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
        <a class="s-yeni-btn" id="sYeniSatis" href="/v2/yeni-satis?sube=${salonId}" hidden><i class="bi bi-plus-lg"></i> <span>Yeni Satış &amp; Tahsilat</span></a>
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
          <div class="s-f-grup s-f-ek" id="sFaturaGizleGrup" style="display:none">
            <label>&nbsp;</label>
            <button type="button" class="s-fatura-gizle" id="sFaturaGizle" title="Faturasız işlemleri gizle / göster">
              <i class="bi bi-file-earmark-text"></i> <span>Faturasızları gizle</span>
            </button>
          </div>
        </div>
      </div>

      <div id="sTablo"></div>
    </div>`;

  sekmeCiz();
  durumSecenekCiz();

  el('#sSekmeler').addEventListener('click', (e) => {
    const b = e.target.closest('.s-sekme');
    if (!b) return;
    durum.tur = parseInt(b.dataset.tur, 10);
    sekmeCiz();
    yukle();
  });

  el('#sAralik').addEventListener('change', (e) => {
    const v = e.target.value;
    document.querySelectorAll('.s-ozel').forEach((x) => { x.style.display = v === 'ozel' ? '' : 'none'; });
    if (v === 'ozel') return;
    [durum.tarih1, durum.tarih2] = aralik(v);
    tarihSet('#sTarih1', durum.tarih1); tarihSet('#sTarih2', durum.tarih2);
    yukle();
  });
  el('#sDurum').addEventListener('change', (e) => { durum.satisDurumu = e.target.value; yukle(); });
  el('#sPersonel').addEventListener('change', (e) => { durum.personelId = e.target.value; yukle(); });
  el('#sFaturaGizle').addEventListener('click', faturasizGizleTikla);

  ['#sTarih1', '#sTarih2'].forEach((sel, i) => {
    const alan = i === 0 ? 'tarih1' : 'tarih2';
    const degisti = (str) => { durum[alan] = str; yukle(); };
    if (window.flatpickr) {
      el(sel)._fp = flatpickr(el(sel), { dateFormat: 'Y-m-d', locale: 'tr', allowInput: true, disableMobile: true, onChange: (_d, str) => degisti(str) });
    } else {
      el(sel).addEventListener('change', (e) => degisti(e.target.value));
    }
  });

  // Ortak veri tablosu: sayfa boyu (10/25/50/100), tablo içi arama, sıralama, sayfalama.
  tablo = veriTablosu(el('#sTablo'), {
    anahtar: 'satislar',
    tabloSinif: 's-tablo',
    kolonlar: kolonlar(),
    sirala: { alan: 'tarih', yon: 'desc' },
    araIpucu: 'Müşteri, hizmet/ürün/paket, personel, adisyon no…',
    bosMesaj: 'Kayıt bulunamadı.',
    satir: satirCiz,
    kaynak: async (p) => {
      if (!salonId) return { ok: false, kod: 422, govde: { mesaj: 'Şube seçili değil (?sube=... bekleniyor).' } };
      const r = await api('GET', '/satislar', {
        params: {
          ...p, salon_id: salonId, tarih1: durum.tarih1, tarih2: durum.tarih2, tur: durum.tur,
          personel_id: durum.personelId, musteri_id: durum.musteriId, satis_durumu: durum.satisDurumu,
        },
      });
      // Satırlar çizilmeden ÖNCE stüdyo/kolon setini güncelle (satirCiz buna bakar).
      if (r.ok && r.govde && r.govde.basarili) ekranGuncelle(r.govde.veri);
      return r;
    },
  });

  // Satır işlemleri (delegasyon — tablo kapsayıcısı kalıcı)
  el('#sTablo').addEventListener('click', satirIslem);
}

let tablo = null;
const yukle = () => tablo.yenile();

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
  const { ok, govde } = await api('GET', '/personel', { params: { salon_id: salonId, per_page: 100 } });
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

// Kolon seti (stüdyoda para kolonları yerine Ödeme Tarihi — eski panel).
function kolonlar() {
  const para = studyo ? [] : [
    { baslik: 'İndirim', sinif: 'rc-sag', sirala: 'indirim' }, { baslik: 'Toplam', sinif: 'rc-sag', sirala: 'toplam' },
    { baslik: 'Ödenen', sinif: 'rc-sag', sirala: 'odenen' }, { baslik: 'Kalan', sinif: 'rc-sag', sirala: 'kalan' },
  ];
  return [
    { baslik: 'Durum', sinif: 's-k-durum', sirala: 'durum' },
    { baslik: 'Satış Tarihi', sirala: 'tarih' },
    { baslik: 'Müşteri', sirala: 'musteri' },
    { baslik: 'Yaklaşan Ödeme', sirala: 'yaklasan' },
    { baslik: 'İçerik' },
    ...para,
    ...(studyo ? [{ baslik: 'Ödeme Tarihi', sirala: 'odeme_tarihi' }] : []),
    { baslik: 'İşlemler', sinif: 'rc-orta s-k-islem' },
  ];
}

// Satır durumu (a.kapali) ve izinli işlemler (a.aksiyonlar) API'den hazır gelir —
// burada yalnızca ÇİZİLİR, iş kuralı hesaplanmaz (Flutter ile aynı sözleşme).
function islemlerCiz(a) {
  const x = a.aksiyonlar || {};
  const url = `/v2/tahsilat/${a.id}`;
  const b = [];
  if (x.odeme_al) b.push(`<button class="s-ib s-ib-yesil" data-islem="odeme-al" data-id="${a.id}" title="Ödeme Alındı"><i class="bi bi-check-lg"></i></button>`);
  if (x.odeme_geri_al) b.push(`<button class="s-ib s-ib-uyari" data-islem="odeme-geri" data-id="${a.id}" title="Ödemeyi Geri Al"><i class="bi bi-arrow-counterclockwise"></i></button>`);
  if (x.tahsil_et) b.push(`<a class="s-ib-metin" href="${url}" title="Tahsil Et"><i class="bi bi-cash-coin"></i> Tahsil Et</a>`);
  if (x.odeme_bilgisi) b.push(`<a class="s-ib s-ib-mor" href="${url}" title="Ödeme Bilgileri"><i class="bi bi-eye"></i></a>`);
  if (x.duzenle) b.push(`<a class="s-ib s-ib-gri" href="${url}" title="Düzenle"><i class="bi bi-pencil-square"></i></a>`);
  if (x.fatura) {
    const fk = Number(a.fatura_kesildi) === 1;
    b.push(`<button class="s-ib ${fk ? 's-ib-yesil' : 's-ib-soluk'}" data-islem="fatura" data-id="${a.id}" title="${fk ? 'Faturalı (kaldırmak için tıkla)' : 'Faturasız (işaretlemek için tıkla)'}"><i class="bi bi-file-earmark-text"></i></button>`);
  }
  if (x.sil) b.push(`<button class="s-ib s-ib-kirmizi" data-islem="sil" data-id="${a.id}" data-musteri="${esc(a.musteri)}" title="Adisyonu Sil"><i class="bi bi-trash3"></i></button>`);
  return `<div class="s-islemler">${b.join('')}</div>`;
}

function satirCiz(a) {
  const rozet = a.kapali
    ? '<span class="s-rozet s-r-kapali">Kapalı</span>'
    : '<span class="s-rozet s-r-acik">Açık</span>';
  const icerik = esc(a.icerikKisaltilmis || a.icerik || '').trim().replace(/\r?\n/g, '<br>');
  const para = studyo ? '' : `
    <td class="rc-sag">${esc(a.indirim || '0,00')} ₺</td>
    <td class="rc-sag">${esc(a.toplam)} ₺</td>
    <td class="rc-sag">${esc(a.odenen)} ₺</td>
    <td class="rc-sag s-kalan${a.kapali ? ' s-sifir' : ''}">${esc(a.kalan_tutar)} ₺</td>`;
  const st = studyo ? `<td class="rc-nowrap">${esc(a.odendi_tarihi || '-')}</td>` : '';
  return `<tr>
    <td>${rozet}</td>
    <td class="rc-nowrap">${esc(a.acilis_tarihi)}${a.acilis_saati ? ' <small>' + esc(a.acilis_saati) + '</small>' : ''}</td>
    <td>${esc(a.musteri)}</td>
    <td class="rc-nowrap">${esc(a.planlanan_alacak_tarihi || '-')}</td>
    <td class="s-icerik">${icerik}</td>
    ${para}${st}
    <td class="rc-orta">${islemlerCiz(a)}</td>
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
    sonuc = await api('DELETE', `/adisyon/${id}`);
  } else if (islem === 'fatura') {
    b.disabled = true;
    sonuc = await api('PATCH', `/adisyon/${id}/fatura`);
  } else if (islem === 'odeme-al' || islem === 'odeme-geri') {
    const al = islem === 'odeme-al';
    if (!al && !(await onay('Bu adisyonun tüm tahsilatları kaldırılacak ve adisyon tekrar açık olacak.', { baslik: 'Ödemeyi geri al', tamam: 'Geri Al' }))) return;
    b.disabled = true;
    sonuc = await api('PATCH', `/adisyon/${id}/odeme`, { body: { alindi: al } });
  } else {
    return;
  }
  if (!sonuc.ok || !sonuc.govde || !sonuc.govde.basarili) {
    b.disabled = false;
    toast((sonuc.govde && sonuc.govde.mesaj) || `İşlem başarısız (${sonuc.kod})`, 'hata');
    return;
  }
  toast(sonuc.govde.mesaj || 'İşlem tamamlandı', 'ok');
  tablo.yenile(false); // bulunduğu sayfada kal
}

async function faturasizGizleTikla() {
  const b = el('#sFaturaGizle');
  b.disabled = true;
  const r = await api('PATCH', '/satislar/faturasiz-gizle', { params: { salon_id: salonId } });
  b.disabled = false;
  if (!r.ok || !r.govde || !r.govde.basarili) {
    toast((r.govde && r.govde.mesaj) || `İşlem başarısız (${r.kod})`, 'hata');
    return;
  }
  toast(r.govde.veri.faturasiz_gizle ? 'Faturasız işlemler gizlendi' : 'Faturasız işlemler gösteriliyor', 'ok');
  yukle();
}

function ekKontrollerCiz(v) {
  const y = v.yetkiler || {};
  // Yeni Satış (v2 /yeni-satis): yalnız satis.adisyon_olustur; stüdyoda eski adı "Yeni Satış".
  el('#sYeniSatis').hidden = !y.adisyon_olustur;
  el('#sYeniSatis span').textContent = studyo ? 'Yeni Satış' : 'Yeni Satış & Tahsilat';
  el('#sFaturaGizleGrup').style.display = y.faturasiz_gizle ? '' : 'none';
  const btn = el('#sFaturaGizle');
  btn.classList.toggle('aktif', !!v.faturasiz_gizle_aktif);
  btn.querySelector('span').textContent = v.faturasiz_gizle_aktif ? 'Faturasızlar gizli' : 'Faturasızları gizle';
}

// Liste cevabıyla ekranın tablo DIŞI kısımları (kartlar, sekmeler, filtre seçenekleri).
function ekranGuncelle(v) {
  const studyoDegisti = studyo !== !!v.studyo_modu;
  sonVeri = v;
  studyo = !!v.studyo_modu;
  if (studyoDegisti) {
    if (studyo && durum.tur === 3) durum.tur = 0;
    sekmeCiz();
    tablo.kolonlarAyarla(kolonlar());
  }
  durumSecenekCiz();
  personelGrupGoster();
  ekKontrollerCiz(v);
  ozetCiz(v);
}

iskeletCiz();
personelleriYukle();
yukle();
