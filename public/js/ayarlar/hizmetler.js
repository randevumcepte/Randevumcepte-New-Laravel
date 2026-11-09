/**
 * Ayarlar → Hizmetler (v2). Eski hizmet_yonetimi_panel akışlarıyla birebir:
 *   - kategori + hizmet tablosu (ad, süre, fiyat, maliyet, personel/cihaz) — v2 ortak veri tablosu
 *   - "Sistemden Hizmet Ekle": havuzdan seç → her hizmet için fiyat/süre/personel (2 adım, tek istek)
 *   - "Yeni Hizmet": salona özel hizmet (+ yeni kategori)
 *   - düzenle (havuz hizmetinin adı/kategorisi değişirse sunucu salona özel kopya açar) / kaldır
 * Seçenekler GET /ayar/hizmetler/form-verisi'nden; yetki ve iş kuralları SUNUCUDA.
 */
import { api, el, esc, TL, onay, mesaj, yazSonuc } from '/js/lib/v2-ortak.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=7';
import { ozetKart } from '/js/lib/v2-bilesen.js?v=7';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';
import { secim, secimListesi, secimFiltreBagla, seciliIdler } from '/js/ayarlar/ortak.js?v=4';

const sayiOku = (v) => { const s = String(v ?? '').trim(); if (!s) return null; const n = Number(s.includes(',') ? s.replace(/\./g, '').replace(',', '.') : s); return Number.isFinite(n) ? n : null; };

