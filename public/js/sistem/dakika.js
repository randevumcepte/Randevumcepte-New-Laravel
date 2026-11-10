/** Dakika Yonetimi: e-santral musterilerinin giden dakika havuzu. Veri /api/v2/sistem/dakika. */
import { el, esc, mesaj } from '/js/lib/v2-ortak.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=8';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';
import { ekran, syApi, syYaz, para, tarih, tarihSecici } from '/js/lib/v2-sistem.js?v=7';

const UYARI_YUZDE = 70, KRITIK_YUZDE = 90;

let izinler = { yaz: false };
let tablo = null;
const satirlar = new Map();

const kart = (id, etiket) => `<div class="kutu"><div class="k-etiket">${etiket}</div><div class="k-deger dk-num"><span id="${id}">—</span> <small>dk</small></div></div>`;

ekran().innerHTML = `
  <div class="sy-page-head"><div><h2>Dakika Yönetimi</h2>
    <div class="subtitle">E-santral müşterilerine tanımlanan giden (dış hat) konuşma dakikası vs. harcanan</div></div></div>
  <div class="dk-ozet">${kart('dkTanimli', 'Toplam Tanımlı')}${kart('dkKullanilan', 'Toplam Kullanılan')}${kart('dkKalan', 'Toplam Kalan')}</div>
  <div class="sy-card"><div class="sy-card-body" id="dkTablo"></div></div>`;

function satirCiz(s) {
  satirlar.set(s.salon_id, s);
  const bar = s.yuzde >= KRITIK_YUZDE ? 'dk-bar-kritik' : (s.yuzde >= UYARI_YUZDE ? 'dk-bar-uyari' : 'dk-bar-ok');
  return `<tr>
    <td><div class="dk-salon-ad">${esc(s.salon_adi)}</div><div class="dk-salon-sub">#${s.salon_id} · ${s.adet} giden çağrı</div></td>
    <td class="dk-num">${esc(s.trunk)}</td>
    <td class="dk-num">${esc(tarih(s.sayim_baslangic))}</td>
    <td class="dk-num rc-sag">${para(s.tanimli, 0)}</td>
    <td class="dk-num rc-sag">${para(s.kullanilan, 1)}${s.hata ? '<div class="dk-hata">veri alınamadı</div>' : ''}</td>
    <td class="dk-num rc-sag"><span class="${s.kalan < 0 ? 'dk-kalan-eksi' : 'dk-kalan-arti'}">${para(s.kalan, 1)}</span></td>
    <td class="dk-bar-wrap"><div class="dk-bar ${bar}"><span style="width:${s.yuzde}%"></span></div><div class="dk-yuzde">%${s.yuzde}${s.tanimli === 0 ? ' (tanımsız)' : ''}</div></td>
    <td class="rc-sag">${izinler.yaz ? `<button class="sy-btn sy-btn-soft sy-btn-sm" data-duzenle="${s.salon_id}"><span class="mdi mdi-pencil"></span> Düzenle</button>` : ''}</td>
  </tr>`;
}

function duzenle(s) {
  const alt = '<button class="sy-btn sy-btn-soft" id="dkVazgec">Vazgeç</button><button class="sy-btn sy-btn-primary" id="dkKaydet"><span class="mdi mdi-content-save"></span> Kaydet</button>';
  const ov = modalAc('Tanımlı Dakika', `
    <div class="rc-alan"><label>Tanımlı dakika (havuz)</label>
      <input type="number" id="dkDakika" min="0" step="1" placeholder="Örn. 1000" value="${s.tanimli}">
      <div class="rc-ipucu">Müşteriye tanınan toplam giden konuşma dakikası. Havuzu büyütmek için artırın.</div></div>
    <div class="rc-alan dk-aralik"><label>Sayım başlangıç tarihi (opsiyonel)</label>
      <input type="text" id="dkSayim" autocomplete="off" value="${esc(s.sayim_baslangic)}">
      <div class="rc-ipucu">Boş bırakılırsa salonun oluşturma tarihinden itibaren sayılır.</div></div>`, alt,
  { ikon: 'telephone', dar: true, altbaslik: `${s.salon_adi} (#${s.salon_id})` });
  tarihSecici(el('#dkSayim', ov));
  el('#dkVazgec', ov).onclick = modalKapat;
  el('#dkDakika', ov).focus();
  el('#dkKaydet', ov).onclick = async (e) => {
    const dakika = el('#dkDakika', ov).value;
    if (dakika === '') { mesaj('Tanımlı dakika zorunlu.', 'hata'); return; }
    const b = e.currentTarget;
    b.disabled = true;
    const { tamam } = await syYaz('PUT', `/dakika/${s.salon_id}`, { tanimli_dakika: parseInt(dakika, 10), sayim_baslangic: el('#dkSayim', ov).value || null });
    if (tamam) { modalKapat(); tablo.yenile(false); } else b.disabled = false;
  };
}

tablo = veriTablosu(el('#dkTablo'), {
  anahtar: 'sy-dakika', birim: 'salon', varsayilanBoy: 25, araIpucu: 'Salon adı / yetkili / telefon…', bosMesaj: 'Santral (trunk) tanımlı müşteri bulunamadı.',
  sirala: { alan: 'yuzde', yon: 'desc' },
  kolonlar: [{ baslik: 'Salon', sirala: 'salon' }, { baslik: 'Trunk' }, { baslik: 'Sayım Başlangıç', sirala: 'sayim' }, { baslik: 'Tanımlı (dk)', sirala: 'tanimli', sinif: 'rc-sag' },
    { baslik: 'Kullanılan (dk)', sirala: 'kullanilan', sinif: 'rc-sag' }, { baslik: 'Kalan (dk)', sirala: 'kalan', sinif: 'rc-sag' }, { baslik: 'Kullanım', sirala: 'yuzde' }, { baslik: '' }],
  satir: satirCiz,
  kaynak: async (p) => {
    const r = await syApi('GET', '/dakika', { params: p });
    if (r.govde?.veri?.izinler) izinler = r.govde.veri.izinler;
    return r;
  },
  cizildi: (_, v) => {
    if (!v?.ozet) return;
    el('#dkTanimli').textContent = para(v.ozet.tanimli, 0);
    el('#dkKullanilan').textContent = para(v.ozet.kullanilan, 1);
    el('#dkKalan').textContent = para(v.ozet.kalan, 1);
  },
});
el('#dkTablo').addEventListener('click', (e) => {
  const b = e.target.closest('button[data-duzenle]');
  if (b) duzenle(satirlar.get(parseInt(b.dataset.duzenle, 10)));
});
tablo.yenile();
