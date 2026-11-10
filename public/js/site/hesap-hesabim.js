// Musteri paneli > Hesabim: mobil musteri ana sayfasinin (musteripaneli/anasayfa/anasayfa.dart + salon_yorumlari.dart)
// web karsiligi. Tum veri /api/v2: musteri-uygulama (ozet, bildirim sayaci, reklamlar, kampanyalar, yorum) ve vitrin
// (salon bilgisi, salon yorumlari). Kisayol linkleri sunucuda cizilir (blade, $linkler).
import { api, esc, salonId, yukleniyor, musteriHazir } from './musteri-api.js';
import { toast, pencere, AYLAR_KISA } from './hesap-ortak.js';

const $ = (id) => document.getElementById(id);
const SALON = salonId();
const kok = $('hesabim');
const WP_MESAJ = 'Merhaba, bilgi / randevu almak istiyorum.';

// Uyelik kategorisi: ikon, renk sinifi, alt yazi (mobil _membershipCard).
const KATEGORI = {
    sadik: ['fa-trophy', 'hh-ikon--mor', (n) => `${n}+ alışveriş`],
    aktif: ['fa-check-circle', 'hh-ikon--yesil', (n) => `${n} alışveriş`],
    pasif: ['fa-user-o', 'hh-ikon--gri', () => 'Henüz alışveriş yok'],
};

function selamOn() {
    const s = new Date().getHours();
    if (s < 6) return 'İyi geceler';
    if (s < 12) return 'Günaydın';
    if (s < 18) return 'İyi günler';
    return 'İyi akşamlar';
}

/** 1000 ve ustu "1.2K" / "12K" (mobil _formatPuan). */
const puanBicim = (v) => (v >= 1000 ? `${(v / 1000).toFixed(v >= 10000 ? 0 : 1)}K` : String(v));
const ondalik = (n) => Number(n || 0).toFixed(1).replace('.', ',');

function yildizlar(puan) {
    const tam = Math.floor(puan);
    const yarim = puan - tam >= 0.5;
    return [1, 2, 3, 4, 5].map((i) => {
        const sinif = i <= tam ? 'fa-star' : (i === tam + 1 && yarim ? 'fa-star-half-o' : 'fa-star-o');
        return `<i class="fa ${sinif}"></i>`;
    }).join('');
}

