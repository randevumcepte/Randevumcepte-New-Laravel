/**
 * v2 VERİ TABLOSU — TÜM v2 tabloları için tek bileşen (DataTables mantığı, bağımlılıksız).
 *
 * Her tabloda aynı davranış:
 *   - "Sayfada [10|25|50|100] kayıt göster" (tercih tablo başına localStorage'da)
 *   - "Ara": tablo İÇERİĞİNDE arama (350 ms gecikmeli)
 *   - Başlığa tıkla sırala: ilk tık artan, tekrar tık azalan (kolon.sirala), sayfalama,
 *     yükleniyor / boş / hata satırı
 *
 * İki kaynak modu:
 *   SUNUCU : kaynak({page, per_page, ara, sirala, yon}) → api() sonucu ({ok,kod,govde});
 *            arama/sıralama/sayfalama uçta (ListeIstegi sözleşmesi) — Flutter da aynı uç.
 *   YEREL  : kayitlarAyarla(dizi) — küçük, zaten yüklenmiş listeler; arama araMetni(kayit)
 *            ile, sıralama kolon.deger(kayit) ile tarayıcıda yapılır.
 *
 * Kullanım:
 *   const t = veriTablosu(el('#kap'), {
 *     anahtar: 'satislar',                       // sayfa boyu tercihi anahtarı
 *     kolonlar: [{ baslik: 'Tarih', sirala: 'tarih' }, { baslik: 'Tutar', sinif: 'rc-sag' }],
 *     satir: (k, i, liste) => `<tr>…</tr>`,
 *     kaynak: (p) => api('GET', '/satislar', { params: { ...p, salon_id } }),
 *     cizildi: (liste, veri) => {},              // özet kartları, olay bağlama vb.
 *     birim: 'kayıt', bosMesaj: 'Kayıt bulunamadı.', araIpucu: 'Müşteri, hizmet…',
 *     sirala: { alan: 'tarih', yon: 'desc' }, tabloSinif: 's-tablo',
 *   });
 *   t.yenile();            // filtre değişince (sayfa 1'e döner)
 *   t.kolonlarAyarla([...]) // kolon seti değişince (ör. stüdyo modu)
 */
import { el, esc, sayfalamaCiz } from '/js/lib/v2-ortak.js?v=2';

export const SAYFA_BOYLARI = [10, 25, 50, 100];

const boyOku = (anahtar, vars) => {
  try {
    const v = parseInt(localStorage.getItem('rc_dt_boy_' + anahtar), 10);
    return SAYFA_BOYLARI.includes(v) ? v : vars;
  } catch (_) { return vars; }
};
const boyYaz = (anahtar, v) => { try { localStorage.setItem('rc_dt_boy_' + anahtar, String(v)); } catch (_) { /* yok say */ } };

const normal = (s) => String(s ?? '').toLocaleLowerCase('tr').replace(/ı/g, 'i').normalize('NFD').replace(/[̀-ͯ]/g, '');

