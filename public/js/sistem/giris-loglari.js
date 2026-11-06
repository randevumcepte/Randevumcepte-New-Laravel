/** Sistem yonetimi giris denemeleri (basarili + basarisiz). */
import { esc } from '/js/lib/v2-ortak.js?v=2';
import { ekran } from '/js/lib/v2-sistem.js?v=1';
import { guvenlikSekmeleri, kisalt, rozet, sayfaBaslik, syTablo } from '/js/sistem/ortak.js?v=1';

ekran().innerHTML = `
  ${sayfaBaslik('Giriş Logları', 'Tüm sistem yönetim girişleri (başarılı + başarısız)', guvenlikSekmeleri('girisler'))}
  <div class="sy-card"><div class="sy-card-body tight" id="glTablo"></div></div>`;

syTablo(ekran().querySelector('#glTablo'), {
  yol: '/guvenlik/girisler',
  anahtar: 'sy_girisler',
  araIpucu: 'E-posta, hata, IP…',
  sirala: { alan: 'zaman', yon: 'desc' },
  kolonlar: [
    { baslik: 'Zaman', sirala: 'zaman' }, { baslik: 'E-posta', sirala: 'email' }, { baslik: 'Durum', sirala: 'durum' },
    { baslik: 'Hata' }, { baslik: 'IP', sirala: 'ip' }, { baslik: 'User-Agent' },
  ],
  satir: (g) => `<tr>
    <td class="nowrap sy-fs-12">${esc(g.zaman)}</td>
    <td><strong>${esc(g.email)}</strong></td>
    <td>${g.basarili ? rozet('success', 'Başarılı') : rozet('danger', 'Başarısız')}</td>
    <td class="sy-fs-13">${esc(g.hata || '—')}</td>
    <td class="sy-text-muted sy-fs-12">${esc(g.ip || '')}</td>
    <td class="sy-text-muted sy-fs-12" title="${esc(g.tarayici || '')}">${esc(kisalt(g.tarayici, 40))}</td>
  </tr>`,
});
