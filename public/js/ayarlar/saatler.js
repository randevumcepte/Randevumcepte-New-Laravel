/**
 * Ayarlar → Çalışma Saatleri (v2). Eski #calisma_mola_saatleri formuyla birebir: her gün için
 * çalışıyor + başlangıç/bitiş, mola var + mola başlangıç/bitiş. 7 gün listesi SUNUCUDAN gelir.
 * Plan tablosu ortak.js'te (cihaz formu ile aynı). Uçlar: GET/PUT /ayar/calisma-saatleri.
 */
import { yukle, kaydet, kart, kaydetCubugu, gonderBagla, planTablosu, planBagla, planOku } from '/js/ayarlar/ortak.js?v=5';

export async function ciz(kap, ctx) {
  const v = await yukle(kap, '/ayar/calisma-saatleri', ctx.salonId);
  if (v) formCiz(kap, ctx, v.gunler);
}

function formCiz(kap, ctx, gunler) {
  kap.innerHTML = `<form class="ay-form" novalidate>
    ${kart('bi-clock', 'Haftalık Çalışma ve Mola Saatleri', planTablosu(gunler), 'Takvim ve online randevu bu saatlere göre açılır.')}
    ${kaydetCubugu()}
  </form>`;
  const form = kap.querySelector('form');
  planBagla(form);
  gonderBagla(form, async () => {
    const veri = await kaydet('PUT', '/ayar/calisma-saatleri', { salon_id: ctx.salonId, gunler: planOku(form) });
    if (veri) formCiz(kap, ctx, veri.gunler);
  });
}
