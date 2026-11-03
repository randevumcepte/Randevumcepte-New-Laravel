/**
 * Özet (dashboard) v2 — build'siz island (sade ES module).
 *
 * Eski isletmeadmin/dashboard.blade.php karşılığı: kahraman + bugünün sayaçları, hızlı
 * eylemler, kartlar (Kasa, Memnuniyet, Randevu, Çarkıfelek, Dönem Karşılaştırma, Bugünkü
 * Akış, Personel), liste sekmeleri. HANGİ kart/sekme/eylemin çizileceği, dönem seçenekleri,
 * tablo kolonları ve tüm sayılar /api/v2/ozet/*'tan gelir — burada yetki kuralı ya da
 * hesap YOK (Flutter ana ekranı aynı uçları kullanır).
 */

import { qp, api, el, esc, TL } from '/js/lib/v2-ortak.js?v=1';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=2';
import { sayi, trTarih } from '/js/lib/v2-donem.js?v=1';
import { halka, cizgi, cubuk, RENK } from '/js/lib/v2-grafik.js?v=1';

const kok = document.getElementById('rc-ozet');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
if (salonId && window.rcPanel) window.rcPanel.salonAyarla(salonId);

/** Kartların ekrandaki yeri (yalnız yerleşim; hangi kartın geleceği API'den). */
const YER = { kasa: 'ust', anket: 'ust', randevu: 'ust', cark: 'ust', karsilastirma: 'genis', akis: 'alt', personel: 'alt' };
const KOVA_RENK = { nakit: RENK.yesil, kredikarti: RENK.mor, havale: RENK.kehribar, online: RENK.camgobegi, diger: RENK.gri };

const bos = (ikon, metin) => `<div class="oz-bos"><i class="bi ${ikon}"></i>${esc(metin)}</div>`;
const yuzde = (v, t) => (t > 0 ? Math.round((v / t) * 1000) / 10 : 0);
const ilkAd = (s) => String(s || '').trim().split(/\s+/)[0] || '';
const halkaHtml = (oran, ust, alt, renk = RENK.mor) => `
  <div class="oz-halka" style="background:conic-gradient(${renk} ${Math.min(100, oran) * 3.6}deg, var(--rc-zemin) 0)">
    <div><div><b>${esc(ust)}</b><small>${alt}</small></div></div></div>`;

// ───────────────────────── İskelet ─────────────────────────

async function baslat() {
  if (!salonId) { kok.innerHTML = bos('bi-shop', 'Şube seçili değil (?sube=... bekleniyor).'); return; }
  kok.innerHTML = `<div class="oz-bos"><span class="rc-yukleniyor"></span> Yükleniyor…</div>`;

  const r = await api('GET', '/ozet/acilis', { params: { salon_id: salonId } });
  if (!r.ok || !r.govde?.basarili) { kok.innerHTML = `<div class="oz-bos rc-hata">${esc(r.govde?.mesaj || `Hata (${r.kod})`)}</div>`; return; }
  const v = r.govde.veri;
  const yer = (y) => v.kartlar.filter((k) => YER[k.kod] === y);

  kok.innerHTML = `
    <div class="oz">
      ${heroHtml(v)}
      ${v.eylemler.length ? `<div class="oz-eylemler">${v.eylemler.map(eylemHtml).join('')}</div>` : ''}
      ${yer('ust').length ? `<div class="oz-grid">${yer('ust').map(kartHtml).join('')}</div>` : ''}
      ${yer('genis').map(kartHtml).join('')}
      ${v.sekmeler.length ? `<section class="oz-kart"><div class="rc-sekmeler" id="ozSekmeler"></div><div id="ozTablo"></div></section>` : ''}
      ${yer('alt').length ? `<div class="oz-grid">${yer('alt').map(kartHtml).join('')}</div>` : ''}
      ${!v.kartlar.length && !v.sekmeler.length ? bos('bi-lock', 'Bu ekrandaki bilgiler için yetkiniz yok.') : ''}
    </div>`;

  selamla(window.rcPanel?.veri);
  document.addEventListener('rc:kabuk', (e) => selamla(e.detail));
  v.kartlar.forEach((k) => { donemBagla(k); kartYukle(k.kod, k.varsayilan_donem); });
  if (v.sekmeler.length) sekmeleriKur(v.sekmeler);
}

