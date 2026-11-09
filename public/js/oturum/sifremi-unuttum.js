/** Şifremi unuttum: önce SMS kodu istenir, sonra kod + yeni şifre gönderilir. */
import { el } from '/js/lib/v2-ortak.js?v=7';
import { formBagla, gonder, mesajGoster, sifreGosterBagla } from '/js/lib/v2-oturum-form.js?v=7';

const HESAP_TURU = 'isletme';
const YONLENDIRME_MS = 3000;
const kutu = el('#sifreMesaj');
const kodFormu = el('#kodFormu');
const sifreFormu = el('#sifreFormu');
let telefon = '';

sifreGosterBagla();

formBagla(kodFormu, kutu, async (f) => {
  telefon = f.get('cep_telefon');
  const { mesaj } = await gonder('/oturum/sifre-kodu', { cep_telefon: telefon, hesap_turu: HESAP_TURU });
  kodFormu.hidden = true;
  sifreFormu.hidden = false;
  mesajGoster(kutu, mesaj, 'ok');
  sifreFormu.elements.kod.focus();
});

formBagla(sifreFormu, kutu, async (f) => {
  if (f.get('yeni_sifre') !== f.get('yeni_sifre_tekrar')) throw new Error('Girdiğiniz şifreler uyuşmamaktadır.');
  const { mesaj } = await gonder('/oturum/sifre-sifirla', {
    cep_telefon: telefon, kod: f.get('kod'), yeni_sifre: f.get('yeni_sifre'), hesap_turu: HESAP_TURU,
  });
  mesajGoster(kutu, mesaj, 'ok');
  setTimeout(() => { location.href = '/v2/giris'; }, YONLENDIRME_MS);
});

el('#yeniKodIste').addEventListener('click', () => {
  sifreFormu.hidden = true;
  kodFormu.hidden = false;
  mesajGoster(kutu, '');
});
