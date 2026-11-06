/**
 * Santral (v2) — build'siz island (sade ES module).
 *
 * Eski panel "Santral Sistemi & Aramalar" (isletmeadmin/santral) ekranının v2 karşılığı.
 * Veri /api/v2/santral/*'dan gelir (web + Flutter AYNI uçlar → TEK KAYNAK):
 *   /santral/ozet      → erişim bayrakları (yönetici / ayar_yonet) + dakika kartı
 *   /santral/cagrilar  → çağrı listesi (sınıflandırma, durum etiketi/rengi, maske SUNUCUDA) + özet sayaçlar
 *   /santral/ayarlar   → hatırlatma araması + özel karşılama menüsü (form seçenekleri dahil)
 * Burada iş kuralı hesaplanmaz; yalnızca çizilir. Anons metni de sunucudan gelir.
 */

import { qp, api, el, esc, tarihStr, yazSonuc } from '/js/lib/v2-ortak.js?v=2';
import { ozetKart } from '/js/lib/v2-bilesen.js?v=1';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=2';

const kok = document.getElementById('rc-santral');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
if (salonId && window.rcPanel) window.rcPanel.salonAyarla(salonId);

const bugun = tarihStr(new Date());
const durum = { sekme: '', tarih1: bugun, tarih2: bugun };
let erisim = {};
let tablo = null;
let ayarlar = null;

const SEKMELER = [
  { kod: '', ad: 'Tümü', ikon: 'bi-telephone' },
  { kod: 'gelen', ad: 'Gelen', ikon: 'bi-telephone-inbound' },
  { kod: 'giden', ad: 'Giden', ikon: 'bi-telephone-outbound' },
  { kod: 'cevapsiz', ad: 'Cevapsız', ikon: 'bi-telephone-x' },
  { kod: 'ulasilamadi', ad: 'Ulaşılamadı', ikon: 'bi-telephone-minus' },
];
const AYAR_SEKME = { kod: 'ayarlar', ad: 'Ayarlar', ikon: 'bi-gear' };

const KOLONLAR = [
  { baslik: 'Müşteri' }, { baslik: 'Telefon' }, { baslik: 'Görüşme Yapan' },
  { baslik: 'Tarih' }, { baslik: 'Durum' }, { baslik: 'Süre', sinif: 'rc-sag' }, { baslik: 'İşlem', sinif: 'rc-orta' },
];

const sayi = (n, basamak = 1) => (Number(n) || 0).toLocaleString('tr-TR', { minimumFractionDigits: 0, maximumFractionDigits: basamak });
const trTarih = (ymd) => (ymd ? ymd.split('-').reverse().join('.') : '');
const sure = (sn) => (sn > 0 ? `${Math.floor(sn / 60)}:${String(sn % 60).padStart(2, '0')}` : '—');

// ───────────────────────── İskelet ─────────────────────────

async function baslat() {
  kok.innerHTML = `
    <div class="sn-kabuk">
      <div class="sn-ust">
        <span class="sn-baslik-ikon"><i class="bi bi-phone"></i></span>
        <div><h1>Santral</h1><div class="sn-alt">Gelen / giden aramalar, ses kayıtları ve santral ayarları</div></div>
      </div>
      <div id="snDakika"></div>
      <div class="sn-sekmeler" id="snSekmeler"></div>
      <div id="snRapor">
        <div class="sn-filtre">
          <label>Başlangıç <input type="text" id="snTarih1" value="${durum.tarih1}"></label>
          <label>Bitiş <input type="text" id="snTarih2" value="${durum.tarih2}"></label>
        </div>
        <div class="rc-ozet" id="snOzet"></div>
        <div class="sn-uyari-kutu" id="snUyari" hidden></div>
        <div class="sn-oynatici" id="snOynatici" hidden>
          <i class="bi bi-soundwave"></i><span id="snOynaticiAd"></span>
          <audio id="snSes" controls preload="none"></audio>
          <button type="button" class="sn-ib" id="snOynaticiKapat" title="Kapat"><i class="bi bi-x-lg"></i></button>
        </div>
        <div id="snTablo"></div>
      </div>
      <div id="snAyarlar" hidden></div>
    </div>`;

  if (!salonId) { kok.querySelector('#snTablo').innerHTML = '<div class="sn-bos">Şube seçili değil (?sube=... bekleniyor).</div>'; return; }

  const r = await api('GET', '/santral/ozet', { params: { salon_id: salonId } });
  if (!r.ok || !r.govde || !r.govde.basarili) {
    kok.querySelector('.sn-kabuk').innerHTML = `<div class="sn-bos">${esc((r.govde && r.govde.mesaj) || `Hata (${r.kod})`)}</div>`;
    return;
  }
  erisim = r.govde.veri.erisim || {};
  dakikaCiz(r.govde.veri.dakika);
  sekmeCiz();
  tarihBagla();
  tabloKur();
  oynaticiBagla();
}

