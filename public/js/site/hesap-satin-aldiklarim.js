// Satin Aldiklarim — mobil MusteriPaneliAdiayonlari (musteripaneli/menu/siparislerim.dart) karsiligi.
// Veri: GET /musteri-uygulama/satislar (mobil ile ayni sorgu: tum gecmis, kapali adisyonlar, tur filtresi).
import { api, esc, salonId, musteriHazir } from './musteri-api.js';
import { $, calistir, sayfalama, pencereAc, ikiHane, AYLAR, AYLAR_KISA, GUNLER, GUNLER_KISA } from './hesap-ortak.js';

// Mobil filtre sekmeleri; studyo modunda urun satisi olmadigindan "Ürün" gizlenir.
const TURLER = [['', 'Tümü'], ['1', 'Hizmet'], ['2', 'Paket'], ['3', 'Ürün']]
    .filter(([id]) => !(id === '3' && document.body.dataset.studyo === '1'));
const TUR_BILGI = {
    1: ['fa-leaf', 'Hizmet'],
    2: ['fa-gift', 'Paket'],
    3: ['fa-shopping-bag', 'Ürün'],
};

let tur = '';
let satislar = [];

// satis_turu bos gelebilir; o zaman icerikteki (H)/(P)/(Ü) isaretlerinden tur cikarilir (mobil _adisyonTur).
function adisyonTuru(a) {
    if (String(a.satis_turu ?? '').trim()) return String(a.satis_turu);
    const ic = String(a.icerik ?? '');
    const p = ic.includes('(P)'), h = ic.includes('(H)'), u = ic.includes('(Ü)') || ic.includes('(U)');
    if (p && !h && !u) return '2';
    if (h && !p && !u) return '1';
    if (u && !p && !h) return '3';
    return '';
}

function turBilgi(t) {
    const anahtar = { Hizmet: 1, Paket: 2, 'Ürün': 3 }[t] ?? t;
    return TUR_BILGI[anahtar] || ['fa-file-text-o', 'Satış'];
}

// Mobil _temizSatir: "{ad} (Ü|H|P)  Personel  400,00 ₺" -> yalniz ad (personel ve tutar gosterilmez).
function temizSatir(s) {
    return s.trim()
        .replace(/\s*\([HÜPKhüpk]\).*$/, '')
        .replace(/\([^)]*(₺|TL|tl|tutar|fiyat|fıyat)[^)]*\)/gi, '')
        .replace(/[\d.,]+\s*₺/g, '')
        .replace(/₺\s*[\d.,]+/g, '')
        .replace(/[\d.,]+\s*TL/gi, '')
        .replace(/\s{2,}/g, ' ')
        .trim();
}
const satirlar = (icerik) => String(icerik ?? '').split(/[\n\r]+/).map(temizSatir).filter(Boolean);

// acilis_tarihi "dd.MM.yyyy" (ya da ISO), acilis_saati "HH:mm" gelir.
function tarihCoz(tarih, saat) {
    const t = String(tarih ?? '').trim();
    if (!t) return null;
    const sm = String(saat ?? '').match(/(\d{1,2}):(\d{2})/);
    const [sa, dk] = sm ? [Number(sm[1]), Number(sm[2])] : [0, 0];
    const dm = t.match(/^(\d{1,2})[./-](\d{1,2})[./-](\d{4})/);
    if (dm) return new Date(Number(dm[3]), Number(dm[2]) - 1, Number(dm[1]), sa, dk);
    const iso = t.match(/^(\d{4})-(\d{2})-(\d{2})/);
    return iso ? new Date(Number(iso[1]), Number(iso[2]) - 1, Number(iso[3]), sa, dk) : null;
}

// Listede birden fazla sube varsa kartta sube adi gosterilir (mobil _cokluSalon).
const cokluSalon = () => new Set(satislar.map((a) => String(a.salon_adi ?? '').trim()).filter(Boolean)).size > 1;