function trTarih(t) {
    const m = String(t || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    return m ? `${Number(m[3])} ${AYLAR_KISA[Number(m[2]) - 1]} ${m[1]}` : '';
}

/** wa.me numarasi: yalniz rakam, 00 / 0 onekleri 90'a cevrilir (mobil _normalizeForWhatsApp). */
function whatsappNumarasi(ham) {
    let d = String(ham || '').replace(/\D/g, '');
    if (!d) return '';
    if (d.startsWith('00')) d = d.slice(2);
    if (d.startsWith('0')) d = '90' + d.slice(1);
    if (!d.startsWith('90') && d.length === 10) d = '90' + d;
    return d;
}

// ───────── Salon bilgisi: randevu kisayollari + iletisim ─────────
function salonBilgisi(salon) {
    const online = Boolean(salon?.musteri_online_randevu_aktif);
    $('hsRandevu').hidden = !online;
    // Grup dersi rezervasyonu blade'de yalniz studyo + grup dersi acik salonda cizilir; online randevu da acik olmali.
    if ($('hsGrupRezervasyon')) $('hsGrupRezervasyon').hidden = !(online && salon?.grup_dersi_aktif);

    const tel = String(salon?.telefon_1 || '').trim();
    const wp = whatsappNumarasi(tel);
    if (tel) $('hsAra').href = `tel:${tel}`;
    if (wp) $('hsWhatsapp').href = `https://wa.me/${wp}?text=${encodeURIComponent(WP_MESAJ)}`;
}

function iletisimBagla() {
    const yoksa = (el, mesaj) => el.addEventListener('click', (e) => {
        if (el.getAttribute('href') === '#') { e.preventDefault(); toast(mesaj); }
    });
    yoksa($('hsAra'), 'Salonun arama numarası tanımlı değil.');
    yoksa($('hsWhatsapp'), 'Salonun WhatsApp numarası tanımlı değil.');
}

// ───────── Ozet: kategori + sadakat puani ─────────
function ozetCiz(o) {
    const kategori = KATEGORI[o?.kategori] ? o.kategori : 'pasif';
    const [ikon, renk, alt] = KATEGORI[kategori];
    const n = Number(o?.tahsilat_sayisi || 0);
    $('hsKategoriIkon').className = `hh-ikon hh-ikon--daire ${renk}`;
    $('hsKategoriIkon').innerHTML = `<i class="fa ${ikon}"></i>`;
    $('hsKategori').textContent = o?.kategori_label || 'Pasif Müşteri';
    $('hsKategoriAlt').textContent = alt(n);
    $('hsPuan').textContent = puanBicim(Number(o?.sadakat_puani || 0));
}

async function sayacYukle() {
    const s = await api('/musteri-uygulama/bildirimler/sayac', { sorgu: { salon_id: SALON } }).catch(() => null);
    const n = Number(s?.okunmamis || 0);
    $('hsZilRozet').hidden = n <= 0;
    $('hsZilRozet').textContent = n;
    $('hsZil').classList.toggle('hh-daire--yeni', n > 0);
    $('hsZil').querySelector('i').className = n > 0 ? 'fa fa-bell' : 'fa fa-bell-o';
}

// ───────── Sana Ozel: uygulama ici reklam kartlari ─────────
let reklamlar = [];

function reklamRozeti(kupon) {
    const deger = Number(kupon?.deger);
    if (!kupon || kupon.deger === null || !Number.isFinite(deger)) return null;
    return kupon.indirim_tipi === 'tutar' ? `${deger} ₺` : `%${Math.trunc(deger)}`;
}

function reklamKarti(r) {
    const kuponVar = Boolean(r.kupon && r.kupon.deger !== null && r.kupon.deger !== undefined);
    const rozet = kuponVar ? reklamRozeti(r.kupon) : null;
    const ipucu = r.aksiyon_tipi === 'randevu'
        ? (kuponVar ? 'Tıkla: indirimli randevu 📅🎟️' : 'Tıkla, randevu al 📅')
        : (kuponVar ? 'Tıkla, kuponu kap 🎟️' : '');
    const rozetHtml = rozet ? `<span class="hh-reklam-rozet">${esc(rozet)}</span>` : '';
    const ipucuHtml = ipucu ? `<small>${esc(ipucu)}</small>` : '';
    if (r.gorsel) {
        return `<button type="button" class="hh-reklam hh-reklam--gorsel" data-reklam="${r.id}">
            <img src="${esc(r.gorsel)}" alt="" loading="lazy">
            ${rozetHtml}
            <span class="hh-reklam-alt"><strong>${esc(r.baslik)}</strong>${ipucuHtml}</span>
        </button>`;
    }
    return `<button type="button" class="hh-reklam" data-reklam="${r.id}">
        ${rozetHtml}
        <span class="hh-reklam-metin"><strong>${esc(r.baslik)}</strong>${r.mesaj ? `<em>${esc(r.mesaj)}</em>` : ''}${ipucuHtml}</span>
    </button>`;
}

async function reklamlariYukle() {
    reklamlar = await api('/musteri-uygulama/reklamlar', { sorgu: { salon_id: SALON } }).catch(() => []) || [];
    const kutu = $('hsReklamlar');
    kutu.innerHTML = reklamlar.map(reklamKarti).join('');
    kutu.querySelectorAll('img').forEach((img) => img.addEventListener('error', () => {
        img.closest('.hh-reklam').classList.add('hh-reklam--hatali');
        img.remove();
    }));
}

function kuponPenceresi(zaten) {
    $('hsKuponEmoji').textContent = zaten ? '👍' : '🎉';
    $('hsKuponBaslik').textContent = zaten ? 'Zaten Senin' : 'Tebrikler!';
    $('hsKuponMesaj').textContent = zaten ? 'Kuponun zaten cebinde 👍' : '🎉 Kuponun tanımlandı!';
    $('hsKuponNot').hidden = zaten;
    pencere($('hsKuponPencere')).ac();
}

async function reklamTikla(r) {
    const aksiyon = r.aksiyon_tipi || 'kupon';
    const kuponYolu = `/musteri-uygulama/reklamlar/${r.id}/kupon`;

    // Bos saat / randevu reklami: (varsa) kupon tanimlanir, sonra randevu bolumune gidilir.
    if (aksiyon === 'randevu') {
        let bekle = 0;
        if (r.kupon) {
            try {
                const s = await api(kuponYolu, { yontem: 'POST' });
                toast(s?.zaten ? 'Kuponun cebinde 👍 Kampanya saatinden randevunu al.' : '🎉 İndirim kuponun tanımlandı! Kampanya saatinden randevunu al.', true);
                bekle = 1500; // not okunabilsin
            } catch (e) { /* mobil ile ayni: kupon alinamazsa da randevuya gecilir */ }
        }
        setTimeout(() => { location.href = kok.dataset.randevu; }, bekle);
        return;
    }
    if (aksiyon !== 'kupon' || !r.kupon) return; // 'yok' ya da kuponsuz: yalniz gorsel

    yukleniyor(true);
    try {
        const s = await api(kuponYolu, { yontem: 'POST' });
        kuponPenceresi(Boolean(s?.zaten));
        reklamlariYukle();
    } catch (e) {
        toast(e.message || 'İşlem başarısız oldu');
    } finally {
        yukleniyor(false);
    }
}

// ───────── Kampanya ve duyurular ─────────
function kampanyaKarti(k) {
    const rozet = (metin, ikon, sinif) => `<span class="hh-etiket ${sinif}"><i class="fa ${ikon}"></i> ${esc(metin)}</span>`;
    const indirim = Math.trunc(Number(k.indirim || 0));
    const fiyat = Number(k.fiyat || 0);
    const kalan = k.kalanGun === null || k.kalanGun === undefined ? null : Number(k.kalanGun);
    return `<div class="hh-kart hh-kampanya">
        <span class="hh-ikon"><i class="fa ${k.tip === 'gap' ? 'fa-clock-o' : 'fa-tag'}"></i></span>
        <div class="hh-kampanya-metin">
            <strong>${esc(k.baslik)}</strong>
            ${k.aciklama ? `<p>${esc(k.aciklama)}</p>` : ''}
            <div class="hh-etiketler">
                ${indirim > 0 ? rozet(`%${indirim} indirim`, 'fa-tag', 'hh-etiket--yesil') : ''}
                ${fiyat > 0 ? rozet(`${fiyat.toFixed(0)} ₺`, 'fa-tags', '') : ''}
                ${kalan !== null ? rozet(kalan > 0 ? `${kalan} gün kaldı` : 'Bugün son gün', 'fa-hourglass-half', 'hh-etiket--turuncu') : ''}
            </div>
        </div>
    </div>`;
}

async function kampanyalariYukle() {
    const liste = await api('/musteri-uygulama/kampanyalar', { sorgu: { salon_id: SALON } }).catch(() => []) || [];
    $('hsKampanyalar').innerHTML = liste.length
        ? `<h4 class="hh-alt-baslik"><i class="fa fa-bullhorn"></i> Kampanya ve Duyurular</h4>${liste.map(kampanyaKarti).join('')}`
        : `<div class="hh-kart hh-satir">
            <span class="hh-ikon hh-ikon--buyuk"><i class="fa fa-bullhorn"></i></span>
            <span class="hh-satir-metin"><strong>Yeni Duyurular</strong><small>Kampanyalar, etkinlikler ve özel teklifler burada görünür.</small></span>
        </div>`;
}

// ───────── Musteri yorumlari (ozet karti + yorum bolumu) ─────────
const yorum = { liste: [], sayfa: 0, sonSayfa: 1, filtre: 0, puan: 0, bildirilecek: null };

function yorumKarti(y) {
    const ad = y.kullanici_adi || 'Müşteri';
    const tarih = trTarih(y.tarih);
    return `<div class="hh-kart hh-yorum">
        <div class="hh-yorum-ust">
            <span class="hh-harf">${esc(ad.slice(0, 1).toLocaleUpperCase('tr'))}</span>
            <span class="hh-satir-metin"><strong>${esc(ad)}</strong>${tarih ? `<small>${esc(tarih)}</small>` : ''}</span>
            ${y.puan > 0 ? `<span class="hh-yildizlar">${yildizlar(y.puan)}</span>` : ''}
        </div>
        ${String(y.yorum || '').trim() ? `<p>${esc(y.yorum)}</p>` : ''}
        ${y.kendi ? '' : `<button type="button" class="hh-bildir" data-bildir="${y.id}"><i class="fa fa-flag-o"></i> Kötüye Kullanım Bildir</button>`}
    </div>`;
}

function yorumListesiCiz() {
    const gorunen = yorum.filtre ? yorum.liste.filter((y) => y.puan === yorum.filtre) : yorum.liste;
    $('hsYorumListe').innerHTML = gorunen.map(yorumKarti).join('');
    $('hsYorumBos').hidden = gorunen.length > 0;
    $('hsYorumBos').querySelector('strong').textContent = yorum.filtre ? 'Bu filtreyle eşleşen yorum yok' : 'Henüz yorum yok';
    $('hsYorumDaha').hidden = yorum.sayfa >= yorum.sonSayfa;
}

function yorumOzetiCiz(v) {
    const ort = Number(v.ortalama || 0);
    const toplamYorum = Number(v.toplam_yorum || 0);
    const toplamPuan = Number(v.toplam_puan || 0);
    $('hsYorumOrt').textContent = ondalik(ort);
    $('hsYorumYildiz').innerHTML = yildizlar(ort);
    $('hsYorumAlt').textContent = toplamYorum > 0 ? `${toplamYorum} yorum · ${toplamPuan} puan` : 'Henüz yorum yok';

    $('hsOzetOrt').textContent = ondalik(ort);
    $('hsOzetYildiz').innerHTML = yildizlar(ort);
    $('hsOzetAlt').textContent = `${toplamPuan} puan · ${toplamYorum} yorum`;
    const dagilim = v.dagilim || {};
    const enCok = Math.max(0, ...[1, 2, 3, 4, 5].map((s) => Number(dagilim[s] || 0)));
    $('hsDagilim').innerHTML = [5, 4, 3, 2, 1].map((s) => {
        const sayi = Number(dagilim[s] || 0);
        return `<div class="hh-dagilim-satir"><span>${s}</span><i class="fa fa-star"></i>
            <span class="hh-cubuk"><span style="width:${enCok ? (sayi / enCok) * 100 : 0}%"></span></span><small>${sayi}</small></div>`;
    }).join('');
    $('hsYorumForm').hidden = !(v.kullanici_yorum_yapabilir && v.salon_id);
}

async function yorumlariYukle(bastan) {
    const v = await api(`/vitrin/${SALON}/yorumlar`, { sorgu: { page: bastan ? 1 : yorum.sayfa + 1 } });
    if (bastan) yorum.liste = [];
    yorum.liste.push(...(v.yorumlar || []));
    yorum.sayfa = v.current_page || 1;
    yorum.sonSayfa = v.last_page || 1;
    yorumOzetiCiz(v);
    yorumListesiCiz();
}

function yildizSec(puan) {
    yorum.puan = puan;
    $('hsYildizSec').querySelectorAll('[data-puan]').forEach((b) => {
        b.querySelector('i').className = `fa ${Number(b.dataset.puan) <= puan ? 'fa-star' : 'fa-star-o'}`;
    });
}

function yorumBolumunuBagla() {
    $('hsYorumKart').addEventListener('click', () => {
        const b = $('hsYorumBolum');
        b.hidden = !b.hidden;
        if (!b.hidden) b.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    $('hsYorumKapat').addEventListener('click', () => { $('hsYorumBolum').hidden = true; });
    $('hsYorumFiltre').addEventListener('click', (e) => {
        const b = e.target.closest('[data-filtre]');
        if (!b) return;
        yorum.filtre = Number(b.dataset.filtre);
        $('hsYorumFiltre').querySelectorAll('[data-filtre]').forEach((x) => x.classList.toggle('aktif', x === b));
        yorumListesiCiz();
    });
    $('hsYorumDaha').addEventListener('click', () => yorumlariYukle(false).catch((e) => toast(e.message)));
    $('hsYildizSec').addEventListener('click', (e) => {
        const b = e.target.closest('[data-puan]');
        if (b) yildizSec(Number(b.dataset.puan));
    });

    $('hsYorumForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        if (yorum.puan < 1) { toast('Lütfen puan verin.'); return; }
        const dugme = $('hsYorumGonder');
        dugme.disabled = true;
        dugme.querySelector('span').textContent = 'Gönderiliyor...';
        try {
            await api('/musteri-uygulama/yorumlar', { yontem: 'POST', govde: { salon_id: SALON, puan: yorum.puan, yorum: $('hsYorumMetin').value.trim() } });
            $('hsYorumMetin').value = '';
            yildizSec(0);
            toast('Değerlendirmeniz için teşekkürler!', true);
            await yorumlariYukle(true);
        } catch (err) {
            toast(err.message || 'Yorum gönderilemedi. Tekrar deneyin.');
        } finally {
            dugme.disabled = false;
            dugme.querySelector('span').textContent = 'Gönder';
        }
    });

    // Uygunsuz icerik bildirimi (Apple 1.2 UGC; mobil ile ayni sebepler)
    const form = $('hsBildirForm');
    const bildirPencere = pencere($('hsBildirPencere'));
    $('hsYorumListe').addEventListener('click', (e) => {
        const b = e.target.closest('[data-bildir]');
        if (!b) return;
        yorum.bildirilecek = b.dataset.bildir;
        form.reset();
        form.querySelector('[type=submit]').disabled = true;
        bildirPencere.ac();
    });
    form.addEventListener('change', () => { form.querySelector('[type=submit]').disabled = !form.elements.sebep.value; });
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        bildirPencere.kapat();
        try {
            await api(`/musteri-uygulama/yorumlar/${yorum.bildirilecek}/bildir`, { yontem: 'POST', govde: { sebep: form.elements.sebep.value } });
            toast('Bildiriminiz alındı, teşekkürler.', true);
        } catch (err) {
            toast('Bildirim gönderilemedi. Lütfen tekrar deneyin.');
        }
    });
}

