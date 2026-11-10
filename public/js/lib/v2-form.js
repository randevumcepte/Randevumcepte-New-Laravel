/**
 * v2 FORM ARŞİVİ bileşeni — TEK KAYNAK. Form Yönetimi sayfası (formlar-v2.js) ve müşteri
 * detay "Sözleşmeler/Belgeler" sekmesi aynı bileşeni kullanır (kopya yok).
 * Import yolu her yerde AYNI olmalı:  import { formArsivi } from '/js/lib/v2-form.js?v=11';
 *
 * Arşiv sekmeleri (Tümü / Onaylananlar / Beklenenler / İptal Edilenler / Sözleşmeler & Harici Belgeler),
 * Form Gönder, Sözleşme Oluştur, Belge Ekle modalları. İş kuralları SUNUCUDA: satırın
 * durumu/rozeti/izinli işlemleri (`durum_bilgi`, `aksiyonlar`), form başına ek alanlar
 * (`ek_alanlar`), taksit planı ve belge linkleri API'den hazır gelir — web ve Flutter aynı
 * kararı görür. Stil: css/formlar-v2.css.
 *
 *   const arsiv = await formArsivi(kap, { salonId, musteri, aksiyonKap, ekSekme });
 *     musteri    : {id, ad, telefon} → yalnız o müşterinin belgeleri; modallarda müşteri sabit
 *     aksiyonKap : butonların konacağı yer (yoksa kap'ın üstünde araç çubuğu)
 *     ekSekme    : {ad, etiket, ikon, panel, ac()} → arşiv sekmelerinin yanına ek sekme
 *   arsiv.veri / arsiv.yetki(k) / arsiv.veriTazele()
 */
