// Seanslarim — mobil SeanslarDashboard (musteripaneli/anasayfa/raporlar/seanslar.dart) karsiligi.
// Veri: GET /musteri-uygulama/seanslar (SeansTakip alanlari); sayilar sunucudan geldigi gibi gosterilir.
import { api, esc, salonId, musteriHazir } from './musteri-api.js';
import { $, calistir, sayfalama, pencereAc } from './hesap-ortak.js';

let kartlar = [];

const sinirsizMi = (k) => String(k.sinirsiz) === '1';
const paketAdi = (k) => (String(k.paket ?? '').trim() || 'Paket');
const tamamlandiMi = (k) => !sinirsizMi(k) && k.toplamSeansSayisi > 0 && k.gelinenSeansSayisi >= k.toplamSeansSayisi;

// Mobil seansTarihi: Y-m-d -> d.m.Y; kisa/bos ise "—".
function seansTarihi(t) {
    const s = String(t ?? '');
    return s.length < 10 ? '—' : `${s.slice(8, 10)}.${s.slice(5, 7)}.${s.slice(0, 4)}`;
}

// Mobil tarihsaatdonustur: "dd.MM.yyyy H:mm"; ikisi de bossa "Belirtilmemiş".
function tarihSaat(tarih, saat) {
    const t = String(tarih ?? ''), s = String(saat ?? '');
    if (!t && !s) return 'Belirtilmemiş';
    const gun = seansTarihi(t);
    const m = s.match(/^(\d{1,2}):(\d{2})/);
    return m ? `${gun} ${Number(m[1])}:${m[2]}` : gun;
}

