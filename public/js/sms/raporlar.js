/**
 * SMS Yönetimi › RAPORLAR — tür alt sekmeleri (API'den), paket tablosu, alıcı detayı modalı.
 * Veri: GET /sms/raporlar?tur= (ListeIstegi) · GET /sms/raporlar/{paket}.
 */
import { esc, mesaj } from '/js/lib/v2-ortak.js?v=1';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=1';

export async function ciz(kap, ctx) {
  let tur = ctx.acilis.rapor_turleri[0]?.kod;
  let yenile = false;

  kap.innerHTML = `
    <section class="sm-panel">
      <div class="sm-panel-bas">
        <div class="sm-ciplar" id="rpTurler"></div>
        <button type="button" class="rc-btn rc-btn-ikincil sm-kucuk-btn" id="rpYenile"><i class="bi bi-arrow-clockwise"></i> Yenile</button>
      </div>
      <div class="sm-uyari-satir" id="rpUyari" hidden></div>
      <div id="rpTablo"></div>
    </section>`;
  const $ = (s) => kap.querySelector(s);

  const turCiz = () => {
    $('#rpTurler').innerHTML = ctx.acilis.rapor_turleri.map((t) =>
      `<button type="button" class="sm-cip${t.kod === tur ? ' aktif' : ''}" data-tur="${esc(t.kod)}">${esc(t.ad)}</button>`).join('');
  };

  const tablo = veriTablosu($('#rpTablo'), {
    anahtar: 'sms_raporlar',
    birim: 'paket',
    bosMesaj: 'Bu türde SMS raporu bulunamadı.',
    araIpucu: 'Mesaj içeriğinde ara…',
    sirala: { alan: 'tarih', yon: 'desc' },
    kolonlar: [
      { baslik: 'Tarih', sirala: 'tarih' },
      { baslik: 'Adet', sirala: 'adet', sinif: 'rc-sag' },
      { baslik: 'Toplam Kredi', sirala: 'kredi', sinif: 'rc-sag' },
      { baslik: 'Mesaj İçeriği' },
      { baslik: 'Durum' },
      { baslik: 'Detay' },
    ],
    satir: (r) => `<tr>
      <td class="sm-nowrap">${esc(r.date)}</td>
      <td class="rc-sag">${esc(r.count)}</td>
      <td class="rc-sag">${esc(Number(r.toplam_kredi).toLocaleString('tr-TR'))}</td>
      <td class="sm-mesaj">${esc(r.msgdetails)}</td>
      <td><span class="rc-rozet rc-rozet-${esc(r.durum_renk)}">${esc(r.durum_etiket)}</span></td>
      <td>${r.id ? `<button type="button" class="rc-islem" data-detay="${esc(r.id)}"><i class="bi bi-list-ul"></i> Detay</button>` : '—'}</td>
    </tr>`,
    kaynak: (p) => {
      const istek = ctx.oku('/raporlar', { ...p, tur, ...(yenile ? { yenile: 1 } : {}) });
      yenile = false;
      return istek;
    },
    cizildi: (_, veri) => {
      const u = $('#rpUyari');
      u.hidden = !veri?.uyari;
      u.textContent = veri?.uyari ? `Rapor alınamadı: ${veri.uyari}` : '';
    },
  });

  $('#rpTurler').addEventListener('click', (e) => {
    const b = e.target.closest('[data-tur]');
    if (!b || b.dataset.tur === tur) return;
    tur = b.dataset.tur;
    turCiz();
    tablo.yenile();
  });
  $('#rpYenile').addEventListener('click', () => { yenile = true; tablo.yenile(); });
  $('#rpTablo').addEventListener('click', (e) => {
    const b = e.target.closest('[data-detay]');
    if (b) detayAc(ctx, b.dataset.detay);
  });

  turCiz();
  tablo.yenile();
}

async function detayAc(ctx, paket) {
  const ov = modalAc('SMS Gönderim Detayı', '<div id="rdTablo"><div class="sm-bos"><span class="rc-yukleniyor"></span> Alıcılar getiriliyor…</div></div>',
    '<button class="rc-btn rc-btn-ikincil" data-kapat>Kapat</button>', { ikon: 'list-ul', genis: true });
  ov.querySelector('[data-kapat]').onclick = modalKapat;

  const r = await ctx.oku(`/raporlar/${encodeURIComponent(paket)}`);
  const kap = ov.querySelector('#rdTablo');
  if (!kap) return;
  const v = r.govde?.veri;
  if (!r.ok || !r.govde?.basarili || v.uyari) {
    kap.innerHTML = `<div class="sm-bos sm-hata">${esc(v?.uyari || r.govde?.mesaj || 'Detay alınamadı')}</div>`;
    if (!r.ok) mesaj(r.govde?.mesaj || 'Detay alınamadı', 'hata');
    return;
  }
  const t = veriTablosu(kap, {
    anahtar: 'sms_rapor_detay',
    birim: 'alıcı',
    bosMesaj: 'Bu paket için alıcı kaydı bulunamadı.',
    araIpucu: 'Müşteri veya telefon ara…',
    kolonlar: [
      { baslik: 'Müşteri', sirala: 'ad', deger: (k) => k.ad },
      { baslik: 'Telefon', sirala: 'telefon', deger: (k) => k.telefon },
      { baslik: 'Operatör', sirala: 'operator', deger: (k) => k.operator },
      { baslik: 'Durum', sirala: 'durum', deger: (k) => k.durum },
      { baslik: 'İletim Tarihi' },
    ],
    araMetni: (k) => `${k.ad} ${k.telefon}`,
    satir: (k) => `<tr>
      <td>${k.ad ? esc(k.ad) : '<span class="sm-soluk">Kayıtlı Değil</span>'}</td>
      <td class="sm-nowrap">${esc(k.telefon)}</td><td>${esc(k.operator)}</td><td>${esc(k.durum)}</td><td class="sm-nowrap">${esc(k.iletim_tarihi)}</td>
    </tr>`,
  });
  t.kayitlarAyarla(v.kayitlar);
}
