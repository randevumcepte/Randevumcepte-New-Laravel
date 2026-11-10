// Musteri paneli: Odullerim & Kuponlarim — mobil musteri menusundeki uc ogenin sekmeli karsiligi:
//   Carkifelek (carkifelek.dart: hak durumu + kurallar; cevirme vitrindeki carkta, ?carkac=1 ile acilir),
//   Puan Odullerim (puan_odullerim.dart: bakiye, odul seviyeleri, talep), Kuponlarim (odullerim.dart: kazanilan kuponlar).
// Puan bakiyesi, kalan hak ve kupon durumu sunucudan; talepten sonra veri yeniden yuklenir (istemcide dusulmez).
import { api, esc, salonId, musteriHazir, yukleniyor } from './musteri-api.js';
import { $, bildir, onayIste, ikiHane } from './hesap-ortak.js';

const SALON = salonId();
const kok = $('oduller');
const panel = (ad) => kok.querySelector(`[data-panel="${ad}"]`);
const SEKMELER = ['cark', 'puan', 'kuponlar'];
const TIP = {
    hizmet_indirimi: ['Hizmet İndirimi', 'hizmet'],
    urun_indirimi: ['Ürün İndirimi', 'urun'],
    hediye: ['Hediye', 'hediye'],
};

let bundle = null;            // salonun marka uygulamasi (mobil app_bundle karsiligi); yoksa gonderilmez
let aktifSalonId = SALON;     // puan odulleri: secili sube
const yuklendi = new Set();


const tarihGoster = (s) => {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
    return m ? `${m[3]}.${m[2]}.${m[1]}` : '';
};

function hataCiz(ad, mesaj) {
    panel(ad).innerHTML = `<div class="profile-empty"><i class="fa fa-exclamation-circle"></i><p>${esc(mesaj)}</p>
        <button type="button" class="apt-action apt-action--info" data-tekrar="${ad}">Tekrar Dene</button></div>`;
}

// Kupon/odulun gecerli oldugu subeler (mobil GecerliSubeRozeti): tek subeli markada cizilmez.
function subeRozeti(o) {
    if (o.gecerli_coklu !== true) return '';
    const adlar = (o.gecerli_salon_adlari || []).filter(Boolean);
    const metin = o.gecerli_tumu === true ? 'Tüm şubelerde geçerli'
        : (adlar.length ? `Geçerli şubeler: ${adlar.join(', ')}` : 'Seçili şubelerde geçerli');
    return `<div class="ho-sube"><i class="fa fa-building-o"></i> ${esc(metin)}</div>`;
}

async function yukle(ad, is) {
    yukleniyor(true);
    try {
        await is();
        yuklendi.add(ad);
    } catch (e) {
        hataCiz(ad, e.message || 'Veri alınamadı.');
    } finally {
        yukleniyor(false);
    }
}

// ───────── Carkifelek ─────────
function carkYukle() {
    return yukle('cark', async () => {
        const d = await api('/musteri-uygulama/cark', { sorgu: { salon_id: SALON } });
        const salonAdi = d.salon?.salon_adi || '';
        if (d.aktif !== true) {
            panel('cark').innerHTML = `<div class="profile-empty"><div class="ho-buyuk-emoji">🎡</div>
                <p>Bu salonda çarkıfelek şu an aktif değil.</p><p><strong>${esc(salonAdi)}</strong></p></div>`;
            return;
        }
        const mesaj = d.bugunCevirdi === true
            ? `Bugün çarkıfeleği çevirdiniz.<br>Bir sonraki çevirme: ${esc(d.yarinSaat || 'yarın')}.`
            : 'Günde 1 kez çarkı çevirebilirsiniz. Hemen deneyin!';
        panel('cark').innerHTML = `
            <div class="ho-hero ho-hero--cark">
                <h3>${esc(salonAdi || 'Çarkıfelek')}</h3>
                <p>Size özel ödüller bekliyor!</p>
                <span class="ho-hak"><i class="fa fa-life-ring"></i> Çevirme hakkınız: <b>${esc(d.kalanHak ?? 0)}</b></span>
            </div>
            <a class="ho-cevir" href="${esc(kok.dataset.carkLink)}">🎲 Çarkı Çevir</a>
            <div class="ho-bilgi">${mesaj}</div>
            <div class="ho-ikili">
                <button type="button" class="ho-btn ho-btn--cerceve" data-git="kuponlar"><i class="fa fa-ticket"></i> Kuponlarım</button>
                <button type="button" class="ho-btn ho-btn--cerceve ho-btn--turuncu" data-git="puan"><i class="fa fa-star"></i> Puan Ödüllerim</button>
            </div>
            <button type="button" class="ho-kural-link" data-kurallar><i class="fa fa-gavel"></i> Kampanya Kuralları ve Bilgilendirme</button>`;
    });
}

// ───────── Puan Odullerim ─────────
let puanVeri = null;

