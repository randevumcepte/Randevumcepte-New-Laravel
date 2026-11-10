/**
 * Ayarlar → Şubeler (v2, salt okuma). Eski #isletme-subeleri tablosu: şube, adres, telefon,
 * üyelik bitişi + "Geçiş Yap". Yeni şube açma (hesap + şifre SMS'i) eski panelde kalır
 * (yeni_sube_url sunucudan). Uç: GET /ayar/subeler.
 */
import { api, el, esc } from '/js/lib/v2-ortak.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=8';

const trTarih = (ymd) => (ymd ? ymd.split('-').reverse().join('.') : '—');

export async function ciz(kap, ctx) {
  kap.innerHTML = `
    <div class="rc-sayfa-ust ay-kaynak-ust">
      <p class="ay-kart-alt">Yetkili olduğunuz işletme / şubeler. "Geçiş Yap" ile paneli o şubeye çevirin.</p>
      <div class="rc-sayfa-aksiyon"><a class="rc-btn rc-btn-birincil" data-yeni hidden><i class="bi bi-plus-lg"></i> Yeni Şube</a></div>
    </div>
    <div data-tablo></div>`;

  const tablo = veriTablosu(el('[data-tablo]', kap), {
    anahtar: 'ayar-subeler', varsayilanBoy: 25, birim: 'şube', araIpucu: 'Şube, adres, telefon…',
    kolonlar: [
      { baslik: 'Şube', sirala: 'salon_adi' }, { baslik: 'Adres' }, { baslik: 'Telefon' },
      { baslik: 'Üyelik Bitiş', sirala: 'uyelik_bitis_tarihi' }, { baslik: 'İşlem', sinif: 'rc-orta' },
    ],
    satir: (s) => {
      const lisans = s.kalan_gun === null ? '' : s.kalan_gun < 0
        ? ' <span class="rc-rozet rc-rozet-kirmizi">Süresi doldu</span>'
        : s.kalan_gun <= 15 ? ` <span class="rc-rozet rc-rozet-turuncu">${s.kalan_gun} gün</span>` : '';
      // Yol parametresi (/v2/ayarlar/{sube}) ?sube='den önce gelir — geçiş linki hep sorgu ile.
      const gecis = `/v2/ayarlar?sube=${encodeURIComponent(s.id)}&p=subeler`;
      return `<tr>
        <td><b>${esc(s.salon_adi)}</b>${s.aktif_sube ? ' <span class="rc-rozet rc-rozet-mor">Şu an</span>' : ''}</td>
        <td>${esc(s.adres || '—')}</td>
        <td class="rc-nowrap">${esc(s.telefon_1 || '—')}</td>
        <td class="rc-nowrap">${trTarih(s.uyelik_bitis_tarihi)}${lisans}</td>
        <td class="rc-orta">${s.aktif_sube ? '' : `<a class="rc-islem" href="${esc(gecis)}"><i class="bi bi-box-arrow-in-right"></i> Geçiş Yap</a>`}</td>
      </tr>`;
    },
    kaynak: (p) => api('GET', '/ayar/subeler', { params: { ...p, salon_id: ctx.salonId } }),
    cizildi: (_l, v) => {
      const b = el('[data-yeni]', kap);
      if (v?.yeni_sube_url) { b.href = v.yeni_sube_url; b.hidden = false; }
    },
  });
  tablo.yenile();
}
