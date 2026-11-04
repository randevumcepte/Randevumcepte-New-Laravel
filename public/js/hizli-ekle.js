/**
 * Header "+" menüsü — form açan öğelerin eylemleri. Liste + görünürlük + etiket sunucudan
 * (/api/v2/kabuk → hizli_ekle; kabuk çizer); burada yalnız `kod` → form eşlemesi. Formlar ekranların
 * kullandığı ORTAK modüllerdir (kopya form yok) ve ilk tıklamada stilleriyle birlikte yüklenir.
 */
const cssYukle = (href) => new Promise((coz) => {
  if (document.querySelector(`link[href^="${href.split('?')[0]}"]`)) { coz(); return; }
  const l = document.createElement('link');
  l.rel = 'stylesheet';
  l.href = href;
  l.onload = l.onerror = () => coz();
  document.head.appendChild(l);
});

/** Kayıt sonrası: açık ekran bu veriyi listeliyorsa yenilenir. */
const ekraniTazele = (...sluglar) => () => { if (sluglar.includes(document.body.dataset.aktif)) location.reload(); };

const randevuIslem = {};   // salon başına tek çekmece

const EYLEMLER = {
  randevu: {
    css: '/css/takvim-v2.css?v=13',
    async ac({ salon }) {
      const { randevuIslemleri } = await import('/js/lib/v2-randevu.js?v=3');
      randevuIslem[salon.id] ||= randevuIslemleri({ salonId: salon.id });
      randevuIslem[salon.id].randevuFormu({ kaydedildi: ekraniTazele('takvim-v2', 'randevular-liste') });
    },
  },
  ongorusme: {
    css: '/css/ongorusmeler-v2.css?v=1',
    async ac({ salon }) {
      const { ongorusmeFormu } = await import('/js/lib/v2-ongorusme.js?v=1');
      ongorusmeFormu({ salonId: salon.id, onKaydet: ekraniTazele('ongorusmeler') });
    },
  },
  musteri: {
    css: '/css/musteri-v2.css?v=4',
    async ac({ salon }) {
      const { musteriFormuAc } = await import('/js/lib/v2-musteri.js?v=1');
      musteriFormuAc({
        salonId: salon.id,
        etiket: salon.musteri_etiket,
        onKaydet: (r) => { location.href = `/v2/musteri/${r.id}?sube=${salon.id}`; },
      });
    },
  },
  masraf: {
    css: '/css/rapor-v2.css?v=1',
    async ac({ salon }) {
      const { masrafModal } = await import('/js/lib/v2-kasa-form.js?v=1');
      masrafModal({ salonId: salon.id, onKaydet: ekraniTazele('kasadefteri') });
    },
  },
  form: {
    css: '/css/formlar-v2.css?v=2',
    async ac({ salon }) {
      const { formGonderModali } = await import('/js/lib/v2-form-gonder.js?v=1');
      formGonderModali({ salonId: salon.id, onGonderildi: ekraniTazele('arsivyonetimi') });
    },
  },
};

document.addEventListener('click', async (e) => {
  const oge = e.target.closest('#rcHizliEkle button[data-kod]');
  if (!oge) return;
  const eylem = EYLEMLER[oge.dataset.kod];
  const veri = window.rcPanel?.veri;
  if (!eylem || !veri) return;
  try {
    await cssYukle(eylem.css);
    await eylem.ac(veri);
  } catch (hata) {
    window.rcPanel.toast('Form açılamadı. Sayfayı yenileyip tekrar deneyin.', 'hata');
  }
});
