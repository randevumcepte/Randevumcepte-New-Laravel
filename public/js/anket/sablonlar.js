/**
 * Şablonlar sekmesi: liste, oluştur/düzenle (soru oluşturucu + ödül), test gönder, sil.
 * Soru tipleri, hazır şablon ve varsayılanlar API'den (form-verisi); içerik kuralları sunucuda (422 → toast).
 */
import { el, esc, mesaj, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=1';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=1';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { kilitle } from '/js/lib/v2-bilesen.js?v=2';
import { alan } from '/js/lib/v2-ongorusme.js?v=1';
import { odulAlanlari, odulBagla } from '/js/anket/odul-form.js?v=1';

const ONEK = 'odul_';

export async function ciz(kap, ctx) {
  kap.innerHTML = `
    <div class="an-arac">
      ${ctx.yazabilir ? `<button class="rc-btn rc-btn-ikincil" id="anHazir" hidden><i class="bi bi-magic"></i> Hazır Şablonu Yükle</button>
        <button class="rc-btn rc-btn-birincil" id="anYeni"><i class="bi bi-plus-lg"></i> Yeni Anket Şablonu</button>` : ''}
    </div>
    <div class="an-kart an-tablo-kart"><div id="anSablonTablo"></div></div>`;

  const tablo = veriTablosu(el('#anSablonTablo', kap), {
    anahtar: 'anket_sablonlari', birim: 'şablon', araIpucu: 'Anket adı…',
    bosMesaj: 'Henüz anket şablonu yok. NPS, yıldızlı memnuniyet ve açık uçlu sorularla bir şablon oluşturun.',
    kolonlar: [
      { baslik: 'Anket Adı', sirala: 'ad' }, { baslik: 'Soru', sinif: 'an-orta' }, { baslik: 'Otomatik Gönderim', sinif: 'an-orta' },
      { baslik: 'Cevap', sirala: 'cevap_sayisi', sinif: 'an-orta' }, { baslik: 'Durum', sirala: 'aktif', sinif: 'an-orta' }, { baslik: 'İşlemler', sinif: 'an-sag' },
    ],
    kaynak: (p) => ctx.oku('/sablonlar', p),
    cizildi: (liste, v) => {
      ctx.rozet('sablonlar', v?.total ?? liste.length);
      const hazir = el('#anHazir', kap);
      if (hazir) hazir.hidden = (v?.total ?? 0) > 0;
    },
    satir: (s) => `<tr>
      <td><b>${esc(s.ad)}</b>
        ${s.varsayilan ? '<span class="rc-rozet rc-rozet-mor">Varsayılan</span>' : ''}
        ${s.odul_ozet ? `<span class="rc-rozet rc-rozet-turuncu"><i class="bi bi-gift"></i> ${esc(s.odul_ozet)}</span>` : ''}
        ${s.aciklama ? `<div class="an-soluk">${esc(s.aciklama.length > 90 ? `${s.aciklama.slice(0, 90)}…` : s.aciklama)}</div>` : ''}</td>
      <td class="an-orta">${s.soru_sayisi}</td>
      <td class="an-orta">${esc(s.gonderim_ozet)}</td>
      <td class="an-orta"><b>${s.cevap_sayisi}</b></td>
      <td class="an-orta"><span class="rc-rozet rc-rozet-${s.aktif ? 'yesil' : 'gri'}">${s.aktif ? 'Aktif' : 'Pasif'}</span></td>
      <td class="an-sag">${ctx.yazabilir ? `<span class="rc-islemler">
        ${s.aktif ? `<button class="rc-islem rc-olumlu" data-test="${s.id}" data-ad="${esc(s.ad)}" title="Test gönder"><i class="bi bi-send"></i> Test</button>` : ''}
        <button class="rc-islem" data-duzenle="${s.id}"><i class="bi bi-pencil"></i> Düzenle</button>
        <button class="rc-islem rc-tehlike" data-sil="${s.id}" data-ad="${esc(s.ad)}" title="Sil"><i class="bi bi-trash"></i></button>
      </span>` : ''}</td>
    </tr>`,
  });

  const yenile = () => tablo.yenile(false);
  const hazir = ctx.fv.hazir_sablon;
  el('#anYeni', kap)?.addEventListener('click', () => sablonFormu(ctx, { sorular: hazir.sorular }, yenile));
  el('#anHazir', kap)?.addEventListener('click', () => sablonFormu(ctx, hazir, yenile));
  el('#anSablonTablo', kap).addEventListener('click', async (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.duzenle) {
      const r = await ctx.oku(`/sablonlar/${b.dataset.duzenle}`);
      if (yazSonuc(r)) sablonFormu(ctx, r.govde.veri, yenile);
    } else if (b.dataset.test) {
      testFormu(ctx, b.dataset.test, b.dataset.ad);
    } else if (b.dataset.sil) {
      if (!await onay(`"${b.dataset.ad}" şablonunu silmek istediğinize emin misiniz?`)) return;
      const r = await ctx.yaz('DELETE', `/sablonlar/${b.dataset.sil}`);
      if (yazSonuc(r, r.govde?.mesaj)) yenile();
    }
  });
  tablo.yenile();
}

