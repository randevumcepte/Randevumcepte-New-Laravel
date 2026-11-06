/** AI Kredi & Kullanim: donem filtresi, ozet kartlari, kredi yukleme ve alarm, tur/salon/gunluk tablolari. Veri /api/v2/sistem/ai-kredi. */
import { el, esc, onay } from '/js/lib/v2-ortak.js?v=2';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=2';
import { ekran, syApi, syVeri, syYaz, para, tarih, tarihSaat } from '/js/lib/v2-sistem.js?v=1';
import { sayi } from '/js/sistem/ortak.js?v=1';

const VARSAYILAN_GUN = 30;

let gun = VARSAYILAN_GUN;
let kur = 40;
const tablolar = {};

const usd = (v) => `$${para(v, Number(v) < 1 ? 4 : 2)}`;
const tl = (v) => `${para(Number(v) * kur)} ₺`;
const kart = (etiket, deger, alt = '', sinif = '') => `<div class="ai-kart ${sinif}"><div class="e">${etiket}</div><div class="d">${deger}</div>${alt ? `<div class="alt">${alt}</div>` : ''}</div>`;

function kartlar(d) {
  const o = d.ozet, a = d.ayar;
  const kalanKart = d.kredi.girildi
    ? kart('Kalan Kredi', usd(d.kalan_usd), `${tl(d.kalan_usd)} · yükledin ${usd(a.yuklenen_usd)}`, d.kredi.kritik ? 'kotu' : 'iyi')
    : kart('Kalan Kredi', '<span class="ai-girilmedi">Kredi girilmedi</span>', 'Aşağıdan yüklediğin krediyi gir ↓');
  return kalanKart
    + kart('Yüklenen Kredi', usd(a.yuklenen_usd), `Tüm zaman harcama: ${usd(d.tum_harcama_usd)}`)
    + kart('Harcama (seçili dönem)', usd(o.maliyet_usd), tl(o.maliyet_usd))
    + kart('Bugün', usd(o.bugun_usd), tl(o.bugun_usd))
    + kart('Toplam Çağrı', sayi(o.cagri), `${sayi(o.gercek)} AI · ${sayi(o.cache)} cache`)
    + kart('Cache Tasarrufu', usd(o.cache_tasarruf_usd), `${sayi(o.cache)} çağrı bedava geldi`, 'iyi')
    + kart('Ort. Maliyet / Çağrı', usd(o.ort_usd), `≈ ${tl(o.ort_usd)}`)
    + kart('Token (dönem)', `<span class="ai-token">${sayi(o.girdi)} <small>girdi</small></span>`, `${sayi(o.cikti)} çıktı`);
}

function uyarilar(d) {
  const a = d.ayar;
  return (d.tablo_yok ? '<div class="ai-uyari"><b>ai_kullanim</b> tablosu henüz yok. Eski monolitte migration çalıştırılınca loglama başlar.</div>' : '')
    + (d.kredi.azaldi ? `<div class="ai-uyari ai-uyari-kirmizi"><b>AI kredin azaldı!</b> Kalan ${usd(d.kalan_usd)} (eşik ${usd(a.esik_usd)}). Düşük-kredi WhatsApp alarmı otomatik gönderilir. Aşağıdan kredi ekleyin.</div>` : '');
}

