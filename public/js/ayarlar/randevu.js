/**
 * Ayarlar → Randevu Ayarları (v2). Eski #randevu_ayarlari formuyla birebir: randevu aralığı,
 * takvim ayarı, online randevu / geçmiş gizle / çakışma uyarısı / stüdyo modu anahtarları,
 * takvim tek renk + yazı rengi + 10'lu renk sırası (zemin/yazı çiftleri, oklarla sıralama).
 * Seçenekler (aralıklar, takvim türleri, palet) ve renk sırası SUNUCUDAN gelir.
 * Uçlar: GET/PATCH /ayar/randevu.
 */
import { esc } from '/js/lib/v2-ortak.js?v=7';
import { yukle, kaydet, kart, secim, anahtar, kaydetCubugu, gonderBagla, isaretli, deger } from '/js/ayarlar/ortak.js?v=5';

const BAYRAKLAR = ['musteri_online_randevu_aktif', 'gecmis_randevulari_gizle', 'cakisma_uyarisi_aktif', 'studyo_modu', 'takvim_tek_renk_aktif', 'takvim_yazi_rengi_aktif'];

export async function ciz(kap, ctx) {
  const v = await yukle(kap, '/ayar/randevu', ctx.salonId);
  if (v) formCiz(kap, ctx, v);
}

const renkSecici = (ad, renk, palet) => `
  <div class="ay-renk">
    <input type="color" data-renk-icin="${ad}" value="${esc(renk)}">
    <input type="text" name="${ad}" value="${esc(renk)}" maxlength="7" class="ay-hex">
    <div class="ay-palet">${palet.map((h) => `<button type="button" class="ay-swatch" style="background:${esc(h)}" data-renk="${esc(h)}" data-hedef="${ad}" title="${esc(h)}"></button>`).join('')}</div>
  </div>`;

