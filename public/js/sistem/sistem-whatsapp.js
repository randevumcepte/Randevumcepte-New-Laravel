/** Sistem WhatsApp: bildirim ayari, test, gonderen oturumu (QR / telefon kodu). Yalniz super_admin. Veri /api/v2/sistem/sistem-whatsapp. */
import { el, esc, mesaj, onay } from '/js/lib/v2-ortak.js?v=7';
import { ekran, syApi, syVeri, syYaz, girisGit } from '/js/lib/v2-sistem.js?v=7';

const KOK = '/sistem-whatsapp';
const HIZLI_MS = 2000, NORMAL_MS = 5000, BAGLI_MS = 8000, KOD_MS = 3000;
const TESHIS_SATIR = 5;

let zamanlayici = null;
const teshisKayit = [];

function yoklamaKur(ms) {
  clearInterval(zamanlayici);
  zamanlayici = setInterval(durumCek, ms);
}

function teshis(t) {
  teshisKayit.push(t);
  if (teshisKayit.length > TESHIS_SATIR) teshisKayit.shift();
  el('#swTeshis').textContent = teshisKayit.join('\n');
}

function ipucuCiz(gonderen) {
  el('#swIpucu').innerHTML = gonderen
    ? `QR'ı <b class="sw-vurgu">${esc(gonderen)}</b> numaralı telefonun WhatsApp'ından okut: <b>WhatsApp &gt; Ayarlar &gt; Bağlı Cihazlar &gt; Cihaz Bağla</b>.`
    : 'Önce yukarıya <b>GÖNDEREN</b> numarayı yazıp Kaydet, sonra o telefonun WhatsApp\'ından <b>Bağlı Cihazlar &gt; Cihaz Bağla</b> ile QR\'ı okut.';
}

function iskelet(a) {
  return `
    <div class="sy-page-head"><div>
      <h2><span class="mdi mdi-whatsapp sw-ikon"></span> Sistem WhatsApp</h2>
      <div class="subtitle">Ayrı bir numarayı QR ile bağla (gönderen), müşteri demo açınca o hattan senin numarana WhatsApp + SMS gelsin.</div>
    </div></div>

    <div class="sy-card sy-mt-12">
      <div class="sy-card-head"><h3><span class="mdi mdi-bell-ring"></span> Bildirim Ayarı</h3></div>
      <div class="sy-card-body">
        <div class="sw-form">
          <div class="sy-form-group sw-alici"><label><b>ALICI</b> — mesajın geleceği numara(lar). Birden fazlaysa <b>virgülle</b> ayır.</label>
            <input type="text" id="swNumara" class="sy-input" value="${esc(a.numara)}" placeholder="0541 xxx xx xx, 0531 xxx xx xx"></div>
          <div class="sy-form-group sw-gonderen"><label><b>GÖNDEREN</b> — QR ile bağlayacağın numara</label>
            <input type="text" id="swGonderen" class="sy-input" value="${esc(a.gonderen_numara)}" placeholder="0531 xxx xx xx"></div>
          <div class="sy-form-group sw-onay"><label><input type="checkbox" id="swAktif"${a.aktif ? ' checked' : ''}> Bildirimler açık</label></div>
          <button class="sy-btn sy-btn-primary" id="swKaydet"><span class="mdi mdi-content-save"></span> Kaydet</button>
        </div>
        <button class="sy-btn sy-btn-soft sy-mt-12" id="swTest"><span class="mdi mdi-send-check"></span> Test Mesajı Gönder (WA + SMS)</button>
        <div class="sy-alert sy-alert-info sy-mt-12" id="swTestSonuc" hidden></div>
      </div>
    </div>

    <div class="sy-card sy-mt-12">
      <div class="sy-card-head"><h3><span class="mdi mdi-qrcode-scan"></span> Gönderen WhatsApp (QR ile bağla)</h3><span id="swDurum" class="rc-rozet rc-rozet-gri">Durum kontrol ediliyor…</span></div>
      <div class="sy-card-body">
        <div id="swServis" class="sw-servis"><span class="mdi mdi-server-network"></span> Servis durumu kontrol ediliyor…</div>
        <div id="swBagli" hidden>
          <div class="sy-alert sy-alert-success"><span class="mdi mdi-check-circle"></span> Gönderen WhatsApp bağlı: <b id="swTelefon">—</b></div>
          <div class="sy-alert sy-alert-danger" id="swUyari" hidden></div>
          <button class="sy-btn sy-btn-sm" id="swCikis"><span class="mdi mdi-logout"></span> Oturumu Kapat</button>
        </div>
        <div id="swBaglan">
          <p class="sy-text-muted" id="swIpucu"></p>
          <button class="sy-btn sy-btn-primary" id="swBaslat"><span class="mdi mdi-whatsapp"></span> Bağlan / QR Göster</button>
          <div id="swTeshis" class="sy-text-muted sy-fs-12 sw-teshis"></div>
          <div id="swQrKutu" class="sw-qr-kutu" hidden>
            <img id="swQr" src="" alt="QR" class="sw-qr">
            <div class="sy-text-muted sy-fs-12">QR 30-60 sn geçerli, otomatik yenilenir. Okuttuktan sonra "Bağlı" olur.</div>
          </div>
          <div class="sw-eslestir">
            <h4><span class="mdi mdi-cellphone-key"></span> Alternatif: Telefon Numarası ile Bağla</h4>
            <p class="sy-text-muted sy-fs-13">QR okutmakta zorlanıyorsan telefon numarasını yaz, 8 haneli kodu <b>WhatsApp &gt; Bağlı Cihazlar &gt; Cihaz Bağla &gt; "Telefon numarası ile bağlan"</b> ekranına gir.</p>
            <div class="sw-eslestir-satir"><input id="swEslesTel" type="tel" class="sy-input" placeholder="905321234567">
              <button id="swEsles" class="sy-btn"><span class="mdi mdi-key"></span> Kod Oluştur</button></div>
            <div id="swKodKutu" class="sw-kod-kutu" hidden>
              <div class="sy-text-muted sy-fs-12">8 haneli pair kodunuz:</div>
              <div id="swKod" class="sw-kod">--------</div>
              <div class="sy-text-muted sy-fs-12">Kod ~60 sn geçerlidir. WhatsApp'ta ilgili ekrana yaz.</div>
            </div>
          </div>
        </div>
      </div>
    </div>`;
}

