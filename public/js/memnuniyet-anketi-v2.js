/**
 * Memnuniyet Anketi (v2) — build'siz island. Eski isletmeadmin/anket_sonuclari.blade'in karşılığı.
 *
 * Sekmeler, soru kataloğu, ödül tipleri, filtreler, dönemler, hazır şablon ve yetkiler TEK istekte
 * GET /api/v2/anket/form-verisi'nden (Flutter da aynı ucu kullanır). Her sekme kendi modülünü tembel
 * yükler (public/js/anket/<kod>.js → export async function ciz(kap, ctx)).
 */
import { qp, api, el, esc } from '/js/lib/v2-ortak.js?v=2';
import { sekmeliSayfa } from '/js/lib/v2-sekme.js?v=1';

const kok = document.getElementById('rc-anket');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
if (salonId && window.rcPanel) window.rcPanel.salonAyarla(salonId);

const MODULLER = {
  sonuclar: () => import('/js/anket/sonuclar.js?v=1'),
  sablonlar: () => import('/js/anket/sablonlar.js?v=1'),
};

(async function baslat() {
  kok.innerHTML = `
    <div class="an-kabuk">
      <div class="rc-sayfa-ust"><div class="rc-sayfa-baslik">
        <span class="rc-sayfa-ikon"><i class="bi bi-chat-dots"></i></span>
        <div><h1>Memnuniyet Anketi</h1><div class="rc-sayfa-alt">Müşteri geri bildirimleri, NPS / memnuniyet skorları ve anket şablonları</div></div>
      </div></div>
      <nav class="rc-sekmeler" id="anSekmeler"></nav>
      <div id="anIcerik"><div class="rc-sekme-yuk"><span class="rc-yukleniyor"></span></div></div>
    </div>`;
  if (!salonId) { el('#anIcerik').innerHTML = '<div class="rc-sekme-bos">Şube seçili değil (?sube=... bekleniyor).</div>'; return; }

  const r = await api('GET', '/anket/form-verisi', { params: { salon_id: salonId } });
  if (!r.ok || !r.govde?.basarili) {
    el('#anIcerik').innerHTML = `<div class="rc-sekme-bos">${esc(r.govde?.mesaj || `Hata (${r.kod})`)}</div>`;
    return;
  }
  const fv = r.govde.veri;
  const ctx = {
    salonId,
    fv,
    yazabilir: !!fv.yetkiler?.yaz,
    oku: (yol, params = {}) => api('GET', '/anket' + yol, { params: { ...params, salon_id: salonId } }),
    yaz: (method, yol, body = {}) => api(method, '/anket' + yol, { body: { ...body, salon_id: salonId } }),
    rozet: (kod, n) => sayfa.rozet(kod, n),
  };
  const sayfa = sekmeliSayfa(el('#anSekmeler'), el('#anIcerik'), { sekmeler: fv.sekmeler, moduller: MODULLER, ctx });
  sayfa.rozet('sablonlar', fv.sablon_sayisi);
})();
