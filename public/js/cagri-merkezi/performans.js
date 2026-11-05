/**
 * Çağrı Merkezi › PERFORMANS (yönetici; eski /isletmeyonetim/arama-dashboard "Performans" sekmesi).
 * Personel kartları (atanan data, aranan, konuşulan, cevapsız, ön görüşme, satış/ciro, geciken)
 * + karta tıklayınca görüşme dökümü (filtre çipleri sunucudan). Veri: GET /cagri-merkezi/performans[/{id}].
 */
import { el, esc } from '/js/lib/v2-ortak.js?v=1';
import { modalAc } from '/js/lib/v2-modal.js?v=1';
import { ozetKart } from '/js/lib/v2-bilesen.js?v=2';
import { gecmisCiz, harf } from '/js/cagri-merkezi/ortak.js?v=6';

const para = (n) => Number(n || 0).toLocaleString('tr-TR');

export async function ciz(kap, ctx) {
  kap.innerHTML = '<div class="rc-ozet" id="cmPOzet"></div><div class="cm-kartlar" id="cmPKart"><div class="cm-bos"><span class="rc-yukleniyor"></span></div></div>';
  const $ = (s) => kap.querySelector(s);
  const r = await ctx.oku('/performans');
  if (!r.ok || !r.govde?.basarili) { $('#cmPKart').innerHTML = `<div class="cm-bos cm-hata">${esc(r.govde?.mesaj || 'Veriler yüklenemedi')}</div>`; return; }
  const { kartlar, ozet } = r.govde.veri;
  $('#cmPOzet').innerHTML = ozetKart('bi-database', 'Toplam Atanan Data', ozet.atanan) + ozetKart('bi-telephone', 'Konuşulan', ozet.konusulan, 'yesil')
    + ozetKart('bi-slash-circle', 'Cevapsız', ozet.cevapsiz, 'turuncu') + ozetKart('bi-calendar-check', 'Arama Randevusu', ozet.randevu, 'mavi');
  if (!kartlar.length) { $('#cmPKart').innerHTML = '<div class="cm-bos"><i class="bi bi-inbox"></i>Henüz hiçbir personele arama listesi atanmamış.</div>'; return; }

  const kutu = (n, t, sinif = '') => `<div class="cm-pk-kutu ${sinif}"><b>${esc(n)}</b><span>${esc(t)}</span></div>`;
  $('#cmPKart').innerHTML = kartlar.map((k) => `
    <button type="button" class="cm-pk" data-personel="${k.personel_id}">
      <div class="cm-pk-bas"><span class="cm-avatar">${harf(k.ad)}</span><b>${esc(k.ad)}</b><span class="cm-cip"><i class="bi bi-clock"></i> ${esc(k.toplam_dk)} dk</span></div>
      <div class="cm-pk-ilerleme"><span>${esc(k.aranan)} / ${esc(k.atanan)} arandı</span><b>%${esc(k.ilerleme)}</b></div>
      <div class="cm-bar"><span style="width:${Math.min(100, Number(k.ilerleme) || 0)}%"></span></div>
      <div class="cm-pk-hedef">${kutu(k.ongorusme, 'Ön Görüşme', 'mavi')}${kutu(k.satis, 'Satış' + (k.satis_cirosu > 0 ? ` · ${para(k.satis_cirosu)}₺` : ''), 'yesil')}</div>
      <div class="cm-pk-izgara">
        ${kutu(k.konusulan, 'Konuşulan', 'yesil')}${kutu(k.cevapsiz, 'Cevapsız', 'kirmizi')}${kutu(k.randevu, 'Tekrar Ara', 'mavi')}
        ${kutu(k.kalan, 'Kalan')}${kutu(k.ulasilamadi, 'Ulaşılamadı', 'gri')}${kutu(k.gecikti, 'Geciken Arama', 'kirmizi')}
        ${kutu(k.gec_aranan, 'Geç Aranan', 'turuncu')}${kutu(k.zamaninda_aranan, 'Zamanında', 'yesil')}${kutu(k.gorusme_sayisi, 'Görüşme')}
      </div>
    </button>`).join('');

  $('#cmPKart').addEventListener('click', (e) => {
    const b = e.target.closest('[data-personel]');
    if (b) detay(ctx, kartlar.find((k) => String(k.personel_id) === b.dataset.personel));
  });
}

async function detay(ctx, k) {
  const ov = modalAc(k.ad, '<div class="cm-ciplar" id="pdFiltre"></div><div id="pdListe"><div class="cm-bos"><span class="rc-yukleniyor"></span></div></div>', '',
    { ikon: 'person-lines-fill', altbaslik: `${k.aranan}/${k.atanan} arandı · ${k.toplam_dk} dk konuşma · ${k.satis} satış`, genis: true });
  const r = await ctx.oku(`/performans/${k.personel_id}`);
  if (!r.ok || !r.govde?.basarili) { el('#pdListe', ov).innerHTML = `<div class="cm-bos cm-hata">${esc(r.govde?.mesaj || 'Döküm yüklenemedi')}</div>`; return; }
  const { notlar, gruplar } = r.govde.veri;
  let aktif = 'hepsi';
  const uyar = (g, n) => !g.sonuclar.length || g.sonuclar.includes(n.sonuc_kod);
  const ciz = () => {
    el('#pdFiltre', ov).innerHTML = gruplar.map((g) => ({ g, n: notlar.filter((x) => uyar(g, x)).length }))
      .filter(({ g, n }) => g.kod === 'hepsi' || n > 0)
      .map(({ g, n }) => `<button type="button" class="cm-cip-btn${aktif === g.kod ? ' aktif' : ''}" data-g="${esc(g.kod)}">${esc(g.ad)} (${n})</button>`).join('');
    const g = gruplar.find((x) => x.kod === aktif) || gruplar[0];
    gecmisCiz(el('#pdListe', ov), notlar.filter((x) => uyar(g, x)), { bos: 'Bu filtreye uygun görüşme yok.', musteriGoster: true });
  };
  el('#pdFiltre', ov).addEventListener('click', (e) => { const b = e.target.closest('[data-g]'); if (b) { aktif = b.dataset.g; ciz(); } });
  ciz();
}