import { api, el, esc, mesaj, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=7';
import { apiForm } from '/js/lib/v2-dosya.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=8';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';
import { formGonderModali, formMusteriSecici, tlBagla, tlOku, tlYaz } from '/js/lib/v2-form-gonder.js?v=7';

const SEKMELER = [
  { ad: 'tumu', etiket: 'Tümü', ikon: 'bi-grid' },
  { ad: 'onayli', etiket: 'Onaylananlar', ikon: 'bi-check-circle' },
  { ad: 'beklenen', etiket: 'Beklenenler', ikon: 'bi-clock' },
  { ad: 'iptal', etiket: 'İptal Edilenler', ikon: 'bi-x-circle' },
  { ad: 'harici', etiket: 'Sözleşmeler & Harici Belgeler', ikon: 'bi-file-earmark' },
];

/** flatpickr (layout yükler) — görünüm d.m.Y, değer dataset.iso (Y-m-d). */
function tarihBagla(kap) {
  if (!window.flatpickr) return;
  kap.querySelectorAll('.f-tarih').forEach((i) => {
    window.flatpickr(i, {
      locale: 'tr', dateFormat: 'd.m.Y', allowInput: false, disableMobile: true,
      onChange: (d) => { const t = d[0]; i.dataset.iso = t ? `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}` : ''; i.dispatchEvent(new Event('change')); },
    });
  });
}

/**
 * İmza tuvali (fare, kalem, dokunmatik). Akıcı çizgi: tarayıcının ara noktaları (getCoalescedEvents) alınır ve noktalar
 * orta nokta eğrileriyle (quadraticCurveTo) birleştirilir; düz çizgi parçaları köşeli görünürdü. Tuval gerçek boyutuna ve
 * piksel yoğunluğuna göre kurulur (pencere açılış animasyonunda ölçülse bile çizime başlarken düzeltilir).
 */
function imzaAlani(cv) {
  const ctx = cv.getContext('2d');
  let dolu = false;
  let noktalar = null;
  const kalem = () => {
    ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    ctx.strokeStyle = ctx.fillStyle = '#1f1f2e';
  };
  const boyutla = () => {
    const r = cv.getBoundingClientRect(), o = window.devicePixelRatio || 1;
    const g = Math.round(r.width * o), y = Math.round(r.height * o);
    if (!g || !y || (cv.width === g && cv.height === y)) return;
    const eski = dolu ? cv.toDataURL('image/png') : null; // boyut değişirse çizim korunur
    cv.width = g; cv.height = y;
    ctx.setTransform(o, 0, 0, o, 0, 0);
    kalem();
    if (eski) { const img = new Image(); img.onload = () => ctx.drawImage(img, 0, 0, r.width, r.height); img.src = eski; }
  };
  if (window.ResizeObserver) new ResizeObserver(boyutla).observe(cv); else requestAnimationFrame(boyutla);
  const nokta = (e) => { const r = cv.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
  const orta = (a, b) => [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2];
  const ciz = () => {
    const n = noktalar.length;
    ctx.beginPath();
    if (n === 1) { ctx.arc(noktalar[0][0], noktalar[0][1], ctx.lineWidth / 2, 0, Math.PI * 2); ctx.fill(); return; }
    if (n === 2) { ctx.moveTo(...noktalar[0]); ctx.lineTo(...orta(noktalar[0], noktalar[1])); ctx.stroke(); return; }
    const [a, b, c] = noktalar.slice(-3);
    ctx.moveTo(...orta(a, b));
    ctx.quadraticCurveTo(b[0], b[1], ...orta(b, c));
    ctx.stroke();
  };
  const bitir = () => {
    if (noktalar && noktalar.length > 1) { // son orta noktadan kalemin kalktığı noktaya
      const [a, b] = noktalar.slice(-2);
      ctx.beginPath(); ctx.moveTo(...orta(a, b)); ctx.lineTo(...b); ctx.stroke();
    }
    noktalar = null;
  };
  cv.addEventListener('pointerdown', (e) => {
    e.preventDefault();
    boyutla();
    cv.setPointerCapture(e.pointerId);
    noktalar = [nokta(e)];
    ciz();
    dolu = true;
  });
  cv.addEventListener('pointermove', (e) => {
    if (!noktalar) return;
    const olaylar = typeof e.getCoalescedEvents === 'function' ? e.getCoalescedEvents() : [];
    for (const p of (olaylar.length ? olaylar : [e])) { noktalar.push(nokta(p)); ciz(); }
  });
  ['pointerup', 'pointercancel'].forEach((t) => cv.addEventListener(t, bitir));
  return {
    dolu: () => dolu,
    veri: () => cv.toDataURL('image/png'),
    temizle: () => {
      ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.clearRect(0, 0, cv.width, cv.height); ctx.restore();
      dolu = false; noktalar = null;
    },
  };
}

/**
 * @param {HTMLElement} kap
 * @param {{salonId:number, musteri?:{id:number,ad:string,telefon?:string}, aksiyonKap?:HTMLElement,
 *          ekSekme?:{ad:string,etiket:string,ikon:string,panel:HTMLElement,ac:Function}}} opt
 */
export async function formArsivi(kap, { salonId, musteri = null, aksiyonKap = null, ekSekme = null }) {
  let veri = null;   // GET /form/form-verisi (yetkiler, taslaklar, hizmetler, paketler, sözleşme, katalog)
  let sekme = 'tumu';
  let arsivTablo = null;
  const yetki = (k) => !!(veri && veri.yetkiler && veri.yetkiler[k]);

  const r = await api('GET', '/form/form-verisi', { params: { salon_id: salonId } });
  if (!r.ok || !r.govde?.basarili) {
    kap.innerHTML = `<div class="rc-ipucu f-bos">${esc(r.govde?.mesaj || `Hata (${r.kod})`)}</div>`;
    return null;
  }
  veri = r.govde.veri;

  /** Müşteri alanı: müşteri kartında sabit seçili (Değiştir yok), sayfada aramalı seçici. */
  const secici = (kapEl, telEl) => formMusteriSecici(salonId, musteri, kapEl, telEl);

  // ─────────────────────────── İSKELET ───────────────────────────

  const btn = [];
  if (yetki('gonder')) {
    btn.push('<button class="rc-btn rc-btn-yesil" data-fa="gonder"><i class="bi bi-send"></i> Form Gönder</button>');
    btn.push('<button class="rc-btn rc-btn-mavi" data-fa="sozlesme"><i class="bi bi-file-earmark-text"></i> Sözleşme Oluştur</button>');
  }
  if (yetki('olustur')) btn.push('<button class="rc-btn rc-btn-birincil" data-fa="belge"><i class="bi bi-plus-lg"></i> Belge Ekle</button>');

  kap.innerHTML = `
    ${aksiyonKap || !btn.length ? '' : `<div class="f-arac">${btn.join('')}</div>`}
    <nav class="rc-sekmeler" role="tablist">
      ${SEKMELER.map((s) => `<button class="rc-sekme${s.ad === sekme ? ' aktif' : ''}" data-sekme="${s.ad}" role="tab">
        <i class="bi ${s.ikon}"></i> ${s.etiket} <span class="rc-sayac" data-sayac="${s.ad}">·</span></button>`).join('')}
      ${ekSekme ? `<button class="rc-sekme" data-sekme="${esc(ekSekme.ad)}" role="tab"><i class="bi ${esc(ekSekme.ikon)}"></i> ${esc(ekSekme.etiket)}</button>` : ''}
    </nav>
    <section data-f="arsiv"></section>`;
  if (aksiyonKap) aksiyonKap.innerHTML = btn.join('');
  const arsivEl = el('[data-f="arsiv"]', kap);
  if (ekSekme) kap.appendChild(ekSekme.panel);

  const butonKok = aksiyonKap || kap;
  const ac = { gonder: formGonderAc, sozlesme: sozlesmeAc, belge: belgeEkleAc };
  butonKok.addEventListener('click', (e) => {
    const b = e.target.closest('[data-fa]');
    if (b) ac[b.dataset.fa]();
  });

  el('.rc-sekmeler', kap).addEventListener('click', (e) => {
    const b = e.target.closest('[data-sekme]');
    if (!b) return;
    kap.querySelectorAll('.rc-sekme').forEach((x) => x.classList.toggle('aktif', x === b));
    const ek = ekSekme && b.dataset.sekme === ekSekme.ad;
    arsivEl.hidden = ek;
    if (ekSekme) ekSekme.panel.hidden = !ek;
    if (ek) { ekSekme.ac(); return; }
    sekme = b.dataset.sekme;
    arsivTablo.yenile();
  });

  arsivTablo = veriTablosu(arsivEl, {
    anahtar: 'formlar',
    tabloSinif: 'f-tablo',
    birim: 'kayıt',
    araIpucu: musteri ? 'Form adı…' : 'Müşteri, form adı…',
    bosMesaj: 'Kayıt bulunamadı.',
    sirala: { alan: 'tarih', yon: 'desc' },
    kolonlar: [
      ...(musteri ? [] : [{ baslik: 'Müşteri', sirala: 'musteri' }]), { baslik: 'Başlık', sirala: 'baslik' },
      { baslik: 'Oluşturulma Tarihi', sirala: 'tarih' }, { baslik: 'Belge' },
      { baslik: 'Durum', sinif: 'rc-orta' }, { baslik: 'İşlemler', sinif: 'rc-orta' },
    ],
    satir: arsivSatir,
    kaynak: (p) => api('GET', '/form/arsiv', { params: { ...p, salon_id: salonId, sekme, musteri_id: musteri?.id || null } }),
    cizildi: (_, v) => sayaclariYaz(v && v.ozet),
  });
  arsivEl.addEventListener('click', arsivAksiyon);
  arsivTablo.yenile();

  function sayaclariYaz(ozet) {
    if (!ozet) return;
    kap.querySelectorAll('[data-sayac]').forEach((s) => { s.textContent = ozet[s.dataset.sayac] ?? 0; });
  }

  // ─────────────────────────── ARŞİV LİSTESİ ───────────────────────────

  function arsivSatir(a) {
    const ak = a.aksiyonlar || {};
    const b = a.belge || {};
    const btn = (aks, ikon, baslik, sinif = '') => `<button class="rc-islem ${sinif}" data-aksiyon="${aks}" data-id="${a.id}" title="${baslik}" aria-label="${baslik}"><i class="bi ${ikon}"></i></button>`;
    const islemler = [
      ak.indir ? `<a class="rc-islem" href="${esc(b.indir_url)}" target="_blank" rel="noopener" title="İndir" aria-label="İndir"><i class="bi bi-download"></i></a>` : '',
      ak.yazdir ? `<a class="rc-islem" href="${esc(b.yazdir_url)}" target="_blank" rel="noopener" title="Yazdır" aria-label="Yazdır"><i class="bi bi-printer"></i></a>` : '',
      ak.gonder ? btn('gonder', 'bi-send', 'Formu Gönder') : '',
      ak.onayla ? btn('onayla', 'bi-check-lg', 'Onayla', 'rc-olumlu') : '',
      ak.iptal ? btn('iptal', 'bi-x-lg', 'İptal Et', 'rc-tehlike') : '',
    ].join('');
    const d = a.durum_bilgi || {};

    return `<tr>
      ${musteri ? '' : `<td>${esc(a.musteri?.name || '-')}</td>`}
      <td>${esc(a.baslik || '-')}</td>
      <td class="rc-nowrap">${esc(a.tarih || '-')}</td>
      <td><a class="f-belge" href="${esc(b.goruntule_url)}" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf"></i> ${esc(a.baslik || 'Belge')}</a></td>
      <td class="rc-orta"><span class="rc-rozet rc-rozet-${esc(d.renk || 'gri')}">${esc(d.etiket || '-')}</span></td>
      <td class="rc-orta"><span class="rc-islemler">${islemler}</span></td>
    </tr>`;
  }

  async function arsivAksiyon(e) {
    const b = e.target.closest('button[data-aksiyon]');
    if (!b) return;
    const id = b.dataset.id;
    // Onay penceresi gereken işlemler: geri alınamaz iptal ve yeniden gönderim (Flutter: formIptalOnayi aynı metinler).
    const ONAYLI = {
      iptal: ['Bu işlem geri alınamaz.', { baslik: 'Emin misiniz?', tamam: 'İptal Et', iptal: 'Vazgeç' }],
      gonder: ['Form müşteriye yeni bir onay koduyla tekrar gönderilecek. Onaylı ya da iptal edilmiş form yeniden "bekleyen" olur.',
        { baslik: 'Formu Tekrar Gönder', tamam: 'Müşteriye Gönder', tehlike: false }],
    };
    const sor = ONAYLI[b.dataset.aksiyon];
    if (sor && !(await onay(...sor))) return;
    b.disabled = true;
    const r = await api('POST', `/form/arsiv/${id}/${b.dataset.aksiyon}`);
    if (yazSonuc(r, r.govde?.mesaj || 'İşlem Başarılı')) arsivTablo.yenile(false);
    else b.disabled = false;
  }

  // ─────────────────────────── FORM GÖNDER ───────────────────────────

  // function bildirimi (hoisting): yukarıdaki `ac` haritası tanımlanırken erişilir; const olursa TDZ hatası.
  function formGonderAc() {
    formGonderModali({ salonId, veri, musteri, onGonderildi: () => arsivTablo.yenile(false) });
  }

  // ─────────────────────────── SÖZLEŞME OLUŞTUR ───────────────────────────

  function sozlesmeAc() {
    const s = veri.sozlesme || {};
    const hizmetler = (veri.hizmetler || []).map((h) => `<option value="${h.id}" data-fiyat="${h.fiyat ?? ''}">${esc(h.ad)}</option>`).join('');
    const paketler = (veri.paketler || []).map((p) => `<option value="${p.paket_id}" data-fiyat="${p.fiyat ?? ''}" data-seans="${p.seans ?? 1}">${esc(p.ad)}</option>`).join('');
    const odeme = (veri.odeme_sekilleri || []).map((o) => `<option value="${o.kod}">${esc(o.ad)}</option>`).join('');

    const govde = `
      <div class="rc-form-izgara">
        <div class="rc-alan"><label>Müşteri *</label><div id="szMusteri"></div></div>
        <div class="rc-alan"><label>Cep Telefon *</label><input type="tel" id="szTel" autocomplete="off"></div>
        <div class="rc-alan"><label>Hizmet Seç</label><select id="szHizmet"><option value="">— Hizmet seçin —</option>${hizmetler}</select></div>
        <div class="rc-alan"><label>Paket Seç <small>(opsiyonel)</small></label><select id="szPaket"><option value="">— Paket seçin —</option>${paketler}</select></div>
      </div>
      <div class="f-izgara-3">
        <div class="rc-alan"><label>Seans Sayısı</label><input type="number" id="szSeans" min="1" value="1"></div>
        <div class="rc-alan"><label>Toplam Ücret (₺) *</label><input id="szToplam" class="f-tl" inputmode="decimal" placeholder="0,00"></div>
        <div class="rc-alan"><label>Kapora (₺)</label><input id="szKapora" class="f-tl" inputmode="decimal" placeholder="0,00"></div>
        <div class="rc-alan"><label>Ödeme Şekli</label><select id="szOdeme" data-arama="yok">${odeme}</select></div>
        <div class="rc-alan f-iki"><label>Hizmet/Paket Geçerlilik Tarihi <small>(opsiyonel — son kullanım)</small></label><input id="szGecerlilik" class="f-tarih" autocomplete="off"></div>
      </div>
      <div class="f-kutu" id="szTaksit" hidden>
        <div class="f-kutu-baslik"><i class="bi bi-calendar3"></i> Taksit Planı</div>
        <div class="f-izgara-3">
          <div class="rc-alan"><label>Taksit Sayısı</label><input type="number" id="szTaksitSayi" min="1" max="60" placeholder="Örn: 6"></div>
          <div class="rc-alan"><label>Taksit Tutarı (₺) <small>(oto)</small></label><input id="szTaksitTutar" class="f-tl" inputmode="decimal" placeholder="otomatik"></div>
          <div class="rc-alan"><label>İlk Taksit Tarihi</label><input id="szIlkTaksit" class="f-tarih" autocomplete="off"></div>
        </div>
        <div id="szPlan" class="f-plan"><span class="rc-ipucu">Taksit sayısı girin…</span></div>
      </div>
      <div class="rc-alan rc-tam f-aralik">
        <label>Sözleşme Şartları * <small>(müşteriye gösterilecek metin — düzenleyebilirsiniz)</small></label>
        <textarea id="szMetin" rows="10" class="f-mono"></textarea>
        <div class="f-satir">
          <span class="rc-ipucu">İpucu: Müşteri adı, hizmet, fiyat ve kapora otomatik olarak metnin üstünde tabloda gösterilir — burada sadece sözleşme şartlarını yazın.</span>
          <button type="button" class="rc-btn rc-btn-ikincil" id="szVarsayilan"><i class="bi bi-bookmark-check"></i> Bu metni varsayılan yap</button>
        </div>
      </div>
      <div class="rc-alan rc-tam f-aralik"><label>Ek Not <small>(opsiyonel)</small></label><textarea id="szNot" rows="2" placeholder="Örn: Seans aralığı 15 günü geçemez."></textarea></div>
      <div class="f-kutu">
        <div class="f-kutu-baslik"><i class="bi bi-pen"></i> Salon Yetkilisi İmzası *</div>
        <div class="rc-ipucu">Sözleşme çift taraflı imzalanır. Salon adına aşağıyı doldurup imzalayın; müşteri kendi imzasını SMS ile gelen link üzerinden atacaktır.</div>
        <div class="rc-form-izgara f-aralik">
          <div class="rc-alan"><label>Yetkili Ad Soyad</label><input id="szYetkiliAd" value="${esc(s.yetkili_ad || '')}"></div>
          <div class="rc-alan"><label>Yetkili Telefon</label><input id="szYetkiliTel" type="tel" value="${esc(s.yetkili_telefon || '')}"></div>
        </div>
        <div class="f-imza"><canvas id="szImza"></canvas><button type="button" class="rc-btn rc-btn-ikincil" id="szImzaTemizle"><i class="bi bi-eraser"></i> Temizle</button></div>
      </div>`;
    const alt = `<button class="rc-btn rc-btn-ikincil" id="szIptal">İptal</button>
      <button class="rc-btn rc-btn-birincil" id="szGonder"><i class="bi bi-send"></i> Oluştur ve Müşteriye Gönder</button>`;
    const ov = modalAc('Hizmet Sözleşmesi Oluştur', govde, alt, { ikon: 'file-earmark-text', sabit: true });
    tlBagla(ov);
    tarihBagla(ov);
    el('#szMetin', ov).value = s.sozlesme_metni || '';
    const imza = imzaAlani(el('#szImza', ov));
    el('#szImzaTemizle', ov).onclick = imza.temizle;
    const ms = secici(el('#szMusteri', ov), el('#szTel', ov));

    const secOpt = (id) => el(id, ov).selectedOptions[0];
    el('#szPaket', ov).addEventListener('change', () => {
      const o = secOpt('#szPaket');
      if (!o || !o.value) return;
      if (o.dataset.fiyat !== '') el('#szToplam', ov).value = tlYaz(o.dataset.fiyat);
      el('#szSeans', ov).value = o.dataset.seans || 1;
      planYenile();
    });
    el('#szHizmet', ov).addEventListener('change', () => {
      const o = secOpt('#szHizmet');
      if (o && o.value && o.dataset.fiyat !== '' && !el('#szToplam', ov).value.trim()) {
        el('#szToplam', ov).value = tlYaz(o.dataset.fiyat);
        planYenile();
      }
    });

    // Taksit önizlemesi: sunucudaki AYNI hesap (GET /form/sozlesme/odeme-plani).
    let planZaman, planNo = 0;
    function planYenile() {
      const taksitli = el('#szOdeme', ov).value === 'taksit';
      el('#szTaksit', ov).hidden = !taksitli;
      const n = parseInt(el('#szTaksitSayi', ov).value, 10);
      if (!taksitli) return;
      if (!n || n < 1) { el('#szPlan', ov).innerHTML = '<span class="rc-ipucu">Taksit sayısı girin…</span>'; return; }
      clearTimeout(planZaman);
      planZaman = setTimeout(async () => {
        const no = ++planNo;
        const r = await api('GET', '/form/sozlesme/odeme-plani', { params: {
          salon_id: salonId, taksit_sayisi: n, toplam_ucret: el('#szToplam', ov).value, kapora: el('#szKapora', ov).value,
          taksit_tutari: el('#szTaksitTutar', ov).value, ilk_taksit_tarihi: el('#szIlkTaksit', ov).dataset.iso || '',
        } });
        if (no !== planNo || !r.ok || !r.govde?.basarili) return;
        const p = r.govde.veri;
        const kalan = (tlOku(el('#szToplam', ov).value) || 0) - (tlOku(el('#szKapora', ov).value) || 0);
        el('#szPlan', ov).innerHTML = `
          <div class="f-plan-ozet">Kalan bakiye <b>${tlYaz(Math.max(0, kalan))} ₺</b> — <b>${p.plan.length}</b> taksit x ~<b>${tlYaz(p.taksit_tutari)} ₺</b></div>
          <table class="rc-tablo f-plan-tablo"><thead><tr><th>#</th><th>Vade Tarihi</th><th class="rc-sag">Tutar</th></tr></thead>
          <tbody>${p.plan.map((t) => `<tr><td>${t.sira}</td><td>${t.tarih ? t.tarih.split('-').reverse().join('.') : '—'}</td><td class="rc-sag">${tlYaz(t.tutar)} ₺</td></tr>`).join('')}</tbody></table>`;
      }, 300);
    }
    ['#szOdeme', '#szTaksitSayi', '#szTaksitTutar', '#szIlkTaksit', '#szToplam', '#szKapora'].forEach((id) => {
      el(id, ov).addEventListener('change', planYenile);
      el(id, ov).addEventListener('input', planYenile);
    });

    el('#szVarsayilan', ov).onclick = async () => {
      const metin = el('#szMetin', ov).value.trim();
      if (!metin) return mesaj('Önce sözleşme şartlarını yazın.', 'hata');
      const r = await api('PUT', '/form/sozlesme-metni', { body: { salon_id: salonId, sozlesme_metni: metin } });
      if (yazSonuc(r, r.govde?.mesaj)) veri.sozlesme = { ...veri.sozlesme, ...r.govde.veri };
    };

    el('#szIptal', ov).onclick = modalKapat;
    el('#szGonder', ov).onclick = async (e) => {
      const m = ms.secili();
      if (!m || !el('#szTel', ov).value.trim()) return mesaj('Müşteri ve cep telefon zorunlu.', 'hata');
      if (!el('#szToplam', ov).value.trim()) return mesaj('Toplam ücret zorunludur.', 'hata');
      if (!el('#szMetin', ov).value.trim()) return mesaj('Sözleşme şartları zorunludur.', 'hata');
      if (!imza.dolu()) return mesaj('Salon yetkilisi imzası zorunludur. Lütfen imza alanına imzanızı atın.', 'hata');
      const b = e.currentTarget;
      b.disabled = true;
      const r = await api('POST', '/form/sozlesme', { body: {
        salon_id: salonId, user_id: m.id, cep_telefon: el('#szTel', ov).value.trim(),
        hizmet_id: el('#szHizmet', ov).value || null, paket_id: el('#szPaket', ov).value || null,
        seans_sayisi: el('#szSeans', ov).value, toplam_ucret: el('#szToplam', ov).value, kapora: el('#szKapora', ov).value,
        odeme_sekli: el('#szOdeme', ov).value, gecerlilik_tarihi: el('#szGecerlilik', ov).dataset.iso || null,
        taksit_sayisi: el('#szTaksitSayi', ov).value, taksit_tutari: el('#szTaksitTutar', ov).value,
        ilk_taksit_tarihi: el('#szIlkTaksit', ov).dataset.iso || null,
        sozlesme_metni: el('#szMetin', ov).value, sozlesme_notu: el('#szNot', ov).value,
        salon_yetkili_ad: el('#szYetkiliAd', ov).value, salon_yetkili_telefon: el('#szYetkiliTel', ov).value,
        salon_imza: imza.veri(),
      } });
      if (yazSonuc(r, r.govde?.mesaj)) { modalKapat(); arsivTablo.yenile(false); } else b.disabled = false;
    };
  }

  // ─────────────────────────── BELGE EKLE (harici) ───────────────────────────

  async function belgeEkleAc() {
    const govde = `
      <div class="rc-form-izgara">
        <div class="rc-alan rc-tam"><label>Form Başlığı</label><input id="hbBaslik" maxlength="250"></div>
        <div class="rc-alan"><label>Müşteri</label><div id="hbMusteri"></div></div>
        <div class="rc-alan"><label>İşlemi Yapan Personel</label><select id="hbPersonel"><option value="">Personel seçin</option></select></div>
        <div class="rc-alan rc-tam"><label>Formu Yükle</label><input type="file" id="hbDosya" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"></div>
      </div>`;
    const alt = `<button class="rc-btn rc-btn-ikincil" id="hbIptal">Vazgeç</button>
      <button class="rc-btn rc-btn-yesil" id="hbKaydet"><i class="bi bi-check-lg"></i> Kaydet</button>`;
    const ov = modalAc('Harici Belge Ekle', govde, alt, { ikon: 'file-earmark-arrow-up', dar: true });
    const s = secici(el('#hbMusteri', ov), null);

    const p = await api('GET', '/personel', { params: { salon_id: salonId, tumu: 1 } });
    if (p.ok && p.govde?.basarili) {
      el('#hbPersonel', ov).insertAdjacentHTML('beforeend', (p.govde.veri.data || [])
        .filter((x) => x.aktif).map((x) => `<option value="${x.id}">${esc(x.personel_adi)}</option>`).join(''));
    }

    el('#hbIptal', ov).onclick = modalKapat;
    el('#hbKaydet', ov).onclick = async (e) => {
      const m = s.secili();
      if (!el('#hbBaslik', ov).value.trim()) return mesaj('Form başlığı zorunludur.', 'hata');
      if (!m) return mesaj('Müşteri/danışan seçiniz.', 'hata');
      if (!el('#hbPersonel', ov).value) return mesaj('İşlemi yapacak personeli seçiniz.', 'hata');
      const dosya = el('#hbDosya', ov).files[0];
      if (!dosya) return mesaj('Yüklenecek dosyayı seçiniz.', 'hata');
      const fd = new FormData();
      fd.append('salon_id', salonId);
      fd.append('baslik', el('#hbBaslik', ov).value.trim());
      fd.append('user_id', m.id);
      fd.append('personel_id', el('#hbPersonel', ov).value);
      fd.append('dosya', dosya);
      e.currentTarget.disabled = true;
      const r = await apiForm('/form/arsiv/harici', fd);
      if (yazSonuc(r, r.govde?.mesaj)) { modalKapat(); arsivTablo.yenile(false); } else e.currentTarget.disabled = false;
    };
  }

  return {
    get veri() { return veri; },
    yetki,
    /** Şablon değişince Form Gönder listesi (taslaklar) tazelensin. */
    async veriTazele() {
      const t = await api('GET', '/form/form-verisi', { params: { salon_id: salonId } });
      if (t.ok && t.govde?.basarili) veri = t.govde.veri;
    },
  };
}
