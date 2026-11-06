/**
 * Satış Raporları (v2) — build'siz island (sade ES module).
 *
 * Eski panel "Satış Raporları" (isletmeadmin/raporlar) karşılığı: Tümü / Hizmet / Ürün /
 * Paket / Personel sekmeleri, dönem + personel filtresi, özet kartları, kalem tablosu ve
 * satır göz butonuyla "Alan Müşteriler" penceresi (artık ürün/paket için de çalışır).
 *
 * TEK KAYNAK: sekmeler, dönemler, personel kapsamı ve maske /api/v2/rapor/secenekler'den;
 * satırlar ve özet /api/v2/rapor/satis*'tan (Flutter da aynı uçlar). Tutar hesabı yok —
 * yalnız çizim. Maskeli (rapor.ciro_kar_gor yok) değerler sunucudan null gelir → "****".
 */

import { qp, api, el, esc, TL } from '/js/lib/v2-ortak.js?v=2';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=2';
import { ozetKart } from '/js/lib/v2-bilesen.js?v=1';
import { modalAc } from '/js/lib/v2-modal.js?v=1';
import { donemSecici, trTarih, sayi } from '/js/lib/v2-donem.js?v=1';

const kok = document.getElementById('rc-raporlar');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
if (salonId && window.rcPanel) window.rcPanel.salonAyarla(salonId);

const durum = { sekme: 'tum', personelId: '' };
let secenek = null;
let donem = null;
let tablo = null;

const para = (v) => (v === null || v === undefined ? '****' : TL(v));
const karHucre = (v) => (v === null || v === undefined ? '****' : `<b class="${v >= 0 ? 'rp-arti' : 'rp-eksi'}">${TL(v)}</b>`);

// ───────────────────────── Kolonlar (sekmeye göre) ─────────────────────────

const PARA_KOLONLARI = [
  { baslik: 'Gelir', sirala: 'gelir', sinif: 'rc-sag' },
  { baslik: 'İndirim', sirala: 'indirim', sinif: 'rc-sag' },
  { baslik: 'Tahsil Edilen', sirala: 'kazanc', sinif: 'rc-sag' },
  { baslik: 'Kalan Alacak', sirala: 'alacak', sinif: 'rc-sag' },
];
const paraHucreleri = (k) => `<td class="rc-sag">${TL(k.gelir)}</td><td class="rc-sag">${TL(k.indirim)}</td><td class="rc-sag">${para(k.kazanc)}</td><td class="rc-sag">${para(k.alacak)}</td>`;
const gozBtn = (k) => `<td class="rc-orta"><button type="button" class="rc-islem" data-tur="${esc(k.tur)}" data-kalem="${k.kalem_id}" data-ad="${esc(k.ad)}" title="Alan müşteriler"><i class="bi bi-eye"></i></button></td>`;

const SEKME_TABLO = {
  tum: {
    kolonlar: [{ baslik: 'Tür', sirala: 'tur_ad' }, { baslik: 'Ad', sirala: 'ad' }, { baslik: 'Adet', sirala: 'adet', sinif: 'rc-sag' }, ...PARA_KOLONLARI, { baslik: '', sinif: 'rc-orta' }],
    satir: (k) => `<tr><td><span class="rc-rozet rc-rozet-${k.tur === 'urun' ? 'mavi' : k.tur === 'hizmet' ? 'mor' : 'yesil'}">${esc(k.tur_ad)}</span></td><td>${esc(k.ad)}</td><td class="rc-sag">${sayi(k.adet)}</td>${paraHucreleri(k)}${gozBtn(k)}</tr>`,
  },
  hizmet: {
    kolonlar: [{ baslik: 'Hizmet', sirala: 'ad' }, { baslik: 'Verilen', sirala: 'adet', sinif: 'rc-sag' }, ...PARA_KOLONLARI,
      { baslik: 'Birim Maliyet', sinif: 'rc-sag' }, { baslik: 'Toplam Maliyet', sirala: 'toplam_maliyet', sinif: 'rc-sag' }, { baslik: 'Net Kâr', sirala: 'kar', sinif: 'rc-sag' }, { baslik: '', sinif: 'rc-orta' }],
    satir: (k) => `<tr><td>${esc(k.ad)}</td><td class="rc-sag">${sayi(k.adet)}</td>${paraHucreleri(k)}<td class="rc-sag">${TL(k.birim_maliyet)}</td><td class="rc-sag">${TL(k.toplam_maliyet)}</td><td class="rc-sag">${karHucre(k.kar)}</td>${gozBtn(k)}</tr>`,
  },
  urun: {
    kolonlar: [{ baslik: 'Ürün', sirala: 'ad' }, { baslik: 'Adet', sirala: 'adet', sinif: 'rc-sag' }, ...PARA_KOLONLARI, { baslik: '', sinif: 'rc-orta' }],
    satir: (k) => `<tr><td>${esc(k.ad)}</td><td class="rc-sag">${sayi(k.adet)}</td>${paraHucreleri(k)}${gozBtn(k)}</tr>`,
  },
  personel: {
    kolonlar: [{ baslik: 'Personel', sirala: 'personel_adi' },
      { baslik: 'Hizmet Geliri', sirala: 'hizmet_geliri', sinif: 'rc-sag' }, { baslik: 'Hizmet Primi', sirala: 'hizmet_primi', sinif: 'rc-sag' },
      { baslik: 'Ürün Geliri', sirala: 'urun_geliri', sinif: 'rc-sag' }, { baslik: 'Ürün Primi', sirala: 'urun_primi', sinif: 'rc-sag' },
      { baslik: 'Paket Geliri', sirala: 'paket_geliri', sinif: 'rc-sag' }, { baslik: 'Paket Primi', sirala: 'paket_primi', sinif: 'rc-sag' },
      { baslik: 'Toplam Gelir', sirala: 'toplam_gelir', sinif: 'rc-sag' }, { baslik: 'Toplam Prim', sirala: 'toplam_prim', sinif: 'rc-sag' }],
    satir: (p) => `<tr><td><b>${esc(p.personel_adi)}</b></td>${['hizmet_geliri', 'hizmet_primi', 'urun_geliri', 'urun_primi', 'paket_geliri', 'paket_primi']
      .map((a) => `<td class="rc-sag">${TL(p[a])}</td>`).join('')}<td class="rc-sag"><b>${TL(p.toplam_gelir)}</b></td><td class="rc-sag"><b>${TL(p.toplam_prim)}</b></td></tr>`,
  },
};
SEKME_TABLO.paket = { ...SEKME_TABLO.urun, kolonlar: [{ baslik: 'Paket', sirala: 'ad' }, ...SEKME_TABLO.urun.kolonlar.slice(1)] };

