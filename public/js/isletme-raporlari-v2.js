/**
 * İşletme Raporları — "Performans Panosu" (v2) — build'siz island (sade ES module).
 *
 * Eski panel isletmeadmin/isletme_raporlari karşılığı: dönem cirosu kahraman kartı, dönem
 * seçici ve İşletme / Hizmet / Ürün / Personel / Müşteri / Randevu sekmeleri (kartlar,
 * grafikler, tablolar). Sekmeler yetkiye göre /api/v2/rapor/secenekler'den gelir (eskiden
 * yetkisiz sekme sessizce sıfır gösteriyordu). Veri /api/v2/rapor/isletme/*; burada hesap yok.
 */

import { qp, api, el, esc, TL } from '/js/lib/v2-ortak.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=8';
import { ozetKart } from '/js/lib/v2-bilesen.js?v=7';
import { donemSecici, sayi } from '/js/lib/v2-donem.js?v=7';
import { cubuk, halka, cizgi, grafikleriTemizle, RENK } from '/js/lib/v2-grafik.js?v=7';

const kok = document.getElementById('rc-isletme-raporlari');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
if (salonId && window.rcPanel) window.rcPanel.salonAyarla(salonId);

let secenek = null;
let donem = null;
let sekme = null;
let istekNo = 0;

const panel = (baslik, ic, renk = 'mor', ek = '') => `<section class="rp-panel"><h3 class="rp-panel-baslik rp-nokta-${renk}">${esc(baslik)}${ek}</h3>${ic}</section>`;
const tuval = (id, boy = 280) => `<div class="rp-grafik" style="height:${boy}px"><canvas id="${id}"></canvas></div>`;

// ───────────────────────── İskelet ─────────────────────────

async function baslat() {
  kok.innerHTML = `
    <div class="rp-kabuk">
      <div class="rp-hero">
        <div>
          <div class="rp-hero-ust">İşletme Yönetim · Analiz</div>
          <h1>Performans Panosu</h1>
          <p>Dönemsel ciro, hizmet–ürün satışları, personel ve müşteri davranışlarınızı tek panoda inceleyin.</p>
        </div>
        <div class="rp-hero-kpi"><span class="rp-canli"></span><small>Seçili Dönem Tahsilatı</small><b id="rpHeroCiro">—</b></div>
      </div>
      <div class="rp-filtre"><div id="rpDonem"></div></div>
      <div class="rc-sekmeler" id="rpSekmeler"></div>
      <div id="rpIcerik"></div>
    </div>`;

  if (!salonId) { el('#rpIcerik').innerHTML = '<div class="rp-bos">Şube seçili değil (?sube=... bekleniyor).</div>'; return; }
  const r = await api('GET', '/rapor/secenekler', { params: { salon_id: salonId, ekran: 'isletme' } });
  if (!r.ok || !r.govde?.basarili) { el('.rp-kabuk').innerHTML = `<div class="rp-bos">${esc(r.govde?.mesaj || `Hata (${r.kod})`)}</div>`; return; }
  secenek = r.govde.veri;
  if (!secenek.sekmeler.length) { el('#rpIcerik').innerHTML = '<div class="rp-bos">Bu ekrandaki raporlar için yetkiniz yok.</div>'; return; }

  sekme = secenek.sekmeler[0].kod;
  donem = donemSecici(el('#rpDonem'), { donemler: secenek.donemler, varsayilan: secenek.varsayilan_donem, onDegis: yukle });
  sekmeCiz();
  yukle();
}

function sekmeCiz() {
  el('#rpSekmeler').innerHTML = secenek.sekmeler
    .map((s) => `<button class="rc-sekme${s.kod === sekme ? ' aktif' : ''}" data-kod="${esc(s.kod)}"><i class="bi ${esc(s.ikon)}"></i> ${esc(s.ad)}</button>`).join('');
  el('#rpSekmeler').onclick = (e) => {
    const b = e.target.closest('.rc-sekme');
    if (!b || b.dataset.kod === sekme) return;
    sekme = b.dataset.kod;
    sekmeCiz();
    yukle();
  };
}

