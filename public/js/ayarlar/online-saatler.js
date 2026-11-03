/**
 * Ayarlar → Online Randevu Saatleri (v2). Eski #online_randevu_saatleri ekranıyla birebir:
 * ana anahtar + günlük slot limiti, çalışma saatlerinden üretilmiş slot ızgarası (tıkla aç/kapat),
 * tarihe özel istisnalar (kapalı / özel saat aralığı).
 * Slotlar ve günler SUNUCUDAN gelir (çalışma saati × randevu aralığı); kayıtta açık slotlar
 * gönderilir, sunucu bitişik slotları pencereye birleştirir (Flutter ile aynı sözleşme).
 * Uçlar: GET/PUT /ayar/online-saatler.
 */
import { esc } from '/js/lib/v2-ortak.js?v=1';
import { yukle, kaydet, kart, anahtar, secim, kaydetCubugu, gonderBagla, saatBagla, tarihBagla, isaretli, deger } from '/js/ayarlar/ortak.js?v=4';

export async function ciz(kap, ctx) {
  const v = await yukle(kap, '/ayar/online-saatler', ctx.salonId);
  if (v) formCiz(kap, ctx, v);
}

function formCiz(kap, ctx, v) {
  const tipler = v.secenekler.istisna_tipleri;
  // Hiç yapılandırılmamış gün = tüm slotlar açık (eski varsayılan).
  const acik = Object.fromEntries(v.gunler.map((g) => [g.gun, new Set(g.yapilandirildi ? g.acik : g.slots)]));

  const gunCiz = (g) => `
    <div class="ay-gun${g.calisiyor ? '' : ' ay-kapali'}" data-gun="${g.gun}">
      <div class="ay-gun-bas"><b>${esc(g.gun_adi)}</b>
        ${g.calisiyor ? `<span><button type="button" class="ay-mini" data-hepsi="1">Tümü açık</button><button type="button" class="ay-mini" data-hepsi="0">Tümü kapalı</button></span>` : '<small>Çalışılmıyor</small>'}</div>
      <div class="ay-slotlar">${g.slots.map((s) => `<button type="button" class="ay-slot${acik[g.gun].has(s) ? ' acik' : ''}" data-slot="${s}">${s}</button>`).join('')}</div>
    </div>`;

  kap.innerHTML = `<form class="ay-form" novalidate>
    ${kart('bi-globe2', 'Online Randevu Kısıtlaması', `
      ${anahtar('kisitlama_aktif', 'Online randevuyu yalnızca seçili saatlerle sınırla', v.kisitlama_aktif, 'Kapalıyken müşteriler çalışma saatleri içindeki tüm boş saatlerden randevu alabilir.')}
      <div class="rc-form-izgara ay-dar"><div class="rc-alan"><label>Günlük online slot limiti</label>
        <input type="number" name="gunluk_limit" min="0" max="1000" value="${esc(v.gunluk_limit ?? '')}" placeholder="Limitsiz">
        <span class="rc-ipucu">Boş veya 0 = limitsiz</span></div></div>`)}
    ${kart('bi-grid-3x3-gap', 'Haftalık Açık Saatler', `<div class="ay-gunler">${v.gunler.map(gunCiz).join('')}</div>`,
      `Randevu aralığı: ${v.aralik_dk} dk. Mor saatler online randevuya açıktır; tıklayarak değiştirin.`)}
    ${kart('bi-calendar-x', 'Tarihe Özel İstisnalar', `
      <div id="ayIstisnalar"></div>
      <button type="button" class="rc-btn rc-btn-ikincil" id="ayIstisnaEkle"><i class="bi bi-plus-lg"></i> İstisna Ekle</button>`)}
    ${kaydetCubugu()}
  </form>`;

  const form = kap.querySelector('form');
  const istisnaKap = form.querySelector('#ayIstisnalar');
  const istisnaSatir = (i = { tarih: '', tip: 'kapali', bas: '', bit: '' }) => {
    const li = document.createElement('div');
    li.className = 'ay-istisna';
    li.innerHTML = `
      <div class="rc-alan"><label>Tarih</label><input type="text" name="tarih" value="${esc(i.tarih)}" data-tarih placeholder="YYYY-AA-GG"></div>
      ${secim('tip', 'Tip', i.tip, tipler)}
      <div class="rc-alan" data-ozel><label>Başlangıç</label><input type="text" name="bas" value="${esc(i.bas || '')}" data-saat></div>
      <div class="rc-alan" data-ozel><label>Bitiş</label><input type="text" name="bit" value="${esc(i.bit || '')}" data-saat></div>
      <button type="button" class="rc-islem rc-tehlike" data-kaldir title="Kaldır"><i class="bi bi-trash"></i></button>`;
    const tipGoster = () => li.querySelectorAll('[data-ozel]').forEach((a) => { a.hidden = li.querySelector('[name=tip]').value !== 'ozel'; });
    li.querySelector('[name=tip]').addEventListener('change', tipGoster);
    tipGoster();
    istisnaKap.appendChild(li);
    saatBagla(li);
    tarihBagla(li);
  };
  v.istisnalar.forEach(istisnaSatir);

  form.addEventListener('click', (e) => {
    const slot = e.target.closest('[data-slot]');
    const gunEl = e.target.closest('[data-gun]');
    if (slot) {
      const set = acik[gunEl.dataset.gun];
      slot.classList.toggle('acik', !set.has(slot.dataset.slot));
      set.has(slot.dataset.slot) ? set.delete(slot.dataset.slot) : set.add(slot.dataset.slot);
    }
    const hepsi = e.target.closest('[data-hepsi]');
    if (hepsi) {
      const set = acik[gunEl.dataset.gun];
      gunEl.querySelectorAll('[data-slot]').forEach((b) => {
        b.classList.toggle('acik', hepsi.dataset.hepsi === '1');
        hepsi.dataset.hepsi === '1' ? set.add(b.dataset.slot) : set.delete(b.dataset.slot);
      });
    }
    if (e.target.closest('#ayIstisnaEkle')) istisnaSatir();
    if (e.target.closest('[data-kaldir]')) e.target.closest('.ay-istisna').remove();
  });

  gonderBagla(form, async () => {
    const istisnalar = [...istisnaKap.querySelectorAll('.ay-istisna')].map((li) => {
      const d = (ad) => li.querySelector(`[name="${ad}"]`).value.trim();
      return d('tip') === 'ozel' ? { tarih: d('tarih'), tip: 'ozel', bas: d('bas'), bit: d('bit') } : { tarih: d('tarih'), tip: 'kapali' };
    }).filter((i) => i.tarih);
    const yeni = await kaydet('PUT', '/ayar/online-saatler', {
      salon_id: ctx.salonId,
      kisitlama_aktif: isaretli(form, 'kisitlama_aktif'),
      gunluk_limit: Number(deger(form, 'gunluk_limit')) || 0,
      gunler: Object.fromEntries(Object.entries(acik).map(([g, set]) => [g, [...set].sort()])),
      istisnalar,
    });
    if (yeni) formCiz(kap, ctx, yeni);
  });
}
