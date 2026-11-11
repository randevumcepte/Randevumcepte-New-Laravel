/**
 * v2 GRUP DERSİ ORTAK KATMANI — TEK KAYNAK (stil: css/ders-v2.css; bu modül kendisi yükler).
 * Takvim, Ders Programı, Satış, Paket, Ön Görüşme ve Müşteri Detay aynı modalları kullanır.
 * Import yolu her yerde AYNI olmalı:
 *   import { dersOturumAc, dersOturumYeni, dersFormu, dersPlani } from '/js/lib/v2-ders.js?v=7';
 *
 * Veri ve kurallar /api/v2/ders/* uçlarından (Flutter aynı uçları kullanır): durum sözlüğü,
 * kapasite → bekleme, telafi bağlama, seans düşümü burada HESAPLANMAZ.
 */
import { api, el, esc, mesaj, onay, tarihStr, yazSonuc } from '/js/lib/v2-ortak.js?v=7';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';
import { musteriSecici } from '/js/lib/v2-secici.js?v=7';
import { kilitle } from '/js/lib/v2-bilesen.js?v=7';
import { alan, secenekler, tarihBagla } from '/js/lib/v2-ongorusme.js?v=9';

if (!document.querySelector('link[href^="/css/ders-v2.css"]')) {
  document.head.insertAdjacentHTML('beforeend', '<link rel="stylesheet" href="/css/ders-v2.css?v=1">');
}

export const GUNLER = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar']
  .map((ad, i) => ({ id: i + 1, ad, kisa: ad.slice(0, 3) }));

export const programGetir = (salonId) => api('GET', '/ders/program', { params: { salon_id: salonId } });

const oge = (x) => (x.ok && x.govde?.basarili ? x.govde.veri : null);

// ─────────────────────────── Ders formu (oturum + şablon ortak) ───────────────────────────

/** Hizmet ↔ eğitmen iki yönlü filtre (personel_sunulan_hizmetler). */
function capraz(eslesme, kaynak, hedef) {
  const m = {};
  eslesme.forEach((e) => { (m[e[kaynak]] ||= []).push(String(e[hedef])); });
  return m;
}
function filtreBagla(ov, fv) {
  const pSec = el('#dfPersonel', ov), hSec = el('#dfHizmet', ov);
  const h2p = capraz(fv.eslesme, 'hizmet_id', 'personel_id'), p2h = capraz(fv.eslesme, 'personel_id', 'hizmet_id');
  const ciz = (sel, liste, adAlan, izinli, bos) => {
    const mevcut = sel.value;
    const gorunen = izinli?.length ? liste.filter((x) => izinli.includes(String(x.id))) : liste;
    sel.innerHTML = secenekler(gorunen, gorunen.some((x) => String(x.id) === mevcut) ? mevcut : '', bos, 'id', adAlan);
  };
  const uygula = () => {
    const p = pSec.value, h = hSec.value;
    ciz(pSec, fv.personeller, 'personel_adi', h2p[h], 'Seçiniz');
    ciz(hSec, fv.hizmetler, 'hizmet_adi', p2h[p], 'Hizmet bağlama (paket düşmez)');
  };
  pSec.addEventListener('change', uygula);
  hSec.addEventListener('change', uygula);
  uygula();
}

/**
 * @param {{salonId:number, fv:object, baslik:string, kayit?:object, tur:'oturum'|'sablon',
 *          kaydet:(govde:object)=>Promise, onKaydet?:Function}} o
 */
