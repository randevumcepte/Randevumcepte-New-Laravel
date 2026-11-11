/**
 * Çarkıfelek › HATIRLATMA (eski /isletmeyonetim/carkhatirlatma). Ana anahtar + en fazla 3
 * hatırlatma kartı (başlık, altyazı, saat, mesaj; kaldır / yeni ekle) + gönderilmeyecek günler.
 * Push'ları eski monolitin cron'u gönderir; bu ekran yalnız ayarı yazar.
 *
 * Veri: GET /cark/hatirlatma → {aktif, bugun_gonderilen, slotlar[], atlanan_gunler[], gunler[]};
 * PUT /cark/hatirlatma (aynı yapı). Varsayılan metinler/saatler API'de (config cark.hatirlatma).
 */
import { esc, yazSonuc } from '/js/lib/v2-ortak.js?v=7';
import { kilitle } from '/js/lib/v2-bilesen.js?v=7';

export async function ciz(kap, ctx) {
  const r = await ctx.oku('/cark/hatirlatma');
  if (!kap.isConnected) return;
  if (!r.ok || !r.govde?.basarili) { kap.innerHTML = `<div class="ck-bos ck-hata">${esc(r.govde?.mesaj || 'Yüklenemedi')}</div>`; return; }
  const v = r.govde.veri;

  kap.innerHTML = `
    <section class="ck-kart ck-hat-ana">
      <div><h3>⏰ Çarkıfelek Hatırlatma Sistemi</h3>
        <p class="ck-ipucu">Müşterilerinize gün içinde en fazla 3 push bildirimi gönderir, çarkı çevirmelerini hatırlatır. Tıklayan müşteri çark ekranına yönlenir.</p>
        <span class="rc-rozet rc-rozet-mor">Bugün gönderilen: <b id="htBugun">${v.bugun_gonderilen}</b></span></div>
      <label class="rc-switch ck-buyuk"><input type="checkbox" id="htAktif" ${v.aktif ? 'checked' : ''}><span class="rc-kaydir"></span></label>
    </section>
    <div class="ck-hat-izgara">${v.slotlar.map((s) => `
      <section class="ck-kart ck-hat-kart" data-no="${s.no}" ${s.aktif ? '' : 'hidden'}>
        <div class="ck-hat-bas">
          <span class="ck-hat-ikon">${esc(s.ikon)}</span>
          <div class="ck-hat-ad"><input data-alan="baslik" maxlength="80" value="${esc(s.baslik)}" placeholder="Başlık">
            <input data-alan="altyazi" maxlength="120" value="${esc(s.altyazi)}" placeholder="Altyazı"></div>
          <button type="button" class="ck-x" data-kaldir title="Bu hatırlatmayı kaldır">×</button>
        </div>
        <div class="rc-alan"><label>Saat</label><input type="time" data-alan="saat" value="${esc(s.saat)}"></div>
        <div class="rc-alan"><label>Mesaj</label><textarea data-alan="mesaj" rows="2" maxlength="${v.mesaj_max}">${esc(s.mesaj)}</textarea></div>
      </section>`).join('')}</div>
    <button type="button" class="ck-hat-ekle" id="htEkle">+ Yeni Hatırlatma Ekle</button>
    <section class="ck-kart">
      <h4>Hangi günler gönderilmesin?</h4>
      <p class="ck-ipucu">İşaretlediğiniz günler push gönderilmez (örn. salonun kapalı olduğu günler).</p>
      <div class="ck-gunler">${v.gunler.map((g) => `<label class="ck-gun"><input type="checkbox" value="${g.no}" ${v.atlanan_gunler.includes(g.no) ? 'checked' : ''}> ${esc(g.ad)}</label>`).join('')}</div>
    </section>
    <div class="ck-sag"><button type="button" class="rc-btn rc-btn-birincil" id="htKaydet"><i class="bi bi-save"></i> Hatırlatma Ayarlarını Kaydet</button></div>`;
  const $ = (s) => kap.querySelector(s);
  const kartlar = [...kap.querySelectorAll('.ck-hat-kart')];
  const ekleGuncelle = () => { $('#htEkle').hidden = kartlar.every((k) => !k.hidden); };

  kap.addEventListener('click', (e) => {
    if (e.target.closest('[data-kaldir]')) { e.target.closest('.ck-hat-kart').hidden = true; ekleGuncelle(); }
  });
  $('#htEkle').onclick = () => { const k = kartlar.find((x) => x.hidden); if (k) k.hidden = false; ekleGuncelle(); };
  ekleGuncelle();

  $('#htKaydet').onclick = (e) => kilitle(e.currentTarget, 'Kaydediliyor…', async () => {
    const deger = (k, a) => k.querySelector(`[data-alan="${a}"]`).value.trim();
    const govde = {
      aktif: $('#htAktif').checked,
      slotlar: kartlar.map((k) => ({ no: Number(k.dataset.no), aktif: !k.hidden, baslik: deger(k, 'baslik'), altyazi: deger(k, 'altyazi'), saat: deger(k, 'saat'), mesaj: deger(k, 'mesaj') })),
      atlanan_gunler: [...kap.querySelectorAll('.ck-gun input:checked')].map((x) => Number(x.value)),
    };
    const s = await ctx.yaz('PUT', '/cark/hatirlatma', govde);
    if (yazSonuc(s, 'Hatırlatma ayarları kaydedildi')) $('#htBugun').textContent = s.govde.veri.bugun_gonderilen;
  });
}
