/**
 * Bildirim Reklamları (v2) — build'siz island (sade ES module).
 *
 * Eski "Bildirim Reklamları" ekranının (isletmeadmin/bildirim_reklamlari.blade) v2 karşılığı,
 * aynı akış ve görünüm: tanıtım bandı, reklam kartları (görsel, tür, durum, kanallar, kupon
 * sayacı), Yeni Reklam / Düzenle modalı, Yayınla-Duraklat, Push Gönder, Sil.
 * v2 ekleri: durum sekmeleri + arama + sayfalama, gönderim öncesi "kaç kişiye gidecek" sayısı.
 *
 * TEK KAYNAK: sözlükler (tür/durum/aksiyon/segment), hizmet/ürün, şubeler, varsayılanlar ve
 * kurallar /api/v2/bildirim-reklam/* uçlarından (Flutter da aynı uçları kullanır) — burada
 * etiket / kural kopyalanmaz. Yazmalar BILDIRIM_REKLAM_V2_YAZMA_AKTIF kapalıyken 409 (toast).
 */

import { qp, api, el, esc, mesaj, onay, yazSonuc, sayfalamaCiz } from '/js/lib/v2-ortak.js?v=1';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { musteriSecici } from '/js/lib/v2-secici.js?v=2';
import { kilitle } from '/js/lib/v2-bilesen.js?v=2';
import { apiForm } from '/js/lib/v2-dosya.js?v=2';
import { alan, secenekler, tarihBagla } from '/js/lib/v2-ongorusme.js?v=1';
import { subeSecici } from '/js/lib/v2-sube-secici.js?v=1';

const kok = document.getElementById('rc-reklam');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
const YOL = '/bildirim-reklam';

let fv = null;
const durumF = { durum: '', ara: '', page: 1 };
let sonListe = [];
let aramaZaman;

const yazabilir = () => !!fv?.yetkiler?.yaz;
const oku = (yol, params = {}) => api('GET', yol, { params: { ...params, salon_id: salonId } });
const yaz = (method, yol, body = {}) => api(method, yol, { body: { ...body, salon_id: salonId } });
const reklamBul = (id) => sonListe.find((r) => String(r.id) === String(id));

// ─────────────────────────── iskelet ───────────────────────────

function iskeletCiz() {
  kok.innerHTML = `
    <div class="br-kabuk">
      <div class="rc-sayfa-ust">
        <div class="rc-sayfa-baslik">
          <span class="rc-sayfa-ikon"><i class="bi bi-megaphone"></i></span>
          <div><h1>Bildirim Reklamları</h1><div class="rc-sayfa-alt">Müşterilerinize resimli, tıklanabilir bildirim ve uygulama içi kampanya</div></div>
        </div>
        <div class="rc-sayfa-aksiyon">
          ${yazabilir() ? '<button class="rc-btn rc-btn-birincil" id="brYeni"><i class="bi bi-plus-lg"></i> Yeni Reklam</button>' : ''}
        </div>
      </div>
      <div class="br-hero">
        <div class="br-hero-ikon">📣</div>
        <div>
          <h2>Müşterilerinize resimli bildirim reklamı gönderin</h2>
          <p>Bir görsel + kısa mesaj hazırlayın. Müşteri uygulamada görsele <b>dokununca indirim kuponu anında hesabına tanımlanır</b>.
             Kanallar: <b>Push</b> (anlık bildirim), <b>Uygulama içi</b> kart ve <b>Tam ekran</b> açılış popup'ı.</p>
        </div>
      </div>
      <div class="br-arac">
        <div class="rc-sekmeler" id="brSekmeler"></div>
        <div class="br-ara"><i class="bi bi-search"></i><input id="brAra" placeholder="Başlık veya mesajda ara" autocomplete="off"></div>
      </div>
      <div id="brListe" class="br-izgara"></div>
      <div id="brSayfa" class="rc-sayfalama"></div>
    </div>`;

  el('#brYeni')?.addEventListener('click', () => formAc(null));
  el('#brAra').addEventListener('input', (e) => {
    clearTimeout(aramaZaman);
    aramaZaman = setTimeout(() => { durumF.ara = e.target.value.trim(); durumF.page = 1; yukle(); }, 300);
  });
  el('#brSekmeler').addEventListener('click', (e) => {
    const b = e.target.closest('[data-durum]');
    if (!b) return;
    durumF.durum = b.dataset.durum; durumF.page = 1; yukle();
  });
  el('#brListe').addEventListener('click', kartTik);
}

