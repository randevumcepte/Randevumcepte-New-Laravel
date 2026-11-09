/** Guvenlik Duvari: watchdog durumu, engelli IP'ler, whitelist/blacklist, son olaylar. Panel kural yazar, watchdog uygular. */
import { el, esc, onay } from '/js/lib/v2-ortak.js?v=7';
import { ekran, syVeri, syYaz } from '/js/lib/v2-sistem.js?v=7';
import { kisalt, metrik, rozet, sayfaBaslik, syTablo } from '/js/sistem/ortak.js?v=1';

const WATCHDOG_IKON = { calisiyor: 'mdi-shield-check', durmus: 'mdi-shield-off', kurulmamis: 'mdi-shield-alert' };
const tablolar = [];

function watchdogCiz(w) {
  const ban = w.canli && w.aktif_ban !== null ? `<span class="gd-soluk">· ${w.aktif_ban} aktif ban</span>` : '';
  el('#gdRozet').innerHTML = `
    <span class="gd-watchdog gd-${esc(w.durum)}" title="${esc(w.son ? `Son heartbeat: ${w.son}` : 'Watchdog hiç heartbeat yazmadı')}">
      <i class="mdi ${WATCHDOG_IKON[w.durum]}"></i> Watchdog: ${esc(w.etiket)} <span class="gd-soluk">· ${esc(w.zaman)}</span> ${ban}
    </span>`;
  const uyari = el('#gdUyari');
  uyari.hidden = w.canli;
  if (w.canli) { uyari.className = ''; return; }
  uyari.className = `sy-card gd-uyari gd-${esc(w.durum)}`;
  uyari.innerHTML = `<div class="sy-card-body sy-fs-13">${w.kuruldu
    ? `<strong>Watchdog ${esc(w.zaman)} — şu an engelleme yapmıyor olabilir.</strong> Cron durmuş ya da script hata veriyor olabilir. Sunucuda: <code>journalctl -t guvenlik-watchdog -n 20</code>.`
    : '<strong>Watchdog kurulu değil / hiç çalışmadı.</strong> Root cron ve ipset kurulumu için <code>docs/GUVENLIK_DUVARI.md</code>. Watchdog çalışmazsa bu paneldeki whitelist/blacklist kuralları ve otomatik engelleme <strong>uygulanmaz</strong>.'}</div>`;
}

function ozetCiz(o) {
  el('#gdMetrik').innerHTML = [
    { renk: 'danger', ikon: 'mdi-shield-lock', etiket: 'Şu An Engelli IP', deger: o.engelli_aktif, alt: 'Aktif ban (son 24s)' },
    { renk: 'warning', ikon: 'mdi-cancel', etiket: 'Son 24 Saat Engel', deger: o.son24_engel, alt: 'Otomatik engelleme sayısı' },
    { renk: o.son_load ? 'warning' : 'success', ikon: 'mdi-speedometer', etiket: 'Son Yük Uyarısı', deger: o.son_load?.deger ?? '—', alt: o.son_load?.zaman ?? 'Yük uyarısı yok' },
    { ikon: 'mdi-clock-outline', etiket: 'Son Olay', deger: o.son_olay?.zaman ?? '—', alt: o.son_olay ? `${o.son_olay.tur} · ${o.son_olay.aksiyon}` : 'Kayıt yok' },
  ].map(metrik).join('');
  el('#gdEngelliSayi').textContent = o.engelli_aktif;
  el('#gdWhiteSayi').textContent = o.whitelist;
  el('#gdBlackSayi').textContent = o.blacklist;
}

async function ozetYukle() {
  const v = await syVeri('/guvenlik-duvari');
  if (!v) return;
  watchdogCiz(v.watchdog);
  ozetCiz(v.ozet);
}

const tazele = () => { ozetYukle(); tablolar.forEach((t) => t.yenile(false)); };

const kuralFormu = (tip, ipIpucu, aciklamaIpucu, buton, sinif) => `
  <form class="sy-flex-row" data-kural-form="${tip}" style="gap:8px;flex-wrap:wrap">
    <input name="ip" placeholder="${ipIpucu}" class="sy-input" required style="flex:1;min-width:160px">
    <input name="aciklama" placeholder="${aciklamaIpucu}" class="sy-input" maxlength="255" style="flex:1;min-width:140px">
    <button class="sy-btn ${sinif}">${buton}</button>
  </form>`;

