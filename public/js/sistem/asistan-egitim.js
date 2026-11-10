/** Asistan Egitimi: kalip kutuphanesi, AI'ya dusen sorular, PDF ile toplu egitim. Veri /api/v2/sistem/asistan-egitim. */
import { el, esc, mesaj, onay } from '/js/lib/v2-ortak.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=8';
import { ekran, syApi, syVeri, syYaz, syForm, para, tarihSaat } from '/js/lib/v2-sistem.js?v=7';
import { sayi } from '/js/sistem/ortak.js?v=1';

const KOK = '/asistan-egitim';

let izinler = { yaz: false };
let onizleme = { dosya: '', maddeler: [] };
const tablolar = {};
const kaliplar = new Map();
const sorular = new Map();


function iskelet(d) {
  const yaz = izinler.yaz;
  return `
    <div class="ae-baslik"><h1><span class="mdi mdi-school"></span> Asistan Eğitimi</h1></div>
    <div class="ae-alt">Kalıp soru-cevap ekle → o sorular (ve eş anlamlıları) AI’ya gitmeden <b>bedava</b> cevaplanır. Aşağıda AI’ya düşen soruları görüp tek tıkla kalıba çevir; asistan zamanla AI’ya daha az ihtiyaç duyar.</div>
    ${d.tablo_yok ? '<div class="ae-uyari"><b>asistan_kalip</b> tabloları yok. Eski monolitte migration çalıştırılmalı.</div>' : ''}
    <div class="ae-kartlar" id="aeKartlar"></div>
    ${yaz ? `<div class="ae-panel"><h2>PDF ile Eğitim (toplu)</h2>
      <div class="ae-pdf-not">Bir PDF yükle → yapay zeka belgeyi <b>tek seferlik</b> okuyup soru-cevap kalıpları çıkarır → sen kontrol edip kaydedersin.
        Kaydedilen sorular <b class="ae-yesil">sonsuza kadar bedava</b> cevaplanır (bir daha AI’ya gitmez).
        <br><span class="ae-ipucu">Not: PDF gelişigüzel olabilir (broşür, fiyat listesi, kurallar). Soru-cevap ayrı ayrı yazman gerekmez; AI kendisi çıkarır. En fazla 20 MB.</span></div>
      <div class="ae-satir"><input type="file" id="aePdf" accept="application/pdf,.pdf">
        <button class="sy-btn sy-btn-primary" id="aePdfOku">PDF’i Oku ve Çıkar</button></div></div>
    <div class="ae-panel ae-onizleme" id="aeOnizleme" hidden></div>
    <div class="ae-panel" id="aeForm"><h2 id="aeFormBaslik">Yeni Kalıp Ekle</h2>
      <div class="rc-alan"><label>Tetikleyiciler (eş anlamlılar) — her satıra bir tane ya da virgülle ayır</label>
        <textarea id="aeTetik" class="sy-textarea" rows="3" placeholder="iade&#10;para iade&#10;geri ödeme&#10;iade politikası"></textarea>
        <div class="ae-ipucu">Bu ifadelerden biri soruda geçerse kalıp devreye girer. Ne kadar çok eş anlamlı yazarsan o kadar çok soruyu bedava karşılar.</div></div>
      <div class="rc-alan"><label>Cevap — birden çok cevap için araya <code>---</code> koy (asistan her seferinde rastgele birini söyler)</label>
        <textarea id="aeCevap" class="sy-textarea" rows="4" placeholder="İyiyim, teşekkürler. Sen nasılsın?&#10;---&#10;Gayet iyiyim, bugün nasıl yardımcı olayım?&#10;---&#10;Harikayım! Hazırım, ne konuşalım?"></textarea>
        <div class="ae-ipucu">Tek satırlık <code>---</code> ayırıcı = cevap havuzu. Örn. “nasılsın” için 10-20 farklı cevap yazarsan asistan hep aynısını söylemez. (Kasa/ciro gibi <b>veri</b> soruları için kalıp KULLANMA; onları sistem canlı veriden bedava söyler.)</div></div>
      <div class="rc-alan ae-kategori"><label>Kategori (opsiyonel)</label><input type="text" id="aeKategori" class="sy-input" maxlength="40" placeholder="ör. politika, hizmet, sohbet"></div>
      <div class="ae-satir"><button class="sy-btn sy-btn-primary" id="aeKaydet">Kaydet</button>
        <button class="sy-btn sy-btn-soft" id="aeTemizle">Temizle</button>
        <label class="ae-aktif"><input type="checkbox" id="aeAktif" checked> Aktif</label></div></div>` : ''}
    <div class="ae-panel"><h2>AI’ya Düşen Sorular — kalıba çevir, bedava yap</h2><div id="aeSorular"></div></div>
    <div class="ae-panel"><h2>Kalıp Kütüphanesi</h2><div id="aeKaliplar"></div></div>`;
}

