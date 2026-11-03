/**
 * Kasa Raporu (v2) — build'siz island (sade ES module).
 *
 * Eski panel isletmeadmin/kasadefteri karşılığı: Gelir / Gider / Dönem Net / Toplam Kazanç
 * kartları, ödeme yöntemi + dönem filtresi, Gelirler ve Giderler tabloları, gelir detayı,
 * Devreden Aylar, Gün Sonu Raporu (özet / tam detay / yazdır), Para Ekle, Para Çek (SMS onay
 * kodu), Yeni Masraf / Personel Gideri, masraf düzenle/sil, para girişi / bağımsız tahsilat sil.
 *
 * TEK KAYNAK: buton görünürlüğü, ödeme yöntemleri, dönemler /api/v2/rapor/secenekler?ekran=kasa;
 * veri /api/v2/kasa/* ve /api/v2/masraf/*. Her yazmadan sonra MEVCUT filtreyle yenilenir
 * (eskiden ay başına sıfırlanıyordu). Yazmalar KASA_V2_YAZMA_AKTIF kapalıyken 409 → toast.
 */

import { qp, api, el, esc, TL, tarihStr, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=1';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=2';
import { ozetKart } from '/js/lib/v2-bilesen.js?v=1';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { donemSecici, trTarih } from '/js/lib/v2-donem.js?v=1';

const kok = document.getElementById('rc-kasa');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
if (salonId && window.rcPanel) window.rcPanel.salonAyarla(salonId);

const durum = { odemeYontemi: '' };
let secenek = null;
let donem = null;
let gelirTablo = null;
let giderTablo = null;
let personelSozu = null;
let masrafFormSozu = null;

const yetki = (k) => !!secenek?.yetkiler?.[k];
const saat = (dt) => { const s = String(dt || '').slice(11, 16); return s && s !== '00:00' ? s : ''; };
const btn = (goster, id, sinif, ikon, ad) => (goster ? `<button class="rc-btn ${sinif}" id="${id}"><i class="bi ${ikon}"></i> ${ad}</button>` : '');
const secim = (liste, deger, ad, secili, bos = 'Seçiniz') => `<option value="">${esc(bos)}</option>`
  + liste.map((o) => `<option value="${o[deger]}"${String(o[deger]) === String(secili ?? '') ? ' selected' : ''}>${esc(o[ad])}</option>`).join('');
const ROZET = {
  personel_gideri: ['turuncu', 'Personel Gideri'], personel_odemesi: ['mavi', 'Personel Ödemesi'], isletme: ['kirmizi', 'İşletme Masrafı'],
};

// ───────────────────────── İskelet ─────────────────────────

async function baslat() {
  if (!salonId) { kok.innerHTML = '<div class="rp-bos">Şube seçili değil (?sube=... bekleniyor).</div>'; return; }
  const r = await api('GET', '/rapor/secenekler', { params: { salon_id: salonId, ekran: 'kasa' } });
  if (!r.ok || !r.govde?.basarili) { kok.innerHTML = `<div class="rp-bos">${esc(r.govde?.mesaj || `Hata (${r.kod})`)}</div>`; return; }
  secenek = r.govde.veri;

  kok.innerHTML = `
    <div class="rp-kabuk">
      <div class="rc-sayfa-ust">
        <div class="rc-sayfa-baslik">
          <span class="rc-sayfa-ikon"><i class="bi bi-cash-stack"></i></span>
          <div><h1>Kasa Raporu</h1><div class="rc-sayfa-alt">Gelir, gider ve kasa hareketleri</div></div>
        </div>
        <div class="rc-sayfa-aksiyon">
          ${btn(yetki('gun_sonu'), 'ksGunSonu', 'rc-btn-mavi', 'bi-calendar-check', 'Gün Sonu')}
          ${btn(yetki('masraf_ekle'), 'ksMasraf', 'rp-btn-kirmizi', 'bi-receipt-cutoff', 'Yeni Masraf')}
          ${btn(yetki('kasa_giris_cikis'), 'ksParaEkle', 'rc-btn-birincil', 'bi-box-arrow-in-down', 'Para Ekle')}
          ${btn(yetki('kasa_giris_cikis'), 'ksParaCek', 'rc-btn-yesil', 'bi-box-arrow-up', 'Para Çek')}
        </div>
      </div>
      <div id="ksRapor"></div>
    </div>`;
  el('#ksGunSonu')?.addEventListener('click', gunSonuAc);
  el('#ksMasraf')?.addEventListener('click', () => masrafModal());
  el('#ksParaEkle')?.addEventListener('click', () => paraModal('giris'));
  el('#ksParaCek')?.addEventListener('click', () => paraModal('cikis'));

  if (!yetki('kasa_gor')) {
    el('#ksRapor').innerHTML = '<div class="rp-kilit"><i class="bi bi-lock"></i> Kasa özetine erişim yetkiniz bulunmamaktadır.</div>';
    return;
  }

  el('#ksRapor').innerHTML = `
    <div class="rp-filtre">
      <label class="rc-alan"><span class="rp-etiket">Ödeme Yöntemi</span>
        <select id="ksOdeme">${secim(secenek.odeme_yontemleri, 'id', 'odeme_yontemi', '', 'Hepsi')}</select></label>
      <div id="ksDonem"></div>
      <button class="rc-btn rp-btn-kehribar" id="ksAylik"><i class="bi bi-calendar3"></i> Devreden Aylar</button>
    </div>
    <div class="rc-ozet" id="ksOzet"></div>
    <div class="rp-izgara-2 rp-kasa-tablolar">
      <section class="rp-panel"><h3 class="rp-panel-baslik rp-nokta-yesil">Gelirler</h3><div id="ksGelirler"></div></section>
      ${yetki('masraf_gor') ? '<section class="rp-panel"><h3 class="rp-panel-baslik rp-nokta-gul">Giderler</h3><div id="ksGiderler"></div></section>' : ''}
    </div>`;

  donem = donemSecici(el('#ksDonem'), { donemler: secenek.donemler, varsayilan: secenek.varsayilan_donem, onDegis: yenile });
  el('#ksOdeme').onchange = (e) => { durum.odemeYontemi = e.target.value; yenile(); };
  el('#ksAylik').onclick = aylikAc;
  tablolariKur();
  yenile();
}

const filtre = () => ({ salon_id: salonId, odeme_yontemi_id: durum.odemeYontemi, ...donem.params() });

/** Kartlar + tablolar MEVCUT filtreyle (her yazmadan sonra da). */
function yenile() {
  if (!donem) return;
  ozetYukle();
  gelirTablo?.yenile();
  giderTablo?.yenile();
}

async function ozetYukle() {
  const r = await api('GET', '/kasa/ozet', { params: filtre() });
  if (!r.ok || !r.govde?.basarili) { el('#ksOzet').innerHTML = `<div class="rp-bos rc-hata">${esc(r.govde?.mesaj || `Hata (${r.kod})`)}</div>`; return; }
  const v = r.govde.veri;
  donem.goster(v.donem);
  const netEtiket = { bugun: 'Günlük Net Kasa', dun: 'Günlük Net Kasa', ay: 'Aylık Net Kasa', gecen_ay: 'Aylık Net Kasa', yil: 'Yıllık Net Kasa', gecen_yil: 'Yıllık Net Kasa' }[v.donem.donem] || 'Dönem Net Kasa';
  el('#ksOzet').innerHTML = ozetKart('bi-arrow-up-circle', 'Gelir', TL(v.gelir), 'turkuaz')
    + ozetKart('bi-arrow-down-circle', 'Gider', TL(v.gider), 'mor')
    + ozetKart('bi-wallet2', netEtiket, TL(v.net), 'mavi')
    + ozetKart('bi-graph-up', 'Toplam Kazanç (Tüm Zamanlar)', TL(v.toplam_kazanc), 'yesil');
}

// ───────────────────────── Tablolar ─────────────────────────

function tablolariKur() {
  gelirTablo = veriTablosu(el('#ksGelirler'), {
    anahtar: 'kasa-gelir', varsayilanBoy: 25, birim: 'gelir', araIpucu: 'Müşteri, tahsil eden, not…', bosMesaj: 'Bu dönemde gelir yok.',
    sirala: { alan: 'odeme_tarihi', yon: 'desc' },
    kolonlar: [{ baslik: 'Tarih', sirala: 'odeme_tarihi' }, { baslik: 'Müşteri', sirala: 'musteri' }, { baslik: 'Tahsil Eden', sirala: 'tahsil_eden' },
      { baslik: 'Notlar' }, { baslik: 'Ödeme Yöntemi', sirala: 'odeme_yontemi' }, { baslik: 'Tutar', sirala: 'tutar', sinif: 'rc-sag' }, { baslik: '', sinif: 'rc-orta' }],
    satir: (t) => `<tr class="rp-tiklanir${t.bagsiz ? ' rp-satir-uyari' : ''}" data-gelir="${t.id}" title="Detay için tıklayın">
      <td>${trTarih(t.odeme_tarihi)}</td>
      <td>${t.para_girisi ? '<span class="rc-rozet rc-rozet-mor">Kasa Girişi</span>' : esc(t.musteri || '-')}${t.bagsiz ? ' <span class="rc-rozet rc-rozet-kirmizi">Kaleme bağlı değil</span>' : ''}</td>
      <td>${esc(t.tahsil_eden || '-')}</td><td class="rp-not">${esc(t.notlar || '')}</td>
      <td>${esc(t.odeme_yontemi || '-')}${t.banka ? `<br><small>${esc(t.banka)}</small>` : ''}</td>
      <td class="rc-sag"><b>${TL(t.tutar)}</b></td>
      <td class="rc-orta">${t.sil_turu ? `<button type="button" class="rc-islem rc-tehlike" data-gelir-sil="${t.id}" title="${t.sil_turu === 'para_girisi' ? 'Para Ekleme Sil' : 'Bağımsız Tahsilatı Sil'}"><i class="bi bi-x-lg"></i></button>` : ''}</td></tr>`,
    kaynak: (p) => api('GET', '/kasa/gelirler', { params: { ...p, ...filtre() } }),
  });
  el('#ksGelirler').addEventListener('click', (e) => {
    const sil = e.target.closest('[data-gelir-sil]');
    if (sil) { gelirSil(sil.dataset.gelirSil); return; }
    const tr = e.target.closest('[data-gelir]');
    if (tr) gelirDetay(tr.dataset.gelir);
  });

  if (!yetki('masraf_gor')) return;
  giderTablo = veriTablosu(el('#ksGiderler'), {
    anahtar: 'kasa-gider', varsayilanBoy: 25, birim: 'gider', araIpucu: 'Harcayan, açıklama, kategori…', bosMesaj: 'Bu dönemde gider yok.',
    sirala: { alan: 'tarih', yon: 'desc' },
    kolonlar: [{ baslik: 'Tarih', sirala: 'tarih' }, { baslik: 'Harcayan', sirala: 'harcayan' }, { baslik: 'Açıklama' },
      { baslik: 'Ödeme Yöntemi', sirala: 'odeme_yontemi' }, { baslik: 'Tutar', sirala: 'tutar', sinif: 'rc-sag' }, { baslik: '', sinif: 'rc-orta' }],
    satir: (m) => {
      const [renk, ad] = ROZET[m.rozet] || ROZET.isletme;
      return `<tr><td>${trTarih(m.tarih)}</td><td>${esc(m.harcayan)} <span class="rc-rozet rc-rozet-${renk}">${ad}</span></td>
        <td class="rp-not">${esc(m.aciklama || '')}</td><td>${esc(m.odeme_yontemi || '-')}</td><td class="rc-sag"><b>${TL(m.tutar)}</b></td>
        <td class="rc-orta">${m.duzenlenebilir ? `<span class="rc-islemler"><button type="button" class="rc-islem" data-masraf-duzenle="${m.id}" title="Düzenle"><i class="bi bi-pencil"></i></button>
          <button type="button" class="rc-islem rc-tehlike" data-masraf-sil="${m.id}" title="Sil"><i class="bi bi-x-lg"></i></button></span>` : ''}</td></tr>`;
    },
    kaynak: (p) => api('GET', '/kasa/giderler', { params: { ...p, ...filtre() } }),
  });
  el('#ksGiderler').addEventListener('click', (e) => {
    const d = e.target.closest('[data-masraf-duzenle]');
    if (d) { masrafModal(d.dataset.masrafDuzenle); return; }
    const s = e.target.closest('[data-masraf-sil]');
    if (s) masrafSil(s.dataset.masrafSil);
  });
}

// ───────────────────────── Ortak form verisi ─────────────────────────

function personeller() {
  personelSozu ||= api('GET', '/personel', { params: { salon_id: salonId, tumu: 1 } })
    .then((r) => (r.ok && r.govde?.basarili ? (r.govde.veri.data || []).filter((p) => Number(p.aktif) === 1) : []));
  return personelSozu;
}

function masrafFormu() {
  masrafFormSozu ||= api('GET', '/masraf/form-verisi', { params: { salon_id: salonId } })
    .then((r) => (r.ok && r.govde?.basarili ? r.govde.veri : { kategoriler: [], odeme_yontemleri: secenek.odeme_yontemleri }));
  return masrafFormSozu;
}

function tarihSecici(girdi) {
  if (window.flatpickr) window.flatpickr(girdi, { dateFormat: 'Y-m-d', altInput: true, altFormat: 'd.m.Y', locale: 'tr', disableMobile: true });
}

/** Form alanlarını düz nesneye çevirir (boş → null, sayılar sayı). */
function formOku(ov, alanlar) {
  const v = {};
  alanlar.forEach(([ad, tip]) => {
    const x = el(`[name="${ad}"]`, ov);
    const deger = x ? x.value.trim() : '';
    v[ad] = deger === '' ? null : (tip === 'sayi' ? Number(deger.replace(',', '.')) : deger);
  });
  return v;
}

// ───────────────────────── Para Ekle / Para Çek ─────────────────────────

async function paraModal(yon) {
  const cikis = yon === 'cikis';
  const pers = await personeller();
  const ov = modalAc(cikis ? 'Kasadan Para Çek' : 'Kasaya Para Ekle', `
    <div class="rc-form-izgara">
      <div class="rc-alan"><label>Tarih</label><input name="tarih" value="${tarihStr(new Date())}"></div>
      <div class="rc-alan"><label>Tutar (₺)</label><input name="tutar" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0,00"></div>
      <div class="rc-alan"><label>Ödeme Yöntemi</label><select name="odeme_yontemi_id">${secim(secenek.odeme_yontemleri, 'id', 'odeme_yontemi', 1)}</select></div>
      <div class="rc-alan"><label>${cikis ? 'Parayı Alan' : 'Para Ekleyen'}</label><select name="personel_id">${secim(pers, 'id', 'personel_adi', '')}</select></div>
      <div class="rc-alan rc-tam"><label>Açıklama</label><textarea name="aciklama" rows="2"></textarea></div>
      ${cikis ? `<div class="rc-alan rc-tam"><label>Onay Kodu <small>(yetkililere SMS ile gönderilir)</small></label>
        <div class="rp-satir"><input name="onay_kodu" inputmode="numeric" maxlength="6" placeholder="4 haneli kod"><button type="button" class="rc-btn rc-btn-ikincil" id="ksKod"><i class="bi bi-send"></i> Kod Gönder</button></div></div>` : ''}
    </div>`,
  `<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn ${cikis ? 'rc-btn-yesil' : 'rc-btn-birincil'}" id="ksParaKaydet"><i class="bi bi-check2"></i> Kaydet</button>`,
  { ikon: cikis ? 'box-arrow-up' : 'box-arrow-in-down', dar: true });
  tarihSecici(el('[name="tarih"]', ov));
  el('[data-kapat]', ov).onclick = modalKapat;

  el('#ksKod', ov)?.addEventListener('click', async (e) => {
    const b = e.currentTarget; // await sonrasi currentTarget null olur
    const { tutar } = formOku(ov, [['tutar', 'sayi']]);
    b.disabled = true;
    const r = await api('POST', '/kasa/para-cikis/onay-kodu', { body: { salon_id: salonId, tutar } });
    b.disabled = false;
    yazSonuc(r, r.govde?.mesaj || 'Onay kodu gönderildi.');
  });

  el('#ksParaKaydet', ov).onclick = async (e) => {
    const govde = { salon_id: salonId, ...formOku(ov, [['tarih'], ['tutar', 'sayi'], ['odeme_yontemi_id', 'sayi'], ['personel_id', 'sayi'], ['aciklama'], ['onay_kodu']]) };
    if (!cikis) delete govde.onay_kodu;
    const b = e.currentTarget;
    b.disabled = true;
    const r = await api('POST', cikis ? '/kasa/para-cikis' : '/kasa/para-giris', { body: govde });
    b.disabled = false;
    if (yazSonuc(r, r.govde?.mesaj)) { modalKapat(); yenile(); }
  };
}

// ───────────────────────── Masraf ekle / düzenle / sil ─────────────────────────

async function masrafModal(id = null) {
  const [fv, pers, mevcut] = await Promise.all([
    masrafFormu(), personeller(),
    id ? api('GET', `/masraf/${id}`).then((r) => (r.ok && r.govde?.basarili ? r.govde.veri : null)) : null,
  ]);
  if (id && !mevcut) { yazSonuc({ ok: false, kod: 404, govde: { mesaj: 'Masraf bulunamadı' } }); return; }
  const m = mevcut || { tarih: tarihStr(new Date()), personel_gideri: false };
  // Silinmiş/pasif harcayan seçili kalsın.
  const liste = m.harcayan_id && !pers.some((p) => p.id === m.harcayan_id) ? [...pers, { id: m.harcayan_id, personel_adi: m.harcayan_adi || '-' }] : pers;

  const ov = modalAc(id ? 'Masraf Düzenleme' : 'Yeni Masraf', `
    <div class="rc-sekmeler rp-segment">
      <button type="button" class="rc-sekme${m.personel_gideri ? '' : ' aktif'}" data-mod="0"><i class="bi bi-receipt"></i> Masraf</button>
      <button type="button" class="rc-sekme${m.personel_gideri ? ' aktif' : ''}" data-mod="1"><i class="bi bi-person-badge"></i> Personel Gideri</button>
    </div>
    <div class="rp-uyari" data-gideri-not ${m.personel_gideri ? '' : 'hidden'}><i class="bi bi-info-circle"></i> Personel gideri kasadan düşülür ve personelin net hak edişinden düşülür.</div>
    <div class="rc-form-izgara">
      <div class="rc-alan"><label>Harcayan</label><select name="harcayan_id">${secim(liste, 'id', 'personel_adi', m.harcayan_id)}</select></div>
      <div class="rc-alan"><label>Tutar (₺)</label><input name="tutar" type="number" min="0" step="0.01" inputmode="decimal" value="${m.tutar ?? ''}"></div>
      <div class="rc-alan"><label>Tarih</label><input name="tarih" value="${esc(m.tarih)}"></div>
      <div class="rc-alan"><label>Ödeme Yöntemi</label><select name="odeme_yontemi_id">${secim(fv.odeme_yontemleri, 'id', 'odeme_yontemi', m.odeme_yontemi_id)}</select></div>
      <div class="rc-alan" data-kategori ${m.personel_gideri ? 'hidden' : ''}><label>Kategori</label><select name="masraf_kategori_id">${secim(fv.kategoriler, 'id', 'kategori', m.masraf_kategori_id)}</select></div>
      <div class="rc-alan rc-tam"><label>Açıklama</label><textarea name="aciklama" rows="2">${esc(m.aciklama || '')}</textarea></div>
    </div>`,
  `<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-birincil" id="ksMasrafKaydet"><i class="bi bi-check2"></i> Kaydet</button>`,
  { ikon: 'receipt-cutoff', dar: true });
  tarihSecici(el('[name="tarih"]', ov));
  el('[data-kapat]', ov).onclick = modalKapat;

  let gideri = !!m.personel_gideri;
  el('.rp-segment', ov).onclick = (e) => {
    const b = e.target.closest('[data-mod]');
    if (!b) return;
    gideri = b.dataset.mod === '1';
    ov.querySelectorAll('[data-mod]').forEach((x) => x.classList.toggle('aktif', x === b));
    el('[data-kategori]', ov).hidden = gideri;
    el('[data-gideri-not]', ov).hidden = !gideri;
  };

  el('#ksMasrafKaydet', ov).onclick = async (e) => {
    const govde = { salon_id: salonId, personel_gideri: gideri,
      ...formOku(ov, [['harcayan_id', 'sayi'], ['tutar', 'sayi'], ['tarih'], ['odeme_yontemi_id', 'sayi'], ['masraf_kategori_id', 'sayi'], ['aciklama']]) };
    if (gideri) govde.masraf_kategori_id = null;
    const b = e.currentTarget;
    b.disabled = true;
    const r = await api(id ? 'PATCH' : 'POST', id ? `/masraf/${id}` : '/masraf', { body: govde });
    b.disabled = false;
    if (yazSonuc(r, r.govde?.mesaj)) { modalKapat(); yenile(); }
  };
}

async function masrafSil(id) {
  if (!(await onay('Masraf kaydı silinecek. Emin misiniz?'))) return;
  if (yazSonuc(await api('DELETE', `/masraf/${id}`), 'Masraf kaydı silindi.')) yenile();
}

// ───────────────────────── Gelir detay / sil ─────────────────────────

async function gelirDetay(id) {
  const r = await api('GET', `/kasa/gelir/${id}`, { params: { salon_id: salonId } });
  if (!r.ok || !r.govde?.basarili) { yazSonuc(r); return; }
  const g = r.govde.veri;
  const alan = (ad, deger, tam = false) => (deger ? `<div class="rp-detay-alan${tam ? ' rc-tam' : ''}"><small>${ad}</small><b>${deger}</b></div>` : '');
  const kalemler = g.kalemler.length
    ? g.kalemler.map((k) => `<div class="rp-kalem"><span class="rc-rozet rc-rozet-mor">${esc(k.tip)}</span><div><b>${esc(k.ad)}</b>${k.adet > 1 ? ` ×${k.adet}` : ''}${k.personel ? `<small>${esc(k.personel)}</small>` : ''}</div><b>${TL(k.tutar)}</b></div>`).join('')
    : (g.adisyon_id ? '<div class="rp-bos">Kalem bilgisi bulunamadı.</div>' : '');
  modalAc('Gelir Detayı', `
    <div class="rp-tutar-kutu"><small>Tahsil Edilen Tutar</small><b>${TL(g.tutar)}</b></div>
    <div class="rp-detay">
      ${alan('Tarih', esc(trTarih(g.odeme_tarihi) + (saat(g.odeme_tarihi) ? ' ' + saat(g.odeme_tarihi) : '')))}
      ${alan('Müşteri', esc(g.musteri || '-'))}
      ${alan('Tahsil Eden', esc(g.tahsil_eden || '-'))}
      ${alan('Ödeme Yöntemi', esc((g.odeme_yontemi || '-') + (g.banka ? ' · ' + g.banka : '')))}
      ${alan('Satış Personeli', esc(g.satici || ''))}
      ${alan('Adisyon No', g.adisyon_id ? `#${g.adisyon_id}${g.harici ? ' <span class="rc-rozet rc-rozet-gri">Harici</span>' : ''}` : '')}
      ${alan('Notlar', esc(g.notlar || ''), true)}
    </div>
    ${kalemler ? `<h4 class="rp-alt-baslik">Ödenen Kalemler</h4>${kalemler}` : ''}`, '', { ikon: 'cash-coin', dar: true, altbaslik: g.tip });
}

async function gelirSil(id, onayKodu = null) {
  if (onayKodu === null && !(await onay('Bu kayıt kasadan silinecek. Emin misiniz?'))) return;
  const r = await api('DELETE', `/kasa/gelir/${id}`, { params: { salon_id: salonId, onay_kodu: onayKodu } });
  const kodGerekli = !!r.govde?.veri?.dogrulama_gerekli;
  if (!yazSonuc(r, kodGerekli ? null : r.govde?.mesaj)) return;
  if (kodGerekli) onayKoduSor(id); else yenile();
}

function onayKoduSor(id) {
  const ov = modalAc('Onay Kodu', `<p class="rp-not">Hesap sahibine SMS ile onay kodu gönderildi. Silmek için kodu girin.</p>
    <div class="rc-alan"><label>Onay Kodu</label><input name="kod" inputmode="numeric" maxlength="6" autofocus></div>`,
  '<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn rp-btn-kirmizi" id="ksKodOnay"><i class="bi bi-trash"></i> Sil</button>', { ikon: 'shield-lock', dar: true });
  el('[data-kapat]', ov).onclick = modalKapat;
  el('#ksKodOnay', ov).onclick = () => { const kod = el('[name="kod"]', ov).value.trim(); modalKapat(); gelirSil(id, kod); };
}

// ───────────────────────── Devreden Aylar ─────────────────────────

function aylikAc() {
  const buYil = new Date().getFullYear();
  const yillar = Array.from({ length: buYil - 2014 }, (_, i) => buYil - i);
  const ov = modalAc('Devreden Aylar', `<div class="rc-alan rp-dar-alan"><label>Yıl</label><select name="yil" data-arama="yok">${yillar.map((y) => `<option>${y}</option>`).join('')}</select></div><div id="ksAylikTablo"></div>`,
    '', { ikon: 'calendar3', genis: true, altbaslik: 'Aylara göre gelir, gider ve net kasa' });
  const t = veriTablosu(el('#ksAylikTablo', ov), {
    anahtar: 'kasa-aylik', varsayilanBoy: 25, bosMesaj: 'Kayıt yok.',
    kolonlar: [{ baslik: 'Ay' }, { baslik: 'Gelir', sinif: 'rc-sag' }, { baslik: 'Gider', sinif: 'rc-sag' }, { baslik: 'Net Kasa', sinif: 'rc-sag' }, { baslik: 'Durum', sinif: 'rc-orta' }],
    satir: (a) => `<tr><td><b>${esc(a.ay_adi)} ${a.yil}</b></td><td class="rc-sag">${TL(a.gelir)}</td><td class="rc-sag">${TL(a.gider)}</td>
      <td class="rc-sag"><b class="${a.net > 0 ? 'rp-arti' : a.net < 0 ? 'rp-eksi' : ''}">${TL(a.net)}</b></td>
      <td class="rc-orta"><span class="rc-rozet rc-rozet-${a.net > 0 ? 'yesil' : a.net < 0 ? 'kirmizi' : 'gri'}">${a.net > 0 ? 'KÂR' : a.net < 0 ? 'ZARAR' : 'DENGELİ'}</span></td></tr>`,
  });
  const yukle = async () => {
    const r = await api('GET', '/kasa/aylik', { params: { salon_id: salonId, yil: el('[name="yil"]', ov).value } });
    if (r.ok && r.govde?.basarili) t.kayitlarAyarla(r.govde.veri.aylar); else t.mesaj(r.govde?.mesaj || `Hata (${r.kod})`);
  };
  el('[name="yil"]', ov).onchange = yukle;
  yukle();
}

// ───────────────────────── Gün Sonu ─────────────────────────

function gunSonuAc() {
  const ov = modalAc('Gün Sonu Raporu', `
    <div class="rp-filtre rp-gs-arac"><div id="gsDonem"></div>
      <div class="rp-satir"><button class="rc-btn rc-btn-ikincil" id="gsDetay"><i class="bi bi-list-ul"></i> Tam Detay</button>
      <button class="rc-btn rc-btn-ikincil" id="gsYazdir"><i class="bi bi-printer"></i> Yazdır / PDF</button></div></div>
    <div id="gsIcerik" class="rp-yazdirilir"></div>`, '', { ikon: 'calendar-check', genis: true });
  let veri = null;
  let detay = false;
  const gd = donemSecici(el('#gsDonem', ov), { donemler: secenek.donemler, varsayilan: 'bugun', onDegis: () => yukle() });
  const yukle = async () => {
    el('#gsIcerik', ov).innerHTML = '<div class="rp-bos"><span class="rc-yukleniyor"></span> Yükleniyor…</div>';
    const r = await api('GET', '/kasa/gun-sonu', { params: { salon_id: salonId, ...gd.params() } });
    if (!r.ok || !r.govde?.basarili) { el('#gsIcerik', ov).innerHTML = `<div class="rp-bos rc-hata">${esc(r.govde?.mesaj || `Hata (${r.kod})`)}</div>`; return; }
    veri = r.govde.veri;
    gd.goster(veri.donem);
    ciz();
  };
  const ciz = () => { el('#gsIcerik', ov).innerHTML = detay ? gunSonuDetay(veri) : gunSonuOzet(veri); el('#gsDetay', ov).innerHTML = detay ? '<i class="bi bi-grid"></i> Özet' : '<i class="bi bi-list-ul"></i> Tam Detay'; };
  el('#gsDetay', ov).onclick = () => { detay = !detay; if (veri) ciz(); };
  el('#gsYazdir', ov).onclick = () => window.print();
  yukle();
}

const tablo = (basliklar, satirlar, alt = '') => `<div class="rc-tablo-sar"><table class="rc-tablo rp-kucuk-tablo"><thead><tr>${basliklar.map(([b, s]) => `<th class="${s || ''}">${b}</th>`).join('')}</tr></thead>
  <tbody>${satirlar.length ? satirlar.join('') : `<tr><td colspan="${basliklar.length}" class="rc-bos">Kayıt yok.</td></tr>`}</tbody>${alt}</table></div>`;

function gunSonuOzet(v) {
  const yontem = v.odeme_etiketleri.map((e) => `<tr><td>${esc(e.ad)}</td><td class="rc-sag">${TL(v.gelir[e.kod])}</td><td class="rc-sag">${TL(v.masraf[e.kod])}</td><td class="rc-sag"><b>${TL(v.net[e.kod])}</b></td></tr>`);
  const liste = (l, ad) => l.map((x) => `<tr><td>${esc(x[ad])}</td><td class="rc-sag">${x.adet}</td><td class="rc-sag">${TL(x.ciro)}</td></tr>`);
  return `
    <div class="rc-ozet">
      ${ozetKart('bi-arrow-up-circle', 'Gelirler Toplamı', TL(v.gelir_toplam), 'yesil')}
      ${ozetKart('bi-arrow-down-circle', 'Masraflar Toplamı', TL(v.masraf_toplam), 'turuncu')}
      ${ozetKart('bi-wallet2', 'Net (Gelir − Masraf)', TL(v.net_toplam), v.net_toplam >= 0 ? 'mavi' : 'turuncu')}
    </div>
    <div class="rp-serit"><span>İşlem Sayısı <b>${v.islem_say}</b></span><span>Müşteri Sayısı <b>${v.musteri_say}</b></span><span>Ortalama Sepet <b>${TL(v.ort_sepet)}</b></span></div>
    <h4 class="rp-alt-baslik">Ödeme Yöntemine Göre</h4>
    ${tablo([['Yöntem'], ['Gelir', 'rc-sag'], ['Masraf', 'rc-sag'], ['Net', 'rc-sag']], yontem,
      `<tfoot><tr><th>Toplam</th><th class="rc-sag">${TL(v.gelir_toplam)}</th><th class="rc-sag">${TL(v.masraf_toplam)}</th><th class="rc-sag">${TL(v.net_toplam)}</th></tr></tfoot>`)}
    <h4 class="rp-alt-baslik">Personel Bazlı Ciro</h4>
    ${tablo([['Personel'], ['İşlem', 'rc-sag'], ['Ciro', 'rc-sag']], liste(v.personeller, 'personel_adi'))}
    <div class="rp-izgara-2">
      <div><h4 class="rp-alt-baslik">En Çok Satan Hizmet</h4>${tablo([['Hizmet'], ['Adet', 'rc-sag'], ['Ciro', 'rc-sag']], liste(v.top_hizmet, 'ad'))}</div>
      <div><h4 class="rp-alt-baslik">En Çok Satan Ürün</h4>${tablo([['Ürün'], ['Adet', 'rc-sag'], ['Ciro', 'rc-sag']], liste(v.top_urun, 'ad'))}</div>
    </div>`;
}

function gunSonuDetay(v) {
  const satis = v.satislar.map((s) => `<tr><td>${v.donem.tek_gun ? esc(s.saat) : esc(trTarih(s.tarih) + ' ' + s.saat)}</td><td>${esc(s.musteri)}${s.para_girisi ? ' <span class="rc-rozet rc-rozet-mor">Para Girişi</span>' : ''}</td><td>${esc(s.tahsil_eden || '')}</td><td>${esc(s.yontem || '')}</td><td class="rc-sag">${TL(s.tutar)}</td></tr>`);
  const masraf = v.isletme_masraflari.map((m) => `<tr><td>${esc(m.harcayan)}</td><td>${esc(m.aciklama || '')}</td><td>${esc(m.yontem || '')}</td><td class="rc-sag">${TL(m.tutar)}</td></tr>`);
  const pers = v.personel_giderleri.map((m) => `<tr><td>${esc(m.harcayan)}</td><td><span class="rc-rozet rc-rozet-${m.tip === 'Personel Gideri' ? 'turuncu' : 'mavi'}">${esc(m.tip)}</span></td><td>${esc(m.aciklama || '')}</td><td class="rc-sag">${TL(m.tutar)}</td></tr>`);
  const satisToplam = v.satislar.reduce((t, s) => t + s.tutar, 0);
  return `
    <h4 class="rp-alt-baslik">Satışlar</h4>
    ${tablo([['Saat'], ['Müşteri'], ['Tahsil Eden'], ['Yöntem'], ['Tutar', 'rc-sag']], satis, `<tfoot><tr><th colspan="4">Toplam</th><th class="rc-sag">${TL(satisToplam)}</th></tr></tfoot>`)}
    <h4 class="rp-alt-baslik">İşletme Masrafları</h4>
    ${tablo([['Harcayan'], ['Açıklama'], ['Yöntem'], ['Tutar', 'rc-sag']], masraf)}
    <h4 class="rp-alt-baslik">Personel Giderleri &amp; Ödemeleri</h4>
    ${tablo([['Personel'], ['Tür'], ['Açıklama'], ['Tutar', 'rc-sag']], pers)}
    <div class="rp-saglama">Satışlar ${TL(satisToplam)} − İşletme Masrafları ${TL(v.isletme_masraf_toplam)} − Personel Giderleri ${TL(v.personel_gider_toplam)} = <b>NET (Kasa) ${TL(v.net_toplam)}</b></div>`;
}

baslat();
