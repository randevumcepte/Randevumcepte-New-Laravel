/**
 * WhatsApp › MESAJLARIM: durum + tarih filtreli gönderim logu (ortak veriTablosu + sunucu sayfalama);
 * tablo içi arama mesaj, hata, müşteri ve (yetkiliyse) telefonda. Satıra tık: mesaj detayı.
 */
import { esc } from '/js/lib/v2-ortak.js?v=1';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=2';
import { durumRozet, mesajDetayAc, tarihSaat } from '/js/whatsapp/ortak.js?v=1';

export async function ciz(kap, ctx) {
  const filtre = { durum: '', baslangic: '', bitis: '' };
  kap.innerHTML = `
    <section class="wa-kart">
      <div class="wa-filtre">
        <div class="rc-alan"><label for="waFDurum">Durum</label><select id="waFDurum" data-arama="yok">
          <option value="">Tümü</option>
          ${ctx.acilis.sozluk.gonderim_durumlari.map((d) => `<option value="${esc(d.kod)}">${esc(d.ad)}</option>`).join('')}
        </select></div>
        <div class="rc-alan"><label>Başlangıç</label><input type="text" data-tarih="baslangic" placeholder="gg.aa.yyyy" autocomplete="off"></div>
        <div class="rc-alan"><label>Bitiş</label><input type="text" data-tarih="bitis" placeholder="gg.aa.yyyy" autocomplete="off"></div>
      </div>
      <div id="waLogTablo"></div>
    </section>`;

  let satirlar = [];
  const tablo = veriTablosu(kap.querySelector('#waLogTablo'), {
    anahtar: 'whatsapp_loglar',
    varsayilanBoy: 25,
    birim: 'mesaj',
    bosMesaj: 'Bu filtre için mesaj yok.',
    araIpucu: 'Mesaj, müşteri, telefon…',
    sirala: { alan: 'tarih', yon: 'desc' },
    kolonlar: [
      { baslik: 'Tarih', sirala: 'tarih' }, { baslik: 'Müşteri', sirala: 'musteri' }, { baslik: 'Telefon', sirala: 'telefon' },
      { baslik: 'Durum', sirala: 'durum' }, { baslik: 'Mesaj' }, { baslik: 'Hata' },
    ],
    satir: (m, i) => `
      <tr class="wa-tikla" data-i="${i}">
        <td class="wa-nowrap">${esc(tarihSaat(m.created_at))}</td>
        <td>${esc(m.musteri_adi || '—')}</td>
        <td class="wa-nowrap">${esc(m.telefon)}</td>
        <td>${durumRozet(ctx, m.durum)}</td>
        <td><div class="wa-kisalt">${esc(m.mesaj)}</div></td>
        <td class="wa-hata-metin">${esc(m.hata || '')}</td>
      </tr>`,
    kaynak: (p) => ctx.oku('/whatsapp/loglar', { ...p, ...filtre }),
    cizildi: (liste) => { satirlar = liste; },
  });

  kap.querySelector('#waLogTablo').addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-i]');
    if (tr) mesajDetayAc(ctx, satirlar[Number(tr.dataset.i)]);
  });
  kap.querySelector('#waFDurum').addEventListener('change', (e) => { filtre.durum = e.target.value; tablo.yenile(); });
  kap.querySelectorAll('[data-tarih]').forEach((g) => {
    const degisti = (v) => { filtre[g.dataset.tarih] = v; tablo.yenile(); };
    if (window.flatpickr) {
      window.flatpickr(g, { locale: 'tr', dateFormat: 'Y-m-d', altInput: true, altFormat: 'd.m.Y', allowInput: true, disableMobile: true, onChange: (_d, s) => degisti(s) });
    } else {
      g.addEventListener('change', () => degisti(g.value));
    }
  });
  tablo.yenile();
}
