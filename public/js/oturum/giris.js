/**
 * Panel girişi: varsayılan tek kullanımlık kod, yedek yol şifre. Giriş kuralları /api/v2/oturum/*'ta;
 * burada yalnız form ve yönlendirme var. Mobil uygulama aynı uçları kullanır.
 */
import { api, el, qp, token } from '/js/lib/v2-ortak.js?v=2';
import { formBagla, gonder, mesajGoster, sifreGosterBagla } from '/js/lib/v2-oturum-form.js?v=1';
import { hedefSayfa, oturumuKaydet } from '/js/lib/v2-oturum-depo.js?v=3';

const HESAP_TURU = 'isletme';
const donus = qp.get('donus');
const kutu = el('#girisMesaj');
const kodIsteFormu = el('#kodIsteFormu');
const kodGirisFormu = el('#kodGirisFormu');
const sifreFormu = el('#girisFormu');
const yenidenDugme = el('#kodYenidenGonder');
const yontemDugme = el('#yontemDegistir');
let telefon = '';
let sayac = null;

// Geçerli oturum varsa formu göstermeye gerek yok.
if (token()) {
  const { ok } = await api('GET', '/profil');
  if (ok) location.replace(hedefSayfa(donus));
}

function oturumAc(veri) {
  oturumuKaydet(veri);
  location.href = hedefSayfa(donus);
}

function sayaciBaslat(saniye) {
  clearInterval(sayac);
  let kalan = saniye;
  const yaz = () => {
    yenidenDugme.disabled = kalan > 0;
    yenidenDugme.textContent = kalan > 0 ? `Yeniden gönder (${kalan} sn)` : 'Kodu SMS ile yeniden gönder';
  };
  yaz();
  sayac = setInterval(() => {
    kalan -= 1;
    yaz();
    if (kalan <= 0) clearInterval(sayac);
  }, 1000);
}

async function kodGonder() {
  const { veri, mesaj } = await gonder('/oturum/kod-iste', { cep_telefon: telefon, hesap_turu: HESAP_TURU });
  kodIsteFormu.hidden = true;
  kodGirisFormu.hidden = false;
  mesajGoster(kutu, mesaj, 'ok');
  sayaciBaslat(veri.yeniden_sn);
  kodGirisFormu.elements.kod.focus();
}

function kodAdiminaDon() {
  clearInterval(sayac);
  kodGirisFormu.reset();
  kodGirisFormu.hidden = true;
  kodIsteFormu.hidden = false;
  mesajGoster(kutu, '');
}

sifreGosterBagla();

formBagla(kodIsteFormu, kutu, async (f) => {
  telefon = f.get('cep_telefon');
  await kodGonder();
});

formBagla(kodGirisFormu, kutu, async (f) => {
  const { veri } = await gonder('/oturum/kod-dogrula', { cep_telefon: telefon, kod: f.get('kod'), hesap_turu: HESAP_TURU });
  oturumAc(veri);
});

formBagla(sifreFormu, kutu, async (f) => {
  const { veri } = await gonder('/oturum/giris', {
    kimlik: f.get('kimlik'),
    password: f.get('password'),
    hesap_turu: HESAP_TURU,
  });
  oturumAc(veri);
});

yenidenDugme.addEventListener('click', async () => {
  yenidenDugme.disabled = true;
  try {
    await kodGonder();
  } catch (hata) {
    yenidenDugme.disabled = false;
    mesajGoster(kutu, hata.message);
  }
});

el('#numaraDegistir').addEventListener('click', kodAdiminaDon);

yontemDugme.addEventListener('click', () => {
  const sifreyeGec = sifreFormu.hidden;
  kodAdiminaDon();
  sifreFormu.hidden = !sifreyeGec;
  kodIsteFormu.hidden = sifreyeGec;
  yontemDugme.textContent = sifreyeGec ? 'Kod ile giriş' : 'Şifre ile giriş';
  (sifreyeGec ? sifreFormu.elements.kimlik : kodIsteFormu.elements.cep_telefon).focus();
});
