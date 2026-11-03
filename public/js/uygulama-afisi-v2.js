/**
 * Uygulama İndirme Afişi (v2) — build'siz island, SALT OKUMA. Eski /isletmeyonetim/uygulama-afisi
 * ekranının v2 karşılığı: afiş önizlemesi + PDF indirme (Android ve iOS linki tanımlıysa), aksi
 * halde uyarı; ayrıca akıllı yönlendirme linki (kopyala / WhatsApp / test et).
 *
 * Görünürlük kararları API bayraklarından (afis_hazir, link_hazir) — burada kural yok.
 * Görseller Bearer gerektirdiği için ortak lib/v2-dosya.js apiBlob ile blob alınır.
 */
import { api, qp, esc, el, mesaj } from '/js/lib/v2-ortak.js?v=1';
import { apiBlob, blobIndir } from '/js/lib/v2-dosya.js?v=2';

const kok = document.getElementById('rc-afis');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;

const ADIMLAR = [
  'Afişi PDF olarak indirin.',
  'A4 boyutunda yazdırın (kenar boşluğu olmadan).',
  'Salonunuzda müşterilerin görebileceği bir yere asın.',
];

const hataMesaji = (r) => (r.govde && r.govde.mesaj) || `Hata (${r.kod})`;

function iskelet() {
  kok.innerHTML = `
    <div class="ua-kabuk">
      <div class="rc-sayfa-ust">
        <div class="rc-sayfa-baslik">
          <span class="rc-sayfa-ikon"><i class="bi bi-qr-code"></i></span>
          <div><h1>Uygulama İndirme Afişi</h1><div class="rc-sayfa-alt">Salonunuza özel QR kodlu afişi indirin, indirme linkini müşterilerinizle paylaşın.</div></div>
        </div>
      </div>
      <div id="uaIcerik"><div class="ua-kart"><span class="rc-yukleniyor"></span> Yükleniyor…</div></div>
    </div>`;
}

function afisKarti(v) {
  const sol = v.afis_hazir
    ? `${ADIMLAR.map((t, i) => `<div class="ua-adim"><span class="ua-no">${i + 1}</span><span>${esc(t)}</span></div>`).join('')}
       <button type="button" class="rc-btn rc-btn-birincil ua-indir" id="uaPdf"><i class="bi bi-download"></i> Afişi PDF İndir</button>`
    : `<div class="ua-uyari">
         <i class="bi bi-exclamation-circle-fill"></i>
         <div><b>Uygulama mağaza bağlantılarınız henüz hazır değil.</b>
           Afişinizin oluşturulabilmesi için hem <strong>App Store</strong> hem de <strong>Google Play</strong> uygulama linklerinizin
           tanımlı olması gerekir${v.eksik_magazalar.length ? ` (eksik: <strong>${v.eksik_magazalar.map(esc).join(', ')}</strong>)` : ''}.
           Uygulamanız yayına alınıp linkler tanımlandığında afişiniz buradan indirilebilir olacaktır.</div>
       </div>`;

  return `
    <div class="ua-kart ua-afis">
      <div class="ua-bilgi">
        <h3>Salonunuza özel uygulama indirme afişi</h3>
        <p>Müşterileriniz afişteki QR kodu telefonlarıyla okuttuğunda, cihazlarına uygun mağazadan (App Store / Google Play)
           uygulamayı indirebilir. Afişi yazdırıp salonunuzda görünür bir yere asabilirsiniz.</p>
        ${sol}
      </div>
      ${v.afis_hazir ? '<div class="ua-onizleme" id="uaOnizleme"><span class="rc-yukleniyor"></span></div>' : ''}
    </div>`;
}

function linkKarti(v) {
  if (!v.link_hazir) return '';
  return `
    <div class="ua-kart">
      <h3>Uygulama indirme linki <span class="rc-rozet rc-rozet-yesil">Akıllı yönlendirme</span></h3>
      <p>Bu tek linki mesaj, e-posta veya sosyal medya üzerinden müşterilerinize gönderebilirsiniz. Link açıldığında telefon
         <strong>Android</strong> ise Google Play, <strong>iPhone</strong> ise App Store sayfasına otomatik yönlendirilir.
         Afişteki QR kod da aynı adrese gider.</p>
      <div class="ua-link">
        <input type="text" id="uaLink" value="${esc(v.indir_linki)}" readonly>
        <button type="button" class="rc-btn rc-btn-ikincil" id="uaKopyala"><i class="bi bi-copy"></i> <span>Kopyala</span></button>
        <a class="rc-btn rc-btn-ikincil" href="${esc(v.whatsapp_url)}" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> WhatsApp ile paylaş</a>
        <a class="rc-btn rc-btn-ikincil" href="${esc(v.indir_linki)}" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Test et</a>
      </div>
    </div>`;
}

async function onizlemeYukle(v) {
  const kap = el('#uaOnizleme');
  const r = await apiBlob(v.uclar.onizleme);
  if (!r.ok) { kap.innerHTML = `<div class="ua-hata">${esc(hataMesaji(r))}</div>`; return; }
  const img = Object.assign(new Image(), { alt: 'Afiş önizleme', src: URL.createObjectURL(r.blob) });
  kap.replaceChildren(img);
}

async function pdfIndir(v, btn) {
  btn.disabled = true;
  const r = await apiBlob(v.uclar.pdf);
  btn.disabled = false;
  if (!r.ok) { mesaj(hataMesaji(r), 'hata'); return; }
  blobIndir(r.blob, v.dosya_adi);
}

async function kopyala(btn) {
  const girdi = el('#uaLink');
  const etiket = btn.querySelector('span');
  try {
    await navigator.clipboard.writeText(girdi.value);
  } catch (_) {
    girdi.select();
    document.execCommand('copy');              // clipboard API'siz (http) tarayıcılar
  }
  etiket.textContent = 'Kopyalandı!';
  setTimeout(() => { etiket.textContent = 'Kopyala'; }, 2000);
}

async function yukle() {
  iskelet();
  if (window.rcPanel?.salonAyarla && salonId) window.rcPanel.salonAyarla(salonId);

  const r = await api('GET', '/uygulama-afisi', { params: { salon_id: salonId } });
  const icerik = el('#uaIcerik');
  if (!r.ok || !r.govde?.basarili) { icerik.innerHTML = `<div class="ua-kart ua-hata">${esc(hataMesaji(r))}</div>`; return; }

  const v = r.govde.veri;
  icerik.innerHTML = afisKarti(v) + linkKarti(v);

  if (v.afis_hazir) {
    onizlemeYukle(v);
    el('#uaPdf').addEventListener('click', (e) => pdfIndir(v, e.currentTarget));
  }
  if (v.link_hazir) {
    el('#uaLink').addEventListener('click', (e) => e.currentTarget.select());
    el('#uaKopyala').addEventListener('click', (e) => kopyala(e.currentTarget));
  }
}

yukle();
