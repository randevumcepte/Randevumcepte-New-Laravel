/**
 * v2 ORTAK DÖNEM SEÇİCİ — tüm rapor ekranları (Satış Raporları, İşletme Raporları, Kasa,
 * Gün Sonu) TEK bileşen. Seçenek listesi API'den gelir (/rapor/secenekler → donemler);
 * tarih aralığı SUNUCUDA çözülür (RaporDonemi) — burada tarih hesabı yok.
 * Stil: css/rapor-v2.css .rp-donem*. Import yolu her yerde AYNI olmalı:
 *   import { donemSecici } from '/js/lib/v2-donem.js?v=1';
 *
 *   const d = donemSecici(el('#kap'), { donemler, varsayilan: 'ay', onDegis: () => yukle() });
 *   d.params()          // { donem, baslangic?, bitis? } → API isteğine eklenir
 *   d.goster(v.donem)   // cevaptaki çözülmüş aralığı ("01.11.2026 – 30.11.2026") yazar
 *
 * Rapor ekranlarının ortak biçimleri de buradadır: trTarih (Y-m-d[ H:i] → gg.aa.yyyy), sayi (tr tamsayı).
 */
import { el, esc } from '/js/lib/v2-ortak.js?v=1';

export const trTarih = (ymd) => (ymd ? String(ymd).slice(0, 10).split('-').reverse().join('.') : '');
export const sayi = (n) => (Number(n) || 0).toLocaleString('tr-TR');

export function donemSecici(kap, { donemler = [], varsayilan = 'ay', onDegis = () => {}, etiket = 'Dönem' }) {
  const durum = { donem: varsayilan, baslangic: '', bitis: '' };

  kap.innerHTML = `
    <div class="rp-donem">
      <label class="rc-alan rp-donem-sec"><span class="rp-etiket">${esc(etiket)}</span>
        <select data-arama="yok">${donemler.map((d) => `<option value="${esc(d.kod)}"${d.kod === varsayilan ? ' selected' : ''}>${esc(d.ad)}</option>`).join('')}</select>
      </label>
      <div class="rp-donem-ozel" hidden>
        <label class="rc-alan"><span class="rp-etiket">Başlangıç</span><input type="text" data-alan="baslangic" placeholder="gg.aa.yyyy" autocomplete="off"></label>
        <label class="rc-alan"><span class="rp-etiket">Bitiş</span><input type="text" data-alan="bitis" placeholder="gg.aa.yyyy" autocomplete="off"></label>
      </div>
      <div class="rp-donem-aralik" aria-live="polite"></div>
    </div>`;

  const ozel = el('.rp-donem-ozel', kap);
  el('select', kap).addEventListener('change', (e) => {
    durum.donem = e.target.value;
    ozel.hidden = durum.donem !== 'ozel';
    if (durum.donem !== 'ozel') onDegis();
    else if (durum.baslangic) onDegis();
  });

  kap.querySelectorAll('[data-alan]').forEach((girdi) => {
    const alan = girdi.dataset.alan;
    const degisti = (v) => {
      if (v === durum[alan]) return;
      durum[alan] = v;
      if (durum.baslangic) onDegis(); // bitiş boşsa sunucu tek gün sayar
    };
    if (window.flatpickr) {
      window.flatpickr(girdi, { dateFormat: 'Y-m-d', altInput: true, altFormat: 'd.m.Y', locale: 'tr', allowInput: true, disableMobile: true, onChange: (_d, s) => degisti(s) });
    } else {
      girdi.addEventListener('change', (e) => degisti(e.target.value));
    }
  });

  return {
    params() {
      return durum.donem === 'ozel'
        ? { donem: 'ozel', baslangic: durum.baslangic, bitis: durum.bitis || durum.baslangic }
        : { donem: durum.donem };
    },
    goster(d) {
      if (!d) return;
      el('.rp-donem-aralik', kap).textContent = d.tek_gun ? trTarih(d.baslangic) : `${trTarih(d.baslangic)} – ${trTarih(d.bitis)}`;
    },
  };
}
