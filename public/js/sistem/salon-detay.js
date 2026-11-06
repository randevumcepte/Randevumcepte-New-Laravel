/** Salon detayi: uyelik, saglik, isletme bilgileri, notlar, talepler, hesaplar. Veri /api/v2/sistem/salon/{id}. */
import { el, esc, onay } from '/js/lib/v2-ortak.js?v=2';
import { syVeri, syYaz, syIndir, ekran, kayitId, tarih, tarihSaat } from '/js/lib/v2-sistem.js?v=1';
import { salonSecenekleri, secenekler, ilIlceBagla, formOku, telLink } from '/js/sistem/salon-ortak.js?v=1';
import { bos, hesabinaGir } from '/js/sistem/ortak.js?v=1';

const kok = ekran();
const id = kayitId();
const SALON = `/salon/${id}`;
const SKOR_RENK = { kritik: 'danger', riskli: 'warning', orta: 'info', iyi: 'success' };
const YETKILI_ROZET = ['Hesap Sahibi', 'Yönetici'];

let sec = null;
let izin = {};

const girdi = (ad, deger, ek = '') => `<input type="text" name="${ad}" class="sy-input" value="${esc(deger)}" ${ek}>`;
const alan = (etiket, icerik) => `<div class="sy-form-group"><label>${etiket}</label>${icerik}</div>`;

function basCiz(d) {
  const s = d.salon;
  const askida = Number(s.askiya_alindi) === 1;
  const i = d.iletisim;
  return `
    <div class="sy-page-head">
      <div>
        <h2 class="sd-baslik">${esc(s.salon_adi)} <span class="sy-badge sy-badge-${askida ? 'danger' : 'success'}">${askida ? 'Askıda' : 'Aktif'}</span></h2>
        ${i.ad || i.telefon ? `<div class="sd-iletisim">
          ${i.ad ? `<span class="sd-kisi"><span class="mdi mdi-account"></span>${esc(i.ad)}</span>` : ''}
          ${i.telefon ? `<a class="sd-telefon" href="tel:${esc(telLink(i.telefon))}"><span class="mdi mdi-phone"></span>${esc(i.telefon)}</a>` : ''}
        </div>` : ''}
        <div class="subtitle sd-alt">${esc(d.il_adi || '')} / ${esc(d.ilce_adi || '')} · ID: ${s.id} · Kayıt: ${tarih(s.created_at)}</div>
      </div>
      <div class="sy-flex-row">
        <a href="/sistemyonetim/salonlar" class="sy-btn"><span class="mdi mdi-arrow-left"></span> Liste</a>
        ${izin.duzenle ? '<button type="button" class="sy-btn sy-btn-success" data-musteri-indir><span class="mdi mdi-microsoft-excel"></span> Müşterileri Excel\'e Aktar</button>' : ''}
        ${izin.hesaba_gir ? `<button type="button" class="sy-btn sy-btn-primary" data-gir data-sebep="Destek girişi"${askida ? ' disabled title="Salon askıda"' : ''}><span class="mdi mdi-login"></span> Salonun Hesabına Gir</button>` : ''}
      </div>
    </div>
    ${askida ? `<div class="sy-alert sy-alert-warning"><strong>Bu salon askıda.</strong>
      ${s.askiya_alma_sebebi ? ` Sebep: ${esc(s.askiya_alma_sebebi)}` : ''}
      ${izin.duzenle ? `<button type="button" class="sy-btn sy-btn-sm sy-btn-success sd-aktif" data-yaz="POST|${SALON}/aktif-et">Aktif Et</button>` : ''}</div>` : ''}`;
}

