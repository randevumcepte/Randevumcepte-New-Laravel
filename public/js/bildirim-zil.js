/**
 * Panel bildirim zili — eski layout_isletmeadmin + custom.js dropdown'unun v2 karşılığı. Tüm v2
 * ekranlarında görünür; veri /api/v2/bildirim (Flutter yönetici ile aynı uçlar).
 * Sayaç 60 sn'de bir ve sekmeye dönünce tazelenir (arka plan sekmesi sorgu atmaz); liste yalnız zil açılınca yüklenir.
 */
import { api, el, esc, onay, pad, yazSonuc } from '/js/lib/v2-ortak.js?v=2';

const POLL_MS = 60000;
const SAYFA_BOYU = 30;

const d = { salon: null, nesil: 0, sayac: { okunmamis: 0, toplam: 0 }, kayitlar: [], sayfa: 1, sonSayfa: 1, yukleniyor: false };

function zaman(tarih) {
  const t = new Date(String(tarih).replace(' ', 'T'));
  if (Number.isNaN(t.getTime())) return '';
  const dk = Math.max(0, Math.round((Date.now() - t.getTime()) / 60000));
  if (dk < 60) return `${dk} dakika önce`;
  if (dk < 1440) return `${Math.round(dk / 60)} saat önce`;
  return `${pad(t.getDate())}.${pad(t.getMonth() + 1)}.${t.getFullYear()} ${pad(t.getHours())}:${pad(t.getMinutes())}`;
}

function sayacCiz(sayac) {
  if (sayac) d.sayac = sayac;
  const rozet = el('#rcBildirimRozet');
  const n = d.sayac.okunmamis;
  rozet.hidden = n <= 0;
  rozet.textContent = n > 99 ? '99+' : String(n);
}

const ogeCiz = (b) => `
  <div class="rc-zil-oge${b.okundu ? '' : ' okunmamis'}" data-id="${b.id}">
    <button class="rc-zil-link" type="button" data-git>
      ${b.resim_url
        ? `<img class="rc-zil-gorsel" src="${esc(b.resim_url)}" alt="" loading="lazy">`
        : '<span class="rc-zil-gorsel"><i class="bi bi-bell"></i></span>'}
      <span class="rc-zil-govde">
        ${b.baslik ? `<strong>${esc(b.baslik)}</strong>` : ''}${esc(b.aciklama)}
        <span class="rc-zil-zaman">${esc(zaman(b.tarih_saat))}</span>
      </span>
    </button>
    <button class="rc-zil-eylem" type="button" data-sil title="Sil" aria-label="Bildirimi sil"><i class="bi bi-x-lg"></i></button>
  </div>`;

function menuCiz() {
  const { okunmamis, toplam } = d.sayac;
  el('#rcBildirimMenu').innerHTML = `
    <div class="rc-zil-bas">
      <span class="rc-zil-baslik">Bildirimler</span>
      ${okunmamis > 0 ? `<span class="rc-zil-yeni">${okunmamis} yeni</span>` : ''}
      <span class="rc-zil-eylemler">
        ${okunmamis > 0 ? '<button class="rc-zil-eylem" type="button" data-tumunu-oku title="Tümünü okundu işaretle" aria-label="Tümünü okundu işaretle"><i class="bi bi-check2-all"></i></button>' : ''}
        ${toplam > 0 ? '<button class="rc-zil-eylem" type="button" data-tumunu-sil title="Tümünü sil" aria-label="Tümünü sil"><i class="bi bi-trash3"></i></button>' : ''}
      </span>
    </div>
    <div class="rc-zil-liste">
      ${d.kayitlar.length ? d.kayitlar.map(ogeCiz).join('') : `<div class="rc-zil-bos">${d.yukleniyor ? 'Yükleniyor…' : 'Bildiriminiz bulunmamaktadır'}</div>`}
    </div>
    ${d.sayfa < d.sonSayfa ? '<button class="rc-zil-daha" type="button" data-daha>Daha fazla göster</button>' : ''}`;
}