function kartlarCiz(o) {
  const k = (e, d, s = '') => `<div class="ae-kart ${s}"><div class="e">${e}</div><div class="d">${sayi(d)}</div></div>`;
  el('#aeKartlar').innerHTML = k('Kalıp Sayısı', o.kalip) + k('Bedava Cevaplanan', o.bedava, 'yesil') + k('Bekleyen Soru', o.bekleyen);
}

async function ozetYenile() {
  const d = await syVeri(KOK);
  if (d) kartlarCiz(d.ozet);
}

// ───────── FORM ─────────

const form = { id: null, cozulmeyenId: null };

function formTemizle() {
  form.id = null;
  form.cozulmeyenId = null;
  ['#aeTetik', '#aeCevap', '#aeKategori'].forEach((s) => { el(s).value = ''; });
  el('#aeAktif').checked = true;
  el('#aeFormBaslik').textContent = 'Yeni Kalıp Ekle';
}

function formGoster(baslik, odak) {
  el('#aeFormBaslik').textContent = baslik;
  el('#aeForm').scrollIntoView({ behavior: 'smooth' });
  odak?.focus();
}

function kalipDuzenle(k) {
  formTemizle();
  form.id = k.id;
  el('#aeTetik').value = k.tetikleyiciler || '';
  el('#aeCevap').value = k.cevap || '';
  el('#aeKategori').value = k.kategori || '';
  el('#aeAktif').checked = k.aktif;
  formGoster(`Kalıbı Düzenle (#${k.id})`);
}

function kalibaCevir(s) {
  formTemizle();
  form.cozulmeyenId = s.id;
  el('#aeTetik').value = s.soru || '';
  formGoster('Bu Soruyu Kalıba Çevir', el('#aeCevap'));
}

async function kalipKaydet() {
  const govde = { tetikleyiciler: el('#aeTetik').value, cevap: el('#aeCevap').value, kategori: el('#aeKategori').value || null, aktif: el('#aeAktif').checked };
  const { tamam } = form.id
    ? await syYaz('PUT', `${KOK}/kalip/${form.id}`, govde)
    : await syYaz('POST', `${KOK}/kalip`, { ...govde, cozulmeyen_id: form.cozulmeyenId });
  if (!tamam) return;
  formTemizle();
  tablolar.kalip.yenile(false);
  tablolar.soru.yenile(false);
  ozetYenile();
}

// ───────── PDF ─────────

async function pdfOku(e) {
  const dosya = el('#aePdf').files[0];
  if (!dosya) { mesaj('PDF seçilmedi.', 'hata'); return; }
  const b = e.currentTarget;
  const metin = b.textContent;
  b.textContent = 'Okunuyor… (10-30 sn)';
  b.disabled = true;
  const fd = new FormData();
  fd.append('pdf', dosya);
  const { tamam, veri } = await syForm(`${KOK}/pdf-coz`, fd);
  b.textContent = metin;
  b.disabled = false;
  if (!tamam) return;
  onizleme = veri;
  onizlemeCiz();
}

function onizlemeCiz() {
  const k = el('#aeOnizleme');
  k.hidden = false;
  k.innerHTML = `<h2>Çıkarılan Soru-Cevaplar — kontrol et, kaydet <span class="ae-dosya">(${esc(onizleme.dosya)})</span></h2>
    ${onizleme.maddeler.map((o, i) => `<div class="ae-madde" data-i="${i}">
      <label class="ae-madde-bas"><input type="checkbox" class="ae-sec" checked> Bu kalıbı kaydet <span class="ae-etik">${esc(o.kategori)}</span></label>
      <div class="rc-alan"><label>Tetikleyiciler (virgülle)</label><textarea class="sy-textarea ae-m-tetik" rows="2">${esc(o.tetikleyiciler)}</textarea></div>
      <div class="rc-alan"><label>Cevap</label><textarea class="sy-textarea ae-m-cevap" rows="3">${esc(o.cevap)}</textarea></div>
    </div>`).join('')}
    <div class="ae-satir"><button class="sy-btn sy-btn-primary" id="aePdfKaydet">Seçilenleri Kaydet</button>
      <button class="sy-btn sy-btn-soft" data-tumu="0">Tümünün işaretini kaldır</button>
      <button class="sy-btn sy-btn-soft" data-tumu="1">Tümünü seç</button></div>`;
  k.querySelectorAll('[data-tumu]').forEach((b) => {
    b.addEventListener('click', () => k.querySelectorAll('.ae-sec').forEach((c) => { c.checked = b.dataset.tumu === '1'; }));
  });
  el('#aePdfKaydet', k).addEventListener('click', pdfKaydet);
}

