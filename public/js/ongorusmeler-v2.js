/**
 * Ön Görüşmeler (v2) — build'siz island (sade ES module).
 *
 * Eski /isletmeyonetim/ongorusmeler ekranının (ongorusmeler.blade + ongorusme-modal + custom.js
 * ongorusme*) v2 karşılığı, aynı akış: tablo (Oluşturma, Müşteri, Referans, Telefon, Randevu
 * Tarihi, Ön Görüşme Nedeni, Görüşmeyi Yapan, Durum, İşlemler), Yeni Ön Görüşme / Düzenle modalı
 * (Müşteri Bilgileri · Görüşme Detayı · Tarih & Personel), Satış Yapıldı (nedene göre seans/fiyat/
 * adet) → tahsilat ekranı, Satış Yapılmadı (sebep notu). Ek: durum sekmeleri (sayaçlı).
 *
 * TEK KAYNAK: liste + sayaçlar, neden kataloğu (fiyat/seans), personel/oda, sözlükler, yetkiler,
 * satır işlemleri (islemler.duzenle/sonuclandir) /api/v2/ongorusme/* uçlarından — Flutter da aynı
 * uçları kullanır; burada iş kuralı yok. Yazmalar ONGORUSME_V2_YAZMA_AKTIF kapalıyken 409 (toast).
 */
import { qp, api, el, esc, TL, mesaj, onay, yazSonuc, tarihStr } from '/js/lib/v2-ortak.js?v=1';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=2';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { musteriSecici } from '/js/lib/v2-secici.js?v=2';
import { kilitle, satisAltButonlari, satisSonrasi } from '/js/lib/v2-bilesen.js?v=2';
import { trTarih } from '/js/lib/v2-donem.js?v=1';

const kok = document.getElementById('rc-ongorusme');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;

const ROZET = { beklemede: 'turuncu', satis_yapildi: 'yesil', satis_yapilmadi: 'kirmizi' };
const SEKME_IKON = { '': 'bi-list-ul', beklemede: 'bi-hourglass-split', satis_yapildi: 'bi-check-circle-fill', satis_yapilmadi: 'bi-x-circle-fill' };

let fv = { nedenler: [], personeller: [], odalar: [], referanslar: [], cinsiyetler: [], durumlar: [], yetkiler: {}, saat_araligi: {} };
let durum = qp.get('durum') || '';
let tablo = null;
let sonListe = [];
let sayaclar = {};

// ─────────────────────────── yardımcılar ───────────────────────────

const yetki = (k) => !!fv.yetkiler?.[k];
const fiyatGizli = () => !!fv.studyo_modu; // stüdyo MODEL B
const oku = (yol, params = {}) => api('GET', yol, { params: { ...params, salon_id: salonId } });
const yaz = (method, yol, body = {}) => api(method, yol, { body: { ...body, salon_id: salonId } });
const sayiVeyaNull = (v) => (String(v ?? '').trim() === '' ? null : Number(String(v).replace(',', '.')));
const kayitBul = (id) => sonListe.find((k) => String(k.id) === String(id));
const nedenler = () => fv.nedenler.flatMap((g) => g.secenekler.map((s) => ({ ...s, grup: g.etiket })));
const nedenBul = (anahtar) => nedenler().find((s) => s.anahtar === anahtar);
const secenekler = (liste, secili, bos = null, deger = 'id', ad = 'ad') =>
  (bos !== null ? `<option value="">${esc(bos)}</option>` : '')
  + liste.map((x) => `<option value="${esc(x[deger])}"${String(secili ?? '') === String(x[deger]) ? ' selected' : ''}>${esc(x[ad])}</option>`).join('');
const alan = (etiket, html, sinif = '') => `<div class="rc-alan ${sinif}"><label>${etiket}</label>${html}</div>`;
const tarihBagla = (inp) => { if (window.flatpickr) window.flatpickr(inp, { locale: 'tr', dateFormat: 'Y-m-d', altInput: true, altFormat: 'd.m.Y', allowInput: true, disableMobile: true }); };

/** Saat seçenekleri sunucudaki aralıktan (eski modal: 07:00–23:45, 15 dk). */
function saatSecenekleri(secili) {
  const { baslangic = '07:00', bitis = '23:45', adim_dk: adim = 15 } = fv.saat_araligi || {};
  const dk = (s) => { const [h, m] = s.split(':').map(Number); return h * 60 + m; };
  const out = [];
  for (let t = dk(baslangic); t <= dk(bitis); t += adim) out.push(`${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`);
  if (secili && !out.includes(secili)) out.push(secili);
  return '<option value="">Seçiniz</option>' + out.map((s) => `<option${s === secili ? ' selected' : ''}>${s}</option>`).join('');
}