export function dersFormu({ salonId, fv, baslik, kayit: k = {}, tur, kaydet, onKaydet = () => {} }) {
  const zaman = tur === 'sablon'
    ? alan('Gün', `<select id="dfGun" data-arama="yok">${secenekler(GUNLER, k.hafta_gunu ?? 1)}</select>`, 'rc-tam')
    : alan('Tarih', `<input id="dfTarih" value="${esc(k.tarih || tarihStr(new Date()))}" autocomplete="off">`, 'rc-tam');
  const ov = modalAc(baslik, `
    <form id="dfForm" class="rc-form-izgara" autocomplete="off">
      ${alan('Ders Adı', `<input id="dfAd" maxlength="255" placeholder="Reformer, Mat…" value="${esc(k.ders_tipi || '')}">`, 'rc-tam')}
      ${alan('Eğitmen', `<select id="dfPersonel">${secenekler(fv.personeller, k.personel_id, 'Seçiniz', 'id', 'personel_adi')}</select>`, 'rc-tam')}
      ${alan('Hizmet <small>(paketten seans düşümü için)</small>', `<select id="dfHizmet">${secenekler(fv.hizmetler, k.hizmet_id, 'Hizmet bağlama (paket düşmez)', 'id', 'hizmet_adi')}</select>`, 'rc-tam')}
      ${zaman}
      ${alan('Başlangıç', `<input id="dfSaat" type="time" required value="${esc(k.saat || '09:00')}">`)}
      ${alan('Bitiş', `<input id="dfBitis" type="time" required value="${esc(k.saat_bitis || '10:00')}">`)}
      ${alan('Kapasite (kişi)', `<input id="dfKapasite" type="number" min="1" required value="${esc(k.kapasite ?? 3)}">`)}
    </form>`,
    '<button class="rc-btn rc-btn-ikincil" id="dfVazgec" type="button">Vazgeç</button><button class="rc-btn rc-btn-yesil" id="dfKaydet" type="button"><i class="bi bi-check-lg"></i> Kaydet</button>',
    { ikon: 'people', sabit: true });

  if (tur === 'oturum') tarihBagla(el('#dfTarih', ov));
  filtreBagla(ov, fv);
  el('#dfVazgec', ov).onclick = modalKapat;
  el('#dfKaydet', ov).onclick = (e) => kilitle(e.currentTarget, 'Kaydediliyor…', async () => {
    const g = {
      salon_id: salonId, ders_tipi: el('#dfAd', ov).value.trim() || null,
      personel_id: el('#dfPersonel', ov).value || null, hizmet_id: el('#dfHizmet', ov).value || null,
      saat: el('#dfSaat', ov).value, saat_bitis: el('#dfBitis', ov).value, kapasite: Number(el('#dfKapasite', ov).value),
    };
    if (tur === 'sablon') g.hafta_gunu = Number(el('#dfGun', ov).value);
    else g.tarih = el('#dfTarih', ov).value;
    const r = await kaydet(g);
    if (yazSonuc(r, r.govde?.mesaj)) onKaydet(r.govde.veri);
  });
}

// ─────────────────────────── Oturum + katılımcılar ───────────────────────────

const DUSUM_MESAJI = {
  hak_yok: ['Paket hakkı yok: katılım kaydedildi, seans düşülmedi.', 'hata'],
  dusuldu: ['Paketten 1 seans düşüldü.', 'ok'],
  telafi_korundu: ['Telafi hakkı korundu; yeni derse bağlanabilir.', 'bilgi'],
};

/** Yeni ders: tarih varsayılanı takvimin günü. */
export async function dersOturumYeni({ salonId, tarih = null, onDegisti = () => {} }) {
  const fv = oge(await programGetir(salonId));
  if (!fv) { mesaj('Ders bilgileri yüklenemedi.', 'hata'); return; }
  dersFormu({
    salonId, fv, tur: 'oturum', baslik: 'Yeni Grup Dersi', kayit: { tarih },
    kaydet: (g) => api('POST', '/ders/oturum', { body: g }),
    onKaydet: (v) => { onDegisti(); dersOturumAc(v.oturum_id, { salonId, onDegisti }); },
  });
}