function uyelikKart(d) {
  const u = d.uyelik;
  const sureler = sec.hizli_sureler[d.demo ? 'demo' : 'lisansli'];
  return `
    <div class="sy-card sy-mt-12" style="border-left:4px solid var(--sy-${esc(u.renk)})"><div class="sy-card-body">
      <div class="sd-uyelik">
        <div class="sd-uyelik-sol">
          <div class="sy-text-muted sy-fs-12 sd-buyuk-harf"><span class="mdi mdi-clock-outline"></span> ${d.demo ? 'Demo / Üyelik Bitişi' : 'Lisans / Üyelik Bitişi'}</div>
          <div class="sd-satir">
            <span class="sd-bitis" style="color:var(--sy-${esc(u.renk)})">${u.bitis ? tarih(u.bitis) : '— tanımsız'}</span>
            ${u.bitis ? `<span class="sy-badge sy-badge-${esc(u.renk)}">${esc(u.metin)}</span>` : ''}
          </div>
        </div>
        <div class="sd-sureler">
          ${sureler.map((q) => `<button type="button" class="sy-btn sy-btn-sm sy-btn-${esc(q.stil)}" data-yaz="POST|${SALON}/sure-uzat" data-govde="${esc(JSON.stringify({ gun: q.gun }))}">${esc(q.etiket)}</button>`).join('')}
          <span class="sy-text-muted sd-ayrac">|</span>
          <form class="sd-tarih-form" data-yaz="POST|${SALON}/sure-uzat">
            <input type="text" name="tarih" class="sy-input sd-tarih" value="${esc(u.bitis || '')}" autocomplete="off">
            <button type="submit" class="sy-btn sy-btn-sm sy-btn-success">Ayarla</button>
          </form>
        </div>
      </div>
      <div class="sd-lisans">
        ${d.demo ? `<span class="sy-badge sy-badge-warning">DEMO HESAP</span>
          <form class="sd-tarih-form" data-yaz="POST|${SALON}/lisans-aktif" data-onay="Bu demo salonun lisansı aktif edilecek (Lisanslı olacak). Devam?">
            <span class="sy-text-muted sy-fs-12">Lisans süresi:</span>
            <select name="gun" class="sy-select sd-oto">${secenekler(sec.lisans_sureleri, 'gun', 'etiket', 365)}</select>
            <button type="submit" class="sy-btn sy-btn-sm sy-btn-success"><span class="mdi mdi-check-decagram"></span> Lisansı Aktif Et</button>
          </form>` : `<span class="sy-badge sy-badge-success">AKTİF</span>
          <span class="sy-text-muted sy-fs-12">Bu salon aktif/lisanslı — süreyi yukarıdan uzatabilirsiniz.</span>`}
        <button type="button" class="sy-btn sy-btn-sm sy-btn-danger sd-sag" data-yaz="POST|${SALON}/lisans-kapat" data-tehlike="1"
          data-onay="Bu salonun lisansı KAPATILACAK. Salon hemen 'lisans süreniz bitmiştir' ekranını görecek ve panele giremeyecek. Devam?"><span class="mdi mdi-lock"></span> Lisansı Kapat / Kısıtla</button>
      </div>
    </div></div>`;
}

function paketKart() {
  const paketler = sec.paketler.map((p) => ({ ad: p }));
  return `
    <div class="sy-card sy-mt-12 sd-paket-kart"><div class="sy-card-body">
      <div class="sy-text-muted sy-fs-12 sd-buyuk-harf sd-paket-bas"><span class="mdi mdi-cash-register"></span> Paketli Üyelik Uzatma &amp; Tahsilat</div>
      <form class="sd-paket" data-yaz="POST|${SALON}/paketli-uzat" data-onay="Üyelik paketli olarak uzatılacak ve yetkiliye WhatsApp/SMS bilgilendirme gidecek. Devam?">
        <div><label class="sy-text-muted sy-fs-12">Paket</label><select name="paket" class="sy-select" required>${secenekler(paketler, 'ad', 'ad', 'Standart')}</select></div>
        <div><label class="sy-text-muted sy-fs-12">Periyot</label><select name="periyot" class="sy-select">${secenekler(sec.periyotlar, 'kod', 'etiket', 'yillik')}</select></div>
        <div><label class="sy-text-muted sy-fs-12">Süre (adet)</label><input type="number" name="adet" class="sy-input sd-sayi" value="1" min="1" step="1"></div>
        <div><label class="sy-text-muted sy-fs-12">Hediye ay</label><input type="number" name="hediye_ay" class="sy-input sd-sayi" value="0" min="0" step="1"></div>
        <div><label class="sy-text-muted sy-fs-12">Ücret (₺)</label><input type="number" name="ucret" class="sy-input sd-tutar" min="0" step="0.01" placeholder="0,00"></div>
        <label class="sd-kutu"><input type="checkbox" name="kdv_dahil"> KDV dahil</label>
        <button type="submit" class="sy-btn sy-btn-sm sy-btn-primary"><span class="mdi mdi-check-bold"></span> Uzat &amp; Bilgilendir</button>
      </form>
      <div class="sy-text-muted sy-fs-12 sy-mt-12">Yıllık seçiminde "Süre" yıl, Aylık seçiminde ay sayısıdır. Hediye ay toplam süreye eklenir (örn. Yıllık × 1 + 4 hediye = 16 ay). Mevcut bitiş ileride ise kalan süre korunur, üzerine eklenir.</div>
    </div></div>`;
}