// ─────────────────────────── iskelet + tablo ───────────────────────────

function iskeletCiz() {
  kok.innerHTML = `
    <div class="og-kabuk">
      <div class="rc-sayfa-ust">
        <div class="rc-sayfa-baslik">
          <span class="rc-sayfa-ikon"><i class="bi bi-chat-left-text"></i></span>
          <div><h1>Ön Görüşmeler</h1><div class="rc-sayfa-alt">${esc(fv.musteri_etiket || 'Müşteri')} ön görüşmeleri ve satışa dönüşüm</div></div>
        </div>
        <div class="rc-sayfa-aksiyon">
          ${yetki('ekle_duzenle') ? '<button class="rc-btn rc-btn-yesil" id="ogYeni"><i class="bi bi-plus-lg"></i> Yeni Ön Görüşme</button>' : ''}
        </div>
      </div>
      <div class="rc-sekmeler" id="ogSekmeler" role="tablist"></div>
      <div id="ogTablo"></div>
    </div>`;

  el('#ogYeni')?.addEventListener('click', () => gorusmeFormu(null));
  el('#ogSekmeler').addEventListener('click', (e) => {
    const b = e.target.closest('[data-durum]');
    if (!b) return;
    durum = b.dataset.durum;
    const u = new URL(location.href);
    if (durum) u.searchParams.set('durum', durum); else u.searchParams.delete('durum');
    history.replaceState(null, '', u);
    sekmeCiz();
    tablo.yenile();
  });

  tablo = veriTablosu(el('#ogTablo'), {
    anahtar: 'ongorusmeler', birim: 'görüşme', varsayilanBoy: 25, tabloSinif: 'og-tablo',
    araIpucu: 'Müşteri, telefon, neden veya personel…', bosMesaj: 'Ön görüşme bulunamadı.',
    sirala: { alan: 'created_at', yon: 'desc' },
    kolonlar: [
      { baslik: 'Oluşturma', sirala: 'created_at' }, { baslik: fv.musteri_etiket || 'Müşteri', sirala: 'ad_soyad' },
      { baslik: 'Referans' }, { baslik: 'Telefon' }, { baslik: 'Randevu Tarihi', sirala: 'hatirlatma_tarihi' },
      { baslik: 'Ön Görüşme Nedeni' }, { baslik: 'Görüşmeyi Yapan', sirala: 'personel_adi' },
      { baslik: 'Durum', sirala: 'durum' }, { baslik: 'İşlemler', sinif: 'rc-orta' },
    ],
    satir: satirCiz,
    kaynak: (p) => (salonId ? oku('/ongorusme', { ...p, durum })
      : Promise.resolve({ ok: false, kod: 422, govde: { mesaj: 'Şube seçili değil (?sube=... bekleniyor).' } })),
    cizildi: (liste, v) => { sonListe = liste; sayaclar = v?.sayaclar || {}; sekmeCiz(); },
  });
  el('#ogTablo').addEventListener('click', satirTiklama);
  sekmeCiz();
}

function sekmeCiz() {
  const sekmeler = [{ kod: '', ad: 'Tümü', sayac: 'tumu' }, ...fv.durumlar.map((d) => ({ ...d, sayac: d.kod }))];
  el('#ogSekmeler').innerHTML = sekmeler.map((s) => `
    <button class="rc-sekme${s.kod === durum ? ' aktif' : ''}" data-durum="${esc(s.kod)}" role="tab" aria-selected="${s.kod === durum}">
      <i class="bi ${SEKME_IKON[s.kod] || 'bi-dot'}"></i> ${esc(s.ad)} <span class="rc-sayac">${sayaclar[s.sayac] ?? '…'}</span>
    </button>`).join('');
}

