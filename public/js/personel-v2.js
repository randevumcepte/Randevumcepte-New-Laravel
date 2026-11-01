/**
 * Personel Yönetimi (v2) — build'siz island (sade ES module).
 *
 * Eski panel "Personeller" ekranının v2 karşılığı. Veri /api/v2/personel* ve
 * /api/v2/personel-yetki/* uçlarından Bearer token ile çekilir (web + Flutter AYNI
 * uç → TEK KAYNAK). Liste (arama + sayfalama), satır aksiyonları (aktif/pasif,
 * sıralama, takvim görünürlüğü, arşivle), ekle/düzenle modalı (eski "Personel
 * Bilgileri" modalının tasarımıyla: bölüm kartları, Çalışma|Mola iki kolon, Prim &
 * Hak Ediş + kaleme göre prim) ve yetki editörü modalı.
 *
 * Yazma uçları PERSONEL_V2_YAZMA_AKTIF kapalıyken 409 döner; UI bunu yakalayıp toast
 * gösterir. Token: localStorage['rc_access_token'] (pilotta ?token=). salon_id
 * data-salon (blade) ya da ?sube= query'sinden.
 */

import { qp, api, el, esc, mesaj, onay, yazSonuc, sayfalamaCiz } from '/js/lib/v2-ortak.js?v=1';
import { ozetKart } from '/js/lib/v2-bilesen.js?v=1';