function saglikKart(g) {
  const renk = SKOR_RENK[g.durum] || 'muted';
  return `
    <div class="sy-card sy-mt-12" style="border-left:4px solid var(--sy-${renk})"><div class="sy-card-body">
      <div class="sy-flex-row sd-saglik">
        <div>
          <div class="sy-text-muted sy-fs-12 sd-buyuk-harf">Sağlık Skoru</div>
          <div class="sd-satir"><span class="sd-skor" style="color:var(--sy-${renk})">${g.skor}</span><span class="sy-text-muted">/100</span><span class="sy-badge sy-badge-${renk}">${esc(g.durum)}</span></div>
        </div>
        <div class="sd-saglik-sag">
          <div class="sy-progress sd-cubuk"><div class="fill" style="width:${g.skor}%;background:var(--sy-${renk})"></div></div>
          ${g.sebepler.length ? `<ul class="sy-text-muted sy-fs-12 sd-sebepler">${g.sebepler.map((s) => `<li>${esc(s)}</li>`).join('')}</ul>` : '<div class="sy-text-muted sy-fs-12 sy-mt-12">Salon sağlıklı çalışıyor.</div>'}
        </div>
      </div>
    </div></div>`;
}

function metrikler(i) {
  const m = (sinif, ikon, etiket, deger) => `<div class="sy-metric ${sinif}"><div class="icon-bg mdi ${ikon}"></div><div class="label">${etiket}</div><div class="value">${deger}</div></div>`;
  return `<div class="sy-metric-grid sy-mt-12">
    ${m('', 'mdi-calendar-multiple', 'Toplam Randevu', i.toplam_randevu)}
    ${m('info', 'mdi-calendar-month', 'Bu Ay', i.bu_ay_randevu)}
    ${m('success', 'mdi-account-multiple-plus', 'Bu Ay Yeni Müşteri', i.bu_ay_yeni_musteri)}
    ${m(i.whatsapp_aktif ? 'success' : '', 'mdi-whatsapp', 'WhatsApp', i.whatsapp_aktif ? 'Aktif' : 'Pasif')}
  </div>`;
}

