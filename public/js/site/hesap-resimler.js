// Musteri paneli > Resimlerim: mobil musteripaneli/menu/musteriresimleri.dart karsiligi.
// Gun gun gruplu islem fotograflari, foto ekleme (istege bagli notla), buyuk gorunum, not duzenleme ve silme.
import { api, esc, salonId, musteriHazir } from './musteri-api.js';
import { $, pencereAc, AYLAR, GUNLER, bildir, calistir, onayIste, metinIste } from './hesap-ortak.js';

const SALON = salonId();
const KOK = $('resimSayfa').dataset.kok.replace(/\/+$/, '');
const url = (yol) => (/^https?:\/\//i.test(yol) ? yol : `${KOK}/${String(yol).replace(/^\/+/, '')}`);

let gruplar = {}; // gun anahtari (YYYY-AA-GG ya da '-') => yollar
let sirali = [];
let notlar = {}; // yol => not
let gorunum = null; // {yollar, i}

function gunBaslik(k) {
    if (k === '-') return 'Tarihsiz';
    const d = new Date(k + 'T00:00:00');
    if (Number.isNaN(d.getTime())) return k;
    const bugun = new Date();
    const dun = new Date(bugun.getFullYear(), bugun.getMonth(), bugun.getDate() - 1);
    const ayni = (a, b) => a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    if (ayni(d, bugun)) return 'Bugün';
    if (ayni(d, dun)) return 'Dün';
    return `${d.getDate()} ${AYLAR[d.getMonth()]} ${d.getFullYear()} · ${GUNLER[d.getDay()]}`;
}

function ayristir(satirlar) {
    gruplar = {};
    notlar = {};
    satirlar.forEach((s) => {
        let yollar;
        try { yollar = Array.isArray(s.islem_fotolari) ? s.islem_fotolari : JSON.parse(s.islem_fotolari || '[]'); } catch (e) { return; }
        if (!Array.isArray(yollar) || !yollar.length) return;
        const not = (s.resim_notu || '').trim();
        if (not) yollar.forEach((y) => { notlar[y] = s.resim_notu; });
        const k = /^\d{4}-\d{2}-\d{2}/.test(s.tarih || '') ? s.tarih.slice(0, 10) : (s.tarih || '-');
        (gruplar[k] ||= []).push(...yollar);
    });
    sirali = Object.keys(gruplar).sort((a, b) => b.localeCompare(a));
}

function ciz() {
    const toplam = sirali.reduce((t, k) => t + gruplar[k].length, 0);
    $('resimBos').hidden = toplam > 0;
    $('resimOzet').hidden = toplam === 0;
    $('resimToplam').textContent = `${toplam} Fotoğraf`;
    $('resimGunSayisi').textContent = `${sirali.length} farklı tarihte`;
    $('resimGruplar').innerHTML = sirali.map((k) => `<section class="hb-gun">
        <div class="hb-gun-baslik"><span class="hb-nokta"></span><strong>${esc(gunBaslik(k))}</strong><span class="hb-sayac">${gruplar[k].length}</span></div>
        <div class="hb-izgara">${gruplar[k].map((y, i) => `<div class="hb-kare">
            <button type="button" class="hb-kare-ac" data-gun="${esc(k)}" data-i="${i}" aria-label="Fotoğrafı aç"><img src="${esc(url(y))}" alt="" loading="lazy"></button>
            ${notlar[y] ? '<span class="hb-kare-not" title="Notlu"><i class="fa fa-sticky-note"></i></span>' : ''}
            <button type="button" class="hb-kare-sil" data-sil="${esc(y)}" aria-label="Fotoğrafı sil"><i class="fa fa-trash"></i></button>
        </div>`).join('')}</div>
    </section>`).join('');
}

const yukle = () => calistir(async () => {
    ayristir(await api('/musteri-uygulama/resimler', { sorgu: { salon_id: SALON } }));
    ciz();
});

// ───────── Buyuk gorunum ─────────
function gorunumCiz() {
    const { yollar, i } = gorunum;
    const y = yollar[i];
    const not = notlar[y] || '';
    if ($('resimPencere').open) $('resimPencere').close(); // pencereAc showModal'i acik pencerede cagirmasin
    pencereAc($('resimPencere'), `<div class="hb-gor-ust"><span class="hs-sayac">${i + 1} / ${yollar.length}</span></div>
        <div class="hb-gor-resim">
            ${i > 0 ? '<button type="button" class="hb-gor-ok hb-gor-ok--sol" data-git="-1" aria-label="Önceki"><i class="fa fa-chevron-left"></i></button>' : ''}
            <img src="${esc(url(y))}" alt="">
            ${i < yollar.length - 1 ? '<button type="button" class="hb-gor-ok hb-gor-ok--sag" data-git="1" aria-label="Sonraki"><i class="fa fa-chevron-right"></i></button>' : ''}
        </div>
        ${not ? `<button type="button" class="hb-gor-not" data-not><i class="fa fa-sticky-note"></i> <span>${esc(not)}</span></button>` : ''}
        <div class="hb-onay-dugmeler">
            <button type="button" class="hb-dugme hb-dugme--ikincil" data-not><i class="fa fa-pencil"></i> Not</button>
            <button type="button" class="hb-dugme hb-dugme--tehlike" data-gor-sil><i class="fa fa-trash"></i> Sil</button>
        </div>`);
}

async function sil(yol) {
    const onay = await onayIste({
        baslik: 'Fotoğrafı sil?', metin: 'Bu fotoğraf hem senin panelinden hem de salonun panelinden kaldırılır. Bu işlem geri alınamaz.',
        dugme: 'Sil', vazgec: 'İptal', tehlikeli: true,
    });
    if (!onay) return false;
    const tamam = await calistir(async () => {
        await api('/musteri-uygulama/resimler', { yontem: 'DELETE', govde: { path: yol } });
        return true;
    });
    if (!tamam) return false;
    await yukle();
    bildir('Fotoğraf silindi', true);
    return true;
}

async function notDuzenle(yol) {
    const not = await metinIste({ baslik: 'Notu düzenle', metin: 'Bu fotoğrafa ait notu güncelle veya temizle.', mevcut: notlar[yol] || '', dugme: 'Kaydet' });
    if (not === null) return;
    const temiz = not.trim();
    const tamam = await calistir(async () => {
        await api('/musteri-uygulama/resimler/not', { yontem: 'PATCH', govde: { path: yol, not: temiz, salon_id: SALON } });
        return true;
    });
    if (!tamam) return;
    await yukle();
    bildir(temiz ? 'Not kaydedildi' : 'Not silindi', true);
}

$('resimGruplar').addEventListener('click', (e) => {
    const silB = e.target.closest('[data-sil]');
    if (silB) { sil(silB.dataset.sil); return; }
    const ac = e.target.closest('[data-gun]');
    if (ac) { gorunum = { yollar: gruplar[ac.dataset.gun], i: Number(ac.dataset.i) }; gorunumCiz(); }
});

$('resimPencere').addEventListener('click', async (e) => {
    if (!gorunum) return;
    const git = e.target.closest('[data-git]');
    if (git) { gorunum.i += Number(git.dataset.git); gorunumCiz(); return; }
    const yol = gorunum.yollar[gorunum.i];
    if (e.target.closest('[data-not]')) {
        await notDuzenle(yol);
        if ($('resimPencere').open) gorunumCiz();
    } else if (e.target.closest('[data-gor-sil]')) {
        if (await sil(yol)) $('resimPencere').close();
    }
});
$('resimPencere').addEventListener('keydown', (e) => {
    if (!gorunum) return;
    const yon = { ArrowLeft: -1, ArrowRight: 1 }[e.key];
    const yeni = gorunum.i + (yon || 0);
    if (yon && yeni >= 0 && yeni < gorunum.yollar.length) { gorunum.i = yeni; gorunumCiz(); }
});

// ───────── Foto ekle ─────────
$('resimDosya').addEventListener('change', async (e) => {
    const dosya = e.target.files[0];
    e.target.value = '';
    if (!dosya) return;
    const not = await metinIste({ baslik: 'Not ekle', metin: 'İstersen bu fotoğraf için kısa bir not yaz (opsiyonel).', dugme: 'Ekle' });
    if (not === null) return; // vazgecildi
    const veri = new FormData();
    veri.append('musteriresim', dosya);
    veri.append('salon_id', String(SALON));
    if (not.trim()) veri.append('not', not.trim());
    const tamam = await calistir(async () => {
        await api('/musteri-uygulama/resimler', { yontem: 'POST', govde: veri });
        return true;
    });
    if (!tamam) return;
    await yukle();
    bildir('Fotoğraf eklendi', true);
});

musteriHazir(yukle);
