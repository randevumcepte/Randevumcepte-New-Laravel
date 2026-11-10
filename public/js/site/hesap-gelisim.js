// Gelisimim (Vucut Olcumu) — mobil VucutOlcumuEkran(businessMode: false) karsiligi, salt okunur.
// Veri: GET /musteri-uygulama/olcumlerim (artan tarih). VKI ve sinifi sunucudan; grafik Chart.js (layout'tan once yuklenir).
import { api, esc, salonId, musteriHazir, yukleniyor } from './musteri-api.js';
import { $ } from './hesap-ortak.js';

const ANA_RENK = '#7C3AED';
// VKI skalasi (web/mobil ile ayni esikler): 15..40 arasinda 4 bolge.
const ZONLAR = [['Zayıf', 14, '#17A2B8'], ['Normal', 26, '#28A745'], ['Fazla', 20, '#FD7E14'], ['Obez', 40, '#DC3545']];
const vkiRenk = (v) => (v <= 0 ? '#9AA0B0' : v < 18.5 ? '#17A2B8' : v < 25 ? '#28A745' : v < 30 ? '#FD7E14' : '#DC3545');
const vkiKonum = (v) => Math.min(1, Math.max(0, (v - 15) / 25));

// Gelisim grafiginde izlenebilir metrikler; dusenIyi: azalma olumlu mu.
const METRIKLER = [
    ['kilo', 'Kilo', 'kg', 'kilo', true],
    ['vki', 'VKİ', '', 'vki', true],
    ['yag', 'Yağ', '%', 'yag_orani', true],
    ['kasKg', 'Kas', 'kg', 'kas_kg', false],
    ['kasPuan', 'Kas Puanı', '', 'kas_puani', false],
    ['icYag', 'İç Yağ.', '', 'ic_yaglanma', true],
    ['odem', 'Ödem', '', 'odem', true],
].map(([anahtar, etiket, birim, alan, dusenIyi]) => ({ anahtar, etiket, birim, alan, dusenIyi }));

const sayi = (v) => (v === null || v === undefined || v === '' ? null : Number(v));
const tarihSirala = (a, b) => String(a.olcum_tarihi).localeCompare(String(b.olcum_tarihi)) || a.id - b.id;

let artan = [];   // grafik icin eski -> yeni
let azalan = [];  // ozet ve gecmis icin yeni -> eski
let secili = 'kilo';
let grafik = null;
let dogumTarihi = null;