async function pdfKaydet() {
  const k = el('#aeOnizleme');
  const maddeler = [...k.querySelectorAll('.ae-madde')].filter((m) => el('.ae-sec', m).checked).map((m) => ({
    tetikleyiciler: el('.ae-m-tetik', m).value,
    cevap: el('.ae-m-cevap', m).value,
    kategori: onizleme.maddeler[parseInt(m.dataset.i, 10)].kategori,
  }));
  if (!maddeler.length) { mesaj('Kaydedilecek madde seçilmedi.', 'hata'); return; }
  const { tamam } = await syYaz('POST', `${KOK}/pdf-kaydet`, { maddeler, dosya: onizleme.dosya });
  if (!tamam) return;
  k.hidden = true;
  k.innerHTML = '';
  el('#aePdf').value = '';
  tablolar.kalip.yenile(false);
  ozetYenile();
}

// ───────── TABLOLAR ─────────

function tablolariKur() {
  tablolar.soru = veriTablosu(el('#aeSorular'), {
    anahtar: 'sy-asistan-soru', birim: 'soru', varsayilanBoy: 25, araIpucu: 'Soru…', bosMesaj: 'Şimdilik AI’ya düşen (kalıba çevrilecek) soru yok.', sirala: { alan: 'adet', yon: 'desc' },
    kolonlar: [{ baslik: 'Soru' }, { baslik: 'Kaç kez', sirala: 'adet' }, { baslik: 'Son', sirala: 'son' }, { baslik: '' }],
    satir: (s) => {
      sorular.set(s.id, s);
      return `<tr><td>${esc(s.soru)}</td><td><span class="ae-rozet">${s.adet}×</span></td><td class="sy-text-muted sy-fs-12">${esc(s.son_tarih ? tarihSaat(s.son_tarih) : '—')}</td>
        <td class="nowrap">${izinler.yaz ? `<button class="sy-btn sy-btn-sm sy-btn-soft" data-cevir="${s.id}">Kalıba çevir</button> <button class="sy-btn sy-btn-sm sy-btn-danger" data-kaldir="${s.id}">Kaldır</button>` : ''}</td></tr>`;
    },
    kaynak: (p) => syApi('GET', `${KOK}/cozulmeyenler`, { params: p }),
  });
  tablolar.kalip = veriTablosu(el('#aeKaliplar'), {
    anahtar: 'sy-asistan-kalip', birim: 'kalıp', varsayilanBoy: 25, araIpucu: 'Tetikleyici, cevap, kategori…', bosMesaj: 'Henüz kalıp yok. Yukarıdan ilk kalıbı ekleyin.', sirala: { alan: 'kullanim', yon: 'desc' },
    kolonlar: [{ baslik: 'Tetikleyiciler' }, { baslik: 'Cevap' }, { baslik: 'Kullanım', sirala: 'kullanim' }, { baslik: '' }],
    satir: (k) => {
      kaliplar.set(k.id, k);
      return `<tr class="${k.aktif ? '' : 'ae-pasif'}"><td>${k.tetikleyici_listesi.map((t) => `<span class="ae-etik">${esc(t)}</span>`).join('')}${k.aktif ? '' : '<div class="ae-pasif-yazi">pasif</div>'}</td>
        <td class="ae-cevap">${esc(k.cevap)}</td><td><span class="ae-rozet">${sayi(k.kullanim_sayisi)}</span></td>
        <td class="nowrap">${izinler.yaz ? `<button class="sy-btn sy-btn-sm sy-btn-soft" data-duzenle="${k.id}">Düzenle</button> <button class="sy-btn sy-btn-sm sy-btn-danger" data-sil="${k.id}">Sil</button>` : ''}</td></tr>`;
    },
    kaynak: (p) => syApi('GET', `${KOK}/kaliplar`, { params: p }),
  });

  el('#aeSorular').addEventListener('click', async (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.cevir) kalibaCevir(sorular.get(parseInt(b.dataset.cevir, 10)));
    if (b.dataset.kaldir && await onay('Soru listeden kaldırılsın mı?', { tehlike: true })) {
      const { tamam } = await syYaz('DELETE', `${KOK}/cozulmeyen/${b.dataset.kaldir}`);
      if (tamam) { tablolar.soru.yenile(false); ozetYenile(); }
    }
  });
  el('#aeKaliplar').addEventListener('click', async (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.duzenle) kalipDuzenle(kaliplar.get(parseInt(b.dataset.duzenle, 10)));
    if (b.dataset.sil && await onay('Kalıp silinsin mi?', { tehlike: true })) {
      const { tamam } = await syYaz('DELETE', `${KOK}/kalip/${b.dataset.sil}`);
      if (tamam) { tablolar.kalip.yenile(false); ozetYenile(); }
    }
  });
  tablolar.soru.yenile();
  tablolar.kalip.yenile();
}

async function baslat() {
  const d = await syVeri(KOK);
  if (!d) return;
  izinler = d.izinler;
  ekran().innerHTML = iskelet(d);
  kartlarCiz(d.ozet);
  tablolariKur();
  if (izinler.yaz) {
    el('#aePdfOku').addEventListener('click', pdfOku);
    el('#aeKaydet').addEventListener('click', kalipKaydet);
    el('#aeTemizle').addEventListener('click', formTemizle);
  }
}

baslat();
