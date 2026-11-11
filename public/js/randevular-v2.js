/**
 * Randevular (v2) — build'siz island (sade ES module).
 *
 * Eski /isletmeyonetim/randevular-liste ekranının v2 karşılığı: Zaman / Kaynak / Durum /
 * Hizmet filtreleri + "Yeni Randevu" + tüm randevuların tablosu. Filtre seçenekleri ve
 * tarih aralığı SUNUCUDAN (GET /randevu/liste/secenekler, RandevuZamani) — Flutter aynı uçlar.
 * Tablo, satır işlemleri (detay çekmecesi, onayla, düzenle, iptal) ve randevu formu
 * müşteri kartı + takvimle ORTAK bileşen (lib/v2-randevu.js); zaman seçici rapor
 * ekranlarıyla ortak (lib/v2-donem.js, stil css/rapor-v2.css).
 */
import { qp, api, el, esc } from '/js/lib/v2-ortak.js?v=7';
import { donemSecici } from '/js/lib/v2-donem.js?v=7';
import { randevuTablosu } from '/js/lib/v2-randevu.js?v=13';

const kok = document.getElementById('rc-randevular');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;

const secim = (id, etiket, ilk, liste) => `
  <label class="rc-alan"><span class="rp-etiket">${esc(etiket)}</span>
    <select id="${id}"><option value="">${esc(ilk)}</option>
      ${liste.map((x) => `<option value="${esc(x.kod ?? x.id)}">${esc(x.ad)}</option>`).join('')}</select></label>`;

async function baslat() {
  if (!salonId) { kok.innerHTML = '<div class="rc-ipucu">Şube seçili değil.</div>'; return; }
  window.rcPanel?.salonAyarla(salonId);

  const r = await api('GET', '/randevu/liste/secenekler', { params: { salon_id: salonId } });
  if (!r.ok || !r.govde?.basarili) { kok.innerHTML = `<div class="rc-ipucu">${esc(r.govde?.mesaj || `Hata (${r.kod})`)}</div>`; return; }
  const s = r.govde.veri;

  kok.innerHTML = `
    <div class="rc-sayfa-ust">
      <div class="rc-sayfa-baslik">
        <span class="rc-sayfa-ikon"><i class="bi bi-card-heading"></i></span>
        <div><h1>Randevular</h1><div class="rc-sayfa-alt">Tüm randevular — filtreleyin, onaylayın, düzenleyin</div></div>
      </div>
      <div class="rc-sayfa-aksiyon"><button class="rc-btn rc-btn-yesil" id="rlYeni"><i class="bi bi-plus-lg"></i> Yeni Randevu</button></div>
    </div>
    <div class="rp-filtre">
      <div id="rlDonem"></div>
      ${secim('rlKaynak', 'Kaynak', 'Tümü', s.kaynaklar)}
      ${secim('rlDurum', 'Durum', 'Tüm Durumlar', s.durumlar)}
      ${secim('rlHizmet', 'Hizmet', 'Tüm Hizmetler', s.hizmetler)}
    </div>
    <div id="rlTablo"></div>`;
  // Bağlantıyla gelen durum filtresi (ör. hatırlatma kartı ?durum=beklemede).
  if ([...el('#rlDurum').options].some((o) => o.value === qp.get('durum'))) el('#rlDurum').value = qp.get('durum');

  let liste = null;
  const donem = donemSecici(el('#rlDonem'), {
    donemler: s.zamanlar, varsayilan: s.varsayilan_zaman, etiket: 'Zaman', onDegis: () => liste.tablo.yenile(),
  });
  liste = randevuTablosu(el('#rlTablo'), {
    salonId,
    yol: '/randevu/liste',
    params: () => ({ ...donem.params(), kaynak: el('#rlKaynak').value, durum: el('#rlDurum').value, hizmet_id: el('#rlHizmet').value }),
    // "Tüm Zamanlar"da aralık yok — önceki aralık yazısı kalmasın.
    cizildi: (_, v) => (v?.donem ? donem.goster(v.donem) : (el('.rp-donem-aralik', kok).textContent = '')),
  });

  ['#rlKaynak', '#rlDurum', '#rlHizmet'].forEach((id) => el(id).addEventListener('change', () => liste.tablo.yenile()));
  el('#rlYeni').onclick = () => liste.islem.randevuFormu({});
}

baslat().catch((e) => { console.error(e); kok.innerHTML = '<div class="rc-ipucu">Sayfa yüklenemedi.</div>'; });
