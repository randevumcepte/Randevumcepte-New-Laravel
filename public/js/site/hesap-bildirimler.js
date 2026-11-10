// Musteri paneli > Bildirimler: mobil MusteriBildirimlerScreen'in web karsiligi. Liste, Tumu/Okunmamis filtresi, tek
// ve toplu okundu, bildirim tipine gore yonlendirme. Veri /api/v2/musteri-uygulama/bildirimler (kapsam: bu salon).
import { api, esc, salonId, yukleniyor, musteriHazir } from './musteri-api.js';
import { toast, AYLAR_KISA, ikiHane } from './hesap-ortak.js';

const $ = (id) => document.getElementById(id);
const SALON = salonId();
const kok = $('bildirimler');
const SAYFA_BOYU = 30;

// Bildirim tipleri (app/Services/NotificationTypes.php; mobil notification_types.dart ile ayni adlar).
const CARK_TIPI = 'wheel_chance';
const INDIRIM_TIPLERI = ['campaign', 'discount', 'birthday'];
const RANDEVU_TIPLERI = ['appointment_created', 'appointment_approved', 'appointment_cancelled', 'appointment_time_changed',
    'appointment_reminder', 'appointment_reminder_hour', 'staff_assigned', 'payment_received'];

const durum = { liste: [], sayfa: 0, sonSayfa: 1, filtre: 0, sayac: { okunmamis: 0, toplam: 0 }, toplu: false };

