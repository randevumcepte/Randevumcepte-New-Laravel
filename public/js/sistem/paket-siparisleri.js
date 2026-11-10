/** Duyuru paketi siparisleri: odenenler elle yuklenip isaretlenir. */
import { el, esc, onay } from '/js/lib/v2-ortak.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=8';
import { ekran, para, syApi, syYaz, tarihSaat } from '/js/lib/v2-sistem.js?v=7';
import { etiketRozet, sayfaBaslik, sayi } from '/js/sistem/ortak.js?v=1';

let yazabilir = false;

const yuklemeHucre = (s) => {
  if (!s.yukleme) return '<span class="sy-text-muted">—</span>';
  return `${etiketRozet(s.yukleme)}${s.yukleme_tarihi ? `<div class="sy-text-muted sy-fs-12">${esc(tarihSaat(s.yukleme_tarihi))}</div>` : ''}`;
};

const islemHucre = (s) => (s.yuklenebilir && yazabilir
  ? `<button type="button" class="sy-btn sy-btn-sm sy-btn-primary" data-yuklendi="${s.id}"><span class="mdi mdi-check"></span> Yüklendi</button>`
  : '<span class="sy-text-muted">—</span>');

function baslat() {
  ekran().innerHTML = `
    ${sayfaBaslik('Duyuru Paketi Siparişleri', 'SMS (duyuru) paketi satın alımları · Ödenenleri VoiceTelekom panelinden yükleyip "Yüklendi" işaretleyin', '<span id="sBekleyen"></span>')}
    <div class="sy-card"><div class="sy-card-body" id="sTablo"></div></div>`;

  const kap = el('#sTablo');
  const tablo = veriTablosu(kap, {
    anahtar: 'sy_paket_siparis',
    varsayilanBoy: 25,
    birim: 'sipariş',
    araIpucu: 'Salon, sipariş no, SMS adedi...',
    bosMesaj: 'Henüz sipariş yok',
    sirala: { alan: 'tarih', yon: 'desc' },
    kolonlar: [
      { baslik: 'Tarih', sirala: 'tarih' }, { baslik: 'Salon', sirala: 'salon' }, { baslik: 'Paket', sirala: 'sms_adet' },
      { baslik: 'Tutar', sirala: 'tutar', sinif: 'rc-sag' }, { baslik: 'Ödeme', sirala: 'odeme' }, { baslik: 'Yükleme', sirala: 'yukleme' },
      { baslik: 'Fatura', sirala: 'fatura' }, { baslik: 'İşlem', sinif: 'rc-sag' },
    ],
    satir: (s) => `<tr>
      <td class="sy-fs-12 rc-nowrap">${esc(tarihSaat(s.tarih))}</td>
      <td class="sy-fs-13">${esc(s.salon_adi)}</td>
      <td class="sy-fw-600">${sayi(s.sms_adet)} SMS</td>
      <td class="rc-sag">${para(s.tutar)} ₺</td>
      <td>${etiketRozet(s.odeme)}</td>
      <td>${yuklemeHucre(s)}</td>
      <td>${etiketRozet(s.fatura)}</td>
      <td class="rc-sag">${islemHucre(s)}</td>
    </tr>`,
    kaynak: async (p) => {
      const r = await syApi('GET', '/paket-siparis', { params: p });
      yazabilir = !!r.govde?.veri?.yazabilir;
      return r;
    },
    cizildi: (_, v) => {
      el('#sBekleyen').innerHTML = v?.bekleyen > 0
        ? `<span class="sy-badge sy-badge-warning" style="font-size:14px;padding:8px 14px"><span class="mdi mdi-bell-alert"></span> ${v.bekleyen} bekleyen yükleme</span>`
        : '';
    },
  });

  kap.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-yuklendi]');
    if (!b) return;
    const tamam = await onay('VoiceTelekom panelinden SMS yüklemesini yaptınız mı?', { baslik: 'Yüklendi İşaretle', tamam: 'Evet, Yüklendi', tehlike: false });
    if (!tamam) return;
    const r = await syYaz('POST', `/paket-siparis/${b.dataset.yuklendi}/yuklendi`);
    if (r.tamam) tablo.yenile(false);
  });
  tablo.yenile();
}

baslat();
