/**
 * Müşteri Detay (v2) — build'siz island (sade ES module).
 *
 * Eski /isletmeyonetim/musteridetay/{id} ekranının v2 karşılığı (tasarım: eski "Modern
 * Tema" başlık kartı + sekmeler). Sekmeler ilk açılışta yüklenir:
 *   Genel Bilgiler  : profil kartı + Randevu Notları zaman çizelgesi (GET /musteri/{id}/randevu-notlari)
 *   Randevular      : GET /musteri/{id}/randevular (sunucu sayfalı)
 *   Vücut Ölçümü    : stüdyo modu — GET/POST /musteri/{id}/olcumler, DELETE /musteri/olcum/{id}
 *   Satışlar        : mevcut GET /satislar?musteri_id= (satır aksiyonları sunucudan)
 *   Seanslar        : ortak seans bileşeni (lib/v2-seans.js) — GET /seans?musteri_id=
 *   Sağlık Bilgileri: PATCH /musteri/{id}/saglik (sorular API'den)
 *   Sözleşmeler/Belgeler: v2 Form Yönetimi ekranı (?musteri_id=) — kopya yok.
 * Görünürlük/izin kararları SUNUCUDAN (`yetkiler`, `ayarlar`); UI yeniden hesaplamaz.
 */
import { qp, api, el, esc, TL, mesaj, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=1';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=2';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { musteriFormuAc, formVerisi, segmentRozeti, tarihTr } from '/js/lib/v2-musteri.js?v=1';
import { seansTakibi } from '/js/lib/v2-seans.js?v=2';

const kok = document.getElementById('rc-musteri-detay');
const musteriId = parseInt(kok.dataset.musteri, 10) || 0;
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
const eskiPanel = kok.dataset.eskiPanel || '';
if (salonId && window.rcPanel) window.rcPanel.salonAyarla(salonId);

let m = null;   // musteri DTO
let a = {};     // ayarlar
let y = {};     // yetkiler
let sekme = (location.hash || '#genel').slice(1);
const yuklenen = new Set();

const P = { salon_id: salonId };
const sekmeTanimlari = () => [
  { ad: 'genel', etiket: 'Genel Bilgiler', ikon: 'bi-person-vcard', goster: true, yukle: genelYukle },
  { ad: 'randevular', etiket: 'Randevular', ikon: 'bi-calendar-check', goster: true, yukle: randevularYukle },
  { ad: 'olcum', etiket: 'Vücut Ölçümü', ikon: 'bi-heart-pulse', goster: a.studyo_modu && y.olcum_gor, yukle: olcumYukle },
  { ad: 'satislar', etiket: 'Satışlar', ikon: 'bi-receipt', goster: y.satis_gor, yukle: satislarYukle },
  { ad: 'seanslar', etiket: 'Seanslar', ikon: 'bi-check2-square', goster: y.seans_gor, yukle: (p) => seansTakibi(p, { salonId, musteriId }) },
  { ad: 'saglik', etiket: 'Sağlık Bilgileri', ikon: 'bi-clipboard2-pulse', goster: !!m.saglik, yukle: saglikYukle },
];

// ─────────────────────────── BAŞLIK + SEKMELER ───────────────────────────

async function baslat() {
  if (!salonId || !musteriId) { kok.innerHTML = '<div class="md-bos">Şube veya müşteri seçili değil.</div>'; return; }
  const r = await api('GET', `/musteri/${musteriId}`, { params: P });
  if (!r.ok || !r.govde?.basarili) { kok.innerHTML = `<div class="md-bos">${esc(r.govde?.mesaj || `Hata (${r.kod})`)}</div>`; return; }
  ({ musteri: m, ayarlar: a, yetkiler: y } = r.govde.veri);
  document.title = `${m.name} — Randevumcepte`;

  kok.innerHTML = `
    <div class="md-kart" id="mdBaslik"></div>
    <div class="rc-sekmeler md-sekmeler" id="mdSekmeler" role="tablist"></div>
    <div id="mdIcerik"></div>`;
  baslikCiz();
  if (y.satis_gor && !a.studyo_modu) bakiyeYukle();

  const gorunur = sekmeTanimlari().filter((s) => s.goster);
  if (!gorunur.some((s) => s.ad === sekme)) sekme = 'genel';
  el('#mdSekmeler').innerHTML = gorunur.map((s) => `
      <button class="rc-sekme" data-sekme="${s.ad}" role="tab"><i class="bi ${s.ikon}"></i> ${esc(s.etiket)}</button>`).join('')
    + (y.belgeler && !a.studyo_modu
      ? `<a class="rc-sekme" href="/v2/formlar?sube=${salonId}&musteri_id=${musteriId}"><i class="bi bi-file-earmark-text"></i> Sözleşmeler/Belgeler <i class="bi bi-box-arrow-up-right md-dis"></i></a>` : '');
  el('#mdSekmeler').addEventListener('click', (e) => {
    const b = e.target.closest('[data-sekme]');
    if (b) sekmeAc(b.dataset.sekme);
  });
  sekmeAc(sekme);
}

function baslikCiz() {
  const karaBtn = y.kara_liste && a.kara_liste_aktif
    ? (m.kara_liste
      ? '<button class="md-btn md-yesil" id="mdKara"><i class="bi bi-check-lg"></i> Kara Listeden Çıkar</button>'
      : '<button class="md-btn md-koyu" id="mdKara"><i class="bi bi-slash-circle"></i> Kara Listeye Ekle</button>')
    : '';
  el('#mdBaslik').innerHTML = `
    <div class="md-sol">
      <div class="md-baslik-satir"><h1>${esc(m.name)}</h1>${segmentRozeti(m.segment)}
        ${m.kara_liste ? '<span class="rc-rozet rc-rozet-gri"><i class="bi bi-slash-circle"></i> Kara listede</span>' : ''}</div>
      <nav class="md-iz"><a href="/v2/musteriler?sube=${salonId}">${a.danisan ? 'Danışanlar' : 'Müşteriler'}</a><span>/</span>${esc(m.name)}</nav>
    </div>
    <div class="md-sag">
      <div id="mdBakiye" class="md-bakiye"></div>
      ${karaBtn}
    </div>`;
  if (el('#mdKara')) el('#mdKara').onclick = karaListeDegistir;
}

/** Toplam Borç / Ödenen — Satış Takibi'nin müşteri filtresiyle aynı toplamlar (tek hesap). */
async function bakiyeYukle() {
  const r = await api('GET', '/satislar', { params: { ...P, musteri_id: musteriId, per_page: 1 } });
  if (!r.ok || !r.govde?.basarili) return; // satış listesi yetkisi yoksa kartlar gizli kalır
  const v = r.govde.veri;
  el('#mdBakiye').innerHTML = `
    <div class="md-stat md-borc"><span><i class="bi bi-arrow-down"></i> Toplam Borç</span><b>${TL(v.kalan_numeric)}</b></div>
    <div class="md-stat md-odenen"><span><i class="bi bi-check-circle"></i> Toplam Ödenen</span><b>${TL(v.odenen_numeric)}</b></div>`;
}

async function karaListeDegistir() {
  const ekle = !m.kara_liste;
  if (ekle && !(await onay(`${m.name} kara listeye alınsın mı? Bildirim ve kampanya mesajları gönderilmez.`, { baslik: 'Kara Liste', tamam: 'Ekle' }))) return;
  const r = await api('PATCH', `/musteri/${musteriId}/kara-liste`, { body: { ...P, kara_liste: ekle } });
  if (yazSonuc(r, r.govde?.mesaj)) { m.kara_liste = r.govde.veri.kara_liste; baslikCiz(); if (y.satis_gor && !a.studyo_modu) bakiyeYukle(); }
}

function sekmeAc(ad) {
  sekme = ad;
  history.replaceState(null, '', `${location.pathname}${location.search}#${ad}`);
  el('#mdSekmeler').querySelectorAll('[data-sekme]').forEach((b) => b.classList.toggle('aktif', b.dataset.sekme === ad));
  const ic = el('#mdIcerik');
  ic.querySelectorAll('.md-panel').forEach((p) => { p.hidden = p.dataset.panel !== ad; });
  if (yuklenen.has(ad)) return;
  yuklenen.add(ad);
  const p = document.createElement('div');
  p.className = 'md-panel';
  p.dataset.panel = ad;
  ic.appendChild(p);
  sekmeTanimlari().find((s) => s.ad === ad).yukle(p);
}

/** Sekme paneli yeniden çizilsin (ör. düzenleme sonrası Genel). */
function paneliYenile(ad) {
  const p = el(`.md-panel[data-panel="${ad}"]`);
  if (p) { p.innerHTML = ''; sekmeTanimlari().find((s) => s.ad === ad).yukle(p); }
}

// ─────────────────────────── GENEL ───────────────────────────

const resimUrl = (yol) => (!yol ? '' : (/^https?:\/\//.test(yol) ? yol : eskiPanel + (yol.startsWith('/') ? '' : '/') + yol));

function genelYukle(p) {
  const bilgi = (ikon, k, v, tam = false) => `<li${tam ? ' class="md-tam"' : ''}><span class="md-ico"><i class="bi ${ikon}"></i></span><div><div class="md-k">${k}</div><div class="md-v${tam ? ' md-notlar' : ''}">${esc(v || '—')}</div></div></li>`;
  const foto = resimUrl(m.profil_resim);
  p.innerHTML = `
    <div class="md-genel">
      <div class="md-profil">
        <div class="md-foto">${foto ? `<img src="${esc(foto)}" alt="" onerror="this.remove()">` : ''}<span>${esc((m.name || '?').charAt(0))}</span></div>
        <div class="md-ad">${esc(m.name)}</div>
        <ul class="md-bilgi">
          ${bilgi('bi-hash', 'ID', String(m.id))}
          ${bilgi('bi-telephone', 'Telefon', m.cep_telefon)}
          ${bilgi('bi-envelope', 'E-posta', m.email)}
          ${bilgi('bi-megaphone', 'Referans', m.referans || 'Yok')}
          ${bilgi('bi-cake2', 'Doğum Tarihi', m.dogum_tarihi ? (m.dogum_tarihi_parca.yil ? tarihTr(m.dogum_tarihi) : tarihTr(m.dogum_tarihi).slice(0, 5)) : '')}
          ${bilgi('bi-person-badge', 'TC Kimlik No', m.tc_kimlik_no)}
          ${bilgi('bi-gender-ambiguous', 'Cinsiyet', m.cinsiyet_adi || 'Belirtilmemiş')}
          ${bilgi('bi-calendar-plus', 'Kayıt Tarihi', tarihTr(m.kayit_tarihi))}
          ${bilgi('bi-sticky', 'Notlar', m.ozel_notlar, true)}
        </ul>
        ${y.ekle_duzenle ? '<button class="rc-btn rc-btn-birincil md-duzenle" id="mdDuzenle"><i class="bi bi-pencil-square"></i> Bilgileri Düzenle</button>' : ''}
      </div>
      <div class="md-notlar-kolon">
        <div class="md-notlar-bas"><h3>Randevu Notları</h3><span class="md-sayi" id="mdNotSayi"></span></div>
        <div class="md-zaman" id="mdZaman"><div class="md-bos"><span class="rc-yukleniyor"></span></div></div>
      </div>
    </div>`;
  if (el('#mdDuzenle', p)) {
    el('#mdDuzenle', p).onclick = () => musteriFormuAc({
      salonId, m, etiket: a.danisan ? 'Danışan' : 'Müşteri',
      onKaydet: async () => {
        const r = await api('GET', `/musteri/${musteriId}`, { params: P });
        if (r.ok && r.govde?.basarili) { m = r.govde.veri.musteri; baslikCiz(); paneliYenile('genel'); }
      },
    });
  }
  notlarYukle(1);
}

async function notlarYukle(sayfa) {
  const kap = el('#mdZaman');
  const r = await api('GET', `/musteri/${musteriId}/randevu-notlari`, { params: { ...P, page: sayfa, per_page: 30 } });
  if (sayfa === 1) kap.innerHTML = '';
  el('.md-daha', kap)?.remove();
  if (!r.ok || !r.govde?.basarili) { kap.insertAdjacentHTML('beforeend', `<div class="md-bos">${esc(r.govde?.mesaj || 'Yüklenemedi')}</div>`); return; }
  const v = r.govde.veri;
  el('#mdNotSayi').textContent = `${v.total} randevu`;
  if (!v.total) { kap.innerHTML = '<div class="md-bos"><i class="bi bi-calendar-x"></i> Müşteriye ait randevu bulunamadı.</div>'; return; }
  kap.insertAdjacentHTML('beforeend', v.data.map((n) => {
    const notlar = [
      n.personel_notu ? `<div class="md-not"><span>Personel Notu</span>${esc(n.personel_notu)}</div>` : '',
      n.randevu_sonrasi_not ? `<div class="md-not"><span>Randevu Sonrası Not</span>${esc(n.randevu_sonrasi_not)}</div>` : '',
    ].join('');
    return `<div class="md-zaman-oge">
      <div class="md-zaman-bas"><span class="md-tarih">${tarihTr(n.tarih)}${n.saat ? ' ' + esc(n.saat) : ''}</span>
        <div class="md-etiketler">${n.hizmetler.map((h) => `<span>${esc(h.hizmet_adi)}</span>`).join('')}</div></div>
      ${notlar || '<div class="md-not-yok"><i class="bi bi-pencil"></i> Bu randevuya not girilmemiş</div>'}
    </div>`;
  }).join(''));
  if (v.current_page < v.last_page) {
    kap.insertAdjacentHTML('beforeend', '<button class="rc-btn rc-btn-ikincil md-daha">Daha fazla göster</button>');
    el('.md-daha', kap).onclick = () => notlarYukle(v.current_page + 1);
  }
}

// ─────────────────────────── RANDEVULAR ───────────────────────────

function randevularYukle(p) {
  const TELAFI = { telafi_randevusu: 'Telafi Randevusu', telafi_verildi: 'Telafi Verildi' };
  veriTablosu(p, {
    anahtar: 'musteri_randevular',
    birim: 'randevu',
    bosMesaj: 'Randevu bulunamadı.',
    araIpucu: 'Tabloda ara…',
    sirala: { alan: 'tarih', yon: 'desc' },
    kolonlar: [
      { baslik: 'Tarih', sirala: 'tarih' }, { baslik: 'Saat' }, { baslik: 'Durum' }, { baslik: 'Hizmetler' },
      { baslik: 'Personel / Cihaz / Oda' }, { baslik: 'Randevu Notu' }, { baslik: 'Oluşturan' },
      { baslik: 'Oluşturulma', sirala: 'olusturulma' },
    ],
    kaynak: (prm) => api('GET', `/musteri/${musteriId}/randevular`, { params: { ...prm, ...P } }),
    satir: (r) => `<tr>
      <td class="rc-nowrap">${tarihTr(r.tarih)}</td>
      <td>${esc(r.saat || '')}</td>
      <td><span class="rc-rozet rc-rozet-${esc(r.durum.renk)}">${esc(r.durum.etiket)}</span>${r.telafi ? `<div><span class="rc-rozet rc-rozet-turuncu md-telafi"><i class="bi bi-arrow-counterclockwise"></i> ${TELAFI[r.telafi]}</span></div>` : ''}</td>
      <td>${esc(r.hizmetler.join(', ') || '—')}</td>
      <td>${esc(r.kaynaklar.join(', ') || '—')}</td>
      <td class="md-metin">${esc(r.randevu_notu || '')}</td>
      <td>${esc(r.olusturan || '—')}</td>
      <td class="rc-nowrap">${r.olusturulma ? tarihTr(r.olusturulma) + ' <small>' + esc(String(r.olusturulma).slice(11, 16)) + '</small>' : '—'}</td>
    </tr>`,
  });
}

// ─────────────────────────── SATIŞLAR ───────────────────────────

function satislarYukle(p) {
  const studyo = a.studyo_modu;
  veriTablosu(p, {
    anahtar: 'musteri_satislar',
    birim: 'satış',
    bosMesaj: 'Satış bulunamadı.',
    araIpucu: 'Hizmet, ürün, paket…',
    sirala: { alan: 'tarih', yon: 'desc' },
    kolonlar: [
      { baslik: 'Satış Tarihi', sirala: 'tarih' }, { baslik: 'Planlanan Alacak' }, { baslik: 'Satış İçeriği' },
      ...(studyo ? [{ baslik: 'Ödeme Tarihi' }] : [
        { baslik: 'Toplam', sirala: 'toplam', sinif: 'rc-sag' }, { baslik: 'Ödenen', sirala: 'odenen', sinif: 'rc-sag' },
        { baslik: 'Kalan', sirala: 'kalan', sinif: 'rc-sag' }]),
      { baslik: 'İşlemler', sinif: 'rc-orta' },
    ],
    kaynak: (prm) => api('GET', '/satislar', { params: { ...prm, ...P, musteri_id: musteriId } }),
    satir: (s) => {
      const x = s.aksiyonlar || {};
      const url = `/v2/tahsilat/${s.id}`;
      const islem = [
        x.tahsil_et ? `<a class="rc-islem rc-olumlu" href="${url}" title="Tahsil Et"><i class="bi bi-cash-coin"></i></a>` : '',
        x.odeme_bilgisi ? `<a class="rc-islem" href="${url}" title="Ödeme Bilgileri"><i class="bi bi-eye"></i></a>` : '',
        x.duzenle ? `<a class="rc-islem" href="${url}" title="Düzenle"><i class="bi bi-pencil-square"></i></a>` : '',
      ].join('');
      return `<tr>
        <td class="rc-nowrap">${esc(s.acilis_tarihi)}</td>
        <td class="rc-nowrap">${esc(s.planlanan_alacak_tarihi || '—')}</td>
        <td class="md-metin">${esc(s.icerik || '')}</td>
        ${studyo ? `<td class="rc-nowrap">${esc(s.odendi_tarihi || '—')}</td>` : `
          <td class="rc-sag">${TL(s.toplam_numeric)}</td><td class="rc-sag">${TL(s.odenen_numeric)}</td><td class="rc-sag">${TL(s.kalan_tutar_numeric)}</td>`}
        <td class="rc-orta"><span class="rc-islemler">${islem || '—'}</span></td>
      </tr>`;
    },
  });
}

// ─────────────────────────── VÜCUT ÖLÇÜMÜ ───────────────────────────

const sayi = (v, h = 1) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('tr-TR', { maximumFractionDigits: h }));

async function olcumYukle(p) {
  p.innerHTML = `
    <div class="md-olcum-ust">
      <h3>Vücut Ölçümleri</h3>
      <button class="rc-btn rc-btn-birincil" id="olYeni"><i class="bi bi-plus-lg"></i> Yeni Ölçüm</button>
    </div>
    <div id="olOzet"></div><div id="olTablo"></div>`;
  const olcumler = [];
  const t = veriTablosu(el('#olTablo', p), {
    anahtar: 'musteri_olcum', birim: 'ölçüm', bosMesaj: 'Henüz ölçüm girilmemiş.',
    kolonlar: [
      { baslik: 'Tarih / Saat', sirala: 'tarih', deger: (o) => `${o.olcum_tarihi} ${o.olcum_saati || ''}` },
      { baslik: 'Kilo', sinif: 'rc-sag' }, { baslik: 'VKİ', sinif: 'rc-sag' }, { baslik: 'Yağ %', sinif: 'rc-sag' },
      { baslik: 'Ödem', sinif: 'rc-sag' }, { baslik: 'Kas Puanı', sinif: 'rc-sag' }, { baslik: 'Kas kg', sinif: 'rc-sag' },
      { baslik: 'İç Yağ.', sinif: 'rc-sag' }, { baslik: 'Not' }, { baslik: '', sinif: 'rc-orta' },
    ],
    sirala: { alan: 'tarih', yon: 'desc' },
    araMetni: (o) => `${o.olcum_tarihi} ${o.not || ''}`,
    satir: (o) => `<tr>
      <td class="rc-nowrap">${tarihTr(o.olcum_tarihi)} <small>${esc(o.olcum_saati || '')}</small></td>
      <td class="rc-sag">${sayi(o.kilo)}</td><td class="rc-sag">${sayi(o.vki, 2)} <small>${esc(o.vki_sinif)}</small></td>
      <td class="rc-sag">${sayi(o.yag_orani)}</td><td class="rc-sag">${sayi(o.odem)}</td><td class="rc-sag">${sayi(o.kas_puani)}</td>
      <td class="rc-sag">${sayi(o.kas_kg)}</td><td class="rc-sag">${sayi(o.ic_yaglanma)}</td><td class="md-metin">${esc(o.not || '')}</td>
      <td class="rc-orta">${y.olcum_sil ? `<button class="rc-islem rc-tehlike" data-olcum-sil="${o.id}" title="Sil"><i class="bi bi-trash"></i></button>` : ''}</td>
    </tr>`,
  });

  async function yenile() {
    const r = await api('GET', `/musteri/${musteriId}/olcumler`, { params: P });
    if (!r.ok || !r.govde?.basarili) { t.mesaj(r.govde?.mesaj || 'Yüklenemedi'); return; }
    olcumler.splice(0, olcumler.length, ...r.govde.veri);
    t.kayitlarAyarla(olcumler);
    const s = olcumler[0];
    el('#olOzet', p).innerHTML = s ? `<div class="rc-ozet">
      <div class="rc-kutu rc-k-mor"><div class="rc-k-ic"><span class="rc-k-ad">Son VKİ</span><span class="rc-k-deger">${sayi(s.vki, 2)} <small>${esc(s.vki_sinif)}</small></span></div></div>
      <div class="rc-kutu rc-k-mavi"><div class="rc-k-ic"><span class="rc-k-ad">Kilo</span><span class="rc-k-deger">${sayi(s.kilo)} kg</span></div></div>
      <div class="rc-kutu rc-k-turkuaz"><div class="rc-k-ic"><span class="rc-k-ad">Boy / Yaş</span><span class="rc-k-deger">${sayi(s.boy)} cm · ${s.yas ?? '—'}</span></div></div>
    </div>` : '';
  }

  el('#olYeni', p).onclick = () => olcumFormu(olcumler[0], yenile);
  p.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-olcum-sil]');
    if (!b) return;
    if (!(await onay('Ölçüm kaydı silinsin mi?', { baslik: 'Ölçüm Sil', tamam: 'Sil' }))) return;
    if (yazSonuc(await api('DELETE', `/musteri/olcum/${b.dataset.olcumSil}`), 'Ölçüm silindi.')) yenile();
  });
  yenile();
}

