/**
 * Satış & Tahsilat Düzenleme (v2) — build'siz island (sade ES module).
 *
 * Modern CRM düzeni: SOL kalemler + ekleme · SAĞ ödeme/özet · ALT yapılan tahsilatlar.
 * /api/v2 uçlarını Bearer token ile tüketir (web + ileride Flutter AYNI uçlar):
 *   - Adisyon özeti + kalemleri HER DURUMDA (kapalı dahil) adıyla listeler.
 *   - Katalogdan hizmet/ürün/paket EKLE, ürün ADEDİ (kaybolmayan input), kalem SİL.
 *   - Tahsil Et: Önizle (dry-run) → Onayla (gerçek; flag kapalıysa 409).
 *
 * Token: localStorage['rc_access_token'] (pilotta ?token=). Panel kabuğu (panel.css /
 * panel-kabuk.js) menü+header'ı sağlar; bu betik yalnızca içerik alanını yönetir.
 */

const BASE = '/api/v2';
const _qp = new URLSearchParams(location.search);
if (_qp.get('token')) localStorage.setItem('rc_access_token', _qp.get('token'));
const token = () => localStorage.getItem('rc_access_token') || '';

async function api(method, yol, body) {
  const r = await fetch(BASE + yol, {
    method,
    cache: 'no-store', // bayat GET (ekleme sonrası eski toplam) önlemek için
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: 'Bearer ' + token() },
    body: body ? JSON.stringify(body) : undefined,
  });
  const t = await r.text();
  return { ok: r.ok, kod: r.status, govde: t ? JSON.parse(t) : null };
}

const TL = (n) => (Number(n) || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺';
const el = (s) => document.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'id-' + Date.now() + '-' + Math.random().toString(16).slice(2));

const kok = document.getElementById('rc-tahsilat');
const adisyonId = parseInt(kok.dataset.adisyon, 10);
let adisyon = null;
let katalog = null;
let salonId = null;
let tutarManuel = false; // kullanıcı Tahsilat Tutarı'na elle yazdı mı? (yazdıysa Kalan'la ezme)

function mesaj(metin, tip = 'bilgi') {
  // Modern toast (shell) varsa onu kullan; yoksa panel içine düş.
  if (window.rcPanel && window.rcPanel.toast) { window.rcPanel.toast(metin, tip); return; }
  el('#t-mesaj').innerHTML = metin ? `<div class="t-msg ${tip}">${esc(metin)}</div>` : '';
}

