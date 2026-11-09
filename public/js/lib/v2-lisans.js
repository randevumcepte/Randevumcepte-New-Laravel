/**
 * Lisans kapısı (sunucu: 402 + veri.kod 'lisans_suresi_doldu') — web v2 tarafı.
 *
 * v2-ortak.js `api` her cevapta bu kodu görünce bu modülü YALNIZ o an yükler (tek yer). Sayfanın
 * üstüne kapatılamayan bir lisans ekranı çizer (eski isletmeadmin.lisanssurebitti karşılığı):
 * Hesabım & Üyelik, Tekrar Dene (sayfayı yeniler; sunucu bitiş tarihini her istekte taze okur,
 * uzatıldıysa sayfa açılır), arama ve çıkış. Tarayıcıda "kapalı" durumu SAKLANMAZ.
 * Hesabım ve Profil sayfaları lisans bitse de açıktır; orada ekran çizilmez.
 */

import { api, esc } from '/js/lib/v2-ortak.js?v=7';
import { oturumuTemizle } from '/js/lib/v2-oturum-depo.js?v=3';

const ILETISIM_TEL = '05412948144';
const ILETISIM_GOSTERIM = '0541 294 81 44';
const ACIK_SAYFALAR = /^\/v2\/(hesabim|profil)(\/|$)/;

let cizildi = false;

/** Lisans ekranı bu sayfada gösterilmeli mi? (Hesabım / Profil açık kalır.) */
export function ekranGerekliMi(yol) {
  return !ACIK_SAYFALAR.test(yol);
}

/** Ekrandaki başlık + metin: sunucunun mesajı esas, başlık aktif olmadı / süresi bitti ayrımı. */
export function lisansMetni(govde) {
  const v = (govde && govde.veri) || {};
  return {
    baslik: v.aktif_olmadi ? 'Lisans Kullanımınız Henüz Aktif Olmadı!' : 'Lisans Kullanım Süreniz Bitti!',
    mesaj: (govde && govde.mesaj) || 'Panel kullanım süreniz sona ermiştir.',
    salonId: Number(v.salon_id) || 0,
  };
}

async function cikis() {
  try { await api('POST', '/oturum/cikis'); } catch (_) { /* ağ hatası: token yine de silinir */ }
  oturumuTemizle();
  location.href = '/v2/giris';
}

export function lisansKapandi(govde) {
  if (cizildi || !ekranGerekliMi(location.pathname)) return;
  cizildi = true;
  const { baslik, mesaj, salonId } = lisansMetni(govde);
  const hesabim = '/v2/hesabim' + (salonId ? '/' + salonId : '');

  const kap = document.createElement('div');
  kap.id = 'rcLisansKapisi';
  kap.setAttribute('role', 'alertdialog');
  kap.setAttribute('aria-modal', 'true');
  kap.setAttribute('aria-labelledby', 'rcLisansBaslik');
  kap.style.cssText = 'position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;'
    + 'padding:16px;background:rgba(23,25,45,.55);font-family:inherit';
  kap.innerHTML = `
    <div style="max-width:460px;width:100%;background:var(--rc-kart,#fff);color:var(--rc-metin,#2b2f45);border-radius:16px;
      padding:28px 24px;box-shadow:var(--rc-golge-2,0 8px 24px rgba(0,0,0,.15));text-align:center">
      <div style="font-size:40px;line-height:1;color:var(--rc-kirmizi,#e4616e)" aria-hidden="true">&#9888;</div>
      <h2 id="rcLisansBaslik" style="margin:12px 0 8px;font-size:20px">${esc(baslik)}</h2>
      <p style="margin:0 0 20px;color:var(--rc-sol,#6b7194);line-height:1.5">${esc(mesaj)}</p>
      <div style="display:flex;flex-direction:column;gap:10px">
        <a href="${esc(hesabim)}" style="display:block;padding:11px;border-radius:10px;background:var(--rc-mor,#5C008E);color:#fff;text-decoration:none;font-weight:600">Hesabım &amp; Üyelik</a>
        <button type="button" data-lisans="tekrar" style="padding:11px;border-radius:10px;border:1px solid var(--rc-cizgi,#e7e9f2);background:transparent;color:inherit;font-weight:600;cursor:pointer">Tekrar Dene</button>
        <a href="tel:${ILETISIM_TEL}" style="color:var(--rc-mor-2,#7B2FB8);text-decoration:none">İletişim: ${ILETISIM_GOSTERIM}</a>
        <button type="button" data-lisans="cikis" style="padding:8px;border:0;background:transparent;color:var(--rc-sol,#6b7194);cursor:pointer">Çıkış Yap</button>
      </div>
    </div>`;
  kap.addEventListener('click', (e) => {
    const b = e.target.closest('[data-lisans]');
    if (!b) return;
    if (b.dataset.lisans === 'tekrar') location.reload();
    if (b.dataset.lisans === 'cikis') cikis();
  });
  document.body.appendChild(kap);
  kap.querySelector('[data-lisans="tekrar"]').focus();
}