/** Boy ve yaş sabit profil bilgisidir: son ölçümden önceden doldurulur (eski ekran gibi). */
function olcumFormu(son, sonra) {
  const alan = (ad, etiket, deger = '', tip = 'number', ek = 'step="0.1" min="0"') =>
    `<div class="rc-alan"><label>${etiket}</label><input name="${ad}" type="${tip}" ${ek} value="${esc(deger ?? '')}"></div>`;
  const bugun = new Date();
  const g = `${bugun.getFullYear()}-${String(bugun.getMonth() + 1).padStart(2, '0')}-${String(bugun.getDate()).padStart(2, '0')}`;
  modalAc('Yeni Ölçüm', `
    <form id="olForm" class="rc-form-izgara">
      ${alan('olcum_tarihi', 'Ölçüm Tarihi', g, 'text', 'data-tarih readonly')}
      ${alan('boy', 'Boy (cm)', son?.boy)}${alan('yas', 'Yaş', son?.yas, 'number', 'min="0" max="120"')}
      ${alan('kilo', 'Kilo (kg)')}
      <div class="rc-alan"><label>VKİ (otomatik)</label><input id="olVki" disabled placeholder="—"></div>
      ${alan('yag_orani', 'Yağ Oranı (%)')}${alan('odem', 'Ödem')}${alan('kas_puani', 'Kas Puanı')}
      ${alan('kas_kg', 'Kas (kg)')}${alan('ic_yaglanma', 'İç Yağlanma')}
      <div class="rc-alan rc-tam"><label>Not</label><input name="not" maxlength="1000" placeholder="opsiyonel"></div>
    </form>`,
  `<button class="rc-btn rc-btn-ikincil" type="button" id="olVazgec">Vazgeç</button>
   <button class="rc-btn rc-btn-birincil" type="submit" form="olForm" id="olKaydet"><i class="bi bi-check2"></i> Kaydet</button>`,
  { ikon: 'heart-pulse', dar: true, sabit: true });

  const f = el('#olForm');
  if (window.flatpickr) window.flatpickr(f.olcum_tarihi, { locale: 'tr', dateFormat: 'Y-m-d', altInput: true, altFormat: 'd.m.Y', maxDate: 'today' });
  // Önizleme yalnız bilgi amaçlı; kayıtta VKİ sunucuda hesaplanır.
  const vki = () => { const b = +f.boy.value / 100, k = +f.kilo.value; el('#olVki').value = b > 0 && k > 0 ? (k / (b * b)).toFixed(2) : ''; };
  f.boy.oninput = vki; f.kilo.oninput = vki;
  el('#olVazgec').onclick = modalKapat;
  f.onsubmit = async (e) => {
    e.preventDefault();
    const body = { ...P, ...Object.fromEntries([...new FormData(f).entries()].filter(([, v]) => v !== '')) };
    el('#olKaydet').disabled = true;
    const r = await api('POST', `/musteri/${musteriId}/olcumler`, { body });
    el('#olKaydet').disabled = false;
    if (yazSonuc(r, 'Ölçüm kaydedildi.')) { modalKapat(); sonra(); }
  };
}

