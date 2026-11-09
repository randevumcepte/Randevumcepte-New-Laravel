/**
 * Hesabım & Faturalar (v2) — build'siz island. Eski /isletmeyonetim/hesabim ekranının karşılığı.
 * Sekme listesi ve tüm veri GET /hesabim'den; kart kuralları sunucuda. Ödeme (paket yükseltme,
 * duyuru paketi satın alma) eski panelde kalır, linkler API'den hazır gelir.
 */
import { api, qp, esc, el, TL, yazSonuc } from '/js/lib/v2-ortak.js?v=7';
import { sekmeliSayfa } from '/js/lib/v2-sekme.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=7';
import { kilitle } from '/js/lib/v2-bilesen.js?v=7';
import { trTarih, sayi } from '/js/lib/v2-donem.js?v=7';

const kok = document.getElementById('rc-hesabim');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
if (salonId && window.rcPanel) window.rcPanel.salonAyarla(salonId);

const bosKutu = (ikon, baslik, alt) => `<div class="hs-bos"><i class="bi ${ikon}"></i><b>${esc(baslik)}</b><small>${esc(alt)}</small></div>`;
const rozet = (renk, metin, ikon = '') => `<span class="rc-rozet rc-rozet-${renk}">${ikon ? `<i class="bi ${ikon}"></i> ` : ''}${esc(metin)}</span>`;
const bilgi = (etiket, deger) => `<div><dt>${esc(etiket)}</dt><dd>${deger}</dd></div>`;

function kalanSinif(g) {
  if (g === null || g === undefined) return '';
  if (g <= 7) return 'kritik';
  return g <= 30 ? 'uyari' : '';
}

function heroCiz(v) {
  const i = v.isletme, k = v.kullanici, g = i.kalan_gun;
  const avatar = k.profil_resim_url
    ? `<img src="${esc(k.profil_resim_url)}" alt="" onerror="this.remove()">`
    : `<span>${esc((k.name || 'R').charAt(0).toUpperCase())}</span>`;
  const kalan = g !== null && g !== undefined && g < 0
    ? '<i class="bi bi-exclamation-circle"></i> Üyelik Süresi Doldu'
    : (g === null || g === undefined ? '' : `<b>${esc(g)}</b> Gün Kaldı`);
  return `
    <div class="hs-hero">
      <div class="hs-avatar">${avatar}</div>
      <div class="hs-hero-ad">
        <h1>${esc(i.salon_adi)}</h1>
        <p>Hesap sahibi: <b>${esc(k.name)}</b></p>
        <div class="hs-hero-iletisim">
          ${k.email ? `<span><i class="bi bi-envelope"></i> ${esc(k.email)}</span>` : ''}
          ${k.gsm1 ? `<span><i class="bi bi-telephone"></i> ${esc(k.gsm1)}</span>` : ''}
        </div>
      </div>
      <div class="hs-hero-sag">
        <span class="hs-paket"><i class="bi bi-star-fill"></i> ${esc(i.uyelik_turu_adi)} · ${esc(i.uyelik_periyodu_adi)}</span>
        ${kalan ? `<span class="hs-kalan ${kalanSinif(g)}">${kalan}</span>` : ''}
      </div>
    </div>`;
}

/* ───────────── Sekmeler (ciz(kap, ctx)) ───────────── */

function uyelikCiz(kap, { v }) {
  const i = v.isletme, g = i.kalan_gun;
  kap.innerHTML = `
    <div class="hs-uyelik">
      <section class="hs-kart">
        <h3><i class="bi bi-rocket-takeoff"></i> Aktif Üyelik Detayları</h3>
        <dl class="hs-liste">
          ${bilgi('Paket Türü', esc(i.uyelik_turu_adi))}
          ${bilgi('Ödeme Periyodu', esc(i.uyelik_periyodu_adi))}
          ${bilgi('Üyelik Bitiş Tarihi', i.uyelik_bitis_tarihi ? esc(trTarih(i.uyelik_bitis_tarihi)) : 'Belirtilmemiş')}
          ${bilgi('Kalan Süre', g !== null && g !== undefined && g < 0 ? '<span class="hs-kirmizi">Süre Doldu</span>' : (g === null || g === undefined ? '—' : `${esc(g)} gün`))}
          ${bilgi('İşletme Kayıt Tarihi', i.kayit_tarihi ? esc(trTarih(i.kayit_tarihi)) : '—')}
        </dl>
      </section>
      <section class="hs-aksiyon">
        <h3>Paketinizi Yükseltin</h3>
        <p>Daha fazla özellik, daha çok kullanıcı ve sınırsız randevu için üst pakete geçebilirsiniz.</p>
        <a class="rc-btn rc-btn-ikincil" href="${esc(v.uyelik_url)}"><i class="bi bi-arrow-up"></i> Paketi Yükselt</a>
      </section>
    </div>`;
}

function hizmetDurumu(h) {
  if (h.deneme) return rozet('turuncu', h.deneme_label || 'Ücretsiz Deneme', 'bi-gift');
  return h.aktif ? rozet('yesil', 'Aktif', 'bi-check-lg') : rozet('kirmizi', 'Pasif', 'bi-x-lg');
}

