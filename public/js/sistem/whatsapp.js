/** WhatsApp Yonetim: Ozet, Salonlar, Mesaj Loglari, Grafik + alici, mesaj, paket, kontor modallari. Veri /api/v2/sistem/whatsapp-yonetim. */
import { el, esc, mesaj, onay } from '/js/lib/v2-ortak.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=8';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';
import { yiginCubuk, RENK } from '/js/lib/v2-grafik.js?v=7';
import { ekran, syApi, syVeri, syYaz, syIndir, tarih, tarihSaat, tarihSecici, chartYukle } from '/js/lib/v2-sistem.js?v=7';
import { metrik, hesabinaGir } from '/js/sistem/ortak.js?v=1';

const KOK = '/whatsapp-yonetim';
const YENILEME_MS = 30000;

const rozet = (renk, metin) => `<span class="rc-rozet rc-rozet-${esc(renk || 'gri')}">${esc(metin)}</span>`;

let sec = null;   // /secenekler: sozlukler + izinler
let sonOzet = null;
let aktifSekme = 'ozet';
const tablolar = {};
const logTarih = {};

async function baslat() {
  sec = await syVeri(`${KOK}/secenekler`);
  if (!sec) return;
  ekran().innerHTML = iskelet();
  sekmeBagla();
  tablolariKur();
  el('#waYenile').addEventListener('click', yenile);
  ozetYukle();
  setInterval(() => { if (aktifSekme === 'ozet' && !document.hidden) ozetYukle(); }, YENILEME_MS);
}

function iskelet() {
  const secenek = (l, bos) => `<option value="">${bos}</option>` + l.map((o) => `<option value="${esc(o.kod)}">${esc(o.ad)}</option>`).join('');
  return `
    <div class="sy-page-head">
      <div><h2><span class="mdi mdi-whatsapp wa-ikon"></span> WhatsApp Yönetim</h2>
        <div class="subtitle">Tüm salonların WhatsApp bağlantı durumu, mesaj logları, alıcı geçmişi</div></div>
      <div class="sy-flex-row"><span id="waSon" class="sy-text-muted sy-fs-12"></span>
        <button class="sy-btn" id="waYenile"><span class="mdi mdi-refresh"></span> Yenile</button></div>
    </div>
    <div class="wa-tabs">
      <div class="wa-tab active" data-sekme="ozet"><span class="mdi mdi-view-dashboard"></span> Özet</div>
      <div class="wa-tab" data-sekme="salonlar"><span class="mdi mdi-store"></span> Salonlar</div>
      ${sec.izinler.log ? '<div class="wa-tab" data-sekme="loglar"><span class="mdi mdi-message-text"></span> Mesaj Logları</div>' : ''}
      <div class="wa-tab" data-sekme="grafik"><span class="mdi mdi-chart-bar"></span> Grafik</div>
    </div>
    <div class="wa-section active" data-bolum="ozet">
      <div class="sy-metric-grid" id="waKartlar"></div>
      <div class="sy-grid-2">
        <div class="sy-card"><div class="sy-card-head"><h3>Mesaj Türü Dağılımı (son 30 gün)</h3></div><div class="sy-card-body tight" id="waTip"></div></div>
        <div class="sy-card"><div class="sy-card-head"><h3>Top 10 Salon (son 30 gün)</h3></div><div class="sy-card-body tight" id="waTop"></div></div>
      </div>
    </div>
    <div class="wa-section" data-bolum="salonlar">
      <div class="sy-filters"><div class="sy-form-group wa-dar"><label>Durum</label>
        <select id="waSalonDurum" class="sy-select" data-arama="yok">${secenek(sec.salon_durumlari, 'Tümü')}</select></div></div>
      <div class="sy-card"><div class="sy-card-body tight" id="waSalonlar"></div></div>
    </div>
    ${sec.izinler.log ? `<div class="wa-section" data-bolum="loglar">
      <div class="sy-filters">
        <div class="sy-form-group wa-dar"><label>Salon ID</label><input type="number" id="waLogSalon" class="sy-input" placeholder="Tümü" min="1"></div>
        <div class="sy-form-group wa-dar"><label>Durum</label><select id="waLogDurum" class="sy-select" data-arama="yok">${secenek(sec.log_durumlari, 'Tümü')}</select></div>
        <div class="sy-form-group wa-dar"><label>Telefon</label><input type="text" id="waLogTelefon" class="sy-input" placeholder="905..."></div>
        <div class="sy-form-group wa-dar"><label>Başlangıç</label><input type="text" id="waLogBas" class="sy-input" autocomplete="off"></div>
        <div class="sy-form-group wa-dar"><label>Bitiş</label><input type="text" id="waLogBit" class="sy-input" autocomplete="off"></div>
        <button class="sy-btn sy-btn-primary" id="waLogUygula"><span class="mdi mdi-magnify"></span> Filtrele</button>
        <button class="sy-btn" id="waLogSifirla">Sıfırla</button>
        <button class="sy-btn" id="waLogCsv"><span class="mdi mdi-file-download"></span> Excel</button>
      </div>
      <div class="sy-card"><div class="sy-card-body tight" id="waLoglar"></div></div>
    </div>` : ''}
    <div class="wa-section" data-bolum="grafik">
      <div class="sy-card"><div class="sy-card-head"><h3>Son 30 Gün — Günlük Mesaj Hacmi</h3></div>
        <div class="sy-card-body"><div class="wa-grafik"><canvas id="waGrafik"></canvas></div></div></div>
    </div>`;
}