// ─────────────────────────── SAĞLIK ───────────────────────────

async function saglikYukle(p) {
  const fv = await formVerisi(salonId);
  const s = m.saglik || {};
  const kilit = y.saglik ? '' : 'disabled';
  const evetHayir = (ad, v) => {
    const evet = String(v) === '1';
    return `<select name="${ad}" ${kilit}>
      <option value="0"${evet ? '' : ' selected'}>Hayır</option><option value="1"${evet ? ' selected' : ''}>Evet</option></select>`;
  };

  p.innerHTML = `
    <div class="md-kutu">
      <h3>Sağlık Bilgileri</h3>
      <form id="sgForm" class="rc-form-izgara">
        ${fv.saglik_sorulari.map((q) => `
          <div class="rc-alan"><label>${esc(q.soru)}</label>${evetHayir(q.alan, s[q.alan])}
            ${q.aciklama_alani ? `<input name="${q.aciklama_alani}" placeholder="Açıklama" maxlength="2000" value="${esc(s[q.aciklama_alani] || '')}" ${kilit}>` : ''}
          </div>`).join('')}
        <div class="rc-alan"><label>Cilt Tipi</label><select name="cilt_tipi" ${kilit}><option value="">Seçiniz</option>
          ${fv.cilt_tipleri.map((c) => `<option value="${c.id}"${String(c.id) === String(s.cilt_tipi ?? '') ? ' selected' : ''}>${esc(c.ad)}</option>`).join('')}</select></div>
        <div class="rc-alan rc-tam"><label>Ek sağlık sorunları</label><textarea name="ek_saglik_sorunu" rows="3" maxlength="2000" ${kilit}>${esc(s.ek_saglik_sorunu || '')}</textarea></div>
      </form>
      ${y.saglik ? '<div class="md-kaydet"><button class="rc-btn rc-btn-birincil" type="submit" form="sgForm" id="sgKaydet"><i class="bi bi-check2"></i> Formu Kaydet</button></div>' : ''}
    </div>`;

  el('#sgForm', p).onsubmit = async (e) => {
    e.preventDefault();
    const body = { ...P, ...Object.fromEntries(new FormData(e.target).entries()) };
    el('#sgKaydet').disabled = true;
    const r = await api('PATCH', `/musteri/${musteriId}/saglik`, { body });
    el('#sgKaydet').disabled = false;
    if (yazSonuc(r, r.govde?.mesaj)) {
      Object.keys(body).forEach((k) => { if (k !== 'salon_id') s[k] = body[k]; });
      m.saglik = s;
    }
  };
}

baslat().catch((e) => { console.error(e); mesaj('Sayfa yüklenemedi', 'hata'); });
