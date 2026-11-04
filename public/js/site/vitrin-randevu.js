// Randevu sihirbazi — adimlar ve DOM eski custom.js akisiyla ayni, veri /api/v2.
// Saat listesi ve kayit sunucuda ayni kuraldan cikar (OnlineMusaitlikServisi).
import { api, ApiHatasi, salonId, tokenYaz, girisliMi, oturumCiz, yukleniyor, kaydir, esc } from './musteri-api.js';

const $ = (id) => document.getElementById(id);
const SALON = salonId();
const durum = { secenekler: null, hizmetler: [], kalemler: [], tarih: null, saat: null, acilis: Date.now(), musteri: null };

// iCheck yerel 'change' tetiklemez; sihirbaz yerel olayla calisir.
window.jQuery(document).on('ifChecked', 'input', (e) => e.target.dispatchEvent(new Event('change', { bubbles: true })));
window.jQuery('.input-radio').iCheck();
window.jQuery('.background-image').each(function () {
    const img = this.querySelector('img');
    if (img) this.style.backgroundImage = `url(${img.src})`;
});
yukleniyor(false);

const ADIMLAR = [['hizmetsecbaslik', 'hizmetsecbaslikmobil'], ['personelsecbaslik', 'personelsecbaslikmobil'], ['tarihsaatsecbaslik', 'tarihsaatsecbaslikmobil'], ['onaybaslik', 'onaybaslikmobil']];
const BOLUMLER = ['hizmetsecimbolumu', 'personelsecimbolumu', 'tarihsaatsecimbolumu', 'onaybolumu'];

function adimaGit(n) {
    BOLUMLER.forEach((id, i) => { $(id).style.display = i === n ? 'block' : 'none'; });
    ADIMLAR.forEach((idler, i) => idler.forEach((id) => $(id)?.classList.toggle('active', i === n)));
    $(BOLUMLER[n]).setAttribute('tabindex', '-1');
    kaydir($(BOLUMLER[n]));
}

async function bekle(is) {
    yukleniyor(true);
    try { return await is(); } catch (e) {
        window.alert(e instanceof ApiHatasi ? e.message : 'Bir hata oluştu. Lütfen tekrar deneyiniz.');
        return null;
    } finally { yukleniyor(false); }
}

// ── 1 → 2: hizmet → personel ──
$('personelsecimadiminagec').addEventListener('click', async (e) => {
    e.preventDefault();
    durum.hizmetler = [...document.querySelectorAll('input[name="randevuhizmet[]"]:checked')].map((c) => ({ id: Number(c.value), ad: c.dataset.ad }));
    if (!durum.hizmetler.length) { window.alert('Devam etmek için en az bir tane hizmet seçiniz!'); return; }
    const liste = await bekle(() => Promise.all(durum.hizmetler.map((h) => api(`/vitrin/${SALON}/personeller`, { sorgu: { hizmet_id: h.id } }))));
    if (!liste) return;
    $('secilenhizmetlistebos').style.display = 'none';
    $('secilenhizmetliste').style.display = 'block';
    $('secilenhizmetliste').innerHTML = '<ul>' + durum.hizmetler.map((h) => `<li>${esc(h.ad)}</li>`).join('') + '</ul>';
    $('personelsecimbolumu').innerHTML = personelBolumu(liste);
    adimaGit(1);
});

function personelBolumu(liste) {
    const satirlar = durum.hizmetler.map((h, i) => `<p>${esc(h.ad)} için personel seçiniz</p><div class="form-group">
        <select name="personeller[]" data-hizmet="${h.id}" style="border-radius:60px">
        ${liste[i].map((p) => `<option value="${p.id}">${esc(p.personel_adi)}</option>`).join('')}
        </select></div>`).join('');
    return `<button id="hizmetseckisminageridon" type="button" style="width:200px;border-radius:60px" class="btn btn-primary">&lt;&lt; GERİ DÖN</button>
        <p style="font-size:20px; font-weight:bold">Personel Seçimi</p>
        <form id="personellisteparametreler">${satirlar}
        <button id="tarihsaatsecimadiminagec" type="submit" class="btn btn-primary width-100 btn-rounded" style="width:100%; margin-top: 10px; margin-bottom: 10px">DEVAM ET <i class="fa fa-chevron-right"></i><i class="fa fa-chevron-right"></i></button></form>`;
}

// ── 2 → 3: personel → tarih/saat ──
$('randevusistemi').addEventListener('submit', async (e) => {
    if (e.target.id !== 'personellisteparametreler') return;
    e.preventDefault();
    const secimler = [...e.target.querySelectorAll('select[name="personeller[]"]')];
    durum.kalemler = secimler.map((s) => ({ hizmet_id: Number(s.dataset.hizmet), personel_id: Number(s.value) }));
    $('personellistebos').style.display = 'none';
    $('personelliste').innerHTML = '<ul>' + secimler.map((s) => `<li>${esc(s.options[s.selectedIndex]?.text)}</li>`).join('') + '</ul>';
    durum.tarih = document.querySelector('input[name="randevutarihi"]:checked').value;
    if (await bekle(saatleriYukle) !== null) adimaGit(2);
});

