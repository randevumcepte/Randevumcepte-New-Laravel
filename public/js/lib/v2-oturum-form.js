/** Giriş, şifre ve deneme formlarının ortak davranışı. */
import { api } from '/js/lib/v2-ortak.js?v=1';
import { ilkAlanHatasi } from '/js/lib/v2-hata.js?v=1';

/** Kimliksiz POST: başarıda {veri, mesaj} döner, hatada kullanıcıya gösterilecek mesajla Error fırlatır. */
export async function gonder(yol, body) {
  let cevap;
  try {
    cevap = await api('POST', yol, { body });
  } catch (_) {
    throw new Error('Bağlantı hatası, lütfen tekrar deneyin.');
  }
  const { ok, kod, govde } = cevap;
  if (!ok || !govde?.basarili) {
    if (kod === 429) throw new Error(govde?.mesaj || 'Çok fazla deneme yapıldı. Lütfen daha sonra tekrar deneyiniz.');
    throw new Error(ilkAlanHatasi(govde) || govde?.mesaj || 'Bir hata oluştu, lütfen tekrar deneyin.');
  }
  return { veri: govde.veri, mesaj: govde.mesaj };
}

export function mesajGoster(kutu, metin, tip = 'hata') {
  kutu.textContent = metin || '';
  kutu.dataset.tip = tip;
  kutu.hidden = !metin;
}

/** Submit: varsayılanı engeller, işlem süresince düğmeyi kilitler, hatayı mesaj kutusuna yazar. */
export function formBagla(form, kutu, isleyici) {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const dugme = form.querySelector('[type="submit"]');
    mesajGoster(kutu, '');
    dugme.disabled = true;
    try {
      await isleyici(new FormData(form));
    } catch (hata) {
      mesajGoster(kutu, hata.message);
    } finally {
      dugme.disabled = false;
    }
  });
}

/** [data-sifre-goster] ikonları yandaki şifre alanını açıp kapatır. */
export function sifreGosterBagla() {
  document.querySelectorAll('[data-sifre-goster]').forEach((ikon) => {
    ikon.addEventListener('click', () => {
      const alan = ikon.parentElement.querySelector('input');
      const gizli = alan.type === 'password';
      alan.type = gizli ? 'text' : 'password';
      ikon.classList.toggle('bi-eye', !gizli);
      ikon.classList.toggle('bi-eye-slash', gizli);
    });
  });
}
