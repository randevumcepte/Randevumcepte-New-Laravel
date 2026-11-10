/**
 * Çağrı Merkezi (v2) — build'siz island (sade ES module). Eski 4 ayrı sayfa (Atanan Aramalar /
 * Çalışma Ekranı, Arama Randevu Takvimi, Çağrı Kayıtları, Çağrı Merkezi paneli + Liste Yönetimi)
 * TEK sekmeli sayfada.
 *
 * Sekme listesi + görünürlük, sözlükler, scriptler, kategoriler, personeller TEK istekte
 * /api/v2/cagri-merkezi/acilis'ten (Flutter da aynı ucu kullanır). Her sekme kendi modülünü
 * tembel yükler (public/js/cagri-merkezi/<kod>.js → export async function ciz(kap, ctx, params)).
 * YENİ SEKME: sunucuda CagriMerkeziSekmeleri'ne tek satır + aşağıdaki MODULLER'e tek satır.
 *
 * Arama randevusu hatırlatması: dakikada bir /yaklasan → "Arama Randevularım" sekme sayacı +
 * sayfa üstü uyarı ("Müşteriye git" → Çalışma Ekranında o müşteri açılır).
 */
import { qp, api, el, esc } from '/js/lib/v2-ortak.js?v=7';
import { sekmeliSayfa } from '/js/lib/v2-sekme.js?v=7';

const kok = document.getElementById('rc-cagri');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
if (salonId && window.rcPanel) window.rcPanel.salonAyarla(salonId);

const MODULLER = {
  calisma: () => import('/js/cagri-merkezi/calisma.js?v=10'),
  ajanda: () => import('/js/cagri-merkezi/ajanda.js?v=10'),
  takvim: () => import('/js/cagri-merkezi/takvim.js?v=10'),
  kayitlar: () => import('/js/cagri-merkezi/kayitlar.js?v=10'),
  performans: () => import('/js/cagri-merkezi/performans.js?v=10'),
  listeler: () => import('/js/cagri-merkezi/listeler.js?v=3'),
  ayarlar: () => import('/js/cagri-merkezi/ayarlar.js?v=3'),
};
const HATIRLATMA_SN = 60;

(async function baslat() {
  kok.innerHTML = `
    <div class="cm-kabuk">
      <div class="rc-sayfa-ust"><div class="rc-sayfa-baslik">
        <span class="rc-sayfa-ikon"><i class="bi bi-headset"></i></span>
        <div><h1>Çağrı Merkezi</h1><div class="rc-sayfa-alt" id="cmAlt">Aramalar, arama randevuları, çağrı kayıtları ve performans</div></div>
      </div></div>
      <div class="cm-hatirlatma" id="cmHatirlatma" hidden></div>
      <nav class="rc-sekmeler" id="cmSekmeler"></nav>
      <div id="cmIcerik"><div class="cm-bos"><span class="rc-yukleniyor"></span></div></div>
    </div>`;
  if (!salonId) { el('#cmIcerik').innerHTML = '<div class="cm-bos">Şube seçili değil (?sube=... bekleniyor).</div>'; return; }

  const r = await api('GET', '/cagri-merkezi/acilis', { params: { salon_id: salonId } });
  if (!r.ok || !r.govde?.basarili) {
    el('#cmIcerik').innerHTML = `<div class="cm-bos cm-hata"><i class="bi bi-shield-lock"></i>${esc(r.govde?.mesaj || `Hata (${r.kod})`)}</div>`;
    return;
  }
  const acilis = r.govde.veri;
  const ctx = {
    salonId,
    acilis,
    oku: (yol, params = {}) => api('GET', '/cagri-merkezi' + yol, { params: { ...params, salon_id: salonId } }),
    yaz: (method, yol, body = {}) => api(method, '/cagri-merkezi' + yol, { body: { ...body, salon_id: salonId } }),
  };
  const sayfa = sekmeliSayfa(el('#cmSekmeler'), el('#cmIcerik'), { sekmeler: acilis.sekmeler, moduller: MODULLER, ctx });
  ctx.sekmeAc = sayfa.ac;
  hatirlatmaBaslat(ctx, sayfa);
})();

/** Zamanı gelen / yaklaşan arama randevuları (aktif listeler). Kapatılan uyarı tekrar çıkmaz. */
function hatirlatmaBaslat(ctx, sayfa) {
  const kapatilan = new Set();
  const kutu = el('#cmHatirlatma');
  const FAZ = { on5: ['Yaklaşıyor', 'mavi'], zaman: ['Zamanı geldi', 'turuncu'], gecikti: ['Gecikti', 'kirmizi'] };
  let son = [];

  const ciz = () => {
    const gorunen = son.filter((x) => !kapatilan.has(x.id));
    sayfa.rozet('ajanda', son.length);
    kutu.hidden = !gorunen.length;
    kutu.innerHTML = gorunen.length ? `<i class="bi bi-bell-fill"></i><div class="cm-hat-liste">${gorunen.slice(0, 3).map((x) => `
      <div class="cm-hat"><span class="rc-rozet rc-rozet-${FAZ[x.faz][1]}">${FAZ[x.faz][0]}</span>
        <b>${esc(x.ad)}</b> <span class="cm-soluk">${esc(x.tarih)} ${esc(x.saat)}</span>
        <button type="button" class="rc-btn rc-btn-birincil cm-kucuk-btn" data-git="${x.id}" data-liste="${x.arama_id}">Müşteriye git</button>
        <button type="button" class="cm-x" data-kapat="${x.id}" title="Kapat">×</button></div>`).join('')}
      ${gorunen.length > 3 ? `<span class="cm-soluk cm-kucuk">+${gorunen.length - 3} arama daha</span>` : ''}</div>` : '';
  };
  kutu.addEventListener('click', (e) => {
    const git = e.target.closest('[data-git]');
    if (git) { kapatilan.add(Number(git.dataset.git)); ciz(); sayfa.ac('calisma', { aranacak_id: git.dataset.git, liste_id: git.dataset.liste }); return; }
    const x = e.target.closest('[data-kapat]');
    if (x) { kapatilan.add(Number(x.dataset.kapat)); ciz(); }
  });
  const yokla = async () => {
    const r = await ctx.oku('/yaklasan');
    if (r.ok && r.govde?.basarili) { son = r.govde.veri.randevular; ciz(); }
  };
  yokla();
  setInterval(() => { if (!document.hidden) yokla(); }, HATIRLATMA_SN * 1000);
}
