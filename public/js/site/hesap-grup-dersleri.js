// Musteri paneli: Grup Derslerim + ders rezervasyonu. Mobil karsiligi: grup_derslerim_ekran.dart ve
// grup_dersi_rezervasyon_ekran.dart (icerik ve isleyis ayni). Kontenjan (bos), katilim izni ve gecmis bilgisi sunucudan.
import { api, esc, salonId, ApiHatasi, musteriHazir, yukleniyor } from './musteri-api.js';
import { $, bildir, onayIste, ikiHane, GUNLER as GUN_AD, GUNLER_KISA as GUN_KISA, AYLAR as AY_AD } from './hesap-ortak.js';

const SALON = salonId();
const kok = $('grupDersleri');
const DURUM = {
    geldi: ['Katıldınız', 'geldi'],
    gelmedi: ['Gelmediniz', 'gelmedi'],
    telafi: ['Telafi', 'telafi'],
    bekleme: ['Bekleme listesi', 'bekleme'],
};


const ymd = (d) => `${d.getFullYear()}-${ikiHane(d.getMonth() + 1)}-${ikiHane(d.getDate())}`;
const gunTarihi = (s) => new Date(String(s).slice(0, 10) + 'T00:00:00');

// ───────── Derslerim ─────────
function dersKarti(d) {
    const durum = d.durum || 'rezerve';
    const [etiket, sinif] = DURUM[durum] || ['Rezerve', 'rezerve'];
    const katilim = d.geldim_isaretleyebilir === true
        ? `<div class="ho-soru">Bu derse katıldınız mı?</div>
           <div class="ho-ikili">
              <button type="button" class="ho-btn ho-btn--hayir" data-katilim="gelmedi" data-id="${esc(d.katilimci_id)}"><i class="fa fa-times"></i> Katılmadım</button>
              <button type="button" class="ho-btn ho-btn--evet" data-katilim="geldi" data-id="${esc(d.katilimci_id)}"><i class="fa fa-check"></i> Katıldım</button>
           </div>`
        : (d.gecmis === true && durum === 'rezerve' ? '<div class="ho-not">Katılım işaretlenmedi</div>' : '');
    return `<div class="ho-kart">
        <div class="ho-kart-ust">
            <strong class="ho-kart-baslik">${esc(d.ders_tipi)}</strong>
            <span class="ho-rozet ho-rozet--${sinif}">${etiket}</span>
        </div>
        <div class="ho-satir"><i class="fa fa-calendar"></i> ${esc(d.tarih)} ${GUN_AD[gunTarihi(d.tarih).getDay()] || ''} &nbsp; <i class="fa fa-clock-o"></i> ${esc(d.saat)} - ${esc(d.saat_bitis)}</div>
        ${d.personel ? `<div class="ho-satir"><i class="fa fa-user"></i> ${esc(d.personel)}</div>` : ''}
        ${d.salon_adi ? `<div class="ho-satir ho-satir--soluk"><i class="fa fa-map-marker"></i> ${esc(d.salon_adi)}</div>` : ''}
        ${katilim}
    </div>`;
}

let dersler = [];
async function derslerimYukle() {
    $('derslerimHata').hidden = true;
    yukleniyor(true);
    try {
        dersler = await api('/musteri-uygulama/derslerim', { sorgu: { salon_id: SALON } }) || [];
        $('derslerimListe').innerHTML = dersler.map(dersKarti).join('');
        $('derslerimBos').hidden = dersler.length > 0;
    } catch (e) {
        $('derslerimListe').innerHTML = '';
        $('derslerimBos').hidden = true;
        $('derslerimHata').querySelector('[data-hata-metin]').textContent = 'Yüklenemedi: ' + e.message;
        $('derslerimHata').hidden = false;
    } finally {
        yukleniyor(false);
    }
}

$('derslerimListe').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-katilim]');
    if (!b) return;
    const d = dersler.find((x) => String(x.katilimci_id) === b.dataset.id);
    if (!d) return;
    const geldi = b.dataset.katilim === 'geldi';
    const onay = await onayIste({ baslik: geldi ? 'Katıldım' : 'Katılmadım', dugme: 'Onayla',
        metin: `${d.ders_tipi} • ${d.tarih} ${d.saat} — ${geldi ? 'Bu derse katıldığınızı' : 'Bu derse katılmadığınızı'} bildiriyorsunuz. Onaylıyor musunuz?` });
    if (!onay) return;
    bildir('');
    yukleniyor(true);
    try {
        await api(`/musteri-uygulama/derslerim/${d.katilimci_id}/katilim`, { yontem: 'POST', govde: { durum: b.dataset.katilim } });
        yukleniyor(false);
        await derslerimYukle();
        bildir(geldi ? 'Katılımınız kaydedildi ✨' : 'Bilginiz için teşekkürler.', true);
    } catch (err) {
        bildir('Hata: ' + err.message);
    } finally {
        yukleniyor(false);
    }
});

// ───────── Ders rezervasyonu ─────────
const bugun = (() => { const n = new Date(); return new Date(n.getFullYear(), n.getMonth(), n.getDate()); })();
let uygun = [];
let secili = bugun;
let degisti = false;
const islemde = new Set();

const gunDersVar = (g) => uygun.some((d) => String(d.tarih).slice(0, 10) === ymd(g));