export async function dersOturumAc(id, { salonId, onDegisti = () => {} }) {
  const r = await api('GET', `/ders/oturum/${id}`);
  if (!oge(r)) { mesaj(r.govde?.mesaj || `Ders yüklenemedi (${r.kod})`, 'hata'); return; }
  const { oturum: o, katilimcilar, durumlar } = r.govde.veri;
  const tekrar = () => dersOturumAc(id, { salonId, onDegisti });
  const degisti = () => { onDegisti(); tekrar(); };
  const rozet = o.doluluk >= o.kapasite ? 'kirmizi' : (o.doluluk > 0 ? 'turuncu' : 'yesil');
  const isaretler = durumlar.filter((d) => d.isaretlenebilir);

  const kisi = (k) => {
    const etiket = k.durum === 'telafi' ? '<span class="rc-rozet rc-rozet-turuncu">telafi</span>'
      : (k.hak_dusuldu ? '<span class="rc-rozet rc-rozet-mor">paket -1</span>' : '');
    const dugmeler = k.durum === 'bekleme' ? '<span class="rc-rozet rc-rozet-turuncu">Beklemede</span>'
      : isaretler.filter((d) => d.kod !== 'telafi' || k.paket_var || k.durum === 'telafi')
        .map((d) => `<button type="button" class="do-durum ${esc(d.kod)}${k.durum === d.kod ? ' on' : ''}" data-durum="${esc(d.kod)}" data-id="${k.id}">${esc(d.etiket)}</button>`).join('');
    return `<li class="do-kisi"><span><b>${esc(k.ad)}</b><small>${esc(k.tel || '')}</small> ${etiket}</span>
      <span class="do-durumlar">${dugmeler}<button type="button" class="do-cikar" data-cikar="${k.id}" title="Çıkar" aria-label="Çıkar">&times;</button></span></li>`;
  };

  const ov = modalAc('Ders Katılımcıları', `
    <div class="do-ozet"><div><b>${esc(o.ders_tipi)}</b><small>${esc(o.tarih)} · ${esc(o.saat)}–${esc(o.saat_bitis)}${o.personel ? ' · ' + esc(o.personel) : ''}</small></div>
      <span class="rc-rozet rc-rozet-${rozet}">${o.doluluk} / ${o.kapasite}</span></div>
    <div id="doMusteri"></div>
    <ul class="do-liste">${katilimcilar.length ? katilimcilar.map(kisi).join('') : '<li class="do-bos">Henüz katılımcı yok.</li>'}</ul>`,
    '<button class="rc-btn rc-btn-ikincil" id="doDuzenle" type="button"><i class="bi bi-pencil"></i> Dersi Düzenle</button><button class="rc-btn do-tehlike" id="doIptal" type="button"><i class="bi bi-trash"></i> Dersi İptal Et</button>',
    { ikon: 'people', genis: true });

  musteriSecici(el('#doMusteri', ov), {
    salonId, yol: '/ders/musteri-ara', ipucu: 'Katılımcı ekle: ad veya telefon',
    onSec: async (m) => {
      if (!m) return;
      const y = await api('POST', `/ders/oturum/${id}/katilimci`, { body: { musteri_id: m.id } });
      if (!yazSonuc(y)) { tekrar(); return; }
      if (y.govde.veri.katilimci_durum === 'bekleme') mesaj('Kapasite dolu: kişi bekleme listesine eklendi.', 'bilgi');
      else if (y.govde.veri.telafi_baglandi) mesaj('Bekleyen telafi bu derse bağlandı; yeni seans düşülmedi.', 'ok');
      degisti();
    },
  });

  ov.querySelectorAll('[data-durum]').forEach((b) => {
    b.onclick = async () => {
      const y = await api('PATCH', `/ders/katilimci/${b.dataset.id}/durum`, { body: { yeni_durum: b.dataset.durum } });
      if (!yazSonuc(y)) return;
      const m = DUSUM_MESAJI[y.govde.veri.dusum];
      if (m) mesaj(m[0], m[1]);
      degisti();
    };
  });
  ov.querySelectorAll('[data-cikar]').forEach((b) => {
    b.onclick = async () => { if (yazSonuc(await api('DELETE', `/ders/katilimci/${b.dataset.cikar}`))) degisti(); };
  });
  el('#doDuzenle', ov).onclick = async () => {
    const fv = oge(await programGetir(salonId));
    if (!fv) return;
    dersFormu({
      salonId, fv, tur: 'oturum', baslik: 'Dersi Düzenle', kayit: o,
      kaydet: (g) => api('PATCH', `/ders/oturum/${id}`, { body: g }), onKaydet: degisti,
    });
  };
  el('#doIptal', ov).onclick = async () => {
    if (!await onay('Ders takvimden kalkacak, düşülen seanslar geri verilecek ve katılımcılara bildirim gidecek.', { baslik: 'Dersi iptal et?', tamam: 'İptal Et', iptal: 'Vazgeç' })) return;
    const y = await api('DELETE', `/ders/oturum/${id}`);
    if (yazSonuc(y, y.govde?.mesaj)) { modalKapat(); onDegisti(); }
  };
}

// ─────────────────────────── Otomatik ders planı (stüdyo) ───────────────────────────

const normal = (s) => String(s || '').replace(/\s+/g, ' ').trim().toLocaleLowerCase('tr');

/** Modal kapanınca çözülür (satış sonrası akış yönlendirmeden önce bekler). */
const kapanisi = (ov) => new Promise((coz) => {
  const g = new MutationObserver(() => { if (!ov.isConnected) { g.disconnect(); coz(); } });
  g.observe(document.body, { childList: true });
});

/**
 * @param {{salonId:number, musteriId:number, adlar?:string[], onBitti?:Function}} o  adlar: yalnız satılan paket/hizmetler
 * @returns {Promise<void>} modal kapanınca (kaynak yoksa hemen)
 */
