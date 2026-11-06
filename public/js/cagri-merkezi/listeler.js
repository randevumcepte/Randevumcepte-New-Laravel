/**
 * Çağrı Merkezi › LİSTE YÖNETİMİ (yönetici; eski arama-dashboard "Liste Yönetimi" sekmesi).
 * "Yeni Arama Listesi" (filtreyle oluştur — cagri-merkezi/liste-olustur.js). Liste seç → sonuca göre segmentler (sabit set, sunucudan); segmenti CSV indir, seçili
 * segment(ler)i yeni listeyle başka personele taşı, listenin tamamını personele ata / atamayı
 * geri al, aktif / pasif / arşiv, sil. Veri: /cagri-merkezi/listeler[/{id}/segmentler|csv|durum|personel|tasi].
 */
import { token, BASE, el, esc, mesaj, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=2';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { kilitle } from '/js/lib/v2-bilesen.js?v=2';
import { alan, secenekler } from '/js/lib/v2-ongorusme.js?v=1';
import { listeOlustur } from '/js/cagri-merkezi/liste-olustur.js?v=1';

export async function ciz(kap, ctx) {
  const d = { arsiv: false, listeler: [], id: null, veri: null, secim: new Set() };
  const durumlar = ctx.acilis.sozluk.liste_durumlari;
  kap.innerHTML = `
    <div class="cm-panel">
      <div class="cm-panel-bas">
        <h3><i class="bi bi-funnel"></i> Liste Segmentasyonu</h3>
        <label class="cm-kucuk"><input type="checkbox" id="lArsiv"> Arşivdekiler</label>
        <select id="lListe" class="cm-liste-sec"></select>
        <button type="button" class="rc-btn rc-btn-ikincil" id="lYenile"><i class="bi bi-arrow-clockwise"></i></button>
        <button type="button" class="rc-btn rc-btn-yesil" id="lYeni"><i class="bi bi-plus-lg"></i> Yeni Arama Listesi</button>
      </div>
      <p class="cm-soluk cm-kucuk cm-aciklama">Bir arama listesini sonuca göre ayırın; segmenti <b>indirin</b> ya da seçtiğiniz segmentleri <b>başka personele taşıyın</b>.</p>
      <div id="lGovde"><div class="cm-bos"><i class="bi bi-hand-index"></i>Yukarıdan bir arama listesi seçin.</div></div>
    </div>`;
  const $ = (s) => kap.querySelector(s);

  async function listeleriYukle() {
    const r = await ctx.oku('/listeler', { arsiv: d.arsiv ? 1 : 0, tumu: 1 });
    d.listeler = r.ok && r.govde?.basarili ? r.govde.veri.kartlar : [];
    $('#lListe').innerHTML = secenekler(d.listeler.map((l) => ({ id: l.id, ad: `${l.baslik} (${l.toplam})${l.personel ? ' — ' + l.personel : ''}` })), d.id, 'Liste seçin…');
    if (d.id && !d.listeler.some((l) => l.id === d.id)) { d.id = null; $('#lGovde').innerHTML = '<div class="cm-bos"><i class="bi bi-hand-index"></i>Yukarıdan bir arama listesi seçin.</div>'; }
  }

  async function segmentYukle() {
    if (!d.id) return;
    $('#lGovde').innerHTML = '<div class="cm-bos"><span class="rc-yukleniyor"></span></div>';
    const r = await ctx.oku(`/listeler/${d.id}/segmentler`);
    if (!r.ok || !r.govde?.basarili) { $('#lGovde').innerHTML = `<div class="cm-bos cm-hata">${esc(r.govde?.mesaj || 'Segmentler yüklenemedi')}</div>`; return; }
    d.veri = r.govde.veri;
    d.secim = new Set(d.veri.varsayilan_aktarim.filter((k) => d.veri.segmentler.some((s) => s.kod === k && s.adet > 0)));
    govdeCiz();
  }

  function govdeCiz() {
    const v = d.veri;
    $('#lGovde').innerHTML = `
      <div class="cm-liste-bilgi">
        <b>${esc(v.baslik)}</b> <span class="cm-soluk">· ${esc(v.toplam)} müşteri · ${v.personel_ad ? esc(v.personel_ad) : 'Atanmamış'}</span>
        <span class="cm-durum-sec">${durumlar.map((x) => `<button type="button" class="cm-cip-btn${v.durum === x.kod ? ' aktif' : ''}" data-durum="${x.kod}">${esc(x.ad)}</button>`).join('')}</span>
      </div>
      <div class="cm-segmentler">${v.segmentler.map((s) => `
        <label class="cm-segment cm-k-${esc(s.renk)}${s.adet ? '' : ' bos'}">
          <input type="checkbox" data-sec="${esc(s.kod)}" ${d.secim.has(s.kod) ? 'checked' : ''} ${s.adet ? '' : 'disabled'}>
          <span class="cm-seg-ad">${esc(s.ad)}</span><b>${esc(s.adet)}</b>
          ${s.adet ? `<button type="button" class="rc-islem" data-indir="${esc(s.kod)}" title="CSV indir"><i class="bi bi-download"></i></button>` : ''}
        </label>`).join('')}</div>
      <div class="cm-liste-aksiyon">
        <button type="button" class="rc-btn rc-btn-birincil" id="lTasi"><i class="bi bi-arrow-left-right"></i> Seçilenleri Personele Taşı</button>
        <button type="button" class="rc-btn rc-btn-ikincil" id="lAta"><i class="bi bi-person-check"></i> Listeyi Personele Ata</button>
        ${v.personel_id ? '<button type="button" class="rc-btn rc-btn-ikincil" id="lGeriAl"><i class="bi bi-person-dash"></i> Atamayı Geri Al</button>' : ''}
        <button type="button" class="rc-btn rc-btn-ikincil cm-tehlike" id="lSil"><i class="bi bi-trash"></i> Listeyi Sil</button>
      </div>`;
  }

  const yaz = async (method, yol, body, basari) => {
    const r = await ctx.yaz(method, `/listeler/${d.id}${yol}`, body);
    if (!yazSonuc(r, r.govde?.mesaj || basari)) return null;
    await listeleriYukle();
    return r.govde.veri;
  };

  function personelModal(baslik, ekHtml, gonder) {
    const ov = modalAc(baslik, `<div class="rc-form-izgara">${alan('Personel *', `<select id="mPersonel">${secenekler(ctx.acilis.personeller, null, 'Personel seçin…')}</select>`, 'rc-tam')}${ekHtml}</div>`,
      '<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-birincil" id="mTamam"><i class="bi bi-check-lg"></i> Onayla</button>',
      { ikon: 'person-check', altbaslik: d.veri.baslik, dar: true });
    el('[data-kapat]', ov).onclick = modalKapat;
    el('#mTamam', ov).onclick = (e) => {
      const pid = Number(el('#mPersonel', ov).value);
      if (!pid) return mesaj('Personel seçin.', 'hata');
      kilitle(e.currentTarget, 'İşleniyor…', async () => { if (await gonder(pid, ov)) { modalKapat(); segmentYukle(); } });
    };
  }

  async function csvIndir(kod) {
    const r = await fetch(`${BASE}/cagri-merkezi/listeler/${d.id}/csv?salon_id=${ctx.salonId}&kod=${encodeURIComponent(kod)}`, { headers: { Authorization: 'Bearer ' + token() } });
    if (!r.ok) { let m = `İndirilemedi (${r.status})`; try { m = (await r.json()).mesaj || m; } catch (_) { /* yok say */ } return mesaj(m, 'hata'); }
    const dosya = (r.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/)?.[1] || 'liste.csv';
    const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(await r.blob()), download: dosya });
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 2000);
  }

  $('#lArsiv').addEventListener('change', (e) => { d.arsiv = e.target.checked; listeleriYukle(); });
  $('#lListe').addEventListener('change', (e) => { d.id = Number(e.target.value) || null; segmentYukle(); });
  $('#lYeni').addEventListener('click', () => listeOlustur(ctx, { tamam: async (v) => { await listeleriYukle(); d.id = v.id; $('#lListe').value = String(v.id); segmentYukle(); } }));
  $('#lYenile').addEventListener('click', async () => { await listeleriYukle(); segmentYukle(); });
  $('#lGovde').addEventListener('change', (e) => {
    const c = e.target.closest('[data-sec]');
    if (c) c.checked ? d.secim.add(c.dataset.sec) : d.secim.delete(c.dataset.sec);
  });
  $('#lGovde').addEventListener('click', async (e) => {
    const t = e.target;
    const indir = t.closest('[data-indir]');
    if (indir) { e.preventDefault(); return csvIndir(indir.dataset.indir); }
    const drm = t.closest('[data-durum]');
    if (drm) { if (await yaz('PATCH', '/durum', { durum: Number(drm.dataset.durum) })) segmentYukle(); return; }
    if (t.closest('#lTasi')) {
      if (!d.secim.size) return mesaj('Taşınacak segment seçin.', 'hata');
      const adet = d.veri.segmentler.filter((s) => d.secim.has(s.kod)).reduce((x, s) => x + s.adet, 0);
      return personelModal(`${adet} müşteriyi taşı`, alan('Yeni liste başlığı <small>(opsiyonel)</small>', '<input id="mBaslik" maxlength="190" placeholder="Boş: otomatik">', 'rc-tam'),
        (pid, ov) => yaz('POST', '/tasi', { kodlar: [...d.secim], personel_id: pid, baslik: el('#mBaslik', ov).value.trim() || null }));
    }
    if (t.closest('#lAta')) return personelModal('Listeyi personele ata', '', (pid) => yaz('PATCH', '/personel', { personel_id: pid }));
    if (t.closest('#lGeriAl')) {
      if (await onay('Liste hiçbir personelin kuyruğunda görünmeyecek.', { baslik: 'Atama geri alınsın mı?', tamam: 'Geri Al' }) && await yaz('PATCH', '/personel', { personel_id: null })) segmentYukle();
      return;
    }
    if (t.closest('#lSil')) {
      if (!await onay(`"${d.veri.baslik}" listesi ve içindeki tüm müşteri / görüşme kayıtları SİLİNECEK. Bu işlem geri alınamaz.`, { baslik: 'Liste silinsin mi?', tamam: 'Sil' })) return;
      if (await yaz('DELETE', '', {})) { d.id = null; $('#lGovde').innerHTML = '<div class="cm-bos"><i class="bi bi-hand-index"></i>Yukarıdan bir arama listesi seçin.</div>'; }
    }
  });

  await listeleriYukle();
}

