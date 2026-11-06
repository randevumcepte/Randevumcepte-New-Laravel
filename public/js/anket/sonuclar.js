/**
 * Sonuçlar & İstatistik sekmesi: Reputation Booster kartı, dönem filtresi, özet kartları, NPS dağılımı,
 * cevap tablosu, cevap detayı, "sitede göster/gizle". Hesap yok — tüm değerler /anket/ozet + /anket/gonderimler.
 */
import { el, esc, mesaj, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=2';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=1';
import { modalAc } from '/js/lib/v2-modal.js?v=1';
import { ozetKart } from '/js/lib/v2-bilesen.js?v=2';
import { donemSecici, sayi } from '/js/lib/v2-donem.js?v=1';
import { secenekler } from '/js/lib/v2-ongorusme.js?v=1';
import { googleAyarAc } from '/js/anket/google-ayar.js?v=1';

const BOS = '<span class="an-bos">—</span>';
const zaman = (s) => {
  const m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2})/.exec(String(s || ''));
  return m ? `${m[3]}.${m[2]}.${m[1]} ${m[4]}` : '—';
};
const npsRozet = (n, sinif) => (n === null || n === undefined ? BOS : `<span class="an-nps an-nps-${esc(sinif)}">${esc(n)}</span>`);
const yildizlar = (p) => '★★★★★☆☆☆☆☆'.slice(5 - Math.min(5, Math.floor(p)), 10 - Math.min(5, Math.floor(p)));
const csatHucre = (c) => (c === null || c === undefined ? BOS : `<span class="an-yildiz">${yildizlar(c)}</span><small class="an-soluk">${esc(c)}</small>`);
const kisalt = (s, n) => { const t = String(s || ''); return t.length > n ? `${t.slice(0, n)}…` : t; };
const yorumHucre = (y, n) => (y ? `<div class="an-yorum">${esc(kisalt(y, n))}</div>` : BOS);
const musteriHucre = (g) => `<b>${esc(g.ad_soyad || '—')}</b><div class="an-soluk">${esc(g.telefon || '')}</div>`;

