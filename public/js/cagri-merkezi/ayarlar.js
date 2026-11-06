/**
 * Çağrı Merkezi › AYARLAR (yönetici; eski /isletmeyonetim/cagri-ayarlari).
 * Görüşme scriptleri (agent kokpitte seçip okur) + görüşme sonucu kategori ağacı (ana + alt).
 * Veri: GET /cagri-merkezi/ayarlar · POST|PATCH|DELETE /scriptler[/{id}] · /kategoriler[/{id}].
 * Kayıt sonrası açılış verisi (ctx.acilis) de tazelenir → kokpit yeni scripti / kategoriyi hemen görür.
 */
import { el, esc, mesaj, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=2';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { kilitle } from '/js/lib/v2-bilesen.js?v=2';
import { alan } from '/js/lib/v2-ongorusme.js?v=1';

export async function ciz(kap, ctx) {
  let v = { scriptler: [], kategoriler: [] };
  kap.innerHTML = `
    <div class="cm-ayar-izgara">
      <section class="cm-panel">
        <div class="cm-panel-bas"><h3><i class="bi bi-file-text"></i> Görüşme Scriptleri</h3>
          <button type="button" class="rc-btn rc-btn-birincil cm-sag-btn" id="ayScriptYeni"><i class="bi bi-plus-lg"></i> Yeni Script</button></div>
        <div id="ayScriptler" class="cm-ayar-liste"></div>
      </section>
      <section class="cm-panel">
        <div class="cm-panel-bas"><h3><i class="bi bi-tags"></i> Sonuç Kategorileri</h3>
          <button type="button" class="rc-btn rc-btn-birincil cm-sag-btn" id="ayKatYeni"><i class="bi bi-plus-lg"></i> Ana Kategori</button></div>
        <div id="ayKategoriler" class="cm-ayar-liste"></div>
      </section>
    </div>`;
  const $ = (s) => kap.querySelector(s);

  async function yukle() {
    const r = await ctx.oku('/ayarlar');
    if (!kap.isConnected) return;
    if (!r.ok || !r.govde?.basarili) { $('#ayScriptler').innerHTML = `<div class="cm-bos cm-hata">${esc(r.govde?.mesaj || 'Yüklenemedi')}</div>`; return; }
    v = r.govde.veri;
    ctx.acilis.scriptler = v.scriptler.filter((s) => s.aktif).map(({ id, baslik, icerik }) => ({ id, baslik, icerik }));
    ctx.acilis.kategoriler = v.kategoriler;
    ciz2();
  }

  function ciz2() {
    $('#ayScriptler').innerHTML = v.scriptler.length ? v.scriptler.map((s) => `
      <div class="cm-ayar-satir">
        <div class="cm-ayar-orta"><b>${esc(s.baslik)}</b> ${s.aktif ? '' : '<span class="rc-rozet rc-rozet-gri">Pasif</span>'}
          <small>${esc(s.icerik.slice(0, 160))}${s.icerik.length > 160 ? '…' : ''}</small></div>
        <span class="rc-islemler">
          <button type="button" class="rc-islem" data-script="${s.id}" title="Düzenle"><i class="bi bi-pencil"></i></button>
          <button type="button" class="rc-islem rc-tehlike" data-script-sil="${s.id}" title="Sil"><i class="bi bi-trash"></i></button></span>
      </div>`).join('') : '<div class="cm-bos"><i class="bi bi-file-earmark-text"></i>Henüz script yok. Agent\'ların görüşmede okuyacağı metni ekleyin.</div>';

    const islem = (k, alt = false) => `<span class="rc-islemler">
      ${alt ? '' : `<button type="button" class="rc-islem" data-kat-alt="${k.id}" title="Alt kategori ekle"><i class="bi bi-plus"></i></button>`}
      <button type="button" class="rc-islem" data-kat="${k.id}" data-ad="${esc(k.ad)}" title="Yeniden adlandır"><i class="bi bi-pencil"></i></button>
      <button type="button" class="rc-islem rc-tehlike" data-kat-sil="${k.id}" data-ad="${esc(k.ad)}" data-ana="${alt ? 0 : 1}" title="Sil"><i class="bi bi-trash"></i></button></span>`;
    $('#ayKategoriler').innerHTML = v.kategoriler.length ? v.kategoriler.map((k) => `
      <div class="cm-ayar-satir"><div class="cm-ayar-orta"><b>${esc(k.ad)}</b><small>${k.altlar.length} alt kategori</small></div>${islem(k)}</div>
      ${k.altlar.map((a) => `<div class="cm-ayar-satir cm-ayar-alt"><div class="cm-ayar-orta"><i class="bi bi-arrow-return-right"></i> ${esc(a.ad)}</div>${islem(a, true)}</div>`).join('')}`).join('')
      : '<div class="cm-bos"><i class="bi bi-tags"></i>Henüz kategori yok. Görüşme sonucunu sınıflandırmak için ekleyin (ör. Fiyat Sordu › Pahalı Buldu).</div>';
  }

  function scriptFormu(s = null) {
    const ov = modalAc(s ? 'Scripti Düzenle' : 'Yeni Görüşme Scripti', `<div class="rc-form-izgara">
        ${alan('Başlık *', `<input id="scB" maxlength="190" value="${esc(s?.baslik || '')}">`, 'rc-tam')}
        ${alan('İçerik', `<textarea id="scI" rows="10" placeholder="Merhaba, ben … adına arıyorum…">${esc(s?.icerik || '')}</textarea>`, 'rc-tam')}
        <label class="cm-kucuk"><input type="checkbox" id="scA" ${s && !s.aktif ? '' : 'checked'}> Aktif (agent'lar görsün)</label></div>`,
    '<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-birincil" id="scKaydet"><i class="bi bi-check-lg"></i> Kaydet</button>',
    { ikon: 'file-text', genis: true, sabit: true });
    el('[data-kapat]', ov).onclick = modalKapat;
    el('#scKaydet', ov).onclick = (e) => {
      const govde = { baslik: el('#scB', ov).value.trim(), icerik: el('#scI', ov).value, aktif: el('#scA', ov).checked };
      if (!govde.baslik) return mesaj('Başlık zorunlu.', 'hata');
      kilitle(e.currentTarget, 'Kaydediliyor…', async () => {
        if (yazSonuc(await ctx.yaz(s ? 'PATCH' : 'POST', s ? `/scriptler/${s.id}` : '/scriptler', govde), 'Script kaydedildi')) { modalKapat(); yukle(); }
      });
    };
  }

  function kategoriFormu({ id = null, ad = '', ustId = null, baslik }) {
    const ov = modalAc(baslik, alan('Kategori adı *', `<input id="kaAd" maxlength="190" value="${esc(ad)}">`, 'rc-tam'),
      '<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-birincil" id="kaKaydet"><i class="bi bi-check-lg"></i> Kaydet</button>',
      { ikon: 'tags', dar: true });
    el('[data-kapat]', ov).onclick = modalKapat;
    el('#kaAd', ov).focus();
    el('#kaKaydet', ov).onclick = (e) => {
      const yeniAd = el('#kaAd', ov).value.trim();
      if (!yeniAd) return mesaj('Ad zorunlu.', 'hata');
      kilitle(e.currentTarget, 'Kaydediliyor…', async () => {
        const r = await ctx.yaz(id ? 'PATCH' : 'POST', id ? `/kategoriler/${id}` : '/kategoriler', { ad: yeniAd, ust_id: ustId });
        if (yazSonuc(r, 'Kategori kaydedildi')) { modalKapat(); yukle(); }
      });
    };
  }

  $('#ayScriptYeni').onclick = () => scriptFormu();
  $('#ayKatYeni').onclick = () => kategoriFormu({ baslik: 'Yeni Ana Kategori' });
  kap.addEventListener('click', async (e) => {
    const t = e.target.closest('button');
    if (!t) return;
    if (t.dataset.script) return scriptFormu(v.scriptler.find((s) => String(s.id) === t.dataset.script));
    if (t.dataset.scriptSil) {
      if (await onay('Script silinsin mi?', { baslik: 'Script sil', tamam: 'Sil' }) && yazSonuc(await ctx.yaz('DELETE', `/scriptler/${t.dataset.scriptSil}`), 'Script silindi')) yukle();
      return;
    }
    if (t.dataset.katAlt) return kategoriFormu({ ustId: Number(t.dataset.katAlt), baslik: 'Yeni Alt Kategori' });
    if (t.dataset.kat) return kategoriFormu({ id: Number(t.dataset.kat), ad: t.dataset.ad, baslik: 'Kategoriyi Yeniden Adlandır' });
    if (t.dataset.katSil) {
      const metin = t.dataset.ana === '1' ? `"${t.dataset.ad}" ve tüm alt kategorileri silinsin mi?` : `"${t.dataset.ad}" silinsin mi?`;
      if (await onay(metin, { baslik: 'Kategori sil', tamam: 'Sil' }) && yazSonuc(await ctx.yaz('DELETE', `/kategoriler/${t.dataset.katSil}`), 'Kategori silindi')) yukle();
    }
  });

  yukle();
}