// ───────────────────────── Dakika kartı ─────────────────────────

function dakikaCiz(d) {
  const kap = el('#snDakika');
  if (!d) { kap.innerHTML = ''; return; }
  const bas = trTarih(d.sayim_baslangic);
  const hata = d.hata ? '<div class="sn-hata-not">Dakika verisi şu an alınamadı, lütfen daha sonra tekrar deneyin.</div>' : '';
  if (d.kapsam === 'dahili') {
    kap.innerHTML = `<div class="sn-kart sn-dakika">
      <div><div class="sn-etiket">Bu dönem giden konuşma sürem (Dahili ${esc(d.dahili)})</div>
      <div class="sn-buyuk">${sayi(d.kullanilan)} <small>dk</small></div>
      <div class="sn-not">${esc(d.adet)} giden çağrı · ${esc(bas)} tarihinden bugüne</div>${hata}</div>
      <span class="sn-baslik-ikon"><i class="bi bi-stopwatch-fill"></i></span></div>`;
    return;
  }
  const yuzde = Number(d.yuzde) || 0;
  const seviye = yuzde >= 90 ? 'kritik' : yuzde >= 70 ? 'uyari' : 'iyi';
  kap.innerHTML = `<div class="sn-kart">
    <div class="sn-kart-ust"><b><i class="bi bi-stopwatch-fill"></i> Dakika Kullanımı</b> <small>(giden — havuz)</small>
      <span class="sn-not">${esc(bas)} tarihinden bugüne · ${esc(d.adet)} giden çağrı</span></div>
    <div class="sn-uclu">
      <div><div class="sn-buyuk">${sayi(d.tanimli, 0)}</div><div class="sn-etiket">Tanımlı (dk)</div></div>
      <div><div class="sn-buyuk">${sayi(d.kullanilan)}</div><div class="sn-etiket">Kullanılan (dk)</div></div>
      <div><div class="sn-buyuk ${d.kalan < 0 ? 'sn-eksi' : 'sn-arti'}">${sayi(d.kalan)}</div><div class="sn-etiket">Kalan (dk)</div></div>
    </div>
    ${d.tanimli > 0
      ? `<div class="sn-bar"><div class="sn-bar-dolu sn-seviye-${seviye}" style="width:${yuzde}%"></div></div><div class="sn-not sn-sag">%${yuzde}</div>`
      : '<div class="sn-not sn-orta">Bu işletme için henüz tanımlı dakika girilmemiş.</div>'}
    ${hata}</div>`;
}

// ───────────────────────── Sekmeler + filtre ─────────────────────────

function sekmeCiz() {
  const liste = erisim.ayar_yonet ? [...SEKMELER, AYAR_SEKME] : SEKMELER;
  el('#snSekmeler').innerHTML = liste
    .map((s) => `<button class="sn-sekme${s.kod === durum.sekme ? ' aktif' : ''}" data-kod="${s.kod}"><i class="bi ${s.ikon}"></i> ${s.ad}</button>`)
    .join('');
  el('#snSekmeler').onclick = (e) => {
    const b = e.target.closest('.sn-sekme');
    if (!b || b.dataset.kod === durum.sekme) return;
    durum.sekme = b.dataset.kod;
    sekmeCiz();
    const ayar = durum.sekme === 'ayarlar';
    el('#snRapor').hidden = ayar;
    el('#snAyarlar').hidden = !ayar;
    if (ayar) ayarlariYukle(); else tablo.yenile();
  };
}

