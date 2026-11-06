/**
 * SMS Yönetimi (v2) — build'siz island (sade ES module). Eski isletmeadmin/toplusmsgonder.blade karşılığı.
 *
 * Sekmeler, yetkiler, sözlükler, bakiye, şablonlar ve ayarlar TEK istekte /api/v2/sms/acilis'ten
 * (Flutter da aynı uç). Her sekme kendi modülünü tembel yükler (public/js/sms/<kod>.js →
 * export async function ciz(kap, ctx)). YENİ SEKME: sunucuda SmsSekmeleri'ne tek satır + MODULLER'e tek satır.
 */
import { qp, api, el, esc } from '/js/lib/v2-ortak.js?v=2';
import { sekmeliSayfa } from '/js/lib/v2-sekme.js?v=1';

const kok = document.getElementById('rc-sms');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
if (salonId && window.rcPanel) window.rcPanel.salonAyarla(salonId);

const MODULLER = {
  raporlar: () => import('/js/sms/raporlar.js?v=1'),
  toplu: () => import('/js/sms/toplu.js?v=2'),
  ayarlar: () => import('/js/sms/ayarlar.js?v=1'),
  kara_liste: () => import('/js/sms/kara_liste.js?v=1'),
};
const YOL = '/sms';

(async function baslat() {
  kok.innerHTML = `
    <div class="sm-kabuk">
      <div class="rc-sayfa-ust">
        <div class="rc-sayfa-baslik">
          <span class="rc-sayfa-ikon"><i class="bi bi-chat-square-text"></i></span>
          <div><h1>SMS Yönetimi</h1><div class="rc-sayfa-alt" id="smAlt">Raporlar, şablonlar, toplu gönderim, ayarlar ve kara liste</div></div>
        </div>
        <div class="rc-sayfa-aksiyon"><span class="sm-bakiye" id="smBakiye" hidden></span></div>
      </div>
      <details class="sm-uyari" id="smUyari" hidden>
        <summary><i class="bi bi-exclamation-triangle"></i> Ticari ileti gönderimi ve yasal yükümlülükler</summary>
        <p></p>
      </details>
      <nav class="rc-sekmeler" id="smSekmeler"></nav>
      <div id="smIcerik"><div class="sm-bos"><span class="rc-yukleniyor"></span></div></div>
    </div>`;
  if (!salonId) { el('#smIcerik').innerHTML = '<div class="sm-bos">Şube seçili değil (?sube=... bekleniyor).</div>'; return; }

  const r = await api('GET', `${YOL}/acilis`, { params: { salon_id: salonId } });
  if (!r.ok || !r.govde?.basarili) {
    el('#smIcerik').innerHTML = `<div class="sm-bos sm-hata">${esc(r.govde?.mesaj || 'SMS Yönetimi yüklenemedi')}</div>`;
    return;
  }
  const acilis = r.govde.veri;

  el('#smUyari').hidden = false;
  el('#smUyari p').textContent = acilis.yasal_uyari;
  const bakiyeCiz = (kalan) => {
    const b = el('#smBakiye');
    b.hidden = kalan === null || kalan === undefined;
    b.innerHTML = b.hidden ? '' : `<i class="bi bi-coin"></i> Kalan SMS: <b>${esc(Number(kalan).toLocaleString('tr-TR'))}</b>`;
  };
  bakiyeCiz(acilis.kalan_sms);

  const ctx = {
    salonId,
    acilis,
    oku: (yol, params = {}) => api('GET', YOL + yol, { params: { ...params, salon_id: salonId } }),
    yaz: (method, yol, body = {}) => api(method, YOL + yol, { body: { ...body, salon_id: salonId } }),
    bakiyeYenile: async () => {
      const b = await api('GET', `${YOL}/bakiye`, { params: { salon_id: salonId } });
      if (b.ok && b.govde?.basarili) bakiyeCiz(b.govde.veri.kalan_sms);
    },
  };
  sekmeliSayfa(el('#smSekmeler'), el('#smIcerik'), { sekmeler: acilis.sekmeler, moduller: MODULLER, ctx });
})();