export function veriTablosu(kap, opt) {
  const o = {
    birim: 'kayıt', bosMesaj: 'Kayıt bulunamadı.', araIpucu: 'Tabloda ara…', tabloSinif: '',
    varsayilanBoy: 10, ...opt,
  };
  let kolonlar = o.kolonlar || [];
  const durum = {
    page: 1,
    per_page: boyOku(o.anahtar || 'genel', o.varsayilanBoy),
    ara: '',
    sirala: o.sirala ? o.sirala.alan : null,
    yon: o.sirala ? o.sirala.yon : 'desc',
  };
  let yerel = null;   // YEREL mod kayıtları
  let istekNo = 0;

  kap.innerHTML = `
    <div class="rc-dt">
      <div class="rc-dt-arac">
        <label class="rc-dt-boy">Sayfada
          <select data-arama="yok">${SAYFA_BOYLARI.map((b) => `<option value="${b}"${b === durum.per_page ? ' selected' : ''}>${b}</option>`).join('')}</select>
          ${esc(o.birim)} göster</label>
        <label class="rc-dt-ara"><i class="bi bi-search"></i>
          <input type="search" placeholder="${esc(o.araIpucu)}" autocomplete="off"></label>
      </div>
      <div class="rc-tablo-sar">
        <table class="rc-tablo ${esc(o.tabloSinif)}"><thead></thead><tbody></tbody></table>
      </div>
      <div class="rc-sayfalama"></div>
    </div>`;
  const thead = el('thead', kap), tbody = el('tbody', kap), sayfaKap = el('.rc-sayfalama', kap);

  el('.rc-dt-boy select', kap).addEventListener('change', (e) => {
    durum.per_page = parseInt(e.target.value, 10);
    boyYaz(o.anahtar || 'genel', durum.per_page);
    durum.page = 1; yukle();
  });
  let zaman;
  el('.rc-dt-ara input', kap).addEventListener('input', (e) => {
    clearTimeout(zaman);
    const v = e.target.value.trim();
    zaman = setTimeout(() => { if (v === durum.ara) return; durum.ara = v; durum.page = 1; yukle(); }, 350);
  });
  thead.addEventListener('click', (e) => {
    const th = e.target.closest('th[data-sirala]');
    if (!th) return;
    const alan = th.dataset.sirala;
    // Yeni kolon → artan; aynı kolona tekrar tık → artan ↔ azalan.
    durum.yon = durum.sirala === alan ? (durum.yon === 'asc' ? 'desc' : 'asc') : 'asc';
    durum.sirala = alan; durum.page = 1; yukle();
  });

  const kolonSayisi = () => Math.max(1, kolonlar.length);
  const bilgiSatiri = (html, sinif = '') => { tbody.innerHTML = `<tr><td colspan="${kolonSayisi()}" class="rc-bos ${sinif}">${html}</td></tr>`; };

  function baslikCiz() {
    thead.innerHTML = '<tr>' + kolonlar.map((k) => {
      if (!k.sirala) return `<th class="${esc(k.sinif || '')}">${esc(k.baslik)}</th>`;
      const aktif = durum.sirala === k.sirala;
      const ikon = aktif ? (durum.yon === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill') : 'bi-chevron-expand';
      const ipucu = aktif ? (durum.yon === 'asc' ? 'Artan — azalan için tıklayın' : 'Azalan — artan için tıklayın') : 'Sıralamak için tıklayın';
      return `<th class="rc-siralanir${aktif ? ' aktif' : ''} ${esc(k.sinif || '')}" data-sirala="${esc(k.sirala)}" title="${ipucu}" aria-sort="${aktif ? (durum.yon === 'asc' ? 'ascending' : 'descending') : 'none'}">${esc(k.baslik)} <i class="bi ${ikon}"></i></th>`;
    }).join('') + '</tr>';
  }

  function ciz(liste, meta, veri) {
    baslikCiz();
    if (liste.length) tbody.innerHTML = liste.map((k, i) => o.satir(k, i, liste)).join('');
    else bilgiSatiri(esc(durum.ara ? `“${durum.ara}” için sonuç bulunamadı.` : o.bosMesaj));
    sayfalamaCiz(sayfaKap, meta, (no) => { durum.page = no; yukle(); }, o.birim);
    if (o.cizildi) o.cizildi(liste, veri);
  }

  function yerelCiz() {
    let l = yerel || [];
    if (durum.ara) {
      const a = normal(durum.ara);
      const metin = o.araMetni || ((k) => Object.values(k).join(' '));
      l = l.filter((k) => normal(metin(k)).includes(a));
    }
    const kol = kolonlar.find((k) => k.sirala === durum.sirala);
    if (kol && kol.deger) {
      const c = durum.yon === 'asc' ? 1 : -1;
      l = [...l].sort((x, y) => {
        const a = kol.deger(x), b = kol.deger(y);
        return (typeof a === 'number' && typeof b === 'number' ? a - b : String(a ?? '').localeCompare(String(b ?? ''), 'tr')) * c;
      });
    }
    const toplam = l.length;
    const son = Math.max(1, Math.ceil(toplam / durum.per_page));
    if (durum.page > son) durum.page = son;
    const bas = (durum.page - 1) * durum.per_page;
    ciz(l.slice(bas, bas + durum.per_page), { current_page: durum.page, last_page: son, total: toplam }, null);
  }

  async function yukle() {
    if (yerel !== null) { yerelCiz(); return; }
    if (!o.kaynak) return;
    const no = ++istekNo;
    baslikCiz();
    bilgiSatiri('<span class="rc-yukleniyor"></span> Yükleniyor…');
    const r = await o.kaynak({ page: durum.page, per_page: durum.per_page, ara: durum.ara, sirala: durum.sirala, yon: durum.yon });
    if (no !== istekNo) return; // daha yeni istek var — eski cevabı çizme
    if (!r || !r.ok || !r.govde || !r.govde.basarili) {
      bilgiSatiri(esc((r && r.govde && r.govde.mesaj) || `Hata (${r ? r.kod : '—'})`), 'rc-hata');
      sayfaKap.innerHTML = '';
      return;
    }
    const v = r.govde.veri || {};
    ciz(v.data || [], v, v);
  }

  return {
    durum,
    /** Filtre değişince: sayfa 1'e dönüp yeniden yükler. */
    yenile(sayfaSifirla = true) { if (sayfaSifirla) durum.page = 1; return yukle(); },
    kolonlarAyarla(k) { kolonlar = k || []; baslikCiz(); },
    /** YEREL mod: kayıtları ver, tablo kendi arar/sıralar/sayfalar. */
    kayitlarAyarla(dizi) { yerel = Array.isArray(dizi) ? dizi : []; yerelCiz(); },
    /** Mesaj satırı (ör. "Şube seçili değil"). */
    mesaj(metin) { baslikCiz(); bilgiSatiri(esc(metin)); sayfaKap.innerHTML = ''; },
  };
}