function bilgiFormu(s) {
  const surum = (ad, deger) => girdi(ad, deger, 'maxlength="5" placeholder="1.0.0"');
  return `
    <div class="sy-card sy-mt-12">
      <div class="sy-card-head"><h3><span class="mdi mdi-store-edit"></span> İşletme Bilgileri</h3><span class="sy-text-muted sy-fs-12">Tüm temel bilgileri buradan güncelleyebilirsiniz</span></div>
      <div class="sy-card-body"><form data-yaz="PUT|${SALON}/bilgi">
        ${alan('Salon Adı', girdi('salon_adi', s.salon_adi, 'required'))}
        <div class="sy-form-row">
          ${alan('İl', `<select name="il_id" id="sdIl" class="sy-select">${secenekler(sec.iller, 'id', 'il_adi', s.il_id, 'Seçiniz')}</select>`)}
          ${alan('İlçe', '<select name="ilce_id" id="sdIlce" class="sy-select"></select>')}
        </div>
        <div class="sy-form-row">
          ${alan('İşletme Türü', `<select name="salon_turu_id" class="sy-select">${secenekler(sec.salon_turleri, 'id', 'salon_turu_adi', s.salon_turu_id, 'Seçiniz')}</select>`)}
          ${alan('Domain <span class="sy-text-muted sy-fs-12">(online randevu mini web sitesi)</span>', girdi('domain', s.domain, 'placeholder="ornek.randevumcepte.com.tr"'))}
        </div>
        ${alan('Adres', `<textarea name="adres" class="sy-textarea" rows="2">${esc(s.adres)}</textarea>`)}
        <div class="sy-form-row">
          ${alan('İşletme Telefon 1', girdi('telefon_1', s.telefon_1))}${alan('İşletme Telefon 2', girdi('telefon_2', s.telefon_2))}${alan('İşletme Telefon 3', girdi('telefon_3', s.telefon_3))}
        </div>
        <div class="sy-form-row">
          ${alan('Yetkili Adı', girdi('yetkili_adi', s.yetkili_adi))}${alan('Yetkili Telefon', girdi('yetkili_telefon', s.yetkili_telefon))}
          ${alan('Yetkili Mail', `<input type="email" name="yetkili_mail" class="sy-input" value="${esc(s.yetkili_mail)}">`)}
        </div>
        ${alan('Açıklama', `<textarea name="aciklama" class="sy-textarea" rows="2">${esc(s.aciklama)}</textarea>`)}
        <div class="sd-bolum"></div>
        <div class="sy-text-muted sy-fs-12 sd-buyuk-harf sd-paket-bas">Mobil Uygulama Linkleri</div>
        ${alan('<span class="mdi mdi-android"></span> Android Uygulama Linki', girdi('android_uygulama', s.android_uygulama, 'placeholder="https://play.google.com/..."'))}
        ${alan('<span class="mdi mdi-apple"></span> iOS Uygulama Linki', girdi('ios_uygulama', s.ios_uygulama, 'placeholder="https://apps.apple.com/..."'))}
        ${alan('<span class="mdi mdi-cellphone"></span> Huawei Uygulama Linki', girdi('huawei_uygulama', s.huawei_uygulama, 'placeholder="https://appgallery.huawei.com/..."'))}
        <div class="sy-form-row">
          ${alan('Android Son Versiyon', surum('android_son_versiyon', s.android_son_versiyon))}${alan('iOS Son Versiyon', surum('ios_son_versiyon', s.ios_son_versiyon))}${alan('Huawei Son Versiyon', surum('huawei_son_versiyon', s.huawei_son_versiyon))}
        </div>
        <div class="sy-flex-row sd-sona"><button class="sy-btn sy-btn-primary"><span class="mdi mdi-content-save"></span> Bilgileri Kaydet</button></div>
      </form></div>
    </div>`;
}

function notKart(n) {
  return `
    <div class="sy-not-card tip-${esc(n.tip)} ${n.sabit ? 'pinned' : ''}">
      <div class="head">
        <div><strong>${esc(n.yazan)}</strong> · <span class="sy-badge sy-badge-muted">${esc(n.tip)}</span>
          ${n.sabit ? '<span class="sy-badge sy-badge-warning"><span class="mdi mdi-pin"></span> Sabit</span>' : ''}</div>
        <div class="sy-text-soft">${esc(n.ne_zaman)}</div>
      </div>
      ${n.baslik ? `<div class="baslik">${esc(n.baslik)}</div>` : ''}
      <div class="sd-not-icerik">${esc(n.icerik)}</div>
      ${izin.not_yaz ? `<div class="sy-flex-row sy-mt-12 sd-sona">
        <button type="button" class="sy-btn sy-btn-sm" data-yaz="POST|/salon/not/${n.id}/pin">${n.sabit ? 'Sabitten Kaldır' : 'Sabitle'}</button>
        ${n.silinebilir ? `<button type="button" class="sy-btn sy-btn-sm sy-btn-danger" data-yaz="DELETE|/salon/not/${n.id}" data-onay="Bu not silinsin mi?" data-tehlike="1"><span class="mdi mdi-delete"></span></button>` : ''}
      </div>` : ''}
    </div>`;
}

