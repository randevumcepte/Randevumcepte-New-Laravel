/**
 * WhatsApp Yönetimi sekmelerinin ortak parçaları: durum rozeti, tarih biçimi, mesaj detayı.
 * Etiket/renk sözlüğü API'den (ctx.gonderimDurumlari).
 */
import { esc } from '/js/lib/v2-ortak.js?v=7';
import { modalAc } from '/js/lib/v2-modal.js?v=7';
import { trTarih } from '/js/lib/v2-donem.js?v=7';

export const tarihSaat = (s) => (s ? `${trTarih(s)} ${String(s).slice(11, 16)}` : '—');

export function durumRozet(ctx, kod) {
  const d = ctx.gonderimDurumlari[kod];
  return d ? `<span class="rc-rozet rc-rozet-${esc(d.renk)}" title="${esc(d.aciklama)}">${esc(d.ad)}</span>` : esc(kod);
}

/** Mesajlarım satırına tıklayınca: tüm alanlar + mesajın tam metni. */
export function mesajDetayAc(ctx, m) {
  const satir = (ad, deger) => (deger ? `<tr><th>${esc(ad)}</th><td>${deger}</td></tr>` : '');
  modalAc('Mesaj Detayı', `
    <table class="wa-detay">
      ${satir('Tarih', esc(tarihSaat(m.created_at)))}
      ${satir('Müşteri', esc(m.musteri_adi || '—'))}
      ${satir('Telefon', esc(m.telefon))}
      ${satir('Durum', durumRozet(ctx, m.durum))}
      ${satir('Randevu', m.randevu_id ? `#${esc(m.randevu_id)}` : '')}
      ${satir('Hata', m.hata ? `<span class="wa-hata-metin">${esc(m.hata)}</span>` : '')}
    </table>
    <h4 class="wa-alt-baslik">Mesaj İçeriği</h4>
    <div class="wa-mesaj">${esc(m.mesaj)}</div>`, '', { ikon: 'chat-left-text' });
}