// ───────────────────────── İskelet ─────────────────────────

async function baslat() {
  kok.innerHTML = `
    <div class="rp-kabuk">
      <div class="rc-sayfa-ust">
        <div class="rc-sayfa-baslik">
          <span class="rc-sayfa-ikon"><i class="bi bi-bar-chart-fill"></i></span>
          <div><h1>Satış Raporları</h1><div class="rc-sayfa-alt">Hizmet, ürün, paket satışları ve personel primleri</div></div>
        </div>
      </div>
      <div class="rc-sekmeler" id="rpSekmeler"></div>
      <div class="rp-filtre">
        <div id="rpDonem"></div>
        <label class="rc-alan" id="rpPersonelAlan" hidden><span class="rp-etiket">Personel</span><select id="rpPersonel"><option value="">Tüm personel</option></select></label>
      </div>
      <div class="rp-uyari" id="rpMaske" hidden><i class="bi bi-eye-slash"></i> Tahsil edilen, kalan alacak ve kâr tutarları yetkiniz gereği gizlenmiştir.</div>
      <div class="rc-ozet" id="rpOzet"></div>
      <div id="rpTablo"></div>
    </div>`;

  if (!salonId) { el('#rpTablo').innerHTML = '<div class="rp-bos">Şube seçili değil (?sube=... bekleniyor).</div>'; return; }

  const r = await api('GET', '/rapor/secenekler', { params: { salon_id: salonId, ekran: 'satis' } });
  if (!r.ok || !r.govde?.basarili) { el('.rp-kabuk').innerHTML = `<div class="rp-bos">${esc(r.govde?.mesaj || `Hata (${r.kod})`)}</div>`; return; }
  secenek = r.govde.veri;

  donem = donemSecici(el('#rpDonem'), { donemler: secenek.donemler, varsayilan: secenek.varsayilan_donem, onDegis: () => tablo.yenile() });
  el('#rpMaske').hidden = !secenek.maskeli;
  sekmeCiz();
  if (secenek.personel_filtresi) personelFiltresi();
  tabloKur();
}

function sekmeCiz() {
  el('#rpSekmeler').innerHTML = secenek.sekmeler
    .map((s) => `<button class="rc-sekme${s.kod === durum.sekme ? ' aktif' : ''}" data-kod="${esc(s.kod)}"><i class="bi ${esc(s.ikon)}"></i> ${esc(s.ad)}</button>`).join('');
  el('#rpSekmeler').onclick = (e) => {
    const b = e.target.closest('.rc-sekme');
    if (!b || b.dataset.kod === durum.sekme) return;
    durum.sekme = b.dataset.kod;
    sekmeCiz();
    el('#rpPersonelAlan').hidden = !secenek.personel_filtresi || durum.sekme === 'personel';
    const t = SEKME_TABLO[durum.sekme];
    tablo.kolonlarAyarla(t.kolonlar);
    tablo.yenile();
  };
}

async function personelFiltresi() {
  el('#rpPersonelAlan').hidden = false;
  const r = await api('GET', '/personel', { params: { salon_id: salonId, tumu: 1 } });
  const liste = r.ok && r.govde?.basarili ? (r.govde.veri.data || []).filter((p) => Number(p.aktif) === 1) : [];
  el('#rpPersonel').insertAdjacentHTML('beforeend', liste.map((p) => `<option value="${p.id}">${esc(p.personel_adi)}</option>`).join(''));
  el('#rpPersonel').onchange = (e) => { durum.personelId = e.target.value; tablo.yenile(); };
}