function sekmeBagla() {
  ekran().querySelector('.wa-tabs').addEventListener('click', (e) => {
    const t = e.target.closest('[data-sekme]');
    if (t) sekmeAc(t.dataset.sekme);
  });
}

function sekmeAc(ad) {
  aktifSekme = ad;
  ekran().querySelectorAll('[data-sekme]').forEach((t) => t.classList.toggle('active', t.dataset.sekme === ad));
  ekran().querySelectorAll('[data-bolum]').forEach((b) => b.classList.toggle('active', b.dataset.bolum === ad));
  sekmeYukle();
}

function sekmeYukle() {
  if (aktifSekme === 'ozet') ozetYukle();
  if (aktifSekme === 'salonlar') tablolar.salon.yenile(false);
  if (aktifSekme === 'loglar') tablolar.log.yenile(false);
  if (aktifSekme === 'grafik') grafikCiz();
}

function yenile() {
  sonOzet = null;
  sekmeYukle();
}

function tablolariKur() {
  tablolar.tip = veriTablosu(el('#waTip'), {
    anahtar: 'sy-wa-tip', birim: 'tür', bosMesaj: 'Veri yok', araIpucu: 'Tür…', araMetni: (t) => t.tip, sirala: { alan: 'toplam', yon: 'desc' },
    kolonlar: [{ baslik: 'Tür' }, { baslik: 'Toplam', sirala: 'toplam', deger: (t) => t.toplam }, { baslik: 'Başarı' }, { baslik: 'Fail' }, { baslik: 'SMS↓' }],
    satir: (t) => `<tr><td><strong>${esc(t.tip)}</strong></td><td>${t.toplam}</td><td>${rozet('yesil', t.basari)}</td><td>${rozet('kirmizi', t.fail)}</td><td>${rozet('turuncu', t.fallback)}</td></tr>`,
  });
  tablolar.top = veriTablosu(el('#waTop'), {
    anahtar: 'sy-wa-top', birim: 'salon', bosMesaj: 'Veri yok', araIpucu: 'Salon…', araMetni: (s) => s.salon_adi,
    kolonlar: [{ baslik: '#' }, { baslik: 'Salon' }, { baslik: 'Mesaj' }],
    satir: (s) => `<tr><td>${s.sira}</td><td><strong>${esc(s.salon_adi)}</strong></td><td>${s.adet}</td></tr>`,
  });

  tablolar.salon = veriTablosu(el('#waSalonlar'), {
    anahtar: 'sy-wa-salon', birim: 'salon', varsayilanBoy: 25, araIpucu: 'Salon adı, numara…', bosMesaj: 'Eşleşme yok',
    kolonlar: [{ baslik: 'ID', sirala: 'id' }, { baslik: 'Salon', sirala: 'salon' }, { baslik: 'Sağlayıcı' }, { baslik: 'Numara' }, { baslik: 'Durum', sirala: 'durum' },
      { baslik: 'Limit', sirala: 'limit' }, { baslik: 'Bugün' }, { baslik: 'Hafta' }, { baslik: 'Bağlandı' }, { baslik: 'Son Hata' }, { baslik: 'İşlem', sinif: 'rc-sag' }],
    satir: salonSatir,
    kaynak: (p) => syApi('GET', `${KOK}/salonlar`, { params: { ...p, durum: el('#waSalonDurum').value } }),
  });
  el('#waSalonDurum').addEventListener('change', () => tablolar.salon.yenile());
  el('#waSalonlar').addEventListener('click', salonAksiyon);

  if (sec.izinler.log) logKur();
}