function satirCiz(k) {
  const islem = (a, ikon, baslik, sinif = '') => `<button class="rc-islem ${sinif}" data-aksiyon="${a}" data-id="${k.id}" title="${baslik}"><i class="bi ${ikon}"></i></button>`;
  const rozet = `<span class="rc-rozet rc-rozet-${ROZET[k.durum_kodu] || 'gri'}">${esc(k.durum_adi)}</span>`;
  const islemler = [
    k.islemler.duzenle ? islem('duzenle', 'bi-pencil', 'Düzenle') : '',
    k.islemler.sonuclandir ? islem('yapildi', 'bi-cart-check', 'Satış Yapıldı', 'og-olumlu') : '',
    k.islemler.sonuclandir ? islem('yapilmadi', 'bi-x-lg', 'Satış Yapılmadı', 'rc-tehlike') : '',
  ].join('');
  return `<tr>
    <td class="og-soluk rc-nowrap">${esc(trTarih(k.created_at))}</td>
    <td class="og-ad">${esc(k.ad_soyad)}</td>
    <td class="og-soluk">${esc(k.musteri_tipi_adi || '—')}</td>
    <td class="rc-nowrap og-tel">${esc(k.cep_telefon || '—')}</td>
    <td class="rc-nowrap">${esc(trTarih(k.hatirlatma_tarihi))}${k.on_gorusme_saati ? ` <small class="og-soluk">${esc(k.on_gorusme_saati)}</small>` : ''}</td>
    <td>${k.neden ? `<span class="og-neden-tur">${esc(k.neden.etiket)}</span> ${esc(k.neden.ad || '—')}` : '<span class="og-soluk">Bilinmiyor</span>'}</td>
    <td class="og-soluk">${esc(k.personel?.personel_adi || '—')}</td>
    <td>${k.durum_kodu === 'satis_yapilmadi' ? `<button class="og-rozet-btn" data-aksiyon="not" data-id="${k.id}" title="Sebebi gör">${rozet}</button>` : rozet}</td>
    <td class="rc-orta"><span class="rc-islemler">${islemler || '<span class="og-soluk">—</span>'}</span></td>
  </tr>`;
}

function satirTiklama(e) {
  const b = e.target.closest('[data-aksiyon]');
  const k = b && kayitBul(b.dataset.id);
  if (!k) return;
  ({
    duzenle: () => gorusmeFormu(k),
    yapildi: () => satisYapildi(k),
    yapilmadi: () => satisYapilmadi(k),
    not: () => modalAc('Satış Yapılmama Sebebi', `<p class="og-not">${esc(k.satisyapilmadi_not || 'Belirtilmemiş')}</p>`,
      '<button class="rc-btn rc-btn-ikincil" data-kapat>Kapat</button>', { ikon: 'chat-quote', altbaslik: k.ad_soyad, dar: true })
      .querySelector('[data-kapat]').addEventListener('click', modalKapat),
  })[b.dataset.aksiyon]?.();
}

// ─────────────────────────── ekle / düzenle ───────────────────────────

