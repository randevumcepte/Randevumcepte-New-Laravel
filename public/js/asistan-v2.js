/**
 * Asistanım (E-Asistan, v2) — build'siz island (sade ES module).
 *
 * Eski panel "Asistanım" ekranının v2 karşılığı: Bugünkü / Yarınki Görevler (kart
 * akışı + görev iptali) ve Ayarlar (otomatik arama aç/kapa + tekrar deneme saati).
 * Veri /api/v2/asistan/* uçlarından (web + Flutter AYNI uç). Kart metinleri, durum
 * etiketleri, iptal edilebilirlik ve ayar listesi SUNUCUDAN gelir — burada yalnız
 * görsel eşleme (tür → renk/ikon) var.
 *
 * Yazma uçları ASISTAN_V2_YAZMA_AKTIF kapalıyken 409 döner; UI toast gösterir.
 */

import { qp, api, el, esc, onay, yazSonuc, tarihStr, sayfalamaCiz } from '/js/lib/v2-ortak.js?v=2';

const kok = document.getElementById('rc-asistan');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
if (salonId && window.rcPanel) window.rcPanel.salonAyarla(salonId);

const gunSonra = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return tarihStr(d); };
const SEKMELER = {
  bugun: { tarih: gunSonra(0), bos: 'Bugün için görev yok' },
  yarin: { tarih: gunSonra(1), bos: 'Yarın için görev yok' },
};
const sayfa = { bugun: 1, yarin: 1 };
const SAYFA_BOYU = 25;

// Yalnız görsel eşleme; tür listesi/metinleri API'den.
const TUR_GORSEL = {
  randevu: 'bi-calendar-check',
  alacak: 'bi-cash-coin',
  kampanya: 'bi-megaphone',
  musteri: 'bi-person-plus',
};

// ─────────────────────────── İSKELET ───────────────────────────

function iskeletCiz() {
  const sekme = (ad, ikon, etiket, rozet = true) => `
    <button class="as-sekme" data-sekme="${ad}" role="tab">
      <i class="bi ${ikon}"></i> ${etiket}${rozet ? ` <span class="as-rozet" data-rozet="${ad}">0</span>` : ''}
    </button>`;
  const gorevPanel = (ad) => `
    <section class="as-panel" data-panel="${ad}" hidden>
      <div class="as-akis" id="asAkis-${ad}"><div class="as-bos"><span class="rc-yukleniyor"></span> Yükleniyor…</div></div>
      <div class="rc-sayfalama" id="asSayfa-${ad}"></div>
    </section>`;

  kok.innerHTML = `
    <div class="as-kabuk">
      <div class="as-ust">
        <span class="as-baslik-ikon"><i class="bi bi-card-checklist"></i></span>
        <div>
          <h1>Asistanım</h1>
          <div class="as-alt">E-asistanın yapacağı hatırlatma aramaları ve ayarları</div>
        </div>
      </div>
      <nav class="as-sekmeler" role="tablist">
        ${sekme('bugun', 'bi-calendar-check', 'Bugünkü Görevler')}
        ${sekme('yarin', 'bi-calendar', 'Yarınki Görevler')}
        ${sekme('ayarlar', 'bi-gear', 'Ayarlar', false)}
      </nav>
      ${gorevPanel('bugun')}
      ${gorevPanel('yarin')}
      <section class="as-panel" data-panel="ayarlar" hidden>
        <div id="asAyarlar"><div class="as-bos"><span class="rc-yukleniyor"></span> Yükleniyor…</div></div>
      </section>
    </div>`;

  kok.querySelector('.as-sekmeler').addEventListener('click', (e) => {
    const b = e.target.closest('.as-sekme');
    if (b) sekmeAc(b.dataset.sekme);
  });
}

let ayarlarYuklendi = false;
function sekmeAc(ad) {
  kok.querySelectorAll('.as-sekme').forEach((b) => b.classList.toggle('aktif', b.dataset.sekme === ad));
  kok.querySelectorAll('.as-panel').forEach((p) => { p.hidden = p.dataset.panel !== ad; });
  if (ad === 'ayarlar' && !ayarlarYuklendi) { ayarlarYuklendi = true; ayarlarYukle(); }
}

// ─────────────────────────── GÖREVLER ───────────────────────────

async function gorevleriYukle(ad) {
  const akis = el(`#asAkis-${ad}`);
  const r = await api('GET', '/asistan/gorevler', {
    params: { salon_id: salonId, tarih: SEKMELER[ad].tarih, page: sayfa[ad], per_page: SAYFA_BOYU },
  });
  if (!r.ok || !r.govde?.basarili) {
    akis.innerHTML = `<div class="as-bos as-hata">${esc(r.govde?.mesaj || `Görevler yüklenemedi (${r.kod})`)}</div>`;
    return;
  }
  const v = r.govde.veri;
  el(`[data-rozet="${ad}"]`, kok).textContent = v.total;
  akis.innerHTML = v.data.length ? v.data.map(kartCiz).join('') : bosCiz(SEKMELER[ad].bos);
  sayfalamaCiz(el(`#asSayfa-${ad}`), v, (p) => { sayfa[ad] = p; gorevleriYukle(ad); }, 'görev');
  akis.onclick = (e) => {
    const b = e.target.closest('[data-iptal]');
    if (b) gorevIptal(ad, v.data.find((g) => g.tur === b.dataset.tur && String(g.id) === b.dataset.iptal));
  };
}