// Mobil SeansTakip.kalanSureMetni: bitis gunu dahil gecerli; bitis yoksa sure islemez.
function kalanSureMetni(k) {
    const m = String(k.bitis ?? '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return 'İlk gelişte başlar';
    const bugun = new Date();
    const fark = Math.round((new Date(+m[1], +m[2] - 1, +m[3]) - new Date(bugun.getFullYear(), bugun.getMonth(), bugun.getDate())) / 86400000);
    return fark < 0 ? 'Süresi doldu' : `${fark} gün kaldı`;
}

const sinirsizEtiketi = (k) => (k.sure ? `Sınırsız · ${k.sure}` : 'Sınırsız');
const sinirsizBilgi = (k) => `${sinirsizEtiketi(k)}  •  ${k.baslangic == null ? kalanSureMetni(k) : `${seansTarihi(k.baslangic)} – ${seansTarihi(k.bitis)}`}`;

function ilerleme(k, kalin = false) {
    const oran = k.toplamSeansSayisi ? Math.min(1, Math.max(0, k.gelinenSeansSayisi / k.toplamSeansSayisi)) : 0;
    return `<span class="hs-ilerleme ${kalin ? 'hs-ilerleme--kalin' : ''} ${tamamlandiMi(k) ? 'hs-ilerleme--tamam' : ''}"><span style="width:${(oran * 100).toFixed(1)}%"></span></span>`;
}

// Mobil _miniStat: ikon + sayi (etiket gorunmez; title olarak verilir).
function miniSayilar(k) {
    const kutu = (tur, ikon, etiket, deger) => `<span class="hs-mini hs-renk--${tur}" title="${etiket}"><i class="fa ${ikon}"></i> ${esc(deger)}</span>`;
    return `<span class="hs-mini-satir">
        ${kutu('bekleyen', 'fa-calendar-check-o', 'Bekleyen', k.bekleyenSeansSayisi)}
        ${kutu('gelinen', 'fa-check', 'Gelinen', k.gelinenSeansSayisi)}
        ${kutu('gelmeyen', 'fa-times', 'Gelmeyen', k.gelinmeyenSeansSayisi)}
    </span>`;
}

function paketKarti(k, i) {
    const hizmetSayisi = Array.isArray(k.hizmetler) ? k.hizmetler.length : 0;
    const sag = tamamlandiMi(k)
        ? '<span class="hs-rozet hs-renk--gelinen"><i class="fa fa-check-circle"></i> Tamamlandı</span>'
        : '<i class="fa fa-chevron-right hs-ok"></i>';
    const orta = sinirsizMi(k)
        ? `<span class="hs-sinirsiz">${esc(sinirsizBilgi(k))}</span>`
        : `<span class="hs-ilerleme-satir">${ilerleme(k)}<strong>${esc(k.gelinenSeansSayisi)} / ${esc(k.toplamSeansSayisi)}</strong></span>`;
    return `<button type="button" class="hs-kart" data-i="${i}">
        <span class="hs-kart-ust">
            <span class="hs-ikon"><i class="fa fa-leaf"></i></span>
            <span class="hs-kart-baslik"><strong>${esc(paketAdi(k))}</strong>${hizmetSayisi > 0 ? `<small>${hizmetSayisi} hizmet</small>` : ''}</span>
            ${sag}
        </span>
        ${orta}
        ${miniSayilar(k)}
    </button>`;
}

// Mobil durum: geldi null = beklemede, 0 = gelmedi, 2 = telafi, digeri geldi.
function seansDurumu(geldi) {
    if (geldi === null || geldi === undefined) return ['bekleyen', 'fa-clock-o', 'Beklemede'];
    if (String(geldi) === '0') return ['gelmeyen', 'fa-times', 'Gelmedi'];
    if (String(geldi) === '2') return ['telafi', 'fa-exclamation', 'Telafi'];
    return ['gelinen', 'fa-check', 'Geldi'];
}

function seansSatiri(s) {
    const [tur, ikon, metin] = seansDurumu(s.geldi);
    const pil = (ik, deger) => (deger ? `<span class="hs-pil"><i class="fa ${ik}"></i> ${esc(deger)}</span>` : '');
    const piller = pil('fa-user-o', s.personel?.personel_adi) + pil('fa-building-o', s.oda?.oda_adi) + pil('fa-cogs', s.cihaz?.cihaz_adi);
    return `<div class="hs-seans">
        <div class="hs-seans-ust">
            <span class="hs-seans-no">${esc(s.seans_no ?? '')}</span>
            <strong>${esc(tarihSaat(s.seans_tarih, s.seans_saat))}</strong>
            <span class="hs-rozet hs-renk--${tur}"><i class="fa ${ikon}"></i> ${metin}</span>
        </div>
        ${piller ? `<div class="hs-pil-satir">${piller}</div>` : ''}
    </div>`;
}

function detay(k) {
    // Seanslar hizmet adina gore gruplanir (ilk gorulme sirasi korunur).
    const gruplar = new Map();
    (k.seanslar || []).forEach((s) => {
        if (!s || typeof s !== 'object') return;
        const ad = String(s.hizmet?.hizmet_adi ?? '').trim() || 'Hizmet';
        if (!gruplar.has(ad)) gruplar.set(ad, []);
        gruplar.get(ad).push(s);
    });
    const liste = gruplar.size
        ? [...gruplar].map(([ad, satirlar]) => `<div class="hs-grup-baslik"><strong>${esc(ad)}</strong><span class="hs-sayac">${satirlar.length} seans</span></div>
            ${satirlar.map(seansSatiri).join('')}`).join('')
        : '<div class="hs-uyari"><i class="fa fa-info-circle"></i> Bu paket için henüz oluşturulmuş randevu bulunmamaktadır.</div>';
    return `<div class="hs-detay-ust">
            <span class="hs-ikon hs-ikon--buyuk"><i class="fa fa-leaf"></i></span>
            <div><h3>${esc(paketAdi(k))}</h3><small>Seans Detayları</small></div>
        </div>
        <div class="hs-genel">
            <div class="hs-genel-ust"><span>${esc(sinirsizMi(k) ? sinirsizEtiketi(k) : 'Genel İlerleme')}</span>
                <strong>${esc(sinirsizMi(k) ? kalanSureMetni(k) : `${k.gelinenSeansSayisi} / ${k.toplamSeansSayisi}`)}</strong></div>
            ${sinirsizMi(k) ? `<span class="hs-sinirsiz">${esc(sinirsizBilgi(k))}</span>` : ilerleme(k, true)}
            ${miniSayilar(k)}
        </div>
        ${liste}`;
}

// Ust ozet: mobil gibi sayfadaki kartlarin sunucu sayilari toplanir (sinirsiz paket seans toplamina girmez).
function ozetCiz() {
    const topla = (alan, kosul = () => true) => kartlar.reduce((a, k) => a + (kosul(k) ? Number(k[alan]) || 0 : 0), 0);
    $('seansAlt').textContent = kartlar.length
        ? `${kartlar.length} paket • ${topla('toplamSeansSayisi', (k) => !sinirsizMi(k))} seans`
        : 'Aktif paketin yok';
    const ist = $('seansIstatistik');
    ist.hidden = !kartlar.length;
    const kutu = (tur, ikon, etiket, deger) => `<div class="hs-ist hs-renk--${tur}"><span class="hs-ist-ikon"><i class="fa ${ikon}"></i></span><span><small>${etiket}</small><strong>${deger}</strong></span></div>`;
    ist.innerHTML = kutu('bekleyen', 'fa-calendar-check-o', 'Bekleyen', topla('bekleyenSeansSayisi'))
        + kutu('gelinen', 'fa-check-circle', 'Gelinen', topla('gelinenSeansSayisi'))
        + kutu('gelmeyen', 'fa-times-circle', 'Gelmeyen', topla('gelinmeyenSeansSayisi'));
}

musteriHazir(() => {
    const sayfaCiz = sayfalama($('seansSayfa'), (no) => yukle(no));
    const yukle = (no) => calistir(async () => {
        $('seansAlt').textContent = 'Yükleniyor...';
        const veri = await api('/musteri-uygulama/seanslar', { sorgu: { salon_id: salonId(), page: no } });
        kartlar = veri.data || [];
        $('seansListe').innerHTML = kartlar.map(paketKarti).join('');
        $('seansBos').hidden = kartlar.length > 0;
        ozetCiz();
        sayfaCiz(veri.current_page, veri.last_page);
        return true;
    }).then((ok) => { if (!ok) $('seansAlt').textContent = ''; }); // hata calistir'da bildirildi

    $('seansListe').addEventListener('click', (e) => {
        const kart = e.target.closest('[data-i]');
        if (kart) pencereAc($('seansPencere'), detay(kartlar[Number(kart.dataset.i)]));
    });
    yukle(1);
});