function tarihBagla() {
  ['tarih1', 'tarih2'].forEach((alan) => {
    const girdi = el(alan === 'tarih1' ? '#snTarih1' : '#snTarih2');
    const degisti = (v) => { if (!v || v === durum[alan]) return; durum[alan] = v; tablo.yenile(); };
    if (window.flatpickr) flatpickr(girdi, { dateFormat: 'Y-m-d', locale: 'tr', allowInput: true, disableMobile: true, onChange: (_d, s) => degisti(s) });
    else girdi.addEventListener('change', (e) => degisti(e.target.value));
  });
}

// ───────────────────────── Çağrı tablosu ─────────────────────────

function tabloKur() {
  tablo = veriTablosu(el('#snTablo'), {
    anahtar: 'santral',
    varsayilanBoy: 25,
    kolonlar: KOLONLAR,
    birim: 'çağrı',
    araIpucu: 'Müşteri, personel, durum…',
    bosMesaj: 'Bu aralıkta çağrı kaydı yok.',
    satir: satirCiz,
    kaynak: (p) => api('GET', '/santral/cagrilar', {
      params: { page: p.page, per_page: p.per_page, ara: p.ara, salon_id: salonId, tarih1: durum.tarih1, tarih2: durum.tarih2, kategori: durum.sekme },
    }),
    cizildi: (_liste, v) => { if (v) { ozetCiz(v.ozet || {}); uyariCiz(v); } },
  });
  tablo.yenile();
  el('#snTablo').addEventListener('click', (e) => {
    const b = e.target.closest('[data-ses]');
    if (b) oynat(b.dataset.ses, b.dataset.ad);
  });
}

function ozetCiz(o) {
  el('#snOzet').innerHTML =
    ozetKart('bi-telephone-inbound-fill', 'Gelen Arama', o.gelen_arama ?? 0, 'yesil') +
    ozetKart('bi-telephone-outbound-fill', 'Giden Arama', o.giden_arama ?? 0, 'mor') +
    ozetKart('bi-telephone-x-fill', 'Cevapsız Arama', o.cevapsiz_arama ?? 0, 'turuncu') +
    ozetKart('bi-telephone-minus-fill', 'Ulaşılamadı', o.basarisiz_arama ?? 0, 'mavi');
}

function uyariCiz(v) {
  const m = [];
  if (v.hata) m.push('Santral şu an yanıt vermiyor; liste eksik olabilir.');
  if (v.kesildi) m.push('Bu aralıkta çok fazla çağrı var; yalnızca en son kayıtlar gösteriliyor. Tarih aralığını daraltın.');
  el('#snUyari').hidden = !m.length;
  el('#snUyari').textContent = m.join(' ');
}

function satirCiz(c) {
  const ad = c.musteri_adi || c.musteri || '';
  const harf = esc((ad.trim()[0] || '#').toLocaleUpperCase('tr'));
  const kayitsiz = !c.dahili_arama && !c.kayitli ? '<span class="sn-kayitsiz" title="Bu işletmenin aktif müşterisi değil">Kayıtsız</span>' : '';
  const islem = [];
  if (c.seskaydi) {
    islem.push(`<button class="sn-ib sn-ib-mor" data-ses="${esc(c.seskaydi)}" data-ad="${esc(ad + ' · ' + c.tarih + ' ' + c.saat)}" title="Ses kaydını dinle"><i class="bi bi-play-fill"></i></button>`);
    islem.push(`<a class="sn-ib" href="${esc(c.seskaydi)}" download target="_blank" rel="noopener" title="Ses kaydını indir"><i class="bi bi-download"></i></a>`);
  }
  if (c.arama_numarasi) islem.push(`<a class="sn-ib sn-ib-yesil" href="tel:${esc(c.arama_numarasi)}" title="Ara"><i class="bi bi-telephone-fill"></i></a>`);
  return `<tr>
    <td><div class="sn-kisi"><span class="sn-avatar">${harf}</span><span>${esc(c.musteri_adi || (c.dahili_arama ? c.musteri : 'Bilinmeyen numara'))}${kayitsiz}</span></div></td>
    <td class="rc-nowrap">${esc(c.telefon)}</td>
    <td>${esc(c.gorusmeyiyapan || '—')}</td>
    <td class="rc-nowrap">${esc(c.tarih)} <small>${esc(c.saat)}</small></td>
    <td><span class="sn-rozet sn-r-${esc(c.renk)}">${esc(c.etiket)}</span>${c.dahili_arama ? ' <small class="sn-not">(dahili)</small>' : ''}</td>
    <td class="rc-sag">${sure(c.sure_sn)}</td>
    <td class="rc-orta"><div class="sn-islemler">${islem.join('')}</div></td>
  </tr>`;
}

