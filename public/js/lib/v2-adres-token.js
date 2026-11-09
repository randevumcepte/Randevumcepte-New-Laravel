/**
 * Adres cubugundaki oturum token'i — saf fonksiyon (yan etkisiz; v2-ortak.js tohumlamada kullanir).
 *
 * Yalniz #token= (fragment) kabul edilir: fragment sunucuya gitmez, erisim loglarina ve Referer'a dusmez.
 * ?token= (sorgu) KABUL EDILMEZ — sunucu/proxy loglarinda, tarayici gecmisinde ve Referer'da kalir; yine de
 * adresten silinir. Eski panel koprusu (/isletmeyonetim/v2-gecis) zaten #token= kullanir.
 *
 * @param {string} search  location.search
 * @param {string} hash    location.hash
 * @returns {{token: ?string, temizle: boolean, adres: string}} adres: token'siz search + hash
 */
export function adrestenToken(search, hash) {
  const sorgu = new URLSearchParams(search || '');
  const parca = new URLSearchParams((hash || '').replace(/^#/, ''));
  const token = parca.get('token') || null;
  const temizle = sorgu.has('token') || parca.has('token');
  sorgu.delete('token');
  parca.delete('token');
  const s = sorgu.toString();
  const h = parca.toString();

  return { token, temizle, adres: (s ? '?' + s : '') + (h ? '#' + h : '') };
}
