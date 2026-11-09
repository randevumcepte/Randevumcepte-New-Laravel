/**
 * Ayarlar → İşletme Bilgileri (v2). Eski ayarlar.blade #isletme-bilgileri kartlarıyla birebir:
 * Kimlik (logo), Tanıtım, Linkler, Fatura, SEO, Görseller.
 * Uçlar: GET/PATCH /ayar/isletme · POST /ayar/isletme/logo · POST/DELETE /ayar/isletme/gorseller.
 * Logo ve görseller seçildiği an yüklenir (eski davranış); metin alanları "Kaydet" ile.
 */
import { esc, mesaj, onay, api, yazSonuc } from '/js/lib/v2-ortak.js?v=7';
import { apiForm } from '/js/lib/v2-dosya.js?v=7';
import { yukle, kaydet, kart, alan, secim, kaydetCubugu, gonderBagla, deger } from '/js/ayarlar/ortak.js?v=4';

const METIN_ALANLARI = ['salon_adi', 'salon_turu_id', 'adres', 'telefon_1', 'whatsapp', 'aciklama', 'instagram_sayfa',
  'facebook_sayfa', 'vergi_adi', 'vergi_adresi', 'vergi_no', 'vergi_dairesi', 'kdv_orani', 'meta_description'];

export async function ciz(kap, ctx) {
  const v = await yukle(kap, '/ayar/isletme', ctx.salonId);
  if (!v) return;
  const s = v.secenekler;
  const kelimeler = Array.from({ length: s.anahtar_kelime_maks }, (_, i) => v.anahtar_kelimeler[i] || '');

  kap.innerHTML = `
    <form class="ay-form" novalidate>
      ${kart('bi-person-badge', 'İşletme Kimliği', `
        <div class="ay-logo">
          <img id="ayLogo" src="${esc(v.logo_url || '')}" alt="" ${v.logo_url ? '' : 'hidden'}>
          <span class="ay-logo-bos" ${v.logo_url ? 'hidden' : ''}><i class="bi bi-image"></i></span>
          <label class="rc-btn rc-btn-ikincil"><i class="bi bi-upload"></i> Logo Yükle<input type="file" id="ayLogoDosya" accept="image/*" hidden></label>
        </div>
        <div class="rc-form-izgara">
          ${alan('salon_adi', 'İşletme Adı *', v.salon_adi, { maks: 255 })}
          ${secim('salon_turu_id', 'İşletme Türü', v.salon_turu_id, s.salon_turleri.map((t) => ({ kod: t.id, ad: t.ad })), { bos: 'Seçiniz' })}
          ${alan('telefon_1', 'Telefon *', v.telefon_1, { tip: 'tel', maks: 30, ek: 'placeholder="5XXXXXXXXX"' })}
          ${alan('whatsapp', 'WhatsApp', v.whatsapp, { tip: 'tel', maks: 30 })}
          ${alan('adres', 'Adres', v.adres, { tip: 'textarea', tam: true, satir: 2 })}
        </div>`)}
      ${kart('bi-card-text', 'Tanıtım', alan('aciklama', `Tanıtım yazısı (en fazla ${s.aciklama_maks} karakter)`, v.aciklama, { tip: 'textarea', tam: true, maks: s.aciklama_maks, satir: 5 }))}
      ${kart('bi-link-45deg', 'Linkler', `
        <div class="rc-form-izgara">
          ${v.site_url ? `<div class="rc-alan rc-tam"><label>Online randevu sayfanız</label>
            <div class="ay-kopya"><input type="text" value="${esc(v.site_url)}" readonly>
              <button type="button" class="rc-btn rc-btn-ikincil" data-kopyala="${esc(v.site_url)}"><i class="bi bi-clipboard"></i> Kopyala</button>
              <a class="rc-btn rc-btn-ikincil" href="${esc(v.site_url)}" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i></a></div></div>` : ''}
          ${alan('instagram_sayfa', 'Instagram', v.instagram_sayfa, { maks: 255, ek: 'placeholder="https://instagram.com/..."' })}
          ${alan('facebook_sayfa', 'Facebook', v.facebook_sayfa, { maks: 255, ek: 'placeholder="https://facebook.com/..."' })}
        </div>`)}
      ${kart('bi-receipt', 'Fatura Bilgileri', `
        <div class="rc-form-izgara">
          ${alan('vergi_adi', 'Fatura Unvanı', v.vergi_adi, { maks: 255 })}
          ${alan('vergi_no', 'Vergi / TC No', v.vergi_no, { maks: 20 })}
          ${alan('vergi_dairesi', 'Vergi Dairesi', v.vergi_dairesi, { maks: 255 })}
          ${alan('kdv_orani', 'KDV Oranı (%)', v.kdv_orani, { tip: 'number', ek: 'min="0" max="100" step="0.01"' })}
          ${alan('vergi_adresi', 'Fatura Adresi', v.vergi_adresi, { tip: 'textarea', tam: true, satir: 2 })}
        </div>`)}
      ${kart('bi-search', 'SEO', `
        <div class="rc-form-izgara">
          ${alan('meta_description', 'Arama motoru açıklaması', v.meta_description, { tip: 'textarea', tam: true, maks: 500, satir: 2 })}
          <div class="rc-alan rc-tam"><label>Anahtar kelimeler</label>
            <div class="ay-kelimeler">${kelimeler.map((k) => `<input type="text" name="anahtar_kelimeler[]" value="${esc(k)}" maxlength="100">`).join('')}</div></div>
        </div>`, 'Online randevu sayfanızın Google aramalarında görünmesine yardımcı olur.')}
      ${kaydetCubugu()}
    </form>
    ${kart('bi-images', 'Görseller', '<div id="ayGaleri"></div>', `Mini sitenizde gösterilir (en fazla ${s.galeri_maks} adet).`)}`;

  const form = kap.querySelector('form');
  gonderBagla(form, async () => {
    const body = { salon_id: ctx.salonId, anahtar_kelimeler: [...form.querySelectorAll('[name="anahtar_kelimeler[]"]')].map((i) => i.value.trim()).filter(Boolean) };
    METIN_ALANLARI.forEach((a) => { const d = deger(form, a).trim(); body[a] = d === '' ? null : d; });
    await kaydet('PATCH', '/ayar/isletme', body);
  });
  kap.querySelector('[data-kopyala]')?.addEventListener('click', (e) => {
    navigator.clipboard?.writeText(e.currentTarget.dataset.kopyala).then(() => mesaj('Link kopyalandı', 'ok'));
  });
  kap.querySelector('#ayLogoDosya')?.addEventListener('change', (e) => logoYukle(kap, ctx, e.target));
  galeriCiz(kap.querySelector('#ayGaleri'), ctx, v.galeri);
}