const kuralTablosu = (kap, tip, aciklamaBasligi, bosMesaj, silOnay, silEtiket) => syTablo(kap, {
  yol: '/guvenlik-duvari/kurallar',
  params: () => ({ tip }),
  anahtar: `sy_gd_${tip}`,
  varsayilanBoy: 10,
  birim: 'kural',
  araIpucu: 'IP, açıklama…',
  bosMesaj,
  sirala: { alan: 'zaman', yon: 'desc' },
  kolonlar: [{ baslik: 'IP', sirala: 'ip' }, { baslik: aciklamaBasligi, sirala: 'aciklama' }, { baslik: 'Ekleyen', sirala: 'ekleyen' }, { baslik: '' }],
  satir: (k) => `<tr>
    <td><strong>${esc(k.ip)}</strong></td>
    <td class="sy-fs-13">${esc(k.aciklama || '—')}</td>
    <td class="sy-fs-12 sy-text-muted">${esc(k.ekleyen || '—')}</td>
    <td class="sy-text-right"><button type="button" class="sy-btn sy-btn-soft sy-btn-sm" data-kural-sil="${k.id}" data-onay="${esc(silOnay(k.ip))}">${silEtiket}</button></td>
  </tr>`,
});

function olaylariBagla() {
  ekran().addEventListener('submit', async (e) => {
    const form = e.target.closest('[data-kural-form]');
    if (!form) return;
    e.preventDefault();
    const { tamam } = await syYaz('POST', `/guvenlik-duvari/${form.dataset.kuralForm}`, { ip: form.ip.value.trim(), aciklama: form.aciklama.value.trim() || null });
    if (tamam) { form.reset(); tazele(); }
  });
  ekran().addEventListener('click', async (e) => {
    const kaldir = e.target.closest('[data-engel-kaldir]');
    if (kaldir) {
      const ip = kaldir.dataset.engelKaldir;
      if (!await onay(`${ip} engeli kaldırılsın (whitelist’e eklenir)?`, { baslik: 'Engeli Kaldır', tamam: 'Engeli Kaldır' })) return;
      const { tamam } = await syYaz('POST', '/guvenlik-duvari/engel-kaldir', { ip });
      if (tamam) tazele();
      return;
    }
    const sil = e.target.closest('[data-kural-sil]');
    if (sil) {
      if (!await onay(sil.dataset.onay, { baslik: 'Kuralı Sil', tamam: 'Sil' })) return;
      const { tamam } = await syYaz('DELETE', `/guvenlik-duvari/kural/${sil.dataset.kuralSil}`);
      if (tamam) tazele();
    }
  });
}

ekran().innerHTML = `
  ${sayfaBaslik('Güvenlik Duvarı', 'Flood / SSH brute-force otomatik engelleme · saldırı anında WhatsApp alarm', `
    <span id="gdRozet"></span>
    <a href="/sistemyonetim/sistem-saglik" class="sy-btn sy-btn-soft">Sistem Sağlık</a>
    <a href="/sistemyonetim/guvenlik/girisler" class="sy-btn sy-btn-soft">Giriş Logları</a>`)}
  <div id="gdUyari" hidden></div>
  <div class="sy-metric-grid" id="gdMetrik"></div>
  <div class="sy-grid-2 gd-aralik">
    <div class="sy-card">
      <div class="sy-card-head"><h3>Whitelist’e Ekle <span class="sy-text-muted sy-fs-12">— bu IP asla engellenmez</span></h3></div>
      <div class="sy-card-body">
        ${kuralFormu('whitelist', 'Örn: 151.135.241.18', 'Açıklama (opsiyonel)', 'Ekle', 'sy-btn-success')}
        <div class="sy-text-muted sy-fs-12 sy-mt-12">Kendi ofis/ev/ekip IP’lerini buraya ekle ki yanlışlıkla engellenmesin.</div>
      </div>
    </div>
    <div class="sy-card">
      <div class="sy-card-head"><h3>Kalıcı Engelle (Blacklist)</h3></div>
      <div class="sy-card-body">
        ${kuralFormu('blacklist', 'Engellenecek IP', 'Sebep (opsiyonel)', 'Engelle', 'sy-btn-danger')}
        <div class="sy-text-muted sy-fs-12 sy-mt-12">Bilinen saldırgan IP’yi elle kalıcı engelle — watchdog en geç 1 dk içinde uygular.</div>
      </div>
    </div>
  </div>
  <div class="sy-card gd-aralik">
    <div class="sy-card-head"><h3>Aktif Engellenen IP’ler <span class="sy-badge sy-badge-danger" id="gdEngelliSayi"></span></h3></div>
    <div class="sy-card-body tight" id="gdEngelli"></div>
  </div>
  <div class="sy-grid-2 gd-aralik">
    <div class="sy-card">
      <div class="sy-card-head"><h3>Whitelist <span class="sy-badge sy-badge-success" id="gdWhiteSayi"></span></h3></div>
      <div class="sy-card-body tight" id="gdWhite"></div>
    </div>
    <div class="sy-card">
      <div class="sy-card-head"><h3>Blacklist (kalıcı) <span class="sy-badge sy-badge-danger" id="gdBlackSayi"></span></h3></div>
      <div class="sy-card-body tight" id="gdBlack"></div>
    </div>
  </div>
  <div class="sy-card gd-aralik">
    <div class="sy-card-head"><h3>Son Olaylar <span class="sy-text-muted sy-fs-12">— son 100 kayıt</span></h3></div>
    <div class="sy-card-body tight" id="gdOlay"></div>
  </div>`;

