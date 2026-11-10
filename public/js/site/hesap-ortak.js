// Musteri paneli sayfa modullerinin (hesap.js, hesap-*.js) ORTAK yardimcilari — tek kaynak:
// bildirim, yukleme sarmali, sayfalama, <dialog> pencere, onay / kisa metin penceresi, ortu pencere, bilgi notu ve
// Turkce tarih adlari. Stil: public/site/css/hesap-ortak.css. Oturum ve musteri bilgisi hesap.js + musteri-api.js'te.
import { esc, yukleniyor } from './musteri-api.js';

export const $ = (id) => document.getElementById(id);
export const ikiHane = (n) => String(n).padStart(2, '0');

export const AYLAR = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
export const AYLAR_KISA = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
// Date.getDay() sirasi (0 = Pazar).
export const GUNLER = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
export const GUNLER_KISA = ['Paz', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt'];

/** Layout'taki #hesapBildirim alanina yazar; basarili ise yesil. Bos metin gizler. */
export function bildir(mesaj, basarili = false) {
    const b = $('hesapBildirim');
    if (!b) return;
    b.textContent = mesaj;
    b.style.display = mesaj ? 'block' : 'none';
    b.style.color = basarili ? '#0a7c3a' : '';
    if (mesaj) b.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

/** Yukleniyor gostergesiyle calistirir; hata #hesapBildirim'e yazilir ve undefined doner (yeniden atilmaz). */
export async function calistir(is) {
    bildir('');
    yukleniyor(true);
    try { return await is(); } catch (e) { bildir(e.message); return undefined; } finally { yukleniyor(false); }
}

/** Mobil sayfalama: onceki / "x / y" / sonraki; tek sayfada gizli. Donen fonksiyon (mevcut, son) ile cizer. */
export function sayfalama(kap, git) {
    let durum = { mevcut: 1, son: 1 };
    kap.addEventListener('click', (e) => {
        const b = e.target.closest('[data-sayfa]');
        if (b && !b.disabled) git(Number(b.dataset.sayfa));
    });
    return (mevcut, son) => {
        durum = { mevcut, son };
        kap.hidden = durum.son <= 1;
        kap.innerHTML = `<button type="button" class="hs-sayfa-btn" data-sayfa="${mevcut - 1}" ${mevcut > 1 ? '' : 'disabled'} aria-label="Önceki sayfa"><i class="fa fa-chevron-left"></i></button>
            <span class="hs-sayfa-no">${mevcut} / ${son}</span>
            <button type="button" class="hs-sayfa-btn" data-sayfa="${mevcut + 1}" ${mevcut < son ? '' : 'disabled'} aria-label="Sonraki sayfa"><i class="fa fa-chevron-right"></i></button>`;
    };
}

/** Detay penceresi (<dialog>): icerigi yazar ve acar; [data-kapat] ya da arka plan tiklamasi kapatir. */
export function pencereAc(dialog, html) {
    if (!dialog.dataset.hazir) {
        dialog.dataset.hazir = '1';
        dialog.addEventListener('click', (e) => {
            if (e.target === dialog || e.target.closest('[data-kapat]')) dialog.close();
        });
    }
    dialog.innerHTML = `<div class="hs-pencere-ic"><button type="button" class="hs-pencere-x" data-kapat aria-label="Kapat"><i class="fa fa-times"></i></button>${html}</div>`;
    dialog.showModal();
}

// ───────── Onay ve kisa metin penceresi (<dialog>) ─────────
function pencereEl() {
    let d = document.getElementById('hbOnay');
    if (!d) {
        d = document.createElement('dialog');
        d.id = 'hbOnay';
        d.className = 'hs-pencere hb-onay';
        document.body.appendChild(d);
    }
    return d;
}

/**
 * Onay penceresi. Onaylanirsa formdaki alanlar ({ad: deger}) doner, vazgecilirse null.
 *   ek: pencereye eklenecek alan HTML'i (textarea, onay kutusu); dogrula(form): hata metni ya da bos.
 */
export function onayIste({ baslik, metin = '', ek = '', dugme = 'Onayla', vazgec = 'Vazgeç', tehlikeli = false, dogrula = null }) {
    const d = pencereEl();
    pencereAc(d, `<form class="hb-onay-form" novalidate>
        <h3 class="hb-onay-baslik">${esc(baslik)}</h3>
        ${metin ? `<p class="hb-onay-metin">${esc(metin)}</p>` : ''}
        ${ek}
        <div class="hb-onay-hata" role="alert" hidden></div>
        <div class="hb-onay-dugmeler">
            <button type="button" class="hb-dugme hb-dugme--ikincil" data-kapat>${esc(vazgec)}</button>
            <button type="submit" class="hb-dugme ${tehlikeli ? 'hb-dugme--tehlike' : ''}">${esc(dugme)}</button>
        </div>
    </form>`);
    const form = d.querySelector('form');
    form.querySelector('textarea, input:not([type=checkbox])')?.focus();
    return new Promise((coz) => {
        let sonuc = null;
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const hata = dogrula ? dogrula(form) : '';
            const kutu = form.querySelector('.hb-onay-hata');
            kutu.textContent = hata || '';
            kutu.hidden = !hata;
            if (hata) return;
            sonuc = Object.fromEntries(new FormData(form));
            d.close();
        });
        d.addEventListener('close', () => coz(sonuc), { once: true });
    });
}

/** Kisa not penceresi (fotograf notu): kaydedilirse kirpilmamis metin, vazgecilirse null. */
export async function metinIste({ baslik, metin, mevcut = '', dugme, ipucu = 'Not...' }) {
    const s = await onayIste({
        baslik, metin, dugme, vazgec: 'İptal',
        ek: `<textarea name="metin" class="modern-form-control hb-onay-alan" rows="3" maxlength="1000" placeholder="${esc(ipucu)}">${esc(mevcut)}</textarea>`,
    });
    return s === null ? null : String(s.metin ?? '');
}

// ───────── Ortu pencere (statik .hh-perde) ve bilgi notu (mobildeki SnackBar) ─────────
let _zamanlayici = null;

/** Mobildeki SnackBar karsiligi: ekranin altinda birkac saniye gorunen not. */
export function toast(mesaj, basarili = false) {
    let t = document.getElementById('hsToast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'hsToast';
        t.className = 'hh-toast';
        t.setAttribute('role', 'status');
        document.body.appendChild(t);
    }
    t.textContent = mesaj;
    t.classList.toggle('hh-toast--basarili', basarili);
    t.classList.add('acik');
    clearTimeout(_zamanlayici);
    _zamanlayici = setTimeout(() => t.classList.remove('acik'), 3500);
}

/** Pencere (.hh-perde): arka plana ya da [data-kapat] dugmesine tiklayinca kapanir. */
export function pencere(el) {
    if (!el.dataset.hazir) {
        el.dataset.hazir = '1';
        el.addEventListener('click', (e) => {
            if (e.target === el || e.target.closest('[data-kapat]')) el.hidden = true;
        });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') el.hidden = true; });
    }
    return { ac: () => { el.hidden = false; }, kapat: () => { el.hidden = true; } };
}