function odulKarti(o, bakiye) {
    const esik = Number(o.puan_esigi) || 0;
    const talepEdilebilir = bakiye >= esik;
    const yuzde = Math.min(100, Math.max(0, (bakiye / (esik <= 0 ? 1 : esik)) * 100));
    const eksik = Math.max(0, esik - bakiye);
    const [tipEtiket, tipSinif] = TIP[o.tip] || ['Ödül', 'diger'];
    const deger = o.deger !== null && o.deger !== undefined && (o.tip === 'hizmet_indirimi' || o.tip === 'urun_indirimi')
        ? `<b>%${Math.trunc(Number(o.deger) || 0)}</b>` : '';
    return `<div class="ho-kart ho-odul${talepEdilebilir ? ' is-hazir' : ''}">
        <div class="ho-kart-ust">
            <div>
                <strong class="ho-kart-baslik ho-koyu">${esc(o.baslik)}</strong>
                <div class="ho-satir ho-satir--soluk">${esc(o.aciklama || '—')}</div>
                <div class="ho-tip-satir"><span class="ho-tip ho-tip--${tipSinif}">${tipEtiket}</span> ${deger}</div>
            </div>
            <div class="ho-esik"><b>${esik}</b><small>PUAN</small></div>
        </div>
        <div class="ho-ilerleme"><span style="width:${yuzde}%"></span></div>
        <div class="ho-ilerleme-yazi"><span>${Math.trunc(bakiye)} / ${esik}</span><span>${Math.trunc(yuzde)}%</span></div>
        ${talepEdilebilir
            ? `<button type="button" class="ho-btn ho-btn--yesil ho-tam" data-talep="${esc(o.id)}"><i class="fa fa-gift"></i> Ödülü Talep Et</button>`
            : `<div class="ho-kilit">🔒 ${Math.trunc(eksik)} puan daha gerekiyor</div>`}
    </div>`;
}

function puanYukle(salonSecimi = null) {
    return yukle('puan', async () => {
        const d = await api('/musteri-uygulama/cark/puan-odulleri', { sorgu: { app_bundle: bundle, salon_id: salonSecimi ?? aktifSalonId } });
        puanVeri = d;
        if (d.bos === true) {
            panel('puan').innerHTML = `<div class="profile-empty"><div class="ho-buyuk-emoji">🎖️</div>
                <p>Henüz puanınız bulunmuyor.<br>Çarkıfelekten "Puan" kazanarak biriktirebilir,<br>biriktiren puanlarla özel ödüller alabilirsiniz.</p></div>`;
            return;
        }
        aktifSalonId = Number(d.salon?.id ?? aktifSalonId);
        const bakiye = Number(d.puanBakiyesi) || 0;
        const kayitlar = d.puanKayitlari || [];
        const seviyeler = d.odulSeviyeleri || [];
        const secici = kayitlar.length > 1
            ? `<div class="ho-cipler">${kayitlar.map((k) => {
                const aktif = Number(k.salon_id) === aktifSalonId;
                return `<button type="button" class="ho-cip${aktif ? ' is-aktif' : ''}" data-puan-salon="${esc(k.salon_id)}" ${aktif ? 'disabled' : ''}>${esc(k.salon_adi)} (${Math.trunc(Number(k.puan) || 0)})</button>`;
            }).join('')}</div>` : '';
        panel('puan').innerHTML = `
            <div class="ho-hero ho-hero--puan">
                <h3>⭐ Puan Ödüllerim</h3>
                <p>${esc(d.salon?.salon_adi || '')}</p>
                <div class="ho-bakiye"><b>${Math.trunc(bakiye)}</b><span>Toplam Puan</span></div>
            </div>
            ${secici}
            ${seviyeler.length ? seviyeler.map((o) => odulKarti(o, bakiye)).join('')
                : `<div class="profile-empty"><div class="ho-buyuk-emoji">🎖️</div>
                    <p>Bu salonda henüz tanımlı puan ödülü yok.<br>Salon yakında ödülleri tanıtacak.</p></div>`}`;
    });
}

async function talepEt(odulId) {
    const o = (puanVeri?.odulSeviyeleri || []).find((x) => String(x.id) === String(odulId));
    if (!o) return;
    const onay = await onayIste({ baslik: 'Ödülü Talep Et', dugme: 'Talep Et',
        metin: `Bu ödülü talep etmek istediğinize emin misiniz? ${o.puan_esigi} puan düşecek ve kupon kodunuz oluşacak.` });
    if (!onay) return;
    bildir('');
    yukleniyor(true);
    let sonuc;
    try {
        sonuc = await api(`/musteri-uygulama/cark/puan-odulleri/${o.id}/talep`, { yontem: 'POST', govde: { salon_id: aktifSalonId, app_bundle: bundle } });
    } catch (e) {
        bildir(e.message || 'İşlem başarısız');
        return;
    } finally {
        yukleniyor(false);
    }
    const p = $('talepPencere');
    p.querySelector('[data-talep-baslik]').textContent = sonuc.baslik || '';
    p.querySelector('[data-talep-kod]').textContent = sonuc.kod || '';
    p.querySelector('[data-talep-sube]').innerHTML = subeRozeti(sonuc);
    p.showModal();
    yuklendi.delete('kuponlar');
    await puanYukle();
}

