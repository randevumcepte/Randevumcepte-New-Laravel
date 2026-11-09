/**
 * v2 HAREKET (aktivite log) TABLOSU — tek bileşen; "Log Hareketleri" sayfası ve başka ekranlara
 * gömülen hareket geçmişleri (müşteri kartı, personel, randevu detayı …) AYNI kodu kullanır.
 *
 * TEK KAYNAK: satırlar, kategori/kaynak/renk/ikon, filtre seçenekleri /api/v2/hareketler* uçlarından
 * (Flutter da aynı uçlar); burada iş kuralı yok — yalnız çizim.
 *
 * Kullanım:
 *   hareketTablosu(el('#kap'), { salonId });                                       // tam sayfa
 *   hareketTablosu(el('#kap'), { salonId, sabit: { hedef_tipi: 'musteri', hedef_id: 42 },
 *                                filtreler: false, anahtar: 'musteri_hareket' });   // gömülü
 *   sabit   : her istekte gönderilen, kullanıcının değiştiremeyeceği filtreler
 *   filtreler: false → yalnız tablo (tablo içi arama + sayfalama yine var)
 */
import { api, el, esc } from '/js/lib/v2-ortak.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=7';

const secenek = (liste, bos, deger = 'kod', ad = 'etiket') =>
  `<option value="">${esc(bos)}</option>` + liste.map((x) => `<option value="${esc(x[deger])}">${esc(x[ad])}</option>`).join('');

/** Kategori renkli rozet/ikon için inline stil (renkler API'den). */
const renkli = (k) => `background:${esc(k.zemin)};color:${esc(k.renk)}`;

function satir(h) {
  const k = h.islem.kategori;
  const hd = h.hedef;
  return `<tr class="hk-satir" style="--hk-renk:${esc(k.renk)};--hk-zemin:${esc(k.zemin)}">
    <td class="hk-zaman"><span class="hk-serit"></span>
      <div class="hk-tarih">${esc(h.tarih)}</div><div class="hk-soluk">${esc(h.saat)}</div><div class="hk-soluk">${esc(h.once)}</div></td>
    <td><div class="hk-kisi">
      <span class="hk-avatar" style="background:${esc(h.kullanici.renk)}">${esc(h.kullanici.bas_harf)}</span>
      <div><div class="hk-guclu">${esc(h.kullanici.ad)}</div><div class="hk-rol" style="color:${esc(h.kullanici.renk)}">${esc(h.kullanici.rol || '—')}</div></div>
    </div></td>
    <td><div class="hk-islem">
      <span class="hk-ikon" style="${renkli(k)}"><i class="bi ${esc(k.ikon)}"></i></span>
      <div><span class="hk-rozet" style="${renkli(k)}" title="${esc(h.islem.kod)}">${esc(h.islem.etiket)}</span>
        <div class="hk-kat" style="color:${esc(k.renk)}">${esc(k.etiket)}
          <span class="hk-kaynak hk-kaynak-${esc(h.kaynak.kod)}"><i class="bi ${esc(h.kaynak.ikon)}"></i> ${esc(h.kaynak.etiket)}</span></div></div>
    </div></td>
    <td>${hd.tip ? `<span class="hk-hedef-tip">${esc(hd.tip_etiket)}${hd.id ? ' #' + esc(hd.id) : ''}</span><br>` : ''}
      <span class="hk-guclu">${esc(hd.etiket || '—')}</span></td>
    <td><span class="hk-aciklama">${esc(h.aciklama || '—')}</span>
      ${h.detay ? `<details class="hk-detay"><summary>Detay</summary><pre>${esc(JSON.stringify(h.detay, null, 2))}</pre></details>` : ''}</td>
    <td><span class="hk-ip">${esc(h.ip || '')}</span></td>
  </tr>`;
}

const KOLONLAR = [
  { baslik: 'Zaman', sirala: 'zaman', sinif: 'hk-k-zaman' },
  { baslik: 'Kullanıcı', sirala: 'kullanici', sinif: 'hk-k-kisi' },
  { baslik: 'İşlem', sirala: 'islem', sinif: 'hk-k-islem' },
  { baslik: 'Hedef', sirala: 'hedef' },
  { baslik: 'Açıklama' },
  { baslik: 'IP', sinif: 'hk-k-ip' },
];