function hizmetKalan(g) {
  if (g === null || g === undefined) return '';
  if (g <= 0) return rozet('kirmizi', 'Süre doldu', 'bi-clock');
  return rozet(g <= 7 ? 'turuncu' : 'yesil', `${g} gün kaldı`, 'bi-clock');
}

function hizmetlerCiz(kap, { v }) {
  kap.innerHTML = `<div class="hs-izgara">${v.hizmetler.map((h) => `
    <article class="hs-hizmet">
      <div class="hs-hizmet-bas">
        <span class="hs-hizmet-ikon ${esc(h.renk)}"><i class="bi ${esc(h.ikon)}"></i></span>
        <div><h4>${esc(h.ad)}</h4><p>${esc(h.aciklama)}</p></div>
      </div>
      <div class="hs-hizmet-satir"><span><small>Periyot:</small> <b>${esc(h.periyot || '-')}</b></span>${hizmetDurumu(h)}</div>
      ${h.baslangic ? `<div class="hs-hizmet-satir"><span><small>Başlangıç:</small> <b>${esc(trTarih(h.baslangic))}</b></span></div>` : ''}
      ${h.bitis ? `<div class="hs-hizmet-satir"><span><small>Bitiş:</small> <b>${esc(trTarih(h.bitis))}</b></span>${hizmetKalan(h.kalan_gun)}</div>` : ''}
    </article>`).join('')}</div>`;
}

function duyuruCiz(kap, { v }) {
  if (!v.sms_paketleri.length) {
    kap.innerHTML = bosKutu('bi-megaphone', 'Şu an satışta paket bulunmuyor', 'Kısa süre içinde duyuru paketleri eklenecektir.');
    return;
  }
  kap.innerHTML = `<div class="hs-izgara">${v.sms_paketleri.map((p) => `
    <article class="hs-hizmet hs-paket-kart">
      <span class="hs-hizmet-ikon mor"><i class="bi bi-megaphone"></i></span>
      <h4>${esc(p.ad)}</h4>
      <p>${esc(p.alt_baslik)}</p>
      <div class="hs-fiyat">${TL(p.ucret)}</div>
      <small class="hs-soluk">Tüm vergiler dahil</small>
      <ul>
        <li><i class="bi bi-check-lg"></i> Süresiz kullanım hakkı</li>
        <li><i class="bi bi-check-lg"></i> Başlıklı, garantili gönderim</li>
        <li><i class="bi bi-check-lg"></i> Kredi kartına taksit imkânı</li>
      </ul>
      <a class="rc-btn rc-btn-birincil" href="${esc(p.satin_al_url)}"><i class="bi bi-cart"></i> Satın Al</a>
    </article>`).join('')}</div>`;
}

function faturaBilgiCiz(kap, { v }) {
  const i = v.isletme;
  kap.innerHTML = `
    <section class="hs-kart">
      <h3><i class="bi bi-person-vcard"></i> Fatura Bilgileriniz</h3>
      <form id="hsFatura" autocomplete="off">
        <div class="rc-form-izgara">
          <div class="rc-alan"><label>Vergi / Firma Ünvanı</label><input name="vergi_adi" maxlength="255" placeholder="Şirket Ünvanı" value="${esc(i.vergi_adi)}"></div>
          <div class="rc-alan"><label>Vergi / TCKN Numarası</label><input name="vergi_no" maxlength="20" placeholder="Vergi numarası" value="${esc(i.vergi_no)}"></div>
          <div class="rc-alan rc-tam"><label>Vergi Adresi</label><textarea name="vergi_adresi" rows="2" maxlength="1000" placeholder="Tam adres">${esc(i.vergi_adresi)}</textarea></div>
          <div class="rc-alan"><label>KDV Oranı (%)</label><input name="kdv_orani" type="number" min="0" max="100" step="0.01" placeholder="20" value="${esc(i.kdv_orani)}"></div>
        </div>
        <div class="hs-form-alt"><button type="submit" class="rc-btn rc-btn-birincil"><i class="bi bi-check2"></i> Bilgileri Kaydet</button></div>
      </form>
    </section>`;
  el('#hsFatura', kap).onsubmit = (e) => {
    e.preventDefault();
    const f = e.target;
    const govde = {
      salon_id: salonId,
      vergi_adi: f.vergi_adi.value.trim() || null,
      vergi_no: f.vergi_no.value.trim() || null,
      vergi_adresi: f.vergi_adresi.value.trim() || null,
    };
    if (f.kdv_orani.value !== '') govde.kdv_orani = f.kdv_orani.value;   // boşsa mevcut oran korunur (eskiyle aynı)
    return kilitle(f.querySelector('[type=submit]'), 'Kaydediliyor…', async () => {
      const r = await api('PUT', '/hesabim/fatura-bilgisi', { body: govde });
      if (yazSonuc(r, r.govde?.mesaj)) Object.assign(v.isletme, r.govde.veri);
    });
  };
}