// ───────── Kuponlarim ─────────
function kuponKarti(o) {
    const kullanildi = Number(o.kullanildi) === 1;
    const gecerlilik = o.gecerlilik_tarihi || '';
    const gecmis = !kullanildi && gecerlilik !== '' && gecerlilik.slice(0, 10) < bugunYmd();
    const [etiket, sinif] = kullanildi ? ['Kullanıldı', 'pasif'] : (gecmis ? ['Süresi Doldu', 'pasif'] : ['Geçerli', 'gecerli']);
    let alt = 'Süresiz';
    if (kullanildi && tarihGoster(o.kullanim_tarihi)) alt = `${tarihGoster(o.kullanim_tarihi)} tarihinde kullanıldı`;
    else if (tarihGoster(gecerlilik)) alt = gecmis ? `Süresi doldu (${tarihGoster(gecerlilik)})` : `Son kullanım: ${tarihGoster(gecerlilik)}`;
    return `<div class="ho-kart ho-kupon${kullanildi || gecmis ? ' is-pasif' : ''}">
        <div class="ho-kart-ust">
            <strong class="ho-kart-baslik">${esc(o.baslik || 'Ödül')}</strong>
            <span class="ho-rozet ho-rozet--${sinif}">${etiket}</span>
        </div>
        <div class="ho-satir ho-satir--soluk">${esc(o.salon_adi || 'Salon')}</div>
        ${subeRozeti(o)}
        <div class="ho-kod">${esc(o.kod)}</div>
        <div class="ho-kupon-alt">${esc(alt)}</div>
    </div>`;
}

function bugunYmd() {
    const n = new Date();
    return `${n.getFullYear()}-${ikiHane(n.getMonth() + 1)}-${ikiHane(n.getDate())}`;
}

function kuponlarYukle() {
    return yukle('kuponlar', async () => {
        const liste = await api('/musteri-uygulama/cark/odullerim', { sorgu: { app_bundle: bundle } }) || [];
        panel('kuponlar').innerHTML = `
            <div class="ho-hero ho-hero--kupon">
                <h3>🎁 Kazandığım Ödüller</h3>
                <p>Çarkıfelekten ve puan ödüllerinden kazandığınız kuponlar burada listelenir. Salonda randevu aldığınızda kupon kodunuzu personele söyleyin.</p>
            </div>
            ${liste.length ? liste.map(kuponKarti).join('')
                : `<div class="profile-empty"><div class="ho-buyuk-emoji">🎡</div>
                    <p>Henüz kazanılmış bir ödülünüz yok.<br>Onaylanmış randevularınız üzerinden çarkı çevirebilirsiniz.</p></div>`}`;
    });
}

// ───────── Sekmeler ─────────
const YUKLEYICI = { cark: carkYukle, puan: () => puanYukle(), kuponlar: kuponlarYukle };

function sekmeAc(ad, gecmiseYaz = true) {
    if (!SEKMELER.includes(ad)) ad = SEKMELER[0];
    kok.querySelectorAll('[data-sekme]').forEach((b) => {
        const acik = b.dataset.sekme === ad;
        b.classList.toggle('is-aktif', acik);
        b.setAttribute('aria-selected', String(acik));
    });
    SEKMELER.forEach((s) => { panel(s).hidden = s !== ad; });
    if (gecmiseYaz) history.replaceState(null, '', '#' + ad);
    bildir('');
    if (!yuklendi.has(ad)) YUKLEYICI[ad]();
}

kok.addEventListener('click', (e) => {
    const h = e.target;
    const sekme = h.closest('[data-sekme]');
    if (sekme) { sekmeAc(sekme.dataset.sekme); return; }
    const git = h.closest('[data-git]');
    if (git) { sekmeAc(git.dataset.git); return; }
    if (h.closest('[data-kurallar]')) { $('kurallarPencere').showModal(); return; }
    const tekrar = h.closest('[data-tekrar]');
    if (tekrar) { YUKLEYICI[tekrar.dataset.tekrar](); return; }
    const salon = h.closest('[data-puan-salon]');
    if (salon) { puanYukle(Number(salon.dataset.puanSalon)); return; }
    const talep = h.closest('[data-talep]');
    if (talep) talepEt(talep.dataset.talep);
});

document.querySelectorAll('.ho-pencere').forEach((p) => {
    p.addEventListener('click', (e) => {
        if (e.target === p || e.target.closest('[data-pencere-kapat]')) p.close();
    });
});
document.querySelector('[data-talep-kuponlar]').addEventListener('click', () => {
    $('talepPencere').close();
    sekmeAc('kuponlar');
});

musteriHazir((ben) => {
    // Mobil istekleri marka uygulamasinin paket adiyla (app_bundle) suzer; sitede karsiligi bu salonun markasi.
    const kayit = (ben.salonlar || []).find((s) => Number(s.salon_id) === SALON);
    bundle = kayit?.salonlar?.app_bundle || null;
    sekmeAc(location.hash.slice(1), false);
});