function yasHesapla(dt) {
    const m = String(dt ?? '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return null;
    const simdi = new Date();
    let y = simdi.getFullYear() - Number(m[1]);
    if (simdi.getMonth() + 1 < Number(m[2]) || (simdi.getMonth() + 1 === Number(m[2]) && simdi.getDate() < Number(m[3]))) y--;
    return y < 0 || y > 130 ? null : y;
}

function kisaTarih(t) {
    const p = String(t ?? '').slice(0, 10).split('-');
    return p.length === 3 ? `${p[2]}/${p[1]}` : String(t ?? '');
}

// ───────── Guncel VKI ozet karti ─────────
function ozetKart() {
    const son = azalan.find((o) => (sayi(o.vki) || 0) > 0) || azalan[0];
    const v = sayi(son?.vki) || 0;
    if (!son || v <= 0) return '';
    const renk = vkiRenk(v);
    const sonBoy = azalan.map((o) => sayi(o.boy)).find((b) => b > 0) ?? null;
    const sonYas = azalan.map((o) => sayi(o.yas)).find((y) => y > 0) ?? null;
    const yas = yasHesapla(dogumTarihi) ?? sayi(son.yas) ?? sonYas;
    const boy = sayi(son.boy) ?? sonBoy;
    const kilo = sayi(son.kilo) || 0;

    // Onceki olcume gore kilo degisimi.
    let delta = '';
    if (kilo > 0) {
        const onceki = azalan.slice(azalan.indexOf(son) + 1).find((o) => (sayi(o.kilo) || 0) > 0);
        if (onceki) {
            const d = kilo - sayi(onceki.kilo);
            delta = Math.abs(d) >= 0.05
                ? `<div class="hs-delta" style="color:${d > 0 ? '#DC3545' : '#28A745'}">${d > 0 ? '▲' : '▼'} ${Math.abs(d).toFixed(1)} kg <span>(önceki ölçüme göre)</span></div>`
                : '<div class="hs-delta hs-soluk">● Kilo sabit</div>';
        }
    }
    const cip = (ikon, metin) => `<span class="hs-pil"><i class="fa ${ikon}"></i> ${esc(metin)}</span>`;
    return `<div class="hs-vki" style="border-left-color:${renk}">
        ${son.olcum_tarihi ? `<div class="hs-soluk hs-vki-tarih"><i class="fa fa-clock-o"></i> Son ölçüm: ${esc(son.olcum_tarihi)}</div>` : ''}
        <div class="hs-vki-govde">
            <div class="hs-vki-deger">
                <small>GÜNCEL VKİ</small>
                <strong style="color:${renk}">${v.toFixed(1)}</strong>
                <span class="hs-vki-sinif" style="background:${renk}">${esc(son.vki_sinif || '')}</span>
            </div>
            <div class="hs-vki-sag">
                <div class="hs-pil-satir">
                    ${boy != null ? cip('fa-arrows-v', `Boy ${boy.toFixed(1)} cm`) : ''}
                    ${yas != null ? cip('fa-birthday-cake', `Yaş ${yas}`) : ''}
                    ${kilo > 0 ? cip('fa-balance-scale', `Kilo ${kilo.toFixed(1)} kg`) : ''}
                </div>
                <div class="hs-gosterge">
                    <span class="hs-gosterge-ok" style="left:${(vkiKonum(v) * 100).toFixed(2)}%"></span>
                    <div class="hs-gosterge-bar">${ZONLAR.map(([ad, gen, r]) => `<span style="flex:${gen};background:${r}" title="${ad}"></span>`).join('')}</div>
                </div>
                <div class="hs-gosterge-olcek"><span>15</span><span>18.5</span><span>25</span><span>30</span><span>40+</span></div>
                ${delta}
            </div>
        </div>
    </div>`;
}

// ───────── Gelisim grafigi ─────────
const mevcutMetrikler = () => METRIKLER.filter((m) => artan.filter((o) => sayi(o[m.alan]) !== null).length >= 2);

function grafikCiz() {
    const kap = $('gelisimGrafik');
    if (grafik) { grafik.destroy(); grafik = null; }
    const metrikler = mevcutMetrikler();
    if (!metrikler.length) { kap.hidden = true; return; }
    const m = metrikler.find((x) => x.anahtar === secili) || metrikler[0];
    const noktalar = artan.filter((o) => sayi(o[m.alan]) !== null);
    const degerler = noktalar.map((o) => sayi(o[m.alan]));
    const tarihler = noktalar.map((o) => kisaTarih(o.olcum_tarihi));

    const minY = Math.min(...degerler), maxY = Math.max(...degerler);
    const aralik = Math.abs(maxY - minY);
    const pay = aralik < 0.5 ? 1 : aralik * 0.18;
    const [altY, ustY] = [minY - pay, maxY + pay];
    const fark = degerler[degerler.length - 1] - degerler[0];
    const degismedi = Math.abs(fark) < (aralik < 0.5 ? 0.05 : 0.1);
    const iyi = m.dusenIyi ? fark < 0 : fark > 0;
    const farkRenk = degismedi ? '#888888' : (iyi ? '#28A745' : '#DC3545');
    const ok = degismedi ? '●' : (fark > 0 ? '▲' : '▼');
    const basamak = aralik < 5 ? 1 : 0;
    const altAralik = Math.max(1, Math.ceil(noktalar.length / 4));

    kap.hidden = false;
    $('gelisimFark').style.color = farkRenk;
    $('gelisimFark').textContent = `${ok} ${Math.abs(fark).toFixed(aralik < 0.5 ? 2 : 1)}${m.birim}`;
    $('gelisimMetrikler').innerHTML = metrikler.map((x) => `<button type="button" class="hs-cip ${x.anahtar === m.anahtar ? 'aktif' : ''}" data-metrik="${x.anahtar}">${x.etiket}</button>`).join('');

    if (!window.Chart) return;
    grafik = new window.Chart($('gelisimTuval'), {
        type: 'line',
        data: { labels: tarihler, datasets: [{
            data: degerler, borderColor: ANA_RENK, borderWidth: 3, tension: 0.25, fill: true, backgroundColor: 'rgba(124,58,237,0.10)',
            pointRadius: 3.5, pointBackgroundColor: '#fff', pointBorderColor: ANA_RENK, pointBorderWidth: 2,
        }] },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: {
                backgroundColor: '#2A2A3C', displayColors: false,
                callbacks: { title: () => '', label: (c) => [`${c.parsed.y.toFixed(basamak)}${m.birim}`, tarihler[c.dataIndex]] },
            } },
            scales: {
                y: { min: altY, max: ustY, border: { display: false }, grid: { color: '#EDEDF3' },
                    ticks: { stepSize: (ustY - altY) / 4, color: '#999999', font: { size: 10 }, callback: (d) => Number(d).toFixed(basamak) } },
                x: { grid: { display: false }, border: { display: false },
                    ticks: { color: '#999999', font: { size: 10 }, autoSkip: false, maxRotation: 0, callback: (_, i) => (i % altAralik === 0 ? tarihler[i] : '') } },
            },
        },
    });
}

