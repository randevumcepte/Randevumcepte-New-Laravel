/**
 * Reklam Yönetimi (v2) — build'siz island (sade ES module).
 *
 * Eski /isletmeyonetim/kampanya_yonetimi (kampanya_yonetimi.blade + reklam-ekle-modal + custom.js
 * kampanya* + reklamYonetimi2.js + senaryoSihirbazi.js) karşılığı: kanal sekmeli kampanya tablosu,
 * Yeni Reklam / Düzenle sihirbazı (kanal → hedef kitle → hizmet/ürün/paket → mesaj → indirim →
 * planlama), Reklam Raporu (reklam/rapor.js) ve Senaryo Sihirbazı (reklam/senaryo.js).
 *
 * TEK KAYNAK: sözlükler (kanal, hedef, cinsiyet, indirim türleri, rapor sekmeleri, yer tutucular,
 * senaryo adımları), canlı kitle sayısı, mesaj önizlemesi, satır durumu/işlemleri /api/v2/kampanya/*
 * uçlarından — Flutter da aynı uçları kullanır; burada iş kuralı yok. Yazmalar
 * KAMPANYA_V2_YAZMA_AKTIF kapalıyken 409 (toast).
 */
import { qp, el, esc, mesaj, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=8';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';
import { kilitle } from '/js/lib/v2-bilesen.js?v=7';
import { trTarih, sayi } from '/js/lib/v2-donem.js?v=7';
import { alan, secenekler, tarihBagla, sayiVeyaNull } from '/js/lib/v2-ongorusme.js?v=8';
import { baglam as b, kanalRozeti, durumRozeti } from '/js/reklam/ortak.js?v=1';

const kok = document.getElementById('rc-reklam');
b.salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;

let kanal = qp.get('kanal') || '';
let tablo = null;
let sonListe = [];
let sayaclar = {};

// ─────────────────────────── iskelet + tablo ───────────────────────────

function iskeletCiz() {
  kok.innerHTML = `
    <div class="rk-kabuk">
      <div class="rc-sayfa-ust">
        <div class="rc-sayfa-baslik">
          <span class="rc-sayfa-ikon"><i class="bi bi-megaphone"></i></span>
          <div><h1>Reklam Yönetimi</h1><div class="rc-sayfa-alt">Arama, SMS ve bildirim kampanyaları · indirim kodu takibi</div></div>
        </div>
        <div class="rc-sayfa-aksiyon">
          ${b.yetki('ekle_duzenle') ? `
            <button class="rc-btn rc-btn-ikincil" id="rkSenaryo"><i class="bi bi-magic"></i> Senaryo Sihirbazı</button>
            <button class="rc-btn rc-btn-yesil" id="rkYeni"><i class="bi bi-plus-lg"></i> Yeni Reklam</button>` : ''}
        </div>
      </div>
      <div class="rc-sekmeler" id="rkSekmeler" role="tablist"></div>
      <div id="rkTablo"></div>
    </div>`;

  el('#rkYeni')?.addEventListener('click', () => sihirbaz(null));
  el('#rkSenaryo')?.addEventListener('click', async () => (await import('/js/reklam/senaryo.js?v=1')).senaryoSihirbazi({ onKaydet: formVerisiTazele }));
  el('#rkSekmeler').addEventListener('click', (e) => {
    const t = e.target.closest('[data-kanal]');
    if (!t) return;
    kanal = t.dataset.kanal;
    const u = new URL(location.href);
    if (kanal) u.searchParams.set('kanal', kanal); else u.searchParams.delete('kanal');
    history.replaceState(null, '', u);
    sekmeCiz();
    tablo.yenile();
  });

  tablo = veriTablosu(el('#rkTablo'), {
    anahtar: 'reklam_yonetimi', birim: 'kampanya', varsayilanBoy: 25, tabloSinif: 'rk-tablo',
    araIpucu: 'Kampanya, hizmet, hedef kitle veya kod…', bosMesaj: 'Henüz kampanya yok. “Yeni Reklam” ile başlayın.',
    sirala: { alan: 'id', yon: 'desc' },
    kolonlar: [
      { baslik: 'Kanal', sirala: 'gorev_turu' }, { baslik: 'Kampanya', sirala: 'paket_isim' },
      { baslik: 'Planlama', sirala: 'baslangic_tarihi' }, { baslik: 'İndirim' }, { baslik: 'Hedef Kitle' },
      { baslik: 'Katılımcı', sirala: 'katilimci_sayisi', sinif: 'rc-sag' }, { baslik: 'Durum' }, { baslik: 'İşlemler', sinif: 'rc-orta' },
    ],
    satir: satirCiz,
    kaynak: (p) => (b.salonId ? b.oku('/kampanya', { ...p, kanal })
      : Promise.resolve({ ok: false, kod: 422, govde: { mesaj: 'Şube seçili değil (?sube=... bekleniyor).' } })),
    cizildi: (liste, v) => { sonListe = liste; sayaclar = v?.sayaclar || {}; sekmeCiz(); },
  });
  el('#rkTablo').addEventListener('click', satirTiklama);
  sekmeCiz();
}

function sekmeCiz() {
  const sekmeler = [{ kod: '', ad: 'Tümü', ikon: 'bi-grid', sayac: 'tumu' }, ...b.fv.kanallar.map((k) => ({ kod: String(k.kod), ad: k.kisa, ikon: k.ikon, sayac: String(k.kod) }))];
  el('#rkSekmeler').innerHTML = sekmeler.map((s) => `
    <button class="rc-sekme${s.kod === kanal ? ' aktif' : ''}" data-kanal="${esc(s.kod)}" role="tab" aria-selected="${s.kod === kanal}">
      <i class="bi ${esc(s.ikon)}"></i> ${esc(s.ad)} <span class="rc-sayac">${sayaclar[s.sayac] ?? '…'}</span>
    </button>`).join('');
}

function satirCiz(k) {
  const islem = (a, ikon, baslik, sinif = '') => `<button class="rc-islem ${sinif}" data-aksiyon="${a}" data-id="${k.id}" title="${baslik}"><i class="bi ${ikon}"></i></button>`;
  const o = k.ozet || {};
  return `<tr>
    <td>${kanalRozeti(k.kanal)}</td>
    <td><button class="rk-ad" data-aksiyon="rapor" data-id="${k.id}">${esc(k.ad)}</button>${k.hizmet_adi ? `<small class="rk-soluk rk-blok">${esc(k.hizmet_adi)}</small>` : ''}</td>
    <td class="rc-nowrap">${esc(trTarih(k.baslangic_tarihi))} – ${esc(trTarih(k.bitis_tarihi))}<small class="rk-soluk rk-blok"><i class="bi bi-clock"></i> ${esc(k.gonderim_saati || '—')}</small></td>
    <td>${k.indirim_turu ? `<b>${esc(k.indirim_turu)}</b>` : '<span class="rk-soluk">—</span>'}${k.indirim_kodu && k.indirim_turu ? `<small class="rk-kod rk-blok">${esc(k.indirim_kodu)}</small>` : ''}</td>
    <td class="rk-soluk">${esc(k.musteri_turu || '—')}</td>
    <td class="rc-sag rc-nowrap"><b>${sayi(k.katilimci_sayisi)}</b>${o.indirim_kullanan ? `<small class="rk-blok rk-yesil" title="İndirim kodunu kullanan">${sayi(o.indirim_kullanan)} kullandı</small>` : ''}</td>
    <td>${durumRozeti(k.durum)}</td>
    <td class="rc-orta"><span class="rc-islemler">
      ${islem('rapor', 'bi-bar-chart', 'Reklam Raporu')}
      ${k.islemler.duzenle ? islem('duzenle', 'bi-pencil', 'Düzenle') : ''}
      ${k.islemler.sil ? islem('sil', 'bi-trash', 'Sil', 'rc-tehlike') : ''}
    </span></td>
  </tr>`;
}

function satirTiklama(e) {
  const t = e.target.closest('[data-aksiyon]');
  const k = t && sonListe.find((x) => String(x.id) === t.dataset.id);
  if (!k) return;
  ({
    rapor: async () => (await import('/js/reklam/rapor.js?v=1')).reklamRaporu(k.id, { onDegisti: () => tablo.yenile(false) }),
    duzenle: () => sihirbaz(k.id),
    sil: async () => {
      if (!(await onay(`“${k.ad}” kampanyası silinsin mi? Planlanmış gönderimler durur; rapor geçmişi korunur.`, { tehlike: true }))) return;
      if (yazSonuc(await b.yaz('DELETE', `/kampanya/${k.id}`), 'Kampanya silindi')) tablo.yenile(false);
    },
  })[t.dataset.aksiyon]?.();
}

// ─────────────────────────── sihirbaz (ekle / düzenle) ───────────────────────────

const bolum = (no, baslik, icerik, ek = '') => `
  <section class="rk-bolum" ${ek}>
    <div class="rk-bolum-baslik"><span class="rk-no">${no}</span> ${baslik}</div>
    ${icerik}
  </section>`;

const kart = (ad, deger, secili, ust, alt, ikon) => `
  <label class="rk-kart${secili ? ' secili' : ''}">
    <input type="radio" name="${ad}" value="${esc(deger)}"${secili ? ' checked' : ''}>
    <i class="bi ${esc(ikon)}"></i><b>${esc(ust)}</b>${alt ? `<small>${esc(alt)}</small>` : ''}
  </label>`;

/**
 * Sihirbaz durumu tek nesnede; alanlar değiştikçe (a) kitle sayısı, (b) mesaj önizlemesi sunucudan
 * istenir (gecikmeli, yarış korumalı). Kayıt gövdesi doğrudan bu nesneden üretilir.
 */
async function sihirbaz(id) {
  const fv = b.fv;
  const p = fv.planlama || {};
  const d = fv.indirim?.varsayilan || {};
  const w = {
    gorev_turu: 1, hedef_preset: 'tum', hedef_filtre: '', hedef_grup: '', hedef_cinsiyet: '', hedef_yenile: false,
    hizmet_urun_paket: '', sablon_id: '', mesaj: '', ad: '', indirim_turu: d.tur || 'yuzde', yuzde: d.yuzde ?? 10,
    xal: d.xal ?? 2, yode: d.yode ?? 1, indirim_kodu: '', baslangic_tarihi: p.baslangic_tarihi, bitis_tarihi: p.bitis_tarihi,
    gonderim_saati: p.gonderim_saati,
  };
  let kayit = null;
  if (id) {
    const r = await b.oku(`/kampanya/${id}`);
    if (!r.ok || !r.govde?.basarili) return mesaj(r.govde?.mesaj || 'Kampanya alınamadı', 'hata');
    kayit = r.govde.veri;
    Object.assign(w, {
      gorev_turu: kayit.gorev_turu, hedef_preset: kayit.hedef_preset, hedef_filtre: kayit.hedef_filtre, hedef_grup: kayit.hedef_grup,
      hedef_cinsiyet: kayit.hedef_cinsiyet, hizmet_urun_paket: kayit.hizmet_urun_paket, mesaj: kayit.mesaj, ad: kayit.paket_isim,
      indirim_turu: kayit.yuzde_mi ? 'yuzde' : 'xalyode', yuzde: kayit.yuzde ?? w.yuzde, xal: kayit.xal ?? w.xal, yode: kayit.yode ?? w.yode,
      indirim_kodu: kayit.indirim_kodu, baslangic_tarihi: kayit.baslangic_tarihi, bitis_tarihi: kayit.bitis_tarihi,
      gonderim_saati: kayit.gonderim_saati || w.gonderim_saati,
    });
  }

  const kalemSecenek = (etiket, liste) => (liste.length ? `<optgroup label="${esc(etiket)}">${secenekler(liste, w.hizmet_urun_paket, null, 'value', 'label')}</optgroup>` : '');
  const sablonGrup = (etiket, tur) => {
    const l = fv.sablonlar.filter((s) => s.tur === tur);
    return l.length ? `<optgroup label="${esc(etiket)}">${secenekler(l, '', null, 'value', 'label')}</optgroup>` : '';
  };

  const govde = `
    ${kayit ? `<div class="rk-bilgi"><i class="bi bi-info-circle"></i> Hedef kitleyi değiştirmezseniz mevcut ${sayi(kayit.katilimci_sayisi)} katılımcı ve arama / kupon ilerlemesi korunur.</div>` : ''}
    ${bolum(1, 'Kanal', `<div class="rk-kartlar rk-kartlar-4">${fv.kanallar.map((k) => kart('kanal', k.kod, Number(w.gorev_turu) === Number(k.kod), k.ad, k.aciklama, k.ikon)).join('')}</div>`)}
    ${bolum(2, 'Hedef Kitle <span class="rk-sayac" id="wSayac">…</span>', `
      <div class="rk-kartlar rk-kartlar-3">${fv.hedefler.map((h) => kart('hedef', h.kod, w.hedef_preset === h.kod, h.ad, h.aciklama, h.ikon)).join('')}</div>
      <div class="rc-form-izgara rk-ust-bosluk">
        ${alan('Müşteri grubu', `<select id="wGrup">${secenekler(fv.gruplar, w.hedef_grup, fv.gruplar.length ? 'Grup seçin' : 'Tanımlı SMS grubu yok', 'value', 'label')}</select>`, 'rk-grup')}
        ${alan('Cinsiyet', `<div class="rk-cipler">${fv.cinsiyetler.map((c) => `<label class="rk-cip${w.hedef_cinsiyet === c.kod ? ' secili' : ''}"><input type="radio" name="cinsiyet" value="${esc(c.kod)}"${w.hedef_cinsiyet === c.kod ? ' checked' : ''}>${esc(c.ad)}</label>`).join('')}</div>`)}
      </div>
      ${kayit ? `<label class="rk-onay"><input type="checkbox" id="wYenile"> Katılımcı listesini güncel kitleyle yeniden oluştur <small>(arama / kupon ilerlemesi sıfırlanır)</small></label>` : ''}`)}
    ${bolum(3, 'Hizmet / Ürün / Paket <small class="rk-soluk">(isteğe bağlı — indirim bu kaleme uygulanır)</small>', `
      <select id="wKalem"><option value="">Seçilmedi (tüm adisyon)</option>${kalemSecenek('Hizmetler', fv.hizmetler)}${kalemSecenek('Ürünler', fv.urunler)}${kalemSecenek('Paketler', fv.paketler)}</select>`)}
    ${bolum(4, 'Mesaj', `
      <div class="rc-form-izgara">
        ${alan('Şablon', `<select id="wSablon"><option value="">Şablon seçin (metin aşağıya gelir)</option>${sablonGrup('Senaryolar', 'senaryo')}${sablonGrup('SMS taslaklarım', 'sablon')}${sablonGrup('Hazır şablonlar', 'sistem')}</select>`)}
        ${alan('Kampanya adı <small>(boşsa şablon / hizmet adı)</small>', `<input id="wAd" maxlength="255" value="${esc(w.ad)}">`)}
      </div>
      ${alan('Mesaj metni *', `<textarea id="wMesaj" rows="4" maxlength="2000" placeholder="Şablon seçin ya da yazın">${esc(w.mesaj)}</textarea>`, 'rc-tam')}
      <div class="rk-cipler rk-yer">${fv.yer_tutucular.map((y) => `<button type="button" class="rk-cip" data-yer="${esc(y.kod)}" title="${esc(y.aciklama)}">${esc(y.kod)}</button>`).join('')}</div>
      <div class="rk-onizleme"><div class="rk-onizleme-bas"><i class="bi bi-eye"></i> Önizleme <small id="wOrnek"></small></div><div id="wOnizleme" class="rk-balon">…</div></div>`)}
    ${bolum(5, 'İndirim', `
      <div class="rk-cipler">${fv.indirim.turler.map((t) => `<label class="rk-cip${w.indirim_turu === t.kod ? ' secili' : ''}"><input type="radio" name="indirimTuru" value="${esc(t.kod)}"${w.indirim_turu === t.kod ? ' checked' : ''}>${esc(t.ad)}</label>`).join('')}</div>
      <div class="rc-form-izgara rk-ust-bosluk">
        ${alan('İndirim oranı (%)', `<input id="wYuzde" type="number" min="1" max="100" value="${esc(w.yuzde)}">`, 'rk-yuzde')}
        ${alan('Alınan (X)', `<input id="wXal" type="number" min="2" max="99" value="${esc(w.xal)}">`, 'rk-xal')}
        ${alan('Ödenen (Y)', `<input id="wYode" type="number" min="1" max="98" value="${esc(w.yode)}">`, 'rk-xal')}
        ${alan('İndirim kodu <small>(boşsa otomatik)</small>', `<input id="wKod" maxlength="30" value="${esc(w.indirim_kodu)}" placeholder="Otomatik">`)}
      </div>`, 'id="wIndirimBolum"')}
    ${bolum(6, 'Planlama', `
      <div class="rc-form-izgara">
        ${alan('Başlangıç (gönderim günü) *', `<input id="wBas" value="${esc(w.baslangic_tarihi)}">`)}
        ${alan('Bitiş (kod geçerliliği) *', `<input id="wBit" value="${esc(w.bitis_tarihi)}">`)}
        ${alan('Gönderim saati *', `<input id="wSaat" value="${esc(w.gonderim_saati)}">`)}
      </div>
      <div id="wAramaUyari"></div>`)}`;

  const ov = modalAc(kayit ? 'Reklamı Düzenle' : 'Yeni Reklam', govde,
    `<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-yesil" id="wKaydet"><i class="bi bi-send"></i> ${kayit ? 'Kaydet' : 'Kaydet & Planla'}</button>`,
    { ikon: 'megaphone', genis: true, sabit: true, altbaslik: kayit?.ad });
  el('[data-kapat]', ov).onclick = modalKapat;
  ['#wBas', '#wBit'].forEach((s) => tarihBagla(el(s, ov)));
  if (window.flatpickr) window.flatpickr(el('#wSaat', ov), { enableTime: true, noCalendar: true, dateFormat: 'H:i', time_24hr: true, minuteIncrement: 5, locale: 'tr' });

  const kanalNesne = () => b.kanal(w.gorev_turu);
  const gorunurluk = () => {
    el('.rk-grup', ov).hidden = w.hedef_preset !== 'grup';
    el('#wIndirimBolum', ov).hidden = !kanalNesne()?.indirimli;
    ov.querySelectorAll('.rk-yuzde').forEach((x) => { x.hidden = w.indirim_turu !== 'yuzde'; });
    ov.querySelectorAll('.rk-xal').forEach((x) => { x.hidden = w.indirim_turu !== 'xalyode'; });
    const ar = kayit?.arama;
    el('#wAramaUyari', ov).innerHTML = Number(w.gorev_turu) === 1
      ? `<div class="rk-bilgi"><i class="bi bi-telephone"></i> Aramalar başlangıç günü seçilen saatte başlar; E-Asistan “Kampanya Araması” ayarı kapalıysa kayıtta otomatik açılır.${ar && !ar.ayar_acik ? ' <b>Şu an kapalı.</b>' : ''}</div>` : '';
    ov.querySelectorAll('.rk-kart, .rk-cip').forEach((l) => { const i = l.querySelector('input'); if (i) l.classList.toggle('secili', i.checked); });
  };

  // Canlı kitle sayısı + mesaj önizlemesi (gecikmeli; yalnız son isteğin cevabı çizilir).
  const hedefGovdesi = () => ({
    hedef_filtre: w.hedef_preset === 'grup' ? '' : w.hedef_filtre, hedef_grup: w.hedef_preset === 'grup' ? w.hedef_grup : '', hedef_cinsiyet: w.hedef_cinsiyet,
  });
  const indirimGovdesi = () => ({ indirim_turu: w.indirim_turu, yuzde: sayiVeyaNull(w.yuzde), xal: sayiVeyaNull(w.xal), yode: sayiVeyaNull(w.yode) });
  const gecikmeli = (fn, ms = 350) => { let t, no = 0; return () => { clearTimeout(t); const n = ++no; t = setTimeout(() => fn(() => n === no), ms); }; };

  const kitleSay = gecikmeli(async (guncel) => {
    const s = el('#wSayac', ov);
    if (w.hedef_preset === 'grup' && !w.hedef_grup) { s.textContent = 'grup seçin'; s.className = 'rk-sayac'; return; }
    s.textContent = '…';
    const r = await b.yaz('POST', '/kampanya/kitle-sayisi', hedefGovdesi());
    if (!guncel()) return;
    const n = r.ok && r.govde?.basarili ? r.govde.veri.toplam : null;
    s.textContent = n === null ? (r.govde?.mesaj || 'hesaplanamadı') : `${sayi(n)} kişi eşleşiyor`;
    s.className = 'rk-sayac' + (n === 0 ? ' rk-sayac-sifir' : '');
    s.dataset.toplam = n ?? '';
  });

  const onizle = gecikmeli(async (guncel) => {
    const r = await b.yaz('POST', '/kampanya/mesaj-onizle', {
      gorev_turu: w.gorev_turu, mesaj: w.mesaj, sablon_id: w.mesaj ? '' : w.sablon_id, hizmet_urun_paket: w.hizmet_urun_paket, ...indirimGovdesi(),
    });
    if (!guncel()) return;
    const v = r.ok && r.govde?.basarili ? r.govde.veri : null;
    el('#wOnizleme', ov).textContent = v ? (v.onizleme || 'Mesaj metni boş.') : (r.govde?.mesaj || 'Önizleme alınamadı');
    el('#wOrnek', ov).textContent = v ? `· ${v.ornek_musteri} için · ${v.karakter} karakter` : '';
  });

  // Şablon seçimi: metni sunucudan (kanala göre hazırlanmış) alıp düzenlenebilir alana koyar.
  async function sablonUygula(deger) {
    w.sablon_id = deger;
    if (!deger) return;
    const r = await b.yaz('POST', '/kampanya/mesaj-onizle', { gorev_turu: w.gorev_turu, sablon_id: deger, hizmet_urun_paket: w.hizmet_urun_paket, ...indirimGovdesi() });
    if (!r.ok || !r.govde?.basarili) return mesaj(r.govde?.mesaj || 'Şablon alınamadı', 'hata');
    w.mesaj = r.govde.veri.sablon_metni;
    el('#wMesaj', ov).value = w.mesaj;
    onizle();
  }

  ov.addEventListener('change', (e) => {
    const t = e.target;
    if (t.name === 'kanal') { w.gorev_turu = Number(t.value); if (w.sablon_id) sablonUygula(w.sablon_id); else onizle(); }
    if (t.name === 'hedef') {
      w.hedef_preset = t.value;
      w.hedef_filtre = fv.hedefler.find((h) => h.kod === t.value)?.filtre ?? '';
      kitleSay();
    }
    if (t.name === 'cinsiyet') { w.hedef_cinsiyet = t.value; kitleSay(); }
    if (t.name === 'indirimTuru') { w.indirim_turu = t.value; onizle(); }
    if (t.id === 'wGrup') { w.hedef_grup = t.value; kitleSay(); }
    if (t.id === 'wKalem') { w.hizmet_urun_paket = t.value; onizle(); }
    if (t.id === 'wSablon') sablonUygula(t.value);
    if (t.id === 'wYenile') w.hedef_yenile = t.checked;
    gorunurluk();
  });
  ov.addEventListener('input', (e) => {
    const t = e.target;
    const harita = { wMesaj: 'mesaj', wYuzde: 'yuzde', wXal: 'xal', wYode: 'yode', wKod: 'indirim_kodu', wAd: 'ad' };
    if (harita[t.id]) { w[harita[t.id]] = t.value; if (['wMesaj', 'wYuzde', 'wXal', 'wYode'].includes(t.id)) onizle(); }
  });
  ov.querySelectorAll('[data-yer]').forEach((btn) => btn.addEventListener('click', () => {
    const ta = el('#wMesaj', ov);
    const [bs, bt] = [ta.selectionStart ?? ta.value.length, ta.selectionEnd ?? ta.value.length];
    ta.value = ta.value.slice(0, bs) + btn.dataset.yer + ta.value.slice(bt);
    ta.focus(); ta.selectionStart = ta.selectionEnd = bs + btn.dataset.yer.length;
    w.mesaj = ta.value; onizle();
  }));

  el('#wKaydet', ov).onclick = (e) => {
    w.baslangic_tarihi = el('#wBas', ov).value; w.bitis_tarihi = el('#wBit', ov).value; w.gonderim_saati = el('#wSaat', ov).value;
    if (!w.mesaj.trim()) return mesaj('Mesaj metni boş olamaz; şablon seçin ya da yazın.', 'hata');
    if (el('#wSayac', ov).dataset.toplam === '0') return mesaj('Seçilen hedef kitlede müşteri yok.', 'hata');
    kilitle(e.currentTarget, 'Kaydediliyor…', async () => {
      const govde = {
        gorev_turu: w.gorev_turu, ad: w.ad.trim() || null, ...hedefGovdesi(), hedef_yenile: w.hedef_yenile,
        hizmet_urun_paket: w.hizmet_urun_paket || null, sablon_id: w.sablon_id || null, mesaj: w.mesaj,
        indirim_kodu: w.indirim_kodu.trim() || null, ...indirimGovdesi(),
        baslangic_tarihi: w.baslangic_tarihi, bitis_tarihi: w.bitis_tarihi, gonderim_saati: w.gonderim_saati,
      };
      const r = await b.yaz(kayit ? 'PATCH' : 'POST', kayit ? `/kampanya/${kayit.id}` : '/kampanya', govde);
      if (!yazSonuc(r, r.govde?.mesaj)) return;
      modalKapat();
      tablo.yenile(!kayit);
    });
  };

  gorunurluk();
  kitleSay();
  onizle();
}

// ─────────────────────────── başlat ───────────────────────────

async function formVerisiTazele() {
  const r = await b.oku('/kampanya/form-verisi');
  if (r.ok && r.govde?.basarili) b.fv = r.govde.veri;
  else mesaj(r.govde?.mesaj || 'Form verisi alınamadı', 'hata');
}

(async function baslat() {
  if (window.rcPanel && b.salonId) window.rcPanel.salonAyarla(b.salonId);
  if (b.salonId) await formVerisiTazele();
  if (!b.fv.kanallar.some((k) => String(k.kod) === kanal)) kanal = '';
  iskeletCiz();
  tablo.yenile();
  const ac = parseInt(qp.get('rapor'), 10);
  if (ac) (await import('/js/reklam/rapor.js?v=1')).reklamRaporu(ac, { onDegisti: () => tablo.yenile(false) });
})();
