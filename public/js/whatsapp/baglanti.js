/**
 * WhatsApp › BAĞLANTI: oturum durumu (QR / telefon koduyla bağlama / çıkış), hatırlatma kanalı,
 * nasıl çalışır, mesaja eklenen işletme bağlantıları.
 *
 * Yoklama eski ekranla aynı: bağlanana kadar 4 sn, bağlıyken 15 sn; sekme arka plandayken sorgu yok.
 * Paylaşılan oturumda bağla / QR / çıkış gösterilmez (sunucu da 409 döner).
 */
import { esc, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=7';
import { kilitle } from '/js/lib/v2-bilesen.js?v=7';
import { tarihSaat } from '/js/whatsapp/ortak.js?v=1';

const YOKLAMA_BAGLI_MS = 15000;
const YOKLAMA_BEKLEME_MS = 4000;
const BASLAT_SONRASI_MS = 1500;

const linkAlani = (etiket, id, deger, ipucu) => `
  <div class="rc-alan"><label for="${id}">${esc(etiket)}</label><input type="url" id="${id}" value="${esc(deger)}" placeholder="${esc(ipucu)}"></div>`;
const baslikAlani = (id, deger, ipucu) => `
  <div class="rc-alan"><label for="${id}">Buton Başlığı</label><input type="text" id="${id}" maxlength="60" value="${esc(deger)}" placeholder="${esc(ipucu)}"></div>`;

export async function ciz(kap, ctx) {
  const { acilis } = ctx;
  const l = acilis.linkler;
  kap.innerHTML = `
    <div class="wa-izgara">
      <section class="wa-kart wa-baglanti">
        <h3>Bağlantı Durumu <span id="waRozet" class="rc-rozet rc-rozet-gri">Yükleniyor…</span></h3>
        <div id="waPaylasilan" class="wa-bilgi" hidden></div>

        <div id="waQr" hidden>
          <p>Telefonunuzdan <b>WhatsApp › Ayarlar › Bağlı Cihazlar › Cihaz Bağla</b> menüsünden aşağıdaki QR kodu okutun.</p>
          <img id="waQrGorsel" class="wa-qr" alt="WhatsApp QR kodu">
          <p class="wa-soluk">QR 30-60 saniye geçerlidir, otomatik yenilenir.</p>
          <button type="button" class="rc-btn rc-btn-ikincil" id="waTelefonAc"><i class="bi bi-telephone"></i> QR taratamıyor musunuz? Telefon numarasıyla bağlanın</button>
          <form id="waTelefonForm" class="wa-telefon" hidden>
            <ol>
              <li>Salonun WhatsApp Business numarasını başında <b>90</b> ile girin (örn: 905321234567)</li>
              <li>"Kod Üret" butonuna basın</li>
              <li>Salonun telefonunda: <b>WA Business › Bağlı Cihazlar › Cihaz Bağla › Telefon numarasıyla bağla</b> → çıkan kodu girin</li>
            </ol>
            <div class="wa-satir">
              <input type="tel" id="waTelefon" placeholder="905321234567" autocomplete="off">
              <button type="submit" class="rc-btn rc-btn-yesil">Kod Üret</button>
            </div>
            <div id="waKod" class="wa-kod" hidden></div>
          </form>
        </div>

        <div id="waBagli" class="wa-durum-kutu" hidden>
          <i class="bi bi-check-circle-fill wa-ikon-ok"></i>
          <p>WhatsApp bağlı numara: <b id="waNumara">-</b></p>
          <p class="wa-soluk">Son bağlantı: <span id="waBagliTarih">-</span></p>
        </div>

        <div id="waKapali" class="wa-durum-kutu" hidden>
          <i class="bi bi-phone wa-ikon-kapali"></i>
          <p>WhatsApp oturumu kapalı. Başlatmak için butona tıklayın.</p>
        </div>

        <div class="wa-eylemler">
          <button type="button" class="rc-btn rc-btn-yesil" id="waBaslat" hidden><i class="bi bi-whatsapp"></i> WhatsApp'ı Bağla</button>
          <button type="button" class="rc-btn rc-btn-kirmizi" id="waCikis" hidden>Oturumu Kapat</button>
        </div>

        <label class="wa-kanal">
          <span class="rc-switch"><input type="checkbox" id="waKanal" ${acilis.kanal.aktif ? 'checked' : ''}><span class="rc-kaydir"></span></span>
          <span><b>WhatsApp üzerinden hatırlatma gönder</b>
            <small>Açık: 1 gün öncesi ve salon hatırlatma saatindeki hatırlatmalar önce WhatsApp'tan gider, başarısızsa SMS'e düşer. Kapalı: Sadece SMS kullanılır.</small></span>
        </label>
      </section>

      <section class="wa-kart">
        <h3>Nasıl Çalışır?</h3>
        <ul class="wa-liste">${acilis.nasil_calisir.map((m) => `<li>${esc(m)}</li>`).join('')}</ul>
        <p id="waSonHata" class="wa-hata-metin" hidden></p>
      </section>
    </div>

    <section class="wa-kart">
      <h3><i class="bi bi-link-45deg"></i> İşletme Bağlantıları</h3>
      <p class="wa-soluk">Buraya girdiğiniz bağlantıları, müşteriye WhatsApp mesajı yazarken tek tıkla mesaja ekleyebilirsiniz. Buton başlığını siz belirlersiniz; boş bıraktığınız butonlar görünmez.</p>
      <form id="waLinkForm" class="wa-linkler">
        <div class="wa-link-tam">${linkAlani('Konum (Google Maps › Paylaş › Bağlantıyı kopyala)', 'waKonum', l.konum_linki, 'https://maps.app.goo.gl/...')}</div>
        ${baslikAlani('waIgBaslik', l.instagram_baslik, 'Örn. Instagram')}
        ${linkAlani('Bağlantı', 'waIg', l.instagram_linki, 'https://instagram.com/kullaniciadi')}
        ${baslikAlani('waWebBaslik', l.web_baslik, 'Örn. Web Sitesi')}
        ${linkAlani('Bağlantı', 'waWeb', l.web_linki, 'https://...')}
        <div class="wa-link-tam"><button type="submit" class="rc-btn rc-btn-yesil">Bağlantıları Kaydet</button></div>
      </form>
    </section>`;

  const $ = (s) => kap.querySelector(s);
  const goster = (hangisi) => {
    $('#waQr').hidden = hangisi !== 'qr';
    $('#waBagli').hidden = hangisi !== 'bagli';
    $('#waKapali').hidden = hangisi !== 'kapali';
    $('#waBaslat').hidden = hangisi !== 'kapali';
    $('#waCikis').hidden = hangisi !== 'bagli' && hangisi !== 'qr';
  };

  let bitti = false;
  let zaman = null;
  let sonDurum = null;

  async function tik() {
    const r = await ctx.oku('/whatsapp/durum');
    if (bitti) return;
    const d = r.ok && r.govde?.basarili ? r.govde.veri : { status: 'servis-kapali', ad: 'Servis Kapalı', renk: 'kirmizi' };
    sonDurum = d.status;
    $('#waRozet').className = `rc-rozet rc-rozet-${esc(d.renk)}`;
    $('#waRozet').textContent = d.ad;
    $('#waSonHata').hidden = !d.lastError;
    $('#waSonHata').textContent = d.lastError ? `Son hata: ${d.lastError}` : '';

    if (d.paylasilan) {
      $('#waPaylasilan').hidden = false;
      $('#waPaylasilan').textContent = `Bu işletme, ${d.paylasilan.salon_adi} işletmesinin WhatsApp oturumunu paylaşıyor. Bağlama ve çıkış işlemleri üst işletmeden yapılır.`;
      goster('');
    } else if (d.bagli) {
      goster('bagli');
    } else {
      // Köprü "kopuk" dese bile yeni QR üretilmiş olabilir: önce QR denenir (eski ekranla aynı).
      const q = await ctx.oku('/whatsapp/qr');
      if (bitti) return;
      const gorsel = q.ok ? q.govde?.veri?.qr : null;
      if (gorsel) $('#waQrGorsel').src = gorsel;
      goster(gorsel ? 'qr' : 'kapali');
    }
    if (d.phone) $('#waNumara').textContent = d.phone;
    $('#waBagliTarih').textContent = tarihSaat(d.connectedAt);
  }

  function zamanla() {
    zaman = setTimeout(async () => {
      if (!document.hidden) await tik();
      if (!bitti) zamanla();
    }, sonDurum === 'connected' ? YOKLAMA_BAGLI_MS : YOKLAMA_BEKLEME_MS);
  }

  $('#waBaslat').addEventListener('click', (e) => kilitle(e.currentTarget, 'Başlatılıyor…', async () => {
    if (!yazSonuc(await ctx.yaz('POST', '/whatsapp/baslat'))) return;
    await new Promise((coz) => { setTimeout(coz, BASLAT_SONRASI_MS); });   // köprü QR'ı üretene kadar
    await tik();
  }));

  $('#waCikis').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    if (!await onay('WhatsApp oturumunu kapatmak istediğinize emin misiniz?', { baslik: 'Oturumu Kapat', tamam: 'Kapat' })) return;
    kilitle(btn, 'Kapatılıyor…', async () => {
      yazSonuc(await ctx.yaz('POST', '/whatsapp/cikis'), 'Oturum kapatıldı');
      await tik();
    });
  });

  $('#waTelefonAc').addEventListener('click', () => { $('#waTelefonForm').hidden = !$('#waTelefonForm').hidden; });

  $('#waTelefonForm').addEventListener('submit', (e) => {
    e.preventDefault();
    kilitle(e.submitter, 'Üretiliyor…', async () => {
      $('#waKod').hidden = true;
      const r = await ctx.yaz('POST', '/whatsapp/pair-phone', { phone: $('#waTelefon').value });
      if (!yazSonuc(r)) return;
      $('#waKod').innerHTML = `<small>Telefonda girilecek kod:</small><b>${esc(r.govde.veri.code)}</b><small>Bu kod 60 saniye geçerlidir</small>`;
      $('#waKod').hidden = false;
    });
  });

  $('#waKanal').addEventListener('change', async (e) => {
    const k = e.target;
    k.disabled = true;
    const r = await ctx.yaz('PUT', '/whatsapp/kanal', { aktif: k.checked });
    k.disabled = false;
    if (yazSonuc(r, 'Ayar kaydedildi')) acilis.kanal = r.govde.veri;
    else k.checked = !k.checked;
  });

  $('#waLinkForm').addEventListener('submit', (e) => {
    e.preventDefault();
    kilitle(e.submitter, 'Kaydediliyor…', async () => {
      const r = await ctx.yaz('PUT', '/whatsapp/linkler', {
        konum_linki: $('#waKonum').value.trim(),
        instagram_baslik: $('#waIgBaslik').value.trim(),
        instagram_linki: $('#waIg').value.trim(),
        web_baslik: $('#waWebBaslik').value.trim(),
        web_linki: $('#waWeb').value.trim(),
      });
      if (yazSonuc(r, 'Bağlantılar kaydedildi')) acilis.linkler = r.govde.veri;
    });
  });

  await tik();
  if (!bitti) zamanla();

  return { birak() { bitti = true; clearTimeout(zaman); } };
}