function tarihSeridiCiz() {
    $('rezervasyonAy').textContent = `${AY_AD[secili.getMonth()]} ${secili.getFullYear()}`;
    let html = '';
    for (let i = 0; i < 30; i++) {
        const g = new Date(bugun.getFullYear(), bugun.getMonth(), bugun.getDate() + i);
        const s = ymd(g) === ymd(secili);
        html += `<button type="button" class="ho-gun${s ? ' is-secili' : ''}" data-gun="${ymd(g)}" role="option" aria-selected="${s}">
            <span class="ho-gun-kisa">${GUN_KISA[g.getDay()]}</span>
            <span class="ho-gun-no">${g.getDate()}</span>
            <span class="ho-gun-nokta${gunDersVar(g) ? ' is-var' : ''}"></span>
        </button>`;
    }
    $('rezervasyonGunler').innerHTML = html;
    $('rezervasyonGunler').querySelector('.is-secili')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
}

function uygunKarti(d) {
    const kapasite = Number(d.kapasite) || 0;
    const doluluk = Number(d.doluluk) || 0;
    const bos = Number(d.bos) || 0;
    const dolu = bos <= 0;
    const mesgul = islemde.has(Number(d.id));
    return `<div class="ho-kart ho-ders">
        <div class="ho-ders-saat"><strong>${esc(d.saat)}</strong>${d.saat_bitis ? `<small>${esc(d.saat_bitis)}</small>` : ''}</div>
        <div class="ho-ders-govde">
            <strong>${esc(d.ders_tipi)}</strong>
            ${d.egitmen ? `<div class="ho-satir ho-satir--soluk">${esc(d.egitmen)}</div>` : ''}
            <div class="ho-satir"><i class="fa fa-users"></i> ${doluluk}/${kapasite} dolu
                <b class="${dolu ? 'ho-kirmizi' : 'ho-yesil'}">${dolu ? 'Dolu' : `${bos} yer kaldı`}</b></div>
        </div>
        <button type="button" class="ho-btn ho-btn--mor" data-rezerve="${esc(d.id)}" ${dolu || mesgul ? 'disabled' : ''}>
            ${mesgul ? '<i class="fa fa-spinner fa-spin"></i>' : (dolu ? 'Dolu' : 'Rezervasyon')}
        </button>
    </div>`;
}

function gunListesiCiz() {
    const t = ymd(secili);
    const gunun = uygun.filter((d) => String(d.tarih).slice(0, 10) === t)
        .sort((a, b) => String(a.saat).localeCompare(String(b.saat)));
    $('rezervasyonListe').innerHTML = gunun.map(uygunKarti).join('');
    $('rezervasyonGunBos').hidden = gunun.length > 0;
}

async function uygunYukle() {
    $('rezervasyonHata').hidden = true;
    yukleniyor(true);
    try {
        // Mobil gibi: sunucu hatasinda bos liste ("ders yok"), baglanti hatasinda hata + tekrar dene.
        uygun = await api('/musteri-uygulama/grup-dersleri', { sorgu: { salon_id: SALON } }).catch((e) => {
            if (e instanceof ApiHatasi && e.kod !== 0) return [];
            throw e;
        }) || [];
        // Secili gunde ders yoksa ilk dersli gun secilir.
        if (!gunDersVar(secili) && uygun.length) {
            secili = uygun.map((d) => gunTarihi(d.tarih)).reduce((a, b) => (b < a ? b : a));
        }
        tarihSeridiCiz();
        gunListesiCiz();
        $('rezervasyonIcerik').hidden = false;
    } catch (e) {
        $('rezervasyonIcerik').hidden = true;
        $('rezervasyonHata').hidden = false;
    } finally {
        yukleniyor(false);
    }
}

$('rezervasyonGunler').addEventListener('click', (e) => {
    const b = e.target.closest('[data-gun]');
    if (!b) return;
    secili = gunTarihi(b.dataset.gun);
    tarihSeridiCiz();
    gunListesiCiz();
});

$('rezervasyonListe').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-rezerve]');
    if (!b || b.disabled) return;
    const id = Number(b.dataset.rezerve);
    const d = uygun.find((x) => Number(x.id) === id);
    if (!d) return;
    const onay = await onayIste({ baslik: 'Rezervasyon', dugme: 'Rezervasyon Yap',
        metin: `${d.ders_tipi} • ${GUN_AD[secili.getDay()]} ${secili.getDate()} ${AY_AD[secili.getMonth()]} ${d.saat} — Bu derse rezervasyon yapmak istiyor musunuz?` });
    if (!onay) return;
    bildir('');
    islemde.add(id);
    gunListesiCiz();
    try {
        await api(`/musteri-uygulama/grup-dersleri/${id}/rezervasyon`, { yontem: 'POST', govde: { salon_id: SALON } });
        degisti = true;
        islemde.delete(id);
        await uygunYukle();
        bildir('Rezervasyonunuz alındı.', true);
    } catch (err) {
        islemde.delete(id);
        gunListesiCiz();
        bildir(err instanceof ApiHatasi && err.kod !== 0 ? (err.message || 'Rezervasyon yapılamadı.') : 'İşlem başarısız.');
    }
});

function rezervasyonGoster(acik) {
    bildir('');
    $('derslerimPanel').hidden = acik;
    $('rezervasyonPanel').hidden = !acik;
    if (acik) { degisti = false; uygunYukle(); }
    else if (degisti) derslerimYukle();
}

$('rezervasyonAc').addEventListener('click', () => rezervasyonGoster(true));
$('rezervasyonKapat').addEventListener('click', () => rezervasyonGoster(false));
$('rezervasyonTekrar').addEventListener('click', uygunYukle);

musteriHazir(() => {
    $('rezervasyonAc').hidden = kok.dataset.rezervasyon !== '1';
    derslerimYukle();
});