function notlarKart(notlar) {
  return `
    <div class="sy-card">
      <div class="sy-card-head"><h3><span class="mdi mdi-note-multiple"></span> İç Notlar</h3><span class="sy-text-muted sy-fs-12">${notlar.length} not</span></div>
      <div class="sy-card-body">
        ${izin.not_yaz ? `<form class="sy-stack sd-not-form" data-yaz="POST|${SALON}/not">
          <div class="sy-form-row">
            <div class="sy-form-group sd-sifir"><label>Başlık (opsiyonel)</label><input type="text" name="baslik" class="sy-input" placeholder="Kısa başlık" maxlength="200"></div>
            <div class="sy-form-group sd-sifir"><label>Tip</label><select name="tip" class="sy-select">${secenekler(sec.not_tipleri, 'kod', 'etiket', 'genel')}</select></div>
          </div>
          <div class="sy-form-group sd-sifir"><textarea name="icerik" class="sy-textarea" rows="3" placeholder="Salon hakkında not..." required></textarea></div>
          <div class="sy-flex-row sd-arasi">
            <label class="sy-fs-13 sd-kutu"><input type="checkbox" name="pinned"> Sabitle</label>
            <button class="sy-btn sy-btn-primary"><span class="mdi mdi-plus"></span> Not Ekle</button>
          </div>
        </form>` : ''}
        ${notlar.length ? notlar.map(notKart).join('') : bos('mdi-note-outline', 'Henüz not yok')}
      </div>
    </div>`;
}

function taleplerKart(talepler) {
  const rozet = (t) => (t.durum === 'cozumlendi' || t.durum === 'kapali' ? 'success' : (t.oncelik === 'acil' ? 'danger' : 'info'));
  const kisa = (m) => (String(m).length > 70 ? `${String(m).slice(0, 70)}...` : String(m));
  return `
    <div class="sy-card">
      <div class="sy-card-head"><h3><span class="mdi mdi-lifebuoy"></span> Bu Salonun Talepleri</h3>
        ${izin.not_yaz ? `<a href="/sistemyonetim/ticket/yeni?salon_id=${id}" class="sy-btn sy-btn-sm sy-btn-soft"><span class="mdi mdi-plus"></span></a>` : ''}</div>
      <div class="sy-card-body tight">
        ${talepler.length ? talepler.map((t) => `<a class="sd-talep" href="/sistemyonetim/ticket/${t.id}">
          <div class="sy-flex-row sd-arasi"><strong>${esc(t.numara)}</strong><span class="sy-badge sy-badge-${rozet(t)}">${esc(t.durum)}</span></div>
          <div class="sy-fs-13">${esc(kisa(t.konu))}</div>
          <div class="sy-text-muted sy-fs-12">${tarihSaat(t.created_at)}</div></a>`).join('') : bos('', 'Talep yok')}
      </div>
    </div>`;
}

function iletisimKart(s) {
  const b = (etiket, deger, sinif = '') => `<div><span class="sy-text-muted sy-fs-12">${etiket}</span><div class="${sinif}">${esc(deger || '—')}</div></div>`;
  return `
    <div class="sy-card"><div class="sy-card-head"><h3><span class="mdi mdi-card-account-details"></span> İletişim</h3></div>
      <div class="sy-card-body"><div class="sy-stack sd-dar">
        ${b('Yetkili Adı', s.yetkili_adi, 'sy-fw-600')}${b('Yetkili Telefon', s.yetkili_telefon)}${b('İşletme Telefon', s.telefon_1)}${b('Adres', s.adres, 'sy-fs-13')}
      </div></div></div>`;
}