/** "Şimdi", "5 dk önce", "3 sa önce", "Dün", "4 gün önce", sonra "12 Kas 14:30" (mobil _relativeTime). */
function goreliZaman(t) {
    const d = new Date(String(t || '').replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return t || '';
    const dk = Math.floor((Date.now() - d.getTime()) / 60000);
    if (dk < 1) return 'Şimdi';
    if (dk < 60) return `${dk} dk önce`;
    const sa = Math.floor(dk / 60);
    if (sa < 24) return `${sa} sa önce`;
    const gun = Math.floor(sa / 24);
    if (gun === 1) return 'Dün';
    if (gun < 7) return `${gun} gün önce`;
    return `${d.getDate()} ${AYLAR_KISA[d.getMonth()]} ${ikiHane(d.getHours())}:${ikiHane(d.getMinutes())}`;
}

function bildirimKarti(b) {
    const okundu = Number(b.okundu) === 1;
    const avatar = b.resim_url
        ? `<img src="${esc(b.resim_url)}" alt="" loading="lazy">`
        : '<i class="fa fa-bell"></i>';
    let etiket = '';
    if (b.randevu_id) etiket = '<span class="hh-etiket">Randevu</span>';
    else if (b.arsiv?.uzanti) etiket = '<span class="hh-etiket hh-etiket--turuncu">PDF</span>';
    return `<button type="button" class="hh-bildirim ${okundu ? '' : 'hh-bildirim--yeni'}" data-id="${b.id}">
        <span class="hh-bildirim-avatar">${avatar}</span>
        <span class="hh-bildirim-metin">
            <strong>${okundu ? '' : '<span class="hh-nokta"></span>'}${esc(b.aciklama)}</strong>
            <small><i class="fa fa-clock-o"></i> ${esc(goreliZaman(b.tarih_saat))} ${etiket}</small>
        </span>
        <i class="fa fa-chevron-right hh-bildirim-ok"></i>
    </button>`;
}

function ciz() {
    const { okunmamis, toplam } = durum.sayac;
    $('bdToplam').textContent = toplam;
    $('bdYeni').textContent = okunmamis > 0 ? `${okunmamis} yeni` : 'Hepsi okundu';
    $('bdYeni').className = okunmamis > 0 ? 'hh-kirmizi' : 'hh-yesil';
    $('bdTumunuOku').hidden = okunmamis <= 0;
    $('bdFiltreRozet').hidden = okunmamis <= 0;
    $('bdFiltreRozet').textContent = okunmamis;

    const liste = $('bdListe');
    liste.innerHTML = durum.liste.map(bildirimKarti).join('');
    liste.querySelectorAll('img').forEach((img) => img.addEventListener('error', () => { img.outerHTML = '<i class="fa fa-bell"></i>'; }));
    $('bdBos').hidden = durum.liste.length > 0;
    $('bdBosBaslik').textContent = durum.filtre ? 'Okunmamış bildirim yok' : 'Bildiriminiz bulunmuyor';
    $('bdBosAlt').textContent = durum.filtre ? 'Tüm bildirimleriniz okundu olarak işaretli.' : 'Yeni bildirimler burada görünecek.';
    $('bdDaha').hidden = durum.sayfa >= durum.sonSayfa;
}

async function yukle(bastan) {
    $('bdHata').hidden = true;
    yukleniyor(true);
    try {
        const v = await api('/musteri-uygulama/bildirimler', { sorgu: {
            salon_id: SALON, page: bastan ? 1 : durum.sayfa + 1, per_page: SAYFA_BOYU, filtre: durum.filtre ? 'okunmamis' : null,
        } });
        if (bastan) durum.liste = [];
        durum.liste.push(...(v.data || []));
        durum.sayfa = v.current_page || 1;
        durum.sonSayfa = v.last_page || 1;
        durum.sayac = v.sayac || durum.sayac;
        ciz();
    } catch (e) {
        if (bastan) {
            $('bdListe').innerHTML = '';
            $('bdBos').hidden = true;
            $('bdDaha').hidden = true;
            $('bdHata').hidden = false;
        } else {
            toast(e.message);
        }
    } finally {
        yukleniyor(false);
    }
}

/** Dokunulan bildirimin gidecegi adres (mobil ile ayni sira: cark, indirim, randevu, PDF). */
function hedef(b) {
    if (b.tip === CARK_TIPI) return kok.dataset.cark;
    if (INDIRIM_TIPLERI.includes(b.tip)) return kok.dataset.oduller;
    if (RANDEVU_TIPLERI.includes(b.tip) || b.randevu_id) return kok.dataset.randevular;
    return null;
}

async function tikla(b) {
    if (Number(b.okundu) !== 1) {
        try {
            await api(`/musteri-uygulama/bildirimler/${b.id}/okundu`, { yontem: 'POST' });
            b.okundu = 1;
            durum.sayac.okunmamis = Math.max(0, durum.sayac.okunmamis - 1);
            if (durum.filtre) durum.liste = durum.liste.filter((x) => x !== b); // Okunmamış görünümünden düşer
            ciz();
        } catch (e) { /* okundu yazilamasa da yonlendirme yapilir */ }
    }
    const adres = hedef(b);
    if (adres) { location.href = adres; return; }
    const uzanti = b.arsiv?.uzanti;
    if (uzanti) window.open(/^https?:\/\//i.test(uzanti) ? uzanti : kok.dataset.eskiKok + String(uzanti).replace(/^\/+/, ''), '_blank', 'noopener');
}

async function tumunuOku() {
    if (durum.toplu) return;
    durum.toplu = true;
    $('bdTumunuOku').disabled = true;
    try {
        await api('/musteri-uygulama/bildirimler/tumu/okundu', { yontem: 'POST', govde: { salon_id: SALON } });
        await yukle(true);
        toast('Tüm bildirimler okundu olarak işaretlendi', true);
    } catch (e) {
        toast(e.message);
    } finally {
        durum.toplu = false;
        $('bdTumunuOku').disabled = false;
    }
}

musteriHazir(() => {
    $('bdListe').addEventListener('click', (e) => {
        const k = e.target.closest('[data-id]');
        const b = k && durum.liste.find((x) => String(x.id) === k.dataset.id);
        if (b) tikla(b);
    });
    $('bdFiltre').addEventListener('click', (e) => {
        const f = e.target.closest('[data-filtre]');
        if (!f || Number(f.dataset.filtre) === durum.filtre) return;
        durum.filtre = Number(f.dataset.filtre);
        $('bdFiltre').querySelectorAll('[data-filtre]').forEach((x) => x.classList.toggle('aktif', x === f));
        yukle(true);
    });
    $('bdTumunuOku').addEventListener('click', tumunuOku);
    $('bdDaha').addEventListener('click', () => yukle(false));
    $('bdTekrar').addEventListener('click', () => yukle(true));
    yukle(true);
});
