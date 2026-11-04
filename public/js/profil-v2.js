/**
 * Profil Bilgileri (v2) — build'siz island. Eski /isletmeyonetim/profil ekranının karşılığı: kişisel
 * bilgiler + şifre değiştirme + kırpmalı profil resmi. Kurallar sunucuda (GET/PUT /profil, POST /profil/resim).
 */
import { api, qp, esc, el, mesaj, yazSonuc } from '/js/lib/v2-ortak.js?v=1';
import { apiForm } from '/js/lib/v2-dosya.js?v=2';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { kilitle } from '/js/lib/v2-bilesen.js?v=2';

const CROPPER = 'https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/';
const RESIM_BOYUT = 800;

const kok = document.getElementById('rc-profil');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || null;
if (salonId && window.rcPanel) window.rcPanel.salonAyarla(salonId);

const avatarHtml = (p) => (p.profil_resim_url
  ? `<img src="${esc(p.profil_resim_url)}" alt="" onerror="this.remove()">`
  : `<span>${esc((p.name || 'R').charAt(0).toUpperCase())}</span>`);

const radyo = (ad, deger, etiket, secili) => `<label class="hs-radyo"><input type="radio" name="${ad}" value="${deger}"${secili === deger ? ' checked' : ''}> ${etiket}</label>`;

function kartCiz(p) {
  el('#pfKart').innerHTML = `
    <div class="hs-avatar hs-avatar-buyuk" id="pfAvatar">${avatarHtml(p)}
      <button type="button" class="hs-avatar-btn" id="pfResimBtn" title="Fotoğrafı değiştir" aria-label="Fotoğrafı değiştir"><i class="bi bi-pencil"></i></button>
    </div>
    <h2>${esc(p.name)}</h2>
    ${p.unvan ? `<div class="hs-soluk">${esc(p.unvan)}</div>` : ''}
    <h3>İletişim Bilgileri</h3>
    <dl class="hs-liste">
      <div><dt>E-posta</dt><dd>${esc(p.email || '—')}</dd></div>
      <div><dt>Telefon</dt><dd>${esc(p.gsm1 || '—')}</dd></div>
    </dl>
    <input type="file" id="pfResim" accept="image/jpeg,image/png,image/webp" hidden>`;
  el('#pfResimBtn').onclick = () => el('#pfResim').click();
  el('#pfResim').onchange = (e) => { const d = e.target.files[0]; e.target.value = ''; if (d) kirpAc(d); };
}

function formCiz(p) {
  el('#pfFormKap').innerHTML = `
    <form id="pfForm" autocomplete="off">
      <div class="rc-form-izgara">
        <div class="rc-alan"><label>Ad Soyad</label><input name="name" required maxlength="255" value="${esc(p.name)}"></div>
        <div class="rc-alan"><label>E-posta Adresi</label><input name="email" type="email" maxlength="255" value="${esc(p.email)}"></div>
        <div class="rc-alan"><label>Ünvan</label><input name="unvan" maxlength="255" value="${esc(p.unvan)}"></div>
        <div class="rc-alan"><label>Telefon Numarası</label><input name="gsm1" type="tel" required value="${esc(p.gsm1)}"></div>
        <div class="rc-alan"><label>Cinsiyet</label><div class="hs-radyolar">${radyo('cinsiyet', 1, 'Erkek', p.cinsiyet)}${radyo('cinsiyet', 0, 'Kadın', p.cinsiyet)}</div></div>
        <div class="rc-alan"><label>Şifre Değiştir</label><input name="password" type="password" minlength="6" maxlength="72" autocomplete="new-password" placeholder="Değiştirmeyecekseniz boş bırakın">
          <span class="rc-ipucu">Şifre değişince diğer cihazlardaki oturumlarınız kapatılır.</span></div>
      </div>
      <div class="hs-form-alt"><button type="submit" class="rc-btn rc-btn-birincil"><i class="bi bi-check2"></i> Bilgileri Güncelle</button></div>
    </form>`;
  el('#pfForm').onsubmit = (e) => kaydet(e);
}