function heroHtml(v) {
  const b = v.bugun || {};
  const sayilar = [
    [b.randevu, 'Bugün Randevu', sayi], [b.gelir, 'Bugün Gelir', TL], [b.on_gorusme, 'Ön Görüşme', sayi],
    [b.paket_satis, 'Paket Satışı', sayi], [b.urun_satis, 'Ürün Satışı', sayi], [v.salon.lisans_gun, 'Üyelik Gün', sayi],
  ].filter(([d]) => d !== null && d !== undefined);
  const tarih = new Date().toLocaleDateString('tr-TR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

  return `
    <div class="oz-hero">
      <div>
        <div class="oz-hero-tarih">${esc(tarih)}</div>
        <h1 id="ozSelam">${esc(selam())} 👋</h1>
        <p>${esc(v.salon.ad)} — Bugünün özetine göz at, aşağıdaki kartlardan her şeyi tek tıkla yönet.</p>
      </div>
      <div class="oz-hero-sayilar">${sayilar.map(([d, e, f]) => `<div class="oz-hero-sayi"><b>${esc(f(d))}</b><small>${esc(e)}</small></div>`).join('')}</div>
    </div>`;
}

function selam() {
  const s = new Date().getHours();
  return s < 6 ? 'İyi geceler' : s < 12 ? 'Günaydın' : s < 18 ? 'İyi günler' : 'İyi akşamlar';
}

function selamla(kabuk) {
  const ad = ilkAd(kabuk?.kullanici?.ad);
  const h = el('#ozSelam');
  if (h && ad) h.textContent = `${selam()}, ${ad} 👋`;
}

const eylemHtml = (e) => (e.url
  ? `<a class="oz-eylem" href="${esc(e.url)}"><i class="bi ${esc(e.ikon)}"></i>${esc(e.etiket)}</a>`
  : `<span class="oz-eylem" aria-disabled="true" title="Bu ekran yeni panelde henüz yok"><i class="bi ${esc(e.ikon)}"></i>${esc(e.etiket)}</span>`);

function kartHtml(k) {
  const donem = k.donemler.length > 1
    ? `<div class="oz-donem">${k.donemler.map((d) => `<button type="button" data-donem="${esc(d.kod)}"${d.kod === k.varsayilan_donem ? ' class="aktif"' : ''}>${esc(d.ad)}</button>`).join('')}</div>`
    : '';
  return `
    <section class="oz-kart" data-kart="${esc(k.kod)}">
      <div class="oz-kart-bas"><div class="oz-kart-baslik"><i class="bi ${esc(k.ikon)}"></i>${esc(k.baslik)}</div>${donem}</div>
      <div class="oz-govde"><div class="oz-bos"><span class="rc-yukleniyor"></span></div></div>
    </section>`;
}

function donemBagla(k) {
  const kap = el(`[data-kart="${k.kod}"] .oz-donem`);
  if (!kap) return;
  kap.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-donem]');
    if (!b || b.classList.contains('aktif')) return;
    kap.querySelectorAll('button').forEach((x) => x.classList.toggle('aktif', x === b));
    kartYukle(k.kod, b.dataset.donem);
  });
}

const istekler = {};
async function kartYukle(kod, donem) {
  const govde = el(`[data-kart="${kod}"] .oz-govde`);
  const no = (istekler[kod] = (istekler[kod] || 0) + 1);
  const r = await api('GET', `/ozet/kart/${kod}`, { params: { salon_id: salonId, ...(donem ? { donem } : {}) } });
  if (no !== istekler[kod]) return;
  if (!r.ok || !r.govde?.basarili) { govde.innerHTML = `<div class="oz-bos rc-hata">${esc(r.govde?.mesaj || `Hata (${r.kod})`)}</div>`; return; }
  CIZICILER[kod]?.(govde, r.govde.veri);
}

