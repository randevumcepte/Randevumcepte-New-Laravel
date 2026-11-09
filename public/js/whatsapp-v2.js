/**
 * WhatsApp Yönetimi (v2) — build'siz island (sade ES module). Eski /isletmeyonetim/whatsapp.
 *
 * Sekme listesi, sözlükler, kanal, linkler ve kontör TEK istekte GET /api/v2/whatsapp'tan (Flutter
 * da aynı ucu kullanır). Her sekme kendi modülünü tembel yükler (public/js/whatsapp/<kod>.js →
 * export async function ciz(kap, ctx)). YENİ SEKME: sunucuda Domain\Whatsapp\WhatsappSayfasi'na
 * tek satır + aşağıdaki MODULLER'e tek satır.
 */
import { qp, api, el, esc, onay } from '/js/lib/v2-ortak.js?v=7';
import { sekmeliSayfa } from '/js/lib/v2-sekme.js?v=7';

const kok = document.getElementById('rc-whatsapp');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
if (salonId && window.rcPanel) window.rcPanel.salonAyarla(salonId);

const MODULLER = {
  baglanti: () => import('/js/whatsapp/baglanti.js?v=1'),
  kontor: () => import('/js/whatsapp/kontor.js?v=1'),
  istatistik: () => import('/js/whatsapp/istatistik.js?v=1'),
  mesajlar: () => import('/js/whatsapp/mesajlar.js?v=1'),
  aliciler: () => import('/js/whatsapp/aliciler.js?v=1'),
};

/** Kontör uyarısı: gösterim sıklığı (gunluk_limit) sunucudan; sayaç bu cihazda, tercih niteliğinde. */
function uyariHakkiVar(limit) {
  const anahtar = `wa_kontor_uyari_${salonId}`;
  const bugun = new Date().toISOString().slice(0, 10);
  try {
    let kayit = JSON.parse(localStorage.getItem(anahtar) || '{}');
    if (kayit.tarih !== bugun) kayit = { tarih: bugun, sayi: 0 };
    if (kayit.sayi >= limit) return false;
    kayit.sayi++;
    localStorage.setItem(anahtar, JSON.stringify(kayit));
  } catch (_) { /* depolama kapalıysa her açılışta gösterilir */ }
  return true;
}

async function kontorUyarisi(uyari, sayfa) {
  if (!uyari || !uyariHakkiVar(uyari.gunluk_limit)) return;
  const al = await onay(uyari.metin, { baslik: uyari.baslik, tamam: 'Kontör Al', iptal: 'Daha Sonra', tehlike: false });
  if (al) sayfa.ac('kontor');
}

(async function baslat() {
  kok.innerHTML = `
    <div class="wa-kabuk">
      <div class="rc-sayfa-ust"><div class="rc-sayfa-baslik">
        <span class="rc-sayfa-ikon"><i class="bi bi-whatsapp"></i></span>
        <div><h1>WhatsApp Yönetimi</h1><div class="rc-sayfa-alt">Bağlantı, kontör, mesaj geçmişi ve alıcılar</div></div>
      </div></div>
      <nav class="rc-sekmeler" id="waSekmeler"></nav>
      <div id="waIcerik"><div class="wa-bos"><span class="rc-yukleniyor"></span></div></div>
    </div>`;
  if (!salonId) { el('#waIcerik').innerHTML = '<div class="wa-bos">Şube seçili değil (?sube=... bekleniyor).</div>'; return; }

  const r = await api('GET', '/whatsapp', { params: { salon_id: salonId } });
  if (!r.ok || !r.govde?.basarili) {
    el('#waIcerik').innerHTML = `<div class="wa-bos wa-hata"><i class="bi bi-shield-lock"></i>${esc(r.govde?.mesaj || `Hata (${r.kod})`)}</div>`;
    return;
  }
  const acilis = r.govde.veri;
  const ctx = {
    salonId,
    acilis,
    gonderimDurumlari: Object.fromEntries(acilis.sozluk.gonderim_durumlari.map((d) => [d.kod, d])),
    oku: (yol, params = {}) => api('GET', yol, { params: { ...params, salon_id: salonId } }),
    yaz: (method, yol, body = {}) => api(method, yol, { body: { ...body, salon_id: salonId } }),
  };
  const sayfa = sekmeliSayfa(el('#waSekmeler'), el('#waIcerik'), { sekmeler: acilis.sekmeler, moduller: MODULLER, ctx });
  kontorUyarisi(acilis.kontor.uyari, sayfa);
})();
