/**
 * Paket satış kalemi — TEK bileşen (Paket Yönetimi "Satış Yap", Yeni Satış ve Satış & Tahsilat
 * "+ Paket"). Eski a47de8f0 paket modalının v2 karşılığı:
 *   - paket seçilince seans (bölge başına hak: satis_seans), fiyat ve hizmet süresi gelir,
 *   - satırda hizmet çıkar / aramalı hizmet ekle (liste katalogdan farklıysa sunucu satışa özel
 *     kopya açar: PaketSurumu — katalog değişmez),
 *   - sınırsız pakette seans alanı yerine "Sınırsız · süre" rozeti.
 * Kurallar sunucuda (KalemServisi); burada yalnız gösterim ve girdi toplanır.
 *
 * Kullanım:
 *   kap.innerHTML = paketSatisKalemi(p, { fiyat: true, fiyatAcik });
 *   paketSatisBagla(kap, { paketBul, hizmetler });          // olaylar kap üzerinde (bir kez)
 *   paketSatisGirdisi(k, { fiyat: true, fiyatAcik })         // {paket_id, seans, hizmetler, fiyat?}
 *
 * p: PaketSunumu / KatalogServisi paket satırı — id|paket_id, paket_adi|ad, fiyat, satis_seans,
 *    sinirsiz, gecerlilik_metni, gunluk_limit, sure, hizmetler[{hizmet_id, hizmet_adi, seans, fiyat}].
 * hizmetler: salonun hizmetleri [{hizmet_id, ad, fiyat}] (eklenen hizmetin fiyatı için).
 */

import { el, esc, mesaj } from '/js/lib/v2-ortak.js?v=2';

const CSS = '/css/v2-paket-satis.css?v=1';
if (!document.querySelector(`link[href="${CSS}"]`)) {
  document.head.insertAdjacentHTML('beforeend', `<link rel="stylesheet" href="${CSS}">`);
}

const paketId = (p) => p.id ?? p.paket_id;
const sayiVeyaNull = (v) => (String(v ?? '').trim() === '' ? null : Number(String(v).replace(',', '.')));
const hizmetSatiri = (id, ad) => `<label class="pk-hizmet-satir"><input type="checkbox" value="${esc(id)}" checked> ${esc(ad)}</label>`;

/** Sınırsız paket rozeti ("Sınırsız · 1 Yıl · günde 1"). */
export const sinirsizRozet = (p) => `<span class="rc-rozet rc-rozet-mor">Sınırsız</span>${p.gecerlilik_metni ? ` <small class="pk-soluk">${esc(p.gecerlilik_metni)}${p.gunluk_limit ? ` · günde ${p.gunluk_limit}` : ''}</small>` : ''}`;

/** Tek paket kaleminin HTML'i (sarmalayıcı .pk-satis-kalem[data-id]). fiyat:false → fiyat alanı çağıranda. */
export function paketSatisKalemi(p, { fiyat = true, fiyatAcik = false, baslik = true } = {}) {
  const sure = p.sure ? ` <small class="pk-soluk">· ${esc(p.sure)} dk</small>` : '';
  const gecerlilik = !p.sinirsiz && p.gecerlilik_metni ? ` <small class="pk-soluk">· ${esc(p.gecerlilik_metni)}</small>` : '';
  return `<div class="pk-satis-kalem" data-id="${esc(paketId(p))}">
    ${baslik ? `<div class="pk-ad">${esc(p.paket_adi ?? p.ad)}${sure}${gecerlilik}</div>` : ''}
    <div class="rc-alan pk-satis-hizmet"><label>Hizmetler</label>
      <div class="pk-hizmet-secim" data-secim>${(p.hizmetler || []).map((h) => hizmetSatiri(h.hizmet_id, h.hizmet_adi)).join('')}</div>
      <div class="pk-hizmet-ara"><i class="bi bi-search"></i><input type="text" data-hizmet-ara placeholder="Hizmet ekle: aramak için yazın" autocomplete="off"><div class="pk-hizmet-sonuc" data-sonuc hidden></div></div>
    </div>
    <div class="rc-form-izgara">
      ${p.sinirsiz
        ? `<div class="rc-alan"><label>Paket</label><div class="pk-sinirsiz">${sinirsizRozet(p)} <small class="pk-soluk">(ilk gelişte başlar)</small></div></div>`
        : `<div class="rc-alan"><label>Seans Sayısı <small class="pk-soluk">(bölge başına)</small></label><input type="number" min="0" step="1" data-seans value="${esc(p.satis_seans ?? '')}"></div>`}
      ${fiyat ? `<div class="rc-alan"><label>Fiyat (₺)</label><input type="number" min="0" step="0.01" data-fiyat value="${esc(p.fiyat ?? '')}" ${fiyatAcik ? '' : 'readonly title="Fiyat değiştirme yetkiniz yok"'}></div>` : ''}
    </div></div>`;
}

