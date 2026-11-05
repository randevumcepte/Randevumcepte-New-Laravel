/**
 * Ders Programı (v2) — build'siz island. Eski isletmeadmin/ders_programi.blade karşılığı:
 * günlere göre haftalık şablon + ekle/düzenle/sil + "Programı Yayınla". Hafta tavanı ve bitiş
 * tarihi kuralı /studyo/politika'dan (stüdyo 52 hafta + bitiş; diğer 12 hafta).
 */
import { qp, api, el, esc, onay, tarihStr, yazSonuc, mesaj } from '/js/lib/v2-ortak.js?v=1';
import { modalKapat } from '/js/lib/v2-modal.js?v=1';
import { kilitle } from '/js/lib/v2-bilesen.js?v=2';
import { alan, tarihBagla } from '/js/lib/v2-ongorusme.js?v=1';
import { GUNLER, dersFormu, programGetir } from '/js/lib/v2-ders.js?v=2';

const kok = document.getElementById('rc-ders-programi');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
let fv = null;
let politika = {};

function sablonKart(s) {
  return `<div class="dp-ders">
    <div class="dp-ders-ust"><span>${esc(s.ders_tipi || 'Grup Dersi')}</span><span class="dp-ders-kisi">${s.kapasite} kişi</span></div>
    <div class="dp-ders-sat"><i class="bi bi-clock"></i> ${esc(s.saat)} – ${esc(s.saat_bitis)}</div>
    <div class="dp-ders-sat"><i class="bi bi-person"></i> ${esc(s.personel || '—')}</div>
    <span class="dp-ders-etiket${s.hizmet_id ? '' : ' yok'}">${s.hizmet_id ? 'paket düşer' : 'paket bağlı değil'}</span>
    <div class="dp-ders-aksiyon"><button type="button" data-duzenle="${s.id}"><i class="bi bi-pencil"></i> Düzenle</button>
      <button type="button" class="sil" data-sil="${s.id}" title="Sil" aria-label="Sil"><i class="bi bi-trash"></i></button></div>
  </div>`;
}

function izgaraCiz() {
  el('#dpIzgara').innerHTML = GUNLER.map((g) => `
    <div class="dp-kolon"><div class="dp-kolon-bas">${g.ad}</div><div class="dp-kolon-govde">
      ${fv.sablon.filter((s) => s.hafta_gunu === g.id).map(sablonKart).join('')}
      <button type="button" class="dp-ekle" data-ekle="${g.id}"><i class="bi bi-plus-lg"></i> Ders Ekle</button>
    </div></div>`).join('');
  kok.querySelectorAll('[data-ekle]').forEach((b) => { b.onclick = () => form({ hafta_gunu: Number(b.dataset.ekle) }); });
  kok.querySelectorAll('[data-duzenle]').forEach((b) => { b.onclick = () => form(fv.sablon.find((s) => String(s.id) === b.dataset.duzenle)); });
  kok.querySelectorAll('[data-sil]').forEach((b) => { b.onclick = () => sil(b.dataset.sil); });
}

function form(k) {
  dersFormu({
    salonId, fv, tur: 'sablon', kayit: k, baslik: k.id ? 'Ders Düzenle' : 'Ders Ekle',
    kaydet: (g) => (k.id ? api('PATCH', `/ders/sablon/${k.id}`, { body: g }) : api('POST', '/ders/sablon', { body: g })),
    onKaydet: async (v) => {
      modalKapat();
      if (v.senkron > 0) mesaj(`${v.senkron} mevcut ders oturumu da güncellendi.`, 'ok');
      await yukle();
    },
  });
}

async function sil(id) {
  const tamam = await onay('Bu program satırı ve ondan üretilmiş, katılımcısı olmayan gelecek dersler kaldırılacak. Katılımcısı olan dersler korunur.', { baslik: 'Silinsin mi?', tamam: 'Sil', iptal: 'Vazgeç' });
  if (!tamam) return;
  const r = await api('DELETE', `/ders/sablon/${id}`);
  if (!yazSonuc(r)) return;
  const { temizlenen, korunan } = r.govde.veri;
  mesaj(`${temizlenen} gelecek ders kaldırıldı${korunan ? `, ${korunan} ders katılımcısı olduğu için korundu` : ''}.`, 'ok');
  await yukle();
}

async function yayinla(btn) {
  const baslangic = el('#dpBaslangic').value, hafta = el('#dpHafta').value, bitis = el('#dpBitis')?.value || '';
  const ozet = bitis ? `${baslangic} – ${bitis} aralığında` : `${baslangic} tarihinden itibaren ${hafta} hafta boyunca`;
  if (!await onay(`${ozet} ders oturumları takvime oluşturulacak.`, { baslik: 'Program yayınlansın mı?', tamam: 'Yayınla', iptal: 'Vazgeç' })) return;
  await kilitle(btn, 'Yayınlanıyor…', async () => {
    const r = await api('POST', '/ders/yayinla', { body: { salon_id: salonId, baslangic, hafta: Number(hafta), bitis: bitis || null } });
    if (yazSonuc(r)) mesaj(`${r.govde.veri.olusan} yeni ders oluşturuldu (${r.govde.veri.atlanan} zaten vardı).`, 'ok');
  });
}

async function yukle() {
  const r = await programGetir(salonId);
  if (!r.ok || !r.govde?.basarili) { el('#dpIzgara').innerHTML = `<div class="do-bos">${esc(r.govde?.mesaj || `Yüklenemedi (${r.kod})`)}</div>`; return; }
  fv = r.govde.veri;
  izgaraCiz();
}

(async function basla() {
  if (!salonId) { kok.innerHTML = '<div class="do-bos">Şube seçili değil.</div>'; return; }
  const p = await api('GET', '/studyo/politika', { params: { salon_id: salonId } });
  politika = p.govde?.veri || {};
  kok.innerHTML = `
    <div class="rc-sayfa-ust"><div class="rc-sayfa-baslik"><span class="rc-sayfa-ikon"><i class="bi bi-grid-3x3-gap-fill"></i></span>
      <div><h1>Ders Programı</h1><div class="rc-sayfa-alt">Haftalık sabit programı tanımlayın; "Programı Yayınla" ileriye dönük dersleri takvime oluşturur.</div></div></div></div>
    <div class="dp-yayin">
      ${alan('Başlangıç', `<input id="dpBaslangic" value="${tarihStr(new Date())}" autocomplete="off">`)}
      ${alan('Hafta', `<input id="dpHafta" type="number" min="1" max="${politika.yayin_maks_hafta || 12}" value="4">`)}
      ${politika.yayin_bitis_tarihi ? alan('Bitiş <small>(opsiyonel)</small>', '<input id="dpBitis" autocomplete="off">') : ''}
      <button class="rc-btn rc-btn-yesil" id="dpYayinla" type="button"><i class="bi bi-rocket-takeoff"></i> Programı Yayınla</button>
    </div>
    <div class="dp-izgara" id="dpIzgara"></div>`;
  tarihBagla(el('#dpBaslangic'), { minDate: 'today' });
  if (el('#dpBitis')) tarihBagla(el('#dpBitis'), { minDate: 'today' });
  el('#dpYayinla').onclick = (e) => yayinla(e.currentTarget);
  await yukle();
}());
