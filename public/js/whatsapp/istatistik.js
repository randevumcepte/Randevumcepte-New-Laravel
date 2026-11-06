/**
 * WhatsApp › İSTATİSTİK: bugün / 7 gün / 30 gün kartları + 30 günlük yığılmış grafik.
 * Veri GET /whatsapp/ozet; hesap sunucuda (Domain\Whatsapp\GonderimOzeti).
 */
import { esc } from '/js/lib/v2-ortak.js?v=2';
import { ozetKart } from '/js/lib/v2-bilesen.js?v=2';
import { sayi } from '/js/lib/v2-donem.js?v=1';
import { yiginCubuk, grafikleriTemizle, RENK } from '/js/lib/v2-grafik.js?v=2';

export async function ciz(kap, ctx) {
  const r = await ctx.oku('/whatsapp/ozet');
  if (!r.ok || !r.govde?.basarili) {
    kap.innerHTML = `<div class="wa-bos wa-hata">${esc(r.govde?.mesaj || `Hata (${r.kod})`)}</div>`;
    return null;
  }
  const d = r.govde.veri;
  const oranRenk = d.basariOrani >= 90 ? 'yesil' : 'turuncu';
  kap.innerHTML = `
    <div class="rc-ozet">
      ${ozetKart('bi-telephone', 'Bağlı Numara', d.numara || '—', 'mavi')}
      ${ozetKart('bi-send', `Bugün (✓${d.bugun.basari} ✗${d.bugun.fail} ⤵${d.bugun.fallback})`, sayi(d.bugun.toplam), 'mor')}
      ${ozetKart('bi-calendar-week', `7 Gün (${sayi(d.hafta.basari)} başarılı)`, sayi(d.hafta.toplam), 'turkuaz')}
      ${ozetKart('bi-calendar-month', `30 Gün (${sayi(d.ay.basari)} başarılı)`, sayi(d.ay.toplam), 'turkuaz')}
      ${ozetKart('bi-graph-up', 'Haftalık Başarı', `%${d.basariOrani}`, oranRenk)}
      ${ozetKart('bi-speedometer2', 'Günlük Limit', sayi(d.gunluk_limit), 'turuncu')}
    </div>
    <section class="wa-kart">
      <h3>Son 30 Gün — Günlük Mesaj Hacmi</h3>
      <div class="wa-grafik"><canvas id="waGrafik"></canvas></div>
    </section>`;

  yiginCubuk(kap.querySelector('#waGrafik'), d.gunler.map((g) => g.gun.slice(5)), [
    { ad: 'Başarılı', veri: d.gunler.map((g) => g.basari), renk: RENK.yesil },
    { ad: 'Başarısız', veri: d.gunler.map((g) => g.fail), renk: RENK.gul },
    { ad: "SMS'e Düştü", veri: d.gunler.map((g) => g.fallback), renk: RENK.kehribar },
  ]);

  return { birak: grafikleriTemizle };
}
