/**
 * Ön Görüşmeler (v2) — build'siz island (sade ES module).
 *
 * Eski /isletmeyonetim/ongorusmeler ekranının (ongorusmeler.blade + ongorusme-modal + custom.js
 * ongorusme*) v2 karşılığı, aynı akış: tablo (Oluşturma, Müşteri, Referans, Telefon, Randevu
 * Tarihi, Ön Görüşme Nedeni, Görüşmeyi Yapan, Durum, İşlemler), Yeni Ön Görüşme / Düzenle modalı
 * (Müşteri Bilgileri · Görüşme Detayı · Tarih & Personel), Satış Yapıldı (nedene göre seans/fiyat/
 * adet) → tahsilat ekranı, Satış Yapılmadı (sebep notu). Ek: durum sekmeleri (sayaçlı).
 *
 * TEK KAYNAK: liste + sayaçlar, neden kataloğu (fiyat/seans), personel/oda, sözlükler, yetkiler,
 * satır işlemleri (islemler.duzenle/sonuclandir) /api/v2/ongorusme/* uçlarından — Flutter da aynı
 * uçları kullanır; burada iş kuralı yok. Yazmalar ONGORUSME_V2_YAZMA_AKTIF kapalıyken 409 (toast).
 */
import { qp, api, el, esc, mesaj } from '/js/lib/v2-ortak.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=7';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';
import { trTarih } from '/js/lib/v2-donem.js?v=7';
import { ongorusmeFormu } from '/js/lib/v2-ongorusme.js?v=8';
import { satisYapildiModal, satisYapilmadiModal } from '/js/lib/v2-ongorusme-sonuc.js?v=2';

const kok = document.getElementById('rc-ongorusme');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;

const ROZET = { beklemede: 'turuncu', satis_yapildi: 'yesil', satis_yapilmadi: 'kirmizi' };
const SEKME_IKON = { '': 'bi-list-ul', beklemede: 'bi-hourglass-split', satis_yapildi: 'bi-check-circle-fill', satis_yapilmadi: 'bi-x-circle-fill' };

let fv = { nedenler: [], personeller: [], odalar: [], referanslar: [], cinsiyetler: [], durumlar: [], yetkiler: {}, saat_araligi: {} };
let durum = qp.get('durum') || '';
let tablo = null;
let sonListe = [];
let sayaclar = {};

// ─────────────────────────── yardımcılar ───────────────────────────

const yetki = (k) => !!fv.yetkiler?.[k];
const oku = (yol, params = {}) => api('GET', yol, { params: { ...params, salon_id: salonId } });
const kayitBul = (id) => sonListe.find((k) => String(k.id) === String(id));


// ─────────────────────────── iskelet + tablo ───────────────────────────

function iskeletCiz() {
  kok.innerHTML = `
    <div class="og-kabuk">
      <div class="rc-sayfa-ust">
        <div class="rc-sayfa-baslik">
          <span class="rc-sayfa-ikon"><i class="bi bi-chat-left-text"></i></span>
          <div><h1>Ön Görüşmeler</h1><div class="rc-sayfa-alt">${esc(fv.musteri_etiket || 'Müşteri')} ön görüşmeleri ve satışa dönüşüm</div></div>
        </div>
        <div class="rc-sayfa-aksiyon">
          ${yetki('ekle_duzenle') ? '<button class="rc-btn rc-btn-yesil" id="ogYeni"><i class="bi bi-plus-lg"></i> Yeni Ön Görüşme</button>' : ''}
        </div>
      </div>
      <div class="rc-sekmeler" id="ogSekmeler" role="tablist"></div>
      <div id="ogTablo"></div>
    </div>`;

  el('#ogYeni')?.addEventListener('click', () => gorusmeFormu(null));
  el('#ogSekmeler').addEventListener('click', (e) => {
    const b = e.target.closest('[data-durum]');
    if (!b) return;
    durum = b.dataset.durum;
    const u = new URL(location.href);
    if (durum) u.searchParams.set('durum', durum); else u.searchParams.delete('durum');
    history.replaceState(null, '', u);
    sekmeCiz();
    tablo.yenile();
  });

  tablo = veriTablosu(el('#ogTablo'), {
    anahtar: 'ongorusmeler', birim: 'görüşme', varsayilanBoy: 25, tabloSinif: 'og-tablo',
    araIpucu: 'Müşteri, telefon, neden veya personel…', bosMesaj: 'Ön görüşme bulunamadı.',
    sirala: { alan: 'created_at', yon: 'desc' },
    kolonlar: [
      { baslik: 'Oluşturma', sirala: 'created_at' }, { baslik: fv.musteri_etiket || 'Müşteri', sirala: 'ad_soyad' },
      { baslik: 'Referans' }, { baslik: 'Telefon' }, { baslik: 'Randevu Tarihi', sirala: 'hatirlatma_tarihi' },
      { baslik: 'Ön Görüşme Nedeni' }, { baslik: 'Görüşmeyi Yapan', sirala: 'personel_adi' },
      { baslik: 'Durum', sirala: 'durum' }, { baslik: 'İşlemler', sinif: 'rc-orta' },
    ],
    satir: satirCiz,
    kaynak: (p) => (salonId ? oku('/ongorusme', { ...p, durum })
      : Promise.resolve({ ok: false, kod: 422, govde: { mesaj: 'Şube seçili değil (?sube=... bekleniyor).' } })),
    cizildi: (liste, v) => { sonListe = liste; sayaclar = v?.sayaclar || {}; sekmeCiz(); },
  });
  el('#ogTablo').addEventListener('click', satirTiklama);
  sekmeCiz();
}

