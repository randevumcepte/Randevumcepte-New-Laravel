/**
 * v2 SEANS TAKİBİ bileşeni — TEK KAYNAK. Seans Takibi sayfası (seans-takibi-v2.js) ve
 * müşteri detay "Seanslar" sekmesi aynı bileşeni kullanır (kopya yok).
 * Import yolu her yerde AYNI olmalı:  import { seansTakibi } from '/js/lib/v2-seans.js?v=1';
 *
 * Eski /isletmeyonetim/seanstakip ekranının akışı birebir:
 *   - Satır: Müşteri · Seans Başlangıcı · Paket Adı · Seans Detayı (Toplam/Beklemede/Geldi/
 *     Gelmedi/Telafi sayaçları + lazerde Seans Dökümü PDF) · aç/kapa.
 *   - Açılınca hizmet (bölge) kartları: seans daireleri + boş hak daireleri + mini sayaç;
 *     lazer kartında "Cihaz Bilgileri".
 *   - Daire → "Seans Düzenle" (Kullanıldı/Kullanılmadı/Telafi/Beklemede); telafi daire →
 *     "Telafi Kullanılsın mı?"; boş daire → "Yeni Seans Kullanımı" (tarih seçimli).
 * Sayaçlar, durum etiketleri, PDF linki, lazer kararı SUNUCUDAN; burada yalnız görsel eşleme.
 * Veri GET/POST/PATCH/PUT /api/v2/seans* (Flutter ile aynı uçlar). Stil: css/seans-v2.css.
 */
import { api, el, esc, mesaj, yazSonuc, tarihStr } from '/js/lib/v2-ortak.js?v=1';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=2';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { tarihTr } from '/js/lib/v2-musteri.js?v=1';

// Yalnız görsel eşleme (durum kodları + etiketler API'deki `durumlar` sözlüğünden).
const GORSEL = {
  toplam: { ikon: 'bi-plus-lg' },
  beklemede: { ikon: 'bi-calendar3' },
  geldi: { ikon: 'bi-check-lg' },
  gelmedi: { ikon: 'bi-x-lg' },
  telafi: { ikon: 'bi-exclamation-triangle' },
};
const DAIRE_IKON = { beklemede: 'bi-circle', geldi: 'bi-check-circle-fill', gelmedi: 'bi-x-circle-fill', telafi: 'bi-exclamation-circle-fill' };
// Sayaç alanı → durum kodu (renk/ikon/etiket için).
const SAYAC_DURUM = { bekleyen: 'beklemede', kullanilan: 'geldi', kullanilmayan: 'gelmedi', telafi: 'telafi' };

/**
 * @param {HTMLElement} kap
 * @param {{salonId:number, musteriId?:number}} opt  musteriId verilirse yalnız o müşteri (Müşteri kolonu gizlenir)
 */