async function durumCek() {
  const r = await syApi('GET', `${KOK}/durum`);
  if (r.kod === 401) { clearInterval(zamanlayici); girisGit(); return; }
  if (!r.ok || !r.govde?.basarili) { durumYaz('Servise ulaşılamadı', 'kirmizi'); return; }
  const d = r.govde.veri;
  durumYaz(d.etiket, d.renk);
  el('#swServis').innerHTML = `<span class="mdi mdi-server-network"></span> WhatsApp servisi (whatsmeow): ${d.servis_acik ? '<b class="sw-acik">AÇIK ✓</b>' : '<b class="sw-kapali">KAPALI ✗ — WhatsApp bağlanamaz!</b>'}`;
  el('#swBagli').hidden = !d.bagli;
  el('#swBaglan').hidden = d.bagli;
  if (d.bagli) {
    el('#swTelefon').textContent = d.phone || '—';
    el('#swUyari').hidden = !d.uyari;
    el('#swUyari').textContent = d.uyari || '';
    el('#swQrKutu').hidden = true;
    yoklamaKur(BAGLI_MS);
  } else {
    qrCek();
  }
}

function durumYaz(metin, renk) {
  const d = el('#swDurum');
  d.textContent = metin;
  d.className = `rc-rozet rc-rozet-${renk}`;
}

async function qrCek() {
  const r = await syApi('GET', `${KOK}/qr`);
  if (!r.ok || !r.govde?.basarili) { teshis('QR isteği başarısız (ağ/servis).'); return; }
  const { qr, teshis: not } = r.govde.veri;
  if (qr) {
    el('#swQr').src = qr;
    el('#swQrKutu').hidden = false;
  } else if (not) {
    teshis(not);
  }
}

function bagla() {
  el('#swKaydet').addEventListener('click', async () => {
    const { tamam, veri } = await syYaz('PUT', `${KOK}/ayar`, {
      numara: el('#swNumara').value, gonderen_numara: el('#swGonderen').value, aktif: el('#swAktif').checked,
    });
    if (!tamam) return;
    el('#swNumara').value = veri.numara;
    el('#swGonderen').value = veri.gonderen_numara;
    ipucuCiz(veri.gonderen_numara);
  });

  el('#swTest').addEventListener('click', async (e) => {
    const b = e.currentTarget;
    b.disabled = true;
    const { tamam, veri } = await syYaz('POST', `${KOK}/test`, undefined, '');
    b.disabled = false;
    const kutu = el('#swTestSonuc');
    kutu.hidden = !tamam;
    if (tamam) kutu.textContent = veri.ozet;
  });

  el('#swBaslat').addEventListener('click', async (e) => {
    const b = e.currentTarget;
    b.disabled = true;
    teshis('Başlatılıyor…');
    const { tamam } = await syYaz('POST', `${KOK}/baslat`, undefined, '');
    b.disabled = false;
    if (!tamam) { durumYaz('Servise ulaşılamadı', 'kirmizi'); return; }
    durumYaz('QR hazırlanıyor…', 'mavi');
    yoklamaKur(HIZLI_MS);
    [800, 2000, 4000].forEach((ms) => setTimeout(qrCek, ms));
  });

  el('#swCikis').addEventListener('click', async () => {
    if (!(await onay('Gönderen WhatsApp oturumu kapatılsın mı?', { tehlike: true }))) return;
    const { tamam } = await syYaz('POST', `${KOK}/cikis`);
    if (tamam) { el('#swKodKutu').hidden = true; yoklamaKur(NORMAL_MS); durumCek(); }
  });

  el('#swEsles').addEventListener('click', async (e) => {
    const telefon = el('#swEslesTel').value.replace(/\D/g, '');
    if (telefon.length < 10) { mesaj('Lütfen ülke kodu ile birlikte geçerli bir numara girin (örn. 905321234567).', 'hata'); return; }
    const b = e.currentTarget;
    b.disabled = true;
    const { tamam, veri } = await syYaz('POST', `${KOK}/eslestir`, { telefon }, '');
    b.disabled = false;
    if (!tamam) return;
    el('#swKod').textContent = veri.gosterim;
    el('#swKodKutu').hidden = false;
    yoklamaKur(KOD_MS);
  });
}

async function baslat() {
  const a = await syVeri(KOK);
  if (!a) return;
  ekran().innerHTML = iskelet(a);
  ipucuCiz(a.gonderen_numara);
  bagla();
  durumCek();
  yoklamaKur(NORMAL_MS);
}

baslat();