// ───────────────────────── Kartlar ─────────────────────────

const CIZICILER = {
  kasa(g, v) {
    const toplam = v.toplam || 0;
    g.innerHTML = `
      <div class="oz-iki">
        <div class="oz-halka-kap"><canvas id="ozKasaHalka"></canvas></div>
        <div>${v.odeme_etiketleri.map((e) => `
          <div class="oz-satir"><span class="oz-nokta" style="background:${KOVA_RENK[e.kod] || RENK.gri}"></span>${esc(e.ad)}
            <b>${esc(TL(v.kovalar[e.kod]))} <span class="oz-alt-yazi">%${yuzde(v.kovalar[e.kod] || 0, toplam)}</span></b></div>`).join('')}
        </div>
      </div>
      <div><div class="oz-alt-yazi">Toplam Gelir${v.personel_kapsami ? ' (kendi satışlarınız)' : ''}</div><div class="oz-buyuk">${esc(TL(toplam))}</div></div>
      <div><div class="oz-alt-yazi">Son 7 gün</div><div class="oz-cizgi-kap"><canvas id="ozKasaCizgi"></canvas></div></div>`;
    const etiket = v.odeme_etiketleri;
    if (!halka(el('#ozKasaHalka', g), etiket.map((e) => e.ad), etiket.map((e) => v.kovalar[e.kod] || 0), { para: true })) {
      el('#ozKasaHalka', g).parentElement.innerHTML = bos('bi-wallet2', 'Bu dönemde tahsilat yok.');
    }
    const c = cizgi(el('#ozKasaCizgi', g), v.son7_gun.map((p) => trTarih(p.tarih).slice(0, 5)), v.son7_gun.map((p) => p.tutar), { renk: RENK.yesil, ek: ' ₺' });
    if (c) { c.options.scales.x.display = false; c.options.scales.y.display = false; c.update(); }
  },

  randevu(g, v) {
    const oran = v.doluluk || 0;
    const renk = oran >= 70 ? RENK.yesil : oran >= 40 ? RENK.kehribar : RENK.gul;
    const sayac = (ikon, ton, d, e) => `<div class="oz-sayac"><i class="bi ${ikon}" style="background:var(--rc-t-${ton});color:var(--rc-t-${ton}-ink)"></i><div><b>${esc(sayi(d))}</b><small>${esc(e)}</small></div></div>`;
    g.innerHTML = `
      <div class="oz-iki">
        ${halkaHtml(oran, `%${oran}`, 'ANLIK<br>DOLULUK', renk)}
        <div>
          ${sayac('bi-alarm', 'mor', v.olusturulan, 'Oluşturulanlar')}
          ${sayac('bi-check-circle-fill', 'yesil', v.sonuclanan, 'Sonuçlananlar')}
          ${sayac('bi-x-circle-fill', 'pembe', v.sonuclanmayan, 'Sonuçlanmayanlar')}
        </div>
      </div>
      <div class="oz-alt-yazi">Kaynak: İşletme ${sayi(v.kaynak.isletme)} · Web ${sayi(v.kaynak.web)} · Uygulama ${sayi(v.kaynak.uygulama)}</div>`;
  },

  anket(g, v) {
    const n = v.nps;
    const s = n.skor;
    const genis = (x) => (n.toplam > 0 ? (x / n.toplam) * 100 : 0);
    g.innerHTML = `
      <div class="oz-iki">
        ${halkaHtml(s === null ? 0 : (s + 100) / 2, s === null ? '—' : (s > 0 ? '+' : '') + s, 'NPS')}
        <div>
          <div class="oz-cubuk" title="Promoter / Pasif / Detractor">
            <span style="width:${genis(n.promoter)}%;background:${RENK.yesil}"></span><span style="width:${genis(n.pasif)}%;background:${RENK.kehribar}"></span><span style="width:${genis(n.detractor)}%;background:${RENK.gul}"></span>
          </div>
          <div class="oz-satir"><span class="oz-nokta" style="background:${RENK.yesil}"></span>Promoter<b>${sayi(n.promoter)}</b></div>
          <div class="oz-satir"><span class="oz-nokta" style="background:${RENK.kehribar}"></span>Pasif<b>${sayi(n.pasif)}</b></div>
          <div class="oz-satir"><span class="oz-nokta" style="background:${RENK.gul}"></span>Detractor<b>${sayi(n.detractor)}</b></div>
          <div class="oz-satir">CSAT / 5<b>${v.csat_ort === null ? '—' : esc(String(v.csat_ort).replace('.', ','))}</b></div>
          <div class="oz-satir">Cevap Oranı<b>%${esc(String(v.cevap_orani).replace('.', ','))} <span class="oz-alt-yazi">${sayi(v.toplam_cevap)} / ${sayi(v.toplam_gonderim)}</span></b></div>
        </div>
      </div>
      <div>${v.son_yorumlar.length ? v.son_yorumlar.map((y) => {
        const sinif = y.nps_skoru === null ? '' : y.nps_skoru >= 9 ? 'yuksek' : y.nps_skoru <= 6 ? 'dusuk' : '';
        const skor = y.nps_skoru !== null ? `NPS ${y.nps_skoru}` : y.csat_skoru !== null ? `CSAT ${y.csat_skoru}` : '';
        const metin = y.yorum.length > 110 ? y.yorum.slice(0, 110) + '…' : y.yorum;
        return `<div class="oz-yorum ${sinif}">“${esc(metin)}”<small>${esc([y.ad, skor, trTarih(y.cevap_zamani)].filter(Boolean).join(' · '))}</small></div>`;
      }).join('') : bos('bi-chat-quote', 'Henüz yorum yok.')}</div>`;
  },

  cark(g, v) {
    g.innerHTML = `
      <div class="oz-iki">
        ${halkaHtml(v.kazanma_orani, `%${v.kazanma_orani}`, 'KAZANMA', RENK.camgobegi)}
        <div>
          <div class="oz-satir">Toplam Çevrim<b>${sayi(v.toplam_cevrim)}</b></div>
          <div class="oz-satir"><span class="oz-nokta" style="background:${RENK.yesil}"></span>Kazanan<b>${sayi(v.kazanan)}</b></div>
          <div class="oz-satir"><span class="oz-nokta" style="background:${RENK.gri}"></span>Boş<b>${sayi(v.bos)}</b></div>
          <div class="oz-satir">Kupon (verilen / kullanılan)<b>${sayi(v.kupon.verilen)} / ${sayi(v.kupon.kullanilan)}</b></div>
          <div class="oz-satir">Kupon Kullanım<b>%${esc(String(v.kupon.orani))}</b></div>
        </div>
      </div>
      <div>${v.son_kazananlar.length ? v.son_kazananlar.map((k) => `<div class="oz-satir"><i class="bi bi-trophy-fill" style="color:#f7c948"></i>${esc(k.ad)}<b>${esc(k.odul)}</b></div>`).join('') : bos('bi-trophy', 'Henüz kazanan yok.')}</div>`;
  },

  karsilastirma(g, v) {
    const d = v.degisim;
    const rozet = d === null ? '' : `<span class="oz-degisim ${d >= 0 ? 'arti' : 'eksi'}">${d >= 0 ? '▲' : '▼'} %${Math.abs(d).toLocaleString('tr-TR')}</span>`;
    const lider = v.liderler;
    const saatler = v.saat_dagilim.filter((s) => s.adet > 0);
    g.innerHTML = `
      <div class="oz-lider">
        <div><small>${esc(v.simdi.etiket)}${v.personel_kapsami ? ' (kendi satışlarınız)' : ''}</small><div class="oz-buyuk">${esc(TL(v.simdi.tutar))}</div>${rozet}</div>
        <div><small>${esc(v.onceki.etiket)}</small><b>${esc(TL(v.onceki.tutar))}</b></div>
        <div><small>Açık Alacak (dönem vadeli)</small><b>${esc(TL(v.alacak))}</b></div>
        ${v.en_yogun_saat !== null ? `<div><small>En Yoğun Saat</small><b>${esc(v.en_yogun_saat)}:00</b></div>` : ''}
      </div>
      ${lider ? `<div class="oz-lider">
        <div><small>Zirvedeki Personel</small><b>${lider.personel ? `${esc(lider.personel.ad)} · ${esc(TL(lider.personel.tutar))}` : '—'}</b></div>
        <div><small>En Çok Uygulanan Hizmet</small><b>${lider.hizmet ? `${esc(lider.hizmet.ad)} · ${sayi(lider.hizmet.adet)}` : '—'}</b></div>
        <div><small>En Çok Satılan Ürün</small><b>${lider.urun ? `${esc(lider.urun.ad)} · ${sayi(lider.urun.adet)}` : '—'}</b></div>
      </div>` : ''}
      <div class="oz-grid">
        <div><div class="oz-alt-yazi">Son 7 dönem tahsilatı</div><div class="oz-grafik"><canvas id="ozSeri"></canvas></div></div>
        <div><div class="oz-alt-yazi">Saat yoğunluğu (randevu)</div>${saatler.length ? '<div class="oz-grafik"><canvas id="ozSaat"></canvas></div>' : bos('bi-clock', 'Bu dönemde randevu yok.')}</div>
      </div>`;
    const seriEtiket = (s) => (s.baslangic === s.bitis ? trTarih(s.baslangic).slice(0, 5) : `${trTarih(s.baslangic).slice(0, 5)}–${trTarih(s.bitis).slice(0, 5)}`);
    cubuk(el('#ozSeri', g), v.seri.map(seriEtiket), v.seri.map((s) => s.tutar), { para: true });
    if (saatler.length) {
      const ilk = v.saat_dagilim.findIndex((s) => s.adet > 0);
      const son = v.saat_dagilim.length - 1 - [...v.saat_dagilim].reverse().findIndex((s) => s.adet > 0);
      const dilim = v.saat_dagilim.slice(ilk, son + 1);
      cubuk(el('#ozSaat', g), dilim.map((s) => `${s.saat}:00`), dilim.map((s) => s.adet), { renk: RENK.lila, ek: ' randevu' });
    }
  },

  akis(g, v) {
    if (!v.liste.length) { g.innerHTML = bos('bi-activity', 'Bugün için kayıt yok.'); return; }
    const simdi = `<div class="oz-simdi">Şimdi · ${esc(v.simdi)}</div>`;
    let eklendi = false;
    const satirlar = v.liste.map((o) => {
      const once = !eklendi && o.saat > v.simdi ? ((eklendi = true), simdi) : '';
      const sag = o.tip === 'tahsilat' ? `<span class="tutar">${esc(TL(o.tutar))}</span>` : `<span class="rc-rozet rc-rozet-${esc(o.durum.renk)}" style="margin-left:auto">${esc(o.durum.etiket)}</span>`;
      return `${once}<div class="oz-akis-oge ${o.tip}"><span class="saat">${esc(o.saat)}</span><span><i class="bi ${o.tip === 'tahsilat' ? 'bi-cash-coin' : 'bi-calendar-check'}"></i> ${esc(o.baslik)}</span>${sag}</div>`;
    }).join('');
    g.innerHTML = `<div class="oz-akis">${satirlar}${eklendi ? '' : simdi}</div>`;
  },

  personel(g, v) {
    if (!v.personeller.length) { g.innerHTML = bos('bi-person', 'Aktif personel yok.'); return; }
    g.innerHTML = v.personeller.map((p) => `
      <div class="oz-personel" title="${esc(p.ad)}: ${p.randevu} randevu / ${v.kapasite} kapasite">
        <div class="ad">${esc(p.ad)}</div><div class="bar"><span style="width:0" data-w="${p.doluluk}"></span></div><div class="yuzde">%${p.doluluk}</div>
      </div>`).join('');
    requestAnimationFrame(() => g.querySelectorAll('.bar span').forEach((s) => { s.style.width = s.dataset.w + '%'; }));
  },
};

