/**
 * Ayarlar (v2) — build'siz island (sade ES module), eski isletmeadmin/ayarlar sayfasının karşılığı.
 *
 * Sekme listesi ve görünürlüğü /api/v2/ayar/ozet'ten gelir (yetki eşlemesi SUNUCUDA; web ve
 * Flutter aynı listeyi görür). v2'ye taşınmış sekmeler kendi modülünü (public/js/ayarlar/*)
 * tembel yükler; henüz taşınmamış olan (Entegrasyonlar — Google Takvim)
 * eski paneldeki aynı sekmeye link verir. Seçili sekme ?p= ile adres çubuğunda tutulur.
 */
import { qp, api, el, esc } from '/js/lib/v2-ortak.js?v=2';
import { YUKLENIYOR } from '/js/ayarlar/ortak.js?v=4';

const kok = document.getElementById('rc-ayarlar');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
if (salonId && window.rcPanel) window.rcPanel.salonAyarla(salonId);

/** Sekme kodu → modül (yalnız görsel/çizim; hangi sekmenin açık olduğu API'den). */
const MODULLER = {
  isletme: () => import('/js/ayarlar/isletme.js?v=4'),
  subeler: () => import('/js/ayarlar/subeler.js?v=4'),
  calisma_saatleri: () => import('/js/ayarlar/saatler.js?v=4'),
  randevu: () => import('/js/ayarlar/randevu.js?v=4'),
  online_saatler: () => import('/js/ayarlar/online-saatler.js?v=4'),
  cihazlar: () => import('/js/ayarlar/cihazlar.js?v=4'),
  hizmetler: () => import('/js/ayarlar/hizmetler.js?v=5'),
  odalar: () => import('/js/ayarlar/odalar.js?v=4'),
  musteri_indirimleri: () => import('/js/ayarlar/indirim.js?v=4'),
};

let ozet = null;

async function baslat() {
  kok.innerHTML = `
    <div class="ay-kabuk">
      <div class="rc-sayfa-ust"><div class="rc-sayfa-baslik">
        <span class="rc-sayfa-ikon"><i class="bi bi-gear"></i></span>
        <div><h1>Ayarlar</h1><div class="rc-sayfa-alt" id="ayAlt">İşletme, çalışma saatleri ve randevu ayarları</div></div>
      </div></div>
      <nav class="rc-sekmeler" id="aySekmeler" role="tablist"></nav>
      <div id="ayIcerik">${YUKLENIYOR}</div>
    </div>`;
  if (!salonId) { el('#ayIcerik').innerHTML = '<div class="ay-bos">Şube seçili değil (?sube=... bekleniyor).</div>'; return; }

  const r = await api('GET', '/ayar/ozet', { params: { salon_id: salonId } });
  if (!r.ok || !r.govde?.basarili) {
    el('#ayIcerik').innerHTML = `<div class="ay-bos">${esc(r.govde?.mesaj || `Hata (${r.kod})`)}</div>`;
    return;
  }
  ozet = r.govde.veri;
  if (ozet.salon_adi) el('#ayAlt').textContent = ozet.salon_adi;
  const istenen = ozet.sekmeler.find((s) => s.kod === qp.get('p'));
  sekmeAc((istenen || ozet.sekmeler.find((s) => s.v2) || ozet.sekmeler[0]).kod);

  el('#aySekmeler').addEventListener('click', (e) => {
    const b = e.target.closest('[data-kod]');
    if (b) sekmeAc(b.dataset.kod);
  });
}

async function sekmeAc(kod) {
  const sekme = ozet.sekmeler.find((s) => s.kod === kod);
  el('#aySekmeler').innerHTML = ozet.sekmeler.map((s) => `
    <button class="rc-sekme${s.kod === kod ? ' aktif' : ''}" data-kod="${esc(s.kod)}" role="tab">
      <i class="bi ${esc(s.ikon)}"></i> ${esc(s.ad)}${s.v2 ? '' : ' <i class="bi bi-box-arrow-up-right ay-eski-ikon" title="Eski panelde açılır"></i>'}
    </button>`).join('');
  const url = new URL(location.href);
  url.searchParams.set('p', kod);
  history.replaceState(null, '', url);

  const kap = el('#ayIcerik');
  if (!sekme.v2 || !MODULLER[kod]) {
    kap.innerHTML = `<div class="ay-bos ay-eski">
      <i class="bi bi-tools"></i><h3>${esc(sekme.ad)}</h3>
      <p>Bu bölüm henüz yeni panele taşınmadı; eski panelde yönetilmeye devam ediyor.</p>
      <a class="rc-btn rc-btn-birincil" href="${esc(sekme.eski_url)}"><i class="bi bi-box-arrow-up-right"></i> Eski panelde aç</a></div>`;
    return;
  }
  // Her sekme kendi kabına çizer: hızlı sekme değişiminde geç gelen cevap ayrılmış kaba düşer.
  const alt = document.createElement('div');
  kap.replaceChildren(alt);
  const modul = await MODULLER[kod]();
  if (alt.isConnected) await modul.ciz(alt, { salonId });
}

baslat();