function salonSatir(r) {
  const b = (aksiyon, baslik, ikon, sinif = '', ek = '') => `<button class="sy-btn sy-btn-sm ${sinif}" data-aksiyon="${aksiyon}" data-id="${r.id}" data-ad="${esc(r.salon_adi)}" data-kontor="${r.kontor}" data-yol="${esc(r.yonet_yol)}" title="${esc(baslik)}"><span class="mdi ${ikon}"></span>${ek}</button>`;
  const i = sec.izinler;
  return `<tr>
    <td>${r.id}</td><td><strong>${esc(r.salon_adi)}</strong></td>
    <td><span class="sy-badge wa-saglayici-${esc(r.saglayici.kod)}">${esc(r.saglayici.ad)}</span></td>
    <td>${esc(r.numara || '—')}</td><td>${rozet(r.durum_renk, r.durum_ad)}</td><td>${r.gunluk_limit}</td>
    <td>${r.bugun_toplam} <span class="sy-text-muted sy-fs-12">(✓${r.bugun_basari} / ✗${r.bugun_fail} / ⤵${r.bugun_fallback})</span></td>
    <td>${r.hafta_toplam}</td><td class="sy-text-muted sy-fs-12">${esc(r.baglanti_tarihi ? tarihSaat(r.baglanti_tarihi) : '—')}</td>
    <td class="wa-hata">${esc(r.son_hata || '')}</td>
    <td class="nowrap sy-text-right wa-islemler">
      ${i.hesaba_gir ? b('yonet', 'Salon hesabına girip yönet', 'mdi-cog', 'sy-btn-primary') : ''}
      ${b('paket', 'Paket / Deneme', 'mdi-gift', 'wa-btn-paket')}
      ${i.para ? b('kontor', `Kontör bakiyesi: ${r.kontor} — Yükle`, 'mdi-wallet', 'wa-btn-kontor', ` ${r.kontor}`) : `<span class="sy-badge sy-badge-muted" title="Kontör bakiyesi"><span class="mdi mdi-wallet"></span> ${r.kontor}</span>`}
      ${i.log ? b('aliciler', 'Alıcılar', 'mdi-account-multiple', 'sy-btn-soft') : ''}
      ${i.log ? b('loglar', 'Logları gör', 'mdi-message') : ''}
    </td></tr>`;
}

