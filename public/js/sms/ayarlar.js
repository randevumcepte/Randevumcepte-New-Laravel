/**
 * SMS Yönetimi › AYARLAR (hesap sahibi). Kartlar + hedefler sunucudan (Domain\Sms\SmsAyarKatalogu);
 * burada kural yok. Veri: acilis.ayar (ilk çizim) · PATCH /sms/ayarlar.
 */
import { esc, yazSonuc } from '/js/lib/v2-ortak.js?v=1';
import { kilitle } from '/js/lib/v2-bilesen.js?v=2';

const HEDEF = { musteri: 'Müşteri', personel: 'Personel' };

export async function ciz(kap, ctx) {
  const yazabilir = ctx.acilis.yetkiler.yazabilir;

  function ciz2() {
    const a = ctx.acilis.ayar;
    const saatler = [];
    for (let s = a.saat_araligi.min; s <= a.saat_araligi.maks; s++) saatler.push(s);
    kap.innerHTML = `
      <form id="ayForm">
        <div class="sm-ayar-izgara">
          ${a.ayarlar.map((k) => `
            <div class="sm-ayar-kart" data-ayar="${k.ayar_id}">
              <h4>${esc(k.baslik)}</h4>
              <p>${esc(k.aciklama)}</p>
              <div class="sm-ayar-secim">
                ${k.hedefler.map((h) => `<label><input type="checkbox" data-hedef="${h}"${k[h] ? ' checked' : ''}${yazabilir ? '' : ' disabled'}>
                  ${k.hedefler.length > 1 ? esc(HEDEF[h]) : 'Açık / Kapalı'}</label>`).join('')}
              </div>
              ${k.ayar_id === a.saat_secimli_ayar ? `<div class="rc-alan sm-ayar-saat"><label>Kaç saat önce gönderilecek?</label>
                <select id="aySaat"${yazabilir ? '' : ' disabled'}>${saatler.map((s) => `<option value="${s}"${s === a.randevu_sms_hatirlatma ? ' selected' : ''}>${s} saat</option>`).join('')}</select></div>` : ''}
            </div>`).join('')}
        </div>
        ${yazabilir ? '<div class="sm-ayar-alt"><button type="submit" class="rc-btn rc-btn-yesil"><i class="bi bi-check-lg"></i> Ayarları Güncelle</button></div>' : ''}
      </form>`;

    kap.querySelector('#ayForm').addEventListener('submit', (e) => {
      e.preventDefault();
      const ayarlar = [...kap.querySelectorAll('[data-ayar]')].map((kart) => {
        const satir = { ayar_id: parseInt(kart.dataset.ayar, 10) };
        kart.querySelectorAll('[data-hedef]').forEach((c) => { satir[c.dataset.hedef] = c.checked; });
        return satir;
      });
      const saat = kap.querySelector('#aySaat');
      kilitle(e.submitter, 'Kaydediliyor…', async () => {
        const r = await ctx.yaz('PATCH', '/ayarlar', { ayarlar, ...(saat ? { randevu_sms_hatirlatma: parseInt(saat.value, 10) } : {}) });
        if (yazSonuc(r, r.govde?.mesaj)) { ctx.acilis.ayar = r.govde.veri; ciz2(); }
      });
    });
  }
  ciz2();
}