function sekmeCiz(sayaclar) {
  const sek = [{ kod: '', ad: 'Tümü', n: sayaclar.tumu }, ...fv.durumlar.map((d) => ({ kod: d.kod, ad: d.ad, n: sayaclar[d.kod] }))];
  el('#brSekmeler').innerHTML = sek.map((s) => `<button class="rc-sekme${s.kod === durumF.durum ? ' aktif' : ''}" data-durum="${esc(s.kod)}">${esc(s.ad)} <span class="rc-sayac">${s.n ?? 0}</span></button>`).join('');
}

// ─────────────────────────── liste + kartlar ───────────────────────────

async function yukle() {
  el('#brListe').innerHTML = '<div class="rc-sekme-yuk"><span class="rc-yukleniyor"></span></div>';
  const r = await oku(YOL, { durum: durumF.durum, ara: durumF.ara, page: durumF.page, per_page: 24 });
  if (!r.ok || !r.govde?.basarili) {
    el('#brListe').innerHTML = `<div class="rc-sekme-bos">${esc(r.govde?.mesaj || 'Reklamlar yüklenemedi')}</div>`;
    return;
  }
  const v = r.govde.veri;
  sonListe = v.data;
  sekmeCiz(v.sayaclar);
  sayfalamaCiz(el('#brSayfa'), v, (p) => { durumF.page = p; yukle(); }, 'reklam');

  if (!sonListe.length) {
    const ilk = !durumF.durum && !durumF.ara;
    el('#brListe').innerHTML = `<div class="br-bos"><div class="br-bos-ikon">📭</div>
      <p>${ilk ? 'Henüz reklam oluşturmadınız.' : 'Bu filtrede reklam yok.'}</p>
      ${ilk && yazabilir() ? '<button class="rc-btn rc-btn-birincil" data-yeni>İlk reklamı oluştur</button>' : ''}</div>`;
    return;
  }
  el('#brListe').innerHTML = sonListe.map(kartHtml).join('');
}

function kartHtml(r) {
  const t = r.tur_etiket, d = r.durum_etiket;
  const gorsel = r.gorsel_url
    ? `<img src="${esc(r.gorsel_url)}" alt="" loading="lazy">`
    : `<i class="bi ${esc(t.ikon)}" style="color:${esc(t.renk)}"></i>`;
  const meta = [
    r.kanal_push ? '<span><i class="bi bi-bell"></i> Push</span>' : '',
    r.kanal_inapp ? '<span><i class="bi bi-phone"></i> Uygulama içi</span>' : '',
    r.tam_ekran ? '<span><i class="bi bi-window-fullscreen"></i> Tam ekran</span>' : '',
    r.kupon_ozet ? `<span><i class="bi bi-ticket-perforated"></i> ${esc(r.kupon_ozet)} kupon</span>` : '',
    r.hedef.kitle === 'segment' ? `<span title="${esc(r.hedef.kisi ? r.hedef.kisi.ad : '')}"><i class="bi bi-funnel"></i> ${esc(r.hedef.etiket)}</span>` : '',
  ].join('');
  const adet = r.kupon_ozet && r.kupon_toplam_adet ? `<div class="br-adet">${r.kupon_dagitilan} / ${r.kupon_toplam_adet} kupon kapıldı</div>`
    : (r.kupon_ozet && r.kupon_dagitilan ? `<div class="br-adet">${r.kupon_dagitilan} kupon kapıldı</div>` : '');
  const aksiyonlar = yazabilir() ? `
      <div class="br-kart-aksiyon">
        <button class="br-a" data-is="duzenle" title="Düzenle"><i class="bi bi-pencil"></i></button>
        ${r.kanal_push ? `<button class="br-a" data-is="gonder" title="Push Gönder"><i class="bi bi-send"></i></button>` : ''}
        ${r.durum === 'aktif'
          ? '<button class="br-a" data-is="durum" data-yeni="pasif" title="Duraklat"><i class="bi bi-pause-fill"></i></button>'
          : '<button class="br-a" data-is="durum" data-yeni="aktif" title="Yayınla"><i class="bi bi-play-fill"></i></button>'}
        <button class="br-a br-sil" data-is="sil" title="Sil"><i class="bi bi-trash"></i></button>
      </div>` : '';

  return `
    <div class="br-kart" data-id="${r.id}">
      <div class="br-kart-gorsel" style="background:${esc(t.renk)}12">
        ${gorsel}
        <span class="br-durum" style="background:${esc(d.renk)}">${esc(d.ad)}</span>
        ${r.push_gonderildi ? '<span class="br-gonderildi" title="Push gönderildi"><i class="bi bi-check2-all"></i></span>' : ''}
      </div>
      <div class="br-kart-govde">
        <span class="br-tur" style="color:${esc(t.renk)};background:${esc(t.renk)}15"><i class="bi ${esc(t.ikon)}"></i> ${esc(t.ad)}</span>
        <h4>${esc(r.baslik)}</h4>
        <p>${esc((r.mesaj || '').length > 70 ? r.mesaj.slice(0, 70) + '…' : (r.mesaj || ''))}</p>
        <div class="br-meta">${meta}</div>
        ${adet}
      </div>
      ${aksiyonlar}
    </div>`;
}

