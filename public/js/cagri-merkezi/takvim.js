/**
 * Çağrı Merkezi › ARAMA RANDEVULARI (eski /isletmeyonetim/arama-randevu-takvim).
 * Seçilen günün arama randevuları PERSONEL sütunlarında, saat sırasıyla; renk/metin/lejand
 * sunucudan (AramaRandevuDurumu). Karta tıklayınca ortak görüşme kokpiti modalda açılır
 * (ARA + sonuç + ertele/iptal + geçmiş). "Arama Randevusu" ile manuel ekleme.
 * Veri: GET /cagri-merkezi/takvim?bas=&bit= (gün), /kayit/{id}; POST /arama-randevusu.
 */
import { el, esc, tarihStr, mesaj, yazSonuc } from '/js/lib/v2-ortak.js?v=1';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { musteriSecici } from '/js/lib/v2-secici.js?v=2';
import { kilitle } from '/js/lib/v2-bilesen.js?v=2';
import { alan, secenekler, tarihBagla } from '/js/lib/v2-ongorusme.js?v=1';
import { gorusmeKokpiti, harf } from '/js/cagri-merkezi/ortak.js?v=2';

const AYLAR = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
const GUNLER = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
const gunBaslik = (g) => { const t = new Date(g + 'T00:00:00'); return `${t.getDate()} ${AYLAR[t.getMonth()]} ${t.getFullYear()}, ${GUNLER[t.getDay()]}`; };
const kaydir = (g, adim) => { const t = new Date(g + 'T00:00:00'); t.setDate(t.getDate() + adim); return tarihStr(t); };

export async function ciz(kap, ctx, params = {}) {
  const d = { gun: params.tarih || tarihStr(new Date()), olaylar: [], filtre: '', lejand: ctx.acilis.sozluk.takvim };

  kap.innerHTML = `
    <div class="cm-panel">
      <div class="cm-takvim-ust">
        <button type="button" class="cm-nav" data-adim="-1" title="Önceki gün"><i class="bi bi-chevron-left"></i></button>
        <input type="text" id="cmGun" value="${d.gun}" readonly>
        <button type="button" class="cm-nav" data-adim="1" title="Sonraki gün"><i class="bi bi-chevron-right"></i></button>
        <button type="button" class="cm-nav" id="cmBugun">Bugün</button>
        <button type="button" class="rc-btn rc-btn-birincil" id="cmEkle"><i class="bi bi-plus-lg"></i> Arama Randevusu</button>
        <span class="cm-gun-baslik" id="cmGunBaslik"></span>
        <span class="cm-sayi" id="cmGunSay"></span>
      </div>
      <div class="cm-lejand" id="cmLejand"></div>
      <div class="cm-pano" id="cmPano"><div class="cm-bos"><span class="rc-yukleniyor"></span></div></div>
    </div>`;
  const $ = (s) => kap.querySelector(s);
  const fp = window.flatpickr ? window.flatpickr($('#cmGun'), { locale: 'tr', dateFormat: 'Y-m-d', altInput: true, altFormat: 'd.m.Y', disableMobile: true, onChange: (_, s) => gunAyarla(s) }) : null;

  kap.querySelectorAll('[data-adim]').forEach((b) => b.addEventListener('click', () => gunAyarla(kaydir(d.gun, Number(b.dataset.adim)))));
  $('#cmBugun').addEventListener('click', () => gunAyarla(tarihStr(new Date())));
  $('#cmEkle').addEventListener('click', () => aramaRandevusuEkle(ctx, d.gun, yukle));
  $('#cmLejand').addEventListener('click', (e) => {
    const c = e.target.closest('[data-filtre]');
    if (c) { d.filtre = c.dataset.filtre; ciz2(); }
  });
  $('#cmPano').addEventListener('click', (e) => {
    const c = e.target.closest('[data-olay]');
    if (c) olayAc(d.olaylar.find((o) => String(o.id) === c.dataset.olay));
  });

  function gunAyarla(g) {
    if (!g || g === d.gun) return;
    d.gun = g;
    fp ? fp.setDate(g, false) : ($('#cmGun').value = g);
    yukle();
  }

  async function yukle() {
    $('#cmGunBaslik').textContent = gunBaslik(d.gun);
    const r = await ctx.oku('/takvim', { bas: d.gun, bit: d.gun });
    if (!kap.isConnected) return;
    if (!r.ok || !r.govde?.basarili) { $('#cmPano').innerHTML = `<div class="cm-bos cm-hata">${esc(r.govde?.mesaj || 'Takvim yüklenemedi')}</div>`; return; }
    d.olaylar = r.govde.veri.olaylar;
    ciz2();
  }

  function ciz2() {
    const sayim = d.olaylar.reduce((t, o) => ({ ...t, [o.durum_kod]: (t[o.durum_kod] || 0) + 1 }), {});
    $('#cmLejand').innerHTML = [{ kod: '', metin: 'Tümü', renk: '#9a93ad' }, ...d.lejand].map((l) => `
      <button type="button" class="cm-lj${d.filtre === l.kod ? ' aktif' : ''}" data-filtre="${esc(l.kod)}">
        <span class="cm-nokta" style="background:${esc(l.renk)}"></span> ${esc(l.metin)} <b>${l.kod ? (sayim[l.kod] || 0) : d.olaylar.length}</b></button>`).join('');
    const gorunen = d.olaylar.filter((o) => !d.filtre || o.durum_kod === d.filtre);
    $('#cmGunSay').textContent = `${gorunen.length} arama`;
    if (!gorunen.length) { $('#cmPano').innerHTML = '<div class="cm-bos"><i class="bi bi-calendar-x"></i>Bu gün için arama randevusu yok.</div>'; return; }
    const kolon = new Map();
    gorunen.forEach((o) => { if (!kolon.has(o.personel_id)) kolon.set(o.personel_id, { ad: o.personel, olaylar: [] }); kolon.get(o.personel_id).olaylar.push(o); });
    $('#cmPano').innerHTML = [...kolon.values()].map((k) => `
      <div class="cm-kolon">
        <div class="cm-kolon-bas"><span class="cm-avatar">${harf(k.ad)}</span><b>${esc(k.ad)}</b><span class="cm-sayi">${k.olaylar.length}</span></div>
        ${k.olaylar.map((o) => `
          <button type="button" class="cm-olay" data-olay="${o.id}" style="--renk:${esc(o.color)}">
            <span class="cm-olay-saat">${esc(o.saat)}</span>
            <span class="cm-olay-ad">${esc(o.musteri)}</span>
            <span class="cm-olay-durum">${esc(o.durum_metin)}</span>
            ${o.not ? `<span class="cm-olay-not">${esc(o.not)}</span>` : ''}
          </button>`).join('')}
      </div>`).join('');
  }

  async function olayAc(o) {
    if (!o) return;
    const r = await ctx.oku(`/kayit/${o.id}`);
    if (!r.ok || !r.govde?.basarili) return mesaj(r.govde?.mesaj || 'Kayıt açılamadı', 'hata');
    const ov = modalAc(o.musteri, '<div id="cmOlayKokpit"></div>', '', { ikon: 'calendar-check', altbaslik: `${o.saat} · ${o.personel} · ${o.durum_metin}`, genis: true });
    gorusmeKokpiti(el('#cmOlayKokpit', ov), ctx, r.govde.veri, { listeBaslik: r.govde.veri.liste_baslik, takvim: o, degisti: () => yukle() });
  }

  yukle();
}

