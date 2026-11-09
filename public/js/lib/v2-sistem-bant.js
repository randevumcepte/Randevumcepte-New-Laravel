/**
 * "Salon hesabina gir" oturumu: isletme panelinde ust bant + Yonetim Paneline Don.
 * Oturumu baslatan sistem yonetimi ekrani rc_sy_imp'i yazar; isletme paneli rc_sy_imp yoksa hicbir sey yapmaz.
 * Bu modul sistem kutuphanelerine bagli degil: isletme kabugunun yuku artmasin.
 */
import { esc } from '/js/lib/v2-ortak.js?v=7';

const IMP = 'rc_sy_imp';

const oku = () => {
  try { return JSON.parse(localStorage.getItem(IMP) || 'null'); } catch (_) { return null; }
};

/** Sistem ekraninda: isletme panelini yetkili token'iyla ac. */
export function impersonationBaslat({ token, log_id: logId, salon_adi: salonAdi, yetkili, hedef }) {
  localStorage.setItem('rc_access_token', token);
  localStorage.setItem(IMP, JSON.stringify({ log_id: logId, salon_adi: salonAdi, yetkili }));
  location.href = hedef;
}

async function don(logId) {
  try {
    await fetch(`/api/v2/sistem/impersonation/${logId}/bitir`, {
      method: 'POST',
      headers: { Accept: 'application/json', Authorization: 'Bearer ' + (localStorage.getItem('rc_sy_token') || '') },
    });
  } catch (_) { /* sunucuya ulasilamasa da tarayicidaki oturum kapanir; token suresi kisa */ }
  localStorage.removeItem(IMP);
  localStorage.removeItem('rc_access_token');
  location.href = '/sistemyonetim/dashboard';
}

export function impersonationBandi() {
  const imp = oku();
  if (!imp) return;
  const bant = document.createElement('div');
  bant.style.cssText = 'position:sticky;top:0;z-index:1000;background:#5C008E;color:#fff;padding:8px 16px;font-size:14px;display:flex;gap:14px;align-items:center;justify-content:center;flex-wrap:wrap';
  bant.innerHTML = `<span>Salon hesabındasınız: <b>${esc(imp.salon_adi)}</b> · ${esc(imp.yetkili)}</span>
    <button type="button" style="background:#fff;color:#5C008E;border:0;border-radius:6px;padding:4px 12px;font-weight:600;cursor:pointer">Yönetim Paneline Dön</button>`;
  bant.querySelector('button').onclick = () => don(imp.log_id);
  document.querySelector('.rc-govde')?.prepend(bant);
}
