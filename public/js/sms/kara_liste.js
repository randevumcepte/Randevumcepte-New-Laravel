/**
 * SMS Yönetimi › KARA LİSTE. Veri: GET|POST /sms/kara-liste · DELETE /sms/kara-liste/{userId}.
 * Modül pasifse (acilis.kara_liste_aktif) ekleme kapalı; mevcut kayıt listelenir ve çıkarılabilir.
 */
import { esc, mesaj, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=7';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';
import { musteriSecici } from '/js/lib/v2-secici.js?v=7';
import { kilitle } from '/js/lib/v2-bilesen.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=7';

export async function ciz(kap, ctx) {
  const { yetkiler, kara_liste_aktif: aktif } = ctx.acilis;
  kap.innerHTML = `
    <section class="sm-panel">
      <div class="sm-panel-bas"><h3><i class="bi bi-slash-circle"></i> Kara Liste</h3>
        ${yetkiler.yazabilir && aktif ? '<button type="button" class="rc-btn rc-btn-birincil sm-kucuk-btn" id="klEkle"><i class="bi bi-plus-lg"></i> Kara Listeye Ekle</button>' : ''}</div>
      ${aktif ? '' : '<div class="sm-uyari-satir">Kara liste modülü bu işletmede pasif.</div>'}
      <div id="klTablo"></div>
    </section>`;
  const $ = (s) => kap.querySelector(s);

  const tablo = veriTablosu($('#klTablo'), {
    anahtar: 'sms_kara_liste',
    birim: 'müşteri',
    bosMesaj: 'Kara listede müşteri yok.',
    araIpucu: 'Müşteri veya telefon ara…',
    sirala: { alan: 'tarih', yon: 'desc' },
    kolonlar: [
      { baslik: 'Müşteri', sirala: 'ad' },
      { baslik: 'Telefon', sirala: 'telefon' },
      { baslik: 'Eklenme Tarihi', sirala: 'tarih' },
      { baslik: 'İşlemler' },
    ],
    satir: (k) => `<tr><td>${esc(k.ad_soyad)}</td><td class="sm-nowrap">${esc(k.telefon)}</td><td>${esc(k.eklenme_tarihi)}</td>
      <td>${yetkiler.yazabilir ? `<button type="button" class="rc-islem" data-cikar="${k.user_id}"><i class="bi bi-x-circle"></i> Listeden Kaldır</button>` : ''}</td></tr>`,
    kaynak: (p) => ctx.oku('/kara-liste', p),
  });
  tablo.yenile();

  $('#klTablo').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-cikar]');
    if (!b) return;
    if (!await onay('Müşteri kara listeden çıkarılsın mı? Artık SMS bildirimlerini alacak.', { baslik: 'Kara listeden çıkar', tamam: 'Çıkar', tehlike: false })) return;
    const r = await ctx.yaz('DELETE', `/kara-liste/${b.dataset.cikar}`);
    if (yazSonuc(r, r.govde?.mesaj)) tablo.yenile(false);
  });

  $('#klEkle')?.addEventListener('click', () => {
    let secilen = null;
    const ov = modalAc('Kara Listeye Ekle', '<div class="rc-alan rc-tam"><label>Engellemek istediğiniz müşteri</label><div id="klSecici"></div></div>',
      '<button class="rc-btn rc-btn-ikincil" data-kapat>Kapat</button><button class="rc-btn rc-btn-birincil" id="klKaydet"><i class="bi bi-plus-lg"></i> Ekle</button>',
      { ikon: 'slash-circle', sabit: true });
    musteriSecici(ov.querySelector('#klSecici'), { salonId: ctx.salonId, yol: '/sms/musteri-ara', onSec: (m) => { secilen = m; } });
    ov.querySelector('[data-kapat]').onclick = modalKapat;
    ov.querySelector('#klKaydet').onclick = async (ev) => {
      if (!secilen) { mesaj('Müşteri seçiniz.', 'hata'); return; }
      if (!await onay(`${secilen.ad} kara listeye eklensin mi? Artık SMS bildirimlerini almayacak.`, { baslik: 'Kara listeye ekle', tamam: 'Ekle', tehlike: false })) return;
      kilitle(ev.target, 'Ekleniyor…', async () => {
        const r = await ctx.yaz('POST', '/kara-liste', { user_id: secilen.id });
        if (yazSonuc(r, r.govde?.mesaj)) { modalKapat(); tablo.yenile(); }
      });
    };
  });
}
