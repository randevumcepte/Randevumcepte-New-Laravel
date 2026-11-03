/**
 * Paket Yönetimi (v2) — build'siz island (sade ES module).
 *
 * Eski "Paket Yönetimi" ekranının (isletmeadmin/paketsatislari.blade + custom.js paket_*)
 * v2 karşılığı, aynı akış: paket tablosu (seçilebilir satırlar), Yeni Paket / Düzenle / Sil,
 * üstte müşteri + personel seçip "Satış Yap" → seans/fiyat onayı → tahsilat ekranı
 * (stüdyo modunda "Ödeme Alındı / Alınmadı").
 *
 * TEK KAYNAK: liste, etkin fiyat/seans, hizmet seçenekleri, yetkiler ve stüdyo bayrağı
 * /api/v2/paket/* uçlarından (Flutter da aynı uçları kullanır) — burada yeniden
 * hesaplanmaz. Yazmalar PAKET_V2_YAZMA_AKTIF kapalıyken 409 döner; yazSonuc toast gösterir.
 */

import { qp, api, el, esc, TL, mesaj, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=1';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=1';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { musteriSecici } from '/js/lib/v2-secici.js?v=2';

const kok = document.getElementById('rc-paket');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;

let fv = { hizmetler: [], yetkiler: {}, studyo_modu: false };
let tablo = null;
let musteri = null;
let sonListe = [];
const secili = new Map(); // paket id → paket (sayfa değişse de seçim korunur — eski stateSave)

// ─────────────────────────── yardımcılar ───────────────────────────

const yetki = (k) => !!fv.yetkiler[k];
const fiyatGizli = () => fv.studyo_modu; // stüdyo MODEL B: fiyat gösterilmez, değer korunur
const oku = (yol, params = {}) => api('GET', yol, { params: { ...params, salon_id: salonId } });
const yaz = (method, yol, body = {}) => api(method, yol, { body: method === 'DELETE' ? undefined : { ...body, salon_id: salonId } });
const sayiVeyaNull = (v) => (String(v ?? '').trim() === '' ? null : Number(String(v).replace(',', '.')));
const paketBul = (id) => sonListe.find((p) => String(p.id) === String(id));

async function kilitle(btn, metin, is) {
  const eski = btn.innerHTML;
  btn.disabled = true; btn.innerHTML = `<span class="rc-yukleniyor"></span> ${esc(metin)}`;
  try { return await is(); } finally { btn.disabled = false; btn.innerHTML = eski; }
}

/** "8" ya da hizmet bazlı seans varsa "8 (5 + 3)". */
function seansMetni(p) {
  const hs = p.hizmetler.filter((h) => h.seans > 0);
  return hs.length > 1 ? `${p.miktar} <small class="pk-soluk">(${hs.map((h) => h.seans).join(' + ')})</small>` : String(p.miktar || '—');
}

// ─────────────────────────── iskelet + tablo ───────────────────────────

function iskeletCiz() {
  const satis = yetki('sat');
  kok.innerHTML = `
    <div class="pk-kabuk">
      <div class="rc-sayfa-ust">
        <div class="rc-sayfa-baslik">
          <span class="rc-sayfa-ikon"><i class="bi bi-boxes"></i></span>
          <div><h1>Paket Yönetimi</h1><div class="rc-sayfa-alt">Paket tanımları ve müşteriye paket satışı</div></div>
        </div>
        <div class="rc-sayfa-aksiyon">
          ${yetki('tanim_olustur') ? '<button class="rc-btn rc-btn-yesil" id="pkYeni"><i class="bi bi-plus-lg"></i> Yeni Paket</button>' : ''}
        </div>
      </div>
      ${satis ? `
      <div class="pk-satis">
        <div class="rc-alan pk-musteri"><label>Müşteri / Danışan</label><div id="pkMusteri"></div></div>
        ${fiyatGizli() ? '' : '<div class="rc-alan"><label>Satan Personel <small>(boşsa siz)</small></label><select id="pkPersonel"><option value="">— Seç —</option></select></div>'}
        <div class="pk-satis-sag">
          <span class="pk-secim" id="pkSecim"></span>
          <button class="rc-btn rc-btn-birincil" id="pkSat"><i class="bi bi-check2"></i> Satış Yap</button>
        </div>
      </div>` : ''}
      <div id="pkTablo"></div>
    </div>`;

  el('#pkYeni')?.addEventListener('click', () => paketFormu(null));
  if (satis) {
    musteri = musteriSecici(el('#pkMusteri'), { salonId, yol: '/paket/musteri-ara' });
    el('#pkSat').addEventListener('click', satisYap);
    el('#pkSecim').addEventListener('click', (e) => { if (e.target.closest('[data-temizle]')) { secili.clear(); secimCiz(); tablo.yenile(false); } });
    if (el('#pkPersonel')) {
      api('GET', '/personel', { params: { salon_id: salonId, tumu: 1, per_page: 100 } }).then((r) => {
        const l = ((r.ok && r.govde?.basarili && r.govde.veri.data) || []).filter((p) => p.aktif);
        el('#pkPersonel').innerHTML = '<option value="">— Seç —</option>' + l.map((p) => `<option value="${p.id}">${esc(p.personel_adi)}</option>`).join('');
      });
    }
  }

  const kolonlar = [
    ...(satis ? [{ baslik: '', sinif: 'pk-sec-th' }] : []),
    { baslik: 'Paket Adı', sirala: 'paket_adi' }, { baslik: 'Hizmet(-ler)' },
    { baslik: 'Seans(-lar)', sirala: 'miktar' },
    ...(fiyatGizli() ? [] : [{ baslik: 'Fiyat', sirala: 'fiyat', sinif: 'rc-sag' }]),
    ...(yetki('tanim_olustur') ? [{ baslik: 'İşlemler', sinif: 'rc-orta' }] : []),
  ];
  tablo = veriTablosu(el('#pkTablo'), {
    anahtar: 'paketler', birim: 'paket', varsayilanBoy: 25, tabloSinif: 'pk-tablo',
    araIpucu: 'Paket veya hizmet adı…', bosMesaj: 'Henüz paket tanımlanmamış.',
    sirala: { alan: 'id', yon: 'desc' },
    kolonlar, satir: satirCiz,
    kaynak: (p) => (salonId ? oku('/paket', p)
      : Promise.resolve({ ok: false, kod: 422, govde: { mesaj: 'Şube seçili değil (?sube=... bekleniyor).' } })),
    cizildi: (liste) => { sonListe = liste; },
  });
  el('#pkTablo').addEventListener('click', satirTiklama);
  el('#pkTablo').addEventListener('change', (e) => {
    const cb = e.target.closest('[data-sec]');
    if (!cb) return;
    const p = paketBul(cb.dataset.sec);
    if (cb.checked && p) secili.set(p.id, p); else secili.delete(Number(cb.dataset.sec));
    cb.closest('tr').classList.toggle('pk-secili', cb.checked);
    secimCiz();
  });
  secimCiz();
}

function satirCiz(p) {
  const sec = secili.has(p.id);
  const islem = (a, ikon, baslik, sinif = '') => `<button class="rc-islem ${sinif}" data-aksiyon="${a}" data-id="${p.id}" title="${baslik}"><i class="bi ${ikon}"></i></button>`;
  return `<tr class="${sec ? 'pk-secili' : ''}">
    ${yetki('sat') ? `<td class="pk-sec"><input type="checkbox" data-sec="${p.id}" ${sec ? 'checked' : ''} aria-label="${esc(p.paket_adi)} seç"></td>` : ''}
    <td><div class="pk-ad">${esc(p.paket_adi)}</div>${p.sure ? `<div class="pk-soluk">Süre: ${p.sure} dk</div>` : ''}</td>
    <td class="pk-hizmet">${esc(p.hizmet_adlari) || '<span class="pk-soluk">—</span>'}</td>
    <td>${seansMetni(p)}</td>
    ${fiyatGizli() ? '' : `<td class="rc-sag pk-fiyat">${TL(p.fiyat)}</td>`}
    ${yetki('tanim_olustur') ? `<td class="rc-orta"><span class="rc-islemler">${islem('duzenle', 'bi-pencil', 'Düzenle')}${islem('sil', 'bi-trash', 'Sil', 'rc-tehlike')}</span></td>` : ''}
  </tr>`;
}

function secimCiz() {
  const k = el('#pkSecim');
  if (k) k.innerHTML = secili.size ? `<b>${secili.size}</b> paket seçili · <button type="button" data-temizle>Temizle</button>` : 'Satış için tablodan paket seçin';
}

async function satirTiklama(e) {
  const b = e.target.closest('[data-aksiyon]');
  const p = b && paketBul(b.dataset.id);
  if (!p) return;
  if (b.dataset.aksiyon === 'duzenle') return paketFormu(p);
  if (await onay(`${p.paket_adi} silinsin mi? Paket katalogdan kaldırılır; geçmiş satışlar etkilenmez.`, { baslik: 'Paketi Sil', tamam: 'Paketi kaldır' })) {
    if (yazSonuc(await yaz('DELETE', `/paket/${p.id}`), 'Paket başarıyla kaldırıldı')) { secili.delete(p.id); secimCiz(); tablo.yenile(false); }
  }
}

// ─────────────────────────── paket ekle / düzenle ───────────────────────────

function paketFormu(p) {
  // Hizmet bazlı seans/fiyat (mobil v1 ile tanımlanmış paketler) düzenlemede KORUNUR.
  const hizmetler = (p?.hizmetler || []).map((h) => ({ hizmet_id: h.hizmet_id, ad: h.hizmet_adi, seans: h.seans, fiyat: h.fiyat }));
  const alan = (ad, html, sinif = '') => `<div class="rc-alan ${sinif}"><label>${ad}</label>${html}</div>`;
  const govde = `
    <form id="pkForm" class="rc-form-izgara" autocomplete="off">
      ${alan('Paket Adı *', `<input id="fAd" required maxlength="255" value="${esc(p?.paket_adi || '')}">`, 'rc-tam')}
      ${alan('Hizmetler *', `<div class="pk-cipler" id="fHizmetler"></div><select id="fHizmetEkle"></select>`, 'rc-tam')}
      ${alan('Süre <small>(dk)</small>', `<input id="fSure" type="number" min="0" step="1" value="${esc(p?.sure ?? '')}">`)}
      ${alan('Seans <small>(opsiyonel)</small>', `<input id="fSeans" type="number" min="0" step="1" value="${esc(p?.miktar ?? '')}">`)}
      ${fiyatGizli() ? '' : alan('Fiyat (₺) <small>(opsiyonel)</small>', `<input id="fFiyat" type="number" min="0" step="0.01" value="${esc(p?.fiyat ?? '')}">`)}
      <div class="rc-alan rc-tam"><span class="rc-ipucu" id="fNot"></span></div>
    </form>`;
  const ov = modalAc(p ? 'Paket Düzenle' : 'Yeni Paket', govde,
    '<button class="rc-btn rc-btn-ikincil" type="button" data-kapat>Kapat</button><button class="rc-btn rc-btn-birincil" type="submit" form="pkForm" id="fKaydet"><i class="bi bi-check-lg"></i> Kaydet</button>',
    { ikon: 'boxes', sabit: true, altbaslik: p?.paket_adi });
  el('[data-kapat]', ov).onclick = modalKapat;

  const ciz = () => {
    el('#fHizmetler', ov).innerHTML = hizmetler.length
      ? hizmetler.map((h, i) => `<span class="pk-cip">${esc(h.ad)}${h.seans > 0 || h.fiyat > 0 ? ` <small>${[h.seans > 0 ? h.seans + ' seans' : '', h.fiyat > 0 && !fiyatGizli() ? TL(h.fiyat) : ''].filter(Boolean).join(' · ')}</small>` : ''}<button type="button" data-i="${i}" aria-label="Kaldır">&times;</button></span>`).join('')
      : '<span class="pk-soluk">Henüz hizmet seçilmedi</span>';
    const kalan = fv.hizmetler.filter((s) => !hizmetler.some((h) => h.hizmet_id === s.hizmet_id));
    el('#fHizmetEkle', ov).innerHTML = '<option value="">+ Hizmet ekle…</option>' + kalan.map((s) => `<option value="${s.hizmet_id}">${esc(s.ad)}</option>`).join('');
    el('#fNot', ov).textContent = hizmetler.some((h) => h.seans > 0 || h.fiyat > 0)
      ? 'Bu pakette hizmet bazlı seans/fiyat tanımlı; toplamları paket seans/fiyatından önceliklidir.' : '';
  };
  el('#fHizmetler', ov).onclick = (e) => { const b = e.target.closest('[data-i]'); if (b) { hizmetler.splice(+b.dataset.i, 1); ciz(); } };
  el('#fHizmetEkle', ov).onchange = (e) => {
    const s = fv.hizmetler.find((x) => String(x.hizmet_id) === e.target.value);
    if (s) { hizmetler.push({ hizmet_id: s.hizmet_id, ad: s.ad, seans: null, fiyat: null }); ciz(); }
  };
  ciz();

  el('#pkForm', ov).addEventListener('submit', (e) => {
    e.preventDefault();
    if (!hizmetler.length) return mesaj('Devam etmek için hizmet seçmeniz gerekmektedir.', 'hata');
    kilitle(el('#fKaydet', ov), 'Kaydediliyor…', async () => {
      const d = {
        paket_adi: el('#fAd', ov).value.trim(),
        hizmetler: hizmetler.map(({ hizmet_id, seans, fiyat }) => ({ hizmet_id, seans, fiyat })),
        sure: sayiVeyaNull(el('#fSure', ov).value),
        miktar: sayiVeyaNull(el('#fSeans', ov).value),
      };
      if (!fiyatGizli()) d.fiyat = sayiVeyaNull(el('#fFiyat', ov).value);
      const r = p ? await yaz('PATCH', `/paket/${p.id}`, d) : await yaz('POST', '/paket', d);
      if (yazSonuc(r, r.govde?.mesaj)) {
        if (p && secili.has(p.id)) secili.set(p.id, r.govde.veri);
        modalKapat(); tablo.yenile(!p);
      }
    });
  });
}

// ─────────────────────────── satış yap ───────────────────────────

function satisYap() {
  const m = musteri.secili();
  if (!secili.size || !m) return mesaj('Satış yapmadan önce lütfen paket ve müşteri seçiniz.', 'hata');
  const paketler = [...secili.values()];
  const fiyatAcik = yetki('fiyat_degistir');
  const govde = `
    <div class="pk-satis-ozet"><i class="bi bi-person"></i> <b>${esc(m.ad)}</b></div>
    ${paketler.map((p) => `<div class="pk-satis-kalem" data-id="${p.id}">
      <div class="pk-ad">${esc(p.paket_adi)}</div>
      <div class="rc-form-izgara">
        <div class="rc-alan"><label>Seans Sayısı</label><input type="number" min="0" step="1" data-seans value="${p.miktar ?? ''}"></div>
        ${fiyatGizli() ? '' : `<div class="rc-alan"><label>Fiyat (₺)</label><input type="number" min="0" step="0.01" data-fiyat value="${p.fiyat}" ${fiyatAcik ? '' : 'readonly title="Fiyat değiştirme yetkiniz yok"'}></div>`}
      </div></div>`).join('')}`;
  const alt = fiyatGizli()
    ? '<button class="rc-btn rc-btn-ikincil" data-odeme="0">Ödeme Alınmadı</button><button class="rc-btn rc-btn-yesil" data-odeme="1"><i class="bi bi-check-lg"></i> Ödeme Alındı</button>'
    : '<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-yesil" data-odeme=""><i class="bi bi-arrow-right"></i> Tahsilata Git</button>';
  const ov = modalAc(fiyatGizli() ? 'Seans Sayısı' : 'Seans ve Fiyatı Belirleyin', govde, alt, { ikon: 'cart-check', sabit: true });
  el('[data-kapat]', ov)?.addEventListener('click', modalKapat);
  // Çift tıklama / ağ tekrarı ikinci adisyon açmasın — modal başına tek anahtar.
  const anahtar = crypto.randomUUID();

  ov.querySelectorAll('[data-odeme]').forEach((btn) => btn.addEventListener('click', () => kilitle(btn, 'İşleniyor…', async () => {
    const kalemler = [...ov.querySelectorAll('.pk-satis-kalem')].map((k) => {
      const kalem = { paket_id: Number(k.dataset.id), seans: sayiVeyaNull(el('[data-seans]', k).value) };
      if (!fiyatGizli() && fiyatAcik) kalem.fiyat = sayiVeyaNull(el('[data-fiyat]', k).value);
      return kalem;
    });
    const r = await yaz('POST', '/paket/satis', { musteri_id: m.id, personel_id: el('#pkPersonel')?.value || null, kalemler, idempotency_key: anahtar });
    if (!yazSonuc(r)) return;
    const adisyonId = r.govde.veri.adisyon_id;
    if (!fiyatGizli()) { location.href = `/v2/tahsilat/${adisyonId}?sube=${salonId}`; return; }
    // Stüdyo: "Ödeme Alındı" → mevcut adisyon ödeme ucu (tamOde); ardından Satış Takibi.
    if (btn.dataset.odeme === '1') yazSonuc(await api('PATCH', `/adisyon/${adisyonId}/odeme`, { body: { alindi: true } }));
    location.href = `/v2/satislar?sube=${salonId}`;
  })));
}

// ─────────────────────────── başlat ───────────────────────────

(async function baslat() {
  if (window.rcPanel && salonId) window.rcPanel.salonAyarla(salonId);
  if (salonId) {
    const r = await oku('/paket/form-verisi');
    if (r.ok && r.govde?.basarili) fv = r.govde.veri;
    else mesaj(r.govde?.mesaj || 'Form verisi alınamadı', 'hata');
  }
  iskeletCiz();
  tablo.yenile();
})();
