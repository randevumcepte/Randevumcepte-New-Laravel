/**
 * Stok Yönetimi (v2) — build'siz island (sade ES module).
 *
 * Eski panel "Stok Yönetimi" ekranının (isletmeadmin/stok_yonetimi.blade) v2 karşılığı:
 * özet kartları, ürün tablosu (arama/filtre/sıralama/sayfalama), ürün ekle/düzenle,
 * hareket geçmişi + manuel hareket, hızlı satış, alış girişi, sayım, transfer,
 * kategori/depo/tedarikçi yönetimi.
 *
 * TEK KAYNAK: veri + seçenekler + yetkiler /api/v2/stok/* uçlarından (Flutter da aynı
 * uçları kullanır). Stok seviyesi/rozet rengi, hareket etiket/rengi, birim/tip listeleri
 * sunucudan gelir — burada yeniden hesaplanmaz. Yazma uçları STOK_V2_YAZMA_AKTIF
 * kapalıyken 409 döner; yazSonuc toast gösterir.
 */

import { qp, api, el, esc, TL, mesaj, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=1';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=1';
import { ozetKart } from '/js/lib/v2-bilesen.js?v=1';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { musteriSecici } from '/js/lib/v2-secici.js?v=1';

const kok = document.getElementById('rc-stok');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;

let fv = { kategoriler: [], depolar: [], tedarikciler: [], birimler: [], tipler: [], manuel_hareket_tipleri: [], hareket_tipleri: [], odeme_yontemleri: [], yetkiler: {} };
let tablo = null;
let sonListe = [];

// ─────────────────────────── yardımcılar ───────────────────────────

const yetki = (k) => !!fv.yetkiler[k];
const oku = (yol, params = {}) => api('GET', yol, { params: { ...params, salon_id: salonId } });
const yaz = (method, yol, body = {}) => api(method, yol, { body: method === 'DELETE' ? undefined : { ...body, salon_id: salonId } });
const sayi = (n) => { const x = Number(n || 0); return Number.isInteger(x) ? String(x) : x.toFixed(3).replace(/\.?0+$/, ''); };
const ROZET = { kirmizi: 'rc-rozet-kirmizi', sari: 'rc-rozet-turuncu', yesil: 'rc-rozet-yesil' };
const secenek = (liste, secili, deger = 'id', ad = 'ad', bos = '— Seç —') =>
  (bos !== null ? `<option value="">${esc(bos)}</option>` : '') +
  liste.map((x) => `<option value="${esc(x[deger])}"${String(secili ?? '') === String(x[deger]) ? ' selected' : ''}>${esc(x[ad])}</option>`).join('');
const urunBul = (id) => sonListe.find((u) => String(u.id) === String(id));
const bos = (ikon, metin) => `<div class="st-bos"><i class="bi ${ikon}"></i>${esc(metin)}</div>`;

/** Buton kilidi + metin; işlem bitince eski haline döner. */
async function kilitle(btn, metin, is) {
  const eski = btn.innerHTML;
  btn.disabled = true; btn.innerHTML = `<span class="rc-yukleniyor"></span> ${esc(metin)}`;
  try { return await is(); } finally { btn.disabled = false; btn.innerHTML = eski; }
}

/** Yazma sonrası: tablo (aynı sayfa) + özet + form verisi tazelenir. */
async function tazele({ formVerisi = false } = {}) {
  if (formVerisi) await formVerisiYukle();
  tablo.yenile(false);
  ozetYukle();
}

// ─────────────────────────── veri ───────────────────────────

async function formVerisiYukle() {
  const r = await oku('/stok/form-verisi');
  if (r.ok && r.govde?.basarili) fv = r.govde.veri;
  else mesaj(r.govde?.mesaj || 'Form verisi alınamadı', 'hata');
  // Kategori eklenip silinince tablo filtresi de güncel kalsın (seçim korunur).
  const kat = el('#stKat');
  if (kat) kat.innerHTML = `<option value="">Tüm Kategoriler</option>${secenek(fv.kategoriler, kat.value, 'id', 'ad', null)}`;
}

async function ozetYukle() {
  const r = await oku('/stok/ozet');
  const o = (r.ok && r.govde?.basarili && r.govde.veri) || {};
  el('#stOzet').innerHTML =
    ozetKart('bi-box-seam', 'Toplam Ürün', o.toplam_urun ?? '—', 'mor') +
    ozetKart('bi-exclamation-triangle', 'Düşük Stok', o.dusuk_stok ?? '—', 'turuncu') +
    ozetKart('bi-x-circle', 'Tükenen', o.tukenen ?? '—', 'pembe') +
    ozetKart('bi-wallet2', 'Stok Değeri', o.toplam_satis_degeri != null ? TL(o.toplam_satis_degeri) : '—', 'yesil');
}

/** Seçiciler için tüm aktif ürünler (opsiyonel depo bazlı stokla). */
async function tumUrunler(params = {}) {
  const r = await oku('/stok/urunler', { tumu: 1, ...params });
  return (r.ok && r.govde?.basarili && r.govde.veri.data) || [];
}

// ─────────────────────────── iskelet + tablo ───────────────────────────

function iskeletCiz() {
  const b = (kosul, id, sinif, ikon, ad) => (kosul ? `<button class="rc-btn ${sinif}" id="${id}"><i class="bi ${ikon}"></i> ${ad}</button>` : '');
  const menu = [
    ['kategoriler', 'bi-tags', 'Kategoriler', true],
    ['depolar', 'bi-building', 'Depolar', true],
    ['tedarikciler', 'bi-truck', 'Tedarikçiler', yetki('tedarikci_yonet')],
    ['transfer', 'bi-arrow-left-right', 'Transfer Yap', yetki('stok_giris')],
    ['defter', 'bi-journal-text', 'Hareket Defteri', true],
  ].filter((m) => m[3]).map(([a, i, ad]) => `<button data-menu="${a}"><i class="bi ${i}"></i> ${ad}</button>`).join('');

  kok.innerHTML = `
    <div class="st-kabuk">
      <div class="rc-sayfa-ust">
        <div class="rc-sayfa-baslik">
          <span class="rc-sayfa-ikon"><i class="bi bi-tags"></i></span>
          <div><h1>Stok Yönetimi</h1><div class="rc-sayfa-alt">Ürünler, depolar, alış, sayım ve hızlı satış</div></div>
        </div>
        <div class="rc-sayfa-aksiyon">
          ${b(yetki('tanim_olustur'), 'stYeni', 'rc-btn-birincil', 'bi-plus-lg', 'Yeni Ürün')}
          ${b(yetki('sat'), 'stSatis', 'rc-btn-yesil', 'bi-cart3', 'Hızlı Satış')}
          ${b(yetki('stok_giris'), 'stAlis', 'st-btn-turuncu', 'bi-truck', 'Alış Girişi')}
          ${b(yetki('stok_sayim'), 'stSayim', 'rc-btn-mavi', 'bi-clipboard-check', 'Sayım')}
          <div class="st-menu" id="stMenu">
            <button class="rc-btn st-btn-koyu" id="stMenuBtn"><i class="bi bi-gear"></i> Ayarlar</button>
            <div class="st-menu-liste">${menu}</div>
          </div>
        </div>
      </div>
      <div class="rc-ozet" id="stOzet"></div>
      <div class="st-filtre">
        <select id="stKat"><option value="">Tüm Kategoriler</option>${secenek(fv.kategoriler, null, 'id', 'ad', null)}</select>
        <select id="stTip"><option value="">Tüm Tipler</option>${secenek(fv.tipler, null, 'deger', 'etiket', null)}</select>
        <select id="stSeviye" data-arama="yok">
          <option value="">Tüm Stok Durumları</option><option value="uyari">Düşük / Kritik</option><option value="tukendi">Tükenen</option>
        </select>
      </div>
      <div id="stTablo"></div>
    </div>`;

  const tikla = (id, f) => { const x = el(id); if (x) x.addEventListener('click', f); };
  tikla('#stYeni', () => urunFormu(null));
  tikla('#stSatis', hizliSatis);
  tikla('#stAlis', alisGirisi);
  tikla('#stSayim', sayim);
  tikla('#stMenuBtn', (e) => { e.stopPropagation(); el('#stMenu').classList.toggle('acik'); });
  document.addEventListener('click', () => el('#stMenu')?.classList.remove('acik'));
  el('.st-menu-liste').addEventListener('click', (e) => {
    const m = e.target.closest('[data-menu]')?.dataset.menu;
    ({ kategoriler, depolar, tedarikciler, transfer, defter: () => hareketler(null) })[m]?.();
  });
  ['#stKat', '#stTip', '#stSeviye'].forEach((s) => el(s).addEventListener('change', () => tablo.yenile()));

  tablo = veriTablosu(el('#stTablo'), {
    anahtar: 'stok', tabloSinif: 'st-tablo', birim: 'ürün', varsayilanBoy: 25,
    araIpucu: 'Ürün adı, barkod veya SKU…', bosMesaj: 'Henüz ürün yok.',
    sirala: { alan: 'urun_adi', yon: 'asc' },
    kolonlar: [
      { baslik: 'Ürün', sirala: 'urun_adi' }, { baslik: 'Kategori', sirala: 'kategori' }, { baslik: 'Tip', sirala: 'tip' },
      { baslik: 'Stok', sirala: 'stok_adedi' }, { baslik: 'Alış', sirala: 'alis_fiyati', sinif: 'rc-sag' },
      { baslik: 'Satış', sirala: 'fiyat', sinif: 'rc-sag' }, { baslik: 'Barkod' }, { baslik: 'İşlem', sinif: 'rc-orta' },
    ],
    satir: satirCiz,
    kaynak: (p) => (salonId
      ? oku('/stok/urunler', { ...p, kategori_id: el('#stKat').value, tip: el('#stTip').value, seviye: el('#stSeviye').value })
      : Promise.resolve({ ok: false, kod: 422, govde: { mesaj: 'Şube seçili değil (?sube=... bekleniyor).' } })),
    cizildi: (liste) => { sonListe = liste; },
  });
  el('#stTablo').addEventListener('click', satirTiklama);
}

function satirCiz(u) {
  const kat = u.kategori_adi
    ? `<span class="st-kat-nokta" style="background:${esc(u.kategori_renk || '#999')}"></span>${esc(u.kategori_adi)}` : '<span class="st-soluk">—</span>';
  const islem = (kosul, a, ikon, baslik, sinif = '') => (kosul ? `<button class="rc-islem ${sinif}" data-aksiyon="${a}" data-id="${u.id}" title="${baslik}"><i class="bi ${ikon}"></i></button>` : '');
  return `<tr>
    <td><div class="st-urun-ad">${esc(u.urun_adi)}</div>${u.aciklama ? `<div class="st-urun-alt">${esc(u.aciklama)}</div>` : ''}</td>
    <td>${kat}</td>
    <td><span class="rc-rozet rc-rozet-${u.tip === 'sarf' ? 'kirmizi' : u.tip === 'karma' ? 'mor' : 'mavi'}">${esc(u.tip_etiket)}</span></td>
    <td><span class="rc-rozet ${ROZET[u.stok_renk] || ''}">${sayi(u.stok_adedi)} ${esc(u.birim)}</span></td>
    <td class="rc-sag">${u.alis_fiyati != null ? TL(u.alis_fiyati) : '<span class="st-soluk">—</span>'}</td>
    <td class="rc-sag st-mor">${TL(u.fiyat)}</td>
    <td>${u.barkod ? `<code class="st-barkod">${esc(u.barkod)}</code>` : '<span class="st-soluk">—</span>'}</td>
    <td class="rc-orta"><span class="rc-islemler">
      ${islem(yetki('tanim_olustur'), 'duzenle', 'bi-pencil', 'Düzenle')}
      ${islem(true, 'gecmis', 'bi-clock-history', 'Hareketler')}
      ${islem(yetki('stok_giris'), 'hareket', 'bi-plus-slash-minus', 'Stok hareketi (fire/iade/düzeltme)')}
      ${islem(yetki('tanim_olustur'), 'sil', 'bi-trash', 'Sil', 'rc-tehlike')}
    </span></td>
  </tr>`;
}

async function satirTiklama(e) {
  const b = e.target.closest('[data-aksiyon]');
  if (!b) return;
  const u = urunBul(b.dataset.id);
  if (!u) return;
  const a = b.dataset.aksiyon;
  if (a === 'duzenle') return urunFormu(u);
  if (a === 'gecmis') return hareketler(u);
  if (a === 'hareket') return manuelHareket(u);
  if (a === 'sil' && await onay(`${u.urun_adi} silinsin mi? (Pasif olarak işaretlenir)`, { baslik: 'Ürünü Sil', tamam: 'Sil' })) {
    if (yazSonuc(await yaz('DELETE', `/stok/urunler/${u.id}`), 'Ürün silindi.')) tazele();
  }
}

// ─────────────────────────── ürün ekle / düzenle ───────────────────────────

function urunFormu(u) {
  const v = (k, d = '') => esc(u?.[k] ?? d);
  const alan = (ad, html, sinif = '') => `<div class="rc-alan ${sinif}"><label>${ad}</label>${html}</div>`;
  const tip = u?.tip || 'satis';
  const govde = `
    <div class="st-bolum"><div class="st-bolum-baslik">Temel Bilgiler</div><div class="rc-form-izgara">
      ${alan('Ürün Adı *', `<input id="fAd" value="${v('urun_adi')}">`)}
      ${alan('Tip', `<div class="st-segment" id="fTip">${fv.tipler.map((t) => `<button type="button" data-tip="${esc(t.deger)}" class="${t.deger === tip ? 'aktif' : ''}">${esc(t.etiket)}</button>`).join('')}</div>`)}
      ${alan('Kategori <small>(opsiyonel)</small>', `<select id="fKat">${secenek(fv.kategoriler, u?.kategori_id)}</select>`)}
      ${alan('Tedarikçi <small>(opsiyonel)</small>', `<select id="fTed">${secenek(fv.tedarikciler, u?.tedarikci_id)}</select>`)}
    </div></div>
    <div class="st-bolum"><div class="st-bolum-baslik">Fiyatlar</div><div class="rc-form-izgara">
      ${alan('Alış Fiyatı (₺)', `<input id="fAlis" type="number" step="0.01" min="0" value="${v('alis_fiyati')}">`)}
      ${alan('Satış Fiyatı (₺) *', `<input id="fFiyat" type="number" step="0.01" min="0" value="${v('fiyat')}">`)}
      ${alan('KDV %', `<input id="fKdv" type="number" step="0.01" min="0" max="100" value="${v('kdv_orani')}">`)}
    </div></div>
    <div class="st-bolum"><div class="st-bolum-baslik">Stok</div><div class="rc-form-izgara">
      ${alan('Birim', `<select id="fBirim" data-arama="yok">${secenek(fv.birimler, u?.birim || 'adet', 'deger', 'etiket', null)}</select>`)}
      ${alan(u ? 'Mevcut Stok <small>(değişirse düzeltme hareketi yazılır)</small>' : 'Başlangıç Stoğu', `<input id="fStok" type="number" step="0.001" value="${v('stok_adedi', 0)}">`)}
      ${alan('Düşük Stok Sınırı <small>(sarı uyarı)</small>', `<input id="fDusuk" type="number" step="0.001" min="0" value="${v('dusuk_stok_siniri')}">`)}
      ${alan('Kritik Stok Sınırı <small>(kırmızı uyarı)</small>', `<input id="fKritik" type="number" step="0.001" min="0" value="${v('kritik_stok_siniri')}">`)}
    </div></div>
    <div class="st-bolum"><div class="st-bolum-baslik">Tanımlayıcı</div><div class="rc-form-izgara">
      ${alan('Barkod', `<input id="fBarkod" value="${v('barkod')}">`)}
      ${alan('SKU <small>(opsiyonel)</small>', `<input id="fSku" value="${v('sku')}">`)}
      ${alan('Açıklama <small>(opsiyonel)</small>', `<textarea id="fAciklama" rows="2">${v('aciklama')}</textarea>`, 'rc-tam')}
    </div></div>`;
  const ov = modalAc(u ? 'Ürün Düzenle' : 'Yeni Ürün', govde,
    '<button class="rc-btn rc-btn-ikincil" data-kapat>İptal</button><button class="rc-btn rc-btn-birincil" id="fKaydet"><i class="bi bi-check-lg"></i> Kaydet</button>',
    { ikon: 'box-seam', sabit: true, altbaslik: u?.urun_adi });
  el('[data-kapat]', ov).onclick = modalKapat;
  el('#fTip', ov).onclick = (e) => {
    const t = e.target.closest('[data-tip]');
    if (t) el('#fTip', ov).querySelectorAll('button').forEach((x) => x.classList.toggle('aktif', x === t));
  };
  el('#fKaydet', ov).onclick = (e) => kilitle(e.currentTarget, 'Kaydediliyor…', async () => {
    const d = {
      urun_adi: el('#fAd', ov).value.trim(),
      tip: el('#fTip .aktif', ov)?.dataset.tip || 'satis',
      kategori_id: el('#fKat', ov).value || null,
      tedarikci_id: el('#fTed', ov).value || null,
      alis_fiyati: el('#fAlis', ov).value || null,
      fiyat: el('#fFiyat', ov).value,
      kdv_orani: el('#fKdv', ov).value || null,
      birim: el('#fBirim', ov).value,
      stok_adedi: el('#fStok', ov).value || 0,
      dusuk_stok_siniri: el('#fDusuk', ov).value || null,
      kritik_stok_siniri: el('#fKritik', ov).value || null,
      barkod: el('#fBarkod', ov).value.trim() || null,
      sku: el('#fSku', ov).value.trim() || null,
      aciklama: el('#fAciklama', ov).value.trim() || null,
    };
    // Düzenlemede stok yalnız kullanıcı değiştirdiyse gönderilir — modal açıkken yapılan
    // bir satış, eski değerle "düzeltme" hareketiyle geri alınmasın.
    if (u && Number(d.stok_adedi) === Number(u.stok_adedi)) delete d.stok_adedi;
    const r = u ? await yaz('PATCH', `/stok/urunler/${u.id}`, d) : await yaz('POST', '/stok/urunler', d);
    if (yazSonuc(r, u ? 'Ürün güncellendi.' : 'Ürün eklendi.')) { modalKapat(); tazele(); }
  });
}

// ─────────────────────────── hareketler ───────────────────────────

/** u verilirse o ürünün hareketleri, yoksa tüm salonun hareket defteri. */
function hareketler(u) {
  const ov = modalAc(u ? `${u.urun_adi} — Hareketler` : 'Hareket Defteri', '<div id="hTablo"></div>', '',
    { ikon: 'clock-history', genis: true });
  veriTablosu(el('#hTablo', ov), {
    anahtar: 'stok_hareket', birim: 'hareket', bosMesaj: 'Henüz hareket yok.', araIpucu: 'Ürün, depo, açıklama…',
    sirala: { alan: 'tarih', yon: 'desc' },
    kolonlar: [
      { baslik: 'Tarih', sirala: 'tarih' }, ...(u ? [] : [{ baslik: 'Ürün', sirala: 'urun_adi' }]),
      { baslik: 'Tip', sirala: 'hareket_tipi' }, { baslik: 'Depo' }, { baslik: 'Miktar', sirala: 'miktar', sinif: 'rc-sag' }, { baslik: 'Açıklama' },
    ],
    satir: (h) => `<tr>
      <td class="rc-nowrap">${esc(h.tarih || '')}</td>${u ? '' : `<td>${esc(h.urun_adi)}</td>`}
      <td><span class="rc-rozet" style="background:${esc(h.hareket_renk)}20;color:${esc(h.hareket_renk)}">${esc(h.hareket_etiket)}</span></td>
      <td>${esc(h.depo_adi || '—')}</td>
      <td class="rc-sag" style="font-weight:800;color:${h.miktar > 0 ? 'var(--rc-yesil)' : 'var(--rc-kirmizi)'}">${h.miktar > 0 ? '+' : ''}${sayi(h.miktar)}</td>
      <td>${esc(h.aciklama)}</td></tr>`,
    kaynak: (p) => oku('/stok/hareketler', { ...p, urun_id: u?.id }),
  });
}

function manuelHareket(u) {
  const ov = modalAc('Stok Hareketi', `
    <div class="rc-form-izgara">
      <div class="rc-alan"><label>Hareket Tipi</label><select id="mTip" data-arama="yok">${secenek(fv.manuel_hareket_tipleri, 'fire', 'deger', 'etiket', null)}</select></div>
      <div class="rc-alan"><label>Depo</label><select id="mDepo">${secenek(fv.depolar, u.varsayilan_depo_id, 'id', 'depo_adi', null)}</select></div>
      <div class="rc-alan"><label>Miktar *</label><input id="mMiktar" type="number" step="0.001"><span class="rc-ipucu">Fire/sarf düşer, iade ekler; manuel düzeltmede eksi değer girilebilir.</span></div>
      <div class="rc-alan rc-tam"><label>Açıklama</label><input id="mAciklama"></div>
    </div>`,
  '<button class="rc-btn rc-btn-ikincil" data-kapat>İptal</button><button class="rc-btn rc-btn-birincil" id="mKaydet"><i class="bi bi-check-lg"></i> Kaydet</button>',
  { ikon: 'plus-slash-minus', dar: true, altbaslik: `${u.urun_adi} · mevcut ${sayi(u.stok_adedi)} ${u.birim}` });
  el('[data-kapat]', ov).onclick = modalKapat;
  el('#mKaydet', ov).onclick = (e) => kilitle(e.currentTarget, 'Kaydediliyor…', async () => {
    const r = await yaz('POST', '/stok/hareketler', {
      urun_id: u.id, hareket_tipi: el('#mTip', ov).value, depo_id: el('#mDepo', ov).value || null,
      miktar: el('#mMiktar', ov).value, aciklama: el('#mAciklama', ov).value.trim() || null,
    });
    if (yazSonuc(r, 'Stok hareketi kaydedildi.')) { modalKapat(); tazele(); }
  });
}

// ─────────────────────────── hızlı satış ───────────────────────────

async function hizliSatis() {
  const tahsilatAcik = yetki('tahsilat_al') && fv.tahsilat_yazma_aktif;
  const ov = modalAc('Hızlı Satış', `
    <div class="st-bolum"><div class="st-bolum-baslik">Müşteri &amp; Personel</div><div class="rc-form-izgara">
      <div class="rc-alan"><label>Müşteri *</label><div id="hsMusteri"></div></div>
      <div class="rc-alan"><label>Personel <small>(opsiyonel)</small></label><select id="hsPersonel"><option value="">— Seç —</option></select></div>
    </div></div>
    <div class="st-bolum"><div class="st-bolum-baslik">Ürün Ekle</div>
      <div class="rc-alan"><input id="hsAra" placeholder="Barkod okutun veya ürün adıyla filtreleyin (Enter: ekle)" autocomplete="off"></div>
      <div class="st-urun-liste" id="hsUrunler"><div class="st-bos"><span class="rc-yukleniyor"></span></div></div>
    </div>
    <div class="st-bolum" style="padding:0"><div id="hsSepet"></div></div>
    <div class="st-bolum"><div class="rc-form-izgara">
      <div class="rc-alan"><label>Ödeme Yöntemi</label><select id="hsYontem" data-arama="yok">${secenek(fv.odeme_yontemleri, null, 'id', 'ad', null)}</select></div>
      <div class="rc-alan"><label>&nbsp;</label><label class="st-onay"><input type="checkbox" id="hsPesin" ${tahsilatAcik ? 'checked' : 'disabled'}> Ödemeyi şimdi al (peşin)</label>
        ${tahsilatAcik ? '' : '<span class="rc-ipucu">Peşin tahsilat bu hesapta kapalı; satış ödeme bekliyor olarak kaydedilir.</span>'}</div>
    </div></div>
    <div class="st-toplam"><span>Toplam</span><b id="hsToplam">${TL(0)}</b></div>`,
  '<button class="rc-btn rc-btn-ikincil" data-kapat>İptal</button><button class="rc-btn rc-btn-yesil" id="hsTamam"><i class="bi bi-check-lg"></i> Satışı Tamamla</button>',
  { ikon: 'cart3', genis: true, sabit: true });
  el('[data-kapat]', ov).onclick = modalKapat;

  const musteri = musteriSecici(el('#hsMusteri', ov), { salonId, yol: '/stok/musteri-ara' });
  const sepet = [];
  let urunler = [];
  // Çift tıklama / ağ tekrarı aynı satışı iki kez yazmasın — modal başına tek anahtar.
  const anahtar = crypto.randomUUID();

  api('GET', '/personel', { params: { salon_id: salonId, tumu: 1, per_page: 100 } }).then((r) => {
    const l = (r.ok && r.govde?.basarili && r.govde.veri.data) || [];
    el('#hsPersonel', ov).innerHTML = secenek(l.filter((p) => p.aktif), null, 'id', 'personel_adi');
  });

  const listeCiz = (f = '') => {
    const a = f.toLocaleLowerCase('tr').trim();
    const l = a ? urunler.filter((u) => `${u.urun_adi} ${u.barkod} ${u.sku}`.toLocaleLowerCase('tr').includes(a)) : urunler;
    el('#hsUrunler', ov).innerHTML = l.length
      ? l.map((u) => `<button type="button" class="st-urun-satir" data-id="${u.id}"><span>${esc(u.urun_adi)} <small class="st-soluk">· stok: ${sayi(u.stok_adedi)} ${esc(u.birim)}</small></span><span class="st-mor">${TL(u.fiyat)}</span><i class="bi bi-plus-circle" style="color:var(--rc-yesil)"></i></button>`).join('')
      : bos('bi-search', 'Ürün bulunamadı');
  };
  const sepetCiz = () => {
    el('#hsSepet', ov).innerHTML = sepet.length
      ? sepet.map((k, i) => `<div class="st-sepet-satir"><div><b>${esc(k.urun.urun_adi)}</b><div class="st-urun-alt">${TL(k.urun.fiyat)} / ${esc(k.urun.birim)}</div></div>
          <input type="number" min="1" step="1" value="${k.miktar}" data-i="${i}">
          <b class="st-mor" style="min-width:90px;text-align:right">${TL(k.urun.fiyat * k.miktar)}</b>
          <button class="rc-islem rc-tehlike" data-sil="${i}"><i class="bi bi-x-lg"></i></button></div>`).join('')
      : bos('bi-basket', 'Sepet boş — barkod okutun veya ürün seçin');
    el('#hsToplam', ov).textContent = TL(sepet.reduce((s, k) => s + k.urun.fiyat * k.miktar, 0));
  };
  const ekle = (u) => { const k = sepet.find((x) => x.urun.id === u.id); if (k) k.miktar++; else sepet.push({ urun: u, miktar: 1 }); sepetCiz(); };

  el('#hsUrunler', ov).onclick = (e) => { const b = e.target.closest('[data-id]'); if (b) ekle(urunler.find((u) => String(u.id) === b.dataset.id)); };
  el('#hsSepet', ov).onchange = (e) => { const i = e.target.dataset.i; if (i !== undefined) { sepet[i].miktar = Math.max(1, parseInt(e.target.value, 10) || 1); sepetCiz(); } };
  el('#hsSepet', ov).onclick = (e) => { const b = e.target.closest('[data-sil]'); if (b) { sepet.splice(+b.dataset.sil, 1); sepetCiz(); } };
  const ara = el('#hsAra', ov);
  ara.oninput = () => listeCiz(ara.value);
  ara.onkeydown = (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const k = ara.value.trim();
    if (!k) return;
    const a = k.toLocaleLowerCase('tr');
    const u = urunler.find((x) => x.barkod === k) || urunler.find((x) => `${x.urun_adi} ${x.barkod} ${x.sku}`.toLocaleLowerCase('tr').includes(a));
    if (u) { ekle(u); ara.value = ''; listeCiz(); } else mesaj('Bulunamadı: ' + k, 'hata');
  };
  sepetCiz();
  urunler = (await tumUrunler()).filter((u) => u.tip !== 'sarf');
  listeCiz();
  ara.focus();

  el('#hsTamam', ov).onclick = (e) => {
    if (!sepet.length) return mesaj('Sepet boş', 'hata');
    const m = musteri.secili();
    if (!m) { musteri.odakla(); return mesaj('Lütfen müşteri seçin', 'hata'); }
    const pesin = el('#hsPesin', ov).checked;
    kilitle(e.currentTarget, 'İşleniyor…', async () => {
      const r = await yaz('POST', '/stok/hizli-satis', {
        sepet: sepet.map((k) => ({ urun_id: k.urun.id, miktar: k.miktar })),
        musteri_id: m.id, personel_id: el('#hsPersonel', ov).value || null,
        tahsilat_yap: pesin, odeme_yontemi_id: pesin ? el('#hsYontem', ov).value : null,
        idempotency_key: anahtar,
      });
      if (yazSonuc(r)) {
        const v = r.govde.veri;
        mesaj(`${r.govde.mesaj} — ${TL(v.toplam_tutar)}`, 'ok');
        modalKapat(); tazele();
      }
    });
  };
}

// ─────────────────────────── alış girişi ───────────────────────────

async function alisGirisi() {
  const ov = modalAc('Alış Girişi (Mal Kabul)', `
    <div class="st-bolum"><div class="st-bolum-baslik">Belge Bilgileri</div><div class="rc-form-izgara">
      <div class="rc-alan"><label>Tedarikçi <small>(opsiyonel)</small></label><select id="aTed">${secenek(fv.tedarikciler, null)}</select></div>
      <div class="rc-alan"><label>Depo</label><select id="aDepo">${secenek(fv.depolar, fv.depolar.find((d) => d.varsayilan)?.id, 'id', 'depo_adi', null)}</select></div>
      <div class="rc-alan rc-tam"><label>Açıklama / Fiş No <small>(opsiyonel)</small></label><input id="aAciklama"></div>
    </div></div>
    <div class="st-bolum"><div class="st-bolum-baslik">Kalemler</div><div id="aKalemler"></div>
      <button type="button" class="rc-btn rc-btn-ikincil" id="aEkle"><i class="bi bi-plus-lg"></i> Kalem Ekle</button></div>`,
  '<button class="rc-btn rc-btn-ikincil" data-kapat>İptal</button><button class="rc-btn st-btn-turuncu" id="aKaydet"><i class="bi bi-truck"></i> Alışı Kaydet</button>',
  { ikon: 'truck', sabit: true });
  el('[data-kapat]', ov).onclick = modalKapat;
  const urunler = await tumUrunler();
  const opt = secenek(urunler, null, 'id', 'urun_adi', '— Ürün seç —');
  const satirEkle = () => {
    const d = document.createElement('div');
    d.className = 'st-kalem';
    d.innerHTML = `<div class="rc-alan"><label>Ürün</label><select class="aUrun">${opt}</select></div>
      <div class="rc-alan"><label>Miktar</label><input class="aMiktar" type="number" step="0.001" min="0" value="1"></div>
      <div class="rc-alan"><label>Birim ₺</label><input class="aFiyat" type="number" step="0.01" min="0"></div>
      <button type="button" class="rc-islem rc-tehlike" title="Kaldır"><i class="bi bi-x-lg"></i></button>`;
    d.querySelector('.aUrun').onchange = (e) => { const u = urunler.find((x) => String(x.id) === e.target.value); d.querySelector('.aFiyat').value = u?.alis_fiyati ?? ''; };
    d.querySelector('button').onclick = () => d.remove();
    el('#aKalemler', ov).appendChild(d);
  };
  satirEkle();
  el('#aEkle', ov).onclick = satirEkle;
  el('#aKaydet', ov).onclick = (e) => kilitle(e.currentTarget, 'Kaydediliyor…', async () => {
    const kalemler = [...ov.querySelectorAll('.st-kalem')].map((d) => ({
      urun_id: d.querySelector('.aUrun').value, miktar: d.querySelector('.aMiktar').value, birim_alis_fiyati: d.querySelector('.aFiyat').value || null,
    })).filter((k) => k.urun_id);
    const r = await yaz('POST', '/stok/alis', {
      tedarikci_id: el('#aTed', ov).value || null, depo_id: el('#aDepo', ov).value || null,
      aciklama: el('#aAciklama', ov).value.trim() || null, kalemler,
    });
    if (yazSonuc(r, r.govde?.mesaj)) { modalKapat(); tazele(); }
  });
}

// ─────────────────────────── sayım ───────────────────────────

function sayim() {
  if (!fv.depolar.length) return mesaj('Önce bir depo tanımlayın', 'hata');
  const ov = modalAc('Sayım Modu', `
    <div class="st-bolum st-bilgi"><i class="bi bi-info-circle"></i><div>Seçilen deponun sistem stoğu ile sayılan miktar karşılaştırılır; yalnızca farklı olanlar için düzeltme hareketi oluşturulur.</div></div>
    <div class="st-bolum"><div class="rc-alan" style="max-width:320px"><label>Hangi depo sayılıyor?</label>
      <select id="sDepo">${secenek(fv.depolar, fv.depolar.find((d) => d.varsayilan)?.id, 'id', 'depo_adi', null)}</select></div></div>
    <div class="st-bolum" style="padding:0" id="sTablo"></div>`,
  '<button class="rc-btn rc-btn-ikincil" data-kapat>İptal</button><button class="rc-btn rc-btn-mavi" id="sKaydet"><i class="bi bi-check-lg"></i> Sayımı Uygula</button>',
  { ikon: 'clipboard-check', genis: true, sabit: true });
  el('[data-kapat]', ov).onclick = modalKapat;

  const yukle = async () => {
    el('#sTablo', ov).innerHTML = '<div class="st-bos"><span class="rc-yukleniyor"></span></div>';
    const l = await tumUrunler({ depo_id: el('#sDepo', ov).value });
    el('#sTablo', ov).innerHTML = l.length ? `<table class="st-sayim"><thead><tr><th>Ürün</th><th>Sistem Stoğu</th><th style="text-align:right">Sayılan</th></tr></thead><tbody>
      ${l.map((u) => `<tr><td><b>${esc(u.urun_adi)}</b></td><td>${sayi(u.depo_stok)} ${esc(u.birim)}</td>
        <td style="text-align:right"><input type="number" step="0.001" min="0" data-urun="${u.id}" data-mevcut="${u.depo_stok}" value="${sayi(u.depo_stok)}"></td></tr>`).join('')}
      </tbody></table>` : bos('bi-inbox', 'Önce ürün ekleyin');
  };
  el('#sDepo', ov).onchange = yukle;
  el('#sTablo', ov).oninput = (e) => {
    const i = e.target.closest('input[data-urun]');
    if (i) i.closest('tr').classList.toggle('farkli', Number(i.value) !== Number(i.dataset.mevcut));
  };
  yukle();
  el('#sKaydet', ov).onclick = (e) => kilitle(e.currentTarget, 'Uygulanıyor…', async () => {
    // Yalnız değişen satırlar gönderilir (sunucu farkı kilit altında yeniden hesaplar).
    const kalemler = [...ov.querySelectorAll('input[data-urun]')].filter((i) => i.closest('tr').classList.contains('farkli'))
      .map((i) => ({ urun_id: i.dataset.urun, sayilan_miktar: i.value || 0 }));
    if (!kalemler.length) return mesaj('Farklı sayılan ürün yok', 'bilgi');
    const r = await yaz('POST', '/stok/sayim', { depo_id: el('#sDepo', ov).value, kalemler });
    if (yazSonuc(r, r.govde?.mesaj)) { modalKapat(); tazele(); }
  });
}

// ─────────────────────────── transfer ───────────────────────────

async function transfer() {
  if (fv.depolar.length < 2) return mesaj('Transfer için en az 2 depo gerekli', 'hata');
  const d = fv.depolar;
  const ov = modalAc('Depolar Arası Transfer', `<div class="rc-form-izgara">
      <div class="rc-alan rc-tam"><label>Ürün *</label><select id="tUrun"><option value="">Yükleniyor…</option></select></div>
      <div class="rc-alan"><label>Kaynak Depo *</label><select id="tKaynak">${secenek(d, d[0].id, 'id', 'depo_adi', null)}</select></div>
      <div class="rc-alan"><label>Hedef Depo *</label><select id="tHedef">${secenek(d, d[1].id, 'id', 'depo_adi', null)}</select></div>
      <div class="rc-alan"><label>Miktar *</label><input id="tMiktar" type="number" step="0.001" min="0"></div>
    </div>`,
  '<button class="rc-btn rc-btn-ikincil" data-kapat>İptal</button><button class="rc-btn rc-btn-birincil" id="tKaydet"><i class="bi bi-arrow-left-right"></i> Transfer Yap</button>',
  { ikon: 'arrow-left-right', dar: true });
  el('[data-kapat]', ov).onclick = modalKapat;
  el('#tUrun', ov).innerHTML = secenek(await tumUrunler(), null, 'id', 'urun_adi', '— Ürün seç —');
  el('#tKaydet', ov).onclick = (e) => kilitle(e.currentTarget, 'Aktarılıyor…', async () => {
    const r = await yaz('POST', '/stok/transfer', {
      urun_id: el('#tUrun', ov).value, kaynak_depo_id: el('#tKaynak', ov).value,
      hedef_depo_id: el('#tHedef', ov).value, miktar: el('#tMiktar', ov).value,
    });
    if (yazSonuc(r, 'Transfer tamamlandı.')) { modalKapat(); tazele({ formVerisi: true }); }
  });
}

// ─────────────── kategori / depo / tedarikçi (ortak tanım listesi) ───────────────

/**
 * Tanım yönetim modalı — dört tanım aynı API sözleşmesini (/stok/{tur}) kullandığı için
 * TEK fonksiyon: ekleme formu alanları + satır çizimi tanıma göre verilir.
 */
function tanimModali({ tur, baslik, ikon, alanlar, satir, yazabilir, ekBtn }) {
  const form = yazabilir ? `<div class="st-bolum"><div class="st-bolum-baslik">Yeni Ekle</div>
      <div class="rc-form-izgara">${alanlar}</div>
      <div style="margin-top:12px"><button class="rc-btn rc-btn-birincil" id="tnEkle"><i class="bi bi-plus-lg"></i> Ekle</button></div></div>` : '';
  const ov = modalAc(baslik, `${form}<div id="tnListe"></div>`, '', { ikon, dar: true });
  const listele = async () => {
    const r = await oku(`/stok/${tur}`);
    const l = (r.ok && r.govde?.basarili && r.govde.veri) || [];
    el('#tnListe', ov).innerHTML = l.length ? l.map((k) => `<div class="st-satir">${satir(k)}
        ${yazabilir && ekBtn ? ekBtn(k) : ''}
        ${yazabilir && !(tur === 'depolar' && k.varsayilan) ? `<button class="rc-islem rc-tehlike" data-sil="${k.id}" title="Sil"><i class="bi bi-trash"></i></button>` : ''}</div>`).join('')
      : bos(`bi-${ikon}`, 'Henüz kayıt yok');
  };
  const degisti = async () => { await formVerisiYukle(); await listele(); tazele(); };
  if (yazabilir) {
    el('#tnEkle', ov).onclick = (e) => kilitle(e.currentTarget, 'Ekleniyor…', async () => {
      const d = Object.fromEntries([...ov.querySelectorAll('[data-alan]')].map((i) => [i.dataset.alan, i.value.trim() || null]));
      if (yazSonuc(await yaz('POST', `/stok/${tur}`, d), 'Eklendi.')) { ov.querySelectorAll('[data-alan]').forEach((i) => { if (i.type !== 'color') i.value = ''; }); degisti(); }
    });
  }
  el('#tnListe', ov).onclick = async (e) => {
    const s = e.target.closest('[data-sil]');
    const v = e.target.closest('[data-varsayilan]');
    if (s && await onay('Kayıt silinsin mi? (Pasif olarak işaretlenir)', { baslik: 'Sil', tamam: 'Sil' })) {
      if (yazSonuc(await yaz('DELETE', `/stok/${tur}/${s.dataset.sil}`), 'Silindi.')) degisti();
    } else if (v && yazSonuc(await yaz('PATCH', `/stok/${tur}/${v.dataset.varsayilan}`, { varsayilan: true }), 'Varsayılan depo değişti.')) degisti();
  };
  listele();
}

const kategoriler = () => tanimModali({
  tur: 'kategoriler', baslik: 'Kategoriler', ikon: 'tags', yazabilir: yetki('tanim_olustur'),
  alanlar: `<div class="rc-alan"><label>Kategori Adı</label><input data-alan="ad" placeholder="örn. Şampuanlar"></div>
    <div class="rc-alan"><label>Renk</label><input data-alan="renk" type="color" value="#6A1B9A"></div>`,
  satir: (k) => `<span class="st-satir-ikon" style="background:${esc(k.renk || '#6A1B9A')}20;color:${esc(k.renk || '#6A1B9A')}"><i class="bi bi-tag"></i></span><div class="st-satir-ic"><b>${esc(k.ad)}</b></div>`,
});

const depolar = () => tanimModali({
  tur: 'depolar', baslik: 'Depolar', ikon: 'building', yazabilir: yetki('tanim_olustur'),
  alanlar: '<div class="rc-alan rc-tam"><label>Depo Adı</label><input data-alan="depo_adi" placeholder="örn. Salon Rafı"></div>',
  satir: (d) => `<span class="st-satir-ikon"><i class="bi bi-building"></i></span><div class="st-satir-ic"><b>${esc(d.depo_adi)} ${d.varsayilan ? '<span class="rc-rozet rc-rozet-yesil">Varsayılan</span>' : ''}</b><small>Toplam: ${sayi(d.toplam_stok)}</small></div>`,
  ekBtn: (d) => (d.varsayilan ? '' : `<button class="rc-islem rc-olumlu" data-varsayilan="${d.id}" title="Varsayılan yap"><i class="bi bi-star"></i></button>`),
});

const tedarikciler = () => tanimModali({
  tur: 'tedarikciler', baslik: 'Tedarikçiler', ikon: 'truck', yazabilir: yetki('tedarikci_yonet'),
  alanlar: `<div class="rc-alan"><label>Ad *</label><input data-alan="ad" placeholder="örn. ABC Kozmetik"></div>
    <div class="rc-alan"><label>Telefon</label><input data-alan="telefon" type="tel"></div>
    <div class="rc-alan"><label>Vergi No</label><input data-alan="vergi_no"></div>
    <div class="rc-alan"><label>E-posta</label><input data-alan="email" type="email"></div>
    <div class="rc-alan rc-tam"><label>Adres</label><textarea data-alan="adres" rows="2"></textarea></div>`,
  satir: (t) => `<span class="st-satir-ikon" style="background:#fff8e1;color:#f6a609"><i class="bi bi-truck"></i></span><div class="st-satir-ic"><b>${esc(t.ad)}</b>${t.telefon || t.email ? `<small>${esc([t.telefon, t.email].filter(Boolean).join(' • '))}</small>` : ''}</div>`,
});

// ─────────────────────────── başlat ───────────────────────────

(async function baslat() {
  if (!salonId) { kok.innerHTML = bos('bi-shop', 'Şube seçili değil (?sube=... bekleniyor).'); return; }
  await formVerisiYukle();
  iskeletCiz();
  ozetYukle();
  tablo.yenile();
})();