export async function ciz(kap, ctx) {
  let filtre = 'tum';
  kap.innerHTML = `
    <div id="anRep"></div>
    <div class="an-filtre"><div id="anDonem"></div></div>
    <div class="rc-ozet" id="anKartlar"></div>
    <div id="anNps"></div>
    <div class="an-kart an-tablo-kart">
      <div class="an-tablo-bas">
        <h3><i class="bi bi-list-ul"></i> Anket Cevapları</h3>
        <label class="rc-alan an-filtre-sec"><select id="anFiltre" data-arama="yok">${secenekler(ctx.fv.filtreler, filtre, null, 'kod')}</select></label>
      </div>
      <div id="anTablo"></div>
    </div>`;

  const donem = donemSecici(el('#anDonem', kap), {
    donemler: ctx.fv.donemler, varsayilan: ctx.fv.varsayilan_donem, onDegis: () => yukle(),
  });

  const tablo = veriTablosu(el('#anTablo', kap), {
    anahtar: 'anket_gonderimleri', birim: 'kayıt', araIpucu: 'Müşteri, telefon, yorum…',
    bosMesaj: 'Bu tarih aralığında anket gönderimi bulunmuyor.',
    sirala: { alan: 'id', yon: 'desc' },
    kolonlar: [
      { baslik: 'Müşteri', sirala: 'ad_soyad' }, { baslik: 'Gönderim', sirala: 'gonderim_zamani' },
      { baslik: 'NPS', sirala: 'nps_skoru', sinif: 'an-orta' }, { baslik: 'Memnuniyet', sirala: 'csat_skoru', sinif: 'an-orta' },
      { baslik: 'Yorum' }, { baslik: 'Durum', sinif: 'an-orta' }, { baslik: '', sinif: 'an-sag' },
    ],
    kaynak: (p) => ctx.oku('/gonderimler', { ...p, ...donem.params(), filtre }),
    satir: (g) => `<tr>
      <td>${musteriHucre(g)}</td>
      <td>${zaman(g.gonderim_zamani)}<div class="an-soluk">${esc(g.gonderim_kanali || '')}</div></td>
      <td class="an-orta">${npsRozet(g.nps_skoru, g.nps_sinifi)}</td>
      <td class="an-orta">${csatHucre(g.csat_skoru)}</td>
      <td>${yorumHucre(g.genel_yorum, 100)}</td>
      <td class="an-orta"><span class="rc-rozet rc-rozet-${esc(g.durum.renk)}">${esc(g.durum.etiket)}</span></td>
      <td class="an-sag"><span class="rc-islemler">
        ${g.webe_uygun && ctx.yazabilir ? webButon(g) : ''}
        ${g.cevaplandi ? `<button class="rc-islem" data-detay="${g.id}"><i class="bi bi-eye"></i> Detay</button>` : ''}
      </span></td>
    </tr>`,
  });

  el('#anFiltre', kap).addEventListener('change', (e) => { filtre = e.target.value; tablo.yenile(); });
  el('#anTablo', kap).addEventListener('click', async (e) => {
    const d = e.target.closest('[data-detay]');
    if (d) { detayAc(ctx, d.dataset.detay); return; }
    const w = e.target.closest('[data-web]');
    if (!w) return;
    w.disabled = true;
    const r = await ctx.yaz('PATCH', `/gonderimler/${w.dataset.web}/web-gizle`, { gizle: w.dataset.gizli !== '1' });
    if (yazSonuc(r)) w.outerHTML = webButon(r.govde.veri);
    else w.disabled = false;
  });

  async function yukle() {
    const r = await ctx.oku('/ozet', donem.params());
    if (!r.ok || !r.govde?.basarili) { mesaj(r.govde?.mesaj || `Hata (${r.kod})`, 'hata'); return; }
    const o = r.govde.veri;
    donem.goster(o.donem);
    ctx.rozet('sonuclar', o.toplamGonderim);
    repCiz(o);
    el('#anKartlar', kap).innerHTML =
      ozetKart('bi-send', 'Gönderim (SMS / WhatsApp)', sayi(o.toplamGonderim), 'mavi')
      + ozetKart('bi-reply', `Cevaplanan (%${o.cevapOrani})`, sayi(o.toplamCevap), 'yesil')
      + ozetKart('bi-graph-up', 'NPS Skoru', o.npsSkor ?? '—', 'mor')
      + ozetKart('bi-star', 'Memnuniyet (5 üzerinden)', o.ortCsat ?? '—', 'turuncu');
    npsCiz(o);
    tablo.yenile();
  }

  function repCiz(o) {
    const aktif = o.reputationPremiumAktif;
    el('#anRep', kap).innerHTML = `
      <div class="an-kart an-rep">
        <div class="an-rep-ust">
          <span class="an-rep-ikon"><i class="bi bi-star-fill"></i></span>
          <div class="an-rep-metin">
            <h3>Reputation Booster <span class="rc-rozet rc-rozet-${aktif ? 'yesil' : 'gri'}">${aktif ? 'Premium Aktif' : 'Pasif'}</span></h3>
            <p>Yüksek puan veren müşterileri otomatik <b>Google Review'a yönlendir</b>, düşük puanları içeride yakala (anlık uyarı).</p>
          </div>
          ${!ctx.yazabilir ? '' : aktif
            ? '<button class="rc-btn rc-btn-ikincil" id="anAyarAc"><i class="bi bi-gear"></i> Ayarla</button>'
            : '<button class="rc-btn rc-btn-mavi" id="anPremium"><i class="bi bi-rocket-takeoff"></i> Premium Aç</button>'}
        </div>
        ${aktif ? `<div class="an-rep-izgara">
          <div class="an-mini"><span>Google Bağlantı</span><b class="${o.googleUrlVar ? 'an-yesil' : 'an-kirmizi'}">${o.googleUrlVar ? 'Kurulu' : 'Eksik'}</b></div>
          <button class="an-mini an-mini-tik" data-rep="google_tiklayan"><span>Google'a Yönlendirilen <i class="bi bi-chevron-right"></i></span><b class="an-mavi">${sayi(o.googleTiklamalar)}</b></button>
          <button class="an-mini an-mini-tik" data-rep="kotu_puan"><span>Düşük Puan Uyarısı <i class="bi bi-chevron-right"></i></span><b class="an-kirmizi">${sayi(o.kotuPuanUyarilari)}</b></button>
          <div class="an-mini"><span>Uyarı Telefonu</span><b class="${o.uyariTelefon ? 'an-yesil' : 'an-kirmizi'}">${esc(o.uyariTelefon || 'Tanımsız')}</b></div>
        </div>` : ''}
      </div>`;
    el('#anAyarAc', kap)?.addEventListener('click', () => googleAyarAc(ctx, yukle));
    el('#anPremium', kap)?.addEventListener('click', async () => {
      if (!await onay('Premium özellikler bu salon için aktif edilsin mi? (Google Review yönlendirme + düşük puan uyarısı)')) return;
      if (yazSonuc(await ctx.yaz('POST', '/ayarlar/premium'), 'Premium aktif edildi')) yukle();
    });
    kap.querySelectorAll('[data-rep]').forEach((b) => b.addEventListener('click', () => kisiListesiAc(ctx, b.dataset.rep, donem.params())));
  }

  function npsCiz(o) {
    const top = o.promoter + o.passive + o.detractor;
    if (!top) { el('#anNps', kap).innerHTML = ''; return; }
    const parca = (n, sinif) => (n ? `<div class="an-nps-${sinif}" style="width:${(n * 100) / top}%">${Math.round((n * 100) / top)}%</div>` : '');
    el('#anNps', kap).innerHTML = `
      <div class="an-kart">
        <div class="an-kucuk-baslik">NPS Dağılımı</div><div class="an-soluk">Toplam ${sayi(top)} NPS cevabı</div>
        <div class="an-nps-bar">${parca(o.detractor, 'detractor')}${parca(o.passive, 'pasif')}${parca(o.promoter, 'promoter')}</div>
        <div class="an-lejant">
          <span><i class="an-nps-detractor"></i> Detraktör (0-6): <b>${sayi(o.detractor)}</b></span>
          <span><i class="an-nps-pasif"></i> Pasif (7-8): <b>${sayi(o.passive)}</b></span>
          <span><i class="an-nps-promoter"></i> Tavsiyeci (9-10): <b>${sayi(o.promoter)}</b></span>
        </div>
      </div>`;
  }

  yukle();
}

