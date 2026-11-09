/**
 * Reklam Yönetimi (v2) — ekranın üç modülünün (liste+sihirbaz, rapor, senaryo) paylaştığı bağlam.
 * Genel yardımcılar lib/ altında (kopyalanmaz); burada yalnız bu ekrana özgü olanlar.
 * Import yolu her yerde AYNI:  import { baglam } from '/js/reklam/ortak.js?v=1';
 */
import { api, esc } from '/js/lib/v2-ortak.js?v=7';

/** Sayfa bağlamı — salon + form verisi (API'den, sabit liste yok) + istek kısayolları. */
export const baglam = {
  salonId: 0,
  fv: { kanallar: [], hedefler: [], cinsiyetler: [], gruplar: [], hizmetler: [], urunler: [], paketler: [], sablonlar: [],
    yer_tutucular: [], indirim: { turler: [], varsayilan: {} }, planlama: {}, katilim_durumlari: [], personeller: [], senaryo: {}, yetkiler: {} },
  oku(yol, params = {}) { return api('GET', yol, { params: { ...params, salon_id: this.salonId } }); },
  yaz(method, yol, body = {}) { return api(method, yol, { body: { ...body, salon_id: this.salonId } }); },
  yetki(k) { return !!this.fv.yetkiler?.[k]; },
  kanal(kod) { return this.fv.kanallar.find((k) => Number(k.kod) === Number(kod)) || null; },
};

/** Kanal rozeti (liste + rapor başlığı). */
export const kanalRozeti = (k) => (k ? `<span class="rk-kanal rk-kanal-${esc(k.kod)}"><i class="bi ${esc(k.ikon)}"></i> ${esc(k.kisa || k.ad)}</span>` : '');

/** Sunucunun verdiği {kod, ad|etiket, renk} rozeti. */
export const durumRozeti = (d) => (d ? `<span class="rc-rozet rc-rozet-${esc(d.renk || 'gri')}">${esc(d.ad || d.etiket || '')}</span>` : '');
