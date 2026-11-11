/** Reputation Booster ayar modalı (eski "Google Review Ayarları") — GET + PATCH /anket/ayarlar. */
import { el, esc, mesaj, yazSonuc } from '/js/lib/v2-ortak.js?v=7';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';
import { kilitle } from '/js/lib/v2-bilesen.js?v=7';
import { alan } from '/js/lib/v2-ongorusme.js?v=9';
import { odulAlanlari, odulBagla } from '/js/anket/odul-form.js?v=1';

const ONEK = 'google_odul_';
const sayiAlan = (etiket, id, deger, ek) => alan(etiket, `<input type="number" id="${id}" value="${esc(deger)}" ${ek}>`);

export async function googleAyarAc(ctx, onKaydet) {
  const r = await ctx.oku('/ayarlar');
  if (!yazSonuc(r)) return;
  const a = r.govde.veri;

  const ov = modalAc('Google Review Ayarları', `
    <div class="an-bilgi an-bilgi-mavi">
      <b>En doğru yol — Google Business'tan yorum bağlantısı:</b><br>
      1. <a href="https://business.google.com" target="_blank" rel="noopener">business.google.com</a> → İşletmenize girin<br>
      2. Sol menü: <b>Müşteriler → Yorumlar</b><br>
      3. <b>"Daha fazla yorum al"</b> → "Bağlantıyı paylaş"<br>
      4. Bağlantıyı kopyalayıp aşağıya yapıştırın (g.page/r/... formatında olmalı)
    </div>
    <div class="rc-form-izgara">
      ${alan('Yorum Bağlantısı', `<input id="anGoogleUrl" value="${esc(a.google_url)}" placeholder="https://g.page/r/.../review">`, 'rc-tam')}
    </div>
    <h4 class="an-baslik an-yesil"><i class="bi bi-star-fill"></i> Yüksek Puan → Google'a Yönlendir</h4>
    <div class="rc-form-izgara">
      ${sayiAlan('NPS eşiği (bu ve üstü)', 'anEsikNps', a.google_review_esik_nps, 'min="0" max="10"')}
      ${sayiAlan('Yıldız eşiği (bu ve üstü)', 'anEsikCsat', a.google_review_esik_csat, 'min="1" max="5" step="0.1"')}
    </div>
    <h4 class="an-baslik an-altin"><i class="bi bi-gift"></i> Google Yorumu Ödülü (tıklayana kupon/puan)</h4>
    <div class="an-bilgi an-bilgi-altin">Müşteri <b>"Google'da Yorum Yaz"a tıklayınca</b> otomatik ödül tanımlanır. Google, yorum karşılığı ödülü politikalarına aykırı bulabilir; dikkatli kullanın.</div>
    ${odulAlanlari(ONEK, a, ctx.fv)}
    <h4 class="an-baslik an-kirmizi"><i class="bi bi-exclamation-triangle"></i> Düşük Puan → Anlık Uyarı</h4>
    <div class="rc-form-izgara">
      ${alan('Uyarı telefonu (salon yetkilisi)', `<input id="anUyariTel" value="${esc(a.kotu_puan_uyari_telefon)}" placeholder="5XXXXXXXXX" maxlength="25" inputmode="tel">`, 'rc-tam')}
      ${sayiAlan('NPS eşiği (bu ve altı)', 'anUyariNps', a.kotu_puan_uyari_esik_nps, 'min="0" max="10"')}
      ${sayiAlan('Yıldız eşiği (bu ve altı)', 'anUyariCsat', a.kotu_puan_uyari_esik_csat, 'min="1" max="5" step="0.1"')}
    </div>`,
  `<button class="rc-btn rc-btn-ikincil" id="anAyarVazgec">İptal</button><button class="rc-btn rc-btn-birincil" id="anAyarKaydet"><i class="bi bi-check-lg"></i> Kaydet</button>`,
  { ikon: 'google', sabit: true });

  const odul = odulBagla(ov);
  el('#anAyarVazgec', ov).onclick = modalKapat;
  el('#anAyarKaydet', ov).onclick = (e) => kilitle(e.currentTarget, 'Kaydediliyor…', async () => {
    const s = await ctx.yaz('PATCH', '/ayarlar', {
      google_url: el('#anGoogleUrl', ov).value.trim(),
      google_review_esik_nps: el('#anEsikNps', ov).value,
      google_review_esik_csat: el('#anEsikCsat', ov).value,
      kotu_puan_uyari_telefon: el('#anUyariTel', ov).value.trim(),
      kotu_puan_uyari_esik_nps: el('#anUyariNps', ov).value,
      kotu_puan_uyari_esik_csat: el('#anUyariCsat', ov).value,
      ...odul.oku(),
    });
    if (!yazSonuc(s, 'Google Review ayarları kaydedildi')) return;
    if (s.govde.veri.uyari) mesaj(s.govde.veri.uyari, 'bilgi');
    modalKapat();
    onKaydet();
  });
}