async function kartTik(e) {
  if (e.target.closest('[data-yeni]')) { formAc(null); return; }
  const b = e.target.closest('[data-is]');
  if (!b) return;
  const r = reklamBul(b.closest('.br-kart').dataset.id);
  if (!r) return;

  if (b.dataset.is === 'duzenle') formAc(r);
  if (b.dataset.is === 'durum') {
    await kilitle(b, '', async () => { if (yazSonuc(await yaz('PATCH', `${YOL}/${r.id}/durum`, { durum: b.dataset.yeni }), b.dataset.yeni === 'aktif' ? 'Reklam yayında' : 'Reklam duraklatıldı')) yukle(); });
  }
  if (b.dataset.is === 'sil') {
    if (!(await onay(`"${r.baslik}" reklamı silinsin mi?`, { baslik: 'Reklamı Sil', tamam: 'Sil' }))) return;
    if (yazSonuc(await api('DELETE', `${YOL}/${r.id}`, { params: { salon_id: salonId } }), 'Reklam silindi')) yukle();
  }
  if (b.dataset.is === 'gonder') await pushGonder(b, r);
}

/** Önce gerçek alıcı sayısı (hedef ∩ bildirime izin veren), onaydan sonra outbox'a gönderim. */
async function pushGonder(btn, r) {
  await kilitle(btn, '', async () => {
    const s = await oku(`${YOL}/${r.id}/alici-sayisi`);
    if (!s.ok || !s.govde?.basarili) { yazSonuc(s); return; }
    const { hedef, alici } = s.govde.veri;
    const metin = alici > 0
      ? `"${r.baslik}" reklamı ${alici} kişiye push bildirim olarak gönderilsin mi? (hedefte ${hedef} müşteri; bildirime izin veren ${alici})`
      : `Hedefte ${hedef} müşteri var ama bildirime izin veren yok — push kimseye gitmez. Yine de reklam yayına alınsın mı?`;
    if (!(await onay(metin, { baslik: 'Push Gönder', tamam: alici > 0 ? 'Gönder' : 'Yayına Al', tehlike: false }))) return;
    const g = await yaz('POST', `${YOL}/${r.id}/gonder`);
    if (yazSonuc(g)) { mesaj(g.govde.mesaj, g.govde.veri.alici > 0 ? 'ok' : 'bilgi'); yukle(); }
  });
}

// ─────────────────────────── oluştur / düzenle modalı ───────────────────────────

const kanalChip = (ad, ikon, baslik, alt, acik) => `
  <label class="br-chip"><input type="checkbox" name="${ad}"${acik ? ' checked' : ''}>
    <i class="bi ${ikon}"></i><b>${baslik}</b><small>${alt}</small><span class="br-chip-tik"><i class="bi bi-check-lg"></i></span></label>`;

