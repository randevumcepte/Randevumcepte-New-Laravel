/**
 * Header WebRTC softphone — buton (kalan dakika rozeti) + açılır tuş takımı paneli.
 * Eski layout_isletmeadmin #webtelefon + webphone.js arayüzünün v2 karşılığı.
 *
 * Sorumluluk ayrımı: SIP bağlantısı/çağrı → SipHat, zil sesleri → Zil, burada yalnızca
 * arayüz durumu (tek ciz() fonksiyonu) + API (arayan adı, dakika).
 *
 * Dışa açık: window.rcSoftphone.ara(numara, {etiket?}) / .bagliMi() — diğer v2 ekranları (ARA butonları)
 * için. Eski ekranlardan taşınan kod için webphoneAra / webphoneBagliMi takma adları.
 * Her çağrı aşaması document'e 'rc:softphone' olayı ({asama, numara, cagriId, yon}) olarak
 * yayılır; çağrı sonrası iş (ses kaydı bağlama vb.) ekranlar tarafından dinlenir.
 */
import { api, esc } from '/js/lib/v2-ortak.js?v=1';
import { SipHat } from '/js/softphone/sip-hat.js?v=1';
import { Zil } from '/js/softphone/zil.js?v=1';

const TUSLAR = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'];
const BAGLANTI_METNI = {
  baglaniyor: 'Bağlanıyor…',
  bagli: 'Hazır',
  kopuk: 'Bağlantı kesildi',
};

/**
 * @param {HTMLElement} kap   header'daki yer tutucu
 * @param {number} salonId
 * @param {object} ayar      GET /softphone cevabı
 * @returns {{yokEt: () => void}}
 */
export function softphoneKur(kap, salonId, ayar) {
  const uzakSes = new Audio();
  uzakSes.autoplay = true;
  const hat = new SipHat(ayar, uzakSes);
  const zil = new Zil(ayar.sesler);
  const adlar = new Map(); // numara → müşteri adı (çağrı başına tekrar sorulmasın)
  const durum = { baglanti: 'baglaniyor', deneme: 0, asama: null, numara: '', ad: '', beklemede: false };

  kap.innerHTML = sablon(ayar);
  const $ = (s) => kap.querySelector(s);
  const panel = $('.rc-sp-panel');
  const girdi = $('.rc-sp-numara');

  /* ───────── arayüz durumu: TEK yerden ───────── */
  function ciz() {
    const bagli = durum.baglanti === 'bagli';
    const asama = durum.asama;
    const kisi = esc(durum.ad || durum.numara);
    const metin = {
      cikiyor: `Aranıyor: ${kisi}`,
      geliyor: `Gelen arama: ${kisi}`,
      baglandi: `Görüşmede: ${kisi}${durum.beklemede ? ' (Beklemede)' : ''}`,
    }[asama] || (durum.baglanti === 'yeniden'
      ? `Yeniden bağlanılıyor… (${durum.deneme})`
      : `${BAGLANTI_METNI[durum.baglanti]} · Dahili ${esc(ayar.dahili)}`);

    $('.rc-sp-durum').innerHTML = metin;
    kap.dataset.durum = asama || durum.baglanti;
    $('[data-eylem="ara"]').hidden = !ayar.arama_yapabilir || asama === 'geliyor';
    $('[data-eylem="ara"]').disabled = !bagli || !!asama;
    $('[data-eylem="cevapla"]').hidden = asama !== 'geliyor';
    $('[data-eylem="kapat"]').disabled = !asama;
    $('[data-eylem="beklet"]').disabled = asama !== 'baglandi';
    $('[data-eylem="beklet"]').classList.toggle('aktif', durum.beklemede);
  }

  function paneliAc() { panel.hidden = false; $('.rc-sp-tetik').setAttribute('aria-expanded', 'true'); }
  function paneliKapat() { panel.hidden = true; $('.rc-sp-tetik').setAttribute('aria-expanded', 'false'); }

  async function adCoz(numara) {
    if (!numara || adlar.has(numara)) return adlar.get(numara) || '';
    const r = await api('GET', '/softphone/arayan', { params: { salon_id: salonId, numara } });
    const ad = (r.ok && r.govde?.veri?.ad) || '';
    adlar.set(numara, ad);
    return ad;
  }

  /* ───────── SIP olayları ───────── */
  hat.addEventListener('durum', (e) => {
    durum.baglanti = e.detail.durum;
    durum.deneme = e.detail.deneme || 0;
    ciz();
  });
  hat.addEventListener('beklet', (e) => { durum.beklemede = e.detail.beklemede; ciz(); });
  hat.addEventListener('hata', (e) => window.rcPanel?.toast(e.detail.mesaj, 'hata'));
  hat.addEventListener('cagri', async (e) => {
    const { asama, numara } = e.detail;
    durum.asama = asama === 'bitti' ? null : asama;
    if (asama === 'cikiyor' || asama === 'geliyor') {
      durum.numara = numara;
      durum.ad = adlar.get(numara) || '';   // ara(…, {etiket}) ile verilen ad numaradan ÖNCE görünür
      paneliAc();
    }
    if (asama === 'cikiyor') zil.gidenBaslat();
    else if (asama === 'geliyor') zil.gelenBaslat();
    else zil.durdur();
    if (asama === 'bitti') { durum.beklemede = false; girdi.value = ''; }
    ciz();
    document.dispatchEvent(new CustomEvent('rc:softphone', { detail: e.detail }));

    if (asama === 'cikiyor' || asama === 'geliyor') {
      const ad = await adCoz(numara);
      if (ad && durum.numara === numara && durum.asama) { durum.ad = ad; ciz(); }
    }
  });

  /* ───────── kullanıcı etkileşimi ───────── */
  const aramaYap = () => hat.ara(girdi.value);
  const eylemler = {
    ara: aramaYap,
    cevapla: () => hat.cevapla(),
    kapat: () => hat.kapat(),
    beklet: () => hat.bekletDegistir(),
  };
  kap.addEventListener('click', (e) => {
    if (e.target.closest('.rc-sp-tetik')) { panel.hidden ? paneliAc() : paneliKapat(); return; }
    const tus = e.target.closest('[data-tus]');
    if (tus) {
      // Görüşmede tuş tonu gönderir (sesli menü); değilse numaraya ekler.
      if (durum.asama === 'baglandi') hat.dtmf(tus.dataset.tus);
      else if (ayar.arama_yapabilir) girdi.value += tus.dataset.tus;
      return;
    }
    const eylem = e.target.closest('[data-eylem]');
    if (eylem && !eylem.disabled) eylemler[eylem.dataset.eylem]();
  });
  girdi.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); aramaYap(); } });
  const disTiklama = (e) => { if (!panel.hidden && !kap.contains(e.target)) paneliKapat(); };
  document.addEventListener('mousedown', disTiklama);
  const sayfaKapanis = () => hat.durdur();
  window.addEventListener('pagehide', sayfaKapanis);

  /* ───────── dışa açık API ───────── */
  const disApi = {
    bagliMi: () => hat.bagliMi(),
    /**
     * opt.etiket: numara yerine gösterilecek ad (ör. KVKK maskeli "Ferdi K. · 0532 *** ** 67") —
     * numara giriş kutusuna YAZILMAZ, panelde yalnız etiket görünür (Çağrı Merkezi kayıtlı müşteri araması).
     */
    ara(numara, opt = {}) {
      let tel = String(numara || '').replace(/\D/g, '');
      if (!tel || !hat.bagliMi()) return false; // çağıran taraf santral originate'e düşebilir
      if (/^5\d{9}$/.test(tel)) tel = '0' + tel; // TR cep: 05XXXXXXXXX
      if (opt.etiket) adlar.set(tel, String(opt.etiket));
      girdi.value = opt.etiket ? '' : tel;
      paneliAc();
      return hat.ara(tel);
    },
  };
  window.rcSoftphone = disApi;
  window.webphoneAra = disApi.ara;
  window.webphoneBagliMi = disApi.bagliMi;

  dakikaGoster($('.rc-sp-dk'), salonId);
  ciz();
  // Sayfa etkileşime hazır olduktan sonra bağlan (santral erişilemezse açılışı bloklamasın).
  const zamanlayici = setTimeout(() => hat.baslat(), 1500);

  return {
    yokEt() {
      clearTimeout(zamanlayici);
      zil.durdur();
      hat.durdur();
      document.removeEventListener('mousedown', disTiklama);
      window.removeEventListener('pagehide', sayfaKapanis);
      if (window.rcSoftphone === disApi) delete window.rcSoftphone;
      kap.innerHTML = '';
    },
  };
}