function krediPaneli(d) {
  const a = d.ayar;
  return `<div class="ai-panel">
    <h2>Kredi Yükleme &amp; Alarm</h2>
    <div class="ai-not">Anthropic bakiyeyi API'den vermez; buraya <b>yüklediğin toplam krediyi</b> (USD) gir. Kalan = yüklenen − tüm zaman harcama.
      Kalan, <b>eşiğin</b> altına inince <b>otomatik WhatsApp + SMS alarmı</b> gider (günde 1 kez). Son güncelleme: ${esc(a.guncelleme || '—')}</div>
    <div class="ai-form">
      <div><label>Toplam yüklenen kredi (USD)</label><input type="number" step="0.01" id="aiYuklenen" value="${esc(a.yuklenen_usd)}"></div>
      <div><label>USD → TL kuru</label><input type="number" step="0.01" id="aiKur" value="${esc(a.kur)}"></div>
      <div><label>Alarm eşiği (USD)</label><input type="number" step="0.01" id="aiEsik" value="${esc(a.esik_usd)}"></div>
      <button class="sy-btn sy-btn-primary" id="aiKaydet">Kaydet</button>
    </div>
    <div class="ai-form ai-form-alt">
      <div><label>Yeni yükleme ekle (USD)</label><input type="number" step="0.01" id="aiEklenen" placeholder="ör. 20"></div>
      <button class="sy-btn sy-btn-primary" id="aiEkle">+ Krediye Ekle</button>
      <button class="sy-btn sy-btn-soft" id="aiTest"><span class="mdi mdi-whatsapp"></span> Test alarmı gönder</button>
    </div>
  </div>`;
}

function krediBagla() {
  el('#aiKaydet').addEventListener('click', async () => {
    const { tamam } = await syYaz('PUT', '/ai-kredi/ayar', { yuklenen: el('#aiYuklenen').value, kur: el('#aiKur').value || null, esik: el('#aiEsik').value || null });
    if (tamam) raporYukle();
  });
  el('#aiEkle').addEventListener('click', async () => {
    const { tamam } = await syYaz('POST', '/ai-kredi/ekle', { eklenen: el('#aiEklenen').value });
    if (tamam) raporYukle();
  });
  el('#aiTest').addEventListener('click', async () => {
    if (await onay('Düşük kredi alarmı Sistem WhatsApp + SMS ile hemen gönderilecek.', { baslik: 'Test Alarmı', tamam: 'Gönder', tehlike: false })) syYaz('POST', '/ai-kredi/test-alarm');
  });
}