function salonAksiyon(e) {
  const b = e.target.closest('button[data-aksiyon]');
  if (!b) return;
  const { aksiyon, id, ad, kontor, yol } = b.dataset;
  if (aksiyon === 'yonet') hesabaGir(id, ad, yol);
  if (aksiyon === 'paket') paketModal(id, ad);
  if (aksiyon === 'kontor') kontorModal(id, ad, kontor);
  if (aksiyon === 'aliciler') aliciModal(id, ad);
  if (aksiyon === 'loglar') {
    el('#waLogSalon').value = id;
    sekmeAc('loglar');
  }
}

async function hesabaGir(id, ad, yol) {
  if (!(await onay(`“${ad}” hesabına girilecek ve bu giriş kayda alınacak.`, { baslik: 'Salon Hesabına Gir', tamam: 'Gir', tehlike: false }))) return;
  await hesabinaGir(id, 'WhatsApp yönetimi', { hedef: yol });
}

// ───────── ÖZET ─────────

async function ozetYukle() {
  const d = await syVeri(`${KOK}/ozet`);
  if (!d) return;
  sonOzet = d;
  const basari = d.basariOrani >= 90 ? 'success' : (d.basariOrani >= 70 ? 'warning' : 'danger');
  el('#waKartlar').innerHTML = [
    metrik({ etiket: 'Aktif Bağlı Salon', deger: d.aktif_salon, ikon: 'mdi-whatsapp', renk: 'success' }),
    metrik({ etiket: 'Ban / Risk', deger: d.ban_risk_salon, ikon: 'mdi-alert', renk: d.ban_risk_salon > 0 ? 'danger' : '', alt: 'otomatik durdurulmuş' }),
    metrik({ etiket: 'Bugün Toplam', deger: d.bugun.toplam, ikon: 'mdi-message-arrow-right', renk: 'info', alt: `✓${d.bugun.basari} · ✗${d.bugun.fail} · ⤵${d.bugun.fallback}` }),
    metrik({ etiket: '7 Gün', deger: d.hafta.toplam, ikon: 'mdi-calendar-week', renk: '', alt: `✓${d.hafta.basari}` }),
    metrik({ etiket: '30 Gün', deger: d.ay.toplam, ikon: 'mdi-calendar-month', renk: '', alt: `✓${d.ay.basari}` }),
    metrik({ etiket: 'Haftalık Başarı', deger: `%${d.basariOrani}`, ikon: 'mdi-check-decagram', renk: basari }),
  ].join('');
  el('#waSon').textContent = `Son güncelleme: ${new Date().toLocaleTimeString('tr-TR')}`;

  const g = await syVeri(`${KOK}/dagilim`, { gun: 30 });
  if (!g) return;
  tablolar.tip.kayitlarAyarla(g.tipler);
  tablolar.top.kayitlarAyarla(g.en_cok.map((s, i) => ({ ...s, sira: i + 1 })));
}

// ───────── LOGLAR ─────────

function logFiltre() {
  return {
    salon_id: el('#waLogSalon').value, durum: el('#waLogDurum').value, telefon: el('#waLogTelefon').value.trim(),
    baslangic: el('#waLogBas').value, bitis: el('#waLogBit').value,
  };
}