async function sayacYenile() {
  const salon = d.salon;
  if (!salon) return;
  const r = await api('GET', '/bildirim/sayac', { params: { salon_id: salon } });
  if (salon === d.salon && r.ok && r.govde?.basarili) sayacCiz(r.govde.veri);
}

async function listeYukle(sayfa = 1) {
  const { salon } = d;
  d.yukleniyor = true;
  if (sayfa === 1) menuCiz();
  const r = await api('GET', '/bildirim', { params: { salon_id: salon, page: sayfa, per_page: SAYFA_BOYU } });
  if (salon !== d.salon) return;
  d.yukleniyor = false;
  if (!r.ok || !r.govde?.basarili) { yazSonuc(r); menuCiz(); return; }
  const v = r.govde.veri;
  d.kayitlar = sayfa === 1 ? v.data : d.kayitlar.concat(v.data);
  d.sayfa = v.current_page;
  d.sonSayfa = v.last_page;
  sayacCiz(v.sayac);
  menuCiz();
}

/** Yazma isteği; başarıda yeni sayaç (hata mesajını ortak yazSonuc gösterir). */
async function yaz(method, yol) {
  const r = await api(method, yol, { params: { salon_id: d.salon } });
  if (!r.ok || !r.govde?.basarili) { yazSonuc(r); return false; }
  sayacCiz(r.govde.veri);
  return true;
}

async function ogeyeGit(b) {
  if (!b.okundu) await yaz('POST', `/bildirim/${b.id}/okundu`);
  location.href = b.hedef.web_yol;
}

async function menuTikla(e) {
  e.stopPropagation(); // kabuğun "dışarı tıklandı" kapanışı silme/okundu sırasında menüyü kapatmasın
  const dugme = e.target.closest('button');
  if (!dugme) return;
  const id = Number(dugme.closest('.rc-zil-oge')?.dataset.id);
  if ('git' in dugme.dataset) {
    const b = d.kayitlar.find((k) => k.id === id);
    if (b) await ogeyeGit(b);
  } else if ('sil' in dugme.dataset) {
    dugme.disabled = true;
    if (await yaz('DELETE', `/bildirim/${id}`)) {
      d.kayitlar = d.kayitlar.filter((k) => k.id !== id);
      menuCiz();
    } else dugme.disabled = false;
  } else if ('tumunuOku' in dugme.dataset) {
    if (await yaz('POST', '/bildirim/tumu/okundu')) await listeYukle();
  } else if ('tumunuSil' in dugme.dataset) {
    if (await onay('Tüm bildirimler silinecek. Devam edilsin mi?') && await yaz('DELETE', '/bildirim')) {
      d.kayitlar = [];
      d.sonSayfa = 1;
      menuCiz();
    }
  } else if ('daha' in dugme.dataset) {
    dugme.disabled = true;
    await listeYukle(d.sayfa + 1);
  }
}

function salonKur(salonId) {
  salonId = parseInt(salonId, 10) || null;
  if (!salonId || salonId === d.salon) return;
  d.salon = salonId;
  d.kayitlar = [];
  sayacYenile();
  import('/js/bildirim-web-push.js?v=1').then((m) => m.webPushKur(salonId)).catch((h) => console.warn('[web push]', h));
}

const kap = el('#rcBildirim');
if (kap) {
  el('#rcBildirimMenu').addEventListener('click', menuTikla);
  // Kabuğun açılır işleyicisi document'ta çalışır: burada sınıf henüz değişmemişken "açılacak mı" okunur.
  el('[data-acilir-tetik]', kap).addEventListener('click', () => {
    if (!kap.classList.contains('acik') && d.salon) listeYukle();
  });
  setInterval(() => { if (!document.hidden) sayacYenile(); }, POLL_MS);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) sayacYenile(); });
  document.addEventListener('rc:bildirim', sayacYenile);
  document.addEventListener('rc:kabuk', (e) => salonKur(e.detail?.salon?.id));
  if (window.rcPanel?.veri) salonKur(window.rcPanel.veri.salon?.id);
}
