// Musteri tarafi ortak yardimcilar — TEK KAYNAK (site + panel). Token yalniz bu dosyada okunur.
const TOKEN_ANAHTAR = 'rc_musteri_token';
const TABAN = '/api/v2';

export const salonId = () => Number(document.body.dataset.salonId);

function depo(islem) {
    try { return islem(window.localStorage); } catch (e) { return null; }
}

export const tokenAl = () => depo((d) => d.getItem(TOKEN_ANAHTAR));
export const tokenYaz = (t) => depo((d) => d.setItem(TOKEN_ANAHTAR, t));
export const tokenSil = () => depo((d) => d.removeItem(TOKEN_ANAHTAR));
export const girisliMi = () => Boolean(tokenAl());

export class ApiHatasi extends Error {
    constructor(mesaj, kod, veri) { super(mesaj); this.kod = kod; this.veri = veri; }
}

export async function api(yol, { yontem = 'GET', govde = null, sorgu = null } = {}) {
    const url = new URL(TABAN + yol, location.origin);
    Object.entries(sorgu || {}).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') url.searchParams.append(k, v); });
    const basliklar = { Accept: 'application/json' };
    const token = tokenAl();
    if (token) basliklar.Authorization = 'Bearer ' + token;
    let gonderilen = null;
    if (govde instanceof FormData) gonderilen = govde;
    else if (govde) { basliklar['Content-Type'] = 'application/json'; gonderilen = JSON.stringify(govde); }

    let yanit;
    try {
        yanit = await fetch(url, { method: yontem, headers: basliklar, body: gonderilen });
    } catch (e) {
        throw new ApiHatasi('Bağlantı hatası. Lütfen tekrar deneyiniz.', 0);
    }
    const json = await yanit.json().catch(() => ({}));
    if (yanit.status === 401 && token && yol.startsWith('/musteri-uygulama')) tokenSil();
    if (!yanit.ok || json.basarili === false) throw new ApiHatasi(hataMesaji(json), yanit.status, json.veri);
    return json.veri;
}

function hataMesaji(json) {
    const alanlar = json.veri && typeof json.veri === 'object' ? Object.values(json.veri).flat() : [];
    const ilk = alanlar.find((m) => typeof m === 'string');
    return ilk || json.mesaj || 'Bir hata oluştu. Lütfen tekrar deneyiniz.';
}

export const yukleniyor = (acik) => {
    const p = document.getElementById('preloader');
    if (p) p.style.display = acik ? 'block' : 'none';
};

export const kaydir = (el) => el && el.scrollIntoView({ behavior: 'smooth', block: 'start' });

export function esc(deger) {
    const d = document.createElement('div');
    d.textContent = deger === null || deger === undefined ? '' : String(deger);
    return d.innerHTML;
}

/** Header'daki giris durumu: isim + resim + cikis (site ve panel ayni isaretleri kullanir). */
export async function oturumCiz() {
    const oturumlar = document.querySelectorAll('[data-musteri-oturum]');
    const misafirler = document.querySelectorAll('[data-musteri-misafir]');
    const goster = (girisli) => {
        oturumlar.forEach((e) => { e.hidden = !girisli; });
        misafirler.forEach((e) => { e.hidden = girisli; });
    };
    goster(false);
    document.querySelectorAll('[data-musteri-cikis]').forEach((b) => b.addEventListener('click', async (ev) => {
        ev.preventDefault();
        await api('/musteri-uygulama/ben/cikis', { yontem: 'POST' }).catch(() => null);
        tokenSil();
        location.href = document.body.dataset.ana;
    }));
    if (!girisliMi()) return null;
    const ben = await api('/musteri-uygulama/ben').catch(() => null);
    if (!ben) { tokenSil(); return null; }
    goster(true);
    document.querySelectorAll('[data-musteri-ad]').forEach((e) => { e.textContent = (ben.name || '').toLocaleUpperCase('tr'); });
    if (ben.profil_resim) document.querySelectorAll('[data-musteri-resim]').forEach((e) => { e.src = ben.profil_resim; });
    return ben;
}

// ───────── Musteri paneli sayfa modulleri (hesap-*.js) ─────────
// hesap.js oturumu bir kez cozer ve musteriyi yayinlar; sayfa modulu musteriHazir ile alir (yukleme sirasi onemsiz).
let _musteri = null;
export function musteriYayinla(ben) {
    _musteri = ben;
    document.dispatchEvent(new CustomEvent('rc:musteri', { detail: ben }));
}
export function musteriHazir(fn) {
    if (_musteri) fn(_musteri);
    else document.addEventListener('rc:musteri', (e) => fn(e.detail), { once: true });
}