// ───────────────────────── Liste sekmeleri ─────────────────────────

/** Kolon tipine göre hücre + sıralama değeri — kolonlar API'den (OzetSekmesi::kolonlar). */
const HUCRE = {
  tarih: (d) => esc(trTarih(d)),
  saat: (d) => esc(d || ''),
  para: (d) => esc(TL(d)),
  durum: (d) => (d ? `<span class="rc-rozet rc-rozet-${esc(d.renk)}">${esc(d.etiket)}</span>` : ''),
  telefon: (d) => esc(d || '—'),
  metin: (d) => esc(d ?? '—'),
};
const DEGER = { para: (d) => Number(d) || 0, durum: (d) => d?.etiket || '', metin: (d) => (typeof d === 'number' ? d : String(d ?? '')) };

function hucre(kolon, k) {
  const h = (HUCRE[kolon.tip] || HUCRE.metin)(k[kolon.alan]);
  if (kolon.alan === 'musteri' && k.musteri_id) return `<a href="/v2/musteri/${k.musteri_id}?sube=${salonId}">${h}</a>`;
  return h;
}

function sekmeleriKur(sekmeler) {
  let aktif = sekmeler[0];
  const kap = el('#ozSekmeler');
  const tabloKap = el('#ozTablo');

  const ciz = () => {
    kap.innerHTML = sekmeler.map((s) => `<button class="rc-sekme${s.kod === aktif.kod ? ' aktif' : ''}" data-kod="${esc(s.kod)}"><i class="bi ${esc(s.ikon)}"></i> ${esc(s.ad)}</button>`).join('');
  };

  /** Sekme başına tablo (kolon seti + boş mesaj sekmeye ait); kayıtlar YEREL mod (≤30 satır). */
  const yukle = async () => {
    const s = aktif;
    const tablo = veriTablosu(tabloKap, {
      anahtar: 'ozet',
      bosMesaj: s.bos_mesaj,
      araIpucu: 'Listede ara…',
      kolonlar: s.kolonlar.map((k) => ({ baslik: k.baslik, sirala: k.alan, sinif: k.tip === 'para' ? 'rc-sag' : '', deger: (r) => (DEGER[k.tip] || DEGER.metin)(r[k.alan]) })),
      araMetni: (r) => s.kolonlar.map((k) => { const d = r[k.alan]; return k.tip === 'durum' ? d?.etiket : k.tip === 'tarih' ? trTarih(d) : d; }).join(' '),
      satir: (r) => {
        const git = s.kod === 'acik_adisyon' ? ` class="oz-tik" data-git="/v2/tahsilat/${r.id}"` : '';
        return `<tr${git}>${s.kolonlar.map((k) => `<td class="${k.tip === 'para' ? 'rc-sag' : ''}">${hucre(k, r)}</td>`).join('')}</tr>`;
      },
    });
    tablo.mesaj('Yükleniyor…');
    const r = await api('GET', `/ozet/sekme/${s.kod}`, { params: { salon_id: salonId } });
    if (s !== aktif) return;
    if (!r.ok || !r.govde?.basarili) { tablo.mesaj(r.govde?.mesaj || `Hata (${r.kod})`); return; }
    tablo.kayitlarAyarla(r.govde.veri.liste);
  };

  kap.addEventListener('click', (e) => {
    const b = e.target.closest('.rc-sekme');
    if (!b || b.dataset.kod === aktif.kod) return;
    aktif = sekmeler.find((x) => x.kod === b.dataset.kod);
    ciz();
    yukle();
  });
  // Açık adisyon satırı → tahsilat ekranı (müşteri linkine tıklama hariç).
  tabloKap.addEventListener('click', (e) => {
    if (e.target.closest('a')) return;
    const tr = e.target.closest('tr[data-git]');
    if (tr) location.href = tr.dataset.git;
  });
  ciz();
  yukle();
}

baslat();
