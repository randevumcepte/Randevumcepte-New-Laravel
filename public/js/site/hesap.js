// Musteri paneli ortak acilisi + giris, profil, randevularim, sifre — tum veri /api/v2/musteri-uygulama.
// Diger sayfalar kendi modulunde (public/js/site/hesap-*.js); oturum ve musteri bilgisi burada bir kez cozulur.
import { api, salonId, tokenYaz, girisliMi, oturumCiz, esc, musteriYayinla } from './musteri-api.js';
import { $, sayfalama, pencereAc, AYLAR, GUNLER, onayIste, bildir, calistir } from './hesap-ortak.js';

const SALON = salonId();
const sayfa = document.body.dataset.sayfa;

// ───────── Giris / Kayit / Sifre yenileme ─────────
function girisSayfasi() {
    if (girisliMi()) { location.href = document.body.dataset.hesabim; return; }
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
    const girildi = (sonuc) => { tokenYaz(sonuc.token); location.href = document.body.dataset.hesabim; };

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

// Mobil hesapkaldirma.dart metinleri.
const HESAP_SILME_UYARI = 'Hesabınızı silmek istediğinizde tüm kişisel bilgileriniz, randevu geçmişiniz ve kayıtlı verileriniz 30 iş günü içerisinde işletme veritabanından kalıcı olarak silinecektir. Bu işlem geri alınamaz.';
const HESAP_SILME_BASARI = 'Hesap silme talebiniz işletmemize başarı ile iletilmiş olup süreç ile ilgili işletmemiz tarafından bilgilendirileceksiniz. Dilerseniz 30 iş günü içerisinde hesap silme talebinizi iptal edebilirsiniz.';

function profilSayfasi(ben) {
    const form = $('profilFormu');
    const doldur = (b, ustBar = false) => {
        ['name', 'cep_telefon', 'email', 'meslek', 'ev_telefon'].forEach((k) => { form.elements[k].value = b[k] || ''; });
        form.elements.cinsiyet.value = String(b.cinsiyet ?? 0);
        form.elements.dogum_tarihi.value = tarihGoster(b.dogum_tarihi);
        $('profilResim').src = b.profil_resim || $('profilResim').dataset.varsayilan;
        // Resim yuklenip kaldirilinca ust bardaki kucuk resim de guncellenir (oturumCiz yalniz acilista cizer).
        if (ustBar) document.querySelectorAll('[data-musteri-resim]').forEach((e) => { e.src = $('profilResim').src; });
    };
    doldur(ben);
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const dogum = form.elements.dogum_tarihi.value;
        if (dogum && !tarihOku(dogum)) { bildir('Doğum tarihini GG.AA.YYYY biçiminde yazınız.'); return; }
        calistir(async () => {
            doldur(await api('/musteri-uygulama/ben', { yontem: 'PATCH', govde: {
                name: form.elements.name.value, cep_telefon: form.elements.cep_telefon.value, email: form.elements.email.value || null,
                meslek: form.elements.meslek.value || null,
                ev_telefon: form.elements.ev_telefon.value || null, cinsiyet: form.elements.cinsiyet.value, dogum_tarihi: dogum ? tarihOku(dogum) : null,
            } }));
            bildir('Profil bilgileriniz başarıyla güncellendi!', true);
        });
    });
    $('profil_resim').addEventListener('change', (e) => {
        const dosya = e.target.files[0];
        e.target.value = '';
        if (!dosya) return;
        const veri = new FormData();
        veri.append('resim', dosya);
        calistir(async () => {
            doldur(await api('/musteri-uygulama/ben/resim', { yontem: 'POST', govde: veri }), true);
            bildir('Profil resmi başarıyla güncellendi!', true);
        });
    });
    $('resimSil').addEventListener('click', async (e) => {
        e.preventDefault();
        const onay = await onayIste({
            baslik: 'Profil resmi kaldırılsın mı?', metin: 'Profil resminiz silinecek. Bu işlem geri alınamaz.', dugme: 'Resmi Sil', tehlikeli: true,
        });
        if (onay) calistir(async () => { doldur(await api('/musteri-uygulama/ben/resim', { yontem: 'DELETE' }), true); bildir('Profil resminiz kaldırıldı.', true); });
    });
    $('hesapSil').addEventListener('click', async () => {
        const onay = await onayIste({
            baslik: 'Hesap Silme Talebi', dugme: 'Hesabımı Sil', vazgec: 'İptal', tehlikeli: true,
            ek: `<div class="hb-uyari-kutu"><strong>UYARI</strong><p>${esc(HESAP_SILME_UYARI)}</p></div>
                <label class="hb-onay-kutu"><input type="checkbox" name="kabul" value="1"> <span>Hesabımı silmek istediğimi ve tüm verilerimin kalıcı olarak silineceğini kabul ediyorum.</span></label>`,
            dogrula: (f) => (f.elements.kabul.checked ? '' : 'Lütfen devam etmeden önce şartları kabul ediniz.'),
        });
        if (!onay) return;
        calistir(async () => {
            try {
                await api('/musteri-uygulama/hesap-silme-talebi', { yontem: 'POST' });
            } catch (hata) {
                throw new Error('Hesap silme talebiniz işletmemize iletilirken bir hata oluştu. Lütfen daha sonra tekrar deneyiniz.');
            }
            bildir(HESAP_SILME_BASARI, true);
        });
    });
}