function gorusmeFormu(k) {
  const bolum = (ikon, baslik, icerik) => `<div class="og-bolum"><div class="og-bolum-baslik"><i class="bi ${ikon}"></i> ${baslik}</div><div class="rc-form-izgara">${icerik}</div></div>`;
  const nedenSec = '<option value="">Seçiniz</option>' + nedenler()
    .map((s) => `<option value="${esc(s.anahtar)}"${k?.neden?.anahtar === s.anahtar ? ' selected' : ''}>${esc(s.grup)} : ${esc(s.ad)}</option>`).join('');
  const personel = k?.personel_id ?? fv.varsayilan_personel_id;
  const govde = `
    <form id="ogForm" autocomplete="off">
      ${bolum('bi-person', `${esc(fv.musteri_etiket || 'Müşteri')} Bilgileri`, `
        ${alan(`Mevcut ${esc(fv.musteri_etiket || 'Müşteri')} <small>(kayıtlıysa)</small>`, '<div id="fMusteri"></div><span class="rc-ipucu">Daha önce kaydedilmişse buradan seçin, aksi halde aşağıdaki alanları doldurun.</span>', 'rc-tam')}
        ${alan('Ad Soyad *', `<input id="fAd" required maxlength="255" value="${esc(k?.ad_soyad || '')}">`)}
        ${alan('Telefon *', `<input id="fTel" type="tel" value="${esc(k?.cep_telefon || '')}">`)}
        ${alan('E-mail <small>(opsiyonel)</small>', `<input id="fEmail" type="email" maxlength="255" value="${esc(k?.email || '')}">`)}
        ${alan('Cinsiyet', `<select id="fCinsiyet">${secenekler(fv.cinsiyetler, k?.cinsiyet, 'Seçiniz')}</select>`)}`)}
      ${bolum('bi-clipboard2-check', 'Görüşme Detayı', `
        ${alan('Ön Görüşme Sebebi *', `<select id="fNeden" required>${nedenSec}</select>`)}
        ${alan('Referans', `<select id="fReferans">${secenekler(fv.referanslar, k?.musteri_tipi, 'Yok')}</select>`)}`)}
      ${bolum('bi-calendar-event', 'Tarih &amp; Personel', `
        ${alan('Tarih *', `<input id="fTarih" required value="${esc(k?.hatirlatma_tarihi || tarihStr(new Date()))}">`)}
        ${alan('Saat *', `<select id="fSaat" required>${saatSecenekleri(k?.on_gorusme_saati)}</select>`)}
        ${fv.oda_zorunlu ? alan('Oda *', `<select id="fOda" required>${secenekler(fv.odalar, k?.oda_id, 'Seçiniz')}</select>`) : ''}
        ${alan('Görüşmeyi Yapan *', `<select id="fPersonel" required>${secenekler(fv.personeller, personel, 'Seçiniz')}</select>`)}
        ${alan('Açıklama <small>(opsiyonel)</small>', `<textarea id="fAciklama" rows="2" maxlength="2000" placeholder="Görüşme ile ilgili kısa notlar…">${esc(k?.aciklama || '')}</textarea>`, 'rc-tam')}`)}
    </form>`;
  const ov = modalAc(k ? 'Ön Görüşme Düzenleme' : 'Yeni Ön Görüşme', govde,
    '<button class="rc-btn rc-btn-ikincil" type="button" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-birincil" type="submit" form="ogForm" id="fKaydet"><i class="bi bi-check-lg"></i> Kaydet</button>',
    { ikon: 'person-plus', sabit: true, genis: true, altbaslik: k ? k.ad_soyad : 'Müşteri ile yapılacak ön görüşmeyi planlayın.' });
  el('[data-kapat]', ov).onclick = modalKapat;
  tarihBagla(el('#fTarih', ov));

  // Mevcut müşteri seçilince ad / telefon ondan dolar; "Değiştir" serbest girişe döner.
  const baslangic = k?.musteri ? { id: k.musteri.id, ad: k.musteri.name, telefon: k.cep_telefon } : null;
  const secici = musteriSecici(el('#fMusteri', ov), {
    salonId, yol: '/ongorusme/musteri-ara', baslangic,
    onSec: (m) => { if (m) { el('#fAd', ov).value = m.ad || ''; el('#fTel', ov).value = m.telefon || ''; } },
  });

  el('#ogForm', ov).addEventListener('submit', (e) => {
    e.preventDefault();
    const neden = nedenBul(el('#fNeden', ov).value);
    const eksik = [!neden && 'Ön görüşme sebebini seçiniz.', !el('#fPersonel', ov).value && 'Ön görüşmeyi gerçekleştirecek personeli seçiniz.',
      fv.oda_zorunlu && !el('#fOda', ov)?.value && 'Oda seçiniz.', !el('#fSaat', ov).value && 'Saat seçiniz.'].filter(Boolean);
    if (eksik.length) return mesaj(eksik.join(' '), 'hata');
    const d = {
      musteri_id: secici.secili()?.id || null, ad_soyad: el('#fAd', ov).value.trim(), telefon: el('#fTel', ov).value.trim(),
      email: el('#fEmail', ov).value.trim() || null, cinsiyet: sayiVeyaNull(el('#fCinsiyet', ov).value),
      musteri_tipi: sayiVeyaNull(el('#fReferans', ov).value), neden_tur: neden.tur, neden_id: neden.id,
      tarih: el('#fTarih', ov).value, saat: el('#fSaat', ov).value, oda_id: sayiVeyaNull(el('#fOda', ov)?.value),
      personel_id: Number(el('#fPersonel', ov).value), aciklama: el('#fAciklama', ov).value.trim() || null,
    };
    kilitle(el('#fKaydet', ov), 'Kaydediliyor…', () => kaydet(k, d));
  });
}

/** Kaydet; çakışma iş sonucudur (veri.cakismavar='1') → onayla "yine de kaydet". */
async function kaydet(k, d) {
  const gonder = (ek = {}) => (k ? yaz('PATCH', `/ongorusme/${k.id}`, { ...d, ...ek }) : yaz('POST', '/ongorusme', { ...d, ...ek }));
  let r = await gonder();
  if (r.ok && r.govde?.veri?.cakismavar === '1') {
    if (!await onay(`${r.govde.veri.cakisanunsurlar} Yine de kaydetmek istiyor musunuz?`, { baslik: 'Çakışma Var', tamam: 'Yine de kaydet' })) return;
    r = await gonder({ cakisanrandevuekle: 1 });
  }
  if (yazSonuc(r, r.govde?.mesaj)) { modalKapat(); tablo.yenile(!k); }
}

// ─────────────────────────── sonuçlandır ───────────────────────────

