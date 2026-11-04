/**
 * Header araçları — kalan SMS + WhatsApp durumu + WebRTC softphone rozetleri.
 * Eski layout_isletmeadmin header'ındaki üç butonun v2 karşılığı; hepsi tüm v2 ekranlarında
 * (panel kabuğu) görünür. Görünürlük kuralları SUNUCUDA (veri null → rozet çizilmez):
 *   SMS / WhatsApp : personel rolüne (5) gizli
 *   Softphone      : salonda santral etkin + kullanıcının WebRTC dahilisi tanımlı
 *
 * Kabuk verisi ('rc:kabuk') her geldiğinde salon değiştiyse rozetler yeniden kurulur;
 * softphone yalnız salon değişince (yeni dahili) yeniden bağlanır.
 */
import { api, el, esc } from '/js/lib/v2-ortak.js?v=1';

const SIP_KUTUPHANESI = '/js/vendor/sip-0.21.2.min.js';

let _salon = null;
let _softphone = null; // {yokEt}
let _nesil = 0;        // hızlı salon değişiminde geç gelen cevabı yok saymak için

const getir = async (yol, salonId) => {
  const r = await api('GET', yol, { params: { salon_id: salonId } });
  return r.ok && r.govde?.basarili ? r.govde.veri : null;
};

const sayi = (n) => Number(n).toLocaleString('tr-TR');

/** Rozet: link varsa <a>, yoksa yalnız bilgi veren <span>. */
function rozet({ url, sinif, baslik, icerik }) {
  const etiket = url ? 'a' : 'span';
  const href = url ? ` href="${esc(url)}"` : '';
  return `<${etiket} class="rc-rozet ${sinif}"${href} title="${esc(baslik)}">${icerik}</${etiket}>`;
}

function smsCiz(kap, v) {
  if (!v) { kap.innerHTML = ''; return; }
  const kalan = v.kalan === null ? '—' : sayi(v.kalan);
  kap.innerHTML = rozet({
    url: v.url,
    sinif: 'rc-rozet-sms',
    baslik: v.kalan === null ? 'Kalan SMS okunamadı' : 'Kalan SMS',
    icerik: `<i class="bi bi-envelope-fill"></i><span class="rc-rozet-sayi">${kalan}</span>`,
  });
}

function whatsappCiz(kap, v) {
  if (!v) { kap.innerHTML = ''; return; }
  let baslik = v.bagli ? `WhatsApp bağlı${v.numara ? ` (${v.numara})` : ''}` : 'WhatsApp bağlı değil — QR ile bağlayın';
  if (v.kontor !== null) baslik += ` · Kontör: ${sayi(v.kontor)}`;
  if (v.paylasilan) baslik += ` · Paylaşılan oturum: ${v.paylasilan.salon_adi}`;
  const kontor = v.kontor === null ? ''
    : `<span class="rc-rozet-kontor${v.kontor_kritik ? ' kritik' : ''}" title="WhatsApp kontör bakiyesi">${sayi(v.kontor)}</span>`;
  kap.innerHTML = rozet({
    url: v.url,
    sinif: 'rc-rozet-wa ' + (v.bagli ? 'bagli' : 'kopuk'),
    baslik,
    icerik: `<i class="bi bi-whatsapp"></i><span class="rc-rozet-metin">${v.bagli ? 'Bağlı' : 'Bağlı Değil'}</span>${kontor}`,
  });
}

let _sipYukleme = null;
function sipKutuphanesi() {
  if (window.SIP) return Promise.resolve();
  _sipYukleme ??= new Promise((coz, red) => {
    const s = document.createElement('script');
    s.src = SIP_KUTUPHANESI;
    s.onload = coz;
    s.onerror = () => { _sipYukleme = null; red(new Error('sip.js yüklenemedi')); };
    document.head.appendChild(s);
  });
  return _sipYukleme;
}

async function softphoneKurulum(kap, salonId, nesil) {
  _softphone?.yokEt();
  _softphone = null;
  const ayar = await getir('/softphone', salonId);
  if (!ayar || nesil !== _nesil) return;
  // Softphone kodu + sip.js (~270 KB) yalnız telefonu olan kullanıcıya yüklenir.
  const [{ softphoneKur }] = await Promise.all([import('/js/softphone/softphone-panel.js?v=3'), sipKutuphanesi()]);
  if (nesil !== _nesil) return;
  _softphone = softphoneKur(kap, salonId, ayar);
}

function salonKur(salonId) {
  salonId = parseInt(salonId, 10) || null;
  if (!salonId || salonId === _salon) return;
  _salon = salonId;
  const nesil = ++_nesil;
  const sms = el('#rcSmsRozet'), wa = el('#rcWaRozet'), sp = el('#rcSoftphone');
  if (!sms || !wa || !sp) return;

  getir('/kabuk/sms', salonId).then((v) => { if (nesil === _nesil) smsCiz(sms, v); });
  getir('/kabuk/whatsapp', salonId).then((v) => { if (nesil === _nesil) whatsappCiz(wa, v); });
  softphoneKurulum(sp, salonId, nesil).catch((e) => console.warn('[softphone]', e));
}

document.addEventListener('rc:kabuk', (e) => salonKur(e.detail?.salon?.id));
if (window.rcPanel?.veri) salonKur(window.rcPanel.veri.salon?.id);
