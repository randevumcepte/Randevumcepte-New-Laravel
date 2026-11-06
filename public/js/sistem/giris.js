/** Sistem yonetimi girisi. Kurallar /api/v2/sistem/oturum/giris'te. */
import { el, qp } from '/js/lib/v2-ortak.js?v=2';
import { formBagla, gonder, sifreGosterBagla } from '/js/lib/v2-oturum-form.js?v=1';
import { syTokenYaz, syToken, syApi } from '/js/lib/v2-sistem.js?v=1';

// Yalniz kendi ekranlarimiza donulur (acik yonlendirme yok).
const hedef = (ilk) => {
  const d = qp.get('donus');
  return d && /^\/sistemyonetim\/(?!\/)/.test(d) ? d : ilk;
};

if (syToken()) {
  const { ok } = await syApi('GET', '/kabuk');
  if (ok) location.replace(hedef('/sistemyonetim/dashboard'));
}

sifreGosterBagla();
formBagla(el('#girisFormu'), el('#girisMesaj'), async (f) => {
  const { veri } = await gonder('/sistem/oturum/giris', { email: f.get('email'), password: f.get('password') });
  syTokenYaz(veri.token);
  location.href = hedef(veri.ilk_sayfa);
});