/** Satış Yapıldı — nedene göre alanlar (paket: seans + fiyat · ürün: adet · hizmet: seans + fiyat). */
function satisYapildi(k) {
  const n = k.neden;
  if (!n) return mesaj('Ön görüşmenin satış nedeni tanımlı değil; önce düzenleyip sebep seçin.', 'hata');
  const katalog = nedenBul(n.anahtar) || {};
  const fiyatAlani = () => (fiyatGizli() ? '' : alan('Fiyat (₺)', `<input id="sFiyat" type="number" min="0" step="0.01" value="${esc(katalog.fiyat ?? '')}" ${yetki('fiyat_degistir') ? '' : 'readonly title="Fiyat değiştirme yetkiniz yok"'}>`));
  const ozel = {
    paket: () => alan('Seans Sayısı', `<input id="sSeans" type="number" min="0" step="1" value="${esc(katalog.seans ?? '')}">`) + fiyatAlani(),
    urun: () => alan('Adet *', '<input id="sAdet" type="number" min="1" step="1" value="1" required>')
      + (fiyatGizli() ? '' : alan('Birim Fiyat', `<input value="${esc(TL(katalog.fiyat))}" readonly>`)),
    hizmet: () => alan('Seans Sayısı <small>(2+ ise seanslı)</small>', '<input id="sSeans" type="number" min="0" step="1">') + fiyatAlani(),
  }[n.tur];
  const govde = `
    <div class="og-satis-ozet"><span class="og-neden-tur">${esc(n.etiket)}</span> <b>${esc(n.ad || '')}</b></div>
    <div class="rc-form-izgara">
      ${ozel()}
      ${alan('Satış Tarihi', `<input id="sTarih" value="${tarihStr(new Date())}">`)}
      ${alan('Satıcı', `<select id="sPersonel">${secenekler(fv.personeller, k.personel_id, 'Görüşmeyi yapan')}</select>`)}
    </div>`;
  const ov = modalAc('Satış Yapıldı', govde, satisAltButonlari(fiyatGizli(), 'Satışı Oluştur'), { ikon: 'cart-check', sabit: true, altbaslik: k.ad_soyad });
  el('[data-kapat]', ov)?.addEventListener('click', modalKapat);
  tarihBagla(el('#sTarih', ov));

  ov.querySelectorAll('[data-odeme]').forEach((btn) => btn.addEventListener('click', () => kilitle(btn, 'İşleniyor…', async () => {
    const d = { tarih: el('#sTarih', ov).value || null, personel_id: sayiVeyaNull(el('#sPersonel', ov).value) };
    if (el('#sSeans', ov)) d.seans = sayiVeyaNull(el('#sSeans', ov).value);
    if (el('#sAdet', ov)) d.adet = sayiVeyaNull(el('#sAdet', ov).value) || 1;
    // Fiyat yalnız yetkiliyken gider (yetkisizde katalog fiyatı sunucuda kullanılır).
    if (el('#sFiyat', ov) && yetki('fiyat_degistir')) d.fiyat = sayiVeyaNull(el('#sFiyat', ov).value);
    const r = await yaz('POST', `/ongorusme/${k.id}/satis-yapildi`, d);
    if (!yazSonuc(r, r.govde?.mesaj)) return;
    await satisSonrasi(salonId, r.govde.veri.adisyon_id, { studyo: fiyatGizli(), odemeAlindi: btn.dataset.odeme === '1' });
  })));
}

function satisYapilmadi(k) {
  const ov = modalAc('Satış Yapılmadı', alan('Satış yapılmama sebebi *', '<textarea id="yNot" rows="3" maxlength="1000" required></textarea>', 'rc-tam'),
    '<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-birincil" id="yKaydet"><i class="bi bi-check-lg"></i> Kaydet</button>',
    { ikon: 'x-circle', altbaslik: k.ad_soyad, dar: true });
  el('[data-kapat]', ov).onclick = modalKapat;
  el('#yKaydet', ov).onclick = (e) => {
    const not = el('#yNot', ov).value.trim();
    if (!not) return mesaj('Satış yapılmama sebebini giriniz.', 'hata');
    kilitle(e.currentTarget, 'Kaydediliyor…', async () => {
      const r = await yaz('POST', `/ongorusme/${k.id}/satis-yapilmadi`, { not });
      if (yazSonuc(r, r.govde?.mesaj)) { modalKapat(); tablo.yenile(false); }
    });
  };
}

// ─────────────────────────── başlat ───────────────────────────

(async function baslat() {
  if (window.rcPanel && salonId) window.rcPanel.salonAyarla(salonId);
  if (salonId) {
    const r = await oku('/ongorusme/form-verisi');
    if (r.ok && r.govde?.basarili) fv = r.govde.veri;
    else mesaj(r.govde?.mesaj || 'Form verisi alınamadı', 'hata');
  }
  if (!fv.durumlar.some((d) => d.kod === durum)) durum = '';
  iskeletCiz();
  tablo.yenile();
})();
