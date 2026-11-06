/** Panel girişi. Giriş kuralları /api/v2/oturum/giris'te; burada yalnız form ve yönlendirme var. */
import { api, el, qp, token } from '/js/lib/v2-ortak.js?v=2';
import { formBagla, gonder, sifreGosterBagla } from '/js/lib/v2-oturum-form.js?v=1';
import { hedefSayfa, oturumuKaydet } from '/js/lib/v2-oturum-depo.js?v=3';

const donus = qp.get('donus');

// Geçerli oturum varsa formu göstermeye gerek yok.
if (token()) {
  const { ok } = await api('GET', '/profil');
  if (ok) location.replace(hedefSayfa(donus));
}

sifreGosterBagla();
formBagla(el('#girisFormu'), el('#girisMesaj'), async (f) => {
  const { veri } = await gonder('/oturum/giris', {
    kimlik: f.get('kimlik'),
    password: f.get('password'),
    hesap_turu: 'isletme',
  });
  oturumuKaydet(veri);
  location.href = hedefSayfa(donus);
});