function logKur() {
  tablolar.log = veriTablosu(el('#waLoglar'), {
    anahtar: 'sy-wa-log', birim: 'mesaj', varsayilanBoy: 50, araIpucu: 'Mesaj içinde, telefon…', bosMesaj: 'Kayıt bulunamadı', sirala: { alan: 'id', yon: 'desc' },
    kolonlar: [{ baslik: 'ID', sirala: 'id' }, { baslik: 'Tarih' }, { baslik: 'Salon' }, { baslik: 'Müşteri' }, { baslik: 'Telefon' }, { baslik: 'Durum', sirala: 'durum' }, { baslik: 'Mesaj' }, { baslik: 'Hata' }],
    satir: (r) => `<tr class="wa-satir" data-log="${r.id}">
      <td>${r.id}</td><td class="sy-text-muted sy-fs-12">${esc(tarihSaat(r.created_at))}</td><td>${esc(r.salon_adi)}</td><td>${esc(r.musteri_adi || '—')}</td>
      <td>${esc(r.telefon)}</td><td>${rozet(r.durum_renk, r.durum_ad)}</td>
      <td><div class="wa-mesaj-kisa" title="${esc(r.mesaj)}">${esc(r.mesaj || '')}</div></td><td class="wa-hata">${esc(r.hata || '')}</td></tr>`,
    kaynak: (p) => syApi('GET', `${KOK}/loglar`, { params: { ...p, ...logFiltre() } }),
  });
  logTarih.bas = tarihSecici(el('#waLogBas'));
  logTarih.bit = tarihSecici(el('#waLogBit'));
  el('#waLoglar').addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-log]');
    if (tr) mesajModal(tr.dataset.log);
  });
  el('#waLogUygula').addEventListener('click', () => tablolar.log.yenile());
  el('#waLogSifirla').addEventListener('click', () => {
    ['#waLogSalon', '#waLogDurum', '#waLogTelefon'].forEach((s) => { el(s).value = ''; });
    logTarih.bas?.clear();
    logTarih.bit?.clear();
    tablolar.log.yenile();
  });
  el('#waLogCsv').addEventListener('click', () => {
    syIndir(`${KOK}/loglar/csv`, { ...logFiltre(), ara: tablolar.log.durum.ara }, `whatsapp_loglari_${Date.now()}.csv`);
  });
}

async function mesajModal(id) {
  const ov = modalAc('Mesaj Detayı', '<span class="rc-yukleniyor"></span> Yükleniyor', '', { ikon: 'chat-text' });
  const l = await syVeri(`${KOK}/mesaj/${id}`);
  if (!l || !ov.isConnected) return;
  const satirlar = [
    ['ID', l.id], ['Tarih', tarihSaat(l.created_at)], ['Salon', `${l.salon_adi} (#${l.salon_id})`], ['Müşteri', l.musteri_adi || '—'],
    ['Randevu ID', l.randevu_id || '—'], ['Telefon', l.telefon], ['Durum', l.durum_ad], ['Hata', l.hata || '—'],
    ['Mesaj ID', l.mesaj_id || '—'], ['Gönderim Tarihi', tarihSaat(l.gonderim_tarihi)],
  ];
  el('.rc-modal-govde', ov).innerHTML = `<table class="sy-table">${satirlar.map(([a, v]) => `<tr><td class="sy-text-muted wa-detay-ad">${a}</td><td><strong>${esc(v)}</strong></td></tr>`).join('')}</table>
    <h5 class="wa-detay-baslik">Mesaj İçeriği</h5><div class="wa-mesaj-tam">${esc(l.mesaj)}</div>`;
}

// ───────── ALICILAR ─────────

