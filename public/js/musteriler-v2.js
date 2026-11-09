/**
 * Müşteriler (v2) — build'siz island (sade ES module).
 *
 * Eski /isletmeyonetim/musteriler ekranının v2 karşılığı: Tümü / Sadık / Aktif / Pasif /
 * Yeni Eklenen sekmeleri (sayaçlı), arama + sıralama + sayfalama (ortak veri tablosu),
 * Yeni Müşteri / Düzenle (ortak form: lib/v2-musteri.js), Sil.
 *
 * İş kuralları SUNUCUDA: segment (≥3 tahsilat = Sadık), sekme sayaçları, telefon
 * maskeleme, portföy kapsamı (musteri.tum_portfoy_gor), buton yetkileri (`yetkiler`),
 * Müşteri/Danışan etiketi ve stüdyo modu (`ayarlar`) — web ve Flutter aynı kararı görür.
 * Yazma uçları MUSTERI_V2_YAZMA_AKTIF kapalıyken 409 döner (toast).
 */
import { qp, api, el, esc, TL, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=7';
import { musteriFormuAc, segmentRozeti, tarihTr } from '/js/lib/v2-musteri.js?v=7';

const kok = document.getElementById('rc-musteriler');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
if (salonId && window.rcPanel) window.rcPanel.salonAyarla(salonId);

// Sekme kodları sunucu filtresiyle aynı (Domain\Musteri\Segment): 3 tümü · 2 sadık · 1 aktif · 0 pasif · 4 yeni.
const SEKMELER = [
  { durum: 3, ad: 'Tümü', ikon: 'bi-list-ul', sayac: 'tum_musteriler' },
  { durum: 2, ad: 'Sadık', ikon: 'bi-star-fill', sayac: 'sadik_musteriler', not: (a, e) => `<b>Sadık ${e.tekil} nedir?</b> En az 3 ödeme (tahsilat) yapmış ${e.kucuk}.`, indirim: 'sadik_yuzde' },
  { durum: 1, ad: 'Aktif', ikon: 'bi-lightning-charge-fill', sayac: 'aktif_musteriler', not: (a, e) => `<b>Aktif ${e.tekil} nedir?</b> 1 veya 2 ödeme (tahsilat) yapmış ${e.kucuk}.`, indirim: 'aktif_yuzde' },
  { durum: 0, ad: 'Pasif', ikon: 'bi-moon-fill', sayac: 'pasif_musteriler', not: (a, e) => `<b>Pasif ${e.tekil} nedir?</b> Hiç ödeme (tahsilat) yapmamış ${e.kucuk}.` },
  { durum: 4, ad: 'Yeni Eklenen', ikon: 'bi-person-plus-fill', sayac: 'yeni_musteriler', not: (a) => `<b>Yeni eklenenler:</b> ${tarihTr(a.yeni_baslangic)} tarihinden itibaren portföye eklenenler, en yeniden eskiye.` },
];

let durum = SEKMELER.some((s) => String(s.durum) === qp.get('durum')) ? parseInt(qp.get('durum'), 10)
  : (qp.get('filtre') === 'yeni' ? 4 : 3);
let son = null;       // son liste cevabı (sayilar, ayarlar, yetkiler)
let tablo = null;

const etiketler = (a) => (a?.danisan
  ? { tekil: 'danışan', kucuk: 'danışan', baslik: 'Danışan', cogul: 'Danışanlar' }
  : { tekil: 'müşteri', kucuk: 'müşteri', baslik: 'Müşteri', cogul: 'Müşteriler' });

const detayUrl = (id) => `/v2/musteri/${id}?sube=${salonId}`;

function iskelet() {
  kok.innerHTML = `
    <div class="rc-sayfa-ust">
      <div class="rc-sayfa-baslik">
        <span class="rc-sayfa-ikon"><i class="bi bi-people-fill"></i></span>
        <div><h1 id="muBaslik">Müşteriler</h1><div class="rc-sayfa-alt">Portföy, segmentler ve iletişim bilgileri</div></div>
      </div>
      <div class="rc-sayfa-aksiyon" id="muAksiyon"></div>
    </div>
    <div class="rc-sekmeler" id="muSekmeler" role="tablist"></div>
    <div id="muNot"></div>
    <div id="muTablo"></div>`;

  el('#muSekmeler').addEventListener('click', (e) => {
    const b = e.target.closest('[data-durum]');
    if (!b) return;
    durum = parseInt(b.dataset.durum, 10);
    const u = new URL(location.href);
    u.searchParams.set('durum', durum);
    history.replaceState(null, '', u);
    sekmeCiz();
    tablo.yenile();
  });

  tablo = veriTablosu(el('#muTablo'), {
    anahtar: 'musteriler',
    tabloSinif: 'mu-tablo',
    birim: 'kayıt',
    araIpucu: 'Ad soyad veya telefon…',
    bosMesaj: 'Kayıt bulunamadı.',
    kolonlar: kolonlar(null),
    satir: satirCiz,
    kaynak: (p) => (salonId
      ? api('GET', '/musteri', { params: { ...p, salon_id: salonId, durum } })
      : Promise.resolve({ ok: false, kod: 422, govde: { mesaj: 'Şube seçili değil (?sube=... bekleniyor).' } })),
    cizildi: (_, v) => { son = v; ustCiz(); },
  });

  el('#muTablo').addEventListener('click', satirTiklama);
  sekmeCiz();
}

function kolonlar(a) {
  return [
    { baslik: 'Ad Soyad', sirala: 'ad' },
    { baslik: 'Telefon' },
    { baslik: 'Kayıt Tarihi', sirala: 'kayit' },
    { baslik: 'Son Randevusu' },
    { baslik: 'Randevu', sinif: 'rc-orta' },
    // Stüdyo modu: parasal bilgi gösterilmez (eski detay kartlarıyla aynı kural).
    ...(a?.studyo_modu ? [] : [{ baslik: 'Toplam Alınan Ücret', sirala: 'odenen', sinif: 'rc-sag' }]),
    { baslik: 'İşlemler', sinif: 'rc-orta' },
  ];
}

function sekmeCiz() {
  const s = son?.sayilar || {};
  el('#muSekmeler').innerHTML = SEKMELER.map((k) => `
    <button class="rc-sekme${k.durum === durum ? ' aktif' : ''}" data-durum="${k.durum}" role="tab" aria-selected="${k.durum === durum}">
      <i class="bi ${k.ikon}"></i> ${esc(k.ad)} <span class="rc-sayac">${s[k.sayac] ?? '…'}</span>
    </button>`).join('');

  const k = SEKMELER.find((x) => x.durum === durum);
  const a = son?.ayarlar || {};
  const indirim = k.indirim && a.indirim ? Number(a.indirim[k.indirim]) || 0 : 0;
  el('#muNot').innerHTML = k.not && son
    ? `<div class="mu-not"><i class="bi bi-info-circle"></i><div>${k.not(a, etiketler(a))}</div>${indirim ? `<span class="rc-rozet rc-rozet-mor">İndirim %${esc(indirim)}</span>` : ''}</div>`
    : '';
}

let ilkCizim = true;
function ustCiz() {
  const a = son?.ayarlar || {};
  const e = etiketler(a);
  el('#muBaslik').textContent = e.cogul;
  document.title = `${e.cogul} — Randevumcepte`;
  el('#muAksiyon').innerHTML = son?.yetkiler?.ekle_duzenle
    ? `<button class="rc-btn rc-btn-birincil" id="muYeni"><i class="bi bi-person-plus-fill"></i> Yeni ${esc(e.baslik)}</button>` : '';
  if (el('#muYeni')) el('#muYeni').onclick = () => musteriFormuAc({ salonId, etiket: e.baslik, onKaydet: (r) => { location.href = detayUrl(r.id); } });
  if (ilkCizim) { ilkCizim = false; tablo.kolonlarAyarla(kolonlar(a)); }
  sekmeCiz();
}

function satirCiz(m) {
  const y = son?.yetkiler || {};
  const studyo = !!son?.ayarlar?.studyo_modu;
  const ad = y.detay_gor ? `<a class="mu-ad" href="${detayUrl(m.id)}">${esc(m.name || '—')}</a>` : `<span class="mu-ad">${esc(m.name || '—')}</span>`;
  const islem = [
    y.detay_gor ? `<a class="rc-islem" href="${detayUrl(m.id)}" title="Detaylı Bilgi"><i class="bi bi-eye"></i></a>` : '',
    y.ekle_duzenle ? `<button class="rc-islem" data-aksiyon="duzenle" data-id="${m.id}" title="Düzenle"><i class="bi bi-pencil"></i></button>` : '',
    y.sil ? `<button class="rc-islem rc-tehlike" data-aksiyon="sil" data-id="${m.id}" title="Sil"><i class="bi bi-trash"></i></button>` : '',
  ].join('');

  return `<tr>
    <td><div class="mu-kisi"><span class="mu-avatar">${esc((m.name || '?').charAt(0))}</span>
      <div>${ad}<div class="mu-alt">${segmentRozeti(m.segment)}${m.kara_liste ? ' <span class="rc-rozet rc-rozet-gri"><i class="bi bi-slash-circle"></i> Kara liste</span>' : ''}</div></div></div></td>
    <td class="rc-nowrap">${esc(m.cep_telefon || '—')}</td>
    <td class="rc-nowrap">${tarihTr(m.kayit_tarihi)}</td>
    <td class="rc-nowrap">${tarihTr(m.son_randevu_tarihi)}</td>
    <td class="rc-orta">${m.randevu_sayisi}</td>
    ${studyo ? '' : `<td class="rc-sag rc-nowrap">${TL(m.toplam_odenen)}</td>`}
    <td class="rc-orta"><span class="rc-islemler">${islem || '—'}</span></td>
  </tr>`;
}

async function satirTiklama(e) {
  const b = e.target.closest('[data-aksiyon]');
  if (!b) return;
  const id = parseInt(b.dataset.id, 10);
  const e2 = etiketler(son?.ayarlar);

  if (b.dataset.aksiyon === 'duzenle') {
    // Liste satırı özet taşır; düzenleme tam detay DTO'su ile (doğum tarihi, TC, notlar…).
    const r = await api('GET', `/musteri/${id}`, { params: { salon_id: salonId } });
    if (!yazSonuc(r)) return;
    musteriFormuAc({ salonId, m: r.govde.veri.musteri, etiket: e2.baslik, onKaydet: () => tablo.yenile(false) });
  } else if (b.dataset.aksiyon === 'sil') {
    if (!(await onay(`${e2.baslik} portföyden silinsin mi? Geçmiş randevu ve satış kayıtları korunur.`, { baslik: `${e2.baslik} Sil`, tamam: 'Sil', iptal: 'Vazgeç', tehlike: true }))) return;
    if (yazSonuc(await api('DELETE', `/musteri/${id}`, { params: { salon_id: salonId } }), `${e2.baslik} silindi.`)) tablo.yenile(false);
  }
}

iskelet();
