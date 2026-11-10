/**
 * WhatsApp › ALICILARIM: numara başına mesaj sayıları (ortak veriTablosu + sunucu sayfalama).
 * Geçmiş, alıcının log id'siyle istenir (telefon maskeli olabilir; numara URL'ye düşmez).
 */
import { esc, mesaj } from '/js/lib/v2-ortak.js?v=7';
import { modalAc } from '/js/lib/v2-modal.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=8';
import { durumRozet, tarihSaat } from '/js/whatsapp/ortak.js?v=1';

async function gecmisAc(ctx, a) {
  const r = await ctx.oku(`/whatsapp/aliciler/${a.id}/gecmis`);
  if (!r.ok || !r.govde?.basarili) { mesaj(r.govde?.mesaj || `Hata (${r.kod})`, 'hata'); return; }
  const v = r.govde.veri;
  const kartlar = v.data.map((m) => `
    <div class="wa-gecmis">
      <div class="wa-gecmis-ust"><b>${esc(tarihSaat(m.created_at))}${m.randevu_id ? ` — Randevu #${esc(m.randevu_id)}` : ''}</b>${durumRozet(ctx, m.durum)}</div>
      <div class="wa-gecmis-metin">${esc(m.mesaj)}</div>
      ${m.hata ? `<div class="wa-hata-metin">Hata: ${esc(m.hata)}</div>` : ''}
    </div>`).join('');
  modalAc(`${v.musteri_adi || v.telefon} — Mesaj Geçmişi`, `<div class="wa-gecmis-liste">${kartlar || '<div class="wa-bos">Mesaj yok</div>'}</div>`, '', {
    ikon: 'clock-history', altbaslik: `${v.telefon} · ${v.data.length} mesaj`, genis: true,
  });
}

export async function ciz(kap, ctx) {
  kap.innerHTML = '<section class="wa-kart"><div id="waAliciTablo"></div></section>';
  let satirlar = [];
  const hedef = kap.querySelector('#waAliciTablo');
  const tablo = veriTablosu(hedef, {
    anahtar: 'whatsapp_aliciler',
    varsayilanBoy: 25,
    birim: 'alıcı',
    bosMesaj: 'Henüz mesaj göndermediniz.',
    araIpucu: 'Müşteri, telefon…',
    sirala: { alan: 'son', yon: 'desc' },
    kolonlar: [
      { baslik: 'Müşteri', sirala: 'musteri' }, { baslik: 'Telefon', sirala: 'telefon' }, { baslik: 'Toplam', sirala: 'toplam' },
      { baslik: 'Durum' }, { baslik: 'İlk', sirala: 'ilk' }, { baslik: 'Son', sirala: 'son' }, { baslik: '' },
    ],
    satir: (a, i) => `
      <tr>
        <td><b>${esc(a.musteri_adi || '—')}</b></td>
        <td class="wa-nowrap">${esc(a.telefon)}</td>
        <td>${esc(a.toplam)}</td>
        <td class="wa-nowrap"><span class="rc-rozet rc-rozet-yesil">✓${esc(a.basari)}</span>
          <span class="rc-rozet rc-rozet-kirmizi">✗${esc(a.fail)}</span>
          <span class="rc-rozet rc-rozet-turuncu">⤵${esc(a.fallback)}</span></td>
        <td class="wa-nowrap wa-soluk">${esc(tarihSaat(a.ilk_mesaj))}</td>
        <td class="wa-nowrap wa-soluk">${esc(tarihSaat(a.son_mesaj))}</td>
        <td><button type="button" class="rc-btn rc-btn-ikincil wa-kucuk" data-i="${i}"><i class="bi bi-clock-history"></i> Geçmiş</button></td>
      </tr>`,
    kaynak: (p) => ctx.oku('/whatsapp/aliciler', p),
    cizildi: (liste) => { satirlar = liste; },
  });
  hedef.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-i]');
    if (b) gecmisAc(ctx, satirlar[Number(b.dataset.i)]);
  });
  tablo.yenile();
}