// ───────── Acilis ─────────
musteriHazir(async (ben) => {
    const ad = String(ben.name || '').trim() || 'Misafir';
    $('hsSelamOn').textContent = selamOn();
    $('hsAd').textContent = `${ad.split(/\s+/)[0]} 👋`;

    iletisimBagla();
    yorumBolumunuBagla();
    $('hsReklamlar').addEventListener('click', (e) => {
        const b = e.target.closest('[data-reklam]');
        const r = b && reklamlar.find((x) => String(x.id) === b.dataset.reklam);
        if (r) reklamTikla(r);
    });

    yukleniyor(true);
    // Ozet markanin (app_bundle) kapsaminda calisir; salonun paketi vitrin bilgisinden okunur.
    const vitrin = api(`/vitrin/${SALON}`).catch(() => null);
    const ozet = vitrin.then((v) => (v?.salon?.app_bundle
        ? api('/musteri-uygulama/ozet', { sorgu: { app_bundle: v.salon.app_bundle } })
        : null)).catch(() => null);
    await Promise.allSettled([
        vitrin.then((v) => salonBilgisi(v?.salon)),
        ozet.then(ozetCiz),
        sayacYukle(),
        reklamlariYukle(),
        kampanyalariYukle(),
        yorumlariYukle(true).catch(() => yorumOzetiCiz({})),
    ]);
    yukleniyor(false);
});