const bosCiz = (baslik) => `
  <div class="as-bos">
    <div class="as-bos-ikon"><i class="bi bi-check2"></i></div>
    <h3>${esc(baslik)}</h3>
    <p>E-asistanın yapacağı bir hatırlatma bulunmuyor.</p>
  </div>`;

function kartCiz(g) {
  // "bekliyor" sonucu varsayılan metindir ("arama yapılacak") — tekrar gösterilmez.
  const sonuc = g.sonuc && g.durum_kod !== 'bekliyor' ? `<span class="as-sonuc">${esc(g.sonuc)}</span>` : '';
  const durum = g.durum_etiket ? `<span class="as-durum as-d-${esc(g.durum_kod)}">${esc(g.durum_etiket)}</span>` : '';
  const iptal = g.iptal_edilebilir
    ? `<button class="as-iptal-btn" data-iptal="${esc(g.id)}" data-tur="${esc(g.tur)}"><i class="bi bi-x-lg"></i> Görevi İptal Et</button>`
    : '';
  const meta = durum || sonuc || iptal ? `<div class="as-meta">${durum}${sonuc}<span class="as-aksiyon">${iptal}</span></div>` : '';

  return `
    <article class="as-kart as-t-${esc(g.tur)}${g.iptal_edildi ? ' as-iptalli' : ''}">
      <div class="as-kart-ikon"><i class="bi ${TUR_GORSEL[g.tur] || 'bi-bell'}"></i></div>
      <div class="as-kart-govde">
        <div class="as-kart-ust">
          <h4>${esc(g.baslik)}</h4>
          ${g.saat ? `<span class="as-saat">${esc(g.saat)}</span>` : ''}
        </div>
        <p class="as-mesaj">${esc(g.mesaj)}</p>
        ${meta}
      </div>
    </article>`;
}

async function gorevIptal(ad, g) {
  if (!g) return;
  const ok = await onay(`${g.baslik} görevi iptal edilsin mi? Bu işlem geri alınamaz.`, { baslik: 'Görevi İptal Et', tamam: 'İptal Et' });
  if (!ok) return;
  const r = await api('POST', `/asistan/gorev/${encodeURIComponent(g.tur)}/${encodeURIComponent(g.id)}/iptal`);
  if (yazSonuc(r, r.govde?.mesaj)) gorevleriYukle(ad);
}

// ─────────────────────────── AYARLAR ───────────────────────────

async function ayarlarYukle() {
  const kap = el('#asAyarlar');
  const r = await api('GET', '/asistan/ayarlar', { params: { salon_id: salonId } });
  if (!r.ok || !r.govde?.basarili) {
    kap.innerHTML = `<div class="as-bos as-hata">${esc(r.govde?.mesaj || `Ayarlar yüklenemedi (${r.kod})`)}</div>`;
    return;
  }
  ayarlarCiz(kap, r.govde.veri);
}

function ayarlarCiz(kap, v) {
  const saatSecim = () => `
    <div class="as-saat-sec">
      <label for="asTekrarSaat">Tekrar deneme:</label>
      <select id="asTekrarSaat">
        ${v.tekrar_arama_secenekleri.map((s) => `<option value="${s}"${s === v.tekrar_arama_saat ? ' selected' : ''}>${s} saat sonra</option>`).join('')}
      </select>
    </div>`;

  kap.innerHTML = `
    <form class="as-ayar-form" id="asAyarForm">
      <div class="as-ayar-izgara">
        ${v.ayarlar.map((a) => `
          <div class="as-ayar">
            <div class="as-ayar-bas">
              <div class="as-ayar-ic">
                <div class="as-ayar-ikon"><i class="bi ${esc(a.ikon)}"></i></div>
                <h6>${esc(a.baslik)}</h6>
                <p>${esc(a.aciklama)}</p>
              </div>
              <label class="as-anahtar" title="${esc(a.baslik)}">
                <input type="checkbox" name="${esc(a.anahtar)}"${a.acik ? ' checked' : ''}>
                <span class="as-anahtar-iz"></span>
              </label>
            </div>
            ${a.saat_secimli ? saatSecim() : ''}
          </div>`).join('')}
      </div>
      <div class="as-kaydet-bar">
        <button type="submit" class="as-kaydet"><i class="bi bi-check2"></i> Ayarları Güncelle</button>
      </div>
    </form>`;

  el('#asAyarForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.currentTarget;
    const ayarlar = Object.fromEntries(v.ayarlar.map((a) => [a.anahtar, form.elements[a.anahtar].checked]));
    const saat = el('#asTekrarSaat', form);
    const btn = el('.as-kaydet', form);
    btn.disabled = true;
    const r = await api('PATCH', '/asistan/ayarlar', {
      body: { salon_id: salonId, ayarlar, tekrar_arama_saat: saat ? parseInt(saat.value, 10) : null },
    });
    btn.disabled = false;
    if (yazSonuc(r, r.govde?.mesaj)) ayarlarCiz(kap, r.govde.veri);
  });
}

// ─────────────────────────── BAŞLAT ───────────────────────────

iskeletCiz();
if (!salonId) {
  kok.querySelector('.as-sekmeler').insertAdjacentHTML('afterend', '<div class="as-bos as-hata">Şube seçilmedi (?sube=).</div>');
} else {
  sekmeAc('bugun');
  // Her iki sekmenin rozeti açılışta dolsun (eski ekranla aynı).
  gorevleriYukle('bugun');
  gorevleriYukle('yarin');
}