function faturalarCiz(kap, { v }) {
  kap.innerHTML = `
    ${v.sms_siparisleri.length ? `<h3 class="hs-tablo-baslik"><i class="bi bi-megaphone"></i> Duyuru Paketi Satın Alımları</h3><section class="hs-kart hs-tablo" id="hsSiparis"></section>` : ''}
    <h3 class="hs-tablo-baslik"><i class="bi bi-receipt"></i> Üyelik Ödemeleri</h3>
    <section class="hs-kart hs-tablo" id="hsOdeme"></section>`;

  if (v.sms_siparisleri.length) {
    veriTablosu(el('#hsSiparis', kap), {
      anahtar: 'hesabim_siparis',
      birim: 'sipariş',
      sirala: { alan: 'created_at', yon: 'desc' },
      kolonlar: [
        { baslik: 'Tarih', sirala: 'created_at', deger: (s) => s.created_at },
        { baslik: 'Paket' },
        { baslik: 'Tutar', sirala: 'tutar', deger: (s) => s.tutar },
        { baslik: 'Yükleme' },
        { baslik: 'Fatura', sinif: 'rc-sag' },
      ],
      araMetni: (s) => `${s.sms_adet} ${trTarih(s.created_at)}`,
      satir: (s) => `<tr>
        <td>${esc(trTarih(s.created_at))}</td>
        <td>${esc(sayi(s.sms_adet))} SMS Duyuru Paketi</td>
        <td class="hs-tutar">${TL(s.tutar)}</td>
        <td>${s.yukleme_durumu === 1 ? rozet('yesil', 'Yüklendi') : rozet('turuncu', 'Hazırlanıyor')}</td>
        <td class="rc-sag">${s.fatura_url ? `<a class="rc-rozet rc-rozet-yesil" target="_blank" rel="noopener" href="${esc(s.fatura_url)}"><i class="bi bi-download"></i> Fatura</a>` : '<small class="hs-soluk">Hazırlanıyor</small>'}</td>
      </tr>`,
    }).kayitlarAyarla(v.sms_siparisleri);
  }

  veriTablosu(el('#hsOdeme', kap), {
    anahtar: 'hesabim_odeme',
    birim: 'ödeme',
    bosMesaj: 'Henüz ödeme kaydı bulunmuyor. Üyelik ödemeleriniz burada listelenecek.',
    sirala: { alan: 'odeme_tarihi', yon: 'desc' },
    kolonlar: [
      { baslik: 'Tarih', sirala: 'odeme_tarihi', deger: (f) => f.odeme_tarihi },
      { baslik: 'Açıklama' },
      { baslik: 'Periyot' },
      { baslik: 'Tutar', sirala: 'tutar', deger: (f) => f.tutar },
      { baslik: 'Durum' },
      { baslik: '', sinif: 'rc-sag' },
    ],
    araMetni: (f) => `${f.aciklama} ${f.periyot || ''} ${trTarih(f.odeme_tarihi)}`,
    satir: (f) => `<tr>
      <td>${esc(trTarih(f.odeme_tarihi))}</td>
      <td>${esc(f.aciklama || '-')}</td>
      <td>${esc(f.periyot || '-')}</td>
      <td class="hs-tutar">${TL(f.tutar)}${f.kdv_dahil ? ' <small class="hs-soluk">KDV dahil</small>' : ''}</td>
      <td>${rozet('yesil', 'Ödendi')}</td>
      <td class="rc-sag">${f.dosya_url ? `<a class="rc-rozet rc-rozet-yesil" target="_blank" rel="noopener" href="${esc(f.dosya_url)}"><i class="bi bi-download"></i> İndir</a>` : ''}</td>
    </tr>`,
  }).kayitlarAyarla(v.faturalar);
}

const yerel = (ciz) => async () => ({ ciz });
const MODULLER = {
  uyelik: yerel(uyelikCiz),
  hizmetler: yerel(hizmetlerCiz),
  duyuru: yerel(duyuruCiz),
  'fatura-bilgi': yerel(faturaBilgiCiz),
  faturalar: yerel(faturalarCiz),
};

async function baslat() {
  if (!salonId) { kok.innerHTML = '<div class="hs-kart">Şube seçili değil (?sube=... bekleniyor).</div>'; return; }
  kok.innerHTML = '<div class="hs-kart"><span class="rc-yukleniyor"></span> Yükleniyor…</div>';
  const r = await api('GET', '/hesabim', { params: { salon_id: salonId } });
  if (!r.ok || !r.govde?.basarili) { kok.innerHTML = `<div class="hs-kart">${esc(r.govde?.mesaj || `Hata (${r.kod})`)}</div>`; return; }
  const v = r.govde.veri;
  kok.innerHTML = `<div class="hs-kabuk">${heroCiz(v)}<div class="rc-sekmeler" id="hsSekmeler"></div><div id="hsIcerik"></div></div>`;
  sekmeliSayfa(el('#hsSekmeler'), el('#hsIcerik'), { sekmeler: v.sekmeler, moduller: MODULLER, ctx: { v } });
}

baslat();