function formHtml(r) {
  const v = { ...fv.varsayilan, ...(r || {}) };
  const h = r?.hedef || { kitle: 'tumu', segment: 'kisi', deger: null };
  const segDeger = (kod) => (h.segment === kod ? h.deger : null);

  return `
    <form id="brForm" class="br-form" autocomplete="off">
      ${alan('Reklam Türü', `<select name="tur">${secenekler(fv.turler, v.tur, null, 'kod', 'ad')}</select>`)}
      <div class="br-ikili">
        <div class="br-sol">
          ${alan('Başlık *', `<input name="baslik" maxlength="120" required value="${esc(v.baslik || '')}" placeholder="Örn: Bu haftaya özel %20 indirim!">`)}
          ${alan('Mesaj', `<textarea name="mesaj" rows="3" maxlength="300" placeholder="Kısa açıklama (bildirim gövdesi)">${esc(v.mesaj || '')}</textarea>`)}
        </div>
        <div class="br-sag">
          ${alan('Görsel (uygulama içi kart)', `
            <div class="br-yukle" id="brYukle">${v.gorsel_url ? `<img src="${esc(v.gorsel_url)}" alt="">` : '<div><i class="bi bi-image"></i><small>Görsel yükle</small></div>'}</div>
            <input type="file" id="brDosya" accept="image/jpeg,image/png,image/webp" hidden>
            <input type="hidden" name="gorsel" value="${esc(v.gorsel || '')}">
            <span class="rc-ipucu">Açılış tam ekran (popup) için dikey Instagram Story görseli önerilir: <b>${esc(fv.gorsel_onerisi || '')}</b>.</span>`)}
        </div>
      </div>

      <div class="rc-alan"><label>Kanallar — nasıl gösterilsin?</label>
        <div class="br-chips">
          ${kanalChip('kanal_push', 'bi-bell', 'Push', 'anlık bildirim', v.kanal_push)}
          ${kanalChip('kanal_inapp', 'bi-phone', 'Uygulama içi', 'ana ekran kartı', v.kanal_inapp)}
          ${kanalChip('tam_ekran', 'bi-window-fullscreen', 'Tam ekran', 'açılış popup', v.tam_ekran)}
        </div>
      </div>

      ${alan('Görsele dokununca ne olsun?', `<select name="aksiyon_tipi">${secenekler(fv.aksiyonlar, v.aksiyon_tipi, null, 'kod', 'ad')}</select>`)}

      <div class="br-kutu br-kupon" data-bolum="kupon">
        <div class="br-kutu-baslik"><i class="bi bi-ticket-perforated"></i> Kupon Ayarları</div>
        <div class="rc-form-izgara">
          ${alan('İndirim Tipi', `<select name="kupon_indirim_tipi">${secenekler([{ id: 'yuzde', ad: 'Yüzde (%)' }, { id: 'tutar', ad: 'Tutar (₺)' }], v.kupon_indirim_tipi)}</select>`)}
          ${alan('Değer', `<input name="kupon_deger" type="number" min="0" step="0.01" value="${v.kupon_deger > 0 ? esc(v.kupon_deger) : ''}" placeholder="20">`)}
          ${alan('Kupon Ne İçin?', `<select name="kupon_gecerli_tip">${secenekler([{ id: 'hizmet', ad: 'Hizmet' }, { id: 'urun', ad: 'Ürün' }, { id: 'tumu', ad: 'Tümü (kısıtlama yok)' }], v.kupon_gecerli_tip)}</select>`)}
          ${alan('Geçerli Hizmet', `<select name="kupon_hizmet_id">${secenekler(fv.hizmetler, v.kupon_hizmet_id, 'Tüm hizmetler')}</select>`, 'br-kupon-hizmet')}
          ${alan('Geçerli Ürün', `<select name="kupon_urun_id">${secenekler(fv.urunler, v.kupon_urun_id, 'Tüm ürünler')}</select>`, 'br-kupon-urun')}
          ${alan('Kupon Başlığı <small>(boş = otomatik)</small>', `<input name="kupon_baslik" maxlength="150" value="${esc(v.kupon_baslik || '')}" placeholder="Örn: %20 Hizmet İndirimi">`)}
          ${alan('Geçerlilik (gün)', `<input name="kupon_gecerlilik_gun" type="number" min="0" value="${esc(v.kupon_gecerlilik_gun ?? '')}" placeholder="Boş = süresiz">`)}
          ${alan('Toplam Adet', `<input name="kupon_toplam_adet" type="number" min="0" value="${esc(v.kupon_toplam_adet ?? '')}" placeholder="Boş = sınırsız">`)}
          ${alan('Kişi Başı Limit', `<input name="kupon_kisi_limit" type="number" min="1" value="${esc(v.kupon_kisi_limit || 1)}">`)}
        </div>
        <small class="br-ipucu">Aynı müşteri kuponu kişi başı limit kadar kapabilir. Toplam adet dolunca kampanya otomatik biter.</small>
        <small class="br-ipucu br-randevu-not">Boş slot reklamında kupon <b>opsiyoneldir</b>: Değer girersen müşteri dokununca kuponu alır (ödemede uygulanır). İndirim istemiyorsan Değer'i boş bırak.</small>
      </div>

      <div class="br-kutu br-randevu" data-bolum="randevu">
        <div class="br-kutu-baslik"><i class="bi bi-calendar-event"></i> Randevu Tarih & Saat Aralığı (boş slot)</div>
        <div class="rc-form-izgara">
          ${alan('Başlangıç Tarihi', `<input name="randevu_tarih" value="${esc(v.randevu_tarih || '')}" placeholder="Seçiniz">`)}
          ${alan('Bitiş Tarihi', `<input name="randevu_tarih_bit" value="${esc(v.randevu_tarih_bit || '')}" placeholder="Seçiniz">`)}
          ${alan('Başlangıç Saati', `<input name="randevu_saat_bas" type="time" value="${esc(v.randevu_saat_bas || '')}">`)}
          ${alan('Bitiş Saati', `<input name="randevu_saat_bit" type="time" value="${esc(v.randevu_saat_bit || '')}">`)}
        </div>
        <small class="br-ipucu">Müşteri dokununca randevu ekranı <b>bu tarih ve saat aralığına</b> kısıtlı açılır. Boş bırakırsan tüm gün açık gelir.</small>
      </div>

      ${alan('Hedef Kitle (Push kime gitsin?)', `<select name="hedef_kitle">${secenekler([{ id: 'tumu', ad: 'Tüm müşteriler' }, { id: 'segment', ad: 'Belirli segment' }], h.kitle)}</select>`)}
      <div class="br-kutu br-segment" data-bolum="segment">
        ${alan('Segment', `<select name="segment_tip">${secenekler(fv.segmentler, h.segment || 'kisi', null, 'kod', 'ad')}</select>`)}
        <div class="br-seg" data-seg="kisi">${alan('Kişi seç (Push yalnızca bu kişiye gider)', '<div id="brKisi"></div>')}
          <small class="br-ipucu">Test için kendini bul-seç; "Push Gönder" bildirimini yalnızca ona yollar.</small></div>
        <div class="br-seg" data-seg="gelmeyen">${alan('Kaç gündür gelmeyenler?', `<input name="segment_gun" type="number" min="1" value="${esc(segDeger('gelmeyen') || fv.varsayilan.segment_gun)}">`)}</div>
        <div class="br-seg" data-seg="hizmet">${alan('Hangi hizmeti alanlar?', `<select name="segment_hizmet_id">${secenekler(fv.hizmetler, segDeger('hizmet'), 'Seçiniz')}</select>`)}</div>
        <div class="br-seg" data-seg="urun">${alan('Hangi ürünü alanlar?', `<select name="segment_urun_id">${secenekler(fv.urunler, segDeger('urun'), 'Seçiniz')}</select>`)}</div>
        <div class="br-seg" data-seg="cinsiyet">${alan('Cinsiyet', `<select name="segment_cinsiyet">${secenekler([{ id: 0, ad: 'Kadın' }, { id: 1, ad: 'Erkek' }], segDeger('cinsiyet') ?? 0)}</select>`)}</div>
      </div>

      ${alan('Durum', `<select name="durum">${secenekler(fv.durumlar.filter((d) => d.formda || d.kod === v.durum), v.durum, null, 'kod', 'ad')}</select>`)}
      <div id="brSube"></div>
    </form>`;
}

