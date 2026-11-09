/**
 * Çağrı Merkezi › ARAMA RANDEVULARIM (eski /isletmeyonetim/arama-randevularim — ajanda).
 * Gün seçilmezse TÜM bekleyen arama randevuları; gün seçilince o günün bekleyen + tamamlananları.
 * Satırdan: ARA (softphone / Bria), Ertele, "Aç" (ortak görüşme kokpiti modalda).
 * Veri: GET /cagri-merkezi/ajanda?tarih=, /kayit/{id}; POST /kayit/{id}/ertele.
 */
import { el, esc, mesaj, yazSonuc } from '/js/lib/v2-ortak.js?v=7';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';
import { kilitle } from '/js/lib/v2-bilesen.js?v=7';
import { alan, tarihBagla } from '/js/lib/v2-ongorusme.js?v=7';
import { gorusmeKokpiti, aramaBaslat, harf } from '/js/cagri-merkezi/ortak.js?v=7';

const DURUM = { bekliyor: ['Bekliyor', 'mavi'], gecikti: ['Gecikti', 'kirmizi'], zamaninda: ['Zamanında arandı', 'yesil'], gec: ['Geç arandı', 'turuncu'] };

export async function ciz(kap, ctx) {
  const d = { tarih: '', satirlar: [] };
  kap.innerHTML = `
    <div class="cm-panel">
      <div class="cm-takvim-ust">
        <input type="text" id="ajGun" placeholder="Gün seçin (boş: tüm bekleyenler)" readonly style="width:230px">
        <button type="button" class="cm-nav" id="ajTumu">Tüm bekleyenler</button>
        <span class="cm-gun-baslik" id="ajBaslik">Bekleyen arama randevuları</span>
        <span class="cm-sayi" id="ajSay"></span>
      </div>
      <div class="cm-ajanda" id="ajListe"><div class="cm-bos"><span class="rc-yukleniyor"></span></div></div>
    </div>`;
  const $ = (s) => kap.querySelector(s);
  const fp = window.flatpickr ? window.flatpickr($('#ajGun'), { locale: 'tr', dateFormat: 'Y-m-d', altInput: true, altFormat: 'd.m.Y', disableMobile: true, onChange: (_, s) => { d.tarih = s; yukle(); } }) : null;
  $('#ajTumu').onclick = () => { d.tarih = ''; fp?.clear(); yukle(); };

  $('#ajListe').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-is]');
    const r = b && d.satirlar.find((x) => String(x.id) === b.dataset.id);
    if (!r) return;
    if (b.dataset.is === 'ara') return kilitle(b, 'Bağlanıyor…', async () => { if (await aramaBaslat(ctx, `/kayit/${r.id}/ara`)) yukle(); });
    if (b.dataset.is === 'ertele') return ertele(r);
    const t = await ctx.oku(`/kayit/${r.id}`);
    if (!t.ok || !t.govde?.basarili) return mesaj(t.govde?.mesaj || 'Kayıt açılamadı', 'hata');
    const ov = modalAc(r.ad, '<div id="ajKokpit"></div>', '', { ikon: 'alarm', altbaslik: `${r.tarih} ${r.saat}`, genis: true });
    gorusmeKokpiti(el('#ajKokpit', ov), ctx, t.govde.veri, { listeBaslik: t.govde.veri.liste_baslik, takvim: { islenmis: r.durum === 'zamaninda' || r.durum === 'gec' }, degisti: () => yukle() });
  });

  async function yukle() {
    $('#ajBaslik').textContent = d.tarih ? `${d.tarih.split('-').reverse().join('.')} arama randevuları` : 'Bekleyen arama randevuları';
    const r = await ctx.oku('/ajanda', { tarih: d.tarih });
    if (!kap.isConnected) return;
    if (!r.ok || !r.govde?.basarili) { $('#ajListe').innerHTML = `<div class="cm-bos cm-hata">${esc(r.govde?.mesaj || 'Yüklenemedi')}</div>`; return; }
    d.satirlar = r.govde.veri.randevular;
    $('#ajSay').textContent = `${d.satirlar.length} arama`;
    $('#ajListe').innerHTML = d.satirlar.length ? d.satirlar.map((x) => {
      const [et, renk] = DURUM[x.durum] || DURUM.bekliyor;
      const bekliyor = x.durum === 'bekliyor' || x.durum === 'gecikti';
      return `<div class="cm-aj cm-k-${renk}">
        <span class="cm-avatar">${harf(x.ad)}</span>
        <div class="cm-aj-orta"><b>${esc(x.ad)}</b>
          <small><i class="bi bi-clock"></i> ${esc(x.tarih)} ${esc(x.saat)} <span class="rc-rozet rc-rozet-${renk}">${et}</span>
          ${x.ses_sayisi ? `<span class="cm-cip"><i class="bi bi-soundwave"></i> ${x.ses_sayisi} kayıt · ${esc(x.ses_son)}</span>` : ''}</small>
          ${x.not ? `<div class="cm-gecmis-not">${esc(x.not)}</div>` : ''}</div>
        <div class="cm-aj-is">
          ${bekliyor && ctx.acilis.erisim.dahili_var ? `<button type="button" class="rc-btn rc-btn-yesil" data-is="ara" data-id="${x.id}"><i class="bi bi-telephone-fill"></i> Ara</button>` : ''}
          ${bekliyor ? `<button type="button" class="rc-btn rc-btn-ikincil" data-is="ertele" data-id="${x.id}"><i class="bi bi-clock-history"></i> Ertele</button>` : ''}
          <button type="button" class="rc-btn rc-btn-ikincil" data-is="ac" data-id="${x.id}"><i class="bi bi-box-arrow-up-right"></i> Aç</button>
        </div></div>`;
    }).join('') : `<div class="cm-bos"><i class="bi bi-calendar-check"></i>${d.tarih ? 'Bu gün için arama randevusu yok.' : 'Bekleyen arama randevunuz yok.'}</div>`;
  }

  function ertele(r) {
    const ov = modalAc('Arama Randevusunu Ertele', `<div class="rc-form-izgara">${alan('Yeni tarih *', '<input id="erT" readonly>')}${alan('Saat *', `<input id="erS" type="time" value="${esc(r.saat)}">`)}</div>`,
      '<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-birincil" id="erKaydet"><i class="bi bi-check-lg"></i> Ertele</button>',
      { ikon: 'clock-history', altbaslik: r.ad, dar: true });
    el('[data-kapat]', ov).onclick = modalKapat;
    tarihBagla(el('#erT', ov), { minDate: 'today' });
    el('#erKaydet', ov).onclick = (e) => {
      const tarih = el('#erT', ov).value, saat = el('#erS', ov).value;
      if (!tarih || !saat) return mesaj('Yeni tarih ve saat seçin.', 'hata');
      kilitle(e.currentTarget, 'Kaydediliyor…', async () => {
        if (yazSonuc(await ctx.yaz('POST', `/kayit/${r.id}/ertele`, { tarih, saat }), 'Arama randevusu ertelendi')) { modalKapat(); yukle(); }
      });
    };
  }

  yukle();
}