/** Kahraman kartı her zaman dönemin tahsilatı (eskiden sekmeye göre bayatlıyordu). */
async function yukle() {
  const no = ++istekNo;
  const p = { salon_id: salonId, ...donem.params() };
  const ozetVar = secenek.sekmeler.some((s) => s.kod === 'isletme');
  el('#rpIcerik').innerHTML = '<div class="rp-bos"><span class="rc-yukleniyor"></span> Yükleniyor…</div>';

  const [ozet, veri] = await Promise.all([
    ozetVar ? api('GET', '/rapor/isletme/ozet', { params: p }) : null,
    sekme === 'isletme' ? null : api('GET', `/rapor/isletme/${sekme}`, { params: p }),
  ]);
  if (no !== istekNo) return;
  if (ozet?.govde?.basarili) el('#rpHeroCiro').textContent = TL(ozet.govde.veri.toplam_gelir);

  const cevap = sekme === 'isletme' ? ozet : veri;
  if (!cevap?.ok || !cevap.govde?.basarili) {
    el('#rpIcerik').innerHTML = `<div class="rp-bos rc-hata">${esc(cevap?.govde?.mesaj || `Hata (${cevap?.kod ?? '—'})`)}</div>`;
    return;
  }
  const v = cevap.govde.veri;
  donem.goster(v.donem);
  grafikleriTemizle();
  CIZICILER[sekme](v);
}

// ───────────────────────── Sekmeler ─────────────────────────

