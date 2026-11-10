// Musteri paneli (giris, profil, randevularim, sifre) — tum veri /api/v2/musteri-uygulama.
import { api, salonId, tokenYaz, girisliMi, oturumCiz, yukleniyor, esc } from './musteri-api.js';

const $ = (id) => document.getElementById(id);
const SALON = salonId();
const sayfa = document.body.dataset.sayfa;
const AYLAR = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
const GUNLER = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
const DURUMLAR = {
    0: ['bekleme', 'fa-hourglass-half', 'Beklemede'],
    1: ['onay', 'fa-check-circle', 'Onaylandı'],
    2: ['iptal', 'fa-ban', 'İptal Edildi'],
    3: ['iptal-self', 'fa-times-circle', 'İptal Ettiniz'],
};

function bildir(mesaj, basarili = false) {
    const b = $('hesapBildirim');
    b.textContent = mesaj;
    b.style.display = mesaj ? 'block' : 'none';
    b.style.color = basarili ? '#0a7c3a' : '';
    if (mesaj) b.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

async function calistir(is) {
    bildir('');
    yukleniyor(true);
    try { return await is(); } catch (e) { bildir(e.message); return undefined; } finally { yukleniyor(false); }
}

// ───────── Giris / Kayit / Sifre yenileme ─────────
function girisSayfasi() {
    if (girisliMi()) { location.href = document.body.dataset.randevularim; return; }
    const acilis = Date.now();
    let mod = 'giris';

    const modKur = (yeni) => {
        mod = yeni;
        const baslik = { giris: 'Giriş Yap', kayit: 'Üye Ol', yenile: 'Şifre Yenile' }[mod];
        $('girisBaslik').textContent = baslik;
        $('girisButon').textContent = baslik;
        document.querySelectorAll('[data-kayit-alani]').forEach((e) => { e.hidden = mod !== 'kayit'; });
        document.querySelectorAll('[data-giris-alani]').forEach((e) => { e.hidden = mod !== 'giris'; });
        document.querySelectorAll('[data-yenile-alani]').forEach((e) => { e.hidden = mod !== 'yenile'; });
        $('modDegistir').textContent = mod === 'giris' ? 'Üye Ol' : 'Giriş Yap';
        kodIstendi = false;
        $('kayitKodGrup').hidden = true;
    };
    // Uye Ol iki adim: once kod istenir, sonra kodla giris (kodsuz hesap acilmaz; kullanici 2026-11-10).
    let kodIstendi = false;
    if (location.hash === '#kayit') modKur('kayit');

    const telefon = () => $('cep_telefon').value.trim();
    const girildi = (sonuc) => { tokenYaz(sonuc.token); location.href = document.body.dataset.randevularim; };

    $('modDegistir').addEventListener('click', (e) => { e.preventDefault(); modKur(mod === 'giris' ? 'kayit' : 'giris'); });
    $('sifremiUnuttum').addEventListener('click', (e) => {
        e.preventDefault();
        calistir(async () => {
            await api('/musteri-oturum/sifre-kodu', { yontem: 'POST', govde: { cep_telefon: telefon(), salon_id: SALON } });
            modKur('yenile');
            bildir('Numaranız kayıtlıysa SMS ile kod gönderildi.', true);
        });
    });
    $('girisFormu').addEventListener('submit', (e) => {
        e.preventDefault();
        calistir(async () => {
            if (mod === 'giris') {
                girildi(await api('/musteri-oturum/giris', { yontem: 'POST', govde: { cep_telefon: telefon(), password: $('sifre').value, salon_id: SALON } }));
            } else if (mod === 'kayit' && !kodIstendi) {
                await api('/musteri-oturum/kod', { yontem: 'POST', govde: {
                    ad_soyad: $('ad_soyad').value, cep_telefon: telefon(), kvkk: $('kvkk').checked, salon_id: SALON,
                    _hp: e.target.elements._hp.value, form_sure_sn: Math.floor((Date.now() - acilis) / 1000),
                } });
                kodIstendi = true;
                $('kayitKodGrup').hidden = false;
                $('girisButon').textContent = 'Doğrula ve Devam Et';
                $('kayit_kod').focus();
                bildir('Doğrulama kodu telefonunuza gönderildi (WhatsApp ya da SMS).', true);
            } else if (mod === 'kayit') {
                girildi(await api('/musteri-oturum/kodla-giris', { yontem: 'POST', govde: {
                    cep_telefon: telefon(), salon_id: SALON, kod: $('kayit_kod').value.trim(), ad_soyad: $('ad_soyad').value,
                } }));
            } else {
                await api('/musteri-oturum/sifre-sifirla', { yontem: 'POST', govde: { cep_telefon: telefon(), salon_id: SALON, kod: $('sifre_kod').value, yeni_sifre: $('yeni_sifre').value } });
                modKur('giris');
                bildir('Şifreniz güncellendi. Yeni şifrenizle giriş yapabilirsiniz.', true);
            }
        });
    });
}

// ───────── Profil ─────────
const tarihGoster = (t) => (t && /^\d{4}-\d{2}-\d{2}/.test(t) ? t.slice(0, 10).split('-').reverse().join('.') : '');
const tarihOku = (t) => { const m = t.trim().match(/^(\d{2})\.(\d{2})\.(\d{4})$/); return m ? `${m[3]}-${m[2]}-${m[1]}` : null; };

function profilSayfasi(ben) {
    const form = $('profilFormu');
    const doldur = (b) => {
        ['name', 'cep_telefon', 'email', 'ev_telefon'].forEach((k) => { form.elements[k].value = b[k] || ''; });
        form.elements.cinsiyet.value = String(b.cinsiyet ?? 0);
        form.elements.dogum_tarihi.value = tarihGoster(b.dogum_tarihi);
        $('profilResim').src = b.profil_resim || $('profilResim').dataset.varsayilan;
    };
    doldur(ben);
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const dogum = form.elements.dogum_tarihi.value;
        if (dogum && !tarihOku(dogum)) { bildir('Doğum tarihini GG.AA.YYYY biçiminde yazınız.'); return; }
        calistir(async () => {
            doldur(await api('/musteri-uygulama/ben', { yontem: 'PATCH', govde: {
                name: form.elements.name.value, cep_telefon: form.elements.cep_telefon.value, email: form.elements.email.value || null,
                ev_telefon: form.elements.ev_telefon.value || null, cinsiyet: form.elements.cinsiyet.value, dogum_tarihi: dogum ? tarihOku(dogum) : null,
            } }));
            bildir('Profiliniz güncellendi.', true);
        });
    });
    $('profil_resim').addEventListener('change', (e) => {
        const dosya = e.target.files[0];
        if (!dosya) return;
        const veri = new FormData();
        veri.append('resim', dosya);
        calistir(async () => doldur(await api('/musteri-uygulama/ben/resim', { yontem: 'POST', govde: veri })));
    });
    $('resimSil').addEventListener('click', (e) => {
        e.preventDefault();
        calistir(async () => doldur(await api('/musteri-uygulama/ben/resim', { yontem: 'DELETE' })));
    });
}