function aliciModal(salonId, salonAdi) {
  const ov = modalAc(`${salonAdi} — Alıcılar`, '<div class="sy-text-muted wa-alici-bilgi" id="waAliciBilgi"></div><div id="waAliciTablo"></div>', '', { ikon: 'people', genis: true });
  const kap = el('#waAliciTablo', ov);
  veriTablosu(kap, {
    anahtar: 'sy-wa-alici', birim: 'alıcı', varsayilanBoy: 25, araIpucu: 'Müşteri, telefon…', bosMesaj: 'Bu salon henüz mesaj atmamış', sirala: { alan: 'son', yon: 'desc' },
    kolonlar: [{ baslik: 'Müşteri', sirala: 'musteri' }, { baslik: 'Telefon', sirala: 'telefon' }, { baslik: 'Toplam', sirala: 'toplam' }, { baslik: 'Durum' }, { baslik: 'İlk', sirala: 'ilk' }, { baslik: 'Son', sirala: 'son' }, { baslik: '' }],
    satir: (r) => `<tr><td><strong>${esc(r.musteri_adi || '—')}</strong></td><td>${esc(r.telefon)}</td><td>${r.toplam}</td>
      <td>${rozet('yesil', `✓${r.basari}`)} ${rozet('kirmizi', `✗${r.fail}`)} ${rozet('turuncu', `⤵${r.fallback}`)}</td>
      <td class="sy-text-muted sy-fs-12">${esc(tarihSaat(r.ilk_mesaj))}</td><td class="sy-text-muted sy-fs-12">${esc(tarihSaat(r.son_mesaj))}</td>
      <td><button class="sy-btn sy-btn-sm sy-btn-soft" data-gecmis="${r.id}" title="Mesaj geçmişi"><span class="mdi mdi-history"></span></button></td></tr>`,
    kaynak: (p) => syApi('GET', `${KOK}/salon/${salonId}/aliciler`, { params: p }),
    cizildi: (_, v) => {
      el('#waAliciBilgi', ov).innerHTML = `<strong>${v.total}</strong> farklı alıcıya mesaj gönderilmiş.${v.salon?.whatsapp_numara ? ` Gönderen: <strong>${esc(v.salon.whatsapp_numara)}</strong>` : ''}`;
    },
  }).yenile();
  kap.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-gecmis]');
    if (b) gecmisModal(salonId, salonAdi, b.dataset.gecmis);
  });
}

/** Tek modal kurali: gecmis acilinca alici listesi kapanir, "Alıcılara Dön" geri getirir. */
async function gecmisModal(salonId, salonAdi, logId) {
  const ov = modalAc('Mesaj Geçmişi', '<span class="rc-yukleniyor"></span> Yükleniyor', '<button class="rc-btn rc-btn-ikincil" id="waGecmisDon">Alıcılara Dön</button>', { ikon: 'clock-history' });
  el('#waGecmisDon', ov).onclick = () => aliciModal(salonId, salonAdi);
  const d = await syVeri(`${KOK}/salon/${salonId}/aliciler/${logId}/gecmis`);
  if (!d || !ov.isConnected) return;
  el('h2', ov).textContent = `${d.musteri_adi || d.telefon} — Mesaj Geçmişi`;
  el('.rc-modal-govde', ov).innerHTML = d.data.length
    ? `<div class="wa-gecmis">${d.data.map((r) => {
      const durum = sec.log_durumlari.find((x) => x.kod === r.durum);
      return `<div class="wa-gecmis-oge"><div class="wa-gecmis-bas"><span><strong>${esc(tarihSaat(r.created_at))}</strong>${r.randevu_id ? ` · Randevu #${r.randevu_id}` : ''}</span>${rozet(durum?.renk, durum?.ad ?? r.durum)}</div>
        <div class="wa-gecmis-metin">${esc(r.mesaj)}</div>${r.hata ? `<div class="wa-hata">Hata: ${esc(r.hata)}</div>` : ''}</div>`;
    }).join('')}</div>`
    : '<div class="sy-empty"><div class="baslik">Mesaj yok</div></div>';
}

// ───────── KONTÖR ─────────

function kontorModal(salonId, salonAdi, mevcut) {
  const alt = '<button class="rc-btn rc-btn-ikincil" id="waKontorVazgec">Vazgeç</button><button class="rc-btn rc-btn-birincil" id="waKontorKaydet"><i class="bi bi-check-lg"></i> İşle</button>';
  const ov = modalAc(`${salonAdi} — Kontör`, `
    <div class="rc-alan"><label>Yüklenecek kontör</label><input type="number" id="waKontorAdet" step="1" placeholder="Örn: 40000 · Düşmek için negatif: -1000"></div>
    <div class="rc-ipucu">Mevcut bakiye: <strong>${esc(mevcut || 0)}</strong></div>`, alt, { ikon: 'wallet2', dar: true });
  el('#waKontorVazgec', ov).onclick = modalKapat;
  el('#waKontorAdet', ov).focus();
  el('#waKontorKaydet', ov).onclick = async (e) => {
    const adet = parseInt(el('#waKontorAdet', ov).value, 10);
    if (!adet) return mesaj('Geçersiz miktar.', 'hata');
    e.currentTarget.disabled = true;
    const b = e.currentTarget;
    const { tamam } = await syYaz('POST', `${KOK}/salon/${salonId}/kontor`, { adet, aciklama: 'panel manuel' });
    if (tamam) { modalKapat(); tablolar.salon.yenile(false); } else b.disabled = false;
    return undefined;
  };
}