async function saatleriYukle() {
    const sorgu = { tarih: durum.tarih };
    durum.kalemler.forEach((k, i) => { sorgu[`kalemler[${i}][hizmet_id]`] = k.hizmet_id; sorgu[`kalemler[${i}][personel_id]`] = k.personel_id; });
    const veri = await api(`/vitrin/${SALON}/saatler`, { sorgu });
    durum.saat = null;
    const kutular = veri.saatler.map((s, i) => {
        const dolu = s.dolu === '1';
        return `<div class="input-radio"><input class="saatsecimleri" id="time${i}" type="radio" name="randevusaati" value="${esc(s.saat)}" ${dolu ? 'disabled' : ''}><label for="time${i}" ${dolu ? 'title="Bu saat dolu"' : ''}>${esc(s.saat)}</label></div>`;
    }).join('');
    $('saatsecimtablosu').innerHTML = `<div class="saatler">${kutular}${veri.mesaj ? `<p>${esc(veri.mesaj)}</p>` : ''}</div>`;
    return veri;
}

$('tarihtablosu').addEventListener('change', (e) => {
    if (e.target.name !== 'randevutarihi' || !e.target.checked || !durum.kalemler.length) return;
    durum.tarih = e.target.value;
    bekle(saatleriYukle);
});

// ── 3 → 4 ──
$('onayadiminagec').addEventListener('click', () => {
    const secili = document.querySelector('input[name="randevusaati"]:checked');
    if (!secili) { window.alert('Devam etmek için lütfen randevu saatinizi seçiniz'); return; }
    durum.saat = secili.value;
    $('tarihsaatbos').style.display = 'none';
    $('tarihsaat').textContent = `${durum.tarih} ${durum.saat}`;
    adimaGit(3);
    if (durum.musteri) ozetiGoster();
});

// Geri butonlari
$('randevusistemi').addEventListener('click', (e) => {
    const hedef = e.target.closest('button');
    if (!hedef) return;
    const geri = { hizmetseckisminageridon: 0, personelseckisminageridon: 1, tarihsaatseckisminageridon: 2 }[hedef.id];
    if (geri !== undefined) { e.preventDefault(); adimaGit(geri); }
});

// ── 4: iletisim → ozet ──
const form = $('rdv-direkt-form');

function hataGoster(mesaj) {
    $('rdv-direkt-hata').innerHTML = `<div class="rdv-direkt-hata-box"><i class="fa fa-exclamation-circle"></i> ${esc(mesaj)}</div>`;
    $('rdv-direkt-hata').style.display = 'block';
}
const hataTemizle = () => { $('rdv-direkt-hata').innerHTML = ''; $('rdv-direkt-hata').style.display = 'none'; };

function sifreBilgi(mesaj, tur = '') {
    $('rdv-sifre-grup').style.display = 'block';
    const bilgi = $('rdv-sifre-bilgi');
    if (mesaj) { bilgi.textContent = mesaj; bilgi.style.color = tur === 'ok' ? '#0a7c3a' : tur === 'err' ? '#b91c1c' : ''; }
    $('rdv_sifre').focus();
}

/** Deger ortak telefon bileseninden; bicim kurali sunucuda (TelefonNumarasi). */
const telefonOku = () => $('rdv_telefon').value.trim() || null;

async function butonluIs(buton, yaziMetni, is) {
    const eski = buton.innerHTML;
    buton.disabled = true;
    buton.innerHTML = `<i class="fa fa-spinner fa-spin"></i> ${esc(yaziMetni)}`;
    try { return await is(); } finally { buton.disabled = false; buton.innerHTML = eski; }
}

form.addEventListener('submit', (e) => {
    e.preventDefault();
    hataTemizle();
    const telefon = telefonOku();
    if (!telefon) { hataGoster('Geçerli bir telefon numarası girin.'); return; }
    if (!$('rdv_kvkk').checked) { hataGoster('Devam etmek için kullanım ve gizlilik koşullarını onaylayın.'); return; }

    butonluIs($('rdv-direkt-submit'), 'İşleniyor...', async () => {
        try {
            const sifre = $('rdv_sifre').value;
            const sonuc = sifre
                ? await api('/musteri-oturum/giris', { yontem: 'POST', govde: { cep_telefon: telefon, password: sifre, salon_id: SALON } })
                : await api('/musteri-oturum/kayit', { yontem: 'POST', govde: {
                    ad_soyad: $('rdv_adsoyad').value, cep_telefon: telefon, kvkk: true, salon_id: SALON,
                    _hp: $('_hp').value, form_sure_sn: Math.floor((Date.now() - durum.acilis) / 1000),
                } });
            if (sonuc.sifre_gerekli) { sifreBilgi('Bu numara sistemde kayıtlı. Mevcut şifrenizi girin ya da "Şifremi unuttum" ile SMS kodu isteyin.'); return; }
            tokenYaz(sonuc.token);
            durum.musteri = sonuc.musteri;
            ozetiGoster();
        } catch (hata) {
            if (hata.kod === 401) sifreBilgi(hata.message, 'err');
            else hataGoster(hata.message);
        }
    });
});

