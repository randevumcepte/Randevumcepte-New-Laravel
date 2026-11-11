/**
 * Çağrı Merkezi › YENİ ARAMA LİSTESİ (yönetici; eski modaldialogs/arama_listesi_ekle).
 * Filtreler (seçenekler sunucudan: acilis.liste_filtreleri) → canlı "N müşteri uyuyor" + sayfalı
 * müşteri listesi (isim sırası, sıra no). Seçim: filtreye uyan TÜMÜ · sıra ARALIĞI (ilk 100 /
 * sonraki 100 / 101–200) · ELLE işaretlenenler. Kümeyi sunucu çözer (id listesi taşınmaz).
 * Veri: POST /cagri-merkezi/listeler/onizleme · POST /cagri-merkezi/listeler.
 */
import { el, esc, tarihStr, mesaj, yazSonuc, sayfalamaCiz } from '/js/lib/v2-ortak.js?v=7';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';
import { kilitle } from '/js/lib/v2-bilesen.js?v=7';
import { alan, secenekler, tarihBagla } from '/js/lib/v2-ongorusme.js?v=9';

const BOY = 50;

export function listeOlustur(ctx, { tamam = () => {} } = {}) {
  const f = ctx.acilis.liste_filtreleri;
  const kod = (l) => l.map((x) => ({ id: x.kod, ad: x.ad }));
  const d = { sayfa: 1, ara: '', toplam: 0, secili: new Set(), mod: 'tumu', istek: 0 };
  const sec = (id, liste, bos = 'Tümü') => `<select id="${id}" class="lo-f">${secenekler(kod(liste), null, bos)}</select>`;

  const ov = modalAc('Yeni Arama Listesi', `
    <div class="rc-form-izgara">
      ${alan('Liste başlığı *', '<input id="loBaslik" maxlength="190" placeholder="Örn: 1. Gün Aramaları">')}
      ${alan('Atanacak personel *', `<select id="loPersonel">${secenekler(ctx.acilis.personeller, null, 'Personel seçin…')}</select>`)}
      ${alan('Aranacak tarih', `<input id="loTarih" value="${tarihStr(new Date())}" readonly>`)}
    </div>
    <div class="cm-lo-bolum"><div class="cm-kart-bas"><i class="bi bi-funnel"></i> Filtreler</div>
      <div class="rc-form-izgara">
        ${alan('Kayıt durumu', sec('loKayit', f.kayit))}
        ${alan('Kayıt başlangıç', '<input id="loT1" class="lo-f" readonly>', 'lo-ozel')}
        ${alan('Kayıt bitiş', '<input id="loT2" class="lo-f" readonly>', 'lo-ozel')}
        ${alan('Müşteri durumu', sec('loDurum', f.durum))}
        ${alan('Gelmeyen müşteriler', sec('loGelmeyen', f.gelmeyen))}
        ${alan('Satış durumu', sec('loSatis', f.satis))}
        ${alan('Cinsiyet', sec('loCinsiyet', f.cinsiyet))}
        ${alan('Doğum günü yaklaşan (gün)', '<input id="loDg" class="lo-f" type="number" min="0" max="366" placeholder="örn: 7">')}
      </div>
      <div class="cm-ciplar cm-lo-bayrak">${f.bayraklar.map((b) => `<label class="cm-cip-btn"><input type="checkbox" class="lo-f" data-bayrak="${esc(b.kod)}" ${b.varsayilan ? 'checked' : ''}> ${esc(b.ad)}</label>`).join('')}</div>
    </div>
    <div class="cm-lo-sayac"><b id="loToplam">…</b><span>müşteri seçtiğin filtrelere uyuyor <small class="cm-soluk" id="loFiltresiz"></small></span></div>
    <div class="cm-lo-bolum">
      <div class="cm-kart-bas"><i class="bi bi-check2-square"></i> Listeye eklenecekler</div>
      <div class="cm-lo-mod">
        <label><input type="radio" name="loMod" value="tumu" checked> Filtreye uyan <b>tümü</b></label>
        <label><input type="radio" name="loMod" value="aralik"> Sıra aralığı
          <input id="loBas" type="number" min="1" value="1"> – <input id="loBit" type="number" min="1" value="100">
          <button type="button" class="cm-mini-btn" id="loSonraki">Sonraki 100</button></label>
        <label><input type="radio" name="loMod" value="secili"> Elle seçtiklerim (<b id="loSeciliSay">0</b>)
          <button type="button" class="cm-mini-btn" id="loSayfaSec">Bu sayfayı seç</button>
          <button type="button" class="cm-mini-btn" id="loTemizle">Temizle</button></label>
      </div>
      <div class="cm-ara"><i class="bi bi-search"></i><input id="loAra" placeholder="Listede ad / telefon ara…" autocomplete="off"></div>
      <div id="loListe" class="cm-lo-liste"></div>
      <div class="rc-sayfalama" id="loSayfa"></div>
    </div>`,
  '<button class="rc-btn rc-btn-ikincil" data-kapat>Kapat</button><button class="rc-btn rc-btn-birincil" id="loKaydet"><i class="bi bi-check-circle"></i> Listeyi Oluştur</button>',
  { ikon: 'headset', genis: true, sabit: true, altbaslik: 'Filtrele, seç, personele ata' });
  const $ = (s) => el(s, ov);
  $('[data-kapat]').onclick = modalKapat;
  tarihBagla($('#loTarih'));
  tarihBagla($('#loT1'), { onChange: () => degisti() });
  tarihBagla($('#loT2'), { onChange: () => degisti() });

  const filtre = () => {
    const x = {
      kayit: $('#loKayit').value, kayit_t1: $('#loT1').value, kayit_t2: $('#loT2').value, durum: $('#loDurum').value,
      gelmeyen: $('#loGelmeyen').value, satis: $('#loSatis').value, cinsiyet: $('#loCinsiyet').value, dogumgunu_yaklasan: $('#loDg').value,
    };
    ov.querySelectorAll('[data-bayrak]').forEach((c) => { x[c.dataset.bayrak] = c.checked; });
    return x;
  };
  const modAyarla = (m) => { d.mod = m; ov.querySelector(`[name="loMod"][value="${m}"]`).checked = true; };
  const ozelGoster = () => ov.querySelectorAll('.lo-ozel').forEach((x) => { x.hidden = $('#loKayit').value !== 'ozel'; });
  ozelGoster();

  async function yukle() {
    const no = ++d.istek;
    $('#loListe').innerHTML = '<div class="cm-bos"><span class="rc-yukleniyor"></span></div>';
    const r = await ctx.yaz('POST', '/listeler/onizleme', { filtre: { ...filtre(), ara: d.ara }, page: d.sayfa, per_page: BOY });
    if (no !== d.istek || !ov.isConnected) return;
    if (!r.ok || !r.govde?.basarili) { $('#loListe').innerHTML = `<div class="cm-bos cm-hata">${esc(r.govde?.mesaj || 'Önizleme alınamadı')}</div>`; return; }
    const v = r.govde.veri;
    d.toplam = v.toplam;
    $('#loToplam').textContent = v.toplam.toLocaleString('tr-TR');
    $('#loFiltresiz').textContent = `· salonda aranabilir toplam ${v.toplam_filtresiz.toLocaleString('tr-TR')} · ${v.ozet}`;
    d.sayfaIdler = v.data.map((m) => m.id);
    $('#loListe').innerHTML = v.data.length ? v.data.map((m) => `
      <label class="cm-lo-satir"><input type="checkbox" data-musteri="${m.id}" ${d.secili.has(m.id) ? 'checked' : ''}>
        <span class="cm-lo-sira">${m.sira ?? ''}</span><b>${esc(m.ad)}</b><small>${esc(m.telefon)}</small>
        <small class="cm-soluk">${m.kayit_tarihi ? 'Kayıt ' + esc(m.kayit_tarihi.split('-').reverse().join('.')) : ''}</small></label>`).join('')
      : '<div class="cm-bos"><i class="bi bi-inbox"></i>Filtreye uyan müşteri yok.</div>';
    sayfalamaCiz($('#loSayfa'), v, (p) => { d.sayfa = p; yukle(); }, 'müşteri');
  }
  let zaman;
  const degisti = () => { clearTimeout(zaman); zaman = setTimeout(() => { d.sayfa = 1; d.secili.clear(); $('#loSeciliSay').textContent = 0; yukle(); }, 350); };

  ov.querySelectorAll('.lo-f').forEach((x) => x.addEventListener('change', () => { ozelGoster(); degisti(); }));
  $('#loDg').addEventListener('input', degisti);
  $('#loAra').addEventListener('input', (e) => { clearTimeout(zaman); zaman = setTimeout(() => { d.ara = e.target.value.trim(); d.sayfa = 1; yukle(); }, 350); });
  ov.querySelectorAll('[name="loMod"]').forEach((x) => x.addEventListener('change', () => { d.mod = x.value; }));
  $('#loListe').addEventListener('change', (e) => {
    const c = e.target.closest('[data-musteri]');
    if (!c) return;
    c.checked ? d.secili.add(Number(c.dataset.musteri)) : d.secili.delete(Number(c.dataset.musteri));
    $('#loSeciliSay').textContent = d.secili.size;
    modAyarla('secili');
  });
  $('#loSayfaSec').onclick = () => { (d.sayfaIdler || []).forEach((id) => d.secili.add(id)); $('#loSeciliSay').textContent = d.secili.size; modAyarla('secili'); yukle(); };
  $('#loTemizle').onclick = () => { d.secili.clear(); $('#loSeciliSay').textContent = 0; yukle(); };
  $('#loSonraki').onclick = () => {
    const bit = Number($('#loBit').value) || 0;
    $('#loBas').value = bit + 1;
    $('#loBit').value = bit + 100;
    modAyarla('aralik');
  };
  ['#loBas', '#loBit'].forEach((s) => $(s).addEventListener('input', () => modAyarla('aralik')));

  $('#loKaydet').onclick = (e) => {
    const govde = { baslik: $('#loBaslik').value.trim(), personel_id: Number($('#loPersonel').value) || null, aranacak_tarih: $('#loTarih').value || null, filtre: filtre(), mod: d.mod };
    if (!govde.personel_id) return mesaj('Aramayı yapacak personeli seçin.', 'hata');
    if (!govde.baslik) return mesaj('Liste için bir başlık yazın (örn. 1. Gün).', 'hata');
    if (d.mod === 'secili' && !d.secili.size) return mesaj('En az bir müşteri seçin.', 'hata');
    if (d.mod === 'tumu' && !d.toplam) return mesaj('Filtreye uyan müşteri yok.', 'hata');
    if (d.mod === 'aralik') Object.assign(govde, { bas: Number($('#loBas').value) || 1, bit: Number($('#loBit').value) || 1 });
    if (d.mod === 'secili') govde.ids = [...d.secili];
    kilitle(e.currentTarget, 'Oluşturuluyor…', async () => {
      const r = await ctx.yaz('POST', '/listeler', govde);
      if (yazSonuc(r, r.govde?.mesaj)) { modalKapat(); tamam(r.govde.veri); }
    });
  };

  yukle();
}
