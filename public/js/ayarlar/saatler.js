/**
 * Ayarlar → Çalışma Saatleri (v2). Eski #calisma_mola_saatleri formuyla birebir: her gün için
 * çalışıyor + başlangıç/bitiş, mola var + mola başlangıç/bitiş. 7 gün listesi SUNUCUDAN gelir.
 * Uçlar: GET/PUT /ayar/calisma-saatleri. Saat sırası kuralı sunucuda (422 mesajı toast'ta).
 */
import { esc } from '/js/lib/v2-ortak.js?v=1';
import { yukle, kaydet, kart, kaydetCubugu, gonderBagla, saatBagla } from '/js/ayarlar/ortak.js?v=1';

export async function ciz(kap, ctx) {
  const v = await yukle(kap, '/ayar/calisma-saatleri', ctx.salonId);
  if (!v) return;
  formCiz(kap, ctx, v.gunler);
}

const saat = (ad, deger) => `<input type="text" name="${ad}" value="${esc(deger)}" data-saat inputmode="numeric" maxlength="5">`;
const tik = (ad, acik) => `<span class="rc-switch"><input type="checkbox" name="${ad}"${acik ? ' checked' : ''}><span class="rc-kaydir"></span></span>`;

function formCiz(kap, ctx, gunler) {
  const satir = (g) => `
    <tr data-gun="${g.haftanin_gunu}" class="${g.calisiyor ? '' : 'ay-kapali'}">
      <th>${esc(g.gun_adi)}</th>
      <td class="rc-orta">${tik('calisiyor', g.calisiyor)}</td>
      <td>${saat('baslangic_saati', g.baslangic_saati)}</td>
      <td>${saat('bitis_saati', g.bitis_saati)}</td>
      <td class="rc-orta">${tik('mola_var', g.mola_var)}</td>
      <td>${saat('mola_baslangic_saati', g.mola_baslangic_saati)}</td>
      <td>${saat('mola_bitis_saati', g.mola_bitis_saati)}</td>
    </tr>`;

  kap.innerHTML = `<form class="ay-form" novalidate>
    ${kart('bi-clock', 'Haftalık Çalışma ve Mola Saatleri', `
      <div class="rc-tablo-sar"><table class="rc-tablo ay-saat-tablo">
        <thead><tr><th>Gün</th><th class="rc-orta">Çalışıyor</th><th>Başlangıç</th><th>Bitiş</th><th class="rc-orta">Mola</th><th>Mola Başlangıç</th><th>Mola Bitiş</th></tr></thead>
        <tbody>${gunler.map(satir).join('')}</tbody>
      </table></div>`, 'Takvim ve online randevu bu saatlere göre açılır.')}
    ${kaydetCubugu()}
  </form>`;

  const form = kap.querySelector('form');
  saatBagla(form);
  form.addEventListener('change', (e) => {
    if (e.target.name === 'calisiyor') e.target.closest('tr').classList.toggle('ay-kapali', !e.target.checked);
  });
  gonderBagla(form, async () => {
    const satirlar = [...form.querySelectorAll('tr[data-gun]')].map((tr) => {
      const d = (ad) => tr.querySelector(`[name="${ad}"]`);
      return {
        haftanin_gunu: Number(tr.dataset.gun),
        calisiyor: d('calisiyor').checked,
        baslangic_saati: d('baslangic_saati').value || null,
        bitis_saati: d('bitis_saati').value || null,
        mola_var: d('mola_var').checked,
        mola_baslangic_saati: d('mola_baslangic_saati').value || null,
        mola_bitis_saati: d('mola_bitis_saati').value || null,
      };
    });
    const veri = await kaydet('PUT', '/ayar/calisma-saatleri', { salon_id: ctx.salonId, gunler: satirlar });
    if (veri) formCiz(kap, ctx, veri.gunler);
  });
}