export async function dersPlani({ salonId, musteriId, adlar = [], sessiz = false, onBitti = () => {} }) {
  const r = await api('GET', '/ders/tekrarli/kaynaklar', { params: { salon_id: salonId, musteri_id: musteriId } });
  const v = oge(r);
  if (!v) { if (!sessiz) mesaj(r.govde?.mesaj || 'Ders planı yüklenemedi.', 'hata'); return; }
  const aranan = adlar.map(normal).filter(Boolean);
  const suzulmus = aranan.length ? v.kaynaklar.filter((k) => aranan.some((a) => normal(k.etiket).includes(a))) : [];
  const kaynaklar = suzulmus.length ? suzulmus : v.kaynaklar;
  if (!kaynaklar.length) { if (!sessiz) mesaj('Uygun (kalan seanslı) satış yok.', 'bilgi'); return; }

  const secili = new Set();
  let egitmen = null, gun = null;

  const ov = modalAc('Otomatik Ders Planı', `
    <p class="rc-ipucu">Paketin/hizmetin seanslarını seçtiğiniz sabit ders slotlarına haftalık otomatik dağıtır.</p>
    ${alan('Paket / Hizmet', `<select id="dplKaynak">${kaynaklar.map((k, i) => `<option value="${i}">${esc(k.etiket)} • ${k.sinirsiz ? 'Sınırsız' : `${k.kalan_seans} seans`}</option>`).join('')}</select>`)}
    <div class="dpl-cipler" id="dplEgitmen"></div><div class="dpl-cipler" id="dplGun"></div><div id="dplSlot"></div>`,
    '<span class="dpl-ozet" id="dplOzet"></span><button class="rc-btn rc-btn-ikincil" id="dplVazgec" type="button">Vazgeç</button><button class="rc-btn rc-btn-yesil" id="dplKaydet" type="button">Planla ve Dağıt</button>',
    { ikon: 'arrow-repeat', genis: true, sabit: true });

  const kaynak = () => kaynaklar[Number(el('#dplKaynak', ov).value)];
  const uygun = () => v.sablonlar.filter((s) => String(s.hizmet_id) === String(kaynak().hizmet_id));
  const filtreli = () => uygun().filter((s) => egitmen === null || String(s.personel_id) === egitmen);
  const cip = (deger, ad, on, veri) => `<button type="button" class="dpl-cip${on ? ' on' : ''}" data-${veri}="${esc(deger)}">${esc(ad)}</button>`;

  const ciz = () => {
    el('#dplOzet', ov).textContent = secili.size ? `${secili.size} slot seçildi` : '';
    if (!uygun().length) {
      el('#dplEgitmen', ov).innerHTML = el('#dplGun', ov).innerHTML = '';
      el('#dplSlot', ov).innerHTML = '<div class="dpl-uyari">Bu hizmet için haftalık programda ders yok. Önce Ders Programı\'na ekleyin.</div>';
      return;
    }
    const egitmenler = new Map(uygun().filter((s) => s.personel_id).map((s) => [String(s.personel_id), s.personel]));
    el('#dplEgitmen', ov).innerHTML = egitmenler.size > 1
      ? cip('', 'Tüm Eğitmenler', egitmen === null, 'egitmen') + [...egitmenler].map(([id, ad]) => cip(id, ad, egitmen === id, 'egitmen')).join('') : '';
    const gunler = [...new Set(filtreli().map((s) => s.hafta_gunu))].sort((a, b) => a - b);
    if (!gunler.includes(gun)) gun = gunler[0] ?? null;
    el('#dplGun', ov).innerHTML = gunler.map((g) => cip(g, GUNLER[g - 1].kisa, g === gun, 'gun')).join('');
    el('#dplSlot', ov).innerHTML = filtreli().filter((s) => s.hafta_gunu === gun)
      .map((s) => `<button type="button" class="dpl-slot${secili.has(s.id) ? ' on' : ''}" data-slot="${s.id}"><span class="dpl-isaret"></span>
        <span><b>${esc(s.saat)} – ${esc(s.saat_bitis)}</b><small>${esc(s.personel || 'Eğitmen atanmamış')} • ${esc(s.ders_tipi || '')} • ${s.kapasite} kişi</small></span></button>`).join('');
    ov.querySelectorAll('[data-egitmen]').forEach((b) => { b.onclick = () => { egitmen = b.dataset.egitmen || null; gun = null; ciz(); }; });
    ov.querySelectorAll('[data-gun]').forEach((b) => { b.onclick = () => { gun = Number(b.dataset.gun); ciz(); }; });
    ov.querySelectorAll('[data-slot]').forEach((b) => {
      b.onclick = () => { const id = Number(b.dataset.slot); if (!secili.delete(id)) secili.add(id); ciz(); };
    });
  };

  el('#dplKaynak', ov).onchange = () => { secili.clear(); egitmen = null; gun = null; ciz(); };
  el('#dplVazgec', ov).onclick = modalKapat;
  el('#dplKaydet', ov).onclick = (e) => kilitle(e.currentTarget, 'Dağıtılıyor…', async () => {
    if (!secili.size) { mesaj('En az bir ders slotu seçin.', 'hata'); return; }
    const k = kaynak();
    const y = await api('POST', '/ders/tekrarli', { body: {
      salon_id: salonId, musteri_id: musteriId, hizmet_id: k.hizmet_id, toplam_seans: k.kalan_seans, sablonlar: [...secili],
      adisyon_paket_id: k.adisyon_paket_id, adisyon_hizmet_id: k.adisyon_hizmet_id, baslangic: tarihStr(new Date()),
    } });
    if (!y.ok || !y.govde?.basarili) { yazSonuc(y); return; }
    const s = y.govde.veri.sonuc;
    mesaj(`${s.olusan} ders planlandı${s.yerlesmeyen > 0 ? ` • ${s.yerlesmeyen} seans yerleştirilemedi` : ''}.`, 'ok');
    modalKapat();
    onBitti();
  });
  ciz();
  await kapanisi(ov);
}

