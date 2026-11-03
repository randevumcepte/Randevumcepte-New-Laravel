/**
 * Çarkıfelek › ÇARK (eski /isletmeyonetim/carkifelek). Dilim editörü + canlı önizleme + test
 * çevirme, çevirme sıklığı, kupon geçerlilik süreleri, şube seçimi, kullanım kuralları, aktiflik
 * ve "Müşterilere Duyur" push'u (eskiden yalnız mobilde vardı).
 *
 * Veri: ctx.acilis (GET /cark: sistem, dilimler, sozluk, subeler). Yazma: PUT /cark (tek kayıt —
 * dilimler + kurallar + süreler birlikte, eski web gibi), PATCH /cark/aktif, POST /cark/duyuru.
 * Kazanan / dilim sayısı / tip-değer kuralları sunucuda (Domain\Cark\DilimSeti); burada yalnız arayüz.
 */
import { el, esc, mesaj, yazSonuc } from '/js/lib/v2-ortak.js?v=1';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { kilitle } from '/js/lib/v2-bilesen.js?v=2';
import { subeSecici } from '/js/lib/v2-sube-secici.js?v=1';
import { carkCiz, cevir, kutlama, kutlamaDurdur, tamAd } from '/js/carkifelek/cark-cizim.js?v=1';