export async function ciz(kap, ctx) {
  const P = { salon_id: ctx.salonId };
  let form = null; // form-verisi (tembel, ilk modalda)
  const formVerisi = async (tazele = false) => {
    if (form && !tazele) return form;
    const r = await api('GET', '/ayar/hizmetler/form-verisi', { params: P });
    form = yazSonuc(r) ? r.govde.veri : null;
    return form;
  };
  let kategoriId = '';

  kap.innerHTML = `
    <div class="rc-ozet" data-ozet></div>
    <div class="rc-sayfa-ust ay-kaynak-ust">
      <div class="rc-alan ay-dar"><label>Kategori</label><select data-kategori><option value="">Tüm kategoriler</option></select></div>
      <div class="rc-sayfa-aksiyon">
        <button class="rc-btn rc-btn-ikincil" data-havuz><i class="bi bi-collection"></i> Sistemden Hizmet Ekle</button>
        <button class="rc-btn rc-btn-birincil" data-yeni><i class="bi bi-plus-lg"></i> Yeni Hizmet</button>
      </div>
    </div>
    <div data-tablo></div>`;

  const tablo = veriTablosu(el('[data-tablo]', kap), {
    anahtar: 'ayar-hizmetler', varsayilanBoy: 25, birim: 'hizmet',
    araIpucu: 'Hizmet, kategori, personel…', bosMesaj: 'Henüz hizmet eklenmemiş.',
    kolonlar: [
      { baslik: 'Kategori', sirala: 'hizmet_kategorisi' }, { baslik: 'Hizmet Adı', sirala: 'hizmet_adi' },
      { baslik: 'Süre', sirala: 'sure_dk', sinif: 'rc-sag' }, { baslik: 'Fiyat', sirala: 'fiyat', sinif: 'rc-sag' },
      { baslik: 'Maliyet', sirala: 'maliyet', sinif: 'rc-sag' }, { baslik: 'Personel / Cihaz' }, { baslik: 'İşlemler', sinif: 'rc-orta' },
    ],
    satir: (h) => `<tr data-id="${h.id}">
      <td>${esc(h.hizmet_kategorisi)}</td>
      <td><b>${esc(h.hizmet_adi)}</b> ${h.cinsiyet_etiket ? `<span class="ay-cinsiyet ay-c-${h.cinsiyet}">${esc(h.cinsiyet_etiket)}</span>` : ''}${h.ozel_hizmet ? ' <small class="ay-soluk">(özel)</small>' : ''}</td>
      <td class="rc-sag rc-nowrap">${h.sure_dk ? `${h.sure_dk} dk` : '—'}</td>
      <td class="rc-sag rc-nowrap">${TL(h.fiyat)}</td>
      <td class="rc-sag rc-nowrap">${h.maliyet ? TL(h.maliyet) : '—'}</td>
      <td>${[h.personel, h.cihaz].filter(Boolean).map(esc).join(', ') || '<span class="ay-uyari"><i class="bi bi-exclamation-circle"></i> Atanmamış</span>'}</td>
      <td class="rc-orta"><span class="rc-islemler">
        <button class="rc-islem" data-duzenle title="Düzenle"><i class="bi bi-pencil"></i></button>
        <button class="rc-islem rc-tehlike" data-sil title="Kaldır"><i class="bi bi-trash"></i></button></span></td>
    </tr>`,
    kaynak: (p) => api('GET', '/ayar/hizmetler', { params: { ...p, ...P, kategori_id: kategoriId } }),
    cizildi: (_l, v) => {
      if (!v?.ozet) return;
      el('[data-ozet]', kap).innerHTML = ozetKart('bi-clipboard2-check', 'Hizmet', v.ozet.toplam_hizmet, 'mor') + ozetKart('bi-tags', 'Kategori', v.ozet.toplam_kategori, 'turkuaz');
    },
  });
  tablo.yenile();

  // Kategori filtresi: salonun kategorileri ilk tam listeden.
  api('GET', '/ayar/hizmetler', { params: { ...P, tumu: 1 } }).then((r) => {
    if (!r.govde?.basarili) return;
    const kat = new Map(r.govde.veri.data.filter((h) => h.kategori_id).map((h) => [h.kategori_id, h.hizmet_kategorisi]));
    el('[data-kategori]', kap).insertAdjacentHTML('beforeend', [...kat].sort((a, b) => a[1].localeCompare(b[1], 'tr'))
      .map(([id, ad]) => `<option value="${id}">${esc(ad)}</option>`).join(''));
  });
  el('[data-kategori]', kap).addEventListener('change', (e) => { kategoriId = e.target.value; tablo.yenile(); });

  const yaz = async (method, yol, body, params) => {
    const r = await api(method, yol, { body, params });
    if (!yazSonuc(r, r.govde?.mesaj)) return null;
    tablo.yenile(false);
    return r.govde.veri ?? true;
  };

  kap.addEventListener('click', async (e) => {
    if (e.target.closest('[data-yeni]')) return hizmetFormu(null);
    if (e.target.closest('[data-havuz]')) return havuzSec();
    const tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    if (e.target.closest('[data-duzenle]')) return hizmetFormu(Number(tr.dataset.id));
    if (e.target.closest('[data-sil]') && await onay('Bu hizmet katalogdan kaldırılsın mı? Geçmiş satış ve randevular etkilenmez.')) {
      yaz('DELETE', `/ayar/hizmetler/${tr.dataset.id}`, undefined, P);
    }
  });

  // ─────────── Yeni / düzenle ───────────
  async function hizmetFormu(id) {
    const [f, d] = await Promise.all([formVerisi(), id ? api('GET', `/ayar/hizmetler/${id}`, { params: P }) : null]);
    if (!f || (d && !yazSonuc(d))) return;
    const h = d ? d.govde.veri : null;
    const kategoriler = f.kategoriler.map((k) => ({ kod: k.id, ad: k.ad }));
    const ov = modalAc(id ? 'Hizmet Düzenle' : 'Yeni Hizmet', `
      <div class="rc-form-izgara">
        <div class="rc-alan rc-tam"><label>Hizmet Adı *</label><input type="text" name="hizmet_adi" maxlength="255" value="${esc(h?.hizmet_adi || '')}"></div>
        <div class="rc-alan"><label>Kategori *</label><div class="ay-kopya">
          <select name="kategori_id"><option value="">Seçiniz</option>${kategoriler.map((k) => `<option value="${k.kod}"${k.kod === h?.kategori_id ? ' selected' : ''}>${esc(k.ad)}</option>`).join('')}</select>
          <button type="button" class="rc-btn rc-btn-ikincil" data-kat-ac title="Yeni kategori"><i class="bi bi-plus-lg"></i></button></div>
          <div class="ay-kopya" data-kat-yeni hidden><input type="text" maxlength="255" placeholder="Yeni kategori adı">
            <button type="button" class="rc-btn rc-btn-ikincil" data-kat-ekle>Ekle</button></div></div>
        ${secim('cinsiyet', 'Cinsiyet', h?.cinsiyet ?? '', f.cinsiyetler, { bos: 'Belirtilmemiş' })}
        <div class="rc-alan"><label>Süre (dk)</label><input type="number" name="sure_dk" min="0" max="1440" value="${esc(h?.sure_dk ?? '')}"></div>
        <div class="rc-alan"><label>Fiyat (₺)</label><input type="text" name="fiyat" inputmode="decimal" value="${esc(h ? String(h.fiyat).replace('.', ',') : '')}"></div>
        <div class="rc-alan"><label>Maliyet (₺)</label><input type="text" name="maliyet" inputmode="decimal" value="${esc(h?.maliyet ? String(h.maliyet).replace('.', ',') : '')}"></div>
        ${h && !h.ozel_hizmet ? '<div class="rc-alan rc-tam"><span class="rc-ipucu"><i class="bi bi-info-circle"></i> Sistem hizmeti: adını veya kategorisini değiştirirseniz yalnız işletmenize özel bir kopya oluşturulur.</span></div>' : ''}
        ${secimListesi('personel_ids', 'Hizmeti veren personeller', f.personeller, 'personel_adi', (h?.personeller || []).map((p) => p.id))}
        ${f.cihazlar.length ? secimListesi('cihaz_ids', 'Hizmette kullanılan cihazlar', f.cihazlar, 'cihaz_adi', (h?.cihazlar || []).map((c) => c.id)) : ''}
      </div>`,
      '<button class="rc-btn rc-btn-ikincil" data-iptal>Vazgeç</button><button class="rc-btn rc-btn-birincil" data-tamam><i class="bi bi-check2"></i> Kaydet</button>',
      { ikon: 'clipboard2-check', genis: true, sabit: true });
    secimFiltreBagla(ov);
    el('[data-iptal]', ov).onclick = modalKapat;
    el('[data-kat-ac]', ov).onclick = () => { el('[data-kat-yeni]', ov).hidden = false; el('[data-kat-yeni] input', ov).focus(); };
    el('[data-kat-ekle]', ov).onclick = () => kategoriEkle(ov);
    el('[data-tamam]', ov).onclick = async (e) => {
      const v = (ad) => el(`[name=${ad}]`, ov)?.value ?? '';
      const body = {
        ...P, hizmet_adi: v('hizmet_adi').trim(), kategori_id: Number(v('kategori_id')) || null,
        cinsiyet: v('cinsiyet') === '' ? null : Number(v('cinsiyet')), sure_dk: v('sure_dk') === '' ? null : Number(v('sure_dk')),
        fiyat: sayiOku(v('fiyat')) ?? 0, maliyet: sayiOku(v('maliyet')) ?? 0,
        personel_ids: seciliIdler(ov, 'personel_ids'), cihaz_ids: seciliIdler(ov, 'cihaz_ids'),
      };
      e.currentTarget.disabled = true;
      const ok = await yaz(id ? 'PATCH' : 'POST', id ? `/ayar/hizmetler/${id}` : '/ayar/hizmetler', body);
      if (ok) { modalKapat(); formVerisi(true); } else e.currentTarget.disabled = false;
    };
  }

  async function kategoriEkle(ov) {
    const girdi = el('[data-kat-yeni] input', ov);
    const ad = girdi.value.trim();
    if (!ad) return girdi.focus();
    const r = await api('POST', '/ayar/hizmet-kategorileri', { body: { ...P, kategori_adi: ad } });
    if (!yazSonuc(r, r.govde?.mesaj)) return;
    const k = r.govde.veri;
    const select = el('[name=kategori_id]', ov);
    if (!select.querySelector(`option[value="${k.id}"]`)) select.insertAdjacentHTML('beforeend', `<option value="${k.id}">${esc(k.ad)}</option>`);
    select.value = String(k.id);
    girdi.value = '';
    el('[data-kat-yeni]', ov).hidden = true;
    form = null;
  }

  // ─────────── Sistemden ekle (2 adım) ───────────
  async function havuzSec() {
    const f = await formVerisi(true);
    if (!f) return;
    if (!f.havuz.length) return mesaj('Eklenebilecek sistem hizmeti kalmadı.', 'bilgi');
    const ov = modalAc('Sistemden Hizmet Ekle', `
      <input type="text" class="ay-filtre" data-havuz-ara placeholder="Hizmet ara…">
      <div class="ay-havuz">${f.havuz.map((g) => `
        <section data-grup><h4 class="ay-ara-baslik">${esc(g.kategori_adi)}</h4>
          <div class="ay-secim">${g.hizmetler.map((h) => `
            <label data-ad="${esc(h.hizmet_adi.toLocaleLowerCase('tr'))}"><input type="checkbox" value="${h.id}" data-sure="${h.sure_dk ?? ''}" data-ad-goster="${esc(h.hizmet_adi)}"> ${esc(h.hizmet_adi)}</label>`).join('')}</div>
        </section>`).join('')}</div>`,
      '<button class="rc-btn rc-btn-ikincil" data-iptal>Vazgeç</button><button class="rc-btn rc-btn-birincil" data-ileri>Devam <i class="bi bi-arrow-right"></i></button>',
      { ikon: 'collection', genis: true, sabit: true, altbaslik: '1/2 — Eklenecek hizmetleri seçin' });
    el('[data-havuz-ara]', ov).addEventListener('input', (e) => {
      const q = e.target.value.trim().toLocaleLowerCase('tr');
      ov.querySelectorAll('[data-grup]').forEach((g) => {
        let gorunen = 0;
        g.querySelectorAll('label').forEach((l) => { l.hidden = !!q && !l.dataset.ad.includes(q); if (!l.hidden) gorunen++; });
        g.hidden = !gorunen;
      });
    });
    el('[data-iptal]', ov).onclick = modalKapat;
    el('[data-ileri]', ov).onclick = () => {
      const secilen = [...ov.querySelectorAll('.ay-havuz input:checked')].map((i) => ({ id: Number(i.value), ad: i.dataset.adGoster, sure: i.dataset.sure }));
      if (!secilen.length) return mesaj('En az bir hizmet seçin.', 'hata');
      havuzDetay(secilen, f);
    };
  }

  function havuzDetay(secilen, f) {
    const ov = modalAc('Sistemden Hizmet Ekle', secilen.map((h) => `
      <section class="ay-kart ay-havuz-kalem" data-hizmet="${h.id}">
        <h4 class="ay-ara-baslik">${esc(h.ad)}</h4>
        <div class="rc-form-izgara">
          <div class="rc-alan"><label>Fiyat (₺)</label><input type="text" name="fiyat" inputmode="decimal"></div>
          <div class="rc-alan"><label>Süre (dk)</label><input type="number" name="sure_dk" min="0" max="1440" value="${esc(h.sure)}"></div>
          ${secimListesi(`p_${h.id}`, 'Personeller', f.personeller, 'personel_adi')}
        </div>
      </section>`).join(''),
      '<button class="rc-btn rc-btn-ikincil" data-geri><i class="bi bi-arrow-left"></i> Geri</button><button class="rc-btn rc-btn-birincil" data-tamam><i class="bi bi-check2"></i> Ekle</button>',
      { ikon: 'collection', genis: true, sabit: true, altbaslik: `2/2 — ${secilen.length} hizmet için fiyat, süre ve personel` });
    secimFiltreBagla(ov);
    el('[data-geri]', ov).onclick = havuzSec;
    el('[data-tamam]', ov).onclick = async (e) => {
      const hizmetler = [...ov.querySelectorAll('[data-hizmet]')].map((s) => ({
        hizmet_id: Number(s.dataset.hizmet),
        fiyat: sayiOku(el('[name=fiyat]', s).value) ?? 0,
        sure_dk: el('[name=sure_dk]', s).value === '' ? null : Number(el('[name=sure_dk]', s).value),
        personel_ids: seciliIdler(s, `p_${s.dataset.hizmet}`),
      }));
      e.currentTarget.disabled = true;
      const ok = await yaz('POST', '/ayar/hizmetler/havuzdan', { ...P, hizmetler });
      if (ok) { modalKapat(); form = null; } else e.currentTarget.disabled = false;
    };
  }
}
