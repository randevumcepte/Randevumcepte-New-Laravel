/** Yeni demo salon: ucretsiz denemeyle ayni kurulum. Veri /api/v2/sistem/salon. */
import { el, esc } from '/js/lib/v2-ortak.js?v=7';
import { syYaz, ekran, tarih } from '/js/lib/v2-sistem.js?v=7';
import { modalAc } from '/js/lib/v2-modal.js?v=7';
import { salonSecenekleri, secenekler, ilIlceBagla, formOku } from '/js/sistem/salon-ortak.js?v=1';

const kok = ekran();
const zorunlu = '<span class="sl-zorunlu">*</span>';

function formCiz(sec) {
  kok.innerHTML = `
    <div class="sy-page-head">
      <div>
        <h2>Yeni Salon (Demo)</h2>
        <div class="subtitle">Ücretsiz deneme kaydıyla birebir aynı kurulum: tüm ayarlar + varsayılan hizmet/form + örnek demo içeriği oluşturulur ve giriş bilgileri hesap sahibine SMS ile gönderilir.</div>
      </div>
      <div class="sy-flex-row"><a href="/sistemyonetim/salonlar" class="sy-btn"><span class="mdi mdi-arrow-left"></span> Liste</a></div>
    </div>
    <form id="seForm">
      <div class="sy-card sy-mt-12">
        <div class="sy-card-head"><h3><span class="mdi mdi-store-plus"></span> Salon Bilgileri</h3></div>
        <div class="sy-card-body">
          <div class="sy-form-group"><label>Salon Adı ${zorunlu}</label><input type="text" name="salon_adi" class="sy-input" required autofocus></div>
          <div class="sy-form-row">
            <div class="sy-form-group"><label>İşletme Türü ${zorunlu}</label>
              <select name="salon_turu_id" class="sy-select" required>${secenekler(sec.salon_turleri, 'id', 'salon_turu_adi', '', 'Seçiniz')}</select></div>
            <div class="sy-form-group"><label>Demo Süresi (gün)</label><input type="number" name="demo_gun" class="sy-input" value="7" min="1" max="3650"></div>
          </div>
          <div class="sy-form-row">
            <div class="sy-form-group"><label>İl</label><select name="il_id" id="seIl" class="sy-select">${secenekler(sec.iller, 'id', 'il_adi', '', 'Seçiniz')}</select></div>
            <div class="sy-form-group"><label>İlçe</label><select name="ilce_id" id="seIlce" class="sy-select"></select></div>
          </div>
          <div class="sy-form-group"><label>Adres</label><input type="text" name="adres" class="sy-input" maxlength="255"></div>
        </div>
      </div>
      <div class="sy-card sy-mt-12">
        <div class="sy-card-head"><h3><span class="mdi mdi-account-key"></span> Yetkili / Giriş Bilgileri</h3><span class="sy-text-muted sy-fs-12">Demo hesabı bu bilgilerle giriş yapar</span></div>
        <div class="sy-card-body">
          <div class="sy-form-row">
            <div class="sy-form-group"><label>Yetkili Adı ${zorunlu}</label><input type="text" name="yetkili_ad" class="sy-input" required></div>
            <div class="sy-form-group"><label>Yetkili Telefon ${zorunlu}</label><input type="text" name="yetkili_telefon" class="sy-input" placeholder="05xx xxx xx xx" required></div>
          </div>
          <div class="sy-form-row">
            <div class="sy-form-group"><label>Yetkili E-posta ${zorunlu}</label><input type="email" name="yetkili_email" class="sy-input" placeholder="giris@ornek.com" required></div>
            <div class="sy-form-group"><label>Şifre <span class="sy-text-muted sy-fs-12">(boş bırakılırsa otomatik üretilir)</span></label><input type="text" name="yetkili_sifre" class="sy-input" placeholder="Otomatik" autocomplete="off"></div>
          </div>
        </div>
      </div>
      <div class="sy-flex-row sy-mt-12">
        <button type="submit" class="sy-btn sy-btn-primary"><span class="mdi mdi-check"></span> Demo Salon Oluştur</button>
        <a href="/sistemyonetim/salonlar" class="sy-btn">Vazgeç</a>
      </div>
    </form>`;
  ilIlceBagla(el('#seIl'), el('#seIlce'), sec.ilceler, null);
  el('#seForm').addEventListener('submit', gonder);
}

/** Sifre yalniz burada gorunur: sonuc kullanici kapatmadan gitmez. */
function sonucGoster(s) {
  const bilgi = [['Giriş e-postası', s.email], ['Kullanıcı adı (telefon)', s.telefon], ['Şifre', s.sifre], ['Demo bitiş', tarih(s.demo_bitis)]];
  const ov = modalAc('Demo salon oluşturuldu', `
    <div class="sy-alert ${s.sifre_gonderildi ? 'sy-alert-success' : 'sy-alert-warning'}">
      ${s.sifre_gonderildi ? 'Kurulum tamamlandı, giriş bilgileri hesap sahibine SMS ile gönderildi.' : "Kurulum tamamlandı ancak giriş bilgileri SMS'i gönderilemedi. Bilgileri hesap sahibine iletin."}
    </div>
    <div class="sy-stack">${bilgi.map(([ad, d]) => `<div><span class="sy-text-muted sy-fs-12">${esc(ad)}</span><div class="sy-fw-600">${esc(d)}</div></div>`).join('')}</div>`,
  `<a href="/sistemyonetim/salon/${s.salon_id}" class="sy-btn sy-btn-primary">Salona Git</a>`, { ikon: 'check-circle', sabit: true });
  return ov;
}

async function gonder(e) {
  e.preventDefault();
  const dugme = e.target.querySelector('[type="submit"]');
  dugme.disabled = true;
  const { tamam, veri } = await syYaz('POST', '/salon', formOku(e.target), '');
  dugme.disabled = false;
  if (tamam) { e.target.reset(); sonucGoster(veri); }
}

const sec = await salonSecenekleri();
if (sec) {
  if (sec.izinler.duzenle) formCiz(sec);
  else kok.innerHTML = '<div class="sy-empty"><div class="icon mdi mdi-lock"></div><div class="baslik">Bu işlem için yetkiniz yok.</div></div>';
}
