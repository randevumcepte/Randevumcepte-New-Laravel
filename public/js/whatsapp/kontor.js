/**
 * WhatsApp › KONTÖR: bakiye bandı + paketler + talep. Bant metni, seviye, fiyat ve avantaj API'den
 * (GET /whatsapp/kontor); istemci yalnız çizer, talepte paket KODU gönderir.
 */
import { el, esc, yazSonuc } from '/js/lib/v2-ortak.js?v=1';
import { kilitle } from '/js/lib/v2-bilesen.js?v=2';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { sayi } from '/js/lib/v2-donem.js?v=1';

const birim = (n) => Number(n).toLocaleString('tr-TR', { minimumFractionDigits: 3, maximumFractionDigits: 3 });

const kart = (p) => `
  <div class="wa-paket${p.en_avantajli ? ' en-iyi' : ''}">
    ${p.en_avantajli ? `<div class="wa-paket-etiket">⭐ EN AVANTAJLI · %${esc(p.indirim_yuzde)}</div>` : ''}
    <div class="wa-paket-adet">${esc(sayi(p.adet))} <span>kontör</span></div>
    <div class="wa-soluk">tanesi ${esc(birim(p.birim_fiyat))} TL</div>
    <div class="wa-paket-fiyat">${esc(sayi(p.fiyat))} <small>TL</small></div>
    <div class="wa-paket-avantaj${p.avantaj > 0 ? '' : ' bos'}">${p.avantaj > 0 ? `${esc(sayi(p.avantaj))} TL avantaj` : '—'}</div>
    <button type="button" class="rc-btn ${p.en_avantajli ? 'rc-btn-yesil' : 'rc-btn-ikincil'}" data-paket="${esc(p.kod)}">Kontör Al</button>
  </div>`;

function talepAc(ctx, p) {
  const ov = modalAc(`${p.ad} — ${p.fiyat_metin}`, `
    <p class="wa-soluk">Bu paket için talebinizi bırakıyorsunuz. Onaylarsanız yetkilimize bildirim gider ve ödeme + kontör yükleme için sizinle iletişime geçilir.</p>
    <div class="rc-alan"><label for="waTalepNot">İletişim / Not <small>(opsiyonel)</small></label>
      <input type="text" id="waTalepNot" maxlength="200" placeholder="örn. bugün ödemek istiyorum / 0555 123 45 67"></div>`,
  '<button type="button" class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button type="button" class="rc-btn rc-btn-yesil" id="waTalepGonder">Talep Gönder</button>', { ikon: 'coin', dar: true });
  el('[data-kapat]', ov).addEventListener('click', modalKapat);
  el('#waTalepGonder', ov).addEventListener('click', (e) => kilitle(e.currentTarget, 'Gönderiliyor…', async () => {
    const r = await ctx.yaz('POST', '/whatsapp/kontor/talep', { paket: p.kod, iletisim: el('#waTalepNot', ov).value.trim() });
    if (yazSonuc(r, r.govde?.mesaj)) modalKapat();
  }));
}

export async function ciz(kap, ctx) {
  const r = await ctx.oku('/whatsapp/kontor');
  if (!r.ok || !r.govde?.basarili) {
    kap.innerHTML = `<div class="wa-bos wa-hata">${esc(r.govde?.mesaj || `Hata (${r.kod})`)}</div>`;
    return;
  }
  const k = r.govde.veri;
  kap.innerHTML = `
    <div class="wa-kontor-bant ${esc(k.renk)}">
      <div class="wa-kontor-ikon">${esc(k.ikon)}</div>
      <div class="wa-kontor-metin">
        <b>${esc(k.baslik)}</b>
        <p>${esc(k.metin)}</p>
        ${k.not ? `<p class="wa-kontor-not">${esc(k.not)}</p>` : ''}
      </div>
      <div class="wa-kontor-rozet">${esc(k.rozet)}</div>
    </div>
    ${k.paylasilan ? `<div class="wa-bilgi">Mesajlarınız ${esc(k.paylasilan.salon_adi)} işletmesinin oturumundan gider; bakiye o işletmeye aittir.</div>` : ''}
    <div class="wa-kontor-bas">
      <h2>WhatsApp Kontör Paketleri</h2>
      <p class="wa-soluk">1 mesaj = 1 kontör. Çok alırsanız tanesi daha ucuza gelir.</p>
    </div>
    <div class="wa-paketler">${k.paketler.map(kart).join('')}</div>
    <p class="wa-soluk wa-orta">Ödeme sonrası kontörünüz manuel olarak yüklenir. "Kontör Al" ile talebinizi bırakın, en kısa sürede sizinle iletişime geçelim.</p>`;

  kap.addEventListener('click', (e) => {
    const b = e.target.closest('[data-paket]');
    if (b) talepAc(ctx, k.paketler.find((p) => p.kod === b.dataset.paket));
  });
}