// ───────── PAKET ─────────

async function paketModal(salonId, salonAdi) {
  const ov = modalAc(`${salonAdi} — Paket Yönetimi`, '<span class="rc-yukleniyor"></span> Yükleniyor', '', { ikon: 'gift' });
  const d = await syVeri(`${KOK}/salon/${salonId}/paket`);
  if (!d || !ov.isConnected) return;
  const govde = el('.rc-modal-govde', ov);
  govde.innerHTML = paketKart(d) + (sec.izinler.para ? paketAksiyon(d) : '');
  if (sec.izinler.para) paketBagla(govde, salonId, salonAdi);
}

function paketKart(d) {
  const kalanRenk = d.kalan_gun !== null && d.kalan_gun <= 7 ? 'wa-kalan-az' : 'wa-kalan-iyi';
  return `<div class="wa-paket-kart">
    <div class="sy-text-muted sy-fs-12">Mevcut Paket</div>
    <div class="wa-paket-ad">${esc(d.paket_ad)}${d.deneme ? ' <span class="sy-badge wa-deneme-rozet">DENEME</span>' : ''}</div>
    ${d.baslangic ? `<div class="sy-fs-13"><span class="mdi mdi-calendar-start"></span> Başlangıç: <strong>${esc(tarih(d.baslangic))}</strong></div>` : ''}
    ${d.bitis ? `<div class="sy-fs-13"><span class="mdi mdi-calendar-end"></span> Bitiş: <strong>${esc(tarih(d.bitis))}</strong>${d.kalan_gun !== null ? ` · <span class="${kalanRenk}">${d.kalan_gun} gün kaldı</span>` : ''}</div>` : ''}
    ${d.periyot_ad ? `<div class="sy-fs-13"><span class="mdi mdi-clock-outline"></span> Periyot: <strong>${esc(d.periyot_ad)}</strong></div>` : ''}
  </div>`;
}