const CIZICILER = {
  isletme(v) {
    const k = v.odeme_yontemleri || {};
    const etiketler = (v.odeme_etiketleri || []);
    el('#rpIcerik').innerHTML = `
      <div class="rc-ozet">
        ${ozetKart('bi-calendar2-check', 'Randevu', sayi(v.toplam_randevu), 'mor')}
        ${ozetKart('bi-receipt', 'Adisyon', sayi(v.toplam_adisyon), 'yesil')}
        ${ozetKart('bi-clipboard2-check', 'Uygulanan Hizmet', sayi(v.uygulanan_hizmet), 'turuncu')}
        ${ozetKart('bi-bag-check', 'Satılan Ürün', sayi(v.satilan_urun), 'mavi')}
        ${ozetKart('bi-gift', 'Satılan Paket', sayi(v.satilan_paket), 'turkuaz')}
        ${ozetKart('bi-graph-up-arrow', 'Net Satış', TL(v.satis_net), 'mor')}
      </div>
      ${panel('Tahsilat Dağılımı', `<div class="rp-dagilim">
          ${tuval('rpHalka', 240)}
          <div class="rp-hucreler">${etiketler.map((e) => `<div class="rp-hucre"><small>${esc(e.ad)}</small><b>${TL(k[e.kod])}</b></div>`).join('')}
            <div class="rp-hucre rp-hucre-toplam"><small>Toplam Tahsilat</small><b>${TL(v.toplam_gelir)}</b></div></div>
        </div>`, 'yesil', ` <a class="rp-panel-link" href="/v2/raporlar?sube=${salonId}">Detay raporlar →</a>`)}`;
    if (!halka(el('#rpHalka'), etiketler.map((e) => e.ad), etiketler.map((e) => k[e.kod] || 0), { para: true })) {
      el('#rpHalka').parentElement.innerHTML = '<div class="rp-bos">Veri yok, grafik gizlendi.</div>';
    }
  },

  hizmet(v) { kalemSekmesi(v, 'Uygulanan', 'Farklı hizmet', 'En Çok Kazandıranlar', RENK.yesil, 'Bu dönemde hizmet uygulanmamış.'); },
  urun(v) { kalemSekmesi(v, 'Satış Adedi', 'Farklı ürün', 'En Çok Satanlar', RENK.gul, 'Bu dönemde ürün satışı yok.'); },

  personel(v) {
    const l = v.personeller || [];
    const ilk3 = l.slice(0, 3);
    el('#rpIcerik').innerHTML = `
      ${ilk3.length ? `<div class="rp-lider">${ilk3.map((p, i) => `<div class="rp-lider-kart rp-lider-${i + 1}"><span class="rp-sira">${i + 1}</span>
        <div><b>${esc(p.personel_adi)}</b><div class="rp-lider-ciro">${TL(p.ciro)}</div><small>${sayi(p.randevu_say)} randevu · ${sayi(p.hizmet_say)} hizmet</small></div></div>`).join('')}</div>`
        : '<div class="rp-bos">Bu dönemde personel aktivitesi yok.</div>'}
      <div class="rp-izgara-2">
        ${panel('Personel Cirosu', tuval('rpPersonelGrafik', 300), 'mor')}
        ${panel('Detaylı Liste', '<div id="rpTablo"></div>', 'mavi')}
      </div>`;
    cubuk(el('#rpPersonelGrafik'), l.map((p) => p.personel_adi), l.map((p) => p.ciro), { para: true });
    yerelTablo('isletme-personel', [
      { baslik: 'Personel', sirala: 'ad', deger: (p) => p.personel_adi },
      { baslik: 'Randevu', sirala: 'randevu', sinif: 'rc-sag', deger: (p) => p.randevu_say },
      { baslik: 'Hizmet', sirala: 'hizmet', sinif: 'rc-sag', deger: (p) => p.hizmet_say },
      { baslik: 'Ciro', sirala: 'ciro', sinif: 'rc-sag', deger: (p) => p.ciro },
    ], (p) => `<tr title="Hizmet ${TL(p.hizmet_ciro)} · Ürün ${TL(p.urun_ciro)} · Paket ${TL(p.paket_ciro)}"><td>${esc(p.personel_adi)}</td><td class="rc-sag">${sayi(p.randevu_say)}</td><td class="rc-sag">${sayi(p.hizmet_say)}</td><td class="rc-sag"><b>${TL(p.ciro)}</b></td></tr>`, l, 'ciro');
  },

  musteri(v) {
    const c = v.cinsiyet || {};
    const top = (c.kadin || 0) + (c.erkek || 0) + (c.belirsiz || 0);
    const yuzde = (n) => (top ? Math.round(n / top * 100) : 0);
    el('#rpIcerik').innerHTML = `
      <div class="rc-ozet">
        ${ozetKart('bi-people', 'Aktif Müşteri', sayi(v.toplam_aktif), 'mor')}
        ${ozetKart('bi-person-plus', 'Yeni Kayıt', sayi(v.yeni_musteri), 'yesil')}
        ${ozetKart('bi-arrow-repeat', 'Tekrar Gelen', sayi(v.tekrar_gelen), 'turuncu')}
        ${ozetKart('bi-heart', 'Sadakat', '%' + sayi(v.sadakat_yuzde), 'turkuaz')}
      </div>
      <div class="rp-izgara-2">
        ${panel('Cinsiyet Dağılımı', `<div class="rp-yigin">
            <span class="rp-yigin-gul" style="width:${yuzde(c.kadin)}%"></span><span class="rp-yigin-mor" style="width:${yuzde(c.erkek)}%"></span><span class="rp-yigin-gri" style="width:${yuzde(c.belirsiz)}%"></span></div>
          <div class="rp-lejant"><span><i class="rp-yigin-gul"></i>Kadın ${sayi(c.kadin)}</span><span><i class="rp-yigin-mor"></i>Erkek ${sayi(c.erkek)}</span><span><i class="rp-yigin-gri"></i>Belirsiz ${sayi(c.belirsiz)}</span></div>`, 'gul')}
        ${panel('En Çok Harcayan 10 Müşteri', '<div id="rpTablo"></div>', 'mor')}
      </div>`;
    yerelTablo('isletme-musteri', [
      { baslik: 'Müşteri', sirala: 'ad', deger: (m) => m.ad }, { baslik: 'Telefon' },
      { baslik: 'İşlem', sirala: 'islem', sinif: 'rc-sag', deger: (m) => m.islem_say },
      { baslik: 'Harcama', sirala: 'toplam', sinif: 'rc-sag', deger: (m) => m.toplam },
    ], (m) => `<tr><td>${esc(m.ad)}</td><td>${esc(m.telefon || '-')}</td><td class="rc-sag">${sayi(m.islem_say)}</td><td class="rc-sag"><b>${TL(m.toplam)}</b></td></tr>`,
    v.top_musteriler || [], 'toplam', 'Bu dönemde tahsilat yok.');
  },

  randevu(v) {
    const k = v.kaynak || {};
    const kTop = (k.isletme || 0) + (k.web || 0) + (k.uygulama || 0);
    const pay = (n) => (kTop ? Math.round(n / kTop * 100) : 0);
    const saatler = (v.saat_dagilim || []).filter((s) => Number(s.saat) >= 6);
    el('#rpIcerik').innerHTML = `
      <div class="rc-ozet">
        ${ozetKart('bi-calendar3', 'Toplam', sayi(v.toplam), 'mor')}
        ${ozetKart('bi-check2-circle', 'Sonuçlanan', sayi(v.sonuclanan), 'yesil')}
        ${ozetKart('bi-person-x', 'Gelmedi', sayi(v.gelmedi), 'turuncu')}
        ${ozetKart('bi-x-circle', 'İptal', sayi(v.iptal), 'mavi')}
        ${ozetKart('bi-hourglass-split', 'Onaylı · Sonuçlanmadı', sayi(v.onayli), 'turkuaz')}
        ${ozetKart('bi-clock-history', 'Onay Bekleyen', sayi(v.bekleyen), 'mor')}
      </div>
      <div class="rp-izgara-3">
        ${panel('Randevu Kaynağı', `<div class="rp-kaynak">${[['bi-shop', 'İşletmeden', k.isletme], ['bi-globe2', 'Website', k.web], ['bi-phone', 'Mobil Uygulama', k.uygulama]]
          .map(([i, ad, n]) => `<div><i class="bi ${i}"></i><span>${ad}</span><b>${sayi(n)}</b><small>%${pay(n || 0)}</small></div>`).join('')}</div>`, 'turuncu')}
        ${panel('Saatlik Yoğunluk', tuval('rpSaat', 240), 'mor')}
        ${panel('Haftanın Günleri', tuval('rpGun', 240), 'yesil')}
      </div>`;
    cizgi(el('#rpSaat'), saatler.map((s) => s.saat + ':00'), saatler.map((s) => s.adet), { ek: ' randevu' });
    cubuk(el('#rpGun'), (v.gun_dagilim || []).map((g) => g.gun), (v.gun_dagilim || []).map((g) => g.adet), { renk: RENK.yesil, ek: ' randevu' });
  },
};

