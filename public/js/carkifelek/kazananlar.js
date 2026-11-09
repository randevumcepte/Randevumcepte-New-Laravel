/**
 * Çarkıfelek › KAZANANLAR (eski /isletmeyonetim/carkkazananlar). Kupon doğrulama kutusu (müşterinin
 * getirdiği kod → detay → tek tıkla kullanıldı / geri al), özet kartları, durum sekmeli kupon
 * tablosu ve çevirme geçmişi — iki tablo da ortak veriTablosu + sunucu sayfalaması.
 *
 * Veri: GET /cark/ozet, /cark/kuponlar?durum=, /cark/cevirmeler; POST /cark/kupon/dogrula,
 * /cark/kupon/{id}/kullan | /geri-al. Durum kuralı ve sekme listesi API'de (KuponDurumu).
 */
import { el, esc, yazSonuc } from '/js/lib/v2-ortak.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=7';
import { kilitle, ozetKart } from '/js/lib/v2-bilesen.js?v=7';

const tarih = (s) => (s ? String(s).slice(0, 16).replace(/^(\d{4})-(\d{2})-(\d{2})/, '$3.$2.$1') : '—');
const DURUM_RENK = { gecerli: 'yesil', kullanildi: 'mavi', sure_doldu: 'kirmizi' };
const TIP_RENK = { puan: 'turuncu', hizmet_indirimi: 'mor', urun_indirimi: 'mavi', paket_indirimi: 'turuncu', tekrar_dene: 'gri', bos: 'gri', hediye: 'yesil' };