// Hesap turleri + cinsiyetler API'den (GET /personel/form-verisi) — UI'da sabit liste yok.
let formVeri = { hesap_turleri: [], cinsiyetler: [] };
const rolAd = (id) => (formVeri.hesap_turleri.find((r) => String(r.id) === String(id)) || {}).ad || ('#' + id);
const GUNLER = ['', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
const CAP = { hizmet: 'Hizmet', urun: 'Urun', paket: 'Paket' };

const kok = document.getElementById('rc-personel');
let salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;

const durum = { baslik: '', page: 1 };
let benim = null;   // giriş yapanın kendi yetkileri (buton gate'i için)
let sema = null;    // yetki şeması (tanimlar + kategoriler + sablonlar) — bir kez yüklenir
let formDuzenleme = false; // prim kalem boş-durum mesajı için

function yetkiVar(key) {
  if (!benim || !benim.ayarlar) return true;
  return benim.ayarlar[key] === true;
}

// ─────────────────────────── İSKELET ───────────────────────────

function iskeletCiz() {
  const yeniBtn = yetkiVar('personel.ekle_duzenle')
    ? `<button class="p-yeni-btn" id="pYeni"><i class="bi bi-person-plus-fill"></i> Yeni Personel</button>` : '';

  kok.innerHTML = `
    <div class="p-kabuk">
      <div class="p-ust">
        <div class="p-baslik">
          <span class="p-baslik-ikon"><i class="bi bi-person-lines-fill"></i></span>
          <div>
            <h1>Personeller</h1>
            <div class="p-alt">Personel listesi, yetkiler ve çalışma saatleri</div>
          </div>
        </div>
        ${yeniBtn}
      </div>

      <div class="rc-ozet" id="pOzet"></div>

      <div class="p-filtre-kart">
        <div class="p-ara">
          <i class="bi bi-search"></i>
          <input type="search" id="pAra" placeholder="Personel adı ara…" autocomplete="off">
        </div>
      </div>

      <div class="p-tablo-sar">
        <table class="p-tablo">
          <thead>
            <tr>
              <th>Personel</th><th>Telefon</th><th>Hesap Türü</th>
              <th class="p-sag">Maaş</th><th class="p-orta">Takvimde</th>
              <th class="p-orta">Durum</th><th class="p-orta">İşlemler</th>
            </tr>
          </thead>
          <tbody id="pGovde"><tr><td colspan="7" class="p-bos">Yükleniyor…</td></tr></tbody>
        </table>
      </div>

      <div class="rc-sayfalama" id="pSayfalama"></div>
    </div>`;

  if (el('#pYeni')) el('#pYeni').addEventListener('click', () => formAc(null));

  let aramaZaman;
  el('#pAra').addEventListener('input', (e) => {
    clearTimeout(aramaZaman);
    const v = e.target.value.trim();
    aramaZaman = setTimeout(() => { durum.baslik = v; durum.page = 1; yukle(); }, 350);
  });

  el('#pGovde').addEventListener('click', satirTiklama);
  el('#pGovde').addEventListener('change', satirDegisim);
}

// ─────────────────────────── LİSTE ───────────────────────────

function satirCiz(p, idx, toplam) {
  const avatar = p.profil_resmi
    ? `<img class="p-avatar" src="${esc(p.profil_resmi)}" alt="" onerror="this.style.visibility='hidden'">`
    : `<span class="p-avatar"></span>`;
  const rol = p.hesap_turu ? `<span class="p-rol">${esc(rolAd(p.hesap_turu))}</span>` : '<span class="p-rol">—</span>';
  const maas = p.maas === null || p.maas === undefined ? '<span style="color:#b0b0c0">••••</span>' : esc(p.maas) + ' ₺';
  const duzenleY = yetkiVar('personel.ekle_duzenle');
  const yetkiY = yetkiVar('personel.yetki_yonet');
  const silY = yetkiVar('personel.sil');

  const sira = duzenleY ? `
    <span class="p-sira">
      <button data-aksiyon="yukari" data-id="${p.id}" ${idx === 0 ? 'disabled' : ''} title="Yukarı"><i class="bi bi-caret-up-fill"></i></button>
      <button data-aksiyon="asagi" data-id="${p.id}" ${idx === toplam - 1 ? 'disabled' : ''} title="Aşağı"><i class="bi bi-caret-down-fill"></i></button>
    </span>` : '';

  const takvim = duzenleY
    ? `<label class="p-switch"><input type="checkbox" data-aksiyon="takvim" data-id="${p.id}" ${p.takvimde_gorunsun ? 'checked' : ''}><span class="p-kaydir"></span></label>`
    : (p.takvimde_gorunsun ? '<i class="bi bi-check-lg" style="color:#16a34a"></i>' : '<i class="bi bi-x-lg" style="color:#c0392b"></i>');

  const durumHuc = duzenleY
    ? `<label class="p-switch"><input type="checkbox" data-aksiyon="aktif" data-id="${p.id}" ${p.aktif ? 'checked' : ''}><span class="p-kaydir"></span></label>`
    : (p.aktif ? '<span class="p-rozet p-r-aktif">Aktif</span>' : '<span class="p-rozet p-r-pasif">Pasif</span>');

  const islemler =
    (duzenleY ? `<button class="p-islem p-islem-duzenle" data-aksiyon="duzenle" data-id="${p.id}"><i class="bi bi-pencil"></i> Düzenle</button>` : '') +
    (duzenleY ? `<button class="p-islem p-islem-yetki" data-aksiyon="sifre" data-id="${p.id}" title="Yeni giriş şifresi gönder"><i class="bi bi-key"></i> Şifre</button>` : '') +
    (yetkiY ? `<button class="p-islem p-islem-yetki" data-aksiyon="yetki" data-id="${p.id}"><i class="bi bi-shield-lock"></i> Yetki</button>` : '') +
    (silY ? `<button class="p-islem p-islem-arsiv" data-aksiyon="arsiv" data-id="${p.id}"><i class="bi bi-archive"></i> Arşivle</button>` : '');

  return `<tr data-id="${p.id}">
    <td><div class="p-kisi">${sira}${avatar}<div><div class="p-kisi-ad">${esc(p.personel_adi || '—')}</div>${p.unvan ? `<div class="p-kisi-unvan">${esc(p.unvan)}</div>` : ''}</div></div></td>
    <td class="p-nowrap">${esc(p.cep_telefon || '—')}</td>
    <td>${rol}</td>
    <td class="p-sag">${maas}</td>
    <td class="p-orta">${takvim}</td>
    <td class="p-orta">${durumHuc}</td>
    <td class="p-orta">${islemler || '—'}</td>
  </tr>`;
}

let sonListe = [];
async function yukle() {
  if (!salonId) {
    el('#pGovde').innerHTML = `<tr><td colspan="7" class="p-bos">Şube seçili değil (?sube=... bekleniyor).</td></tr>`;
    return;
  }
  el('#pGovde').innerHTML = `<tr><td colspan="7" class="p-bos"><span class="rc-yukleniyor"></span> Yükleniyor…</td></tr>`;
  const { ok, kod, govde } = await api('GET', '/personel', { params: { salon_id: salonId, baslik: durum.baslik, page: durum.page } });
  if (!ok || !govde || !govde.basarili) {
    el('#pGovde').innerHTML = `<tr><td colspan="7" class="p-bos p-hata">${esc((govde && govde.mesaj) || `Hata (${kod})`)}</td></tr>`;
    return;
  }
  const v = govde.veri;
  sonListe = v.data || [];
  const o = v.ozet || {};
  el('#pOzet').innerHTML =
    ozetKart('bi-people-fill', 'Toplam Personel', o.toplam ?? 0, 'mor') +
    ozetKart('bi-check2-circle', 'Aktif', o.aktif ?? 0, 'yesil') +
    ozetKart('bi-calendar-check', 'Takvimde', o.takvimde ?? 0, 'mavi');
  el('#pGovde').innerHTML = sonListe.length
    ? sonListe.map((p, i) => satirCiz(p, i, sonListe.length)).join('')
    : `<tr><td colspan="7" class="p-bos">Personel bulunamadı.</td></tr>`;
  sayfalamaCiz(el('#pSayfalama'), v, (no) => { durum.page = no; yukle(); }, 'personel');
}

function personelBul(id) { return sonListe.find((p) => String(p.id) === String(id)); }

// ─────────────────────────── SATIR AKSİYONLARI ───────────────────────────

async function satirTiklama(e) {
  const btn = e.target.closest('[data-aksiyon]');
  if (!btn || btn.tagName === 'INPUT') return;
  const id = parseInt(btn.dataset.id, 10);
  const aksiyon = btn.dataset.aksiyon;

  if (aksiyon === 'duzenle') return formAc(personelBul(id));
  if (aksiyon === 'yetki') return yetkiAc(personelBul(id));
  if (aksiyon === 'arsiv') return arsivleAkis(personelBul(id));
  if (aksiyon === 'sifre') {
    const p = personelBul(id);
    if (!(await onay(`${p?.personel_adi || 'Personel'} için yeni giriş şifresi oluşturulup WhatsApp/SMS ile gönderilsin mi?`, { baslik: 'Şifre Gönder', tamam: 'Gönder', iptal: 'Vazgeç' }))) return;
    yazSonuc(await api('POST', `/personel/${id}/sifre-gonder`), 'Yeni şifre personele gönderiliyor.');
    return;
  }
  if (aksiyon === 'yukari' || aksiyon === 'asagi') {
    btn.disabled = true;
    const r = await api('PATCH', `/personel/${id}/siralama`, { body: { delta: aksiyon === 'yukari' ? -1 : 1 } });
    if (yazSonuc(r)) yukle(); else btn.disabled = false;
  }
}

async function satirDegisim(e) {
  const inp = e.target.closest('input[data-aksiyon]');
  if (!inp) return;
  const id = parseInt(inp.dataset.id, 10);
  if (inp.dataset.aksiyon === 'aktif') {
    const r = await api('PATCH', `/personel/${id}/durum`, { body: { aktif: inp.checked } });
    if (!yazSonuc(r, inp.checked ? 'Personel aktife alındı.' : 'Personel pasife alındı.')) inp.checked = !inp.checked;
  } else if (inp.dataset.aksiyon === 'takvim') {
    const r = await api('PATCH', `/personel/${id}/takvim-gorunur`, { body: {} });
    if (yazSonuc(r)) { const p = personelBul(id); if (p) p.takvimde_gorunsun = r.govde.veri.takvimde_gorunsun; }
    else inp.checked = !inp.checked;
  }
}

// ─────────────────────────── MODAL ───────────────────────────

function modalAc(baslik, govdeHtml, altHtml, opt = {}) {
  modalKapat();
  const ov = document.createElement('div');
  ov.className = 'p-modal-overlay';
  ov.id = 'pModal';
  const altb = opt.altbaslik ? `<div class="p-modal-altbaslik">${esc(opt.altbaslik)}</div>` : '';
  ov.innerHTML = `
    <div class="p-modal${opt.dar ? ' p-dar' : ''}" role="dialog">
      <div class="p-modal-bas">
        <div class="p-modal-bas-ic">
          <span class="p-modal-ikon"><i class="bi bi-${esc(opt.ikon || 'person')}"></i></span>
          <div><h2>${esc(baslik)}</h2>${altb}</div>
        </div>
        <button class="p-kapat" id="pModalKapat" aria-label="Kapat">&times;</button>
      </div>
      <div class="p-modal-govde">${govdeHtml}</div>
      ${altHtml ? `<div class="p-modal-alt">${altHtml}</div>` : ''}
    </div>`;
  document.body.appendChild(ov);
  ov.addEventListener('click', (e) => { if (e.target === ov) modalKapat(); });
  el('#pModalKapat').addEventListener('click', modalKapat);
  return ov;
}
function modalKapat() { const m = el('#pModal'); if (m) m.remove(); }
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') modalKapat(); });

// ─────────────────────────── EKLE / DÜZENLE ───────────────────────────

function saatSatirlari(onek, saatler) {
  let h = '';
  for (let g = 1; g <= 7; g++) {
    const s = saatler[g] || {};
    const acik = s.acik ?? (onek === 'calisma'); // çalışma default açık, mola default kapalı
    h += `
      <div class="p-saat-row${acik ? ' p-dolu' : ''}" data-row="${onek}-${g}">
        <label class="p-switch"><input type="checkbox" class="p-${onek}-acik" data-gun="${g}" ${acik ? 'checked' : ''}><span class="p-kaydir"></span></label>
        <span class="p-saat-gun">${GUNLER[g]}</span>
        <input type="time" class="p-${onek}-bas" data-gun="${g}" value="${esc((s.baslangic_saati || (onek === 'calisma' ? '09:00' : '13:00')).slice(0, 5))}">
        <input type="time" class="p-${onek}-bit" data-gun="${g}" value="${esc((s.bitis_saati || (onek === 'calisma' ? '18:00' : '14:00')).slice(0, 5))}">
      </div>`;
  }
  return h;
}

function primToggleSatiri(tur, baslik) {
  return `
    <div class="p-detay-toggle" data-tur="${tur}">
      <div class="p-detay-bilgi">
        <div class="p-detay-baslik"><i class="bi bi-list-ul"></i> ${baslik}</div>
        <div class="p-detay-hint">Açıksa genel "${baslik.replace('Primini Kaleme Göre Belirle', 'Primi %')}" pasif olur; her kaleme ayrı yüzde girersiniz.</div>
      </div>
      <label class="p-switch"><input type="checkbox" id="f${CAP[tur]}Detayli"><span class="p-kaydir"></span></label>
    </div>
    <div class="p-kalem-list" id="${tur}KalemList" style="display:none"></div>`;
}

async function formAc(p) {
  const duzenleme = !!p;
  formDuzenleme = duzenleme;
  const secenek = (liste, secili) => liste.map((x) => `<option value="${esc(x.id)}" ${secili != null && String(secili) === String(x.id) ? 'selected' : ''}>${esc(x.ad)}</option>`).join('');
  const rolOpt = secenek(formVeri.hesap_turleri, p?.hesap_turu);
  const cinsOpt = secenek(formVeri.cinsiyetler, p?.cinsiyet);

  // Düzenlemede saatler + kaleme göre prim verisi sunucudan paralel yüklenir.
  let calisma = {}, mola = {}, primVeri = null;
  if (duzenleme) {
    const [c, m, pk] = await Promise.all([
      api('GET', `/personel/${p.id}/calisma-saatleri`),
      api('GET', `/personel/${p.id}/mola-saatleri`),
      api('GET', `/personel/${p.id}/prim-kalemleri`),
    ]);
    (c.govde?.veri || []).forEach((x) => { calisma[x.haftanin_gunu] = { acik: !!x.calisiyor, baslangic_saati: x.baslangic_saati, bitis_saati: x.bitis_saati }; });
    (m.govde?.veri || []).forEach((x) => { mola[x.haftanin_gunu] = { acik: !!x.mola_var, baslangic_saati: x.baslangic_saati, bitis_saati: x.bitis_saati }; });
    if (pk.ok && pk.govde?.basarili) primVeri = pk.govde.veri;
  }

  const govde = `
    <div class="p-sec">
      <div class="p-sec-bas"><span class="p-sec-ikon p-sec-info"><i class="bi bi-person-badge"></i></span>
        <div><h3>Temel Bilgiler</h3></div></div>
      <div class="p-form-izgara">
        <div class="p-alan"><label>Ad Soyad *</label><input id="fAd" value="${esc(p?.personel_adi || '')}"></div>
        <div class="p-alan"><label>Ünvan</label><input id="fUnvan" value="${esc(p?.unvan || '')}"></div>
        <div class="p-alan"><label>Cep Telefonu</label><input id="fTel" value="${esc(p?.cep_telefon || '')}" placeholder="05XX XXX XX XX"></div>
        <div class="p-alan"><label>Cinsiyet</label><select id="fCinsiyet"><option value="">Seçiniz</option>${cinsOpt}</select></div>
        <div class="p-alan"><label>Hesap Türü *</label><select id="fRol"><option value="">Seçiniz</option>${rolOpt}</select></div>
      </div>
    </div>

    <div class="p-sec">
      <div class="p-sec-bas"><span class="p-sec-ikon p-sec-saat"><i class="bi bi-clock-fill"></i></span>
        <div><h3>Çalışma &amp; Mola Saatleri</h3><div class="p-sec-alt">Hangi gün, hangi saatler arası çalıştığını ve mola saatlerini belirleyin</div></div></div>
      <div class="p-saat-grid">
        <div>
          <div class="p-saat-kolbas p-kol-yesil"><i class="bi bi-check-circle-fill"></i> Çalışma Saatleri</div>
          ${saatSatirlari('calisma', calisma)}
        </div>
        <div>
          <div class="p-saat-kolbas p-kol-turuncu"><i class="bi bi-cup-hot-fill"></i> Mola Saatleri</div>
          ${saatSatirlari('mola', mola)}
        </div>
      </div>
    </div>

    <div class="p-sec">
      <div class="p-sec-bas"><span class="p-sec-ikon p-sec-prim"><i class="bi bi-cash-coin"></i></span>
        <div><h3>Prim &amp; Hak Ediş Ayarları</h3><div class="p-sec-alt">Aylık maaş ve tahsilat üzerinden prim yüzdeleri (Hak Ediş raporunda hesaplanır)</div></div></div>
      <div class="p-prim-grid">
        <div class="p-alan"><label>Sabit Maaş (₺)</label><input id="fMaas" type="number" step="0.01" value="${esc(p?.maas ?? '')}"></div>
        <div class="p-alan"><label>Hizmet Primi (%)</label><input id="fHizmetPrim" type="number" step="0.01" value="${esc(p?.hizmet_prim_yuzde ?? '')}"></div>
        <div class="p-alan"><label>Ürün Primi (%)</label><input id="fUrunPrim" type="number" step="0.01" value="${esc(p?.urun_prim_yuzde ?? '')}"></div>
        <div class="p-alan"><label>Paket Primi (%)</label><input id="fPaketPrim" type="number" step="0.01" value="${esc(p?.paket_prim_yuzde ?? '')}"></div>
      </div>
      ${primToggleSatiri('hizmet', 'Hizmet Primini Kaleme Göre Belirle')}
      ${primToggleSatiri('urun', 'Ürün Primini Kaleme Göre Belirle')}
      ${primToggleSatiri('paket', 'Paket Primini Kaleme Göre Belirle')}
    </div>`;

  const alt = `
    <button class="p-btn p-btn-ikincil" id="fIptal">Vazgeç</button>
    <button class="p-btn p-btn-birincil" id="fKaydet"><i class="bi bi-check-lg"></i> Kaydet</button>`;

  modalAc(duzenleme ? 'Personel Düzenle' : 'Yeni Personel', govde, alt, {
    ikon: 'person-lines-fill',
    altbaslik: 'Personel bilgilerini, çalışma saatlerini ve hak ediş ayarlarını düzenleyin',
  });

  // Çalışma/mola satır vurgusu toggle ile senkron.
  el('.p-modal-govde').addEventListener('change', (e) => {
    const sw = e.target.closest('.p-calisma-acik, .p-mola-acik');
    if (sw) sw.closest('.p-saat-row').classList.toggle('p-dolu', sw.checked);
  });

  // Kaleme göre prim toggle'ları.
  ['hizmet', 'urun', 'paket'].forEach((tur) => {
    const cb = el('#f' + CAP[tur] + 'Detayli');
    cb.addEventListener('change', () => primDetayUygula(tur, cb.checked));
  });

  if (duzenleme && primVeri) {
    primKalemRender('hizmet', primVeri.hizmetler);
    primKalemRender('urun', primVeri.urunler);
    primKalemRender('paket', primVeri.paketler);
    ['hizmet', 'urun', 'paket'].forEach((tur) => {
      const on = !!Number((primVeri.flags || {})[tur] || 0);
      el('#f' + CAP[tur] + 'Detayli').checked = on;
      primDetayUygula(tur, on);
    });
  }

  el('#fIptal').addEventListener('click', modalKapat);
  el('#fKaydet').addEventListener('click', () => formKaydet(p));
}

/** Kaleme göre liste satırlarını çiz (boşsa duruma göre mesaj). */
function primKalemRender(tur, items) {
  const list = el('#' + tur + 'KalemList');
  if (!list) return;
  if (!items || !items.length) {
    const msg = !formDuzenleme
      ? 'Personeli kaydedip "Düzenle" ile kaleme göre giriş yapabilirsiniz.'
      : (tur === 'hizmet'
        ? 'Bu personele atanmış hizmet yok. Önce hizmet atayın.'
        : 'Kayıtlı ' + (tur === 'urun' ? 'ürün' : 'paket') + ' bulunamadı.');
    list.innerHTML = `<div class="p-kalem-bos">${msg}</div>`;
    return;
  }
  list.innerHTML = items.map((it) => `
    <div class="p-kalem-row">
      <span class="p-kalem-ad">${esc(it.ad)}</span>
      <input type="number" step="0.01" min="0" max="100" class="p-kalem-yuzde" data-tur="${tur}" data-id="${it.id}" value="${it.yuzde == null ? '' : esc(it.yuzde)}" placeholder="%">
    </div>`).join('');
}

/** Toggle açık → genel % pasif + liste görünür. */
function primDetayUygula(tur, on) {
  const list = el('#' + tur + 'KalemList');
  if (list) {
    list.style.display = on ? '' : 'none';
    if (on && !list.innerHTML.trim()) primKalemRender(tur, null); // add modunda boş → mesaj
  }
  const genel = el('#f' + CAP[tur] + 'Prim');
  if (genel) {
    genel.disabled = on;
    const alan = genel.closest('.p-alan');
    if (alan) alan.classList.toggle('p-genel-pasif', on);
  }
  const satir = document.querySelector(`.p-detay-toggle[data-tur="${tur}"]`);
  if (satir) satir.classList.toggle('p-acik', on);
}

function saatToplaForm(onek, varAlan) {
  const rows = [];
  document.querySelectorAll(`.p-${onek}-acik`).forEach((inp) => {
    const g = parseInt(inp.dataset.gun, 10);
    rows.push({
      haftanin_gunu: g,
      [varAlan]: inp.checked ? 1 : 0,
      baslangic_saati: el(`.p-${onek}-bas[data-gun="${g}"]`).value || null,
      bitis_saati: el(`.p-${onek}-bit[data-gun="${g}"]`).value || null,
    });
  });
  return rows;
}

function primKalemToplaForm() {
  const pk = { hizmet: {}, urun: {}, paket: {} };
  document.querySelectorAll('.p-kalem-yuzde').forEach((inp) => {
    const v = inp.value.trim();
    if (v !== '') pk[inp.dataset.tur][inp.dataset.id] = v;
  });
  return pk;
}

async function formKaydet(p) {
  const ad = el('#fAd').value.trim();
  const rol = el('#fRol').value;
  if (!ad) { mesaj('Ad Soyad zorunlu.', 'hata'); return; }
  if (!rol) { mesaj('Hesap türü seçiniz.', 'hata'); return; }

  const body = {
    salon_id: salonId,
    personel_adi: ad,
    unvan: el('#fUnvan').value.trim(),
    cinsiyet: el('#fCinsiyet').value === '' ? null : parseInt(el('#fCinsiyet').value, 10),
    cep_telefon: el('#fTel').value.trim(),
    maas: el('#fMaas').value === '' ? null : el('#fMaas').value,
    hizmet_prim_yuzde: el('#fHizmetPrim').value === '' ? null : el('#fHizmetPrim').value,
    urun_prim_yuzde: el('#fUrunPrim').value === '' ? null : el('#fUrunPrim').value,
    paket_prim_yuzde: el('#fPaketPrim').value === '' ? null : el('#fPaketPrim').value,
    hizmet_prim_detayli: el('#fHizmetDetayli').checked,
    urun_prim_detayli: el('#fUrunDetayli').checked,
    paket_prim_detayli: el('#fPaketDetayli').checked,
    prim_kalemleri: primKalemToplaForm(),
    sistem_yetki: parseInt(rol, 10),
    calisma_saatleri: saatToplaForm('calisma', 'calisiyor'),
    mola_saatleri: saatToplaForm('mola', 'mola_var'),
  };

  const kaydetBtn = el('#fKaydet');
  kaydetBtn.disabled = true;
  const r = p
    ? await api('PATCH', `/personel/${p.id}`, { body })
    : await api('POST', '/personel', { body });

  if (yazSonuc(r, p ? 'Personel güncellendi.' : 'Personel eklendi.')) {
    modalKapat();
    if (r.govde.veri && r.govde.veri.sifre_gonderiliyor) mesaj('Giriş şifresi personele WhatsApp/SMS ile gönderiliyor.', 'bilgi');
    yukle();
  } else {
    kaydetBtn.disabled = false;
  }
}

// ─────────────────────────── YETKİ EDİTÖRÜ ───────────────────────────

async function yetkiAc(p) {
  if (!p) return;
  const istekler = [api('GET', `/personel/${p.id}/yetki`)];
  if (!sema) istekler.unshift(api('GET', '/personel-yetki/sema'));
  const sonuclar = await Promise.all(istekler);
  if (!sema) { const s = sonuclar.shift(); if (s.ok && s.govde?.basarili) sema = s.govde.veri; }
  const cur = sonuclar[0];
  if (!sema || !cur.ok || !cur.govde?.basarili) { mesaj('Yetki bilgisi alınamadı.', 'hata'); return; }

  const mevcut = cur.govde.veri;
  const ayarlar = { ...mevcut.ayarlar };
  let seciliSablon = mevcut.kayit_var ? mevcut.sablon : '';

  const sablonKart = sema.sablonlar.map((s) => `
    <div class="p-sablon${seciliSablon === s.key ? ' aktif' : ''}" data-sablon="${s.key}">
      <i class="bi bi-${esc(s.ikon || 'person')}"></i>
      <div class="p-sablon-ad">${esc(s.ad)}</div>
      <div class="p-sablon-ac">${esc(s.aciklama || '')}</div>
    </div>`).join('');

  const katGrup = {};
  sema.tanimlar.forEach((t) => { (katGrup[t.kategori] = katGrup[t.kategori] || []).push(t); });
  const katHtml = Object.entries(katGrup).map(([kat, list]) => {
    const kinfo = (sema.kategoriler && sema.kategoriler[kat]) || { ad: kat, ikon: 'folder' };
    const satirlar = list.map((t) => `
      <div class="p-yetki-sat">
        <div class="p-yetki-bilgi"><div class="p-yetki-label">${esc(t.label)}</div>${t.aciklama ? `<div class="p-yetki-ac">${esc(t.aciklama)}</div>` : ''}</div>
        <label class="p-switch"><input type="checkbox" class="p-yk" data-key="${esc(t.key)}" ${ayarlar[t.key] ? 'checked' : ''}><span class="p-kaydir"></span></label>
      </div>`).join('');
    return `<div class="p-kat"><div class="p-kat-bas"><i class="bi bi-${esc(kinfo.ikon)}"></i> ${esc(kinfo.ad)}</div>${satirlar}</div>`;
  }).join('');

  const govde = `
    <p style="margin:0 0 14px;color:#8a8a9a;font-size:13.5px"><strong>${esc(p.personel_adi)}</strong> için yetkiler. Bir şablon seçip ince ayar yapabilirsiniz.</p>
    <div class="p-sablonlar" id="ykSablonlar">${sablonKart}</div>
    <div id="ykKategoriler">${katHtml}</div>`;
  const alt = `
    <button class="p-btn p-btn-ikincil" id="ykIptal">Vazgeç</button>
    <button class="p-btn p-btn-birincil" id="ykKaydet"><i class="bi bi-shield-check"></i> Yetkileri Kaydet</button>`;

  modalAc('Yetki Yönetimi', govde, alt, { ikon: 'shield-lock', altbaslik: esc(p.personel_adi) + ' için erişim yetkileri' });

  el('#ykSablonlar').addEventListener('click', (e) => {
    const kart = e.target.closest('.p-sablon');
    if (!kart) return;
    seciliSablon = kart.dataset.sablon;
    document.querySelectorAll('.p-sablon').forEach((x) => x.classList.toggle('aktif', x === kart));
    const s = sema.sablonlar.find((x) => x.key === seciliSablon);
    if (s) document.querySelectorAll('.p-yk').forEach((inp) => { inp.checked = s.ayarlar[inp.dataset.key] === true; });
  });
  el('#ykKategoriler').addEventListener('change', () => {
    seciliSablon = 'ozel';
    document.querySelectorAll('.p-sablon').forEach((x) => x.classList.remove('aktif'));
  });

  el('#ykIptal').addEventListener('click', modalKapat);
  el('#ykKaydet').addEventListener('click', async () => {
    const yeniAyar = {};
    document.querySelectorAll('.p-yk').forEach((inp) => { yeniAyar[inp.dataset.key] = inp.checked; });
    const btn = el('#ykKaydet');
    btn.disabled = true;
    const r = await api('PATCH', `/personel/${p.id}/yetki`, { body: { sablon: seciliSablon || 'ozel', ayarlar: yeniAyar } });
    if (yazSonuc(r, 'Yetkiler kaydedildi.')) modalKapat(); else btn.disabled = false;
  });
}

// ─────────────────────────── ARŞİVLE ───────────────────────────

async function arsivleAkis(p) {
  if (!p) return;
  const gh = await api('GET', `/personel/${p.id}/gelecek-hizmetler`);
  if (!gh.ok || !gh.govde?.basarili) { mesaj((gh.govde && gh.govde.mesaj) || 'Bilgi alınamadı.', 'hata'); return; }
  const v = gh.govde.veri;

  if (!v || v.sonuc !== 'ok' || !v.count) {
    const ok = await onay(`${p.personel_adi} arşivlensin mi? Pasife alınır ve listeden gizlenir.`, { baslik: 'Personeli Arşivle', tamam: 'Arşivle', iptal: 'Vazgeç' });
    if (!ok) return;
    const r = await api('DELETE', `/personel/${p.id}`, { body: {} });
    if (yazSonuc(r, 'Personel arşivlendi.')) yukle();
    return;
  }

  const hedefOpt = (v.personeller || []).map((h) => `<option value="${h.id}">${esc(h.personel_adi)}</option>`).join('');
  const satirlar = (v.hizmetler || []).map((h) => `
    <div class="p-transfer-sat">
      <div class="p-transfer-bilgi"><strong>${esc(h.tarih)}${h.saat ? ' ' + esc(h.saat) : ''}</strong> · ${esc(h.musteri || '—')} · ${esc(h.hizmet || '—')}</div>
      <select class="p-alan p-transfer-hedef" data-rh="${h.rh_id}" style="min-width:180px"><option value="">Devralacak personel…</option>${hedefOpt}</select>
    </div>`).join('');

  const govde = `
    <div class="p-uyari"><i class="bi bi-exclamation-triangle"></i> <strong>${esc(p.personel_adi)}</strong> arşivlenmeden önce, gelecekteki <strong>${v.count}</strong> randevu hizmeti başka bir personele devredilmelidir.</div>
    ${satirlar}`;
  const alt = `
    <button class="p-btn p-btn-ikincil" id="arIptal">Vazgeç</button>
    <button class="p-btn p-btn-birincil" id="arKaydet"><i class="bi bi-archive"></i> Devret ve Arşivle</button>`;

  modalAc('Personeli Arşivle', govde, alt, { ikon: 'archive', altbaslik: 'Gelecek hizmetleri devret, sonra arşivle' });
  el('#arIptal').addEventListener('click', modalKapat);
  el('#arKaydet').addEventListener('click', async () => {
    const transferler = {};
    let eksik = false;
    document.querySelectorAll('.p-transfer-hedef').forEach((s) => {
      if (!s.value) eksik = true; else transferler[s.dataset.rh] = parseInt(s.value, 10);
    });
    if (eksik) { mesaj('Tüm hizmetler için devralacak personel seçiniz.', 'hata'); return; }
    const btn = el('#arKaydet');
    btn.disabled = true;
    const r = await api('DELETE', `/personel/${p.id}`, { body: { transferler } });
    if (r.ok && r.govde?.basarili && r.govde.veri?.sonuc === 'ok') {
      mesaj(`Personel arşivlendi (${r.govde.veri.aktarilan} hizmet aktarıldı).`, 'ok');
      modalKapat(); yukle();
    } else {
      mesaj((r.govde && r.govde.mesaj) || (r.govde?.veri?.mesaj) || `Hata (${r.kod})`, 'hata');
      btn.disabled = false;
    }
  });
}

// ─────────────────────────── BOOT ───────────────────────────

async function benimYukle() {
  if (!salonId) return;
  const { ok, govde } = await api('GET', '/personel-yetki/benim', { params: { salon_id: salonId } });
  if (ok && govde && govde.basarili) benim = govde.veri;
}

async function formVerisiYukle() {
  if (!salonId) return;
  const { ok, govde } = await api('GET', '/personel/form-verisi', { params: { salon_id: salonId } });
  if (ok && govde && govde.basarili) formVeri = govde.veri;
}

(async function boot() {
  await Promise.all([benimYukle(), formVerisiYukle()]);
  iskeletCiz();
  yukle();
})();