/** Stüdyoda satış sonrası: satılan paket/hizmetler için plan teklifi (modül kapalıysa sessizce geçer). */
export async function satisSonrasiDersPlani(salonId, adisyonId) {
  const a = oge(await api('GET', `/adisyon/${adisyonId}`));
  if (!a?.adisyon?.user_id) return;
  const adlar = [...(a.paketler || []), ...(a.hizmetler || [])].map((k) => k.ad);
  await dersPlani({ salonId, musteriId: a.adisyon.user_id, adlar, sessiz: true });
}

// ─────────────────────────── Müşteri detay sekmesi ───────────────────────────

export async function musteriDersSekmesi(kap, { salonId, musteriId, plan = false }) {
  const durumRozet = { rezerve: 'mavi', geldi: 'yesil', gelmedi: 'kirmizi', telafi: 'turuncu', bekleme: 'turuncu' };
  const yukle = async () => {
    const params = { salon_id: salonId, musteri_id: musteriId };
    const [d, p] = await Promise.all([
      api('GET', `/ders/musteri/${musteriId}/dersler`, { params: { salon_id: salonId } }),
      plan ? api('GET', '/ders/tekrarli', { params }) : null,
    ]);
    const dersler = oge(d) || [];
    const planlar = (p && oge(p)) || [];
    kap.innerHTML = `
      ${plan ? `<div class="dm-bolum"><div class="dm-bolum-bas"><h3>Otomatik Ders Planı</h3><button class="rc-btn rc-btn-yesil" id="dmPlan" type="button"><i class="bi bi-arrow-repeat"></i> Plan Oluştur</button></div>
        ${planlar.length ? planlar.map((x) => `<div class="dm-satir"><span><b>${esc(x.hizmet_adi)}</b><small>${x.yerlesen}/${x.toplam_seans} seans yerleşti${x.kalan > 0 ? ` • ${x.kalan} bekliyor` : ''}</small></span>
          <button class="rc-btn rc-btn-ikincil" data-plan-sil="${x.id}" type="button">Sil</button></div>`).join('') : '<div class="do-bos">Plan yok.</div>'}</div>` : ''}
      <div class="dm-bolum"><div class="dm-bolum-bas"><h3>Dersler</h3></div>
        ${dersler.length ? dersler.map((x) => `<div class="dm-satir"><span><b>${esc(x.ders_tipi)}</b><small>${esc(x.tarih)} ${esc(x.saat)}${x.personel ? ' · ' + esc(x.personel) : ''}</small></span>
          <span class="rc-rozet rc-rozet-${durumRozet[x.durum] || 'gri'}">${esc(x.durum)}</span></div>`).join('') : '<div class="do-bos">Son 3 gün ve sonrasında ders yok.</div>'}</div>`;
    if (plan) {
      el('#dmPlan', kap).onclick = () => dersPlani({ salonId, musteriId, onBitti: yukle });
      kap.querySelectorAll('[data-plan-sil]').forEach((b) => {
        b.onclick = async () => {
          if (!await onay('Plan silinecek, gelecekteki otomatik katılımlar kaldırılacak. Geçmiş dersler korunur.', { baslik: 'Plan silinsin mi?', tamam: 'Sil', iptal: 'Vazgeç' })) return;
          const y = await api('DELETE', `/ders/tekrarli/${b.dataset.planSil}`);
          if (yazSonuc(y, y.govde?.mesaj)) yukle();
        };
      });
    }
  };
  await yukle();
}
