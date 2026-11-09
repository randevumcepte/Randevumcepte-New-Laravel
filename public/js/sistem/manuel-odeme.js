/** Manuel odeme linki: serbest hizmet satirlari + eski paneldeki odeme sayfasina link. */
import { el, mesaj } from '/js/lib/v2-ortak.js?v=7';
import { ekran, para, syVeri, syYaz } from '/js/lib/v2-sistem.js?v=7';
import { kilitli, salonSecici, sayfaBaslik } from '/js/sistem/ortak.js?v=1';

const SATIR = `<div class="mol-satir">
  <input type="text" class="sy-input mol-ad" placeholder="Örn. Üyelik bedeli / Danışmanlık" maxlength="255">
  <input type="number" min="1" value="1" class="sy-input mol-adet">
  <input type="text" class="sy-input mol-tutar" inputmode="decimal" placeholder="0,00">
  <button type="button" class="mol-sil" title="Satırı sil">&times;</button>
</div>`;

/** Yalniz anlik toplam icin; asil ayristirma sunucuda. */
const tutarOku = (m) => parseFloat(String(m || '').replace(/\./g, '').replace(',', '.')) || 0;

async function baslat() {
  const s = await syVeri('/manuel-odeme/secenekler');
  if (!s) return;

  ekran().innerHTML = `
    ${sayfaBaslik('Manuel Ödeme Linki', 'İşletmeye satılan hizmeti, adedi ve tutarı girip PayTR ödeme linki üretin')}
    <div class="sy-card" style="max-width:860px"><div class="sy-card-body">
      <div class="sy-form-group"><label>İşletme *</label><div id="mSalon"></div>
        <small class="sy-text-muted">Ödeme sayfası e-posta/telefon bilgisini seçilen işletmeden alır.</small></div>
      <div class="sy-form-group" style="margin-top:18px"><label>Hizmetler *</label>
        <div class="mol-satir-head"><span>Hizmet Adı</span><span>Adet</span><span>Birim Tutar (₺)</span><span></span></div>
        <div id="mSatirlar"></div>
        <button type="button" class="sy-btn sy-btn-soft sy-btn-sm" id="mEkle"><span class="mdi mdi-plus"></span> Hizmet satırı ekle</button>
        <div class="mol-toplam">Toplam: <span id="mToplam">0,00</span> ₺</div>
      </div>
      <div class="sy-form-group" style="margin-top:14px"><label>Not (opsiyonel — ödeme sayfasında görünmez)</label>
        <input type="text" id="mNot" class="sy-input" maxlength="5000" placeholder="İç not"></div>
      ${s.yazabilir ? `<div class="sy-flex-row" style="justify-content:flex-end;margin-top:18px">
        <button type="button" class="sy-btn sy-btn-primary" id="mOlustur"><span class="mdi mdi-link-variant"></span> Ödeme Linki Oluştur</button>
      </div>` : ''}
      <div class="mol-sonuc" id="mSonuc" hidden>
        <div class="baslik">✓ Ödeme linki oluşturuldu (Form #<span id="mFormId"></span>) — Toplam: <span id="mSonucToplam"></span> ₺</div>
        <div class="mol-link-kutu">
          <input type="text" class="sy-input" id="mLink" readonly>
          <button type="button" class="sy-btn sy-btn-primary" id="mKopyala"><span class="mdi mdi-content-copy"></span> Kopyala</button>
        </div>
      </div>
    </div></div>`;

  const salon = salonSecici(el('#mSalon'), { ipucu: 'İşletme ara / seç...' });
  const satirlar = el('#mSatirlar');
  const satirEkle = () => satirlar.insertAdjacentHTML('beforeend', SATIR);
  const toplamYaz = () => {
    const toplam = [...satirlar.querySelectorAll('.mol-satir')].reduce((t, r) => t + (parseInt(el('.mol-adet', r).value, 10) || 0) * tutarOku(el('.mol-tutar', r).value), 0);
    el('#mToplam').textContent = para(toplam);
  };

  satirlar.addEventListener('click', (e) => {
    if (!e.target.classList.contains('mol-sil')) return;
    const satir = e.target.closest('.mol-satir');
    if (satirlar.children.length > 1) {
      satir.remove();
    } else {
      el('.mol-ad', satir).value = '';
      el('.mol-tutar', satir).value = '';
      el('.mol-adet', satir).value = '1';
    }
    toplamYaz();
  });
  satirlar.addEventListener('input', toplamYaz);
  el('#mEkle').addEventListener('click', satirEkle);
  satirEkle();

  el('#mOlustur')?.addEventListener('click', (e) => kilitli(e.currentTarget, async () => {
    el('#mSonuc').hidden = true;
    if (!salon.tekId()) { mesaj('Lütfen bir işletme seçin.', 'hata'); return; }
    const hizmetler = [...satirlar.querySelectorAll('.mol-satir')]
      .map((r) => ({ ad: el('.mol-ad', r).value.trim(), adet: parseInt(el('.mol-adet', r).value, 10) || 1, tutar: el('.mol-tutar', r).value.trim() }))
      .filter((h) => h.ad !== '' || h.tutar !== '');
    if (!hizmetler.length) { mesaj('En az bir geçerli hizmet satırı girin (ad ve tutar zorunlu).', 'hata'); return; }

    const { tamam, veri } = await syYaz('POST', '/manuel-odeme', { salon_id: salon.tekId(), hizmetler, notlar: el('#mNot').value }, '');
    if (!tamam) return;
    el('#mFormId').textContent = veri.form_id;
    el('#mSonucToplam').textContent = para(veri.toplam);
    el('#mLink').value = veri.link;
    el('#mSonuc').hidden = false;
  }));

  el('#mKopyala').addEventListener('click', async (e) => {
    const b = e.currentTarget;
    const eski = b.innerHTML;
    await navigator.clipboard?.writeText(el('#mLink').value).catch(() => {});
    b.innerHTML = '<span class="mdi mdi-check"></span> Kopyalandı';
    setTimeout(() => { b.innerHTML = eski; }, 1800);
  });
}

baslat();