function paketAksiyon(d) {
  const de = sec.deneme;
  const secilir = sec.paketler.filter((p) => p.secilir);
  const seciliPaket = (secilir.find((p) => p.kod === d.paket) ?? secilir.find((p) => p.ad === d.paket_ad) ?? secilir[0]).kod;
  const denemeBolumu = d.deneme
    ? '<button class="sy-btn sy-btn-danger wa-tam" id="waDenemeIptal"><span class="mdi mdi-close-circle"></span> Denemeyi İptal Et (Başlangıç paketine düşür)</button>'
    : `<div class="wa-deneme-kutu"><div class="wa-deneme-baslik">Ücretsiz Deneme Tanımla</div>
        <div class="sy-text-muted sy-fs-12">Salon, seçtiğin paketle X gün boyunca ücretsiz kullanır. Süre dolunca Başlangıç paketine düşer (manuel iptal gerekir).</div></div>
      <div class="wa-iki"><input type="number" id="waDenemeGun" value="${de.gun}" min="${de.min}" max="${de.maks}" class="sy-input" placeholder="Gün">
        <button class="sy-btn sy-btn-primary" id="waDenemeVer"><span class="mdi mdi-gift"></span> Deneme Ver</button></div>
      <div class="wa-hizli">${de.hizli.map((h) => `<button class="sy-btn sy-btn-sm${h.onerilen ? ' sy-btn-soft' : ''}" data-gun="${h.gun}">${esc(h.etiket)}</button>`).join('')}</div>`;
  return `${denemeBolumu}
    <details class="wa-manuel"><summary><span class="mdi mdi-cog"></span> Manuel Paket Ayarla (ödeme onayı sonrası)</summary>
      <div class="wa-iki wa-manuel-alan">
        <select id="waPaket" class="sy-select" data-arama="yok">${secilir.map((p) => `<option value="${esc(p.kod)}"${p.kod === seciliPaket ? ' selected' : ''}>${esc(p.secim_ad)}</option>`).join('')}</select>
        <select id="waPeriyot" class="sy-select" data-arama="yok">${sec.periyotlar.map((p) => `<option value="${esc(p.kod)}"${p.kod === d.periyot ? ' selected' : ''}>${esc(p.ad)}</option>`).join('')}</select>
        <input type="text" id="waPaketBas" value="${esc(d.baslangic || '')}" class="sy-input" placeholder="Başlangıç" autocomplete="off">
        <input type="text" id="waPaketBit" value="${esc(d.bitis || '')}" class="sy-input" placeholder="Bitiş" autocomplete="off">
      </div>
      <button class="sy-btn sy-btn-primary wa-tam" id="waPaketKaydet"><span class="mdi mdi-content-save"></span> Kaydet</button>
    </details>`;
}

function paketBagla(k, salonId, salonAdi) {
  k.querySelectorAll('button[data-gun]').forEach((b) => {
    b.addEventListener('click', () => { el('#waDenemeGun', k).value = b.dataset.gun; });
  });
  tarihSecici(el('#waPaketBas', k));
  tarihSecici(el('#waPaketBit', k));
  const ver = el('#waDenemeVer', k);
  if (ver) {
    ver.addEventListener('click', async () => {
      const { tamam } = await syYaz('POST', `${KOK}/salon/${salonId}/deneme`, { paket: sec.deneme.paket, gun: el('#waDenemeGun', k).value });
      if (tamam) { paketModal(salonId, salonAdi); tablolar.salon.yenile(false); }
    });
  }
  const iptal = el('#waDenemeIptal', k);
  if (iptal) {
    iptal.addEventListener('click', async () => {
      if (!(await onay('Denemeyi iptal et — salon Başlangıç paketine düşürülecek. Emin misin?', { tehlike: true }))) return;
      const { tamam } = await syYaz('POST', `${KOK}/salon/${salonId}/deneme/iptal`);
      if (tamam) { modalKapat(); tablolar.salon.yenile(false); }
    });
  }
  el('#waPaketKaydet', k).addEventListener('click', async () => {
    const { tamam } = await syYaz('PUT', `${KOK}/salon/${salonId}/paket`, {
      paket: el('#waPaket', k).value, periyot: el('#waPeriyot', k).value,
      baslangic: el('#waPaketBas', k).value || null, bitis: el('#waPaketBit', k).value || null,
    });
    if (tamam) { modalKapat(); tablolar.salon.yenile(false); }
  });
}

// ───────── GRAFİK ─────────

async function grafikCiz() {
  const d = sonOzet ?? await syVeri(`${KOK}/ozet`);
  if (!d) return;
  sonOzet = d;
  try { await chartYukle(); } catch (_) { mesaj('Grafik kütüphanesi yüklenemedi.', 'hata'); return; }
  yiginCubuk(el('#waGrafik'), d.gunler.map((g) => g.gun.substring(5)), [
    { ad: 'Başarılı', veri: d.gunler.map((g) => g.basari), renk: RENK.yesil },
    { ad: 'Başarısız', veri: d.gunler.map((g) => g.fail), renk: RENK.gul },
    { ad: "SMS'e Düştü", veri: d.gunler.map((g) => g.fallback), renk: RENK.kehribar },
  ]);
}

baslat();