/** Yeni kayıtta s.id yoktur; hazır şablon ad + açıklamayı da doldurur. */
function sablonFormu(ctx, s, onKaydet) {
  const { soru_katalogu: katalog, varsayilan } = ctx.fv;
  const tipBul = (tip) => katalog.tipler.find((t) => t.tip === tip);
  const otomatik = s.otomatik_gonder ?? varsayilan.otomatik_gonder;
  const ov = modalAc(s.id ? 'Anket Şablonu Düzenle' : 'Yeni Anket Şablonu', `
    <div class="rc-form-izgara">
      ${alan('Anket Adı *', `<input id="anAd" value="${esc(s.ad || '')}" maxlength="200" placeholder="Örn: Hizmet Sonrası Memnuniyet Anketi">`, 'rc-tam')}
      ${alan('Açıklama', `<textarea id="anAciklama" rows="2" placeholder="Formun üstünde gösterilir">${esc(s.aciklama || '')}</textarea>`, 'rc-tam')}
      ${alan('Otomatik Gönderim', `<label class="an-secim"><input type="checkbox" id="anOtomatik" ${otomatik ? 'checked' : ''}> Hizmet süresi sonunda gönder</label>`)}
      ${alan('Gecikme (saat)', `<input type="number" id="anSaat" min="0" max="720" value="${esc(s.gonder_saat_sonra ?? varsayilan.gonder_saat_sonra)}"><span class="rc-ipucu">0 = hemen (önerilen)</span>`)}
      ${alan('Varsayılan', `<label class="an-secim"><input type="checkbox" id="anVarsayilan" ${s.varsayilan ? 'checked' : ''}> Hızlı gönderimde kullan</label>`)}
      ${s.id ? alan('Durum', `<label class="an-secim"><input type="checkbox" id="anAktif" ${s.aktif ? 'checked' : ''}> Aktif</label>`) : ''}
    </div>
    <h4 class="an-baslik an-altin"><i class="bi bi-gift"></i> Anketi Tamamlayana Ödül</h4>
    <p class="an-soluk">Anketi dolduran kayıtlı müşteriye otomatik kupon veya puan verilir (puanına bakılmaz); davet mesajında da vurgulanır.</p>
    ${odulAlanlari(ONEK, s, ctx.fv)}
    <h4 class="an-baslik">Sorular *</h4>
    <p class="an-soluk">Soru ekleyip ↑↓ ile sıralayabilirsiniz. NPS ve yıldız soruları otomatik istatistik üretir.</p>
    <div id="anSorular"></div>
    ${katalog.gruplar.map((g) => `
      <div class="an-ekle-grup"><div class="an-kucuk-baslik">${esc(g.etiket)}</div>
        ${katalog.tipler.filter((t) => t.grup === g.grup).map((t) => `<button type="button" class="rc-islem" data-ekle="${esc(t.tip)}"><i class="bi ${esc(t.ikon)}"></i> ${esc(t.etiket)}</button>`).join('')}
      </div>`).join('')}`,
  '<button class="rc-btn rc-btn-ikincil" id="anVazgec">İptal</button><button class="rc-btn rc-btn-birincil" id="anKaydet"><i class="bi bi-check-lg"></i> Kaydet</button>',
  { ikon: 'chat-dots', sabit: true });

  const sorular = el('#anSorular', ov);
  const soruEkle = (q) => sorular.insertAdjacentHTML('beforeend', soruHtml(q, tipBul(q.tip)));
  (s.sorular || []).filter((q) => tipBul(q.tip)).forEach(soruEkle);
  const odul = odulBagla(ov);

  el('.rc-modal-govde', ov).addEventListener('click', (e) => {
    const ekle = e.target.closest('[data-ekle]');
    if (ekle) { soruEkle({ tip: ekle.dataset.ekle, soru: '', zorunlu: true, secenekler: [] }); return; }
    const islem = e.target.closest('[data-soru]');
    if (!islem) return;
    const satir = islem.closest('.an-soru');
    if (islem.dataset.soru === 'sil') satir.remove();
    if (islem.dataset.soru === 'yukari' && satir.previousElementSibling) satir.after(satir.previousElementSibling);
    if (islem.dataset.soru === 'asagi' && satir.nextElementSibling) satir.before(satir.nextElementSibling);
  });

  el('#anVazgec', ov).onclick = modalKapat;
  el('#anKaydet', ov).onclick = (e) => kilitle(e.currentTarget, 'Kaydediliyor…', async () => {
    const govde = {
      ad: el('#anAd', ov).value.trim(),
      aciklama: el('#anAciklama', ov).value.trim(),
      otomatik_gonder: el('#anOtomatik', ov).checked,
      gonder_saat_sonra: el('#anSaat', ov).value || 0,
      varsayilan: el('#anVarsayilan', ov).checked,
      sorular: [...sorular.querySelectorAll('.an-soru')].map(soruOku),
      ...odul.oku(),
    };
    if (s.id) govde.aktif = el('#anAktif', ov).checked;
    const r = await ctx.yaz(s.id ? 'PATCH' : 'POST', s.id ? `/sablonlar/${s.id}` : '/sablonlar', govde);
    if (!yazSonuc(r, 'Şablon kaydedildi')) return;
    modalKapat();
    onKaydet();
  });
}

