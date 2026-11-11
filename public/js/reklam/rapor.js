/**
 * Reklam Raporu (v2) — eski reklam-detay-modal + reklamYonetimi2.js karşılığı.
 * Kampanya özeti + katılımcı sekmeleri (sözlük ve sayaçlar API'den; arama-dışı kanalda arama
 * sekmeleri gizli) + Katılımcı Ekle / Çıkar / Ulaşılamayanları Tekrar Ara / Personele Ata.
 * Satır durumu (metin + renk) ve izinli işlemler sunucudan gelir.
 *
 *   reklamRaporu(kampanyaId, { onDegisti })
 */
import { el, esc, mesaj, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=8';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';
import { musteriSecici } from '/js/lib/v2-secici.js?v=7';
import { kilitle, ozetKart } from '/js/lib/v2-bilesen.js?v=7';
import { trTarih, sayi } from '/js/lib/v2-donem.js?v=7';
import { secenekler } from '/js/lib/v2-ongorusme.js?v=9';
import { baglam as b, kanalRozeti, durumRozeti } from '/js/reklam/ortak.js?v=1';

export async function reklamRaporu(id, { onDegisti = () => {} } = {}) {
  const r = await b.oku(`/kampanya/${id}`);
  if (!r.ok || !r.govde?.basarili) return mesaj(r.govde?.mesaj || 'Kampanya alınamadı', 'hata');
  const k = r.govde.veri;
  const arama = Number(k.gorev_turu) === 1;
  const sekmeler = b.fv.katilim_durumlari.filter((d) => arama || !d.yalniz_arama);
  let durum = 'tumu';
  let sayaclar = {};
  let liste = [];

  const govde = `
    <div class="rk-rapor-ust">
      <div>${kanalRozeti(k.kanal)} ${durumRozeti(k.durum)}</div>
      <div class="rk-soluk">${esc(trTarih(k.baslangic_tarihi))} – ${esc(trTarih(k.bitis_tarihi))} · ${esc(k.gonderim_saati || '')} · ${esc(k.musteri_turu || '')}</div>
    </div>
    <div class="rc-ozet rk-rapor-ozet">
      ${ozetKart('bi-people', 'Katılımcı', sayi(k.katilimci_sayisi), 'mor')}
      ${arama ? ozetKart('bi-check2-circle', 'Katılan', sayi(k.ozet.katilan), 'yesil') : ''}
      ${arama ? ozetKart('bi-telephone-x', 'Ulaşılamadı', sayi(k.ozet.ulasilamadi), 'turuncu') : ''}
      ${k.indirim_turu ? ozetKart('bi-ticket-perforated', 'Kod Kullanan', sayi(k.ozet.indirim_kullanan), 'turkuaz') : ''}
    </div>
    <div class="rk-rapor-bilgi">
      ${k.hizmet_adi ? `<span><i class="bi bi-box"></i> ${esc(k.hizmet_adi)}</span>` : ''}
      ${k.indirim_turu ? `<span><i class="bi bi-percent"></i> ${esc(k.indirim_turu)} · kod <b class="rk-kod">${esc(k.indirim_kodu)}</b></span>` : ''}
      ${arama && k.arama && !k.arama.ayar_acik ? '<span class="rk-uyari"><i class="bi bi-exclamation-triangle"></i> E-Asistan “Kampanya Araması” kapalı — aramalar başlamaz.</span>' : ''}
      <button class="rk-link" id="rMesaj"><i class="bi bi-chat-left-text"></i> Mesaj içeriği</button>
    </div>
    <div class="rk-rapor-arac">
      <div class="rc-sekmeler" id="rSekmeler" role="tablist"></div>
      <div class="rk-rapor-eylem" id="rEylem"></div>
    </div>
    <div id="rTablo"></div>`;

  const ov = modalAc('Reklam Raporu', govde, '<button class="rc-btn rc-btn-ikincil" data-kapat>Kapat</button>', { ikon: 'bar-chart', genis: true, altbaslik: k.ad });
  el('[data-kapat]', ov).onclick = modalKapat;
  el('#rMesaj', ov).onclick = () => mesajGoster();

  const tablo = veriTablosu(el('#rTablo', ov), {
    anahtar: 'reklam_rapor', birim: 'katılımcı', varsayilanBoy: 25, araIpucu: 'Ad, telefon veya kod…', bosMesaj: 'Bu sekmede katılımcı yok.',
    sirala: { alan: 'ad_soyad', yon: 'asc' },
    kolonlar: [
      { baslik: b.fv.musteri_etiket || 'Müşteri', sirala: 'ad_soyad' }, { baslik: 'Telefon' }, { baslik: 'Durum', sirala: 'durum' },
      { baslik: 'İndirim Kodu', sirala: 'kullanim' }, { baslik: '', sinif: 'rc-orta' },
    ],
    satir: (x) => `<tr>
      <td><b>${esc(x.ad_soyad)}</b></td>
      <td class="rc-nowrap">${esc(x.telefon || '—')}</td>
      <td><span class="rc-rozet rc-rozet-${esc(x.durum_renk)}">${esc(x.durum)}</span></td>
      <td>${x.indirim_kodu_kullanildi ? `<span class="rc-rozet rc-rozet-yesil"><i class="bi bi-check2"></i> Kullanıldı</span> <small class="rk-soluk">${esc(x.indirim_kodu_kullanim_tarihi || '')}</small>`
        : '<span class="rk-soluk">Kullanılmadı</span>'}</td>
      <td class="rc-orta">${x.islemler.sil ? `<button class="rc-islem rc-tehlike" data-sil="${x.id}" title="Kampanyadan çıkar"><i class="bi bi-person-dash"></i></button>` : ''}</td>
    </tr>`,
    kaynak: (p) => b.oku(`/kampanya/${id}/katilimcilar`, { ...p, durum }),
    cizildi: (l, v) => { liste = l; sayaclar = v?.sayaclar || {}; sekmeCiz(); },
  });

  function sekmeCiz() {
    el('#rSekmeler', ov).innerHTML = sekmeler.map((s) => `
      <button class="rc-sekme${s.kod === durum ? ' aktif' : ''}" data-durum="${esc(s.kod)}" role="tab">${esc(s.ad)} <span class="rc-sayac">${sayaclar[s.kod] ?? '…'}</span></button>`).join('');
    const yaz = b.yetki('ekle_duzenle');
    el('#rEylem', ov).innerHTML = !yaz ? '' : `
      <button class="rc-btn rc-btn-ikincil" data-eylem="ekle"><i class="bi bi-person-plus"></i> Katılımcı Ekle</button>
      ${b.fv.personeller.length ? '<button class="rc-btn rc-btn-ikincil" data-eylem="ata"><i class="bi bi-headset"></i> Personele Ata</button>' : ''}
      ${arama && durum === 'ulasilamadi' && sayaclar.ulasilamadi ? '<button class="rc-btn rc-btn-mavi" data-eylem="tekrar"><i class="bi bi-arrow-repeat"></i> Tekrar Ara</button>' : ''}`;
  }

  ov.addEventListener('click', async (e) => {
    const s = e.target.closest('[data-durum]');
    if (s) { durum = s.dataset.durum; sekmeCiz(); tablo.yenile(); return; }
    const sil = e.target.closest('[data-sil]');
    if (sil) {
      const x = liste.find((y) => String(y.id) === sil.dataset.sil);
      if (x && await onay(`${x.ad_soyad} kampanyadan çıkarılsın mı?`, { tehlike: true })
        && yazSonuc(await b.yaz('DELETE', `/kampanya/${id}/katilimcilar/${x.id}`), 'Katılımcı çıkarıldı')) { tablo.yenile(false); onDegisti(); }
      return;
    }
    const ey = e.target.closest('[data-eylem]');
    if (!ey) return;
    if (ey.dataset.eylem === 'tekrar') {
      if (!(await onay(`${sayaclar.ulasilamadi} ulaşılamayan kişi yeniden aranmak üzere sıraya alınsın mı?`))) return;
      kilitle(ey, 'Sıraya alınıyor…', async () => {
        const c = await b.yaz('POST', `/kampanya/${id}/tekrar-ara`);
        if (yazSonuc(c, c.govde?.mesaj)) tablo.yenile();
      });
    }
    if (ey.dataset.eylem === 'ekle') katilimciEkle();
    if (ey.dataset.eylem === 'ata') personeleAta();
  });

  /** İç içe modal yok: alt pencere raporu kapatır, iş bitince rapor yeniden açılır. */
  const rapordanAc = (baslik, html, alt, opt, kur) => {
    const alt2 = modalAc(baslik, html, alt, { dar: true, altbaslik: k.ad, ...opt });
    el('[data-kapat]', alt2).onclick = () => reklamRaporu(id, { onDegisti });
    kur(alt2);
  };

  function katilimciEkle() {
    let secili = null;
    rapordanAc('Katılımcı Ekle', '<div id="kSecici"></div>',
      '<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-yesil" id="kEkle"><i class="bi bi-person-plus"></i> Ekle</button>',
      { ikon: 'person-plus' }, (m) => {
        musteriSecici(el('#kSecici', m), { salonId: b.salonId, yol: '/kampanya/musteri-ara', onSec: (x) => { secili = x; } });
        el('#kEkle', m).onclick = (e) => {
          if (!secili) return mesaj('Müşteri seçin.', 'hata');
          kilitle(e.currentTarget, 'Ekleniyor…', async () => {
            const c = await b.yaz('POST', `/kampanya/${id}/katilimcilar`, { user_id: secili.id });
            if (yazSonuc(c, c.govde?.mesaj)) { onDegisti(); reklamRaporu(id, { onDegisti }); }
          });
        };
      });
  }

  function personeleAta() {
    const sekme = sekmeler.find((s) => s.kod === durum);
    rapordanAc('Personele Ata', `
      <p class="rk-soluk">“${esc(sekme?.ad)}” sekmesindeki <b>${sayi(sayaclar[durum] || 0)}</b> kişi Çağrı Merkezi'nde seçilen personele arama listesi olarak atanır.</p>
      <div class="rc-alan"><label>Personel *</label><select id="pPersonel">${secenekler(b.fv.personeller, '', 'Personel seçin')}</select></div>`,
    '<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-yesil" id="pAta"><i class="bi bi-headset"></i> Ata</button>',
    { ikon: 'headset' }, (m) => {
      el('#pAta', m).onclick = (e) => {
        const pid = parseInt(el('#pPersonel', m).value, 10);
        if (!pid) return mesaj('Personel seçin.', 'hata');
        kilitle(e.currentTarget, 'Atanıyor…', async () => {
          const c = await b.yaz('POST', `/kampanya/${id}/personele-ata`, { personel_id: pid, durum });
          if (yazSonuc(c, c.govde?.mesaj)) reklamRaporu(id, { onDegisti });
        });
      };
    });
  }

  function mesajGoster() {
    rapordanAc('Mesaj İçeriği', `<div class="rk-balon">${esc(k.mesaj || '—')}</div>
      <p class="rk-soluk rk-ust-bosluk">{müşteri} ve {gün} gönderimde her kişiye göre doldurulur.${arama ? ' Aramada metnin başına asistanın selamı eklenir.' : ''}</p>`,
    '<button class="rc-btn rc-btn-ikincil" data-kapat>Geri</button>', { ikon: 'chat-left-text' }, () => {});
  }

  sekmeCiz();
  tablo.yenile();
}