function hesapSatiri(y, askida) {
  const rolSinif = YETKILI_ROZET.includes(y.rol_adi) ? 'sy-badge-info' : 'sy-badge-muted';
  const hesapPasif = `POST|${SALON}/hesap-pasif`;
  return `
    <div class="sd-hesap"><div class="sy-flex-row sd-arasi sd-hesap-ic">
      <div class="sd-hesap-sol">
        <div class="sy-fw-600">${esc(y.ad)} <span class="sy-badge ${rolSinif}">${esc(y.rol_adi)}</span>
          ${y.aktif ? '' : '<span class="sy-badge sy-badge-danger">İşten Ayrılmış</span>'}</div>
        <div class="sy-text-muted sy-fs-12">${esc(y.email || '—')}</div>
        <div class="sy-text-muted sy-fs-12"><span class="mdi mdi-phone"></span>
          ${y.telefon ? `<a class="sl-tel" href="tel:${esc(telLink(y.telefon))}">${esc(y.telefon)}</a>` : ' —'}</div>
      </div>
      <div class="sd-hesap-dugmeler">
        ${izin.hesaba_gir ? `<button type="button" class="sy-btn sy-btn-sm sy-btn-soft" data-gir data-yetkili="${y.id}" data-sebep="${y.sahip ? 'Sahip hesabı girişi' : 'Personel hesabı girişi'}"
          data-onay="Bu ${y.sahip ? 'sahip' : 'personel'} hesabına geçiş yapılacak. Tüm hareketleriniz loglanacaktır. Devam edilsin mi?"${askida ? ' disabled title="Salon askıda"' : ''}><span class="mdi mdi-login"></span> Bu hesapla gir</button>` : ''}
        ${izin.sahip_yap ? `<button type="button" class="sy-btn sy-btn-sm sy-btn-warning" title="Bu hesabı Hesap Sahibi (rol 1) yap — yanlışlıkla personele düşen sahibi kurtarır"
          data-yaz="POST|${SALON}/sahip-yap" data-govde="${esc(JSON.stringify({ yetkili_id: y.id }))}" data-onay="${esc(y.ad)} bu salonda Hesap Sahibi (rol 1) yapılacak. Devam?"><span class="mdi mdi-crown"></span> Hesap Sahibi Yap</button>` : ''}
        ${!izin.duzenle ? '' : (y.aktif
    ? `<button type="button" class="sy-btn sy-btn-sm" title="İşten çıkar (pasif yap)" data-yaz="${hesapPasif}" data-govde="${esc(JSON.stringify({ yetkili_id: y.id, aktif: 0 }))}" data-tehlike="1"
          data-onay="${esc(y.ad)} işten çıkarılacak (pasif). Giriş varsayılanı artık bu hesabı atlar. Devam?"><span class="mdi mdi-account-off"></span> İşten Çıkar</button>`
    : `<button type="button" class="sy-btn sy-btn-sm sy-btn-success" title="Tekrar aktif et" data-yaz="${hesapPasif}" data-govde="${esc(JSON.stringify({ yetkili_id: y.id, aktif: 1 }))}"><span class="mdi mdi-account-check"></span> Geri Al</button>`)}
      </div>
    </div></div>`;
}

function yonetimKartlari(s, askida) {
  if (!izin.duzenle) return '';
  return `
    <div class="sy-card"><div class="sy-card-head"><h3><span class="mdi mdi-account-tie"></span> Müşteri Temsilcisi</h3></div>
      <div class="sy-card-body"><form data-yaz="POST|${SALON}/mt-ata">
        <div class="sy-form-group"><select name="musteri_yetkili_id" class="sy-select">${secenekler(sec.temsilciler, 'id', 'name', s.musteri_yetkili_id, 'Atama yok')}</select></div>
        <button class="sy-btn sy-btn-primary sy-btn-sm">Kaydet</button>
      </form></div></div>
    ${askida ? '' : `<div class="sy-card"><div class="sy-card-head"><h3 class="sd-tehlike"><span class="mdi mdi-cancel"></span> Salonu Askıya Al</h3></div>
      <div class="sy-card-body"><form data-yaz="POST|${SALON}/askiya-al" data-onay="Salon askıya alınacak. Hesaba giriş engellenecek. Devam?" data-tehlike="1">
        <textarea name="sebep" class="sy-textarea" rows="2" placeholder="Sebep" maxlength="255" required></textarea>
        <button class="sy-btn sy-btn-danger sy-mt-12 sy-btn-sm">Askıya Al</button>
      </form></div></div>`}`;
}

