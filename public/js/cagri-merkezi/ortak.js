/**
 * Çağrı Merkezi sekmelerinin ORTAK bileşenleri — TEK KAYNAK (stil: css/cagri-merkezi-v2.css .cm-*).
 *
 *   gorusmeKokpiti(kap, ctx, kayit, opt)  Görüşme kokpiti: başlık (ARA / Yeni Randevu / Randevu
 *                                         Geçmişi / Müşteri Detayı) + script + "Görüşme Sonucu" formu +
 *                                         Çağrı Geçmişi & Ses Kayıtları. Çalışma Ekranı (sağ panel)
 *                                         ve Arama Randevuları (modal) AYNI bileşeni kullanır — eski
 *                                         arama_listelerim ile arama_randevu_takvim'deki kopya kokpit tekleşti.
 *   sonucRozeti(ctx, kod) / gecmisCiz(kap, liste) / sesOynatici(url) / sure(sn)
 *
 * Etiket / renk / sonuç listesi / kategori / script / personel SUNUCUDAN (ctx.acilis); burada
 * yalnız çizim + kullanıcı akışı. Yazma cevapları yazSonuc ile (409 kill-switch dahil) toast olur.
 */
import { api, el, esc, mesaj, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=7';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';
import { kilitle } from '/js/lib/v2-bilesen.js?v=7';
import { randevuIslemleri, randevuTablosu } from '/js/lib/v2-randevu.js?v=8';
import { ongorusmeFormu, alan, secenekler, tarihBagla } from '/js/lib/v2-ongorusme.js?v=8';

/** Sonuç kodu → ikon (yalnız görsel; anlamı sunucu sözlüğünde). */
const IKON = { 0: 'bi-volume-mute', 1: 'bi-telephone-outbound', 2: 'bi-telephone-x', 3: 'bi-calendar-event', 4: 'bi-telephone-fill',
  5: 'bi-slash-circle', 6: 'bi-calendar-check', 7: 'bi-bag-check', 8: 'bi-calendar2-check', 9: 'bi-x-circle' };
const HEDEF = [6, 7];   // "Sonuç (hedef)" satırındaki dönüşüm sonuçları

export const harf = (ad) => esc(((ad || '?').trim().charAt(0) || '?').toLocaleUpperCase('tr'));
export const sure = (sn) => (sn > 0 ? `${Math.floor(sn / 60)}:${String(sn % 60).padStart(2, '0')}` : '—');
export const sesOynatici = (url) => (url ? `<audio class="cm-ses" controls preload="none" src="${esc(url)}"></audio>` : '');

/** Sözlükten sonuç bilgisi; null/bilinmeyen → "Bekliyor". */
export function sonucBilgi(ctx, kod) {
  const s = ctx.acilis.sozluk;
  return (kod === null || kod === undefined || kod === '') ? s.bekliyor : (s.sonuclar.find((x) => x.kod === Number(kod)) || s.bekliyor);
}
export const sonucRozeti = (ctx, kod) => { const b = sonucBilgi(ctx, kod); return `<span class="rc-rozet rc-rozet-${esc(b.renk)}">${esc(b.ad)}</span>`; };

/** Çağrı geçmişi satırları (kokpit + performans dökümü aynı görünüm). */
export function gecmisCiz(kap, liste, { bos = 'Bu müşteriyle henüz görüşme kaydedilmedi.', musteriGoster = false } = {}) {
  if (!liste.length) { kap.innerHTML = `<div class="cm-bos"><i class="bi bi-mic-mute"></i>${esc(bos)}</div>`; return; }
  kap.innerHTML = liste.map((g) => {
    const kat = [g.kategori, g.alt_kategori].filter(Boolean).join(' › ');
    return `<div class="cm-gecmis cm-k-${esc(g.renk || 'gri')}">
      <div class="cm-gecmis-ust">
        ${musteriGoster ? `<b>${esc(g.musteri || 'Müşteri')}</b>` : ''}
        <span class="cm-gecmis-tarih"><i class="bi bi-clock"></i> ${esc(g.tarih)}</span>
        <span class="rc-rozet rc-rozet-${esc(g.renk || 'gri')}">${esc(g.sonuc)}</span>
        ${g.randevu_tarih ? `<span class="cm-cip cm-cip-mavi"><i class="bi bi-calendar-check"></i> ${esc(g.randevu_tarih)} ${esc(g.randevu_saat || '')}</span>` : ''}
        ${g.satis_tutari ? `<span class="cm-cip cm-cip-yesil"><i class="bi bi-bag-check"></i> ${esc(Number(g.satis_tutari).toLocaleString('tr-TR'))} ₺</span>` : ''}
        ${g.sure_dk > 0 ? `<span class="cm-cip"><i class="bi bi-stopwatch"></i> ${esc(g.sure_dk)} dk</span>` : ''}
        ${kat ? `<span class="cm-cip"><i class="bi bi-tag"></i> ${esc(kat)}</span>` : ''}
      </div>
      ${g.not ? `<div class="cm-gecmis-not"><i class="bi bi-sticky"></i> ${esc(g.not)}</div>` : ''}
      ${g.ses ? sesOynatici(g.ses) : (musteriGoster ? '' : '<div class="cm-soluk cm-kucuk"><i class="bi bi-hourglass-split"></i> Ses kaydı işleniyor — birazdan “Yenile”ye basın.</div>')}
    </div>`;
  }).join('');
}

/**
 * ARA — v2 kabuğundaki WebRTC softphone bağlıysa softphone ile (sunucu numara + gösterim etiketi
 * döner; panelde numara değil etiket görünür), değilse santral originate (Bria çalar). Tek yerden:
 * kokpit + Çağrı Kayıtları + ajanda.  @returns {Promise<boolean>} başlatıldı mı
 */
export async function aramaBaslat(ctx, yol, govde = {}) {
  const sp = window.rcSoftphone;
  const softphone = !!(sp && sp.bagliMi && sp.bagliMi());
  const r = await ctx.yaz('POST', yol, { ...govde, softphone: softphone ? 1 : 0 });
  if (!r.ok || !r.govde?.basarili) { mesaj(r.govde?.mesaj || `Arama başlatılamadı (${r.kod})`, 'hata'); return false; }
  const v = r.govde.veri || {};
  if (v.softphone && v.numara_ara) {
    if (!sp.ara(v.numara_ara, { etiket: v.etiket })) { mesaj('Softphone hazır değil — üst köşedeki telefonun "Bağlı" olduğundan emin olun.', 'hata'); return false; }
    return true;
  }
  mesaj(r.govde.mesaj || 'Arama başlatıldı', 'ok');
  return true;
}

/** Müşteriyle yapılan TÜM ses kayıtları (son 120 gün) — modal. */
export async function tumSesKayitlari(ctx, kayitId, ad) {
  const ov = modalAc('Tüm Ses Kayıtları', '<div id="tsListe"><div class="cm-bos"><span class="rc-yukleniyor"></span></div></div>', '', { ikon: 'soundwave', altbaslik: ad });
  const r = await ctx.oku(`/kayit/${kayitId}/ses-kayitlari`);
  const kap = el('#tsListe', ov);
  if (!kap) return;
  if (!r.ok || !r.govde?.basarili) { kap.innerHTML = `<div class="cm-bos cm-hata">${esc(r.govde?.mesaj || 'Kayıtlar yüklenemedi')}</div>`; return; }
  const { kayitlar, santral_hata: hata } = r.govde.veri;
  kap.innerHTML = (hata ? '<div class="cm-uyari">Santrale şu an ulaşılamıyor; liste eksik olabilir.</div>' : '')
    + (kayitlar.length ? kayitlar.map((x) => `<div class="cm-gecmis"><span class="cm-gecmis-tarih"><i class="bi bi-clock"></i> ${esc(x.tarih)}</span>${sesOynatici(x.url)}</div>`).join('')
      : '<div class="cm-bos"><i class="bi bi-mic-mute"></i>Bu müşteriyle kayıtlı görüşme bulunamadı.</div>');
}

/** Manuel WhatsApp mesajı (numara personele gösterilmez; sunucu müşteriye gönderir). */
function whatsappMesaj(ctx, k) {
  const ov = modalAc('WhatsApp Mesajı', `${alan('Mesaj *', '<textarea id="waMesaj" rows="5" maxlength="1000" placeholder="Müşteriye gönderilecek mesaj…"></textarea><span class="rc-ipucu" id="waSay">0 / 1000</span>', 'rc-tam')}`,
    '<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-yesil" id="waGonder"><i class="bi bi-whatsapp"></i> Gönder</button>',
    { ikon: 'whatsapp', altbaslik: k.ad, dar: true });
  const $ = (s) => el(s, ov);
  $('[data-kapat]').onclick = modalKapat;
  $('#waMesaj').oninput = () => { $('#waSay').textContent = `${$('#waMesaj').value.length} / 1000`; };
  $('#waGonder').onclick = (e) => {
    const m = $('#waMesaj').value.trim();
    if (!m) return mesaj('Mesaj boş olamaz.', 'hata');
    kilitle(e.currentTarget, 'Gönderiliyor…', async () => {
      const r = await ctx.yaz('POST', `/kayit/${k.aranacak_musteri_id}/whatsapp`, { mesaj: m });
      if (yazSonuc(r, r.govde?.mesaj)) modalKapat();
    });
  };
}

/** Tek randevuIslemleri örneği (randevu formu çekmecesi body'ye bir kez eklenir). */
let _randevu = null;
const randevu = (ctx) => (_randevu ||= randevuIslemleri({ salonId: ctx.salonId }));

/** Dönüşüm formları için müşteri kimliği (ad tam; telefon yetkiye göre). */
async function musteriKimligi(ctx, kayitId) {
  const r = await ctx.oku(`/kayit/${kayitId}/musteri`);
  if (!r.ok || !r.govde?.basarili) { mesaj(r.govde?.mesaj || 'Müşteri bilgisi alınamadı', 'hata'); return null; }
  return r.govde.veri;
}

/**
 * Görüşme kokpiti.
 * @param {HTMLElement} kap
 * @param {object} ctx      sayfa bağlamı (salonId, acilis, oku, yaz)
 * @param {object} k        kuyruk satırı (GET /listeler/{id}/musteriler veya /kayit/{id})
 * @param {{listeBaslik?:string, takvim?:object, degisti?:Function}} opt
 *        takvim  : takvim olayı (verilirse "İptal / Ertele" bölümü) · degisti(k): yazım sonrası
 */
export function gorusmeKokpiti(kap, ctx, k, opt = {}) {
  const e = ctx.acilis.erisim;
  const y = e.yetkiler || {};
  const sozluk = ctx.acilis.sozluk.sonuclar;
  const temel = sozluk.filter((s) => s.agent_secebilir && !HEDEF.includes(s.kod));
  const hedef = sozluk.filter((s) => HEDEF.includes(s.kod));
  const personelSec = e.yonetici && ctx.acilis.personeller.length > 0;
  const zaman = k.not_tarih && k.not_saat ? `<span class="cm-k-zaman"><i class="bi bi-calendar-check"></i> Randevu: ${esc(k.not_tarih.split('-').reverse().join('.'))} ${esc(k.not_saat)}</span>` : '';
  // Hedef (dönüşüm) sonuçları aynı ızgarada, kalın kenar + 🎯 ile ayrışır (eski ayrı satır dikey yer kaplıyordu).
  const btn = (s, hedefMi = false) => `<button type="button" class="cm-sonuc cm-s-${esc(s.renk)}${hedefMi ? ' cm-hedef' : ''}" data-sonuc="${s.kod}" title="${hedefMi ? 'Sonuç (hedef)' : ''}"><i class="bi ${IKON[s.kod] || 'bi-dot'}"></i><span>${esc(s.ad)}</span></button>`;
  const takvimBolum = opt.takvim && !opt.takvim.islenmis ? `
    <div class="cm-kart cm-takvim-islem">
      <div class="cm-kart-bas"><i class="bi bi-calendar-event"></i> Arama Randevusu</div>
      <div class="cm-satir">
        <input type="text" id="kErtTarih" placeholder="Yeni tarih" readonly>
        <input type="time" id="kErtSaat">
        <button type="button" class="rc-btn rc-btn-ikincil" id="kErtele"><i class="bi bi-clock-history"></i> Ertele</button>
        <button type="button" class="rc-btn rc-btn-ikincil cm-tehlike" id="kIptal"><i class="bi bi-x-circle"></i> İptal Et</button>
      </div>
    </div>` : '';

  kap.innerHTML = `
    <div class="cm-kokpit">
      <div class="cm-k-bas">
        <div class="cm-avatar cm-avatar-b">${harf(k.ad)}</div>
        <div class="cm-k-kim">
          <div class="cm-k-ad">${esc(k.ad || 'Müşteri')}</div>
          <div class="cm-k-alt"><i class="bi bi-telephone"></i> ${esc(k.telefonGizlenmis || 'gizli')} <span id="kRozet">${sonucRozeti(ctx, k.durum_kod)}</span>
            ${Number(k.randevu_olusturuldu) === 1 ? '<span class="cm-cip cm-cip-mavi"><i class="bi bi-calendar2-check"></i> Randevu Verildi</span>' : ''}</div>
          ${opt.listeBaslik || zaman ? `<div class="cm-k-meta">${opt.listeBaslik ? `<span><i class="bi bi-megaphone"></i> ${esc(opt.listeBaslik)}</span>` : ''}${zaman}</div>` : ''}
        </div>
        <div class="cm-k-aksiyon">
          ${k.user_id ? `<a class="rc-btn rc-btn-ikincil" href="/v2/musteri/${k.user_id}?sube=${ctx.salonId}" target="_blank" rel="noopener" title="Müşteri Detayı"><i class="bi bi-person-vcard"></i><span class="cm-btn-metin">Müşteri Detayı</span></a>
            <button type="button" class="rc-btn rc-btn-ikincil" id="kGecmisRandevu" title="Randevu Geçmişi"><i class="bi bi-clock-history"></i><span class="cm-btn-metin">Randevu Geçmişi</span></button>
            <button type="button" class="rc-btn rc-btn-mavi" id="kYeniRandevu" title="Yeni Randevu"><i class="bi bi-calendar-plus"></i><span class="cm-btn-metin">Yeni Randevu</span></button>` : ''}
          <button type="button" class="cm-ara-btn" id="kAra" ${e.dahili_var ? '' : 'disabled title="Size dahili numara atanmamış"'}><i class="bi bi-telephone-fill"></i> ARA</button>
        </div>
      </div>
      <div class="cm-k-govde">
        ${ctx.acilis.scriptler.length ? `<div class="cm-kart"><div class="cm-kart-bas"><i class="bi bi-file-text"></i> Görüşme Scripti</div>
          <select id="kScript">${secenekler(ctx.acilis.scriptler, null, '— Görüşme scripti seçin —', 'id', 'baslik')}</select>
          <div id="kScriptIcerik" class="cm-script" hidden></div></div>` : ''}
        ${takvimBolum}
        <div class="cm-kart">
          <div class="cm-kart-bas"><i class="bi bi-check2-circle"></i> Görüşme Sonucu</div>
          <div class="cm-sonuc-izgara">${temel.map((x) => btn(x)).join('')}${hedef.map((x) => btn(x, true)).join('')}</div>
          ${ctx.acilis.kategoriler.length ? `<div class="cm-iki">
            <select id="kKat">${secenekler(ctx.acilis.kategoriler, null, 'Kategori seçin (opsiyonel)')}</select>
            <select id="kAltKat" hidden></select></div>` : ''}
          <textarea id="kNot" rows="2" placeholder="Görüşme notu (müşteri ne dedi, talep, vb.)…"></textarea>
          <div class="cm-satis-alan" id="kSatisAlan" hidden>
            <label>Satış tutarı (₺) <input type="number" min="0" step="0.01" id="kSatisTutar" placeholder="örn: 1500"></label>
            ${y.satis ? '<button type="button" class="rc-btn rc-btn-yesil" id="kKasa"><i class="bi bi-cash-coin"></i> Satış Oluştur (Kasaya İşle)</button>' : ''}
          </div>
          <label class="cm-sonra"><input type="checkbox" id="kSonra"> 📅 ARAMA RANDEVUSU VER — müşteri sonra aranmak istedi (Tekrar Aranacak)</label>
          <div class="cm-sonra-alan" id="kSonraAlan" hidden>
            ${personelSec ? `<select id="kSonraPersonel">${secenekler(ctx.acilis.personeller, null, '👤 Arayacak personel (varsayılan: liste personeli)')}</select>` : ''}
            <input type="text" id="kSonraTarih" placeholder="Tarih seçin" readonly>
            <input type="time" id="kSonraSaat">
          </div>
          <button type="button" class="cm-kaydet" id="kKaydet"><i class="bi bi-save"></i> Sonucu Kaydet</button>
          <div class="cm-iki">
            <button type="button" class="cm-whatsapp" id="kWhatsapp"><i class="bi bi-whatsapp"></i> WhatsApp Mesajı</button>
            ${y.anket ? '<button type="button" class="cm-anket" id="kAnket"><i class="bi bi-star"></i> Memnuniyet Anketi</button>' : ''}
          </div>
        </div>
        <div class="cm-kart">
          <div class="cm-kart-bas"><i class="bi bi-clock-history"></i> Çağrı Geçmişi &amp; Ses Kayıtları
            <button type="button" class="cm-mini-btn" id="kTumSes"><i class="bi bi-soundwave"></i> Tüm Ses Kayıtları</button>
            <button type="button" class="cm-mini-btn cm-mini-yan" id="kGecmisYenile"><i class="bi bi-arrow-clockwise"></i> Yenile</button></div>
          <div id="kGecmis"><div class="cm-bos"><span class="rc-yukleniyor"></span></div></div>
        </div>
      </div>
    </div>`;

  let secili = null;
  const $ = (s) => el(s, kap);
  const durumYaz = (kod, ek = {}) => {
    Object.assign(k, { durum_kod: kod }, ek);
    $('#kRozet').innerHTML = sonucRozeti(ctx, kod);
    opt.degisti?.(k);
  };
  const gecmisYukle = async () => {
    const r = await ctx.oku(`/kayit/${k.aranacak_musteri_id}/gecmis`);
    if (!kap.isConnected) return;
    if (!r.ok || !r.govde?.basarili) { $('#kGecmis').innerHTML = `<div class="cm-bos cm-hata">${esc(r.govde?.mesaj || 'Geçmiş yüklenemedi')}</div>`; return; }
    gecmisCiz($('#kGecmis'), r.govde.veri.gecmis);
  };
  const formuSifirla = () => {
    secili = null;
    kap.querySelectorAll('.cm-sonuc').forEach((b) => b.classList.remove('aktif'));
    $('#kNot').value = '';
    $('#kSatisAlan').hidden = true;
    $('#kSonra').checked = false;
    $('#kSonraAlan').hidden = true;
  };
  const sonucGonder = (govde) => ctx.yaz('POST', `/kayit/${k.aranacak_musteri_id}/sonuc`, govde);

  tarihBagla($('#kSonraTarih'), { minDate: 'today' });
  if ($('#kErtTarih')) tarihBagla($('#kErtTarih'), { minDate: 'today' });
  gecmisYukle();

  $('#kGecmisYenile').onclick = () => { $('#kGecmis').innerHTML = '<div class="cm-bos"><span class="rc-yukleniyor"></span></div>'; gecmisYukle(); };

  $('#kScript')?.addEventListener('change', (ev) => {
    const s = ctx.acilis.scriptler.find((x) => String(x.id) === ev.target.value);
    $('#kScriptIcerik').hidden = !(s && s.icerik.trim());
    $('#kScriptIcerik').textContent = s ? s.icerik : '';
  });

  $('#kKat')?.addEventListener('change', (ev) => {
    const kat = ctx.acilis.kategoriler.find((x) => String(x.id) === ev.target.value);
    const alt = $('#kAltKat');
    alt.hidden = !(kat && kat.altlar.length);
    alt.innerHTML = kat ? secenekler(kat.altlar, null, 'Alt kategori seçin') : '';
  });

  // Sonuç seç: "sonra ara" ile çelişmesin; 7 → tutar alanı; 6 → ön görüşme formu (kaydedilince işaretlenir).
  kap.querySelectorAll('.cm-sonuc').forEach((b) => b.addEventListener('click', async () => {
    kap.querySelectorAll('.cm-sonuc').forEach((x) => x.classList.toggle('aktif', x === b));
    secili = Number(b.dataset.sonuc);
    $('#kSonra').checked = false;
    $('#kSonraAlan').hidden = true;
    $('#kSatisAlan').hidden = secili !== 7;
    if (secili === 6) onGorusme();
  }));

  $('#kSonra').addEventListener('change', (ev) => {
    $('#kSonraAlan').hidden = !ev.target.checked;
    if (ev.target.checked) { secili = null; kap.querySelectorAll('.cm-sonuc').forEach((x) => x.classList.remove('aktif')); $('#kSatisAlan').hidden = true; }
  });

  $('#kKaydet').addEventListener('click', (ev) => {
    const sonra = $('#kSonra').checked;
    const not = $('#kNot').value;
    const tarih = $('#kSonraTarih').value;
    const saat = $('#kSonraSaat').value;
    const tutar = $('#kSatisTutar').value;
    if (sonra && (!tarih || !saat)) return mesaj('Tekrar arama için tarih ve saat seçin.', 'hata');
    if (secili === 7 && !(parseFloat(tutar) > 0)) return mesaj('Telefonda satış için tutar girin.', 'hata');
    if (!sonra && secili === null && !not.trim()) return mesaj('Bir görüşme sonucu seçin, tekrar arama oluşturun ya da not yazın.', 'hata');
    kilitle(ev.currentTarget, 'Kaydediliyor…', async () => {
      const r = await sonucGonder({
        sonuc: sonra ? null : secili, not, kategori_id: $('#kKat')?.value || null, alt_kategori_id: $('#kAltKat')?.value || null,
        satis_tutari: secili === 7 ? tutar : null, tarih: sonra ? tarih : null, saat: sonra ? saat : null,
        arama_personel_id: sonra ? ($('#kSonraPersonel')?.value || null) : null,
      });
      if (!yazSonuc(r, 'Kaydedildi')) return;
      durumYaz(r.govde.veri.durum, sonra ? { not_tarih: tarih, not_saat: saat } : {});
      formuSifirla();
      gecmisYukle();
    });
  });

  $('#kAra').addEventListener('click', (ev) => kilitle(ev.currentTarget, 'Bağlanıyor…', async () => {
    if (await aramaBaslat(ctx, `/kayit/${k.aranacak_musteri_id}/ara`) && Number(k.durum_kod) === 3) durumYaz(1);
  }));
  $('#kWhatsapp').addEventListener('click', () => whatsappMesaj(ctx, k));
  $('#kTumSes').addEventListener('click', () => tumSesKayitlari(ctx, k.aranacak_musteri_id, k.ad));

  $('#kAnket')?.addEventListener('click', async (ev) => {
    if (!await onay('Müşteriye anket linki WhatsApp veya SMS ile gönderilecek.', { baslik: 'Memnuniyet anketi gönderilsin mi?', tamam: 'Evet, gönder' })) return;
    kilitle(ev.currentTarget, 'Gönderiliyor…', async () => {
      yazSonuc(await ctx.yaz('POST', `/kayit/${k.aranacak_musteri_id}/anket`), 'Anket gönderim kuyruğuna alındı');
    });
  });

  $('#kKasa')?.addEventListener('click', () => telefondaSatis(ctx, k, {
    tutar: $('#kSatisTutar').value, not: $('#kNot').value,
    tamam: () => { durumYaz(7); formuSifirla(); gecmisYukle(); },
  }));

  $('#kGecmisRandevu')?.addEventListener('click', () => {
    const ov = modalAc('Randevu Geçmişi', '<div id="kRgTablo"></div>', '', { ikon: 'clock-history', altbaslik: k.ad, genis: true });
    randevuTablosu(el('#kRgTablo', ov), { salonId: ctx.salonId, yol: `/musteri/${k.user_id}/randevular`, musteri: { id: k.user_id, ad: k.ad } }).tablo.yenile();
  });

  // Yeni Randevu: yalnız randevu formu KAYDEDİLİNCE "Randevu Verildi" (8) işaretlenir.
  $('#kYeniRandevu')?.addEventListener('click', async () => {
    const m = await musteriKimligi(ctx, k.aranacak_musteri_id);
    if (!m) return;
    randevu(ctx).randevuFormu({
      musteri: m,
      kaydedildi: async () => {
        const r = await ctx.yaz('POST', `/kayit/${k.aranacak_musteri_id}/randevu-verildi`);
        if (yazSonuc(r)) { durumYaz(r.govde.veri.durum, { randevu_olusturuldu: 1 }); gecmisYukle(); }
      },
    });
  });

  async function onGorusme() {
    if (!y.ongorusme) { mesaj('Ön görüşme oluşturma yetkiniz yok.', 'hata'); return; }
    const m = await musteriKimligi(ctx, k.aranacak_musteri_id);
    if (!m) return;
    ongorusmeFormu({
      salonId: ctx.salonId, musteri: m,
      onKaydet: async () => {
        const r = await sonucGonder({ sonuc: 6, not: $('#kNot').value || null });
        if (yazSonuc(r)) { durumYaz(6); formuSifirla(); gecmisYukle(); }
      },
    });
  }

  // Takvim: arama randevusunu ertele / iptal et.
  $('#kErtele')?.addEventListener('click', (ev) => {
    const tarih = $('#kErtTarih').value, saat = $('#kErtSaat').value;
    if (!tarih || !saat) return mesaj('Yeni tarih ve saat seçin.', 'hata');
    kilitle(ev.currentTarget, 'Kaydediliyor…', async () => {
      const r = await ctx.yaz('POST', `/kayit/${k.aranacak_musteri_id}/ertele`, { tarih, saat });
      if (yazSonuc(r, 'Arama randevusu ertelendi')) durumYaz(3, { not_tarih: tarih, not_saat: saat });
    });
  });
  $('#kIptal')?.addEventListener('click', async (ev) => {
    if (!await onay('Bu arama randevusu iptal edilsin mi? Takvimde "İptal" olarak kalır.', { baslik: 'Arama randevusu iptal', tamam: 'İptal Et' })) return;
    kilitle(ev.currentTarget, 'İptal ediliyor…', async () => {
      const r = await ctx.yaz('POST', `/kayit/${k.aranacak_musteri_id}/iptal`);
      if (yazSonuc(r, 'Arama randevusu iptal edildi')) durumYaz(9);
    });
  });
}

/**
 * Telefonda Satış modalı: katalogdan paket / hizmet / ürün → gerçek satış (adisyon) + görüşme
 * "Telefonda Satış" olarak işaretlenir (sunucuda tek uç). Tahsilat v2 tahsilat ekranında alınır.
 */
export async function telefondaSatis(ctx, k, { tutar = '', not = '', tamam = () => {} } = {}) {
  const r = await api('GET', '/katalog', { params: { salon_id: ctx.salonId } });
  if (!r.ok || !r.govde?.basarili) return mesaj(r.govde?.mesaj || 'Katalog yüklenemedi', 'hata');
  const kat = r.govde.veri;
  const TUR = {
    paket: { ad: 'Paket', liste: kat.paketler, id: 'paket_id' },
    hizmet: { ad: 'Hizmet', liste: kat.hizmetler, id: 'hizmet_id' },
    urun: { ad: 'Ürün', liste: kat.urunler, id: 'urun_id' },
  };
  const fiyatYetki = !!ctx.acilis.erisim.yetkiler?.fiyat_degistir;
  const ov = modalAc('Telefonda Satış', `
    <div class="rc-form-izgara">
      ${alan('Ne satıldı?', `<select id="sTur">${Object.entries(TUR).map(([kod, t]) => `<option value="${kod}">${t.ad}</option>`).join('')}</select>`)}
      ${alan('Kalem *', '<select id="sKalem"></select>')}
      ${alan('Satış fiyatı (₺)', `<input id="sFiyat" type="number" min="0" step="0.01" value="${esc(tutar)}" ${fiyatYetki ? '' : 'readonly title="Katalog fiyatı uygulanır (fiyat değiştirme yetkiniz yok)"'}>`)}
      ${alan('Adet', '<input id="sAdet" type="number" min="1" step="1" value="1">', 'cm-adet')}
    </div>
    <p class="cm-soluk cm-kucuk"><i class="bi bi-info-circle"></i> Satış (adisyon) oluşturulur; tahsilatı açılan satış ekranından alırsınız.</p>`,
  '<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-yesil" id="sKaydet"><i class="bi bi-check-lg"></i> Satışı Oluştur</button>',
  { ikon: 'bag-check', altbaslik: k.ad, sabit: true });
  const $ = (s) => el(s, ov);
  $('[data-kapat]').onclick = modalKapat;
  const kalemleriCiz = () => {
    const t = TUR[$('#sTur').value];
    $('#sKalem').innerHTML = secenekler(t.liste, null, `${t.ad} seçin…`, t.id, 'ad');
    $('.cm-adet').hidden = $('#sTur').value !== 'urun';
  };
  $('#sTur').onchange = kalemleriCiz;
  $('#sKalem').onchange = () => {
    const t = TUR[$('#sTur').value];
    const x = t.liste.find((i) => String(i[t.id]) === $('#sKalem').value);
    if (x && (!fiyatYetki || !(parseFloat($('#sFiyat').value) > 0))) $('#sFiyat').value = x.fiyat ?? '';
  };
  kalemleriCiz();
  const anahtar = (crypto.randomUUID && crypto.randomUUID()) || String(Date.now()) + Math.random();

  $('#sKaydet').onclick = (ev) => {
    const tur = $('#sTur').value;
    if (!$('#sKalem').value) return mesaj('Satılan paket / hizmet / ürünü seçin.', 'hata');
    kilitle(ev.currentTarget, 'İşleniyor…', async () => {
      const govde = { tur, katalog_id: Number($('#sKalem').value), not: not || null, idempotency_key: anahtar };
      if (tur === 'urun') govde.adet = Number($('#sAdet').value) || 1;
      if (tur !== 'urun' && fiyatYetki && $('#sFiyat').value !== '') govde.fiyat = Number($('#sFiyat').value);
      const s = await ctx.yaz('POST', `/kayit/${k.aranacak_musteri_id}/satis`, govde);
      if (!yazSonuc(s, s.govde?.mesaj)) return;
      modalKapat();
      tamam(s.govde.veri);
      if (await onay('Satış oluşturuldu. Tahsilatı şimdi almak ister misiniz?', { baslik: 'Tahsilat', tamam: 'Tahsilat ekranını aç' })) {
        window.open(`/v2/tahsilat/${s.govde.veri.adisyon_id}?sube=${ctx.salonId}`, '_blank', 'noopener');
      }
    });
  };
}