// ───────── Randevularim ─────────
function randevuKarti(r) {
    const t = new Date(r.tarih.slice(0, 10) + 'T00:00:00');
    const [sinif, ikon, metin] = DURUMLAR[r.durum] || DURUMLAR[0];
    const cipler = (liste, ek = '') => liste.map((x) => `<span class="apt-chip ${ek}">${esc(x)}</span>`).join('');
    const olusturma = r.olusturulma ? `<div class="appointment-meta"><span class="apt-meta-item"><i class="fa fa-clock-o"></i> Oluşturulma: ${esc(tarihGoster(r.olusturulma))}</span></div>` : '';
    return `<div class="appointment-card durum-${r.durum}">
        <div class="appointment-date">
            <span class="apt-day">${String(t.getDate()).padStart(2, '0')}</span>
            <span class="apt-month">${AYLAR[t.getMonth()]}</span>
            <span class="apt-time">${esc(r.saat)}</span>
            <span class="apt-weekday">${GUNLER[t.getDay()]}</span>
        </div>
        <div class="appointment-body">
            <div class="appointment-salon"><i class="fa fa-store" style="color:var(--primary-purple);"></i> <span>${esc(r.salonlar.salon_adi)}</span></div>
            ${r.hizmetler.length ? `<div class="appointment-row"><span class="apt-label"><i class="fa fa-list-ul"></i> Hizmetler:</span><span class="apt-value">${cipler(r.hizmetler.map((h) => h.hizmetler.hizmet_adi))}</span></div>
            <div class="appointment-row"><span class="apt-label"><i class="fa fa-user-md"></i> Personel:</span><span class="apt-value">${cipler(r.hizmetler.map((h) => h.personeller.personel_adi), 'apt-chip--soft')}</span></div>` : ''}
            ${olusturma}
        </div>
        <div class="appointment-side">
            <span class="apt-status apt-status--${sinif}"><i class="fa ${ikon}"></i> ${metin}</span>
            ${r.iptal_edilebilir ? `<button type="button" class="apt-action apt-action--danger" data-iptal="${r.id}"><i class="fa fa-times"></i> İptal Et</button>` : ''}
        </div>
    </div>`;
}