function satisKarti(a, i, coklu) {
    const dt = tarihCoz(a.acilis_tarihi, a.acilis_saati);
    const [ikon, etiket] = turBilgi(adisyonTuru(a));
    const kalemler = satirlar(a.icerik);
    const salon = coklu ? String(a.salon_adi ?? '').trim() : '';
    return `<button type="button" class="hs-satis" data-i="${i}">
        <span class="hs-satis-tarih">
            <small>${dt ? GUNLER_KISA[dt.getDay()] : '--'}</small>
            <strong>${dt ? dt.getDate() : '--'}</strong>
            <small>${dt ? AYLAR_KISA[dt.getMonth()] : ''}</small>
        </span>
        <span class="hs-satis-govde">
            <span class="hs-satis-tur"><i class="fa ${ikon}"></i> ${etiket}${dt ? `<em>${ikiHane(dt.getHours())}:${ikiHane(dt.getMinutes())}</em>` : ''}</span>
            <strong class="hs-satis-ad">${esc(kalemler[0] || 'Bilgi yok')}</strong>
            ${kalemler.length > 1 ? `<small>+${kalemler.length - 1} kalem daha</small>` : ''}
            ${salon ? `<span class="hs-satis-salon"><i class="fa fa-home"></i> ${esc(salon)}</span>` : ''}
        </span>
        <i class="fa fa-chevron-right hs-ok"></i>
    </button>`;
}

function detay(a) {
    const dt = tarihCoz(a.acilis_tarihi, a.acilis_saati);
    const [ikon, etiket] = turBilgi(adisyonTuru(a));
    const kalemler = satirlar(a.icerik);
    const salon = cokluSalon() ? String(a.salon_adi ?? '').trim() : '';
    const tarih = dt
        ? `${GUNLER[dt.getDay()]}, ${dt.getDate()} ${AYLAR[dt.getMonth()]} ${dt.getFullYear()} • ${ikiHane(dt.getHours())}:${ikiHane(dt.getMinutes())}`
        : String(a.acilis_tarihi ?? '');
    return `<div class="hs-detay-ust">
            <span class="hs-ikon"><i class="fa ${ikon}"></i></span>
            <div><small class="hs-ust-etiket">${etiket} #${esc(a.id)}</small><div>${esc(tarih)}</div>
            ${salon ? `<span class="hs-satis-salon"><i class="fa fa-home"></i> ${esc(salon)}</span>` : ''}</div>
        </div>
        <div class="hs-icerik-baslik">İÇERİK <span class="hs-sayac">${kalemler.length}</span></div>
        ${kalemler.length
            ? `<ol class="hs-icerik">${kalemler.map((k) => `<li>${esc(k)}</li>`).join('')}</ol>`
            : '<p class="hs-soluk">Bilgi yok</p>'}
        <button type="button" class="btn-submit hs-kapat" data-kapat><span>Kapat</span></button>`;
}

musteriHazir(() => {
    const bugun = new Date();
    const tarih2 = `${bugun.getFullYear()}-${ikiHane(bugun.getMonth() + 1)}-${ikiHane(bugun.getDate())}`;
    const sayfaCiz = sayfalama($('satisSayfa'), (no) => yukle(no));

    const cipCiz = () => {
        $('satisTurler').innerHTML = TURLER.map(([id, ad]) => `<button type="button" class="hs-cip ${id === tur ? 'aktif' : ''}" data-tur="${id}">${ad}</button>`).join('');
    };
    const bosCiz = () => {
        const ad = TURLER.find(([id]) => id === tur)?.[1];
        $('satisBosBaslik').textContent = tur ? `${ad} satışı yok` : 'Henüz kaydın yok';
        $('satisBosMetin').textContent = tur ? 'Bu kategoride henüz alışveriş yok.' : 'Satın aldıkların burada listelenecek.';
    };

    const yukle = (no) => calistir(async () => {
        $('satisAlt').textContent = 'Yükleniyor...';
        const veri = await api('/musteri-uygulama/satislar', { sorgu: {
            salon_id: salonId(), tarih1: '1970-09-01', tarih2, tur: tur || null, acik_kapali: 0, page: no,
        } });
        satislar = veri.data || [];
        const coklu = cokluSalon();
        $('satisListe').innerHTML = satislar.map((a, i) => satisKarti(a, i, coklu)).join('');
        $('satisAlt').textContent = satislar.length ? `${veri.total} kayıt` : 'Kayıt bulunamadı';
        bosCiz();
        $('satisBos').hidden = satislar.length > 0;
        sayfaCiz(veri.current_page, veri.last_page);
        return true;
    }).then((ok) => { if (!ok) $('satisAlt').textContent = ''; }); // hata calistir'da bildirildi

    $('satisTurler').addEventListener('click', (e) => {
        const b = e.target.closest('[data-tur]');
        if (!b || b.dataset.tur === tur) return;
        tur = b.dataset.tur;
        cipCiz();
        yukle(1);
    });
    $('satisListe').addEventListener('click', (e) => {
        const kart = e.target.closest('[data-i]');
        if (kart) pencereAc($('satisPencere'), detay(satislar[Number(kart.dataset.i)]));
    });
    cipCiz();
    yukle(1);
});
