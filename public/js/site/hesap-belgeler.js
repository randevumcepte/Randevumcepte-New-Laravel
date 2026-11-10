// Musteri paneli > Sozlesme / Belgelerim: mobil musteripaneli/menu/musterisözlesmeleri.dart karsiligi.
// Liste, arama (bos ya da en az 3 harf), sayfalama, detay penceresi ve belge indirme. Mobil musteri ekraninda imza yoktur.
// Veri: GET /musteri-uygulama/sozlesmelerim (musteri token'dan; yalniz kendi kayitlari).
import { api, esc, salonId, musteriHazir } from './musteri-api.js';
import { $, sayfalama, pencereAc, calistir } from './hesap-ortak.js';

const SALON = salonId();
const KOK = $('belgeSayfa').dataset.kok.replace(/\/+$/, '');
let satirlar = [];
let sonArama = '';

// Mobil getStatusColorArsiv.
function durum(a) {
    if (a.durum === null && a.cevapladi === null && a.cevapladi2 === null) return ['#6a1b9a', 'Harici Belge'];
    if (a.durum === null && (a.cevapladi === 1 || a.cevapladi2 === 1)) return ['#fbc02d', 'Onay Bekleniyor'];
    if (a.durum === null && (a.cevapladi === 0 || a.cevapladi2 === 0)) return ['#1976d2', 'Form Bekleniyor'];
    if (a.durum === 1) return ['#4caf50', 'Onaylı'];
    if (a.durum === 0) return ['#e53935', 'İptal Edildi'];
    return ['#9ca3af', ''];
}

const formAdi = (a) => String(a.form?.form_adi ?? '');
const baslik = (a) => (formAdi(a).toLowerCase() === 'harici' ? (a.sozlesme_adi || 'Harici Belge') : (formAdi(a) || 'Belge'));
const personel = (a) => a.personel?.personel_adi || '-';
const dosyaVar = (a) => Boolean(a.uzanti) && a.uzanti !== 'null';

function ikon(a) {
    const f = formAdi(a).toLowerCase();
    if (f === 'harici' || String(a.sozlesme_adi || '').toLowerCase().includes('belge')) return 'fa-paperclip';
    if (f.includes('sağlık') || f.includes('saglik')) return 'fa-medkit';
    if (f.includes('sözleşme') || f.includes('sozlesme')) return 'fa-handshake-o';
    if (f.includes('aydınlat') || f.includes('kvkk') || f.includes('onay')) return 'fa-check-square-o';
    return 'fa-file-text';
}

const cip = ([renk, metin]) => `<span class="hb-durum" style="--hb-ton:${renk}">${esc(metin || '—')}</span>`;

function kart(a, i) {
    const d = durum(a);
    return `<button type="button" class="hb-belge" data-i="${i}">
        <span class="hb-belge-ikon" style="--hb-ton:${d[0]}"><i class="fa ${ikon(a)}"></i></span>
        <span class="hb-belge-govde">
            <span class="hb-belge-ust"><strong>${esc(baslik(a))}</strong>${cip(d)}</span>
            <small><i class="fa fa-calendar"></i> ${esc(a.tarih || '-')}</small>
            <small><i class="fa fa-user-o"></i> ${esc(personel(a))}</small>
        </span>
    </button>`;
}

function detay(a) {
    const d = durum(a);
    const satir = (ik, etiket, deger) => `<div class="hb-detay-satir"><i class="fa ${ik}"></i><div><small>${etiket}</small><span>${esc(deger)}</span></div></div>`;
    const indir = dosyaVar(a)
        ? `<a class="hb-dugme hb-dugme--tam" href="${esc(`${KOK}/${String(a.uzanti).replace(/^\/+/, '')}`)}" target="_blank" rel="noopener" download><i class="fa fa-download"></i> Belgeyi İndir</a>`
        : '<span class="hb-dugme hb-dugme--tam hb-dugme--pasif" aria-disabled="true"><i class="fa fa-ban"></i> Bu kayıt için dosya yok</span>';
    return `<div class="hb-detay-ust">
            <span class="hb-belge-ikon" style="--hb-ton:${d[0]}"><i class="fa ${ikon(a)}"></i></span>
            <div><h3>${esc(baslik(a))}</h3>${cip(d)}</div>
        </div>
        ${satir('fa-user-o', 'Müşteri', a.musteri?.name || '-')}
        ${satir('fa-calendar', 'Tarih', a.tarih || '-')}
        ${satir('fa-id-badge', 'Personel', personel(a))}
        ${indir}`;
}

const ciz = sayfalama($('belgeSayfalama'), (s) => yukle(s));

function yukle(sayfa = 1) {
    return calistir(async () => {
        const v = await api('/musteri-uygulama/sozlesmelerim', { sorgu: { salon_id: SALON, sekme: 'tumu', page: sayfa, per_page: 9, ara: sonArama } });
        satirlar = v.data || [];
        $('belgeListe').innerHTML = satirlar.map(kart).join('');
        $('belgeBos').hidden = satirlar.length > 0;
        $('belgeSayac').hidden = satirlar.length === 0;
        $('belgeSayac').textContent = String(satirlar.length);
        ciz(v.current_page || sayfa, v.last_page || 1);
    });
}

$('belgeListe').addEventListener('click', (e) => {
    const k = e.target.closest('[data-i]');
    if (k) pencereAc($('belgePencere'), detay(satirlar[Number(k.dataset.i)]));
});

// Mobil: bos ya da en az 3 harf, 450 ms bekleyip sunucuda arar.
let zamanlayici = null;
$('belgeAra').addEventListener('input', (e) => {
    const metin = e.target.value.trim();
    if (metin && metin.length < 3) return;
    clearTimeout(zamanlayici);
    zamanlayici = setTimeout(() => { if (metin !== sonArama) { sonArama = metin; yukle(1); } }, 450);
});

musteriHazir(() => yukle(1));