function formAc(r) {
  const ov = modalAc(r ? 'Reklamı Düzenle' : 'Yeni Bildirim Reklamı', formHtml(r),
    '<button class="rc-btn rc-btn-ikincil" data-kapat>Vazgeç</button><button class="rc-btn rc-btn-birincil" id="brKaydet"><i class="bi bi-check-lg"></i> Kaydet</button>',
    { ikon: 'megaphone', altbaslik: 'Görsel + mesaj hazırla, kanalı ve aksiyonu seç', genis: true, sabit: true });
  const f = el('#brForm', ov);
  el('[data-kapat]', ov).onclick = modalKapat;

  // Görünürlük: aksiyon → kupon / randevu kutuları, hedef → segment kutusu, kupon kapsamı → hizmet/ürün.
  const aksiyon = () => fv.aksiyonlar.find((a) => a.kod === f.aksiyon_tipi.value) || {};
  const gorunum = () => {
    const a = aksiyon();
    el('[data-bolum="kupon"]', f).hidden = !a.kupon;
    el('.br-randevu-not', f).hidden = !(a.kupon && !a.kupon_zorunlu);
    el('[data-bolum="randevu"]', f).hidden = !a.randevu;
    el('[data-bolum="segment"]', f).hidden = f.hedef_kitle.value !== 'segment';
    f.querySelectorAll('.br-seg').forEach((s) => { s.hidden = s.dataset.seg !== f.segment_tip.value; });
    const kt = f.kupon_gecerli_tip.value;
    el('.br-kupon-hizmet', f).hidden = kt === 'urun';
    el('.br-kupon-urun', f).hidden = kt === 'hizmet';
  };
  ['aksiyon_tipi', 'hedef_kitle', 'segment_tip', 'kupon_gecerli_tip'].forEach((n) => f[n].addEventListener('change', gorunum));
  gorunum();

  tarihBagla(f.randevu_tarih);
  tarihBagla(f.randevu_tarih_bit);
  const kisi = musteriSecici(el('#brKisi', f), { salonId, yol: `${YOL}/musteri-ara`, ipucu: 'İsim veya telefon yazın…', baslangic: r?.hedef?.kisi || null });

  // Çoklu şube (ortak seçici): yalnız yeni reklamda; düzenlemede grup korunur (salon_ids gönderilmez).
  const sube = subeSecici(el('#brSube', f), r ? [] : fv.subeler);

  // Görsel: seçilince önizleme + yükleme; dönen yol formun 'gorsel' alanına.
  const kutu = el('#brYukle', f), dosya = el('#brDosya', f);
  kutu.onclick = () => dosya.click();
  dosya.onchange = async () => {
    const d = dosya.files[0];
    if (!d) return;
    kutu.innerHTML = '<span class="rc-yukleniyor"></span>';
    const fd = new FormData();
    fd.append('salon_id', salonId);
    fd.append('gorsel', d);
    const y = await apiForm(`${YOL}/gorsel`, fd);
    if (yazSonuc(y)) {
      f.gorsel.value = y.govde.veri.yol;
      kutu.innerHTML = `<img src="${esc(y.govde.veri.url)}" alt="">`;
    } else {
      kutu.innerHTML = f.gorsel.value && r?.gorsel_url ? `<img src="${esc(r.gorsel_url)}" alt="">` : '<div><i class="bi bi-image"></i><small>Görsel yükle</small></div>';
    }
    dosya.value = '';
  };

  el('#brKaydet', ov).onclick = (e) => kilitle(e.currentTarget, 'Kaydediliyor…', async () => {
    if (!f.baslik.value.trim()) { mesaj('Başlık zorunlu', 'hata'); f.baslik.focus(); return; }
    const govde = formGovdesi(f, kisi, sube);
    const c = r ? await yaz('PATCH', `${YOL}/${r.id}`, govde) : await yaz('POST', YOL, govde);
    if (yazSonuc(c, 'Reklam kaydedildi')) { modalKapat(); yukle(); }
  });
}

/** Form → API gövdesi (alan adları eski web / Flutter v1 ile aynı). */
function formGovdesi(f, kisi, sube) {
  const g = Object.fromEntries(new FormData(f).entries());
  ['kanal_push', 'kanal_inapp', 'tam_ekran'].forEach((k) => { g[k] = f[k].checked; });
  if (g.hedef_kitle === 'segment' && g.segment_tip === 'kisi') g.segment_user_id = kisi.secili()?.id || null;
  const subeler = sube.secili();
  if (subeler.length) g.salon_ids = subeler;
  return g;
}

// ─────────────────────────── açılış ───────────────────────────

(async function baslat() {
  if (!salonId) { kok.innerHTML = '<div class="rc-sekme-bos">Şube seçilmedi.</div>'; return; }
  const r = await oku(`${YOL}/form-verisi`);
  if (!r.ok || !r.govde?.basarili) {
    kok.innerHTML = `<div class="rc-sekme-bos">${esc(r.govde?.mesaj || 'Sayfa yüklenemedi')}</div>`;
    return;
  }
  fv = r.govde.veri;
  iskeletCiz();
  yukle();
})();