function oynaticiBagla() {
  el('#snOynaticiKapat').addEventListener('click', () => { const a = el('#snSes'); a.pause(); a.removeAttribute('src'); el('#snOynatici').hidden = true; });
}

function oynat(url, ad) {
  const a = el('#snSes');
  el('#snOynaticiAd').textContent = ad || '';
  el('#snOynatici').hidden = false;
  a.src = url;
  a.play().catch(() => { /* tarayıcı otomatik oynatmayı engellediyse kullanıcı ▶ ile başlatır */ });
}

// ───────────────────────── Ayarlar ─────────────────────────

async function ayarlariYukle() {
  const kap = el('#snAyarlar');
  kap.innerHTML = '<div class="sn-bos"><span class="rc-yukleniyor"></span> Yükleniyor…</div>';
  const r = await api('GET', '/santral/ayarlar', { params: { salon_id: salonId } });
  if (!r.ok || !r.govde || !r.govde.basarili) { kap.innerHTML = `<div class="sn-bos">${esc((r.govde && r.govde.mesaj) || `Hata (${r.kod})`)}</div>`; return; }
  ayarlar = r.govde.veri;
  kap.innerHTML = '<div class="sn-ayar-izgara"><form class="sn-kart" id="snHatirlatma"></form><form class="sn-kart" id="snOzelMenu"></form></div>';
  hatirlatmaCiz();
  ozelMenuCiz();
}

function hatirlatmaCiz() {
  const h = ayarlar.hatirlatma;
  const f = el('#snHatirlatma');
  f.innerHTML = `
    <h3>Yaklaşan Randevu Hatırlatma</h3>
    <p class="sn-not">Randevusu yaklaşan müşteriye otomatik hatırlatma araması yapılsın/yapılmasın ayarıdır.</p>
    <label class="sn-anahtar"><input type="checkbox" name="aktif" ${h.aktif ? 'checked' : ''}> Açık / Kapalı</label>
    <label class="sn-alan">Kaç saat önce aransın?
      <select name="saat_once">${h.saat_secenekleri.map((s) => `<option value="${s}"${s === h.saat_once ? ' selected' : ''}>${s} saat</option>`).join('')}</select>
    </label>
    <button type="submit" class="sn-kaydet">Ayarları Güncelle</button>`;
  f.onsubmit = async (e) => {
    e.preventDefault();
    const btn = f.querySelector('button[type=submit]');
    btn.disabled = true;
    const r = await api('PUT', '/santral/ayarlar/hatirlatma', {
      body: { salon_id: salonId, aktif: f.elements.aktif.checked, saat_once: parseInt(f.elements.saat_once.value, 10) },
    });
    btn.disabled = false;
    if (yazSonuc(r, 'Santral ayarları kaydedildi.')) { ayarlar.hatirlatma = r.govde.veri; hatirlatmaCiz(); }
  };
}