export async function ciz(kap, ctx) {
  const durumlar = ctx.acilis.sozluk.kupon_durumlari;
  let durum = 'tumu';
  let sayaclar = {};
  let kuponSatirlari = [];

  kap.innerHTML = `
    <section class="ck-kart ck-dogrula">
      <div class="ck-alt-bas"><span>🔍</span><div><h4>Kupon Doğrulama</h4>
        <p>Müşterinin elindeki kodu yazın, detayları görün ve tek tıkla kullanım onayı verin.</p></div></div>
      <form class="ck-dogrula-form" id="kzForm">
        <input type="text" id="kzKod" maxlength="12" placeholder="ÖRN: AB3XY7Q2" autocomplete="off">
        <button type="submit" class="rc-btn rc-btn-birincil"><i class="bi bi-search"></i> Doğrula</button>
      </form>
      <div id="kzSonuc"></div>
    </section>
    <div class="rc-ozet" id="kzOzet"></div>
    <section class="ck-kart">
      <h3 class="ck-kart-baslik">🎁 Kuponlar</h3>
      <nav class="rc-sekmeler" id="kzSekmeler"></nav>
      <div id="kzKuponlar"></div>
    </section>
    <section class="ck-kart">
      <h3 class="ck-kart-baslik">📜 Çevirme Geçmişi</h3>
      <div id="kzCevirmeler"></div>
    </section>`;
  const $ = (s) => kap.querySelector(s);

  // ── doğrulama ──
  const kod = $('#kzKod');
  kod.addEventListener('input', () => { kod.value = kod.value.toUpperCase().replace(/[^A-Z0-9]/g, ''); });
  kod.focus();
  $('#kzForm').addEventListener('submit', (e) => {
    e.preventDefault();
    if (!kod.value) return kod.focus();
    kilitle(e.submitter || el('button', e.target), 'Kontrol…', async () => {
      const r = await ctx.yaz('POST', '/cark/kupon/dogrula', { kod: kod.value });
      if (r.ok && r.govde?.basarili) return sonucCiz(r.govde.veri);
      $('#kzSonuc').innerHTML = `<div class="ck-sonuc-kart hata"><span>❌</span><div><h4>Geçersiz Kod</h4><p>${esc(r.govde?.mesaj || 'Kod bulunamadı')}</p></div></div>`;
    });
  });
  $('#kzSonuc').addEventListener('click', (e) => {
    const b = e.target.closest('[data-isle]');
    if (b) isle(b, Number(b.dataset.id), b.dataset.isle, true);
  });

  function sonucCiz(o) {
    const islem = o.durum === 'gecerli'
      ? `<button class="rc-btn rc-btn-yesil" data-isle="kullan" data-id="${o.id}"><i class="bi bi-check-lg"></i> Kullanıldı Olarak İşaretle</button>`
      : o.durum === 'kullanildi'
        ? `<button class="rc-btn rc-btn-ikincil" data-isle="geri-al" data-id="${o.id}"><i class="bi bi-arrow-counterclockwise"></i> Kullanımı Geri Al</button>`
        : '<span class="ck-soluk">Süresi dolmuş — işlem yapılamaz</span>';
    $('#kzSonuc').innerHTML = `
      <div class="ck-sonuc-kart ${esc(o.durum)}">
        <div class="ck-sonuc-bilgi">
          <span class="rc-rozet rc-rozet-${DURUM_RENK[o.durum]}">${esc(o.durum_ad)}</span>
          <h4>${esc(o.baslik)}</h4>
          <div class="ck-sonuc-detay">
            <div><span>Müşteri:</span> <b>${esc(o.musteri.ad)}</b></div>
            ${o.musteri.telefon ? `<div><span>Telefon:</span> ${esc(o.musteri.telefon)}</div>` : ''}
            <div><span>Kazanma:</span> ${tarih(o.kazanma_tarihi)}</div>
            <div><span>Son Kullanım:</span> ${o.gecerlilik_tarihi ? tarih(o.gecerlilik_tarihi) : 'Süresiz'}</div>
            ${o.kullanim_tarihi ? `<div><span>Kullanıldı:</span> <b class="ck-kirmizi">${tarih(o.kullanim_tarihi)}</b></div>` : ''}
          </div>
        </div>
        <div class="ck-sonuc-islem">${islem}</div>
      </div>`;
  }

  async function isle(btn, id, is, dogrulamadan) {
    await kilitle(btn, 'İşleniyor…', async () => {
      const r = await ctx.yaz('POST', `/cark/kupon/${id}/${is}`);
      if (!yazSonuc(r, r.govde?.mesaj)) return;
      if (dogrulamadan) sonucCiz(r.govde.veri);
      ozetYukle();
      kuponTablosu.yenile(false);
    });
  }

  // ── özet ──
  async function ozetYukle() {
    const r = await ctx.oku('/cark/ozet');
    if (!kap.isConnected || !r.ok || !r.govde?.basarili) return;
    const o = r.govde.veri;
    $('#kzOzet').innerHTML = ozetKart('bi-arrow-repeat', 'Toplam Çevirme', o.toplam_cevirme, 'mor')
      + ozetKart('bi-calendar-day', 'Bugün', o.bugun_cevirme, 'mavi')
      + ozetKart('bi-hourglass-split', 'Bekleyen Kupon', o.kullanilmamis, 'turuncu')
      + ozetKart('bi-check2-circle', 'Kullanılmış', o.kullanilmis, 'yesil');
  }

  // ── kuponlar ──
  const sekmeCiz = () => {
    $('#kzSekmeler').innerHTML = durumlar.map((s) => `
      <button type="button" class="rc-sekme${s.kod === durum ? ' aktif' : ''}" data-durum="${s.kod}">${esc(s.ad)} <span class="rc-sayac">${sayaclar[s.kod] ?? '…'}</span></button>`).join('');
  };
  $('#kzSekmeler').addEventListener('click', (e) => {
    const b = e.target.closest('[data-durum]');
    if (!b || b.dataset.durum === durum) return;
    durum = b.dataset.durum; sekmeCiz(); kuponTablosu.yenile();
  });
  const kuponTablosu = veriTablosu($('#kzKuponlar'), {
    anahtar: 'cark_kuponlar', birim: 'kupon', varsayilanBoy: 25, bosMesaj: 'Bu filtrede kupon yok.',
    araIpucu: 'Kod, ödül, müşteri adı veya telefon…', sirala: { alan: 'islem', yon: 'desc' },
    kolonlar: [{ baslik: 'Kod', sirala: 'kod' }, { baslik: 'Ödül' }, { baslik: 'Müşteri' }, { baslik: 'Kazanma', sirala: 'kazanma' },
      { baslik: 'Son Kullanma', sirala: 'gecerlilik' }, { baslik: 'Durum' }, { baslik: 'İşlem', sinif: 'rc-orta' }],
    satir: (o) => `<tr>
      <td><span class="ck-kod">${esc(o.kod)}</span></td>
      <td><span class="rc-rozet rc-rozet-${TIP_RENK[o.tip] || 'gri'}">${esc(o.baslik)}</span></td>
      <td><b>${esc(o.musteri.ad)}</b>${o.musteri.telefon ? `<br><small class="ck-soluk">${esc(o.musteri.telefon)}</small>` : ''}</td>
      <td class="ck-soluk">${tarih(o.kazanma_tarihi)}</td>
      <td class="ck-soluk">${o.gecerlilik_tarihi ? tarih(o.gecerlilik_tarihi) : 'Süresiz'}</td>
      <td><span class="rc-rozet rc-rozet-${DURUM_RENK[o.durum]}">${esc(o.durum_ad)}</span>${o.kullanim_tarihi ? `<br><small class="ck-soluk">${tarih(o.kullanim_tarihi)}</small>` : ''}</td>
      <td class="rc-orta">${o.durum === 'gecerli' ? `<button class="rc-islem" data-isle="kullan" data-id="${o.id}"><i class="bi bi-check-lg"></i> Kullanıldı</button>`
        : o.durum === 'kullanildi' ? `<button class="rc-islem" data-isle="geri-al" data-id="${o.id}"><i class="bi bi-arrow-counterclockwise"></i> Geri Al</button>` : '<span class="ck-soluk">—</span>'}</td>
    </tr>`,
    kaynak: (p) => ctx.oku('/cark/kuponlar', { ...p, durum }),
    cizildi: (liste, v) => { kuponSatirlari = liste; sayaclar = v?.sayaclar || {}; sekmeCiz(); },
  });
  $('#kzKuponlar').addEventListener('click', (e) => {
    const b = e.target.closest('[data-isle]');
    if (b && kuponSatirlari.some((o) => o.id === Number(b.dataset.id))) isle(b, Number(b.dataset.id), b.dataset.isle, false);
  });

  // ── çevirme geçmişi ──
  const cevirmeTablosu = veriTablosu($('#kzCevirmeler'), {
    anahtar: 'cark_cevirmeler', birim: 'çevirme', varsayilanBoy: 25, bosMesaj: 'Henüz çevirme yapılmamış.',
    araIpucu: 'Müşteri veya dilim…', sirala: { alan: 'tarih', yon: 'desc' },
    kolonlar: [{ baslik: 'Tarih', sirala: 'tarih' }, { baslik: 'Müşteri' }, { baslik: 'Kazanan Dilim', sirala: 'dilim' }, { baslik: 'Ödül' }],
    satir: (l) => `<tr><td class="ck-soluk">${tarih(l.tarih)}</td><td><b>${esc(l.musteri)}</b></td><td class="ck-soluk">${esc(l.dilim_ismi || '—')}</td>
      <td><span class="rc-rozet rc-rozet-${TIP_RENK[l.tip] || 'gri'}">${esc(l.odul)}</span></td></tr>`,
    kaynak: (p) => ctx.oku('/cark/cevirmeler', p),
  });

  sekmeCiz();
  ozetYukle();
  kuponTablosu.yenile();
  cevirmeTablosu.yenile();
}