// ───────── Olcum gecmisi ─────────
function olcumKarti(o) {
    const v = sayi(o.vki) || 0;
    const metrik = (ad, deger, birim) => (sayi(deger) === null ? '' : `<span>${ad}: ${sayi(deger).toFixed(1)}${birim}</span>`);
    return `<div class="hs-olcum">
        <div class="hs-olcum-ust">
            <strong><i class="fa fa-calendar"></i> ${esc(o.olcum_tarihi)}</strong>
            ${v > 0 ? `<span class="hs-rozet" style="color:${vkiRenk(v)};background:${vkiRenk(v)}1f">VKİ ${v.toFixed(1)}&nbsp;&nbsp;${esc(o.vki_sinif || '')}</span>` : ''}
        </div>
        <div class="hs-olcum-degerler">
            ${metrik('Kilo', o.kilo, ' kg')}${metrik('Yağ', o.yag_orani, '%')}${metrik('Ödem', o.odem, '')}
            ${metrik('Kas Puanı', o.kas_puani, '')}${metrik('Kas', o.kas_kg, ' kg')}${metrik('İç Yağ.', o.ic_yaglanma, '')}
        </div>
        ${o.not ? `<div class="hs-soluk hs-olcum-not"><i class="fa fa-pencil-square-o"></i> ${esc(o.not)}</div>` : ''}
    </div>`;
}

async function yukle() {
    $('gelisimHata').hidden = true;
    yukleniyor(true);
    try {
        const liste = await api('/musteri-uygulama/olcumlerim', { sorgu: { salon_id: salonId() } });
        artan = [...(liste || [])].sort(tarihSirala);
        azalan = [...artan].reverse();
        $('gelisimBos').hidden = azalan.length > 0;
        $('gelisimIcerik').hidden = azalan.length === 0;
        $('gelisimOzet').innerHTML = ozetKart();
        $('gelisimGecmis').innerHTML = azalan.map(olcumKarti).join('');
        grafikCiz();
    } catch (e) {
        $('gelisimBos').hidden = true;
        $('gelisimIcerik').hidden = true;
        $('gelisimHataMetin').textContent = e.message;
        $('gelisimHata').hidden = false;
    } finally {
        yukleniyor(false);
    }
}

musteriHazir((ben) => {
    dogumTarihi = ben.dogum_tarihi || null;
    $('gelisimMetrikler').addEventListener('click', (e) => {
        const b = e.target.closest('[data-metrik]');
        if (b && b.dataset.metrik !== secili) { secili = b.dataset.metrik; grafikCiz(); }
    });
    $('gelisimYenile').addEventListener('click', yukle);
    $('gelisimTekrar').addEventListener('click', yukle);
    yukle();
});