/** Manuel arama randevusu (eski art_ekle_modal) — personel yalnız kendine ekler (sunucu da denetler). */
function aramaRandevusuEkle(ctx, gun, yenile) {
  const pers = ctx.acilis.personeller;
  const benim = ctx.acilis.erisim.personel_id;
  const ov = modalAc('Arama Randevusu Ekle', `
    <div class="rc-form-izgara">
      ${alan('Müşteri *', '<div id="aMusteri"></div>', 'rc-tam')}
      ${alan('Arayacak personel *', `<select id="aPersonel">${secenekler(pers, benim, pers.length > 1 ? 'Personel seçin…' : null)}</select>`)}
      ${alan('Tarih *', `<input id="aTarih" value="${esc(gun)}" readonly>`)}
      ${alan('Saat *', '<input id="aSaat" type="time" value="10:00">')}
      ${alan('Not <small>(opsiyonel)</small>', '<textarea id="aNot" rows="2" placeholder="Görüşme notu / hatırlatma…"></textarea>', 'rc-tam')}
    </div>`,
  '<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-birincil" id="aKaydet"><i class="bi bi-check-lg"></i> Arama Randevusu Ekle</button>',
  { ikon: 'calendar-plus', sabit: true });
  const $ = (s) => el(s, ov);
  $('[data-kapat]').onclick = modalKapat;
  tarihBagla($('#aTarih'), { minDate: 'today' });
  const secici = musteriSecici($('#aMusteri'), { salonId: ctx.salonId, yol: '/cagri-merkezi/musteri-ara' });
  $('#aKaydet').onclick = (e) => {
    const musteri = secici.secili();
    const v = { musteri_id: musteri?.id, personel_id: Number($('#aPersonel').value) || null, tarih: $('#aTarih').value, saat: $('#aSaat').value, not: $('#aNot').value.trim() || null };
    if (!v.musteri_id || !v.personel_id || !v.tarih || !v.saat) return mesaj('Müşteri, personel, tarih ve saat zorunludur.', 'hata');
    kilitle(e.currentTarget, 'Kaydediliyor…', async () => {
      const r = await ctx.yaz('POST', '/arama-randevusu', v);
      if (yazSonuc(r, r.govde?.mesaj)) { modalKapat(); yenile(); }
    });
  };
}
