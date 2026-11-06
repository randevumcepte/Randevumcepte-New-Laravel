/**
 * Sistem yonetimi ekranlarinin ORTAK yardimcilari — TEK KAYNAK.
 *   import { syApi, syYaz, syVeri, ekran, kayitId, syIndir, syForm, para, tarihSaat, tarih } from '/js/lib/v2-sistem.js?v=1';
 * Geri kalani (el, esc, mesaj, onay, veriTablosu, modalAc) isletme paneliyle ayni kutuphanelerden gelir.
 * Kimlik: localStorage rc_sy_token — isletme panelinin rc_access_token'indan ayri.
 */
import { apiIstemcisi, yazSonuc, mesaj, el } from '/js/lib/v2-ortak.js?v=2';
import { apiForm, apiBlob, blobIndir } from '/js/lib/v2-dosya.js?v=3';

const KOK = '/api/v2/sistem';
const TOKEN = 'rc_sy_token';

export const syToken = () => localStorage.getItem(TOKEN) || '';
export const syTokenYaz = (t) => localStorage.setItem(TOKEN, t);
export const syTokenSil = () => localStorage.removeItem(TOKEN);

const kimlik = { kok: KOK, tokenOku: syToken };

export const syApi = apiIstemcisi(syToken, KOK);

/** Oturum dustuyse giris sayfasina (donus adresiyle). */
export function girisGit() {
  location.href = '/sistemyonetim/giris?donus=' + encodeURIComponent(location.pathname + location.search);
}

/** Okuma: basariliysa veri; degilse hata toast'i + null. 401 girise yonlendirir. */
export async function syVeri(yol, params) {
  const r = await syApi('GET', yol, { params });
  if (r.kod === 401) { girisGit(); return null; }
  if (r.ok && r.govde?.basarili) return r.govde.veri;
  mesaj(r.govde?.mesaj || `Hata (${r.kod})`, 'hata');
  return null;
}

/** Yazma: {tamam, veri}. Basari mesaji verilmezse sunucunun mesaji gosterilir. */
export async function syYaz(method, yol, body, basariMesaji) {
  const r = await syApi(method, yol, { body });
  if (r.kod === 401) { girisGit(); return { tamam: false, veri: null }; }
  const tamam = yazSonuc(r, basariMesaji ?? r.govde?.mesaj);
  return { tamam, veri: tamam ? r.govde.veri : null };
}

/** Dosyali yazma (FormData). */
export async function syForm(yol, formData, basariMesaji) {
  const r = await apiForm(yol, formData, 'POST', kimlik);
  const tamam = yazSonuc(r, basariMesaji ?? r.govde?.mesaj);
  return { tamam, veri: tamam ? r.govde.veri : null };
}

/** CSV/Excel indirme (Bearer gerektigi icin blob). */
export async function syIndir(yol, params, dosyaAdi) {
  const r = await apiBlob(yol, params, kimlik);
  if (r.ok) blobIndir(r.blob, dosyaAdi);
  else mesaj(r.govde?.mesaj || `Hata (${r.kod})`, 'hata');
}

/** Ekran koku + URL'deki kayit id'si. */
export const ekran = () => el('#sy-ekran');
export const kayitId = () => parseInt(ekran().dataset.id, 10) || null;

export const para = (n, b = 2) => (Number(n) || 0).toLocaleString('tr-TR', { minimumFractionDigits: b, maximumFractionDigits: b });

const tarihOku = (d) => (d ? new Date(String(d).replace(' ', 'T')) : null);
export function tarih(d) {
  const t = tarihOku(d);
  return t && !isNaN(t) ? t.toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—';
}
export function tarihSaat(d) {
  const t = tarihOku(d);
  return t && !isNaN(t) ? `${tarih(d)} ${t.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' })}` : '—';
}

/** flatpickr tarih secici: gorunum d.m.Y, value Y-m-d (native date input kullanilmaz). Layout yukler. */
export function tarihSecici(girdi, degisti) {
  if (!window.flatpickr) return null;
  return window.flatpickr(girdi, { locale: 'tr', dateFormat: 'Y-m-d', altInput: true, altFormat: 'd.m.Y', allowInput: true, disableMobile: true, onChange: (_d, s) => degisti?.(s) });
}

let chartSozu = null;
/** Chart.js kabukta yuklu degil; yalniz grafik ceken ekran ister (v2-grafik window.Chart bekler). */
export function chartYukle() {
  if (window.Chart) return Promise.resolve();
  chartSozu ??= new Promise((tamam, hata) => {
    const s = Object.assign(document.createElement('script'), { src: 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', onload: tamam, onerror: hata });
    document.head.appendChild(s);
  });
  return chartSozu;
}