export async function ciz(kap, ctx) {
  const { sozluk } = ctx.acilis;
  const tipListe = sozluk.dilim_tipleri;
  const T = ctx.tipler;
  const d = durumOlustur(ctx.acilis);
  let donuyor = false;

  kap.innerHTML = `
    <div class="ck-ust">
      <div class="ck-ust-bilgi"><span class="ck-ust-ikon">🎡</span>
        <div><h2>Çarkıfelek Sistemi</h2><p>Dilimlerinizi düzenleyin, müşterilerinize özel ödüller sunun</p></div></div>
      <span class="ck-durum" id="ckDurum"><span class="ck-nokta"></span><span id="ckDurumMetin"></span></span>
    </div>

    <div class="ck-izgara">
      <section class="ck-kart ck-onizleme">
        <p class="ck-etiket">Önizleme</p>
        <div class="ck-pim-kap">
          <svg id="ckPim" class="ck-pim" viewBox="0 0 32 54" width="32" height="54">
            <defs>
              <linearGradient id="ckPg" x1="0%" x2="100%"><stop offset="0%" stop-color="#7f1d1d"/><stop offset="40%" stop-color="#ef4444"/><stop offset="70%" stop-color="#fca5a5"/><stop offset="100%" stop-color="#7f1d1d"/></linearGradient>
              <linearGradient id="ckPg2" x1="0%" x2="100%"><stop offset="0%" stop-color="#92400e"/><stop offset="50%" stop-color="#fbbf24"/><stop offset="100%" stop-color="#92400e"/></linearGradient>
            </defs>
            <polygon points="16,54 2,10 30,10" fill="url(#ckPg)" stroke="#7f1d1d"/>
            <rect x="2" y="0" width="28" height="12" rx="5" fill="url(#ckPg)" stroke="#7f1d1d"/>
            <polygon points="16,48 8,14 13,14" fill="rgba(255,255,255,0.18)"/>
            <circle cx="16" cy="54" r="4" fill="url(#ckPg2)" stroke="#78350f"/>
          </svg>
        </div>
        <div class="ck-isilti"><div class="ck-teker"><svg id="ckCark" viewBox="0 0 300 300"></svg></div></div>
        <div class="ck-onizleme-btn">
          <button type="button" class="rc-btn rc-btn-birincil" id="ckTest">🎲 Test Et</button>
          <button type="button" class="rc-btn rc-btn-ikincil" id="ckDuyur" title="Uygulaması yüklü müşterilere push"><i class="bi bi-megaphone"></i> Müşterilere Duyur</button>
        </div>
        <label class="ck-satir"><span>Çark Durumu</span>
          <span class="rc-switch"><input type="checkbox" id="ckAktif"><span class="rc-kaydir"></span></span></label>
      </section>

      <section class="ck-kart">
        <div class="ck-yonetim-bas">
          <h3>Dilim Yönetimi</h3>
          <div class="ck-sayac"><button type="button" data-say="-1">−</button><span id="ckSay"></span><button type="button" data-say="1">+</button></div>
        </div>
        <p class="ck-ipucu">Kazandırmak istediğiniz dilimi seçin — müşteri çarkı ne kadar çevirirse çevirsin o dilim çıkar. (Min ${sozluk.dilim_min} · Maks ${sozluk.dilim_max} dilim)</p>
        <div class="ck-dilimler" id="ckDilimler"></div>

        <div class="ck-alt-kart">
          <div class="ck-alt-bas"><span>🔁</span><div><h4>Çevirme Sıklığı</h4>
            <p>Bir müşteri çarkı kaç <b>günde bir</b> çevirebilsin? <b>1</b> = her gün (varsayılan). Örn. <b>7</b> → son çevirmeden 7 gün sonra tekrar çevirebilir.</p></div></div>
          <div class="rc-alan"><label>Çevirme aralığı (gün)</label><input type="number" min="1" step="1" id="ckAralik" placeholder="1 = her gün">
            <span class="rc-ipucu">Değer büyüdükçe müşteri daha seyrek çevirir; değerli ödüllerin sık kazanılmasını sınırlar.</span></div>
        </div>

        <div class="ck-alt-kart">
          <div class="ck-alt-bas"><span>⏳</span><div><h4>Kupon Geçerlilik Süreleri</h4>
            <p>Müşteriye verilen kuponların kaç gün geçerli olacağını belirleyin. <b>0</b> veya boş → kupon <b>süresiz</b>.</p></div></div>
          <div class="rc-form-izgara">
            <div class="rc-alan"><label>Çark ödülü kuponu (gün)</label><input type="number" min="0" step="1" id="ckCarkGun" placeholder="0 = süresiz"><span class="rc-ipucu">Örn. 30 → çarktan kazanılan kupon 30 gün geçerli.</span></div>
            <div class="rc-alan"><label>Puan ödülü kuponu (gün)</label><input type="number" min="0" step="1" id="ckPuanGun" placeholder="0 = süresiz"><span class="rc-ipucu">Örn. 60 → puanla talep edilen ödül 60 gün geçerli.</span></div>
          </div>
        </div>

        <div id="ckSubeler"></div>

        <div class="ck-yonetim-alt">
          <div class="ck-kazanan"><span>🏆</span><div><small>Çıkacak dilim:</small><b id="ckKazanan">Seçilmedi</b></div></div>
          <button type="button" class="rc-btn rc-btn-birincil" data-kaydet><i class="bi bi-save"></i> Ayarı Kaydet</button>
        </div>
      </section>
    </div>

    <section class="ck-kart ck-kurallar">
      <div class="ck-alt-bas"><span>📋</span><div><h4>Çarkıfelek Kullanım Kuralları</h4>
        <p>Bu metin çarkı çeviren müşterilere bilgilendirme/uyarı olarak gösterilir (geçerlilik süresi, kullanım koşulları…).</p></div></div>
      <textarea id="ckKurallar" rows="8" placeholder="• Kazandığınız ödüller 30 gün içinde geçerlidir."></textarea>
      <div class="ck-yonetim-alt">
        <span class="rc-ipucu">Boş bırakırsanız müşterilere <b>koruyucu varsayılan kurallar</b> gösterilir.</span>
        <button type="button" class="rc-btn rc-btn-ikincil" data-kaydet><i class="bi bi-card-checklist"></i> Kuralları Kaydet</button>
      </div>
    </section>`;

  const $ = (s) => kap.querySelector(s);
  const svg = $('#ckCark');
  const subeler = subeSecici($('#ckSubeler'), ctx.acilis.subeler);
  $('#ckAralik').value = d.aralik > 1 ? d.aralik : '';
  $('#ckCarkGun').value = d.carkGun || '';
  $('#ckPuanGun').value = d.puanGun || '';
  $('#ckKurallar').value = d.kurallar;
  maddeIsaretiBagla($('#ckKurallar'));
  hepsiniCiz();

  // ── olaylar ──
  $('#ckDilimler').addEventListener('input', (e) => {
    const i = Number(e.target.closest('[data-i]')?.dataset.i);
    const s = d.dilimler[i];
    if (!s) return;
    if (e.target.matches('[data-ad]')) { s.name = e.target.value; s._adli = e.target.value.trim() !== ''; }
    if (e.target.matches('[data-renk]')) { s.color = e.target.value; e.target.parentElement.style.background = s.color; }
    if (e.target.matches('[data-deger]')) { const v = parseFloat(e.target.value); s.deger = Number.isNaN(v) ? null : v; }
    carkiCiz();
  });
  $('#ckDilimler').addEventListener('click', (e) => {
    const b = e.target.closest('button');
    const i = Number(e.target.closest('[data-i]')?.dataset.i);
    const s = d.dilimler[i];
    if (!b || !s) return;
    if (b.dataset.sec !== undefined) d.kazanan = i;
    if (b.dataset.tip) {
      s.tip = b.dataset.tip;
      if (!T[s.tip].degerli) s.deger = null;
      if (!T[s.tip].kupon) s.indirim_tipi = 'yuzde';
      if (!s._adli) s.name = T[s.tip].kisa;   // kullanıcının yazdığı adı ezme
    }
    if (b.dataset.birim) s.indirim_tipi = b.dataset.birim;
    hepsiniCiz();
  });
  kap.querySelector('.ck-sayac').addEventListener('click', (e) => {
    const yon = Number(e.target.closest('[data-say]')?.dataset.say);
    if (!yon) return;
    const n = d.dilimler.length + yon;
    if (n < sozluk.dilim_min) return mesaj(`En az ${sozluk.dilim_min} dilim olmalıdır.`, 'hata');
    if (n > sozluk.dilim_max) return mesaj(`En fazla ${sozluk.dilim_max} dilim eklenebilir.`, 'hata');
    if (yon < 0) { d.dilimler.pop(); d.kazanan = Math.min(d.kazanan, n - 1); }
    else d.dilimler.push({ name: `Ödül ${n}`, color: sozluk.renkler[(n - 1) % sozluk.renkler.length], tip: 'bos', deger: null, indirim_tipi: 'yuzde', _adli: false });
    hepsiniCiz();
  });

  $('#ckTest').onclick = async (e) => {
    if (donuyor || d.dilimler.length < 2) return;
    donuyor = true;
    const b = e.currentTarget; b.disabled = true; b.textContent = '⏳ Çevriliyor…';
    await cevir(svg, d.dilimler.length, d.kazanan, { pim: $('#ckPim') });
    donuyor = false; b.disabled = false; b.textContent = '🎲 Test Et';
    if (!kap.isConnected) return;
    const ov = modalAc('Sonuç!', `<div class="ck-sonuc"><span>🎉</span><p>Test çevirme sonucu:</p><b>${esc(tamAd(d.dilimler[d.kazanan], T[d.dilimler[d.kazanan].tip]))}</b></div>`,
      '<button class="rc-btn rc-btn-birincil" data-kapat>Kapat</button>', { ikon: 'trophy', dar: true });
    el('[data-kapat]', ov).onclick = modalKapat;
    kutlama();
    new MutationObserver((_, g) => { if (!ov.isConnected) { kutlamaDurdur(); g.disconnect(); } }).observe(document.body, { childList: true });
  };

  $('#ckAktif').onchange = async (e) => {
    const yeni = e.target.checked;
    if (!d.kayitli) { d.aktif = yeni; durumCiz(); return mesaj('Durum, ayarı kaydettiğinizde uygulanır.', 'bilgi'); }
    const r = await ctx.yaz('PATCH', '/cark/aktif', { aktifmi: yeni });
    if (yazSonuc(r, r.govde?.mesaj)) { d.aktif = yeni; ctx.acilis.sistem.aktifmi = yeni ? 1 : 0; }
    durumCiz();
  };

  $('#ckDuyur').onclick = () => duyuruAc(ctx, d);

  kap.querySelectorAll('[data-kaydet]').forEach((b) => { b.onclick = (e) => kilitle(e.currentTarget, 'Kaydediliyor…', kaydet); });

  async function kaydet() {
    const sayi = (sec, min) => Math.max(min, parseInt($(sec).value, 10) || min);
    const govde = {
      dilimler: d.dilimler.map((s, i) => ({
        name: (s.name || '').trim() || T[s.tip].kisa, color: s.color, probability: i === d.kazanan ? 100 : 0,
        tip: s.tip, deger: T[s.tip].degerli ? s.deger : null, indirim_tipi: s.indirim_tipi,
      })),
      aktifmi: d.aktif,
      kurallar: $('#ckKurallar').value,
      cevirme_araligi_gun: sayi('#ckAralik', 1),
      kupon_cark_gecerlilik_gun: sayi('#ckCarkGun', 0),
      kupon_puan_gecerlilik_gun: sayi('#ckPuanGun', 0),
      salon_ids: subeler.secili(),
    };
    const r = await ctx.yaz('PUT', '/cark', govde);
    if (!yazSonuc(r, 'Ayar kaydedildi! 🎉')) return;
    Object.assign(ctx.acilis, { sistem: r.govde.veri.sistem, dilimler: r.govde.veri.dilimler });
    d.kayitli = true;
    $('#ckKurallar').value = r.govde.veri.sistem.kurallar;
  }

  // ── çizim ──
  function hepsiniCiz() { listeCiz(); carkiCiz(); durumCiz(); $('#ckSay').textContent = d.dilimler.length; }
  function carkiCiz() {
    carkCiz(svg, d.dilimler, d.kazanan, T);
    const k = d.dilimler[d.kazanan];
    $('#ckKazanan').textContent = k ? tamAd(k, T[k.tip]) : 'Seçilmedi';
  }
  function durumCiz() {
    $('#ckAktif').checked = d.aktif;
    $('#ckDurum').className = `ck-durum ${d.aktif ? 'acik' : 'kapali'}`;
    $('#ckDurumMetin').textContent = d.aktif ? 'Aktif' : 'Pasif';
  }
  function listeCiz() {
    $('#ckDilimler').innerHTML = d.dilimler.map((s, i) => {
      const t = T[s.tip];
      const kazanan = i === d.kazanan;
      const birim = t.kupon
        ? `<div class="ck-birim"><button type="button" data-birim="yuzde" class="${s.indirim_tipi !== 'tutar' ? 'aktif' : ''}" title="Yüzde indirim">%</button><button type="button" data-birim="tutar" class="${s.indirim_tipi === 'tutar' ? 'aktif' : ''}" title="Sabit ₺ tutar">₺</button></div>`
        : `<span class="ck-birim-metin">${t.degerli ? 'puan' : ''}</span>`;
      return `<div class="ck-dilim${kazanan ? ' kazanan' : ''}" data-i="${i}">
        <div class="ck-dilim-ust">
          <span class="ck-no">${i + 1}</span>
          <label class="ck-renk" style="background:${esc(s.color)}" title="Renk seç"><input type="color" data-renk value="${esc(s.color)}"></label>
          <input type="text" class="ck-ad" data-ad value="${esc(s.name)}" maxlength="100" placeholder="Dilim adı">
          <button type="button" class="ck-sec${kazanan ? ' aktif' : ''}" data-sec>${kazanan ? '★ Kazanan' : 'Seç'}</button>
        </div>
        <div class="ck-dilim-alt">
          <div class="ck-tipler">${tipListe.map((x) => `<button type="button" data-tip="${x.kod}" class="${s.tip === x.kod ? 'aktif' : ''}">${x.emoji} ${esc(x.ad)}</button>`).join('')}</div>
          ${t.degerli ? `<div class="ck-deger"><input type="number" min="0" step="any" data-deger value="${s.deger ?? ''}" placeholder="${s.tip === 'puan' ? 'Puan miktarı' : (s.indirim_tipi === 'tutar' ? 'Tutar (₺)' : 'Yüzde (%)')}">${birim}</div>` : ''}
        </div>
      </div>`;
    }).join('');
  }

  return { birak: kutlamaDurdur };
}