export async function hareketTablosu(kap, { salonId, sabit = {}, filtreler = true, anahtar = 'hareketler' }) {
  const filtre = {};
  kap.innerHTML = `<div class="hk">
      ${filtreler ? '<div class="hk-lejant" role="group" aria-label="Kategori"></div><div class="hk-filtre"></div>' : ''}
      <div class="hk-tablo"></div>
    </div>`;

  const tablo = veriTablosu(el('.hk-tablo', kap), {
    anahtar, kolonlar: KOLONLAR, satir, varsayilanBoy: 25, tabloSinif: 'hk-tablo-ic',
    birim: 'hareket', bosMesaj: 'Bu kriterlere uyan log hareketi yok.', araIpucu: 'Kullanıcı, hedef, açıklama…',
    sirala: { alan: 'zaman', yon: 'desc' },
    kaynak: (p) => api('GET', '/hareketler', { params: { ...p, ...filtre, ...sabit, salon_id: salonId } }),
  });

  tablo.yenile(); // filtre seçenekleriyle paralel
  if (filtreler) await filtreleriKur(kap, salonId, sabit, filtre, () => tablo.yenile());

  return { yenile: () => tablo.yenile(false) };
}

async function filtreleriKur(kap, salonId, sabit, filtre, yenile) {
  const r = await api('GET', '/hareketler/secenekler', { params: { salon_id: salonId } });
  const s = r.govde?.veri;
  if (!r.ok || !s) { el('.hk-filtre', kap).innerHTML = ''; return; } // tablo kendi hata satırını gösterir

  const lejant = el('.hk-lejant', kap);
  lejant.innerHTML = s.kategoriler.map((k) =>
    `<button type="button" class="hk-lejant-oge" data-kategori="${esc(k.kod)}" style="color:${esc(k.renk)}"><i class="bi ${esc(k.ikon)}"></i> ${esc(k.etiket)}</button>`).join('');
  lejant.addEventListener('click', (e) => {
    const b = e.target.closest('[data-kategori]');
    if (!b) return;
    filtre.kategori = filtre.kategori === b.dataset.kategori ? '' : b.dataset.kategori;
    lejant.querySelectorAll('[data-kategori]').forEach((x) => x.classList.toggle('aktif', x.dataset.kategori === filtre.kategori));
    yenile();
  });

  const alanlar = [
    ['islem', 'İşlem', `<select>${secenek(s.islemler, 'Hepsi')}</select>`],
    !sabit.kullanici_id && ['kullanici_id', 'Kullanıcı', `<select>${secenek(s.kullanicilar, 'Hepsi', 'id', 'ad')}</select>`],
    !sabit.hedef_tipi && ['hedef_tipi', 'Hedef', `<select>${secenek(s.hedef_tipleri, 'Hepsi')}</select>`],
    ['kaynak', 'Kaynak', `<select>${secenek(s.kaynaklar, 'Hepsi')}</select>`],
    ['tarih_bas', 'Başlangıç', '<input type="text" placeholder="gg.aa.yyyy" autocomplete="off">'],
    ['tarih_bit', 'Bitiş', '<input type="text" placeholder="gg.aa.yyyy" autocomplete="off">'],
  ].filter(Boolean);
  const kutu = el('.hk-filtre', kap);
  kutu.innerHTML = alanlar.map(([ad, etiket, html]) => `<div class="rc-alan" data-filtre="${ad}"><label>${etiket}</label>${html}</div>`).join('')
    + '<div class="rc-alan hk-sifirla"><label>&nbsp;</label><button type="button" class="rc-btn rc-btn-ikincil"><i class="bi bi-arrow-counterclockwise"></i> Sıfırla</button></div>';

  const degisti = (ad, v) => { filtre[ad] = v || ''; yenile(); };
  const tarihler = [];
  kutu.querySelectorAll('[data-filtre]').forEach((a) => {
    const ad = a.dataset.filtre;
    const g = el('select, input', a);
    if (g.tagName === 'SELECT') g.addEventListener('change', () => degisti(ad, g.value));
    else if (window.flatpickr) {
      tarihler.push(window.flatpickr(g, { locale: 'tr', dateFormat: 'Y-m-d', altInput: true, altFormat: 'd.m.Y', allowInput: true, disableMobile: true,
        onChange: (_d, str) => degisti(ad, str) }));
    }
  });
  el('.hk-sifirla button', kutu).addEventListener('click', () => {
    Object.keys(filtre).forEach((k) => { filtre[k] = ''; });
    kutu.querySelectorAll('select').forEach((x) => { x.value = ''; });
    tarihler.forEach((t) => t.clear(false));
    lejant.querySelectorAll('.aktif').forEach((x) => x.classList.remove('aktif'));
    yenile();
  });
}
