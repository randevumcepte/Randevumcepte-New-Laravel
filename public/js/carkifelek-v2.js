/**
 * Çarkıfelek (v2) — build'siz island (sade ES module). Eski 4 ayrı sayfa (Çarkıfelek, Çark
 * Kazananlar, Puan Ödülleri, Hatırlatma; partials/carkifelek_tabs) TEK sekmeli sayfada.
 *
 * Sekme listesi, çark ayarı, sözlükler (dilim tipleri, kupon durumları, renkler, sınırlar) ve
 * şube seçenekleri TEK istekte GET /api/v2/cark'tan (Flutter da aynı ucu kullanır). Her sekme
 * kendi modülünü tembel yükler (public/js/carkifelek/<kod>.js → export async function ciz(kap, ctx)).
 * YENİ SEKME: sunucuda Domain\Cark\CarkSekmeleri'ne tek satır + aşağıdaki MODULLER'e tek satır.
 */
import { qp, api, el, esc } from '/js/lib/v2-ortak.js?v=7';
import { sekmeliSayfa } from '/js/lib/v2-sekme.js?v=7';

const kok = document.getElementById('rc-cark');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
if (salonId && window.rcPanel) window.rcPanel.salonAyarla(salonId);

const MODULLER = {
  cark: () => import('/js/carkifelek/cark.js?v=1'),
  kazananlar: () => import('/js/carkifelek/kazananlar.js?v=1'),
  puan: () => import('/js/carkifelek/puan.js?v=1'),
  hatirlatma: () => import('/js/carkifelek/hatirlatma.js?v=1'),
};

(async function baslat() {
  kok.innerHTML = `
    <div class="ck-kabuk">
      <div class="rc-sayfa-ust"><div class="rc-sayfa-baslik">
        <span class="rc-sayfa-ikon"><i class="bi bi-bullseye"></i></span>
        <div><h1>Çarkıfelek</h1><div class="rc-sayfa-alt">Çark ödülleri, kuponlar, puan ödülleri ve hatırlatmalar</div></div>
      </div></div>
      <nav class="rc-sekmeler" id="ckSekmeler"></nav>
      <div id="ckIcerik"><div class="ck-bos"><span class="rc-yukleniyor"></span></div></div>
    </div>`;
  if (!salonId) { el('#ckIcerik').innerHTML = '<div class="ck-bos">Şube seçili değil (?sube=... bekleniyor).</div>'; return; }

  const r = await api('GET', '/cark', { params: { salon_id: salonId } });
  if (!r.ok || !r.govde?.basarili) {
    el('#ckIcerik').innerHTML = `<div class="ck-bos ck-hata"><i class="bi bi-shield-lock"></i>${esc(r.govde?.mesaj || `Hata (${r.kod})`)}</div>`;
    return;
  }
  const acilis = r.govde.veri;
  const ctx = {
    salonId,
    acilis,
    // Dilim tipi sözlüğü kod → tanım (çizim ve editör ortak kullanır)
    tipler: Object.fromEntries(acilis.sozluk.dilim_tipleri.map((t) => [t.kod, t])),
    oku: (yol, params = {}) => api('GET', yol, { params: { ...params, salon_id: salonId } }),
    yaz: (method, yol, body = {}) => api(method, yol, { body: { ...body, salon_id: salonId } }),
  };
  sekmeliSayfa(el('#ckSekmeler'), el('#ckIcerik'), { sekmeler: acilis.sekmeler, moduller: MODULLER, ctx });
})();