/** GET /cark verisinden editör durumu (kayıt yoksa sözlükteki varsayılan dilimler). */
function durumOlustur(a) {
  const kayitli = Boolean(a.sistem.id) && a.dilimler.length > 0;
  const kaynak = kayitli ? a.dilimler : a.sozluk.varsayilan_dilimler.map((x, i) => ({ ...x, color: a.sozluk.renkler[i % a.sozluk.renkler.length], probability: i === 0 ? 100 : 0 }));
  const kazanan = Math.max(0, kaynak.findIndex((x) => Number(x.probability) === 100));
  return {
    kayitli,
    dilimler: kaynak.map((x) => ({ name: x.name, color: x.color, tip: x.tip, deger: x.deger, indirim_tipi: x.indirim_tipi || 'yuzde', _adli: kayitli && !!x.name })),
    kazanan,
    aktif: Number(a.sistem.aktifmi) === 1,
    aralik: a.sistem.cevirme_araligi_gun,
    carkGun: a.sistem.kupon_cark_gecerlilik_gun,
    puanGun: a.sistem.kupon_puan_gecerlilik_gun,
    kurallar: a.sistem.kurallar || '',
  };
}

/** Kurallar kutusu: her yeni satır "• " ile başlar (Shift+Enter düz satır) — eski davranış. */
function maddeIsaretiBagla(ta) {
  ta.addEventListener('focus', () => { if (!ta.value.trim()) ta.value = '• '; });
  ta.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || e.shiftKey) return;
    e.preventDefault();
    const { selectionStart: s, selectionEnd: b } = ta;
    ta.value = `${ta.value.slice(0, s)}\n• ${ta.value.slice(b)}`;
    ta.selectionStart = ta.selectionEnd = s + 3;
  });
}