async function adisyonYukle() {
  const { ok, kod, govde } = await api('GET', '/adisyon/' + adisyonId);
  if (!ok) { mesaj(`Adisyon yüklenemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  adisyon = govde.veri;
  salonId = adisyon.adisyon.salon_id;
  // Panel kabuğunu bu adisyonun salonuna göre tazele (header: salon adı / lisans / menü yetkisi).
  if (window.rcPanel && salonId) window.rcPanel.salonAyarla(salonId);
  basligiGoster();
  ozetGoster();
  kalemlerGoster();
  gecmisGoster();
  alacakGoster();
  if (!katalog) katalogYukle();
  // Tahsilat Tutarı, güncel Kalan'ı takip eder (kalem ekle/sil sonrası canlı güncellenir);
  // kullanıcı kutuya elle yazmaya başlayınca (kısmi ödeme) müdahale etmeyiz.
  const h = adisyon.hesap;
  if (!tutarManuel) el('#t-tutar').value = h.kalan > 0 ? h.kalan : '';
}

async function katalogYukle() {
  const { ok, govde } = await api('GET', '/katalog?salon_id=' + salonId);
  if (ok) { katalog = govde.veri; }
}

function basligiGoster() {
  const a = adisyon.adisyon;
  el('#t-musteri').textContent = a.musteri_ad || 'Müşteri';
  el('#t-adisyon-no').textContent = '#' + adisyonId + (a.tarih ? ' · ' + a.tarih : '');
  const kapali = adisyon.hesap.durum === 'kapali';
  const d = el('#t-durum');
  d.textContent = kapali ? 'KAPALI' : 'AKTİF';
  d.className = 't-durum ' + (kapali ? 'kapali' : 'acik');
}

function ozetGoster() {
  const h = adisyon.hesap;
  const kapali = h.durum === 'kapali';
  el('#t-ozet-serit').innerHTML = `
    <div class="t-cip"><span>Ara Toplam</span><b>${TL(h.brut ?? h.net)}</b></div>
    <div class="t-cip"><span>İndirim</span><b>${TL(h.indirim || 0)}</b></div>
    <div class="t-cip vurgu-yesil"><span>Ödenen</span><b>${TL(h.odenen)}</b></div>
    <div class="t-cip ${kapali ? 'vurgu-yesil' : 'vurgu-kirmizi'}"><span>Kalan (Alacak)</span><b>${TL(h.kalan)}</b></div>`;
  const buyuk = el('#t-kalan-buyuk');
  buyuk.textContent = TL(h.kalan);
  buyuk.className = kapali ? 'kapali' : '';
  el('#t-ozet-detay').innerHTML = `
    <div class="sat"><span>Ara Toplam</span><b>${TL(h.brut ?? h.net)}</b></div>
    <div class="sat"><span>İndirim</span><b>${TL(h.indirim || 0)}</b></div>
    <div class="sat"><span>Net Tutar</span><b>${TL(h.net)}</b></div>
    <div class="sat"><span>Ödenen</span><b style="color:#16a34a">${TL(h.odenen)}</b></div>
    <div class="sat"><span>Kalan</span><b style="color:${kapali ? '#16a34a' : '#dc2626'}">${TL(h.kalan)}</b></div>`;
}

function kalemlerGoster() {
  const v = adisyon;
  const rows = [];
  (v.hizmetler || []).forEach((h) => rows.push(satir('hizmet', h.id, h.ad || 'Hizmet #' + h.hizmet_id, h.seans_sayisi ? h.seans_sayisi + ' seans' : '', h.fiyat, h.indirim_tutari, null)));
  (v.paketler || []).forEach((p) => rows.push(satir('paket', p.id, p.ad || 'Paket #' + p.paket_id, '', p.fiyat, p.indirim_tutari, null)));
  (v.urunler || []).forEach((u) => rows.push(satir('urun', u.id, u.ad || 'Ürün #' + u.urun_id, '', u.fiyat, u.indirim_tutari, u.adet)));
  el('#t-kalemler').innerHTML = rows.length
    ? `<table class="t-tbl"><thead><tr><th>Kalem</th><th>Tür</th><th class="r">Fiyat</th><th class="r">İndirim</th><th></th></tr></thead><tbody>${rows.join('')}</tbody></table>`
    : '<div class="t-bos">Henüz kalem yok. Yukarıdan hizmet, ürün veya paket ekleyin.</div>';
  kalemOlaylariBagla();
}

function satir(tip, id, ad, ek, fiyat, indirim, adet) {
  const rozet = `<span class="t-rozet ${tip}">${tip === 'hizmet' ? 'Hizmet' : tip === 'urun' ? 'Ürün' : 'Paket'}</span>${ek ? ' <small style="color:#9aa0bd">' + esc(ek) + '</small>' : ''}`;
  const tur = tip === 'urun'
    ? `${rozet}<br><input type="number" min="1" value="${adet || 1}" class="t-adet-inp" data-id="${id}"> adet`
    : rozet;
  return `<tr>
    <td><span class="t-kalem-ad">${esc(ad)}</span></td>
    <td>${tur}</td>
    <td class="r">${TL(fiyat)}</td>
    <td class="r">${TL(indirim || 0)}</td>
    <td class="r"><button class="t-sil" data-tip="${tip}" data-id="${id}" title="Kalemi sil"><i class="bi bi-trash"></i></button></td></tr>`;
}

function kalemOlaylariBagla() {
  document.querySelectorAll('.t-sil').forEach((b) => (b.onclick = () => kalemSil(b.dataset.tip, b.dataset.id)));
  document.querySelectorAll('.t-adet-inp').forEach((inp) => {
    let zaman;
    inp.oninput = () => {
      clearTimeout(zaman);
      const deger = inp.value;
      zaman = setTimeout(() => adetDegistir(inp.dataset.id, deger), 600);
    };
  });
}

function gecmisGoster() {
  const t = adisyon.tahsilatlar || [];
  el('#t-gecmis').innerHTML = t.length
    ? `<table class="t-tbl"><thead><tr><th>Tarih</th><th>Ödeme</th><th class="r">Tutar</th></tr></thead><tbody>${
        t.map((o) => `<tr><td>${esc(o.odeme_tarihi || '-')}</td><td><i class="bi bi-cash-coin t-odeme-ikon"></i>Tahsilat</td><td class="r" style="color:#16a34a;font-weight:700">${TL(o.tutar)}</td></tr>`).join('')
      }</tbody></table>`
    : '<div class="t-bos">Henüz tahsilat yapılmamış.</div>';
}

/* ───────────── Planlanan alacaklar ───────────── */
function alacakGoster() {
  const a = adisyon.alacaklar || [];
  el('#t-alacaklar').innerHTML = a.length
    ? `<table class="t-tbl"><thead><tr><th>Vade</th><th>Not</th><th class="r">Tutar</th><th></th></tr></thead><tbody>${
        a.map((x) => `<tr>
          <td><i class="bi bi-calendar-event" style="color:#6b7194;margin-right:6px"></i>${esc(x.planlanan_odeme_tarihi || '-')}</td>
          <td>${esc(x.aciklama || '')}</td>
          <td class="r" style="font-weight:700">${TL(x.tutar)}</td>
          <td class="r"><button class="t-sil" data-alacak="${x.id}" title="Alacağı iptal et"><i class="bi bi-trash"></i></button></td></tr>`).join('')
      }</tbody></table>`
    : '<div class="t-bos">Planlanan alacak yok.</div>';
  document.querySelectorAll('#t-alacaklar [data-alacak]').forEach((b) => (b.onclick = () => alacakSil(b.dataset.alacak)));
}

function alacakFormAc() {
  const f = el('#t-alacak-form');
  f.hidden = !f.hidden;
  if (!f.hidden) {
    const kalan = adisyon?.hesap?.kalan || 0;
    if (!el('#t-alacak-tutar').value) el('#t-alacak-tutar').value = kalan > 0 ? kalan : '';
    if (!el('#t-alacak-tarih').value) el('#t-alacak-tarih').value = new Date().toISOString().slice(0, 10);
  }
}

async function alacakOlustur() {
  const tutar = parseFloat(el('#t-alacak-tutar').value || '0');
  if (!(tutar > 0)) { mesaj('Alacak tutarı girin', 'hata'); return; }
  const body = {
    salon_id: salonId, adisyon_id: adisyonId, tutar,
    planlanan_odeme_tarihi: el('#t-alacak-tarih').value || null,
    aciklama: el('#t-alacak-not').value || null,
  };
  const { ok, kod, govde } = await api('POST', '/alacak', body);
  if (!ok) { mesaj(`Alacak oluşturulamadı (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  mesaj('Alacak oluşturuldu.', 'ok');
  el('#t-alacak-form').hidden = true;
  el('#t-alacak-not').value = '';
  await adisyonYukle();
}

async function alacakSil(alacakId) {
  const onaylandi = window.rcPanel && window.rcPanel.onay
    ? await window.rcPanel.onay('Bu planlanan alacağı iptal etmek istediğinize emin misiniz?', { baslik: 'Alacağı İptal Et', tamam: 'İptal Et' })
    : confirm('Alacağı iptal etmek istediğinize emin misiniz?');
  if (!onaylandi) return;
  const { ok, kod, govde } = await api('DELETE', '/alacak/' + alacakId);
  if (!ok) { mesaj(`Alacak iptal edilemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  mesaj('Alacak iptal edildi.', 'ok');
  await adisyonYukle();
}

/* ───────────── Kalem ekleme paneli ───────────── */
function ekleAc(tip) {
  if (!katalog) { mesaj('Katalog yükleniyor, tekrar deneyin.', 'bilgi'); katalogYukle(); return; }
  const panel = el('#t-ekle-panel');
  panel.hidden = false;
  el('#t-ekle-tip').value = tip;
  katalogDoldur(tip);
  el('#t-ekle-adet-kutu').style.display = tip === 'urun' ? '' : 'none';
  el('#t-ekle-baslik').textContent = tip === 'hizmet' ? 'Hizmet Ekle' : tip === 'urun' ? 'Ürün Ekle' : 'Paket Ekle';
  el('#t-ekle-katalog').focus();
}

function katalogDoldur(tip) {
  const liste = tip === 'hizmet' ? katalog.hizmetler : tip === 'urun' ? katalog.urunler : katalog.paketler;
  const idAlan = tip === 'hizmet' ? 'hizmet_id' : tip === 'urun' ? 'urun_id' : 'paket_id';
  el('#t-ekle-katalog').innerHTML = (liste || []).map((x) => `<option value="${x[idAlan]}">${esc(x.ad)} — ${TL(x.fiyat)}</option>`).join('');
}

async function kalemEkle() {
  const tip = el('#t-ekle-tip').value;
  const katalogId = parseInt(el('#t-ekle-katalog').value, 10);
  if (!katalogId) { mesaj('Katalogdan seçim yapın', 'hata'); return; }
  const body = { tip, katalog_id: katalogId };
  if (tip === 'urun') body.adet = parseInt(el('#t-ekle-adet').value || '1', 10);
  const { ok, kod, govde } = await api('POST', '/adisyon/' + adisyonId + '/kalem', body);
  if (!ok) { mesaj(`Kalem eklenemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  mesaj('Kalem eklendi.', 'ok');
  el('#t-ekle-panel').hidden = true;
  await adisyonYukle();
}

async function adetDegistir(kalemId, adet) {
  const { ok, kod, govde } = await api('PATCH', '/kalem/urun/' + kalemId, { adet: parseInt(adet, 10) });
  if (!ok) { mesaj(`Adet güncellenemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  mesaj('Adet güncellendi.', 'ok');
  await adisyonYukle();
}

async function kalemSil(tip, kalemId) {
  const onaylandi = window.rcPanel && window.rcPanel.onay
    ? await window.rcPanel.onay('Bu kalemi adisyondan silmek istediğinize emin misiniz?', { baslik: 'Kalemi Sil', tamam: 'Sil', iptal: 'Vazgeç' })
    : confirm('Kalemi silmek istediğinize emin misiniz?');
  if (!onaylandi) return;
  const { ok, kod, govde } = await api('DELETE', '/kalem/' + tip + '/' + kalemId);
  if (!ok) { mesaj(`Kalem silinemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  mesaj('Kalem silindi.', 'ok');
  await adisyonYukle();
}

/* ───────────── Tahsilat (ödeme) ───────────── */
function girdi(dryRun) {
  return {
    salon_id: salonId, adisyon_id: adisyonId,
    tutar: parseFloat(el('#t-tutar').value || '0'),
    indirim: parseFloat(el('#t-indirim').value || '0'),
    odeme_yontemi_id: parseInt(el('#t-yontem').value, 10),
    odeme_tarihi: el('#t-tarih').value,
    notlar: el('#t-not').value || null,
    dry_run: dryRun,
  };
}
let idemKey = null;
async function onizle() {
  el('#t-plan').innerHTML = '';
  idemKey = uuid();
  const { ok, kod, govde } = await api('POST', '/tahsilat', girdi(true));
  if (!ok) { mesaj(`Önizleme hatası (${kod}): ${govde?.mesaj || ''}`, 'hata'); return; }
  mesaj('Önizleme hazır — kontrol edip onaylayın.', 'bilgi');
  const p = govde.veri;
  const sat = (p.kalem_tahsilatlari || []).map((k) => `<li>${esc(k.tip)} #${k.adisyon_hizmet_id || k.adisyon_urun_id || k.adisyon_paket_id} → <b>${TL(k.tutar)}</b></li>`).join('');
  const ind = (p.indirim_uygulamalari || []).map((i) => `<li>indirim ${esc(i.tip)} → ${TL(i.delta)}</li>`).join('');
  const uy = (p.uyarilar || []).map((u) => `<li style="color:#b00020">${esc(u)}</li>`).join('');
  el('#t-plan').innerHTML = `<div class="t-plan"><h4>Önizleme (henüz yazılmadı)</h4>
    <div>Ödeme: <b>${TL(p.parent_tahsilat?.tutar)}</b></div>
    <div style="margin-top:6px">Kalemlere dağıtım:</div><ul>${sat || '<li>-</li>'}</ul>
    ${ind ? '<div>İndirim:</div><ul>' + ind + '</ul>' : ''}${uy ? '<div>Uyarılar:</div><ul>' + uy + '</ul>' : ''}
    <button id="t-onayla" class="t-btn yesil">Onayla ve Kaydet</button></div>`;
  el('#t-onayla').onclick = kaydet;
}
async function kaydet() {
  el('#t-onayla').disabled = true;
  const g = girdi(false); g.idempotency_key = idemKey || uuid();
  const { ok, kod, govde } = await api('POST', '/tahsilat', g);
  if (!ok) { mesaj(`Kaydedilemedi (${kod}): ${govde?.mesaj || ''}`, 'hata'); el('#t-onayla').disabled = false; return; }
  mesaj('Tahsilat kaydedildi' + (govde.veri?.replay ? ' (zaten işlenmişti)' : '') + '. Tahsilat #' + (govde.veri?.tahsilat_id ?? '-'), 'ok');
  el('#t-plan').innerHTML = '';
  tutarManuel = false; // yeni işlem sonrası Tahsilat Tutarı güncel Kalan'a dönsün
  await adisyonYukle();
}

function iskelet() {
  kok.innerHTML = `
    <div class="t-baslik">
      <div>
        <h1>Satış &amp; Tahsilat Düzenleme</h1>
        <div class="t-alt"><b id="t-musteri">…</b> <span id="t-adisyon-no"></span></div>
      </div>
      <span class="t-durum" id="t-durum"></span>
    </div>

    <div class="t-ozet-serit" id="t-ozet-serit"></div>

    <div class="t-kolon">
      <!-- SOL: kalemler + ekleme -->
      <section class="t-kart t-sol">
        <div class="t-kart-bas">
          <h3>Kalemler</h3>
          <div class="t-ekle-btns">
            <button class="t-mini mor"     data-tip="hizmet">+ Hizmet</button>
            <button class="t-mini kirmizi" data-tip="urun">+ Ürün</button>
            <button class="t-mini koyu"    data-tip="paket">+ Paket</button>
          </div>
        </div>
        <div class="t-ekle-panel" id="t-ekle-panel" hidden>
          <label id="t-ekle-baslik">Kalem Ekle</label>
          <div class="t-ekle-satir">
            <select id="t-ekle-tip" style="display:none"><option value="hizmet">Hizmet</option><option value="urun">Ürün</option><option value="paket">Paket</option></select>
            <select id="t-ekle-katalog" class="t-katalog"></select>
            <span id="t-ekle-adet-kutu"><input id="t-ekle-adet" class="t-adet" type="number" min="1" value="1" title="Adet"></span>
            <button id="t-ekle-btn" class="t-mini mor">Ekle</button>
            <button id="t-ekle-iptal" class="t-mini" style="background:#8a90ad">Vazgeç</button>
          </div>
        </div>
        <div class="t-kart-govde" id="t-kalemler"></div>
      </section>

      <!-- SAĞ: ödeme / özet -->
      <aside class="t-kart t-sag">
        <div class="t-odeme-bas">Ödeme</div>
        <div class="t-alacak"><span>Kalan Alacak (₺)</span><b id="t-kalan-buyuk">—</b></div>
        <div class="t-ozet-detay" id="t-ozet-detay"></div>
        <div class="t-form">
          <div class="grid">
            <div><label>Tahsilat Tutarı (₺)</label><input id="t-tutar" type="number" step="0.01" min="0"></div>
            <div><label>İndirim (₺)</label><input id="t-indirim" type="number" step="0.01" min="0" value="0"></div>
            <div><label>Ödeme Yöntemi</label><select id="t-yontem"><option value="1">Nakit</option><option value="2">Kredi Kartı</option><option value="3">Havale/EFT</option></select></div>
            <div><label>Tarih</label><input id="t-tarih" type="date"></div>
          </div>
          <label>Not (opsiyonel)</label>
          <textarea id="t-not" rows="2"></textarea>
          <button id="t-onizle" class="t-btn mor">Tahsil Et (Önizle)</button>

          <!-- Alacak (vade) oluşturma -->
          <button id="t-alacak-ac" class="t-btn ikincil"><i class="bi bi-wallet2"></i> Alacak (Vade) Oluştur</button>
          <div id="t-alacak-form" class="t-alacak-form" hidden>
            <div class="grid">
              <div><label>Alacak Tutarı (₺)</label><input id="t-alacak-tutar" type="number" step="0.01" min="0"></div>
              <div><label>Planlanan Ödeme Tarihi</label><input id="t-alacak-tarih" type="date"></div>
            </div>
            <label>Not (opsiyonel)</label>
            <input id="t-alacak-not" type="text" placeholder="örn. 2. taksit">
            <button id="t-alacak-kaydet" class="t-btn mor">Alacağı Kaydet</button>
          </div>
        </div>
        <div class="t-mesaj" id="t-mesaj"></div>
        <div id="t-plan"></div>
      </aside>
    </div>

    <!-- ALT: yapılan tahsilatlar + planlanan alacaklar -->
    <div class="t-kolon t-alt-kolon">
      <section class="t-kart t-gecmis">
        <div class="t-kart-bas"><h3>Yapılan Tahsilatlar</h3></div>
        <div class="t-kart-govde" id="t-gecmis"></div>
      </section>
      <section class="t-kart t-alacaklar">
        <div class="t-kart-bas"><h3>Planlanan Alacaklar</h3></div>
        <div class="t-kart-govde" id="t-alacaklar"></div>
      </section>
    </div>`;

  el('#t-tarih').value = new Date().toISOString().slice(0, 10);
  el('#t-tutar').addEventListener('input', () => { tutarManuel = true; }); // elle yazınca Kalan'la ezme
  el('#t-onizle').onclick = onizle;
  el('#t-ekle-btn').onclick = kalemEkle;
  el('#t-ekle-iptal').onclick = () => { el('#t-ekle-panel').hidden = true; };
  document.querySelectorAll('.t-ekle-btns [data-tip]').forEach((b) => (b.onclick = () => ekleAc(b.dataset.tip)));
  el('#t-alacak-ac').onclick = alacakFormAc;
  el('#t-alacak-kaydet').onclick = alacakOlustur;
}

iskelet();
adisyonYukle();
