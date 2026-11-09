/** Ücretsiz deneme: form → SMS kodu → hesap. Tüm kurallar /api/v2/deneme'de. */
import { api, el, esc } from '/js/lib/v2-ortak.js?v=7';
import { formBagla, gonder, mesajGoster } from '/js/lib/v2-oturum-form.js?v=7';

const YONLENDIRME_MS = 5000;
const kutu = el('#denemeMesaj');
const form = el('#denemeFormu');
const kodFormu = el('#kodFormu');
let telefon = '';

function adimGoster(kodAdimi) {
  form.hidden = kodAdimi;
  kodFormu.hidden = !kodAdimi;
}

async function turleriYukle() {
  const { ok, govde } = await api('GET', '/deneme/secenekler');
  if (!ok || !govde?.basarili) {
    mesajGoster(kutu, 'İşletme türleri yüklenemedi. Lütfen sayfayı yenileyiniz.');
    return;
  }
  el('#salonTuru').insertAdjacentHTML('beforeend', govde.veri.salon_turleri
    .map((t) => `<option value="${t.id}">${esc(t.ad)}</option>`).join(''));
}

formBagla(form, kutu, async (f) => {
  const veri = Object.fromEntries(f.entries());
  if (Object.values(veri).some((d) => !String(d).trim())) throw new Error('Lütfen tüm alanları eksiksiz doldurunuz.');
  const { mesaj } = await gonder('/deneme/kod-gonder', veri);
  telefon = veri.cep_telefon;
  el('#kodAciklama').textContent = mesaj;
  adimGoster(true);
  kodFormu.elements.kod.focus();
});

formBagla(kodFormu, kutu, async (f) => {
  const { veri, mesaj } = await gonder('/deneme/dogrula', { cep_telefon: telefon, kod: f.get('kod') });
  kodFormu.hidden = true;
  mesajGoster(kutu, mesaj, 'ok');
  setTimeout(() => { location.href = veri.giris_yolu; }, YONLENDIRME_MS);
});

el('#formaDon').addEventListener('click', () => {
  adimGoster(false);
  mesajGoster(kutu, '');
});

turleriYukle();