function randevularSayfasi() {
    let sayfaNo = 0;
    const yukle = () => calistir(async () => {
        const veri = await api('/musteri-uygulama/randevular', { sorgu: { salon_id: SALON, page: sayfaNo + 1 } });
        sayfaNo = veri.current_page;
        $('randevuListe').insertAdjacentHTML('beforeend', veri.data.map(randevuKarti).join(''));
        $('randevuBos').hidden = veri.total > 0;
        $('dahaFazla').hidden = veri.current_page >= veri.last_page;
    });
    $('dahaFazla').addEventListener('click', yukle);
    $('randevuListe').addEventListener('click', (e) => {
        const b = e.target.closest('[data-iptal]');
        if (!b || !window.confirm('Randevunuzu iptal etmek istediğinize emin misiniz?')) return;
        calistir(async () => {
            await api(`/musteri-uygulama/randevular/${b.dataset.iptal}/iptal`, { yontem: 'POST' });
            $('randevuListe').innerHTML = '';
            sayfaNo = 0;
            await yukle();
            bildir('Randevunuz iptal edildi.', true);
        });
    });
    yukle();
}

// ───────── Sifre ─────────
function sifreSayfasi() {
    $('sifreFormu').addEventListener('submit', (e) => {
        e.preventDefault();
        if ($('yeni_sifre').value !== $('yeni_sifre_tekrar').value) { bildir('Yeni şifreler birbiriyle aynı değil.'); return; }
        calistir(async () => {
            await api('/musteri-uygulama/ben/sifre', { yontem: 'POST', govde: { mevcut_sifre: $('mevcut_sifre').value, yeni_sifre: $('yeni_sifre').value } });
            e.target.reset();
            bildir('Şifreniz değiştirildi.', true);
        });
    });
}

// ───────── Acilis ─────────
$('customerUserBtn')?.addEventListener('click', (e) => { e.stopPropagation(); $('customerUserMenu').classList.toggle('open'); });
document.addEventListener('click', () => $('customerUserMenu')?.classList.remove('open'));

if (sayfa === 'giris') {
    girisSayfasi();
} else {
    oturumCiz().then((ben) => {
        if (!ben) { location.href = document.body.dataset.giris; return; }
        ({ profil: () => profilSayfasi(ben), randevular: randevularSayfasi, sifre: sifreSayfasi })[sayfa]();
    });
}