async function dakikaGoster(hedef, salonId) {
  const r = await api('GET', '/softphone/dakika', { params: { salon_id: salonId } });
  const kalan = r.ok ? r.govde?.veri?.kalan : null;
  if (kalan === null || kalan === undefined) return;
  hedef.textContent = Math.round(kalan).toLocaleString('tr-TR') + ' dk';
  hedef.hidden = false;
}

function sablon(ayar) {
  const tuslar = TUSLAR.map((t) => `<button type="button" class="rc-sp-tus" data-tus="${t}">${t}</button>`).join('');
  const eylem = (ad, ikon, etiket, sinif) =>
    `<button type="button" class="rc-sp-eylem ${sinif}" data-eylem="${ad}"><i class="bi ${ikon}"></i><span>${etiket}</span></button>`;

  return `
    <button type="button" class="rc-rozet rc-rozet-sp rc-sp-tetik" title="Web telefonu" aria-haspopup="true" aria-expanded="false">
      <span class="rc-sp-nokta"></span><i class="bi bi-telephone-fill"></i>
      <span class="rc-sp-dk" title="Kalan konuşma dakikası" hidden></span>
    </button>
    <div class="rc-sp-panel" role="dialog" aria-label="Web telefonu" hidden>
      <div class="rc-sp-ekran"><i class="bi bi-headset"></i><span class="rc-sp-durum"></span></div>
      <input type="tel" data-tel="yok" class="rc-sp-numara" autocomplete="off"
        placeholder="Dahili / numara" ${ayar.arama_yapabilir ? '' : 'hidden'}>
      <div class="rc-sp-tuslar">${tuslar}</div>
      <div class="rc-sp-eylemler">
        ${eylem('ara', 'bi-telephone-fill', 'Ara', 'yesil')}
        ${eylem('cevapla', 'bi-telephone-inbound-fill', 'Cevapla', 'yesil')}
        ${eylem('kapat', 'bi-telephone-x-fill', 'Kapat', 'kirmizi')}
        ${eylem('beklet', 'bi-pause-fill', 'Beklet', 'turuncu')}
      </div>
    </div>`;
}
