/** Veritabani, PHP, disk, WhatsApp ve son log hatalari. Veri /sistem/sistem-saglik. */
import { esc } from '/js/lib/v2-ortak.js?v=7';
import { ekran, syVeri } from '/js/lib/v2-sistem.js?v=7';
import { bos, metrik, sayfaBaslik } from '/js/sistem/ortak.js?v=1';

const diskRenk = (k) => (k > 90 ? 'danger' : (k > 75 ? 'warning' : 'success'));

async function baslat() {
  const v = await syVeri('/sistem-saglik');
  if (!v) return;
  const dbTamam = v.veritabani.durum === 'OK';
  ekran().innerHTML = `
    ${sayfaBaslik('Sistem Sağlık', 'Veritabanı, disk, log ve servis durumu')}
    <div class="sy-metric-grid">${[
      { renk: dbTamam ? 'success' : 'danger', ikon: 'mdi-database', etiket: 'Veritabanı', deger: dbTamam ? 'OK' : 'HATA', alt: v.veritabani.surum || '—' },
      { renk: 'info', ikon: 'mdi-language-php', etiket: 'PHP', deger: v.php_surum, alt: `Laravel ${v.laravel_surum}` },
      {
        renk: diskRenk(v.disk.kullanim), ikon: 'mdi-harddisk', etiket: 'Disk Kullanımı', deger: `%${v.disk.kullanim}`, alt: `${v.disk.bos_gb} GB boş / ${v.disk.toplam_gb} GB`,
        ek: `<div class="sy-progress sy-mt-12"><div class="fill" style="width:${Number(v.disk.kullanim)}%"></div></div>`,
      },
      { renk: 'success', ikon: 'mdi-whatsapp', etiket: 'WhatsApp Aktif Salon', deger: v.whatsapp_aktif },
    ].map(metrik).join('')}</div>
    <div class="sy-card">
      <div class="sy-card-head"><h3>Son Log Hataları (laravel.log)</h3><span class="sy-text-muted sy-fs-12">${v.log_hatalari.length} kayıt</span></div>
      <div class="sy-card-body">${v.log_hatalari.length
        ? `<div class="ss-log">${v.log_hatalari.map((s) => `<div>${esc(s)}</div>`).join('')}</div>`
        : bos('mdi-check-all', 'Hata yok')}</div>
    </div>`;
}

baslat();