ozetYukle();
olaylariBagla();

tablolar.push(
  syTablo(el('#gdEngelli'), {
    yol: '/guvenlik-duvari/engelliler',
    anahtar: 'sy_gd_engelli',
    varsayilanBoy: 25,
    birim: 'IP',
    araIpucu: 'IP, tür, detay…',
    bosMesaj: 'Şu an engelli IP yok. Sistem temiz görünüyor.',
    sirala: { alan: 'zaman', yon: 'desc' },
    kolonlar: [
      { baslik: 'IP', sirala: 'ip' }, { baslik: 'Tür', sirala: 'tur' }, { baslik: 'Değer', sirala: 'deger' }, { baslik: 'Detay' },
      { baslik: 'Zaman', sirala: 'zaman' }, { baslik: 'İşlem', sinif: 'sy-text-right' },
    ],
    satir: (o) => `<tr>
      <td><strong>${esc(o.ip)}</strong></td>
      <td>${rozet(o.tur_renk, o.tur_etiket)}</td>
      <td class="sy-fs-13">${esc(o.deger_metin)}</td>
      <td class="sy-fs-13 sy-text-muted">${esc(o.detay || '—')}</td>
      <td class="nowrap sy-fs-12">${esc(o.zaman)}</td>
      <td class="sy-text-right"><button type="button" class="sy-btn sy-btn-soft sy-btn-sm" data-engel-kaldir="${esc(o.ip)}">Engeli Kaldır</button></td>
    </tr>`,
  }),
  kuralTablosu(el('#gdWhite'), 'whitelist', 'Açıklama', 'Whitelist boş', (ip) => `${ip} whitelist’ten silinsin?`, 'Sil'),
  kuralTablosu(el('#gdBlack'), 'blacklist', 'Sebep', 'Blacklist boş', (ip) => `${ip} engeli kaldırılsın?`, 'Kaldır'),
  syTablo(el('#gdOlay'), {
    yol: '/guvenlik-duvari/olaylar',
    anahtar: 'sy_gd_olay',
    varsayilanBoy: 25,
    birim: 'olay',
    araIpucu: 'IP, tür, aksiyon, detay…',
    bosMesaj: 'Henüz olay yok. Watchdog kurulduğunda olaylar burada listelenecek.',
    sirala: { alan: 'zaman', yon: 'desc' },
    kolonlar: [
      { baslik: 'Zaman', sirala: 'zaman' }, { baslik: 'Tür', sirala: 'tur' }, { baslik: 'IP', sirala: 'ip' }, { baslik: 'Değer', sirala: 'deger' },
      { baslik: 'Aksiyon', sirala: 'aksiyon' }, { baslik: 'Detay' },
    ],
    satir: (o) => `<tr>
      <td class="nowrap sy-fs-12">${esc(o.zaman)}</td>
      <td class="sy-fs-13">${esc(o.tur_etiket)}</td>
      <td class="sy-fs-13">${esc(o.ip || '—')}</td>
      <td class="sy-fs-13">${esc(o.deger_metin)}</td>
      <td>${rozet(o.aksiyon_renk, o.aksiyon_etiket)}</td>
      <td class="sy-fs-12 sy-text-muted" title="${esc(o.detay || '')}">${esc(kisalt(o.detay, 60))}</td>
    </tr>`,
  }),
);