async function logoYukle(kap, ctx, girdi) {
  if (!girdi.files[0]) return;
  const fd = new FormData();
  fd.append('salon_id', ctx.salonId);
  fd.append('logo', girdi.files[0]);
  const r = await apiForm('/ayar/isletme/logo', fd);
  girdi.value = '';
  if (!yazSonuc(r, r.govde?.mesaj)) return;
  const img = kap.querySelector('#ayLogo');
  img.src = r.govde.veri.logo_url;
  img.hidden = false;
  kap.querySelector('.ay-logo-bos').hidden = true;
}

function galeriCiz(kap, ctx, g) {
  const ekle = g.kalan > 0
    ? `<label class="ay-gorsel ay-gorsel-ekle"><i class="bi bi-plus-lg"></i><span>Görsel ekle<br><small>${g.kalan} adet kaldı</small></span>
        <input type="file" accept="image/*" multiple hidden></label>`
    : '';
  kap.innerHTML = `<div class="ay-galeri">${g.gorseller.map((x) => `
    <figure class="ay-gorsel"><a href="${esc(x.url)}" target="_blank" rel="noopener"><img src="${esc(x.url)}" alt="" loading="lazy"></a>
      <button type="button" class="ay-gorsel-sil" data-sil="${x.id}" title="Sil"><i class="bi bi-x-lg"></i></button></figure>`).join('')}${ekle}</div>`;

  kap.querySelector('input[type=file]')?.addEventListener('change', async (e) => {
    const dosyalar = [...e.target.files];
    if (!dosyalar.length) return;
    if (dosyalar.length > g.kalan) { mesaj(`En fazla ${g.kalan} görsel daha ekleyebilirsiniz.`, 'hata'); e.target.value = ''; return; }
    const fd = new FormData();
    fd.append('salon_id', ctx.salonId);
    dosyalar.forEach((d) => fd.append('gorseller[]', d));
    kap.querySelector('.ay-gorsel-ekle').classList.add('ay-yukleniyor');
    const r = await apiForm('/ayar/isletme/gorseller', fd);
    if (yazSonuc(r, r.govde?.mesaj)) galeriCiz(kap, ctx, r.govde.veri);
    else galeriCiz(kap, ctx, g);
  });
  kap.onclick = async (e) => {
    const b = e.target.closest('[data-sil]');
    if (!b || !(await onay('Bu görsel silinsin mi?', { tehlike: true }))) return;
    const r = await api('DELETE', `/ayar/isletme/gorseller/${b.dataset.sil}`, { params: { salon_id: ctx.salonId } });
    if (yazSonuc(r, r.govde?.mesaj)) galeriCiz(kap, ctx, r.govde.veri);
  };
}
