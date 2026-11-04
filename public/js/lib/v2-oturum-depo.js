/** Panel oturumunun tarayıcıdaki durumu — TEK KAYNAK (giriş sayfası + panel kabuğu). */
const TOKEN = 'rc_access_token';
const KAYNAK = 'rc_oturum_kaynagi';
const ILK_SAYFA = 'rc_ilk_sayfa';

export function oturumuKaydet({ token, ilk_sayfa: ilkSayfa }) {
  localStorage.setItem(TOKEN, token);
  localStorage.setItem(KAYNAK, 'v2');
  localStorage.setItem(ILK_SAYFA, ilkSayfa || '/v2/profil');
}

export function oturumuTemizle() {
  [TOKEN, KAYNAK, ILK_SAYFA].forEach((a) => localStorage.removeItem(a));
}

export const v2denGirildi = () => localStorage.getItem(KAYNAK) === 'v2';

/** Yalnız kendi ekranlarımıza dönülür; dışarıdan verilen adres açık yönlendirme olmasın. */
export function hedefSayfa(donus) {
  return donus && /^\/v2\/(?!\/)/.test(donus) ? donus : (localStorage.getItem(ILK_SAYFA) || '/v2/profil');
}

export function girisSayfasinaGit() {
  location.href = '/v2/giris?donus=' + encodeURIComponent(location.pathname + location.search);
}