/** Kalemde işaretli hizmet id'leri. */
export const seciliHizmetler = (k) => [...k.querySelectorAll('[data-secim] input:checked')].map((c) => Number(c.value));

/** Kalem girdisi: sınırsızda seans gönderilmez (sunucu tavanı yazar). */
export function paketSatisGirdisi(k, { fiyat = true, fiyatAcik = false } = {}) {
  const seansAlani = el('[data-seans]', k);
  const kalem = { paket_id: Number(k.dataset.id), seans: seansAlani ? sayiVeyaNull(seansAlani.value) : null, hizmetler: seciliHizmetler(k) };
  if (fiyat && fiyatAcik) kalem.fiyat = sayiVeyaNull(el('[data-fiyat]', k)?.value);
  return kalem;
}

/**
 * Olayları kap üzerinde bağlar (kap içeriği değişse de geçerli; bir kez çağrılır).
 * fiyatAlani(k): seçim değişince güncellenecek fiyat input'u (varsayılan kalemin [data-fiyat]'ı).
 */
export function paketSatisBagla(kap, { paketBul, hizmetler = () => [], fiyatAlani = (k) => el('[data-fiyat]', k) }) {
  const salonHizmetleri = () => (typeof hizmetler === 'function' ? hizmetler() : hizmetler) || [];
  const kalemi = (n) => n.closest('.pk-satis-kalem');

  // Pakette hizmet bazlı fiyat varsa fiyat seçime göre (sunucu: PaketSurumu::secimFiyati ile aynı kural).
  const fiyatGuncelle = (k) => {
    const alan = fiyatAlani(k);
    const p = paketBul(k.dataset.id);
    if (!alan || !p || !(p.hizmetler || []).some((h) => h.fiyat > 0)) return;
    alan.value = seciliHizmetler(k).reduce((t, id) => t + Number(p.hizmetler.find((h) => h.hizmet_id === id)?.fiyat ?? salonHizmetleri().find((s) => s.hizmet_id === id)?.fiyat ?? 0), 0);
  };
  // Yüzlerce hizmette düz liste kullanılmaz, yazdıkça süzülür (en çok 50 satır).
  const sonucCiz = (k) => {
    const q = el('[data-hizmet-ara]', k).value.toLocaleLowerCase('tr').trim();
    const ekli = [...k.querySelectorAll('[data-secim] input')].map((c) => Number(c.value));
    const liste = salonHizmetleri().filter((s) => !ekli.includes(s.hizmet_id) && s.ad.toLocaleLowerCase('tr').includes(q));
    const kutu = el('[data-sonuc]', k);
    kutu.innerHTML = liste.slice(0, 50).map((s) => `<button type="button" data-hizmet-ekle="${s.hizmet_id}">${esc(s.ad)}</button>`).join('')
      + (!liste.length ? '<div class="pk-hizmet-not">Sonuç bulunamadı</div>' : liste.length > 50 ? `<div class="pk-hizmet-not">${liste.length - 50} hizmet daha var, aramayı daraltın</div>` : '');
    kutu.hidden = false;
  };
  const hizmetEkle = (k, b) => {
    el('[data-secim]', k).insertAdjacentHTML('beforeend', hizmetSatiri(b.dataset.hizmetEkle, b.textContent));
    const giris = el('[data-hizmet-ara]', k);
    giris.value = '';
    giris.focus();
    sonucCiz(k);
    fiyatGuncelle(k);
  };

  kap.addEventListener('change', (e) => {
    const k = kalemi(e.target);
    if (!k || !e.target.matches('[data-secim] input')) return;
    if (!seciliHizmetler(k).length) {
      e.target.checked = true; // paket en az bir hizmet içermeli
      mesaj('Pakette en az bir hizmet kalmalıdır.', 'hata');
      return;
    }
    fiyatGuncelle(k);
  });
  kap.addEventListener('input', (e) => { if (e.target.matches('[data-hizmet-ara]')) sonucCiz(kalemi(e.target)); });
  kap.addEventListener('focusin', (e) => { if (e.target.matches('[data-hizmet-ara]')) sonucCiz(kalemi(e.target)); });
  kap.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || !e.target.matches('[data-hizmet-ara]')) return;
    e.preventDefault();
    const k = kalemi(e.target);
    const ilk = el('[data-hizmet-ekle]', k);
    if (ilk) hizmetEkle(k, ilk);
  });
  kap.addEventListener('mousedown', (e) => {
    if (!e.target.closest('.pk-hizmet-ara')) kap.querySelectorAll('[data-sonuc]').forEach((s) => { s.hidden = true; });
  });
  kap.addEventListener('click', (e) => {
    const b = e.target.closest('[data-hizmet-ekle]');
    if (b) hizmetEkle(kalemi(b), b);
  });
}
