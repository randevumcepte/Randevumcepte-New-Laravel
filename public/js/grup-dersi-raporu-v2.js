/**
 * Grup Dersi Raporu (v2) — eski isletmeadmin/grup_dersi_raporu.blade karşılığı: tarih aralığı,
 * doluluk/katılım/no-show kartları, eğitmen ve ders bazlı tablolar. Hesap sunucuda (Domain\Ders\DersRaporu).
 */
import { qp, api, el, esc, tarihStr, mesaj } from '/js/lib/v2-ortak.js?v=2';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=2';
import { ozetKart } from '/js/lib/v2-bilesen.js?v=2';
import { alan, tarihBagla } from '/js/lib/v2-ongorusme.js?v=1';
import '/js/lib/v2-ders.js?v=2'; // ortak stil

const kok = document.getElementById('rc-grup-raporu');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
const bugun = new Date();
const tablolar = {};

const dolulukHucre = (d) => `<td class="rc-nowrap"><span class="gr-cubuk"><span style="width:${Math.min(100, Number(d) || 0)}%"></span></span>%${esc(d)}</td>`;
const tablo = (id, ilk, ilkAl, ekKolon) => (tablolar[id] ||= veriTablosu(el('#' + id), {
  anahtar: 'grup-raporu-' + id, birim: 'satır', bosMesaj: 'Bu aralıkta ders yok.',
  araMetni: ilkAl,
  kolonlar: [
    { baslik: ilk, sirala: 'ad', deger: ilkAl }, { baslik: 'Ders', sirala: 'oturum', deger: (x) => x.oturum },
    { baslik: 'Katılım', sirala: 'katilim', deger: (x) => x.katilim }, { baslik: 'Gelen', sirala: 'gelen', deger: (x) => x.gelen },
    ...(ekKolon ? [{ baslik: 'Gelmedi', sirala: 'gelmedi', deger: (x) => x.gelmedi }] : []),
    { baslik: 'Doluluk', sirala: 'doluluk', deger: (x) => x.doluluk },
  ],
  satir: (x) => `<tr><td>${esc(ilkAl(x))}</td><td>${x.oturum}</td><td>${x.katilim}</td><td>${x.gelen}</td>${ekKolon ? `<td>${x.gelmedi}</td>` : ''}${dolulukHucre(x.doluluk)}</tr>`,
}));

async function yukle() {
  const r = await api('GET', '/ders/rapor', { params: { salon_id: salonId, tarih1: el('#grT1').value, tarih2: el('#grT2').value } });
  if (!r.ok || !r.govde?.basarili) { mesaj(r.govde?.mesaj || `Rapor alınamadı (${r.kod})`, 'hata'); return; }
  const { ozet: o, egitmen, ders } = r.govde.veri;
  el('#grOzet').innerHTML = [
    ozetKart('bi-pie-chart-fill', 'Ortalama Doluluk', `%${o.doluluk}`, 'mor'),
    ozetKart('bi-calendar-check', 'Toplam Ders', o.oturum, 'mavi'),
    ozetKart('bi-people', 'Toplam Katılım (kayıt)', o.katilim, 'turkuaz'),
    ozetKart('bi-check-circle', 'Gelen', o.gelen, 'yesil'),
    ozetKart('bi-x-circle', `Gelmedi (no-show %${o.noshow})`, o.gelmedi, 'turuncu'),
    ozetKart('bi-hourglass-split', 'Bekleme Listesi', o.bekleme, 'mor'),
  ].join('');
  tablo('grEgitmen', 'Eğitmen', (x) => x.personel, true).kayitlarAyarla(egitmen);
  tablo('grDers', 'Ders', (x) => x.ders_tipi, false).kayitlarAyarla(ders);
}

kok.innerHTML = `
  <div class="rc-sayfa-ust"><div class="rc-sayfa-baslik"><span class="rc-sayfa-ikon"><i class="bi bi-bar-chart-fill"></i></span>
    <div><h1>Grup Dersi Raporu</h1><div class="rc-sayfa-alt">Doluluk, katılım ve no-show metrikleri</div></div></div>
    <div class="gr-filtre">${alan('Başlangıç', `<input id="grT1" value="${tarihStr(new Date(bugun.getFullYear(), bugun.getMonth(), 1))}" autocomplete="off">`)}
      ${alan('Bitiş', `<input id="grT2" value="${tarihStr(bugun)}" autocomplete="off">`)}
      <button class="rc-btn rc-btn-yesil" id="grGetir" type="button">Getir</button></div></div>
  <div class="rc-ozet" id="grOzet"></div>
  <div class="gr-kart"><h3>Eğitmen Bazlı</h3><div id="grEgitmen"></div></div>
  <div class="gr-kart"><h3>Ders Bazlı</h3><div id="grDers"></div></div>`;
tarihBagla(el('#grT1'));
tarihBagla(el('#grT2'));
el('#grGetir').onclick = yukle;
if (salonId) yukle(); else kok.innerHTML = '<div class="do-bos">Şube seçili değil.</div>';
