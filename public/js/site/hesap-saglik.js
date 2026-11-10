// Musteri paneli > Saglik Bilgilerim: mobil musteripaneli/menu/saglikbilgileri.dart karsiligi.
// KVKK: veri yalniz token sahibinin kendi kaydidir (GET /ben?tam=1, PATCH /ben/saglik); sayfada baska kimlik kullanilmaz.
import { api, musteriHazir } from './musteri-api.js';
import { bildir, calistir } from './hesap-ortak.js';

const form = document.getElementById('saglikFormu');
// Evet/Hayir sorulari: formdaki radyo gruplari (cilt_tipi haric).
const sorular = [...new Set([...form.querySelectorAll('.hb-evet-hayir input')].map((i) => i.name))];

function doldur(m) {
    [...sorular, 'cilt_tipi'].forEach((ad) => {
        const deger = m[ad] === null || m[ad] === undefined || m[ad] === '' ? null : String(Number(m[ad]));
        form.querySelectorAll(`input[name="${ad}"]`).forEach((i) => { i.checked = deger !== null && i.value === deger; });
    });
    const not = m.ek_saglik_sorunu;
    form.elements.ek_saglik_sorunu.value = !not || String(not).toLowerCase() === 'null' ? '' : not;
}

const yukle = () => calistir(async () => {
    doldur(await api('/musteri-uygulama/ben', { sorgu: { tam: 1 } }));
    form.hidden = false;
});

form.addEventListener('submit', (e) => {
    e.preventDefault();
    const secili = (ad) => form.querySelector(`input[name="${ad}"]:checked`)?.value ?? null;
    const govde = Object.fromEntries([...sorular, 'cilt_tipi'].map((ad) => [ad, secili(ad)]));
    govde.ek_saglik_sorunu = form.elements.ek_saglik_sorunu.value;
    calistir(async () => {
        try {
            await api('/musteri-uygulama/ben/saglik', { yontem: 'PATCH', govde });
        } catch (hata) {
            throw new Error('Bilgiler kaydedilirken bir hata oluştu. Lütfen tekrar deneyin.');
        }
        doldur(await api('/musteri-uygulama/ben', { sorgu: { tam: 1 } }));
        bildir('Sağlık bilgileriniz kaydedildi.', true);
    });
});

musteriHazir(yukle);
