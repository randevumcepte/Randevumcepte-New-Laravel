/**
 * Çarkıfelek › PUAN ÖDÜLLERİ (eski /isletmeyonetim/puanodulleri). Üstte puan KAZANMA kuralı
 * (her X TL'ye Y puan + randevuya Z puan), altta puan MERDİVENİ (eşik → ödül kartları) ve
 * ekle/düzenle modalı (yeni ödülde şube seçimi), "Örnekleri Yükle".
 *
 * Veri: GET /sadakat; PUT /sadakat/kazanim; POST|PATCH|DELETE /sadakat/puan-odulu[/{id}];
 * POST /sadakat/puan-odulu/ornekler. Tip listesi API'den (PuanOdulTipi).
 */
import { el, esc, mesaj, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=1';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { kilitle } from '/js/lib/v2-bilesen.js?v=2';
import { alan, sayiVeyaNull } from '/js/lib/v2-ongorusme.js?v=1';
import { subeSecici } from '/js/lib/v2-sube-secici.js?v=1';

const ORNEK_ODEME = 1000;

export async function ciz(kap, ctx) {
  const r = await ctx.oku('/sadakat');
  if (!kap.isConnected) return;
  if (!r.ok || !r.govde?.basarili) { kap.innerHTML = `<div class="ck-bos ck-hata">${esc(r.govde?.mesaj || 'Yüklenemedi')}</div>`; return; }
  const v = r.govde.veri;
  const tipler = Object.fromEntries(v.sozluk.odul_tipleri.map((t) => [t.kod, t]));

  kap.innerHTML = `
    <section class="ck-kart ck-kazanim" id="pkKart">
      <div class="ck-yonetim-bas"><h3>⭐ Puan Kazanma Kuralı</h3>
        <label class="ck-satir"><span>Açık</span><span class="rc-switch"><input type="checkbox" id="pkAktif"><span class="rc-kaydir"></span></span></label></div>
      <p class="ck-ipucu">Müşteriler ödeme yaptıkça ve randevusuna geldikçe otomatik puan kazanır; biriken puanla aşağıdaki ödülleri alır. Puanlar birkaç dakika içinde hesaba geçer, silinen tahsilatın puanı geri alınır.</p>
      <div class="ck-kural">Her <input type="number" id="pkTutar" min="1" step="1"> TL ödemeye <input type="number" id="pkPuan" min="0" step="1"> puan</div>
      <div class="ck-kural">Gelinen her randevuya <input type="number" id="pkZiyaret" min="0" step="1"> puan</div>
      <div class="ck-ornek" id="pkOrnek"></div>
      <div class="ck-yonetim-alt">
        <small class="ck-soluk" id="pkBaslangic"></small>
        <button type="button" class="rc-btn rc-btn-birincil" id="pkKaydet"><i class="bi bi-save"></i> Kuralı Kaydet</button>
      </div>
    </section>
    <div class="ck-arac">
      <button type="button" class="rc-btn rc-btn-birincil" id="poEkle"><i class="bi bi-plus-lg"></i> Yeni Ödül Ekle</button>
      <span class="ck-soluk" id="poToplam"></span>
    </div>
    <div id="poListe"></div>`;
  const $ = (s) => kap.querySelector(s);

  // ── kazanım kuralı ──
  const k = v.kazanim;
  $('#pkAktif').checked = !!k.aktif;
  $('#pkTutar').value = Math.round(k.harcama_tutari);
  $('#pkPuan').value = k.harcama_puani;
  $('#pkZiyaret').value = k.ziyaret_puani;
  const kural = () => ({
    aktif: $('#pkAktif').checked, harcama_tutari: parseInt($('#pkTutar').value, 10) || 0,
    harcama_puani: parseInt($('#pkPuan').value, 10) || 0, ziyaret_puani: parseInt($('#pkZiyaret').value, 10) || 0,
  });
  const ornekCiz = () => {
    const x = kural();
    $('#pkKart').classList.toggle('kapali', !x.aktif);
    $('#pkOrnek').textContent = !x.harcama_puani && !x.ziyaret_puani ? 'Puan vermek için en az bir değer girin.'
      : `Örnek: Randevusuna gelip ${ORNEK_ODEME.toLocaleString('tr-TR')} TL ödeyen müşteri ${(x.harcama_tutari > 0 ? Math.floor(ORNEK_ODEME / x.harcama_tutari) * x.harcama_puani : 0) + x.ziyaret_puani} puan kazanır.`;
  };
  const baslangicCiz = (b, aktif) => {
    $('#pkBaslangic').textContent = aktif && b ? `${b.slice(0, 16).replace(/^(\d{4})-(\d{2})-(\d{2})/, '$3.$2.$1')} tarihinden sonraki ödeme ve randevular puan kazanır.`
      : 'Açtığınız andan sonraki ödeme ve randevular puan kazanır; geçmiş kayıtlara puan dağıtılmaz.';
  };
  kap.querySelector('#pkKart').addEventListener('input', ornekCiz);
  ornekCiz();
  baslangicCiz(k.baslangic, k.aktif);
  $('#pkKaydet').onclick = (e) => kilitle(e.currentTarget, 'Kaydediliyor…', async () => {
    const s = await ctx.yaz('PUT', '/sadakat/kazanim', kural());
    if (yazSonuc(s, 'Kural kaydedildi')) baslangicCiz(s.govde.veri.baslangic, s.govde.veri.aktif);
  });

  // ── ödül merdiveni ──
  let oduller = v.odul_seviyeleri;
  const listeCiz = () => {
    $('#poToplam').innerHTML = `Toplam: <b>${oduller.length}</b> ödül seviyesi`;
    $('#poListe').innerHTML = oduller.length ? `<div class="ck-merdiven">${oduller.map((o) => {
      const t = tipler[o.tip] || { kisa: 'Ödül', renk: 'gri', degerli: false };
      return `<div class="ck-basamak${o.aktif ? '' : ' pasif'}">
        <span class="ck-esik">⭐ ${o.puan_esigi} Puan</span>
        <h4>${esc(o.baslik)}</h4>
        <p>${esc(o.aciklama || '—')}</p>
        <div class="ck-basamak-tip"><span class="rc-rozet rc-rozet-${t.renk}">${esc(t.kisa)}</span>${t.degerli && o.deger ? `<b>%${Number(o.deger)}</b>` : ''}<small>Sıra: ${o.sira}</small></div>
        <div class="ck-basamak-is">
          <button type="button" class="rc-islem" data-duzenle="${o.id}"><i class="bi bi-pencil"></i> Düzenle</button>
          <button type="button" class="rc-islem ck-sil" data-sil="${o.id}"><i class="bi bi-trash"></i> Sil</button>
        </div></div>`;
    }).join('')}</div>`
      : `<div class="ck-bos"><div class="ck-bos-ikon">🎖️</div>Henüz puan ödülü tanımlanmamış. "Yeni Ödül Ekle" ile başlayın.
        <button type="button" class="rc-btn rc-btn-mavi" id="poOrnek"><i class="bi bi-list-check"></i> Örnekleri Yükle (6 adet)</button></div>`;
  };
  listeCiz();

  $('#poEkle').onclick = () => odulFormu(null);
  $('#poListe').addEventListener('click', async (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.id === 'poOrnek') {
      return kilitle(b, 'Yükleniyor…', async () => {
        const s = await ctx.yaz('POST', '/sadakat/puan-odulu/ornekler');
        if (yazSonuc(s, s.govde?.mesaj)) { oduller = s.govde.veri.odul_seviyeleri; listeCiz(); }
      });
    }
    const o = oduller.find((x) => x.id === Number(b.dataset.duzenle || b.dataset.sil));
    if (!o) return;
    if (b.dataset.duzenle) return odulFormu(o);
    if (!(await onay(`"${o.baslik}" ödülünü silmek istediğinize emin misiniz?`, { tehlike: true }))) return;
    const s = await ctx.yaz('DELETE', `/sadakat/puan-odulu/${o.id}`);
    if (yazSonuc(s, 'Silindi')) { oduller = s.govde.veri.odul_seviyeleri; listeCiz(); }
  });

  function odulFormu(o) {
    const tipSec = v.sozluk.odul_tipleri.map((t) => `<option value="${t.kod}" ${(o?.tip || 'hizmet_indirimi') === t.kod ? 'selected' : ''}>${esc(t.ad)}</option>`).join('');
    const ov = modalAc(o ? 'Puan Ödülü Düzenle' : 'Yeni Puan Ödülü', `
      ${alan('Başlık *', `<input id="pmBaslik" maxlength="150" placeholder="Örn: %20 Hizmet İndirimi" value="${esc(o?.baslik || '')}">`)}
      ${alan('Açıklama', `<textarea id="pmAciklama" maxlength="300" rows="2" placeholder="Müşterinin göreceği kısa açıklama">${esc(o?.aciklama || '')}</textarea>`)}
      <div class="rc-form-izgara">
        ${alan('Gerekli Puan *', `<input id="pmPuan" type="number" min="1" value="${o?.puan_esigi ?? 100}">`)}
        ${alan('Sıra', `<input id="pmSira" type="number" min="0" value="${o?.sira ?? 0}">`)}
        ${alan('Tip', `<select id="pmTip">${tipSec}</select>`)}
        ${alan('Değer (%)', `<input id="pmDeger" type="number" min="0" step="0.1" placeholder="örn: 20" value="${o?.deger ?? ''}">`, 'pm-deger')}
      </div>
      <label class="ck-satir"><span>Aktif (müşteriler görebilsin)</span><span class="rc-switch"><input type="checkbox" id="pmAktif" ${!o || o.aktif ? 'checked' : ''}><span class="rc-kaydir"></span></span></label>
      <div id="pmSubeler"></div>`,
    '<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-birincil" id="pmKaydet"><i class="bi bi-save"></i> Kaydet</button>',
    { ikon: 'star', sabit: true });
    // Düzenlemede şube grubu korunur (eski davranış) → seçici yalnız yeni ödülde.
    const subeler = o ? subeSecici(null) : subeSecici(el('#pmSubeler', ov), ctx.acilis.subeler);
    const tipDegisti = () => { const t = tipler[el('#pmTip', ov).value]; el('.pm-deger', ov).style.display = t?.degerli ? '' : 'none'; };
    el('#pmTip', ov).onchange = tipDegisti;
    tipDegisti();
    el('[data-kapat]', ov).onclick = modalKapat;
    el('#pmKaydet', ov).onclick = (e) => {
      const tip = el('#pmTip', ov).value;
      const govde = {
        baslik: el('#pmBaslik', ov).value.trim(), aciklama: el('#pmAciklama', ov).value.trim(),
        puan_esigi: parseInt(el('#pmPuan', ov).value, 10) || 0, sira: parseInt(el('#pmSira', ov).value, 10) || 0,
        tip, deger: tipler[tip]?.degerli ? sayiVeyaNull(el('#pmDeger', ov).value) : null, aktif: el('#pmAktif', ov).checked,
        salon_ids: subeler.secili(),
      };
      if (!govde.baslik || govde.puan_esigi < 1) return mesaj('Başlık ve puan eşiği zorunlu.', 'hata');
      kilitle(e.currentTarget, 'Kaydediliyor…', async () => {
        const s = o ? await ctx.yaz('PATCH', `/sadakat/puan-odulu/${o.id}`, govde) : await ctx.yaz('POST', '/sadakat/puan-odulu', govde);
        if (yazSonuc(s, 'Kaydedildi')) { oduller = s.govde.veri.odul_seviyeleri; listeCiz(); modalKapat(); }
      });
    };
  }
}