function ozelMenuCiz() {
  const m = ayarlar.ozel_menu;
  const f = el('#snOzelMenu');
  const ornek = (tip) => (m.tipler.find((t) => t.kod === tip) || m.tipler[0]).ornek;
  const satirlar = m.secenekler.length ? m.secenekler : [{ tus: m.tuslar[0], etiket: '', tip: m.tipler[0].kod, hedef: '' }];
  f.innerHTML = `
    <h3>Özel Karşılama Menüsü</h3>
    <p class="sn-not">Açık olduğunda işletmenize gelen çağrılar karşılamadan sonra aşağıdaki tuşlu menüyü dinler.
      Her tuş için yönlendirme seçin: ${m.tipler.map((t) => `<b>${esc(t.ad)}</b> (${esc(t.aciklama.toLocaleLowerCase('tr'))})`).join(' veya ')}.
      Arayan tuşlama yapmazsa çağrı standart akışla operatöre aktarılır; 0 tuşu menüyü tekrarlar.</p>
    <label class="sn-anahtar"><input type="checkbox" name="aktif" ${m.aktif ? 'checked' : ''}> Açık / Kapalı</label>
    <div class="sn-menu-baslik"><span>Tuş</span><span>İşlem adı (anonsta okunur)</span><span>Yönlendirme</span><span>Kuyruk / Dahili No</span><span></span></div>
    <div id="snMenuSatirlar">${satirlar.map((s) => menuSatiri(s, m, ornek)).join('')}</div>
    ${Object.entries(m.hedef_onerileri).map(([tip, liste]) => `<datalist id="snHedef-${esc(tip)}">${liste.map((h) => `<option value="${esc(h.no)}">${esc(h.ad)}</option>`).join('')}</datalist>`).join('')}
    <button type="button" class="sn-ekle" id="snMenuEkle"><i class="bi bi-plus-lg"></i> Seçenek Ekle</button>
    <div class="sn-anons"><b>Okunacak anons</b><div>${esc(m.metin || 'Henüz kayıtlı seçenek yok.')}</div></div>
    <button type="submit" class="sn-kaydet">Menüyü Kaydet</button>`;

  const kap = el('#snMenuSatirlar');
  el('#snMenuEkle').onclick = () => {
    const kullanilan = [...kap.querySelectorAll('[name=tus]')].map((s) => s.value);
    const bos = m.tuslar.find((t) => !kullanilan.includes(t));
    if (!bos) return;
    kap.insertAdjacentHTML('beforeend', menuSatiri({ tus: bos, etiket: '', tip: m.tipler[0].kod, hedef: '' }, m, ornek));
  };
  kap.onchange = (e) => {
    if (e.target.name !== 'tip') return;
    const hedef = e.target.closest('.sn-menu-satir').querySelector('[name=hedef]');
    hedef.setAttribute('list', 'snHedef-' + e.target.value);
    hedef.placeholder = ornek(e.target.value);
  };
  kap.onclick = (e) => {
    const sil = e.target.closest('.sn-sil');
    if (!sil) return;
    const satir = sil.closest('.sn-menu-satir');
    if (kap.children.length > 1) satir.remove();
    else satir.querySelectorAll('input').forEach((i) => { i.value = ''; });
  };
  f.onsubmit = async (e) => {
    e.preventDefault();
    const secenekler = [...kap.querySelectorAll('.sn-menu-satir')].map((s) => ({
      tus: s.querySelector('[name=tus]').value,
      etiket: s.querySelector('[name=etiket]').value,
      tip: s.querySelector('[name=tip]').value,
      hedef: s.querySelector('[name=hedef]').value,
    }));
    const btn = f.querySelector('button[type=submit]');
    btn.disabled = true;
    const r = await api('PUT', '/santral/ayarlar/ozel-menu', { body: { salon_id: salonId, aktif: f.elements.aktif.checked, secenekler } });
    btn.disabled = false;
    if (yazSonuc(r, 'Özel karşılama menüsü kaydedildi.')) { ayarlar.ozel_menu = r.govde.veri; ozelMenuCiz(); }
  };
}

function menuSatiri(s, m, ornek) {
  return `<div class="sn-menu-satir">
    <select name="tus" data-arama="yok">${m.tuslar.map((t) => `<option value="${t}"${t === s.tus ? ' selected' : ''}>${t}</option>`).join('')}</select>
    <input type="text" name="etiket" maxlength="${m.etiket_maks}" value="${esc(s.etiket)}" placeholder="Örn: Randevu işlemleri">
    <select name="tip" data-arama="yok">${m.tipler.map((t) => `<option value="${t.kod}"${t.kod === s.tip ? ' selected' : ''}>${esc(t.ad)}</option>`).join('')}</select>
    <input type="text" name="hedef" inputmode="numeric" list="snHedef-${esc(s.tip)}" value="${esc(s.hedef)}" placeholder="${esc(ornek(s.tip))}">
    <button type="button" class="sn-ib sn-sil" title="Seçeneği sil"><i class="bi bi-trash3"></i></button>
  </div>`;
}

baslat();