function sekmeCiz() {
  const sekmeler = [{ kod: '', ad: 'Tümü', sayac: 'tumu' }, ...fv.durumlar.map((d) => ({ ...d, sayac: d.kod }))];
  el('#ogSekmeler').innerHTML = sekmeler.map((s) => `
    <button class="rc-sekme${s.kod === durum ? ' aktif' : ''}" data-durum="${esc(s.kod)}" role="tab" aria-selected="${s.kod === durum}">
      <i class="bi ${SEKME_IKON[s.kod] || 'bi-dot'}"></i> ${esc(s.ad)} <span class="rc-sayac">${sayaclar[s.sayac] ?? '…'}</span>
    </button>`).join('');
}

function satirCiz(k) {
  const islem = (a, ikon, baslik, sinif = '') => `<button class="rc-islem ${sinif}" data-aksiyon="${a}" data-id="${k.id}" title="${baslik}"><i class="bi ${ikon}"></i></button>`;
  const rozet = `<span class="rc-rozet rc-rozet-${ROZET[k.durum_kodu] || 'gri'}">${esc(k.durum_adi)}</span>`;
  const islemler = [
    k.islemler.duzenle ? islem('duzenle', 'bi-pencil', 'Düzenle') : '',
    k.islemler.sonuclandir ? islem('yapildi', 'bi-cart-check', 'Satış Yapıldı', 'og-olumlu') : '',
    k.islemler.sonuclandir ? islem('yapilmadi', 'bi-x-lg', 'Satış Yapılmadı', 'rc-tehlike') : '',
  ].join('');
  return `<tr>
    <td class="og-soluk rc-nowrap">${esc(trTarih(k.created_at))}</td>
    <td class="og-ad">${esc(k.ad_soyad)}</td>
    <td class="og-soluk">${esc(k.musteri_tipi_adi || '—')}</td>
    <td class="rc-nowrap og-tel">${esc(k.cep_telefon || '—')}</td>
    <td class="rc-nowrap">${esc(trTarih(k.hatirlatma_tarihi))}${k.on_gorusme_saati ? ` <small class="og-soluk">${esc(k.on_gorusme_saati)}</small>` : ''}</td>
    <td>${k.neden ? `<span class="og-neden-tur">${esc(k.neden.etiket)}</span> ${esc(k.neden.ad || '—')}` : '<span class="og-soluk">Bilinmiyor</span>'}</td>
    <td class="og-soluk">${esc(k.personel?.personel_adi || '—')}</td>
    <td>${k.durum_kodu === 'satis_yapilmadi' ? `<button class="og-rozet-btn" data-aksiyon="not" data-id="${k.id}" title="Sebebi gör">${rozet}</button>` : rozet}</td>
    <td class="rc-orta"><span class="rc-islemler">${islemler || '<span class="og-soluk">—</span>'}</span></td>
  </tr>`;
}

function satirTiklama(e) {
  const b = e.target.closest('[data-aksiyon]');
  const k = b && kayitBul(b.dataset.id);
  if (!k) return;
  ({
    duzenle: () => gorusmeFormu(k),
    yapildi: () => satisYapildi(k),
    yapilmadi: () => satisYapilmadi(k),
    not: () => modalAc('Satış Yapılmama Sebebi', `<p class="og-not">${esc(k.satisyapilmadi_not || 'Belirtilmemiş')}</p>`,
      '<button class="rc-btn rc-btn-ikincil" data-kapat>Kapat</button>', { ikon: 'chat-quote', altbaslik: k.ad_soyad, dar: true })
      .querySelector('[data-kapat]').addEventListener('click', modalKapat),
  })[b.dataset.aksiyon]?.();
}

// ─────────────────────────── ekle / düzenle ───────────────────────────

/** Ekle / düzenle — ortak form (lib/v2-ongorusme.js; Çağrı Merkezi de aynı formu açar). */
function gorusmeFormu(k) {
  ongorusmeFormu({ salonId, fv, kayit: k, onKaydet: () => tablo.yenile(!k) });
}

// ─────────────────────────── sonuçlandır ───────────────────────────

/** Sonuçlandırma pencereleri ortak modülde (randevu çekmecesi de aynılarını açar). */
const satisYapildi = (k) => satisYapildiModal({ salonId, fv, kayit: k });
const satisYapilmadi = (k) => satisYapilmadiModal({ salonId, kayit: k, onBitti: () => tablo.yenile(false) });

// ─────────────────────────── başlat ───────────────────────────

(async function baslat() {
  if (window.rcPanel && salonId) window.rcPanel.salonAyarla(salonId);
  if (salonId) {
    const r = await oku('/ongorusme/form-verisi');
    if (r.ok && r.govde?.basarili) fv = r.govde.veri;
    else mesaj(r.govde?.mesaj || 'Form verisi alınamadı', 'hata');
  }
  if (!fv.durumlar.some((d) => d.kod === durum)) durum = '';
  iskeletCiz();
  tablo.yenile();
})();