// ───────── Randevularim ─────────
// Mobil randevularim.dart: durum etiketleri, filtreler (Backend/backend.dart tarih ve durum eslemesi), detay ve iptal.
const DURUMLAR = {
    0: ['bekleme', 'fa-hourglass-half', 'Onay Bekliyor'],
    1: ['onay', 'fa-check-circle', 'Onaylı'],
    2: ['iptal', 'fa-ban', 'Reddedildi'],
    3: ['iptal-self', 'fa-times-circle', 'İptal Edildi'],
};
const gun = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

function tarihAraligi(secim) {
    const b = new Date();
    const y = b.getFullYear();
    const a = b.getMonth();
    const yarin = new Date(y, a, b.getDate() + 1);
    return {
        bugun: [gun(b), gun(b)],
        yarin: [gun(yarin), gun(yarin)],
        bu_ay: [gun(new Date(y, a, 1)), gun(new Date(y, a + 1, 0))],
        gelecek_ay: [gun(new Date(y, a + 1, 1)), gun(new Date(y, a + 2, 0))],
        bu_yil: [`${y}-01-01`, `${y}-12-31`],
    }[secim] || [null, null];
}

// Mobil: yalniz aktif (bekleyen/onayli), tahsilati eklenmemis ve gelecek tarihli randevu iptal edilebilir.
const iptalEdilebilir = (r) => r.iptal_edilebilir && !r.tahsilat_eklendi;
const hizmetMetni = (r) => r.hizmetler.map((h) => h.hizmetler.hizmet_adi).filter(Boolean);
const personelMetni = (r) => [...new Set(r.hizmetler.map((h) => h.personeller.personel_adi).filter(Boolean))];

function randevuKarti(r, i) {
    const t = new Date(r.tarih.slice(0, 10) + 'T00:00:00');
    const [sinif, ikon, metin] = DURUMLAR[r.durum] || DURUMLAR[0];
    const cipler = (liste, ek = '') => liste.map((x) => `<span class="apt-chip ${ek}">${esc(x)}</span>`).join('');
    const hizmetler = hizmetMetni(r);
    const personeller = personelMetni(r);
    const olusturma = r.olusturulma ? `<div class="appointment-meta"><span class="apt-meta-item"><i class="fa fa-clock-o"></i> Oluşturulma: ${esc(tarihGoster(r.olusturulma))}</span></div>` : '';
    return `<div class="appointment-card durum-${r.durum} hb-tiklanir" data-detay="${i}" tabindex="0" role="button">
        <div class="appointment-date">
            <span class="apt-day">${String(t.getDate()).padStart(2, '0')}</span>
            <span class="apt-month">${AYLAR[t.getMonth()]}</span>
            <span class="apt-time">${esc(r.saat)}</span>
            <span class="apt-weekday">${GUNLER[t.getDay()]}</span>
        </div>
        <div class="appointment-body">
            <div class="appointment-salon"><i class="fa fa-store" style="color:var(--primary-purple);"></i> <span>${esc(r.salonlar.salon_adi)}</span></div>
            <div class="appointment-row"><span class="apt-label"><i class="fa fa-list-ul"></i> Hizmetler:</span><span class="apt-value">${hizmetler.length ? cipler(hizmetler) : 'Hizmet bilgisi yok'}</span></div>
            ${personeller.length ? `<div class="appointment-row"><span class="apt-label"><i class="fa fa-user-md"></i> Personel:</span><span class="apt-value">${cipler(personeller, 'apt-chip--soft')}</span></div>` : ''}
            ${olusturma}
        </div>
        <div class="appointment-side">
            <span class="apt-status apt-status--${sinif}"><i class="fa ${ikon}"></i> ${metin}</span>
            ${iptalEdilebilir(r) ? `<button type="button" class="apt-action apt-action--danger" data-iptal="${r.id}"><i class="fa fa-times"></i> İptal Et</button>` : ''}
        </div>
    </div>`;
}

