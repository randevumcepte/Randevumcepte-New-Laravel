/**
 * SMS Yönetimi › ŞABLON AYARLARI VE TOPLU SMS.
 * Sol: mesaj + karakter/ücret sayacı + gönder. Sağ: müşteri seçici (arama, sonsuz kaydırma, tümünü seç).
 * Alt: şablon kartları (tıkla → mesaja yükle). Oluştur/düzenle/sil modal.
 *
 * Veri: GET /sms/alicilar · /alicilar/idler · POST /gonderim/onizle · /gonderim · /sablonlar.
 * Ücret eşikleri API'den (acilis.parca_esikleri); onay kutusundaki kişi/kredi sayısı sunucudan.
 */
import { el, esc, mesaj, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=1';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { kilitle } from '/js/lib/v2-bilesen.js?v=2';
import { alan } from '/js/lib/v2-ongorusme.js?v=1';

const SAYFA = 100;
const IZLEME_MS = 3000;
const IZLEME_TUR = 20;
const parcaHesap = (uzunluk, esikler) => {
  const i = esikler.findIndex((e) => uzunluk <= e);
  if (i >= 0) return i + 1;
  const son = esikler[esikler.length - 1];
  return esikler.length + Math.ceil((uzunluk - son) / (son - esikler[esikler.length - 2]));
};

export async function ciz(kap, ctx) {
  const { yetkiler, parca_esikleri: esikler } = ctx.acilis;
  const yazabilir = yetkiler.yazabilir;
  const gonderebilir = yetkiler.gonder && yazabilir;
  const secim = { idler: new Set(), toplam: 0, sayfa: 1, ara: '', yukleniyor: false, bitti: false };

  kap.innerHTML = `
    <section class="sm-panel">
      <div class="sm-gonder-izgara">
        <div>
          ${alan('Mesaj İçeriği', '<textarea id="tpMesaj" rows="9" maxlength="2000" placeholder="Mesaj İçeriği"></textarea>', 'rc-tam')}
          <div class="sm-sayac" id="tpSayac"></div>
          ${yetkiler.gonder ? `<button type="button" class="rc-btn rc-btn-yesil" id="tpGonder"${gonderebilir ? '' : ' disabled'}><i class="bi bi-send"></i> Toplu SMS'i Gönder</button>` : ''}
        </div>
        ${yetkiler.gonder ? `<div>
          <label class="sm-etiket">Müşterileri Seçiniz</label>
          <div class="sm-secici-arac">
            <div class="rc-alan"><input id="tpAra" type="search" placeholder="Müşteri arayın…" autocomplete="off"></div>
            <button type="button" class="rc-btn rc-btn-ikincil sm-kucuk-btn" id="tpTumu">Tümünü Seç</button>
            <button type="button" class="rc-btn rc-btn-ikincil sm-kucuk-btn" id="tpHicbiri">Tümünü Kaldır</button>
          </div>
          <div class="sm-secici" id="tpListe"><div class="sm-bos"><span class="rc-yukleniyor"></span></div></div>
          <div class="sm-secili-sayi" id="tpSecili">0 müşteri seçildi</div>
        </div>` : ''}
      </div>
    </section>
    <section class="sm-panel">
      <div class="sm-panel-bas"><h3><i class="bi bi-card-text"></i> Şablonlar <small>Metni mesaja almak için üstüne tıklayın</small></h3>
        ${yazabilir ? '<button type="button" class="rc-btn rc-btn-birincil sm-kucuk-btn" id="tpSablonYeni"><i class="bi bi-plus-lg"></i> Şablon Oluştur</button>' : ''}</div>
      <div class="sm-sablonlar" id="tpSablonlar"></div>
    </section>`;
  const $ = (s) => kap.querySelector(s);
  const mesajKutu = $('#tpMesaj');

  function sayacCiz() {
    const uzunluk = mesajKutu.value.length;
    const parca = parcaHesap(uzunluk, esikler);
    const s = $('#tpSayac');
    s.textContent = `${uzunluk} (Gönderim başına ${parca} SMS üzerinden ücretlendirilecektir)`;
    s.className = `sm-sayac sm-sayac-${parca === 1 ? 'normal' : parca === 2 ? 'uyari' : 'tehlike'}`;
  }
  mesajKutu.addEventListener('input', sayacCiz);
  sayacCiz();

  // ── Müşteri seçici ──
  const seciliCiz = () => { const e = $('#tpSecili'); if (e) e.textContent = `${secim.idler.size} müşteri seçildi`; };

  async function musteriYukle(ekle) {
    if (!$('#tpListe') || secim.yukleniyor || (ekle && secim.bitti)) return;
    secim.yukleniyor = true;
    const istek = secim.ara;
    const sayfa = ekle ? secim.sayfa + 1 : 1;
    const r = await ctx.oku('/alicilar', { page: sayfa, per_page: SAYFA, ara: istek });
    secim.yukleniyor = false;
    const liste = $('#tpListe');
    if (!liste || istek !== secim.ara) { if (liste) musteriYukle(false); return; }
    if (!r.ok || !r.govde?.basarili) { if (!ekle) liste.innerHTML = `<div class="sm-bos sm-hata">${esc(r.govde?.mesaj || 'Müşteriler yüklenemedi')}</div>`; return; }
    const v = r.govde.veri;
    secim.sayfa = v.current_page; secim.toplam = v.total; secim.bitti = v.current_page >= v.last_page;
    const html = v.data.map((m) => `<label class="sm-secici-satir${m.kara_liste ? ' sm-kara' : ''}">
        <input type="checkbox" value="${m.id}"${secim.idler.has(m.id) ? ' checked' : ''}${m.kara_liste ? ' disabled' : ''}>
        <span>${esc(m.name || '(İsimsiz)')}</span><small>${esc(m.telefon)}</small>
        ${m.kara_liste ? '<span class="rc-rozet rc-rozet-gri">Kara liste</span>' : ''}</label>`).join('');
    if (ekle) liste.insertAdjacentHTML('beforeend', html);
    else liste.innerHTML = html || '<div class="sm-bos"><i class="bi bi-search"></i> Müşteri bulunamadı.</div>';
  }

  if (yetkiler.gonder) {
    let zaman;
    $('#tpAra').addEventListener('input', (e) => {
      clearTimeout(zaman);
      zaman = setTimeout(() => { secim.ara = e.target.value.trim(); musteriYukle(false); }, 350);
    });
    $('#tpListe').addEventListener('scroll', (e) => {
      const k = e.currentTarget;
      if (k.scrollTop + k.clientHeight >= k.scrollHeight - 120) musteriYukle(true);
    });
    $('#tpListe').addEventListener('change', (e) => {
      const id = parseInt(e.target.value, 10);
      if (!id) return;
      if (e.target.checked) secim.idler.add(id); else secim.idler.delete(id);
      seciliCiz();
    });
    $('#tpTumu').addEventListener('click', (e) => kilitle(e.currentTarget, 'Seçiliyor…', async () => {
      const r = await ctx.oku('/alicilar/idler', { ara: secim.ara });
      if (!yazSonuc(r)) return;
      r.govde.veri.idler.forEach((id) => secim.idler.add(id));
      kap.querySelectorAll('#tpListe input:not(:disabled)').forEach((c) => { c.checked = true; });
      seciliCiz();
    }));
    $('#tpHicbiri').addEventListener('click', () => {
      secim.idler.clear();
      kap.querySelectorAll('#tpListe input').forEach((c) => { c.checked = false; });
      seciliCiz();
    });
    $('#tpGonder').addEventListener('click', (e) => kilitle(e.currentTarget, 'Gönderiliyor…', () => gonder()));
    musteriYukle(false);
  }

  async function gonder() {
    const metin = mesajKutu.value.trim();
    if (!metin || !secim.idler.size) { mesaj('Lütfen alıcıları seçip mesajınızı yazınız.', 'hata'); return; }
    const govde = { musteri_idler: [...secim.idler], mesaj: metin };
    const on = await ctx.yaz('POST', '/gonderim/onizle', govde);
    if (!yazSonuc(on)) return;
    const o = on.govde.veri;
    if (!o.alici) { mesaj('Seçili müşteriler kara listede ya da telefonsuz olduğu için mesaj gönderilemez.', 'hata'); return; }
    const satirlar = [`${o.alici} kişiye SMS gönderilecek (${o.parca} SMS × ${o.alici} = ${o.tahmini_kredi} kredi).`];
    if (o.kara_liste) satirlar.push(`${o.kara_liste} kişi kara listede olduğu için atlanacak.`);
    if (o.bakiye_yeterli === false) satirlar.push(`Dikkat: kalan bakiye (${o.kalan_sms}) yetersiz görünüyor.`);
    if (!await onay(satirlar.join(' '), { baslik: 'SMS gönderilsin mi?', tamam: 'Gönder', tehlike: false })) return;

    const r = await ctx.yaz('POST', '/gonderim', { ...govde, tur: 'toplu' });
    if (!yazSonuc(r, r.govde?.mesaj)) return;
    mesajKutu.value = '';
    sayacCiz();
    secim.idler.clear();
    kap.querySelectorAll('#tpListe input').forEach((c) => { c.checked = false; });
    seciliCiz();
    sonucuIzle(r.govde.veri.gonderim_id);
  }

  /** Kuyruk sonucunu kısa süre izler; sonuç gelmezse kullanıcı Raporlar'dan takip eder. */
  async function sonucuIzle(id) {
    for (let i = 0; i < IZLEME_TUR; i++) {
      await new Promise((coz) => setTimeout(coz, IZLEME_MS));
      if (!kap.isConnected) return;
      const r = await ctx.oku(`/gonderim/${id}`);
      const d = r.govde?.veri;
      if (!r.ok || !d?.tamamlandi) continue;
      if (d.durum === 'hata') mesaj(`SMS gönderilemedi: ${d.hata || d.etiket}`, 'hata');
      else { mesaj('SMS sağlayıcıya iletildi.', 'ok'); ctx.bakiyeYenile(); }
      return;
    }
    mesaj('Gönderim hâlâ kuyrukta. Sonucu SMS Raporları\'ndan takip edebilirsiniz.', 'bilgi');
  }

  // ── Şablonlar ──
  function sablonlariCiz() {
    const liste = ctx.acilis.taslaklar;
    $('#tpSablonlar').innerHTML = liste.length ? liste.map((t) => `
      <article class="sm-sablon" data-sablon="${t.id}" title="Metni mesaja al">
        <div class="sm-sablon-bas"><b>${esc(t.baslik)}</b>
          ${t.duzenlenebilir && yazabilir ? `<span class="rc-islemler">
            <button type="button" class="rc-islem" data-duzenle="${t.id}" title="Düzenle"><i class="bi bi-pencil"></i></button>
            <button type="button" class="rc-islem rc-tehlike" data-sil="${t.id}" title="Sil"><i class="bi bi-trash"></i></button></span>` : ''}</div>
        <p>${esc(t.taslak_icerik)}</p>
      </article>`).join('') : '<div class="sm-bos"><i class="bi bi-card-text"></i> Henüz şablon yok.</div>';
  }
  const sablonBul = (id) => ctx.acilis.taslaklar.find((t) => String(t.id) === String(id));
  const listeyiAl = (r) => { ctx.acilis.taslaklar = r.govde.veri.taslaklar; sablonlariCiz(); };

  function sablonFormu(t = null) {
    const ov = modalAc(t ? 'Şablonu Düzenle' : 'Yeni Şablon', `<div class="rc-form-izgara">
        ${alan('Şablon Adı *', `<input id="sfAd" maxlength="20" value="${esc(t?.baslik || '')}">`, 'rc-tam')}
        ${alan('Mesaj İçeriği *', `<textarea id="sfIcerik" rows="9" maxlength="2000">${esc(t?.taslak_icerik || '')}</textarea>`, 'rc-tam')}</div>`,
    '<button class="rc-btn rc-btn-ikincil" data-kapat>Kapat</button><button class="rc-btn rc-btn-birincil" id="sfKaydet"><i class="bi bi-check-lg"></i> Kaydet</button>',
    { ikon: 'card-text', sabit: true });
    el('[data-kapat]', ov).onclick = modalKapat;
    el('#sfKaydet', ov).onclick = (e) => {
      const govde = { baslik: el('#sfAd', ov).value.trim(), icerik: el('#sfIcerik', ov).value.trim() };
      if (!govde.baslik || !govde.icerik) { mesaj('Şablon adı ve mesaj içeriği boş olamaz.', 'hata'); return; }
      kilitle(e.currentTarget, 'Kaydediliyor…', async () => {
        const r = await (t ? ctx.yaz('PATCH', `/sablonlar/${t.id}`, govde) : ctx.yaz('POST', '/sablonlar', govde));
        if (yazSonuc(r, r.govde?.mesaj)) { listeyiAl(r); modalKapat(); }
      });
    };
  }

  $('#tpSablonYeni')?.addEventListener('click', () => sablonFormu());
  $('#tpSablonlar').addEventListener('click', async (e) => {
    const sil = e.target.closest('[data-sil]');
    if (sil) {
      if (!await onay('Bu şablon silinsin mi?', { baslik: 'Şablon silinecek', tamam: 'Sil', tehlike: true })) return;
      const r = await ctx.yaz('DELETE', `/sablonlar/${sil.dataset.sil}`);
      if (yazSonuc(r, r.govde?.mesaj)) listeyiAl(r);
      return;
    }
    const duz = e.target.closest('[data-duzenle]');
    if (duz) { sablonFormu(sablonBul(duz.dataset.duzenle)); return; }
    const kart = e.target.closest('[data-sablon]');
    if (kart) {
      mesajKutu.value = sablonBul(kart.dataset.sablon)?.taslak_icerik || '';
      sayacCiz();
      mesajKutu.focus();
      mesajKutu.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });
  sablonlariCiz();
}