export function seansTakibi(kap, { salonId, musteriId = null }) {
  const satirlar = new Map();   // anahtar → satır (son çizilen sayfa)
  const acik = new Set();       // açık satır anahtarları (yeniden çizimde korunur)
  let etiket = { toplam: 'Toplam' };
  let grupDersi = false;

  kap.classList.add('st-kok');
  kap.innerHTML = `
    <div class="st-lejant" hidden></div>
    <div class="st-tablo"></div>`;

  const kolonlar = [
    ...(musteriId ? [] : [{ baslik: 'Müşteri', sirala: 'musteri' }]),
    { baslik: 'Seans Başlangıcı', sirala: 'baslangic' },
    { baslik: 'Paket Adı', sirala: 'ad' },
    { baslik: 'Seans Detayı' },
    { baslik: '', sinif: 'rc-sag' },
  ];

  const tablo = veriTablosu(el('.st-tablo', kap), {
    anahtar: 'seans-takibi',
    kolonlar,
    sirala: { alan: 'tarih', yon: 'desc' },
    birim: 'satış',
    bosMesaj: 'Seanslı paket veya hizmet satışı bulunamadı.',
    araIpucu: musteriId ? 'Paket / hizmet adı…' : 'Müşteri, telefon, paket / hizmet…',
    tabloSinif: 'st-tablosu',
    kaynak: async (p) => {
      const r = await api('GET', '/seans', { params: { ...p, salon_id: salonId, musteri_id: musteriId } });
      if (r.ok && r.govde && r.govde.basarili) sozlukAyarla(r.govde.veri); // satırlar çizilmeden önce etiketler hazır
      return r;
    },
    satir: (k) => satirHtml(k),
    cizildi: (liste) => {
      satirlar.clear();
      liste.forEach((k) => satirlar.set(k.anahtar, k));
    },
  });

  function sozlukAyarla(veri) {
    (veri.durumlar || []).forEach((d) => { etiket[d.kod] = d.etiket; });
    grupDersi = !!(veri.ayarlar && veri.ayarlar.grup_dersi_aktif);
    const lej = el('.st-lejant', kap);
    lej.innerHTML = `<i class="bi bi-info-circle"></i><span><b>Seans Detayı</b> sütunundaki sayaçlar:</span>`
      + ['toplam', 'beklemede', 'geldi', 'gelmedi', 'telafi'].map((d) => `<span class="st-hap st-${d}"><i class="bi ${GORSEL[d].ikon}"></i> ${esc(etiket[d] || d)}</span>`).join('');
    lej.hidden = false;
  }

  // ─────────────────────────── ÇİZİM ───────────────────────────

  const sayacHaplari = (s) => `<span class="st-hap st-toplam" title="${esc(etiket.toplam)}">${s.toplam} <i class="bi ${GORSEL.toplam.ikon}"></i></span>`
    + Object.entries(SAYAC_DURUM).map(([alan, d]) => `<span class="st-hap st-${d}" title="${esc(etiket[d] || d)}">${s[alan]} <i class="bi ${GORSEL[d].ikon}"></i></span>`).join('');

  function satirHtml(k) {
    const ac = acik.has(k.anahtar);
    const pdf = k.dokum_url ? `<a class="st-pdf" href="${esc(k.dokum_url)}" target="_blank" rel="noopener" title="Seans Dökümü (PDF)"><i class="bi bi-file-earmark-pdf"></i></a>` : '';
    return `
      <tr class="st-satir${ac ? ' acik' : ''}" data-anahtar="${esc(k.anahtar)}">
        ${musteriId ? '' : `<td class="st-musteri">${esc(k.musteri.ad || '—')}</td>`}
        <td>${tarihTr(k.baslangic_tarihi)}</td>
        <td class="st-ad">${esc(k.ad || '—')} <span class="rc-rozet rc-rozet-${k.tur === 'paket' ? 'mor' : 'mavi'}">${k.tur === 'paket' ? 'Paket' : 'Hizmet'}</span></td>
        <td><span class="st-haplar">${sayacHaplari(k.sayac)}${pdf}</span></td>
        <td class="rc-sag"><button type="button" class="st-ac" data-ac="${esc(k.anahtar)}" aria-label="Seansları göster"><i class="bi bi-chevron-${ac ? 'up' : 'down'}"></i></button></td>
      </tr>
      <tr class="st-detay" data-detay="${esc(k.anahtar)}"${ac ? '' : ' hidden'}>
        <td colspan="${kolonlar.length}">${kartlarHtml(k)}</td>
      </tr>`;
  }

  function kartlarHtml(k) {
    if (!k.hizmetler.length) return '<div class="st-bos">Bu pakette hizmet detayı bulunmamaktadır.</div>';
    return `<div class="st-kartlar">${k.hizmetler.map((h) => {
      const daireler = h.seanslar.map((s) => {
        const ipucu = `${tarihTr(s.tarih)} ${s.saat || ''} - ${etiket[s.durum] || s.durum}`;
        return `<button type="button" class="st-daire st-${s.durum}" data-seans="${s.id}" data-anahtar="${esc(k.anahtar)}" data-hizmet="${h.hizmet_id}" title="${esc(ipucu)}"><i class="bi ${DAIRE_IKON[s.durum]}"></i></button>`;
      }).join('') + Array.from({ length: h.bos_hak }, () =>
        `<button type="button" class="st-daire st-yeni" data-ekle="1" data-anahtar="${esc(k.anahtar)}" data-hizmet="${h.hizmet_id}" title="Yeni seans kullanımı"><i class="bi bi-circle"></i></button>`).join('');
      const mini = (ad, deger, d) => `<div><small>${ad}</small><strong class="st-y-${d}">${deger}</strong></div>`;
      return `
        <div class="st-kart">
          <div class="st-kart-bas"><h6>${esc(h.ad || '-')}</h6><span class="rc-rozet rc-rozet-yesil">${h.sayac.toplam} Seans</span></div>
          <div class="st-daireler">${daireler || '<span class="st-bos">Seans hakkı yok</span>'}</div>
          <div class="st-mini">${mini('Kullanıldı', h.sayac.kullanilan, 'geldi')}${mini('Kalan', h.sayac.bekleyen, 'beklemede')}${mini('Kullanılmadı', h.sayac.kullanilmayan, 'gelmedi')}${mini('Telafi', h.sayac.telafi, 'telafi')}</div>
          ${h.lazer ? `<button type="button" class="rc-btn st-cihaz-btn" data-cihaz="${esc(k.anahtar)}" data-hizmet="${h.hizmet_id}"><i class="bi bi-lightning-charge"></i> Cihaz Bilgileri</button>` : ''}
        </div>`;
    }).join('')}</div>`;
  }

  /** Yazma sonrası sunucunun döndüğü güncel satırı yerinde yeniden çiz (sayfa yeniden yüklenmez). */
  function satirYenile(satir) {
    if (!satir) { tablo.yenile(false); return; }
    satirlar.set(satir.anahtar, satir);
    const tr = kap.querySelector(`tr.st-satir[data-anahtar="${CSS.escape(satir.anahtar)}"]`);
    if (!tr) return;
    tr.nextElementSibling.remove();
    tr.outerHTML = satirHtml(satir);
  }

  // ─────────────────────────── OLAYLAR ───────────────────────────

  kap.addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.ac) return acKapat(b.dataset.ac);
    const k = satirlar.get(b.dataset.anahtar || b.dataset.cihaz);
    if (!k) return;
    const h = k.hizmetler.find((x) => String(x.hizmet_id) === b.dataset.hizmet);
    if (b.dataset.cihaz) return cihazAc(k, h);
    if (b.dataset.ekle) return yeniSeans(k, h);
    if (b.dataset.seans) {
      const s = h.seanslar.find((x) => String(x.id) === b.dataset.seans);
      return s.durum === 'telafi' ? telafiSor(k, h, s) : seansDuzenle(k, h, s);
    }
  });

  function acKapat(anahtar) {
    const ac = !acik.has(anahtar);
    ac ? acik.add(anahtar) : acik.delete(anahtar);
    const tr = kap.querySelector(`tr.st-satir[data-anahtar="${CSS.escape(anahtar)}"]`);
    tr.classList.toggle('acik', ac);
    tr.nextElementSibling.hidden = !ac;
    el('.st-ac i', tr).className = `bi bi-chevron-${ac ? 'up' : 'down'}`;
  }

  const bilgi = (k, h, tarihSaat) => `
    <div class="st-bilgi">
      <div><i class="bi bi-person"></i> ${esc(k.musteri.ad)}</div>
      <div><i class="bi bi-tag"></i> ${esc(h.ad)}</div>
      ${tarihSaat ? `<div><i class="bi bi-calendar3"></i> ${esc(tarihSaat)}</div>` : ''}
    </div>`;
  const durumBtn = (d, geldi, metin) => `<button type="button" class="rc-btn st-btn st-${d}" data-geldi="${geldi}"><i class="bi ${GORSEL[d].ikon}"></i> ${esc(metin)}</button>`;
  const tarihSaat = (s) => `${tarihTr(s.tarih)} | ${s.saat || '--:--'}`;

  /** Modal butonlarına (data-geldi) tek işleyici: geldi değeri → islem(geldi). */
  function butonlariBagla(ov, islem) {
    ov.querySelectorAll('[data-geldi]').forEach((b) => b.addEventListener('click', async () => {
      ov.querySelectorAll('[data-geldi]').forEach((x) => { x.disabled = true; });
      const g = b.dataset.geldi === 'null' ? null : (b.dataset.geldi === 'kapat' ? 'kapat' : parseInt(b.dataset.geldi, 10));
      if (g === 'kapat') { modalKapat(); return; }
      if (await islem(g)) modalKapat();
      else ov.querySelectorAll('[data-geldi]').forEach((x) => { x.disabled = false; });
    }));
  }

  async function durumYaz(s, geldi) {
    const r = await api('PATCH', `/seans/${s.id}`, { body: { geldi } });
    if (!yazSonuc(r, r.govde && r.govde.mesaj)) return false;
    satirYenile(r.govde.veri.satir);
    return true;
  }

  function seansDuzenle(k, h, s) {
    const ov = modalAc('Seans Düzenle', bilgi(k, h, tarihSaat(s))
      + `<div class="st-btnlar">${durumBtn('geldi', 1, 'Kullanıldı')}${durumBtn('gelmedi', 0, 'Kullanılmadı')}${durumBtn('telafi', 2, etiket.telafi || 'Telafi')}${durumBtn('beklemede', 'null', etiket.beklemede || 'Beklemede')}</div>`,
    '', { ikon: 'pencil-square', dar: true });
    butonlariBagla(ov, (g) => durumYaz(s, g));
  }

  function telafiSor(k, h, s) {
    const soru = grupDersi ? 'Müşteri telafi dersine katıldı mı?' : 'Müşteri telafi randevusuna geldi mi?';
    const ov = modalAc('Telafi Kullanılsın mı?', bilgi(k, h, tarihSaat(s))
      + `<p class="st-not">${esc(soru)} "Evet" derseniz seans <b>${esc(etiket.geldi || 'Geldi')}</b> olarak işaretlenir.</p>`
      + `<div class="st-btnlar">${durumBtn('geldi', 1, 'Evet')}<button type="button" class="rc-btn rc-btn-ikincil" data-geldi="kapat">Hayır</button></div>`,
    '', { ikon: 'exclamation-triangle', dar: true });
    butonlariBagla(ov, (g) => durumYaz(s, g));
  }

  function yeniSeans(k, h) {
    const ov = modalAc('Yeni Seans Kullanımı', bilgi(k, h, '')
      + `<div class="rc-alan st-tarih"><label for="stTarih">Seans Tarihi</label><input id="stTarih" type="text" value="${tarihStr(new Date())}" autocomplete="off"></div>`
      + `<div class="st-btnlar">${durumBtn('geldi', 1, 'Kullanıldı')}${durumBtn('gelmedi', 0, 'Kullanılmadı')}${durumBtn('telafi', 2, etiket.telafi || 'Telafi')}</div>`,
    '', { ikon: 'plus-circle', dar: true });
    const inp = el('#stTarih', ov);
    if (window.flatpickr) window.flatpickr(inp, { locale: 'tr', dateFormat: 'Y-m-d', altInput: true, altFormat: 'd.m.Y' });
    butonlariBagla(ov, async (geldi) => {
      const r = await api('POST', '/seans', { body: { tur: k.tur, kalem_id: k.id, hizmet_id: h.hizmet_id, geldi, tarih: inp.value || null } });
      if (!yazSonuc(r, r.govde && r.govde.mesaj)) return false;
      satirYenile(r.govde.veri.satir);
      return true;
    });
  }

  // ─────────────────────────── CİHAZ BİLGİLERİ (lazer) ───────────────────────────

  function cihazAc(k, h) {
    if (!h.seanslar.length) {
      mesaj('Bu bölgede henüz oluşmuş seans yok. Randevu "geldi" işaretlenince veya daireye tıklayıp "Kullanıldı" deyince seans oluşur; sonra cihaz bilgisi girilebilir.', 'bilgi');
      return;
    }
    // Varsayılan: en son "geldi" seans; hiç yoksa en son seans.
    const son = [...h.seanslar].reverse().find((s) => s.durum === 'geldi') || h.seanslar[h.seanslar.length - 1];
    const secenek = h.seanslar.map((s, i) => `<option value="${s.id}"${s.id === son.id ? ' selected' : ''}>${i + 1}. Seans${s.tarih ? ' — ' + tarihTr(s.tarih) : ''} ${s.durum === 'geldi' ? '✓' : (s.durum === 'gelmedi' ? '✗' : '•')}</option>`).join('');
    const alan = (id, ad) => `<div class="rc-alan"><label for="cf-${id}">${ad}</label><input id="cf-${id}" data-alan="${id}" type="text" maxlength="50"></div>`;
    const ov = modalAc('Cihaz / Seans Bilgileri', `
      <div class="st-cihaz-bilgi" id="cfBilgi">Yükleniyor…</div>
      ${h.seanslar.length > 1 ? `<div class="rc-alan"><label for="cfSeans">Seans Seç</label><select id="cfSeans">${secenek}</select></div>` : ''}
      <div class="st-bolge"><small>Uygulama Bölgesi</small><strong>${esc(h.ad)}</strong></div>
      <div class="rc-form-izgara">
        ${alan('enerji', 'Enerji (Jül)')}${alan('hiz', 'Hız')}${alan('ms', 'MS')}${alan('atis_sayisi', 'Atış Sayısı')}
        <div class="rc-alan"><label for="cf-personel">Uygulamayı Yapan</label><select id="cf-personel" data-alan="personel_id"></select></div>
        <div class="rc-alan"><label for="cf-notlar">Not (opsiyonel)</label><input id="cf-notlar" data-alan="notlar" type="text" maxlength="1000"></div>
      </div>`,
    '<button type="button" class="rc-btn rc-btn-ikincil" data-iptal>İptal</button><button type="button" class="rc-btn rc-btn-birincil" data-kaydet disabled><i class="bi bi-check-lg"></i> Kaydet</button>',
    { ikon: 'lightning-charge' });
    let seansId = son.id;
    const kaydetBtn = el('[data-kaydet]', ov);
    const yukle = async () => {
      kaydetBtn.disabled = true;
      el('#cfBilgi', ov).textContent = 'Yükleniyor…';
      ov.querySelectorAll('[data-alan]').forEach((i) => { i.value = ''; });
      const r = await api('GET', `/seans/${seansId}/cihaz`);
      if (!r.ok || !r.govde || !r.govde.basarili) { el('#cfBilgi', ov).innerHTML = `<span class="st-hata">${esc((r.govde && r.govde.mesaj) || 'Veri alınamadı.')}</span>`; return; }
      const { seans: s, personeller, bolgeler } = r.govde.veri;
      el('#cfBilgi', ov).innerHTML = `<b>${esc(s.musteri_adi)}</b>${s.seans_no ? ` · ${s.seans_no}. Seans` : ''}${s.tarih ? ` · ${tarihTr(s.tarih)}` : ''}`;
      const d = bolgeler[0] || {};
      const secili = String(d.personel_id || s.varsayilan_personel_id || '');
      el('#cf-personel', ov).innerHTML = '<option value="">— Seçiniz —</option>'
        + personeller.map((p) => `<option value="${p.id}"${String(p.id) === secili ? ' selected' : ''}>${esc(p.ad)}</option>`).join('');
      ['enerji', 'hiz', 'ms', 'atis_sayisi', 'notlar'].forEach((a) => { el(`[data-alan="${a}"]`, ov).value = d[a] || ''; });
      kaydetBtn.disabled = false;
    };
    const sec = el('#cfSeans', ov);
    if (sec) sec.addEventListener('change', () => { seansId = parseInt(sec.value, 10); yukle(); });
    el('[data-iptal]', ov).addEventListener('click', modalKapat);
    kaydetBtn.addEventListener('click', async () => {
      const b = { uygulama_bolgesi: h.ad };
      ov.querySelectorAll('[data-alan]').forEach((i) => { b[i.dataset.alan] = i.value || null; });
      b.personel_id = b.personel_id ? parseInt(b.personel_id, 10) : null;
      kaydetBtn.disabled = true;
      const r = await api('PUT', `/seans/${seansId}/cihaz`, { body: { bolgeler: [b] } });
      kaydetBtn.disabled = false;
      if (!yazSonuc(r, r.govde && r.govde.mesaj)) return;
      modalKapat();
      tablo.yenile(false); // PDF ikonu ilk cihaz kaydında belirir
    });
    yukle();
  }

  return { yenile: () => tablo.yenile(false) };
}