function randevuDetay(r) {
    const t = new Date(r.tarih.slice(0, 10) + 'T00:00:00');
    const [sinif, ikon, metin] = DURUMLAR[r.durum] || DURUMLAR[0];
    const satir = (ik, etiket, deger) => `<div class="hb-detay-satir"><i class="fa ${ik}"></i><div><small>${etiket}</small><span>${esc(deger)}</span></div></div>`;
    const hizmetler = hizmetMetni(r);
    const personeller = personelMetni(r);
    return `<div class="hb-detay-ust">
            <div><h3>${t.getDate()} ${AYLAR[t.getMonth()]} ${t.getFullYear()} · ${esc(r.saat)}</h3><small>${GUNLER[t.getDay()]}</small></div>
            <span class="apt-status apt-status--${sinif}"><i class="fa ${ikon}"></i> ${metin}</span>
        </div>
        ${satir('fa-store', 'Şube / Salon', r.salonlar.salon_adi)}
        ${satir('fa-list-ul', 'Hizmet(ler)', hizmetler.length ? hizmetler.join(', ') : 'Belirtilmemiş')}
        ${personeller.length ? satir('fa-user-md', 'Personel', personeller.join(', ')) : ''}
        ${r.musteri_notu ? satir('fa-sticky-note-o', 'Notum', r.musteri_notu) : ''}
        ${r.personel_notu ? satir('fa-comment-o', 'Salon Notu', r.personel_notu) : ''}
        ${satir('fa-clock-o', 'Oluşturulma', r.olusturulma ? tarihGoster(r.olusturulma) : '-')}
        ${iptalEdilebilir(r) ? `<button type="button" class="hb-dugme hb-dugme--tehlike hb-dugme--tam" data-iptal="${r.id}"><i class="fa fa-times"></i> Randevuyu İptal Et</button>` : ''}`;
}

function randevularSayfasi() {
    const filtre = $('randevuFiltre');
    let liste = [];
    let sayfaNo = 1;
    const filtreli = () => ['tarih', 'durum', 'kaynak'].some((k) => filtre.elements[k].value !== '');
    const ciz = sayfalama($('randevuSayfa'), (s) => yukle(s));

    function yukle(s = 1) {
        const [tarih1, tarih2] = tarihAraligi(filtre.elements.tarih.value);
        const kaynak = filtre.elements.kaynak.value;
        return calistir(async () => {
            const veri = await api('/musteri-uygulama/randevular', { sorgu: {
                salon_id: SALON, page: s, per_page: 10, tarih1, tarih2, durum: filtre.elements.durum.value,
                ...(kaynak ? { 'kaynaklar[]': kaynak } : {}),
            } });
            liste = veri.data;
            sayfaNo = veri.current_page;
            $('randevuListe').innerHTML = liste.map(randevuKarti).join('');
            ciz(veri.current_page, veri.last_page);
            $('randevuBos').hidden = veri.total > 0;
            $('randevuBosMetin').textContent = filtreli()
                ? 'Seçili filtrelere uyan randevu bulunamadı. Filtreleri sıfırlamayı dene.'
                : 'İlk randevunu oluşturmak için yukarıdaki butonu kullanabilirsin.';
            $('filtreSifirla').hidden = !filtreli();
        });
    }

    async function iptalEt(id) {
        const onay = await onayIste({
            baslik: 'Randevuyu İptal Et', metin: 'Bu randevuyu iptal etmek istediğinize emin misiniz? Bu işlem geri alınamaz.',
            dugme: 'İptal Et', tehlikeli: true,
        });
        if (!onay) return;
        $('randevuPencere').close();
        calistir(async () => {
            await api(`/musteri-uygulama/randevular/${id}/iptal`, { yontem: 'POST' });
            await yukle(sayfaNo);
            bildir('Randevunuz iptal edildi.', true);
        });
    }

    filtre.addEventListener('change', () => yukle(1));
    filtre.addEventListener('submit', (e) => e.preventDefault());
    $('filtreSifirla').addEventListener('click', () => { filtre.reset(); yukle(1); });
    const ac = (e) => {
        const iptal = e.target.closest('[data-iptal]');
        if (iptal) { iptalEt(iptal.dataset.iptal); return; }
        const kart = e.target.closest('[data-detay]');
        if (kart) pencereAc($('randevuPencere'), randevuDetay(liste[Number(kart.dataset.detay)]));
    };
    $('randevuListe').addEventListener('click', ac);
    $('randevuListe').addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.matches('[data-detay]')) ac(e); });
    $('randevuPencere').addEventListener('click', (e) => {
        const iptal = e.target.closest('[data-iptal]');
        if (iptal) iptalEt(iptal.dataset.iptal);
    });
    yukle(1);
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
        musteriYayinla(ben); // yeni panel sayfalari (hesap-*.js) musteriHazir ile alir; /ben ikinci kez istenmez
        ({ profil: () => profilSayfasi(ben), randevular: randevularSayfasi, sifre: sifreSayfasi })[sayfa]?.();
    });
}