function tablolariKur() {
  tablolar.tur = veriTablosu(el('#aiTurler'), {
    anahtar: 'sy-ai-tur', birim: 'tür', bosMesaj: 'Bu dönemde AI kullanımı yok.', araIpucu: 'Tür…', araMetni: (t) => t.ad, sirala: { alan: 'maliyet', yon: 'desc' },
    kolonlar: [{ baslik: 'Tür' }, { baslik: 'Çağrı', sinif: 'rc-sag', sirala: 'cagri', deger: (t) => t.cagri }, { baslik: 'Cache', sinif: 'rc-sag' }, { baslik: 'Token', sinif: 'rc-sag' },
      { baslik: 'Maliyet ($)', sinif: 'rc-sag', sirala: 'maliyet', deger: (t) => t.maliyet }, { baslik: 'Maliyet (₺)', sinif: 'rc-sag' }],
    satir: (t) => `<tr><td><span class="ai-rozet" style="background:${esc(t.renk)}"></span>${esc(t.ad)}</td><td class="rc-sag">${sayi(t.cagri)}</td><td class="rc-sag">${sayi(t.cache)}</td>
      <td class="rc-sag">${sayi(t.token)}</td><td class="rc-sag">${usd(t.maliyet)}</td><td class="rc-sag">${tl(t.maliyet)}</td></tr>`,
  });
  tablolar.salon = veriTablosu(el('#aiSalonlar'), {
    anahtar: 'sy-ai-salon', birim: 'salon', varsayilanBoy: 25, araIpucu: 'Salon adı…', bosMesaj: 'Bu dönemde salon kullanımı yok.', sirala: { alan: 'maliyet', yon: 'desc' },
    kolonlar: [{ baslik: 'Salon', sirala: 'salon' }, { baslik: 'Çağrı', sinif: 'rc-sag', sirala: 'cagri' }, { baslik: 'Cache', sinif: 'rc-sag', sirala: 'cache' }, { baslik: 'Token', sinif: 'rc-sag', sirala: 'token' },
      { baslik: 'Maliyet ($)', sinif: 'rc-sag', sirala: 'maliyet' }, { baslik: 'Maliyet (₺)', sinif: 'rc-sag' }, { baslik: 'Son', sinif: 'rc-sag', sirala: 'son' }],
    satir: (s) => `<tr><td>${s.salon_id ? `<a href="/sistemyonetim/salon/${s.salon_id}">${esc(s.salon_adi)}</a>` : esc(s.salon_adi)}</td><td class="rc-sag">${sayi(s.cagri)}</td><td class="rc-sag">${sayi(s.cache)}</td>
      <td class="rc-sag">${sayi(s.token)}</td><td class="rc-sag">${usd(s.maliyet)}</td><td class="rc-sag">${tl(s.maliyet)}</td><td class="rc-sag sy-text-muted sy-fs-12">${esc(s.son ? tarihSaat(s.son) : '—')}</td></tr>`,
    kaynak: (p) => syApi('GET', '/ai-kredi/salonlar', { params: { ...p, gun } }),
  });
  tablolar.gunluk = veriTablosu(el('#aiGunluk'), {
    anahtar: 'sy-ai-gunluk', birim: 'gün', bosMesaj: 'Veri yok.', araIpucu: 'Gün…', araMetni: (g) => tarih(g.gun), varsayilanBoy: 25,
    kolonlar: [{ baslik: 'Gün' }, { baslik: 'Çağrı', sinif: 'rc-sag' }, { baslik: 'Maliyet ($)', sinif: 'rc-sag' }, { baslik: 'Akış', sinif: 'ai-akis' }],
    satir: (g) => `<tr><td>${esc(tarih(g.gun))}</td><td class="rc-sag">${sayi(g.cagri)}</td><td class="rc-sag">${usd(g.maliyet)}</td>
      <td><div class="ai-bar-bg"><div class="ai-bar" style="width:${g.oran}%"></div></div></td></tr>`,
  });
}

async function raporYukle() {
  const d = await syVeri('/ai-kredi', { gun });
  if (!d) return;
  kur = Number(d.kur) || kur;
  if (!el('#aiKartlar')) {
    ekran().innerHTML = `
      <div class="ai-baslik"><h1><span class="mdi mdi-robot-happy"></span> AI Kredi &amp; Kullanım</h1><div class="ai-filtre" id="aiFiltre"></div></div>
      <div id="aiUyarilar"></div><div class="ai-kartlar" id="aiKartlar"></div><div id="aiKredi"></div>
      <div class="ai-panel"><h2>Tür Bazında Dağılım</h2><div id="aiTurler"></div></div>
      <div class="ai-panel"><h2>Salon Bazında Kullanım</h2><div id="aiSalonlar"></div></div>
      <div class="ai-panel"><h2>Günlük Akış (son 30 gün)</h2><div id="aiGunluk"></div></div>`;
    tablolariKur();
    el('#aiFiltre').addEventListener('click', (e) => {
      const b = e.target.closest('button[data-gun]');
      if (!b) return;
      gun = parseInt(b.dataset.gun, 10);
      raporYukle();
    });
  }
  el('#aiFiltre').innerHTML = d.gun_secenekleri.map((g) => `<button type="button" data-gun="${g.gun}" class="${g.gun === d.gun ? 'act' : ''}">${esc(g.ad)}</button>`).join('');
  el('#aiUyarilar').innerHTML = uyarilar(d);
  el('#aiKartlar').innerHTML = kartlar(d);
  if (d.izinler.yaz) {
    el('#aiKredi').innerHTML = krediPaneli(d);
    krediBagla();
  }
  tablolar.tur.kayitlarAyarla(d.turler);
  tablolar.gunluk.kayitlarAyarla(d.gunluk);
  tablolar.salon.yenile();
}

raporYukle();