function duyuruAc(ctx, d) {
  if (!d.kayitli || !d.aktif) return mesaj('Duyuru için çark kaydedilmiş ve aktif olmalı.', 'hata');
  const ov = modalAc('Müşterilere Duyur', `
    <p class="ck-ipucu">Uygulaması yüklü ve bildirim izni vermiş müşterilerinize "çarkı çevir" bildirimi gider; tıklayan müşteri çark ekranına yönlenir. <b>{ad}</b> ve <b>{salon_adi}</b> kişiselleştirilir.</p>
    <div class="rc-alan"><label>Başlık</label><input id="dyBaslik" maxlength="80" placeholder="Boş: 🎡 Salon adı"></div>
    <div class="rc-alan"><label>Mesaj</label><textarea id="dyMesaj" rows="3" maxlength="300" placeholder="Boş: Çark-ı Felek'i çevir, sürpriz ödülü kap! Bugün şansını dene 🎁"></textarea></div>`,
  '<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-birincil" id="dyGonder"><i class="bi bi-send"></i> Gönder</button>',
  { ikon: 'megaphone', dar: true });
  el('[data-kapat]', ov).onclick = modalKapat;
  el('#dyGonder', ov).onclick = (e) => kilitle(e.currentTarget, 'Gönderiliyor…', async () => {
    const r = await ctx.yaz('POST', '/cark/duyuru', { baslik: el('#dyBaslik', ov).value, mesaj: el('#dyMesaj', ov).value });
    if (yazSonuc(r, r.govde?.mesaj)) modalKapat();
  });
}