// ───────────────────────── Tablo + özet ─────────────────────────

function tabloKur() {
  tablo = veriTablosu(el('#rpTablo'), {
    anahtar: 'satis-raporlari',
    varsayilanBoy: 25,
    kolonlar: SEKME_TABLO[durum.sekme].kolonlar,
    birim: 'kalem',
    araIpucu: 'Hizmet, ürün, paket, personel…',
    bosMesaj: 'Seçili dönemde satış yok.',
    sirala: { alan: 'gelir', yon: 'desc' },
    satir: (k) => SEKME_TABLO[durum.sekme].satir(k),
    kaynak: (p) => api('GET', durum.sekme === 'personel' ? '/rapor/satis/personel' : '/rapor/satis', {
      params: { ...p, salon_id: salonId, sekme: durum.sekme, personel_id: durum.personelId, ...donem.params() },
    }),
    cizildi: (_l, v) => { if (v) { ozetCiz(v.ozet || {}); donem.goster(v.donem); } },
  });
  tablo.yenile();
  el('#rpTablo').addEventListener('click', (e) => {
    const b = e.target.closest('[data-kalem]');
    if (b) musteriPenceresi(b.dataset.tur, b.dataset.kalem, b.dataset.ad);
  });
}

function ozetCiz(o) {
  if (durum.sekme === 'personel') {
    el('#rpOzet').innerHTML = ozetKart('bi-cash-coin', 'Toplam Gelir (Tahsil)', TL(o.toplam_gelir), 'mor') + ozetKart('bi-award', 'Toplam Prim', TL(o.toplam_prim), 'yesil');
    return;
  }
  let h = ozetKart('bi-graph-up-arrow', 'Toplam Gelir', TL(o.gelir), 'mor')
    + ozetKart('bi-wallet2', 'Tahsil Edilen', para(o.kazanc), 'yesil')
    + ozetKart('bi-hourglass-split', 'Kalan Alacak', para(o.alacak), 'turuncu')
    + ozetKart('bi-percent', 'İndirim', TL(o.indirim), 'mavi');
  if (durum.sekme === 'hizmet') h += ozetKart('bi-tools', 'Toplam Maliyet', TL(o.toplam_maliyet), 'turuncu') + ozetKart('bi-piggy-bank', 'Net Kâr', para(o.kar), 'turkuaz');
  el('#rpOzet').innerHTML = h;
}

// ───────────────────────── Alan müşteriler ─────────────────────────

function musteriPenceresi(tur, kalemId, ad) {
  const ov = modalAc(ad, '<div class="rc-ozet rp-ozet-kucuk" id="rpMOzet"></div><div id="rpMTablo"></div>', '', { ikon: 'eye', genis: true, altbaslik: 'Seçili dönemde bu kalemi alan müşteriler' });
  const t = veriTablosu(el('#rpMTablo', ov), {
    anahtar: 'satis-raporlari-musteri',
    kolonlar: [{ baslik: 'Tarih', sirala: 'satis_tarihi' }, { baslik: 'Müşteri', sirala: 'musteri' }, { baslik: 'Telefon' }, { baslik: 'Personel', sirala: 'personel' },
      { baslik: 'Fiyat', sirala: 'gelir', sinif: 'rc-sag' }, { baslik: 'Ödenen', sirala: 'odenen', sinif: 'rc-sag' }, { baslik: 'Kalan', sirala: 'kalan', sinif: 'rc-sag' }],
    birim: 'kayıt', araIpucu: 'Müşteri, personel, adisyon no…', bosMesaj: 'Seçili dönem ve filtreye uyan müşteri yok.',
    sirala: { alan: 'satis_tarihi', yon: 'desc' },
    satir: (m) => `<tr><td>${trTarih(m.satis_tarihi)}</td><td>${esc(m.musteri)}</td><td>${esc(m.telefon || '-')}</td>
      <td>${m.personel ? `<span class="rc-rozet rc-rozet-mor">${esc(m.personel)}</span>` : '-'}</td>
      <td class="rc-sag">${TL(m.gelir)}</td><td class="rc-sag rp-arti">${para(m.odenen)}</td><td class="rc-sag rp-eksi">${para(m.kalan)}</td></tr>`,
    kaynak: (p) => api('GET', `/rapor/satis/${encodeURIComponent(tur)}/${encodeURIComponent(kalemId)}/musteriler`, {
      params: { ...p, salon_id: salonId, personel_id: durum.personelId, ...donem.params() },
    }),
    cizildi: (_l, v) => {
      const o = v?.ozet || {};
      el('#rpMOzet', ov).innerHTML = ozetKart('bi-list-ol', 'Kayıt', sayi(o.adet), 'mor') + ozetKart('bi-tag', 'Toplam Fiyat', TL(o.gelir), 'mavi')
        + ozetKart('bi-check2-circle', 'Toplam Ödenen', para(o.odenen), 'yesil') + ozetKart('bi-hourglass-split', 'Toplam Kalan', para(o.kalan), 'turuncu');
    },
  });
  t.yenile();
}

baslat();