function webButon(g) {
  const gizli = g.web_gizle;
  return `<button class="rc-islem ${gizli ? '' : 'rc-olumlu'}" data-web="${g.id}" data-gizli="${gizli ? 1 : 0}" title="Bu olumlu yorumu salon web sitesinde göster/gizle">
    <i class="bi bi-${gizli ? 'eye-slash' : 'globe'}"></i> ${gizli ? 'Sitede Gizli' : 'Sitede'}</button>`;
}

/** Reputation kartındaki kişi listeleri: aynı /gonderimler ucu, sunucu filtresiyle. */
function kisiListesiAc(ctx, filtre, donemParams) {
  const google = filtre === 'google_tiklayan';
  const ov = modalAc(google ? "Google'a Yönlendirilen Müşteriler" : 'Düşük Puan Veren Müşteriler',
    `<div class="an-bilgi ${google ? 'an-bilgi-yesil' : 'an-bilgi-kirmizi'}">${google
      ? "Bu müşteriler Google'a yorum bırakmak için tıkladı. Teşekkür mesajı atabilirsiniz."
      : 'Bu müşteriler düşük puan verdi. Özür dileyip durumu telafi edebilirsiniz.'}</div><div id="anKisiTablo"></div>`,
    '', { ikon: google ? 'hand-thumbs-up' : 'exclamation-triangle', genis: true });
  veriTablosu(el('#anKisiTablo', ov), {
    anahtar: 'anket_kisi', birim: 'kişi', bosMesaj: 'Bu kategoride kayıt yok.', araIpucu: 'Müşteri, telefon, yorum…',
    sirala: { alan: 'cevap_zamani', yon: 'desc' },
    kolonlar: [{ baslik: 'Müşteri', sirala: 'ad_soyad' }, { baslik: 'NPS', sirala: 'nps_skoru', sinif: 'an-orta' }, { baslik: 'CSAT', sirala: 'csat_skoru', sinif: 'an-orta' }, { baslik: 'Yorum' }, { baslik: 'Tarih', sirala: 'cevap_zamani', sinif: 'an-sag' }],
    kaynak: (p) => ctx.oku('/gonderimler', { ...p, ...donemParams, filtre }),
    satir: (g) => `<tr><td>${musteriHucre(g)}</td><td class="an-orta">${npsRozet(g.nps_skoru, g.nps_sinifi)}</td>
      <td class="an-orta">${g.csat_skoru === null ? BOS : `<span class="an-yildiz">★ ${esc(Number(g.csat_skoru).toFixed(1))}</span>`}</td>
      <td>${yorumHucre(g.genel_yorum, 80)}</td><td class="an-sag an-soluk">${zaman(g.cevap_zamani)}</td></tr>`,
  }).yenile();
}