async function kaydet(e) {
  e.preventDefault();
  const f = e.target;
  const cinsiyet = f.querySelector('[name="cinsiyet"]:checked');
  const govde = {
    salon_id: salonId,
    name: f.name.value.trim(),
    email: f.email.value.trim() || null,
    unvan: f.unvan.value.trim() || null,
    gsm1: f.gsm1.value.trim(),
    cinsiyet: cinsiyet ? Number(cinsiyet.value) : null,
    password: f.password.value || null,
  };
  await kilitle(f.querySelector('[type=submit]'), 'Kaydediliyor…', async () => {
    const r = await api('PUT', '/profil', { body: govde });
    if (!yazSonuc(r, r.govde?.mesaj)) return;
    const p = r.govde.veri;
    kartCiz(p);
    f.password.value = '';
    window.rcPanel?.tazele();
  });
}

/** cropperjs ilk kullanımda yüklenir; yüklenemezse resim kırpılmadan gönderilir. */
function cropperYukle() {
  if (window.Cropper) return Promise.resolve(true);
  return new Promise((coz) => {
    const css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = CROPPER + 'cropper.min.css';
    document.head.appendChild(css);
    const s = document.createElement('script');
    s.src = CROPPER + 'cropper.min.js';
    s.onload = () => coz(true);
    s.onerror = () => coz(false);
    document.head.appendChild(s);
  });
}

async function resimGonder(dosya) {
  const fd = new FormData();
  fd.append('resim', dosya, dosya.name || 'profil.jpg');
  if (salonId) fd.append('salon_id', salonId);
  const r = await apiForm('/profil/resim', fd);
  if (!yazSonuc(r, r.govde?.mesaj)) return false;
  kartCiz(r.govde.veri);
  window.rcPanel?.tazele();
  return true;
}

async function kirpAc(dosya) {
  if (!dosya.type.startsWith('image/')) { mesaj('Lütfen bir görsel seçiniz.', 'hata'); return; }
  if (!(await cropperYukle())) { await resimGonder(dosya); return; }

  const url = URL.createObjectURL(dosya);
  const ov = modalAc('Fotoğrafı Kırp', `<div class="hs-kirp"><img id="pfKirpImg" src="${url}" alt=""></div>`,
    `<button class="rc-btn rc-btn-ikincil" id="pfKirpIptal" type="button">Vazgeç</button>
     <button class="rc-btn rc-btn-birincil" id="pfKirp" type="button"><i class="bi bi-crop"></i> Kırp ve Yükle</button>`,
    { ikon: 'crop', sabit: true });
  const kirpici = new window.Cropper(el('#pfKirpImg', ov), { aspectRatio: 1, viewMode: 1, autoCropArea: 1 });
  el('#pfKirpIptal', ov).onclick = modalKapat;
  el('#pfKirp', ov).onclick = (e) => kilitle(e.currentTarget, 'Yükleniyor…', async () => {
    const tuval = kirpici.getCroppedCanvas({ width: RESIM_BOYUT, height: RESIM_BOYUT, fillColor: '#fff' });
    const blob = await new Promise((coz) => tuval.toBlob(coz, 'image/jpeg', 0.9));
    if (blob && await resimGonder(new File([blob], 'profil.jpg', { type: 'image/jpeg' }))) modalKapat();
  });
}

async function baslat() {
  kok.innerHTML = `
    <div class="hs-kabuk">
      <div class="rc-sayfa-ust"><div class="rc-sayfa-baslik">
        <span class="rc-sayfa-ikon"><i class="bi bi-person"></i></span>
        <div><h1>Profil Bilgileri</h1><div class="rc-sayfa-alt">Kişisel bilgileriniz, fotoğrafınız ve şifreniz</div></div>
      </div></div>
      <div class="hs-profil">
        <section class="hs-kart hs-profil-kart" id="pfKart"><span class="rc-yukleniyor"></span></section>
        <section class="hs-kart" id="pfFormKap"></section>
      </div>
    </div>`;
  const r = await api('GET', '/profil');
  if (!r.ok || !r.govde?.basarili) { el('#pfKart').textContent = r.govde?.mesaj || `Hata (${r.kod})`; return; }
  kartCiz(r.govde.veri);
  formCiz(r.govde.veri);
}

baslat();