function formCiz(kap, ctx, v) {
  const s = v.secenekler;
  kap.innerHTML = `<form class="ay-form" novalidate>
    ${kart('bi-calendar-check', 'Takvim', `
      <div class="rc-form-izgara">
        ${secim('randevu_saat_araligi', 'Randevu Aralığı', v.randevu_saat_araligi, s.saat_araliklari.map((d) => ({ kod: d, ad: `${d} dakikada bir` })))}
        ${secim('randevu_takvim_turu', 'Takvim Ayarı', v.randevu_takvim_turu, s.takvim_turleri)}
      </div>`)}
    ${kart('bi-toggles', 'Randevu Davranışı', `
      ${anahtar('musteri_online_randevu_aktif', 'Müşteri online randevu alabilsin', v.musteri_online_randevu_aktif, 'Bu seçenek açıkken müşteriler mobil/web üzerinden kendileri randevu oluşturabilir.')}
      ${anahtar('gecmis_randevulari_gizle', 'Geçmiş randevular görünmesin', v.gecmis_randevulari_gizle, 'Açıkken takvimde, randevu listesinde ve müşteri kartında saati 1 saatten fazla geçmiş randevular gösterilmez. Son 1 saat içindekiler görünmeye devam eder.')}
      ${anahtar('cakisma_uyarisi_aktif', 'Aynı saatte dolu personel/cihaz/oda için uyar', v.cakisma_uyarisi_aktif)}
      ${anahtar('studyo_modu', 'Stüdyo Modu (Pilates / kurs stüdyosu)', v.studyo_modu, 'Açıkken finans/satış/ürün/ön görüşme/çark gibi paneller gizlenir, ödeme "Alındı / Alınmadı" olarak sadeleşir ve üyeler için vücut ölçümü + gelişim takibi açılır. Diğer işletmelerde kapalı bırakın.')}`)}
    ${kart('bi-palette', 'Takvim Renkleri', `
      ${anahtar('takvim_tek_renk_aktif', 'Takvim tek renk kullanılsın', v.takvim_tek_renk_aktif)}
      <div class="ay-alt-bolum" data-goster="takvim_tek_renk_aktif">${renkSecici('takvim_tek_renk', v.takvim_tek_renk, s.palet)}</div>
      ${anahtar('takvim_yazi_rengi_aktif', 'Takvim yazı rengini elle belirle', v.takvim_yazi_rengi_aktif)}
      <div class="ay-alt-bolum" data-goster="takvim_yazi_rengi_aktif">${renkSecici('takvim_yazi_rengi', v.takvim_yazi_rengi, s.palet)}</div>
      <div class="ay-alt-bolum" data-gizle="takvim_tek_renk_aktif">
        <h4 class="ay-ara-baslik">Renk Sırası (10'lu skala)</h4>
        <p class="ay-kart-alt">Tek renk kapalıyken takvim kaynakları (personel / oda / cihaz / kategori) görünüm sırasına göre bu renklerden sırayla boyanır. Oklarla sırayı değiştirin.</p>
        <ol class="ay-sira" id="aySira"></ol>
      </div>`)}
    ${kaydetCubugu()}
  </form>`;

  const form = kap.querySelector('form');
  let sira = v.takvim_renk_sirasi.map((c) => ({ ...c }));
  const siraCiz = () => {
    form.querySelector('#aySira').innerHTML = sira.map((c, i) => `
      <li data-i="${i}">
        <span class="ay-onizleme" style="background:${esc(c.bg)};color:${esc(c.text)}">Örnek</span>
        <label>Zemin <input type="color" data-sira="bg" value="${esc(c.bg)}"></label>
        <label>Yazı <input type="color" data-sira="text" value="${esc(c.text)}"></label>
        <span class="ay-sira-ok">
          <button type="button" class="rc-islem" data-tasi="-1" ${i === 0 ? 'disabled' : ''} title="Yukarı"><i class="bi bi-arrow-up"></i></button>
          <button type="button" class="rc-islem" data-tasi="1" ${i === sira.length - 1 ? 'disabled' : ''} title="Aşağı"><i class="bi bi-arrow-down"></i></button>
        </span>
      </li>`).join('');
  };
  const gorunurluk = () => {
    form.querySelectorAll('[data-goster]').forEach((b) => { b.hidden = !isaretli(form, b.dataset.goster); });
    form.querySelectorAll('[data-gizle]').forEach((b) => { b.hidden = isaretli(form, b.dataset.gizle); });
  };
  siraCiz();
  gorunurluk();

  form.addEventListener('change', gorunurluk);
  form.addEventListener('input', (e) => {
    const t = e.target;
    if (t.dataset.renkIcin) form.querySelector(`[name="${t.dataset.renkIcin}"]`).value = t.value.toUpperCase();
    if (t.classList.contains('ay-hex') && /^#[0-9a-f]{6}$/i.test(t.value)) form.querySelector(`[data-renk-icin="${t.name}"]`).value = t.value;
    if (t.dataset.sira) {
      sira[Number(t.closest('li').dataset.i)][t.dataset.sira] = t.value.toUpperCase();
      const c = sira[Number(t.closest('li').dataset.i)];
      Object.assign(t.closest('li').querySelector('.ay-onizleme').style, { background: c.bg, color: c.text });
    }
  });
  form.addEventListener('click', (e) => {
    const sw = e.target.closest('[data-renk]');
    if (sw) {
      form.querySelector(`[name="${sw.dataset.hedef}"]`).value = sw.dataset.renk;
      form.querySelector(`[data-renk-icin="${sw.dataset.hedef}"]`).value = sw.dataset.renk;
    }
    const ok = e.target.closest('[data-tasi]');
    if (ok) {
      const i = Number(ok.closest('li').dataset.i), j = i + Number(ok.dataset.tasi);
      [sira[i], sira[j]] = [sira[j], sira[i]];
      siraCiz();
    }
  });

  gonderBagla(form, async () => {
    const body = {
      salon_id: ctx.salonId,
      randevu_saat_araligi: Number(deger(form, 'randevu_saat_araligi')),
      randevu_takvim_turu: Number(deger(form, 'randevu_takvim_turu')),
      takvim_tek_renk: deger(form, 'takvim_tek_renk'),
      takvim_yazi_rengi: deger(form, 'takvim_yazi_rengi'),
      takvim_renk_sirasi: sira,
    };
    BAYRAKLAR.forEach((b) => { body[b] = isaretli(form, b); });
    const yeni = await kaydet('PATCH', '/ayar/randevu', body);
    if (yeni) formCiz(kap, ctx, yeni);
  });
}
