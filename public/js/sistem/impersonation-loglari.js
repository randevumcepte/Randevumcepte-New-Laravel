/** Salon hesabina giris kayitlari: kim, hangi salona, hangi sebeple, ne kadar sure. */
import { esc } from '/js/lib/v2-ortak.js?v=2';
import { ekran } from '/js/lib/v2-sistem.js?v=1';
import { guvenlikSekmeleri, rozet, sayfaBaslik, syTablo } from '/js/sistem/ortak.js?v=1';

ekran().innerHTML = `
  ${sayfaBaslik('Salon Hesabına Giriş Logları', 'Hangi destek personeli, hangi salona, hangi sebeple, ne kadar süre girdi', guvenlikSekmeleri('impersonation'))}
  <div class="sy-card"><div class="sy-card-body tight" id="imTablo"></div></div>`;

syTablo(ekran().querySelector('#imTablo'), {
  yol: '/guvenlik/impersonation',
  anahtar: 'sy_impersonation',
  araIpucu: 'Personel, salon, sebep, IP…',
  sirala: { alan: 'baslangic', yon: 'desc' },
  kolonlar: [
    { baslik: 'Personel', sirala: 'personel' }, { baslik: 'Salon', sirala: 'salon' }, { baslik: 'Sebep' },
    { baslik: 'Başlangıç', sirala: 'baslangic' }, { baslik: 'Süre' }, { baslik: 'IP', sirala: 'ip' },
  ],
  satir: (i) => `<tr>
    <td><strong>${esc(i.personel)}</strong></td>
    <td><a href="/sistemyonetim/salon/${i.salon_id}">${esc(i.salon_adi)}</a><div class="sy-text-muted sy-fs-12">${esc(i.yetkili_email || '')}</div></td>
    <td class="sy-fs-13">${esc(i.sebep || '—')}${i.ticket_id ? `<br><a href="/sistemyonetim/ticket/${i.ticket_id}" class="sy-fs-12">→ Ticket</a>` : ''}</td>
    <td class="nowrap sy-fs-12">${esc(i.baslangic)}</td>
    <td class="sy-fs-13">${i.sure ? esc(i.sure) : rozet('warning', 'Aktif')}</td>
    <td class="sy-text-muted sy-fs-12">${esc(i.ip || '')}</td>
  </tr>`,
});
