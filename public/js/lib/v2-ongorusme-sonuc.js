/**
 * v2 ÖN GÖRÜŞME SONUÇLANDIRMA — TEK KAYNAK. Ön Görüşmeler ekranı ve randevu çekmecesi (takvim,
 * randevular, müşteri detayı) aynı "Satış Yapıldı" / "Satış Yapılmadı" pencerelerini açar.
 * Import yolu her yerde AYNI olmalı:  import { satisYapildiModal } from '/js/lib/v2-ongorusme-sonuc.js?v=3';
 *
 *   satisYapildiModal({ salonId, fv, kayit })            → satış açılır, tahsilat ekranına gidilir
 *   satisYapilmadiModal({ salonId, kayit, onBitti })     → sebep notu zorunlu
 *     fv    : GET /ongorusme/form-verisi (verilmezse çekilir)
 *     kayit : GET /ongorusme/{id} satırı (id, ad_soyad, personel_id, neden{tur, anahtar, etiket, ad})
 */
import { api, el, esc, TL, mesaj, yazSonuc, tarihStr } from '/js/lib/v2-ortak.js?v=7';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';
import { kilitle, satisAltButonlari, satisSonrasi } from '/js/lib/v2-bilesen.js?v=7';
import { satisSonrasiDersPlani } from '/js/lib/v2-ders.js?v=7';
import { alan, secenekler, tarihBagla, sayiVeyaNull, nedenListesi, formVerisi } from '/js/lib/v2-ongorusme.js?v=9';

const yaz = (salonId, yol, body) => api('POST', yol, { body: { ...body, salon_id: salonId } });

/** Satış Yapıldı — nedene göre alanlar (paket: seans + fiyat · ürün: adet · hizmet: seans + fiyat). */
export async function satisYapildiModal({ salonId, fv = null, kayit: k }) {
  const n = k.neden;
  if (!n) return mesaj('Ön görüşmenin satış nedeni tanımlı değil; önce düzenleyip sebep seçin.', 'hata');
  try { fv = fv || await formVerisi(salonId); } catch (e) { return mesaj(e.message, 'hata'); }
  const yetki = (a) => !!fv.yetkiler?.[a];
  const fiyatGizli = !!fv.studyo_modu; // stüdyo MODEL B
  const katalog = nedenListesi(fv).find((s) => s.anahtar === n.anahtar) || {};
  const fiyatAlani = () => (fiyatGizli ? '' : alan('Fiyat (₺)', `<input id="sFiyat" type="number" min="0" step="0.01" value="${esc(katalog.fiyat ?? '')}" ${yetki('fiyat_degistir') ? '' : 'readonly title="Fiyat değiştirme yetkiniz yok"'}>`));
  const ozel = {
    paket: () => alan('Seans Sayısı', `<input id="sSeans" type="number" min="0" step="1" value="${esc(katalog.seans ?? '')}">`) + fiyatAlani(),
    urun: () => alan('Adet *', '<input id="sAdet" type="number" min="1" step="1" value="1" required>')
      + (fiyatGizli ? '' : alan('Birim Fiyat', `<input value="${esc(TL(katalog.fiyat))}" readonly>`)),
    hizmet: () => alan('Seans Sayısı <small>(2+ ise seanslı)</small>', '<input id="sSeans" type="number" min="0" step="1">') + fiyatAlani(),
  }[n.tur];
  const govde = `
    <div class="og-satis-ozet"><span class="og-neden-tur">${esc(n.etiket)}</span> <b>${esc(n.ad || '')}</b></div>
    <div class="rc-form-izgara og-satis-izgara">
      ${ozel()}
      ${alan('Satış Tarihi', `<input id="sTarih" value="${tarihStr(new Date())}">`)}
      ${alan('Satıcı', `<select id="sPersonel">${secenekler(fv.personeller, k.personel_id, 'Görüşmeyi yapan')}</select>`)}
    </div>`;
  const ov = modalAc('Satış Yapıldı', govde, satisAltButonlari(fiyatGizli, 'Satışı Oluştur'), { ikon: 'cart-check', sabit: true, altbaslik: k.ad_soyad });
  el('[data-kapat]', ov)?.addEventListener('click', modalKapat);
  tarihBagla(el('#sTarih', ov));

  ov.querySelectorAll('[data-odeme]').forEach((btn) => btn.addEventListener('click', () => kilitle(btn, 'İşleniyor…', async () => {
    const d = { tarih: el('#sTarih', ov).value || null, personel_id: sayiVeyaNull(el('#sPersonel', ov).value) };
    if (el('#sSeans', ov)) d.seans = sayiVeyaNull(el('#sSeans', ov).value);
    if (el('#sAdet', ov)) d.adet = sayiVeyaNull(el('#sAdet', ov).value) || 1;
    // Fiyat yalnız yetkiliyken gider (yetkisizde katalog fiyatı sunucuda kullanılır).
    if (el('#sFiyat', ov) && yetki('fiyat_degistir')) d.fiyat = sayiVeyaNull(el('#sFiyat', ov).value);
    const r = await yaz(salonId, `/ongorusme/${k.id}/satis-yapildi`, d);
    if (!yazSonuc(r, r.govde?.mesaj)) return;
    if (fiyatGizli) await satisSonrasiDersPlani(salonId, r.govde.veri.adisyon_id);
    await satisSonrasi(salonId, r.govde.veri.adisyon_id, { studyo: fiyatGizli, odemeAlindi: btn.dataset.odeme === '1' });
  })));
}

/** Satış Yapılmadı + sebep notu (zorunlu). */
export function satisYapilmadiModal({ salonId, kayit: k, onBitti = () => {} }) {
  const ov = modalAc('Satış Yapılmadı', alan('Satış yapılmama sebebi *', '<textarea id="yNot" rows="3" maxlength="1000" required></textarea>', 'rc-tam'),
    '<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-birincil" id="yKaydet"><i class="bi bi-check-lg"></i> Kaydet</button>',
    { ikon: 'x-circle', altbaslik: k.ad_soyad, dar: true });
  el('[data-kapat]', ov).onclick = modalKapat;
  el('#yKaydet', ov).onclick = (e) => {
    const not = el('#yNot', ov).value.trim();
    if (!not) return mesaj('Satış yapılmama sebebini giriniz.', 'hata');
    kilitle(e.currentTarget, 'Kaydediliyor…', async () => {
      const r = await yaz(salonId, `/ongorusme/${k.id}/satis-yapilmadi`, { not });
      if (yazSonuc(r, r.govde?.mesaj)) { modalKapat(); onBitti(r.govde.veri); }
    });
  };
}