$('rdv-sifremi-unuttum').addEventListener('click', () => {
    const telefon = telefonOku();
    if (!telefon) { sifreBilgi('Önce telefon numaranızı doğru girin.', 'err'); return; }
    butonluIs($('rdv-sifremi-unuttum'), 'Gönderiliyor...', async () => {
        try {
            await api('/musteri-oturum/sifre-kodu', { yontem: 'POST', govde: { cep_telefon: telefon, salon_id: SALON } });
            $('rdv-sifre-yenile-grup').style.display = 'block';
            sifreBilgi('Numaranız kayıtlıysa SMS ile kod gönderildi. Kodu ve yeni şifrenizi aşağıya girin.', 'ok');
            $('rdv_sifre_kod').focus();
        } catch (hata) { sifreBilgi(hata.message, 'err'); }
    });
});

$('rdv-sifre-yenile').addEventListener('click', () => {
    const telefon = telefonOku();
    const yeni = $('rdv_yeni_sifre').value;
    butonluIs($('rdv-sifre-yenile'), 'Kaydediliyor...', async () => {
        try {
            await api('/musteri-oturum/sifre-sifirla', { yontem: 'POST', govde: { cep_telefon: telefon, salon_id: SALON, kod: $('rdv_sifre_kod').value, yeni_sifre: yeni } });
            $('rdv_sifre').value = yeni;
            $('rdv-sifre-yenile-grup').style.display = 'none';
            sifreBilgi('Şifreniz güncellendi. "Randevuyu Oluştur" ile devam edebilirsiniz.', 'ok');
        } catch (hata) { sifreBilgi(hata.message, 'err'); }
    });
});

function ozetiGoster() {
    $('kisiselbilgileralani').style.display = 'none';
    const d = $('randevudokumu');
    d.setAttribute('style', 'display:block;border:3px solid var(--slp-brand); border-radius:14px; padding:20px;margin-bottom:20px;background:#fff;');
    $('secilenhizmetdokumu').textContent = durum.hizmetler.map((h) => h.ad).join(', ');
    $('secilenpersoneldokumu').textContent = [...document.querySelectorAll('select[name="personeller[]"]')].map((s) => s.options[s.selectedIndex]?.text).join(', ');
    $('randevutarihidokumu').textContent = durum.tarih.split('-').reverse().join('.');
    $('randevusaatidokumu').textContent = durum.saat;
    $('randevusistemi').classList.replace('col-lg-8', 'col-md-12');
    $('randevuozetbolumu').style.display = 'none';
    kaydir(d);
}

// ── Onay: randevu olusur ──
const idempotency = crypto.randomUUID();
$('randevuonaylabutton').addEventListener('click', () => {
    if (!$('gizlilikkosulukabul').checked) { window.alert('Devam etmek için kullanım ve gizlilik koşullarını onaylayın.'); return; }
    bekle(async () => {
        await api('/musteri-uygulama/randevular', { yontem: 'POST', govde: {
            salon_id: SALON, tarih: durum.tarih, saat: durum.saat, kalemler: durum.kalemler, kaynak: 'web',
            not: document.querySelector('textarea[name="randevunotu"]').value || null, idempotency_key: idempotency,
        } });
        const b = $('randevuonaybildirim');
        b.style.display = 'block';
        b.textContent = 'Randevu talebiniz alındı. Randevularım sayfasına yönlendiriliyorsunuz...';
        kaydir(b);
        setTimeout(() => { location.href = document.body.dataset.randevularim; }, 2000);
    });
});

// ── Oturum + yorum formu ──
oturumCiz().then((ben) => {
    if (!ben) return;
    durum.musteri = ben;
    $('rdv_adsoyad').value = ben.name || '';
    $('rdv_telefon').value = ben.cep_telefon || '';
});

$('salonyorumyap')?.addEventListener('submit', (e) => {
    e.preventDefault();
    const f = e.target;
    bekle(async () => {
        await api('/musteri-uygulama/yorumlar', { yontem: 'POST', govde: {
            salon_id: SALON, puan: Number(f.querySelector('input[name="puanlama"]:checked')?.value || 5), yorum: f.querySelector('textarea').value,
        } });
        location.reload();
    });
});

if (!girisliMi()) $('rdv_adsoyad').focus({ preventScroll: true });
