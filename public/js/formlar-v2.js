/**
 * Form Yönetimi (v2) — build'siz island (sade ES module).
 *
 * Eski /isletmeyonetim/arsivyonetimi ekranının v2 karşılığı: arşiv sekmeleri (Tümü /
 * Onaylananlar / Beklenenler / İptal Edilenler / Harici Belgeler), Form Gönder, Sözleşme
 * Oluştur, Belge Ekle modalları ve Form Şablonları oluşturucu (eskiden iframe).
 *
 * İş kuralları SUNUCUDA: satırın durumu/rozeti/izinli işlemleri (`durum_bilgi`,
 * `aksiyonlar`), form başına ek alanlar (`ek_alanlar`), şablon eleman kataloğu, taksit
 * planı ve belge linkleri API'den hazır gelir — web ve Flutter aynı kararı görür.
 * PDF görüntüle/indir eski paneldedir (oturumlu link). Yazma uçları FORM_V2_YAZMA_AKTIF
 * kapalıyken 409 döner (toast).
 */

import { qp, api, el, esc, mesaj, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=1';
import { apiForm } from '/js/lib/v2-dosya.js?v=1';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=2';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { musteriSecici } from '/js/lib/v2-secici.js?v=1';

const kok = document.getElementById('rc-formlar');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
const musteriFiltre = parseInt(qp.get('musteri_id'), 10) || null;

const SEKMELER = [
  { ad: 'tumu', etiket: 'Tümü', ikon: 'bi-grid' },
  { ad: 'onayli', etiket: 'Onaylananlar', ikon: 'bi-check-circle' },
  { ad: 'beklenen', etiket: 'Beklenenler', ikon: 'bi-clock' },
  { ad: 'iptal', etiket: 'İptal Edilenler', ikon: 'bi-x-circle' },
  { ad: 'harici', etiket: 'Harici Belgeler', ikon: 'bi-file-earmark' },
];

let veri = null;          // GET /form/form-verisi (yetkiler, taslaklar, hizmetler, paketler, sözleşme, katalog)
let sekme = 'tumu';
let arsivTablo = null;
let sablonTablo = null;

const yetki = (k) => !!(veri && veri.yetkiler && veri.yetkiler[k]);

/** "1.234,56" görünümü (tr-TR); boş/NaN → '' */
const tlYaz = (n) => (n === null || n === undefined || n === '' || isNaN(Number(n)) ? ''
  : Number(n).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
/** tr-TR para girdisini sayıya çevir (sunucu da aynı biçimi kabul eder; önizleme için). */
const tlOku = (s) => {
  const v = String(s ?? '').trim();
  if (!v) return null;
  const n = Number(v.includes(',') ? v.replace(/\./g, '').replace(',', '.') : v);
  return isNaN(n) ? null : n;
};

// ─────────────────────────── İSKELET ───────────────────────────

function iskeletCiz() {
  const btn = [];
  if (yetki('gonder')) {
    btn.push('<button class="rc-btn rc-btn-yesil" id="fFormGonder"><i class="bi bi-send"></i> Form Gönder</button>');
    btn.push('<button class="rc-btn rc-btn-mavi" id="fSozlesme"><i class="bi bi-file-earmark-text"></i> Sözleşme Oluştur</button>');
  }
  if (yetki('olustur')) {
    btn.push('<button class="rc-btn rc-btn-birincil" id="fBelge"><i class="bi bi-plus-lg"></i> Belge Ekle</button>');
  }

  kok.innerHTML = `
    <div class="f-kabuk">
      <div class="rc-sayfa-ust">
        <div class="rc-sayfa-baslik">
          <span class="rc-sayfa-ikon"><i class="bi bi-folder2-open"></i></span>
          <div><h1>Form Yönetimi</h1><div class="rc-sayfa-alt">Form, sözleşme ve belge arşivi</div></div>
        </div>
        <div class="rc-sayfa-aksiyon">${btn.join('')}</div>
      </div>

      <nav class="rc-sekmeler" role="tablist">
        ${SEKMELER.map((s) => `<button class="rc-sekme${s.ad === sekme ? ' aktif' : ''}" data-sekme="${s.ad}" role="tab">
          <i class="bi ${s.ikon}"></i> ${s.etiket} <span class="rc-sayac" data-sayac="${s.ad}">·</span></button>`).join('')}
        <button class="rc-sekme" data-sekme="sablonlar" role="tab"><i class="bi bi-ui-checks"></i> Form Şablonları</button>
      </nav>

      <section id="fArsiv"></section>
      <section id="fSablonlar" hidden>
        <div class="f-sablon-ust">
          <span class="rc-ipucu">Dinamik onam / sözleşme şablonları. Sıra, Form Gönder listesindeki sırayı da belirler.</span>
          ${yetki('olustur') ? '<button class="rc-btn rc-btn-yesil" id="fYeniSablon"><i class="bi bi-plus-lg"></i> Yeni Form Şablonu</button>' : ''}
        </div>
        <div id="fSablonTablo"></div>
      </section>
    </div>`;

  el('.rc-sekmeler', kok).addEventListener('click', (e) => {
    const b = e.target.closest('[data-sekme]');
    if (b) sekmeAc(b.dataset.sekme);
  });
  el('#fFormGonder')?.addEventListener('click', formGonderAc);
  el('#fSozlesme')?.addEventListener('click', sozlesmeAc);
  el('#fBelge')?.addEventListener('click', belgeEkleAc);
  el('#fYeniSablon')?.addEventListener('click', () => sablonAc(null));

  arsivTablo = veriTablosu(el('#fArsiv'), {
    anahtar: 'formlar',
    tabloSinif: 'f-tablo',
    birim: 'kayıt',
    araIpucu: 'Müşteri, form adı…',
    bosMesaj: 'Kayıt bulunamadı.',
    sirala: { alan: 'tarih', yon: 'desc' },
    kolonlar: [
      { baslik: 'Müşteri', sirala: 'musteri' }, { baslik: 'Başlık', sirala: 'baslik' },
      { baslik: 'Oluşturulma Tarihi', sirala: 'tarih' }, { baslik: 'Belge' },
      { baslik: 'Durum', sinif: 'rc-orta' }, { baslik: 'İşlemler', sinif: 'rc-orta' },
    ],
    satir: arsivSatir,
    kaynak: (p) => api('GET', '/form/arsiv', { params: { ...p, salon_id: salonId, sekme, musteri_id: musteriFiltre } }),
    cizildi: (_, v) => sayaclariYaz(v && v.ozet),
  });
  el('#fArsiv').addEventListener('click', arsivAksiyon);

  sablonTablo = veriTablosu(el('#fSablonTablo'), {
    anahtar: 'form-sablon',
    birim: 'şablon',
    araIpucu: 'Form adı…',
    bosMesaj: 'Henüz form şablonu oluşturmadınız.',
    kolonlar: [
      { baslik: 'Sıra', sinif: 'rc-orta' }, { baslik: 'Form Adı' }, { baslik: 'Eleman Sayısı' },
      { baslik: 'Oluşturulma' }, { baslik: 'İşlemler', sinif: 'rc-orta' },
    ],
    araMetni: (s) => s.form_adi,
    satir: sablonSatir,
  });
  el('#fSablonTablo').addEventListener('click', sablonAksiyon);
}

function sekmeAc(ad) {
  kok.querySelectorAll('.rc-sekme').forEach((b) => b.classList.toggle('aktif', b.dataset.sekme === ad));
  const sablon = ad === 'sablonlar';
  el('#fArsiv').hidden = sablon;
  el('#fSablonlar').hidden = !sablon;
  if (sablon) { sablonlariYukle(); return; }
  sekme = ad;
  arsivTablo.yenile();
}

function sayaclariYaz(ozet) {
  if (!ozet) return;
  kok.querySelectorAll('[data-sayac]').forEach((s) => { s.textContent = ozet[s.dataset.sayac] ?? 0; });
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
    <td>${esc(a.musteri?.name || '-')}</td>
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
  if (b.dataset.aksiyon === 'gonder') {
    const tamam = await onay('Form müşteriye yeni bir onay koduyla tekrar gönderilecek. Onaylı ya da iptal edilmiş form yeniden "bekleyen" olur.',
      { baslik: 'Formu Tekrar Gönder', tamam: 'Müşteriye Gönder', tehlike: false });
    if (!tamam) return;
  }
  b.disabled = true;
  const r = await api('POST', `/form/arsiv/${id}/${b.dataset.aksiyon}`);
  if (yazSonuc(r, r.govde?.mesaj || 'İşlem Başarılı')) arsivTablo.yenile(false);
  else b.disabled = false;
}

// ─────────────────────────── FORM GÖNDER ───────────────────────────

function formGonderAc() {
  const t = veri.taslaklar || [];
  const secenekler = t.length
    ? t.map((f) => `<option value="${f.id}">${esc(f.form_adi)}</option>`).join('')
    : '<option value="" disabled selected>Henüz form oluşturmadınız — Form Şablonları sekmesinden ekleyin</option>';
  const hizmetler = (veri.hizmetler || []).map((h) => `<option value="${h.hizmet_id}">${esc(h.ad)}</option>`).join('');

  const govde = `
    <div class="rc-form-izgara">
      <div class="rc-alan rc-tam"><label>Form/Sözleşme Türü</label><select id="fgForm">${secenekler}</select></div>
      <div class="rc-alan"><label>Müşteri</label><div id="fgMusteri"></div></div>
      <div class="rc-alan"><label>Cep Telefon</label><input type="tel" id="fgTel" autocomplete="off"></div>
      <div class="rc-alan rc-tam" id="fgHizmetAlan" hidden><label>Hizmet</label><select id="fgHizmet"><option value="">Hizmet seçin</option>${hizmetler}</select></div>
      <div class="rc-alan" data-ucret hidden><label>Toplam Ücret (₺)</label><input id="fgToplam" class="f-tl" inputmode="decimal" placeholder="0,00"></div>
      <div class="rc-alan" data-ucret hidden><label>Kapora (₺)</label><input id="fgKapora" class="f-tl" inputmode="decimal" placeholder="0,00"></div>
    </div>`;
  const alt = `<button class="rc-btn rc-btn-ikincil" id="fgIptal">Vazgeç</button>
    <button class="rc-btn rc-btn-yesil" id="fgGonder"><i class="bi bi-send"></i> Gönder</button>`;
  const ov = modalAc('Form Gönder', govde, alt, { ikon: 'send', dar: true });
  tlBagla(ov);

  const secici = musteriSecici(el('#fgMusteri', ov), {
    salonId, yol: '/form/musteri-ara',
    onSec: async (m) => {
      el('#fgTel', ov).value = '';
      if (!m) return;
      const r = await api('GET', `/form/musteri/${m.id}`, { params: { salon_id: salonId } });
      if (r.ok && r.govde?.basarili) el('#fgTel', ov).value = r.govde.veri.telefon || '';
    },
  });

  // Form başına ek alanlar sunucudan (eski 9/10/12 kuralları — SabitFormlar).
  const ekAlanlar = () => {
    const f = t.find((x) => String(x.id) === el('#fgForm', ov).value);
    const k = (f && f.ek_alanlar) || {};
    el('#fgHizmetAlan', ov).hidden = !k.hizmet;
    ov.querySelectorAll('[data-ucret]').forEach((a) => { a.hidden = !k.ucret; });
    return k;
  };
  el('#fgForm', ov).addEventListener('change', ekAlanlar);
  ekAlanlar();

  el('#fgIptal', ov).onclick = modalKapat;
  el('#fgGonder', ov).onclick = async (e) => {
    const m = secici.secili();
    if (!el('#fgForm', ov).value) return mesaj('Form/sözleşme türünü seçiniz.', 'hata');
    if (!m) return mesaj('Müşteri/danışan seçiniz.', 'hata');
    const k = ekAlanlar();
    e.currentTarget.disabled = true;
    const r = await api('POST', '/form/arsiv', { body: {
      salon_id: salonId,
      form_id: Number(el('#fgForm', ov).value),
      user_id: m.id,
      telefon: el('#fgTel', ov).value.trim(),
      hizmet_id: k.hizmet ? (el('#fgHizmet', ov).value || null) : null,
      toplam_ucret: k.ucret ? el('#fgToplam', ov).value : null,
      kapora: k.ucret ? el('#fgKapora', ov).value : null,
    } });
    if (yazSonuc(r, r.govde?.mesaj)) { modalKapat(); arsivTablo.yenile(false); } else e.currentTarget.disabled = false;
  };
}

/** Para alanları: odaktan çıkınca tr-TR biçimi. */
function tlBagla(kap) {
  kap.querySelectorAll('.f-tl').forEach((i) => {
    i.addEventListener('blur', () => { const n = tlOku(i.value); i.value = n === null ? '' : tlYaz(n); });
  });
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

  const secici = musteriSecici(el('#szMusteri', ov), {
    salonId, yol: '/form/musteri-ara',
    onSec: async (m) => {
      el('#szTel', ov).value = '';
      if (!m) return;
      const r = await api('GET', `/form/musteri/${m.id}`, { params: { salon_id: salonId } });
      if (r.ok && r.govde?.basarili) el('#szTel', ov).value = r.govde.veri.telefon || '';
    },
  });

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
    const m = secici.secili();
    if (!m || !el('#szTel', ov).value.trim()) return mesaj('Müşteri ve cep telefon zorunlu.', 'hata');
    if (!el('#szToplam', ov).value.trim()) return mesaj('Toplam ücret zorunludur.', 'hata');
    if (!el('#szMetin', ov).value.trim()) return mesaj('Sözleşme şartları zorunludur.', 'hata');
    if (!imza.dolu()) return mesaj('Salon yetkilisi imzası zorunludur. Lütfen imza alanına imzanızı atın.', 'hata');
    const btn = e.currentTarget;
    btn.disabled = true;
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
    if (yazSonuc(r, r.govde?.mesaj)) { modalKapat(); arsivTablo.yenile(false); } else btn.disabled = false;
  };
}

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

/** İmza tuvali (fare + dokunmatik, pointer events). */
function imzaAlani(cv) {
  const ctx = cv.getContext('2d');
  let ciziyor = false, dolu = false;
  const boyutla = () => {
    const r = cv.getBoundingClientRect(), o = window.devicePixelRatio || 1;
    cv.width = r.width * o; cv.height = r.height * o;
    ctx.scale(o, o); ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#1f1f2e';
  };
  requestAnimationFrame(boyutla);
  const nokta = (e) => { const r = cv.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
  cv.addEventListener('pointerdown', (e) => { ciziyor = true; cv.setPointerCapture(e.pointerId); ctx.beginPath(); ctx.moveTo(...nokta(e)); });
  cv.addEventListener('pointermove', (e) => { if (!ciziyor) return; ctx.lineTo(...nokta(e)); ctx.stroke(); dolu = true; });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach((t) => cv.addEventListener(t, () => { ciziyor = false; }));
  return {
    dolu: () => dolu,
    veri: () => cv.toDataURL('image/png'),
    temizle: () => { ctx.clearRect(0, 0, cv.width, cv.height); dolu = false; },
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
  const secici = musteriSecici(el('#hbMusteri', ov), { salonId, yol: '/form/musteri-ara' });

  const p = await api('GET', '/personel', { params: { salon_id: salonId, tumu: 1 } });
  if (p.ok && p.govde?.basarili) {
    el('#hbPersonel', ov).insertAdjacentHTML('beforeend', (p.govde.veri.data || [])
      .filter((x) => x.aktif).map((x) => `<option value="${x.id}">${esc(x.personel_adi)}</option>`).join(''));
  }

  el('#hbIptal', ov).onclick = modalKapat;
  el('#hbKaydet', ov).onclick = async (e) => {
    const m = secici.secili();
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

// ─────────────────────────── FORM ŞABLONLARI ───────────────────────────

let sablonlar = [];

async function sablonlariYukle() {
  const r = await api('GET', '/form/sablon', { params: { salon_id: salonId } });
  if (!r.ok || !r.govde?.basarili) { sablonTablo.mesaj(r.govde?.mesaj || `Hata (${r.kod})`); return; }
  sablonlar = r.govde.veri || [];
  sablonTablo.kayitlarAyarla(sablonlar);
}

function sablonSatir(s) {
  const i = sablonlar.findIndex((x) => x.id === s.id);
  const duzenle = yetki('olustur');
  const sira = duzenle ? `<span class="f-sira">
      <button class="rc-islem" data-aksiyon="yukari" data-id="${s.id}" ${i <= 0 ? 'disabled' : ''} title="Yukarı"><i class="bi bi-arrow-up"></i></button>
      <button class="rc-islem" data-aksiyon="asagi" data-id="${s.id}" ${i >= sablonlar.length - 1 ? 'disabled' : ''} title="Aşağı"><i class="bi bi-arrow-down"></i></button></span>` : '—';
  const islemler = [
    duzenle ? `<button class="rc-islem" data-aksiyon="duzenle" data-id="${s.id}"><i class="bi bi-pencil"></i> Düzenle</button>` : '',
    `<a class="rc-islem" href="${esc(s.bos_pdf_url)}" target="_blank" rel="noopener"><i class="bi bi-download"></i> PDF</a>`,
    duzenle ? `<button class="rc-islem rc-tehlike" data-aksiyon="sil" data-id="${s.id}"><i class="bi bi-trash"></i> Sil</button>` : '',
  ].join('');
  return `<tr>
    <td class="rc-orta">${sira}</td>
    <td>${esc(s.form_adi)}${s.is_sozlesme_tipi ? ' <span class="rc-rozet rc-rozet-mavi">Sözleşme</span>' : ''}</td>
    <td>${s.eleman_sayisi ? s.eleman_sayisi + ' eleman' : '-'}</td>
    <td class="rc-nowrap">${esc(s.tarih || '-')}</td>
    <td class="rc-orta"><span class="rc-islemler">${islemler}</span></td>
  </tr>`;
}

async function sablonAksiyon(e) {
  const b = e.target.closest('button[data-aksiyon]');
  if (!b) return;
  const s = sablonlar.find((x) => String(x.id) === b.dataset.id);
  if (!s) return;
  const a = b.dataset.aksiyon;
  if (a === 'duzenle') return sablonAc(s);
  if (a === 'sil') {
    if (!(await onay(`"${s.form_adi}" form şablonunu silmek istediğinize emin misiniz? Bu işlem geri alınamaz.`, { baslik: 'Formu Sil', tamam: 'Evet, Sil' }))) return;
    const r = await api('DELETE', `/form/sablon/${s.id}`, { params: { salon_id: salonId } });
    if (yazSonuc(r, 'Silindi')) { sablonlariYukle(); formVerisiTazele(); }
    return;
  }
  b.disabled = true;
  const r = await api('PATCH', `/form/sablon/${s.id}/sira`, { body: { salon_id: salonId, yon: a } });
  if (yazSonuc(r)) { sablonlar = r.govde.veri || []; sablonTablo.kayitlarAyarla(sablonlar); formVerisiTazele(); } else b.disabled = false;
}

/** Şablon oluşturucu / düzenleyici. Eleman tipleri, etiketler ve zorunluluk kataloğu API'den. */
function sablonAc(s) {
  const katalog = veri.sablon_katalogu || { gruplar: [], tipler: [] };
  const tip = (t) => katalog.tipler.find((x) => x.tip === t) || { tip: t, etiket: t, renk: '#999', girdi: 'tek' };
  let elemanlar = (s ? s.sorular : []).map((x) => ({ tip: x.tip, soru: x.soru }));

  const govde = `
    <div class="rc-form-izgara">
      <div class="rc-alan"><label>Form Adı *</label><input id="sbAd" maxlength="200" placeholder="Örn: Lazer Epilasyon Onam Formu" value="${esc(s ? s.form_adi : '')}"></div>
      <div class="rc-alan"><label>Tip</label>
        <label class="f-onay"><span class="rc-switch"><input type="checkbox" id="sbSozlesme" ${s && s.is_sozlesme_tipi ? 'checked' : ''}><span class="rc-kaydir"></span></span> Hizmet Sözleşmesi</label></div>
      <div class="rc-alan rc-tam"><label>Form Başlık Açıklaması <small>(formun hemen üstünde gri kutuda gösterilir)</small></label>
        <textarea id="sbAciklama" rows="2" placeholder="Bu formdaki açıklamaların amacı...">${esc(s ? s.aciklama || '' : '')}</textarea></div>
    </div>
    <h3 class="f-alt-baslik">Form İçeriği</h3>
    <div class="rc-ipucu">Aşağıdaki butonlarla form elemanları ekleyin; ↑↓ ile sıralayın.</div>
    <div id="sbElemanlar" class="f-elemanlar"></div>
    <div id="sbGruplar"></div>`;
  const alt = `<button class="rc-btn rc-btn-ikincil" id="sbIptal">İptal</button>
    <button class="rc-btn rc-btn-birincil" id="sbKaydet"><i class="bi bi-save"></i> Kaydet</button>`;
  const ov = modalAc(s ? 'Formu Düzenle: ' + s.form_adi : 'Yeni Form Şablonu', govde, alt, { ikon: 'ui-checks', genis: true, sabit: true });

  const gruplarCiz = () => {
    const sozlesme = el('#sbSozlesme', ov).checked;
    el('#sbGruplar', ov).innerHTML = katalog.gruplar.filter((g) => !g.yalniz_sozlesme || sozlesme).map((g) => `
      <div class="f-grup"><div class="f-grup-baslik">${esc(g.etiket)}${g.not ? ` <small>(${esc(g.not)})</small>` : ''}</div>
        ${katalog.tipler.filter((t) => t.grup === g.grup && t.eklenebilir).map((t) => `<button type="button" class="f-tip-btn" data-ekle="${t.tip}" style="--tip:${esc(t.renk)}"><i class="bi ${esc(t.ikon)}"></i> ${esc(t.etiket)}</button>`).join('')}
      </div>`).join('');
  };

  const elemanlarCiz = () => {
    el('#sbElemanlar', ov).innerHTML = elemanlar.length ? elemanlar.map((x, i) => {
      const t = tip(x.tip);
      const girdi = t.girdi === null
        ? `<div class="f-oto">${esc(t.alt_not || 'Otomatik bilgi')}</div>`
        : (t.girdi === 'cok'
          ? `<textarea data-i="${i}" rows="3" placeholder="${esc(t.ipucu || '')}">${esc(x.soru)}</textarea>`
          : `<input data-i="${i}" placeholder="${esc(t.ipucu || '')}" value="${esc(x.soru)}">`)
          + (t.alt_not ? `<small class="rc-ipucu">${esc(t.alt_not)}</small>` : '');
      const zor = t.zorunlu === true ? '<span class="f-zorunlu">● Zorunlu</span>' : (t.zorunlu === false ? '<span class="rc-ipucu">Opsiyonel</span>' : '');
      return `<div class="f-eleman" style="--tip:${esc(t.renk)}">
        <span class="f-eleman-tip">${esc(t.etiket)}</span>
        <div class="f-eleman-girdi rc-alan">${girdi}</div>
        <div class="f-eleman-yan">${zor}
          <span class="rc-islemler">
            <button type="button" class="rc-islem" data-el="yukari" data-i="${i}" ${i === 0 ? 'disabled' : ''} title="Yukarı"><i class="bi bi-arrow-up"></i></button>
            <button type="button" class="rc-islem" data-el="asagi" data-i="${i}" ${i === elemanlar.length - 1 ? 'disabled' : ''} title="Aşağı"><i class="bi bi-arrow-down"></i></button>
            <button type="button" class="rc-islem" data-el="kopyala" data-i="${i}" title="Kopyala (altına ekle)"><i class="bi bi-copy"></i></button>
            <button type="button" class="rc-islem rc-tehlike" data-el="sil" data-i="${i}" title="Sil"><i class="bi bi-trash"></i></button>
          </span></div>
      </div>`;
    }).join('') : '<div class="rc-ipucu f-bos">Henüz eleman eklenmedi.</div>';
  };

  el('#sbElemanlar', ov).addEventListener('input', (e) => {
    const i = e.target.dataset.i;
    if (i !== undefined) elemanlar[+i].soru = e.target.value;
  });
  el('#sbElemanlar', ov).addEventListener('click', (e) => {
    const b = e.target.closest('[data-el]');
    if (!b) return;
    const i = +b.dataset.i;
    const takas = (j) => { [elemanlar[i], elemanlar[j]] = [elemanlar[j], elemanlar[i]]; };
    ({ yukari: () => takas(i - 1), asagi: () => takas(i + 1), kopyala: () => elemanlar.splice(i + 1, 0, { ...elemanlar[i] }), sil: () => elemanlar.splice(i, 1) })[b.dataset.el]();
    elemanlarCiz();
  });
  el('#sbGruplar', ov).addEventListener('click', (e) => {
    const b = e.target.closest('[data-ekle]');
    if (!b) return;
    const t = tip(b.dataset.ekle);
    elemanlar.push({ tip: t.tip, soru: t.girdi === null ? t.tip : '' });
    elemanlarCiz();
    ov.querySelector('.f-eleman:last-child input, .f-eleman:last-child textarea')?.focus();
  });
  el('#sbSozlesme', ov).addEventListener('change', gruplarCiz);
  gruplarCiz();
  elemanlarCiz();

  el('#sbIptal', ov).onclick = modalKapat;
  el('#sbKaydet', ov).onclick = async (e) => {
    const ad = el('#sbAd', ov).value.trim();
    if (!ad) return mesaj('Form adı zorunludur.', 'hata');
    if (!elemanlar.length) return mesaj('En az bir eleman ekleyin.', 'hata');
    e.currentTarget.disabled = true;
    const body = { salon_id: salonId, form_adi: ad, aciklama: el('#sbAciklama', ov).value, is_sozlesme: el('#sbSozlesme', ov).checked, sorular: elemanlar };
    const r = s ? await api('PATCH', `/form/sablon/${s.id}`, { body }) : await api('POST', '/form/sablon', { body });
    if (yazSonuc(r, r.govde?.mesaj)) { modalKapat(); sablonlariYukle(); formVerisiTazele(); } else e.currentTarget.disabled = false;
  };
}

/** Şablon değişince Form Gönder listesi (taslaklar) tazelensin. */
async function formVerisiTazele() {
  const r = await api('GET', '/form/form-verisi', { params: { salon_id: salonId } });
  if (r.ok && r.govde?.basarili) veri = r.govde.veri;
}

// ─────────────────────────── BAŞLAT ───────────────────────────

(async function baslat() {
  if (!salonId) { kok.innerHTML = '<div class="rc-ipucu f-bos">Şube seçili değil.</div>'; return; }
  const r = await api('GET', '/form/form-verisi', { params: { salon_id: salonId } });
  if (!r.ok || !r.govde?.basarili) {
    kok.innerHTML = `<div class="rc-ipucu f-bos">${esc(r.govde?.mesaj || `Hata (${r.kod})`)}</div>`;
    return;
  }
  veri = r.govde.veri;
  iskeletCiz();
  arsivTablo.yenile();
})();