function soruHtml(q, t) {
  const metin = esc(q.soru || '');
  const girdi = t.tip === 'bilgi_metni'
    ? `<textarea data-metin rows="2" placeholder="Bilgi/açıklama metni...">${metin}</textarea>`
    : `<input data-metin value="${metin}" placeholder="${t.cevapli ? 'Soru metnini yazın...' : 'Bölüm başlığı...'}">`;
  return `
    <div class="an-soru rc-alan" data-tip="${esc(t.tip)}" style="border-left-color:${esc(t.renk)}">
      <div class="an-soru-ust">
        <span class="an-tip" style="color:${esc(t.renk)}">${esc(t.etiket)}</span>
        ${t.cevapli ? `<label class="an-secim"><input type="checkbox" data-zorunlu ${q.zorunlu !== false ? 'checked' : ''}> Zorunlu</label>` : ''}
        <span class="rc-islemler">
          <button type="button" class="rc-islem" data-soru="yukari" title="Yukarı"><i class="bi bi-arrow-up"></i></button>
          <button type="button" class="rc-islem" data-soru="asagi" title="Aşağı"><i class="bi bi-arrow-down"></i></button>
          <button type="button" class="rc-islem rc-tehlike" data-soru="sil" title="Sil"><i class="bi bi-trash"></i></button>
        </span>
      </div>
      ${girdi}
      ${t.secenekli ? `<textarea data-secenekler rows="3" placeholder="Her satıra bir seçenek">${esc((q.secenekler || []).join('\n'))}</textarea>` : ''}
    </div>`;
}

function soruOku(s) {
  const q = { tip: s.dataset.tip, soru: el('[data-metin]', s).value.trim(), zorunlu: !!el('[data-zorunlu]', s)?.checked };
  const sec = el('[data-secenekler]', s);
  if (sec) q.secenekler = sec.value.split('\n').map((x) => x.trim()).filter(Boolean);
  return q;
}

function testFormu(ctx, id, ad) {
  const ov = modalAc('Test Gönder', `
    <div class="an-bilgi an-bilgi-yesil">Anketin nasıl göründüğünü görmek için kendi telefonunuza gönderin (WhatsApp bağlıysa WhatsApp, değilse SMS).</div>
    <div class="rc-form-izgara">
      ${alan('Şablon', `<input value="${esc(ad)}" disabled>`, 'rc-tam')}
      ${alan('Ad Soyad', '<input id="anTestAd" placeholder="Adınız">')}
      ${alan('Telefon', '<input id="anTestTel" placeholder="5XXXXXXXXX" inputmode="tel" maxlength="25">')}
    </div>`,
  '<button class="rc-btn rc-btn-ikincil" id="anTestVazgec">İptal</button><button class="rc-btn rc-btn-yesil" id="anTestGonder"><i class="bi bi-send"></i> Gönder</button>',
  { ikon: 'send', dar: true });
  el('#anTestVazgec', ov).onclick = modalKapat;
  el('#anTestTel', ov).focus();
  el('#anTestGonder', ov).onclick = (e) => kilitle(e.currentTarget, 'Gönderiliyor…', async () => {
    const r = await ctx.yaz('POST', `/sablonlar/${id}/gonder`, { telefon: el('#anTestTel', ov).value.trim(), ad_soyad: el('#anTestAd', ov).value.trim() });
    if (!yazSonuc(r)) return;
    modalKapat();
    mesaj('Anket gönderim kuyruğuna alındı; telefonunuza gelen linke tıklayarak doldurabilirsiniz.', 'ok');
  });
}
