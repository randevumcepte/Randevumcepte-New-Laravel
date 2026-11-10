/**
 * Panel girişi: web şifreyle girer (kodla giriş yalnız mobil uygulamada). Giriş kuralları /api/v2/oturum/*'ta;
 * burada yalnız form ve yönlendirme var.
 */
import { api, el, qp, token } from '/js/lib/v2-ortak.js?v=7';
import { formBagla, gonder, sifreGosterBagla } from '/js/lib/v2-oturum-form.js?v=7';
import { hedefSayfa, oturumuKaydet } from '/js/lib/v2-oturum-depo.js?v=3';

const HESAP_TURU = 'isletme';
const donus = qp.get('donus');
const kutu = el('#girisMesaj');

// Geçerli oturum varsa formu göstermeye gerek yok.
if (token()) {
  const { ok } = await api('GET', '/profil');
  if (ok) location.replace(hedefSayfa(donus));
}

sifreGosterBagla();

formBagla(el('#girisFormu'), kutu, async (f) => {
  const { veri } = await gonder('/oturum/giris', {
    kimlik: f.get('kimlik'),
    password: f.get('password'),
    hesap_turu: HESAP_TURU,
  });
  oturumuKaydet(veri);
  location.href = hedefSayfa(donus);
});