function ciz(d) {
  const s = d.salon;
  const askida = Number(s.askiya_alindi) === 1;
  kok.innerHTML = `
    ${basCiz(d)}
    ${izin.duzenle ? uyelikKart(d) + paketKart() : ''}
    ${saglikKart(d.saglik)}
    ${metrikler(d.istatistik)}
    ${izin.duzenle ? bilgiFormu(s) : ''}
    <div class="sy-grid-2-1 sy-mt-12">
      <div class="sy-stack">${notlarKart(d.notlar)}${taleplerKart(d.talepler)}</div>
      <div class="sy-stack">
        ${izin.duzenle ? '' : iletisimKart(s)}
        <div class="sy-card"><div class="sy-card-head"><h3><span class="mdi mdi-account-key"></span> İşletme &amp; Personel Hesapları</h3></div>
          <div class="sy-card-body tight">${d.yetkililer.length ? d.yetkililer.map((y) => hesapSatiri(y, askida)).join('') : bos('', 'Hesap yok')}</div></div>
        ${yonetimKartlari(s, askida)}
        <div class="sy-card"><div class="sy-card-head"><h3><span class="mdi mdi-history"></span> Son Hesap Girişleri</h3></div>
          <div class="sy-card-body tight">${d.girisler.length ? d.girisler.map((g) => `<div class="sd-giris"><strong>${esc(g.user_name)}</strong> · ${tarihSaat(g.baslangic_tarihi)}<div class="sy-text-muted sy-fs-12">${esc(g.sebep || '—')}</div></div>`).join('') : bos('', 'Henüz giriş yok')}</div></div>
      </div>
    </div>`;

  if (izin.duzenle) {
    ilIlceBagla(el('#sdIl'), el('#sdIlce'), sec.ilceler, s.ilce_id);
    const tarihGirdisi = el('.sd-tarih');
    if (window.flatpickr) window.flatpickr(tarihGirdisi, { locale: 'tr', dateFormat: 'Y-m-d', altInput: true, altFormat: 'd.m.Y', altInputClass: 'sy-input sd-tarih', disableMobile: true });
  }
}

async function yukle() {
  const d = await syVeri(SALON);
  if (d) ciz(d);
}

/** data-yaz="METOD|yol": onay varsa sor, yaz, ekrani yenile. */
async function yaz(oge, govde) {
  if (oge.dataset.onay && !await onay(oge.dataset.onay, { tehlike: oge.dataset.tehlike === '1' })) return;
  const [metod, yol] = oge.dataset.yaz.split('|');
  const { tamam } = await syYaz(metod, yol, govde);
  if (tamam) yukle();
}

async function hesabaGec(d) {
  if (d.dataset.onay && !await onay(d.dataset.onay, { tehlike: false })) return;
  hesabinaGir(id, d.dataset.sebep, { yetkiliId: Number(d.dataset.yetkili) || null });
}

function olaylariBagla() {
  kok.addEventListener('submit', (e) => {
    const form = e.target.closest('form[data-yaz]');
    if (!form) return;
    e.preventDefault();
    yaz(form, formOku(form));
  });
  kok.addEventListener('click', (e) => {
    const dugme = e.target.closest('button[data-yaz]');
    if (dugme) { yaz(dugme, dugme.dataset.govde ? JSON.parse(dugme.dataset.govde) : {}); return; }
    const gir = e.target.closest('button[data-gir]');
    if (gir) { hesabaGec(gir); return; }
    if (e.target.closest('[data-musteri-indir]')) syIndir(`${SALON}/musteriler/csv`, {}, `musteriler-${id}.csv`);
  });
}

sec = await salonSecenekleri();
if (sec) {
  izin = sec.izinler;
  olaylariBagla();
  await yukle();
}