function kalemSekmesi(v, adetEtiket, cesitEtiket, grafikBaslik, renk, bos) {
  const l = v.kalemler || [];
  el('#rpIcerik').innerHTML = `
    <div class="rc-ozet">
      ${ozetKart('bi-123', adetEtiket, sayi(v.toplam_adet), 'mor')}
      ${ozetKart('bi-cash-stack', 'Ciro', TL(v.toplam_ciro), 'yesil')}
      ${ozetKart('bi-calculator', 'Ortalama Birim Fiyat', TL(v.ortalama_fiyat), 'turuncu')}
      ${ozetKart('bi-grid', cesitEtiket, sayi(v.cesit), 'mavi')}
    </div>
    <div class="rp-izgara-2">
      ${panel(grafikBaslik + ' (İlk 10)', l.length ? tuval('rpKalemGrafik', 320) : `<div class="rp-bos">${esc(bos)}</div>`, 'yesil')}
      ${panel('Detaylı Liste', '<div id="rpTablo"></div>', 'mor')}
    </div>`;
  if (l.length) {
    const ilk = l.slice(0, 10);
    cubuk(el('#rpKalemGrafik'), ilk.map((k) => k.ad), ilk.map((k) => k.ciro), { yatay: true, para: true, renk });
  }
  yerelTablo('isletme-kalem', [
    { baslik: 'Ad', sirala: 'ad', deger: (k) => k.ad },
    { baslik: 'Adet', sirala: 'adet', sinif: 'rc-sag', deger: (k) => k.adet },
    { baslik: 'Ciro', sirala: 'ciro', sinif: 'rc-sag', deger: (k) => k.ciro },
  ], (k) => `<tr><td>${esc(k.ad)}</td><td class="rc-sag">${sayi(k.adet)}</td><td class="rc-sag"><b>${TL(k.ciro)}</b></td></tr>`, l, 'ciro', bos);
}

/** Küçük, zaten yüklenmiş listeler: ortak tablo YEREL modda (arama/sıralama/sayfalama tarayıcıda). */
function yerelTablo(anahtar, kolonlar, satir, kayitlar, siralaAlan, bosMesaj = 'Kayıt yok.') {
  veriTablosu(el('#rpTablo'), { anahtar, kolonlar, satir, bosMesaj, sirala: { alan: siralaAlan, yon: 'desc' } }).kayitlarAyarla(kayitlar);
}

baslat();