async function detayAc(ctx, id) {
  const ov = modalAc('Anket Cevap Detayı', '<div class="rc-sekme-yuk"><span class="rc-yukleniyor"></span></div>', '', { ikon: 'chat-dots' });
  const r = await ctx.oku(`/gonderimler/${id}`);
  const govde = el('.rc-modal-govde', ov);
  if (!r.ok || !r.govde?.basarili) { govde.innerHTML = `<div class="rc-sekme-bos">${esc(r.govde?.mesaj || 'Detay bulunamadı.')}</div>`; return; }
  const d = r.govde.veri;
  const yapi = ctx.fv.soru_katalogu.tipler.filter((t) => !t.cevapli).map((t) => t.tip);
  const cevapMap = Object.fromEntries((d.cevaplar || []).map((c) => [c.indeks, c.cevap]));
  govde.innerHTML = `
    <div class="an-bilgi an-bilgi-mor"><b>${esc(d.musteri_ad || '')}</b><div class="an-soluk">Şablon: ${esc(d.sablon_ad)}</div></div>
    ${(d.sorular || []).map((s, i) => (yapi.includes(s.tip) ? '' : `
      <div class="an-cevap"><div class="an-cevap-soru">${i + 1}. ${esc(s.soru)}</div><div>${cevapHtml(s.tip, cevapMap[i])}</div></div>`)).join('')}`;
}

function cevapHtml(tip, c) {
  if (c === undefined || c === null || c === '' || (Array.isArray(c) && !c.length)) return BOS;
  if (tip === 'csat_yildiz') { const n = parseInt(c, 10) || 0; return `<span class="an-yildiz an-buyuk">${yildizlar(n)}</span> <small class="an-soluk">(${n}/5)</small>`; }
  if (tip === 'nps') { const n = parseInt(c, 10); return `${npsRozet(n, n >= 9 ? 'promoter' : (n >= 7 ? 'pasif' : 'detractor'))} <small class="an-soluk">/ 10</small>`; }
  if (tip === 'evet_hayir') return c === 'evet' ? '<b class="an-yesil">✓ Evet</b>' : (c === 'hayir' ? '<b class="an-kirmizi">✗ Hayır</b>' : BOS);
  return esc(Array.isArray(c) ? c.join(', ') : c);
}
