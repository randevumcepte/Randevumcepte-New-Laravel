/**
 * Çağrı Merkezi › ÇALIŞMA EKRANI (eski /isletmeyonetim/arama-listelerim).
 * Sol: atanan liste çipleri + müşteri kuyruğu (sunucu araması + durum çipleri); sağ: ortak
 * görüşme kokpiti (cagri-merkezi/ortak.js). Veri: GET /cagri-merkezi/listeler,
 * /listeler/{id}/musteriler, /kayit/{id}. params: {liste_id, aranacak_id} → o müşteriyi aç
 * (hatırlatma "Müşteriye git").
 */
import { esc } from '/js/lib/v2-ortak.js?v=7';
import { gorusmeKokpiti, sonucRozeti, harf } from '/js/cagri-merkezi/ortak.js?v=13';

const SAYFA = 100;

export async function ciz(kap, ctx, params = {}) {
  const d = { listeler: [], liste: null, grup: 'hepsi', ara: '', satirlar: [], gruplar: [], sayfa: 1, son: 1, secili: null, istek: 0 };

  kap.innerHTML = `
    <div class="cm-hero">
      <div class="cm-hstat"><b id="cmHListe">0</b><span>Atanan Liste</span></div>
      <div class="cm-hstat"><b id="cmHToplam">0</b><span>Toplam Müşteri</span></div>
      <div class="cm-hstat"><b id="cmHArandi">0</b><span>Arandı</span></div>
      <div class="cm-hstat"><b id="cmHKalan">0</b><span>Sırada Bekleyen</span></div>
      <p class="cm-hero-not"><i class="bi bi-shield-lock"></i> Numaralar KVKK gereği gizli olabilir — <b>ARA</b> butonuyla santral sizi bağlar.</p>
    </div>
    <div class="cm-izgara">
      <section class="cm-panel cm-sol">
        <div class="cm-panel-bas"><h3><i class="bi bi-list-ul"></i> Müşteri Kuyruğu</h3><span class="cm-sayi" id="cmKSay">0</span></div>
        <div class="cm-listeler" id="cmListeler"><span class="rc-yukleniyor"></span></div>
        <div class="cm-ara"><i class="bi bi-search"></i><input id="cmAra" placeholder="Müşteri ara…" autocomplete="off"></div>
        <div class="cm-ciplar" id="cmGruplar"></div>
        <div class="cm-kuyruk" id="cmKuyruk"><div class="cm-bos"><i class="bi bi-hand-index"></i>Yukarıdan bir liste seçin.</div></div>
      </section>
      <section class="cm-panel cm-sag" id="cmDetay">
        <div class="cm-detay-bos"><i class="bi bi-person-circle"></i><b>Bir müşteri seçin</b><span>Soldaki kuyruktan bir müşteriye tıklayın; arama butonu, görüşme formu ve geçmiş burada açılır.</span></div>
      </section>
    </div>`;
  const $ = (s) => kap.querySelector(s);

  // ── listeler ──
  const r = await ctx.oku('/listeler');
  if (!r.ok || !r.govde?.basarili) { $('#cmListeler').innerHTML = `<span class="cm-hata">${esc(r.govde?.mesaj || 'Listeler yüklenemedi')}</span>`; return; }
  d.listeler = r.govde.veri.kartlar;
  const top = (alan) => d.listeler.reduce((t, k) => t + (Number(k[alan]) || 0), 0);
  $('#cmHListe').textContent = d.listeler.length;
  $('#cmHToplam').textContent = top('toplam');
  $('#cmHArandi').textContent = top('arandi');
  $('#cmHKalan').textContent = top('kalan');
  listeleriCiz();

  $('#cmListeler').addEventListener('click', (e) => {
    const c = e.target.closest('[data-liste]');
    if (c) listeSec(Number(c.dataset.liste));
  });
  $('#cmGruplar').addEventListener('click', (e) => {
    const c = e.target.closest('[data-grup]');
    if (c) { d.grup = c.dataset.grup; kuyrukYukle(); }
  });
  let zaman;
  $('#cmAra').addEventListener('input', (e) => {
    clearTimeout(zaman);
    zaman = setTimeout(() => { const q = e.target.value.trim(); d.ara = q.length >= 2 ? q : ''; kuyrukYukle(); }, 350);
  });
  $('#cmKuyruk').addEventListener('click', (e) => {
    if (e.target.closest('#cmDahaFazla')) { kuyrukYukle({ ekle: true }); return; }
    const m = e.target.closest('[data-kayit]');
    if (m) detayAc(d.satirlar.find((x) => String(x.aranacak_musteri_id) === m.dataset.kayit));
  });

  if (params.aranacak_id) {
    if (params.liste_id && d.listeler.some((l) => l.id === Number(params.liste_id))) await listeSec(Number(params.liste_id), false);
    const t = await ctx.oku(`/kayit/${params.aranacak_id}`);
    if (t.ok && t.govde?.basarili) detayAc(t.govde.veri);
  } else if (d.listeler.length) {
    listeSec(d.listeler[0].id);
  }

  function listeleriCiz() {
    $('#cmListeler').innerHTML = d.listeler.length
      ? d.listeler.map((k) => `<button type="button" class="cm-liste${k.id === d.liste ? ' aktif' : ''}" data-liste="${k.id}">
          ${esc(k.baslik)}<small>${esc(k.kalan)} sırada • ${esc(k.aranacak_tarih || 'Tarih yok')}${ctx.acilis.erisim.yonetici && k.personel ? ' • ' + esc(k.personel) : ''}</small></button>`).join('')
      : '<span class="cm-soluk cm-kucuk">Size atanmış aktif liste yok.</span>';
  }

  async function listeSec(id, ilkiniAc = true) {
    d.liste = id;
    d.grup = 'hepsi';
    d.ara = '';
    $('#cmAra').value = '';
    listeleriCiz();
    await kuyrukYukle({ ilkiniAc });
  }

  async function kuyrukYukle({ ekle = false, ilkiniAc = false, sessiz = false } = {}) {
    if (!d.liste) return;
    const no = ++d.istek;
    d.sayfa = ekle ? d.sayfa + 1 : 1;
    if (!ekle && !sessiz) $('#cmKuyruk').innerHTML = '<div class="cm-bos"><span class="rc-yukleniyor"></span></div>';
    const q = await ctx.oku(`/listeler/${d.liste}/musteriler`, { grup: d.grup, ara: d.ara, page: d.sayfa, per_page: SAYFA });
    if (no !== d.istek) return;   // yarış: liste/arama değişti
    if (!q.ok || !q.govde?.basarili) { $('#cmKuyruk').innerHTML = `<div class="cm-bos cm-hata">${esc(q.govde?.mesaj || 'Müşteriler yüklenemedi')}</div>`; return; }
    const v = q.govde.veri;
    d.satirlar = ekle ? d.satirlar.concat(v.data) : v.data;
    d.gruplar = v.gruplar;
    d.son = v.last_page || 1;
    $('#cmKSay').textContent = v.total ?? d.satirlar.length;
    gruplariCiz();
    kuyrukCiz();
    if (ilkiniAc && d.satirlar.length) detayAc(d.satirlar[0]);
  }

  function gruplariCiz() {
    $('#cmGruplar').innerHTML = d.gruplar.map((g) => `<button type="button" class="cm-cip-btn${g.kod === d.grup ? ' aktif' : ''}" data-grup="${esc(g.kod)}">${esc(g.ad)} (${esc(g.adet)})</button>`).join('');
  }

  function kuyrukCiz() {
    if (!d.satirlar.length) { $('#cmKuyruk').innerHTML = '<div class="cm-bos"><i class="bi bi-inbox"></i>Bu filtreye uygun müşteri yok.</div>'; return; }
    $('#cmKuyruk').innerHTML = d.satirlar.map((m) => `
      <div class="cm-musteri${d.secili === m.aranacak_musteri_id ? ' secili' : ''}" data-kayit="${m.aranacak_musteri_id}">
        <span class="cm-avatar">${harf(m.ad)}</span>
        <span class="cm-mus-orta"><b>${esc(m.ad)}</b><small><i class="bi bi-telephone"></i> ${esc(m.telefonGizlenmis || 'gizli')}</small></span>
        ${sonucRozeti(ctx, m.durum_kod)}
        ${Number(m.randevu_olusturuldu) === 1 ? '<span class="cm-cip cm-cip-mavi" title="Randevu Verildi"><i class="bi bi-calendar2-check"></i></span>' : ''}
      </div>`).join('') + (d.sayfa < d.son ? '<button type="button" class="cm-daha" id="cmDahaFazla">Daha fazla yükle</button>' : '');
  }

  function detayAc(m) {
    if (!m) return;
    d.secili = m.aranacak_musteri_id;
    kuyrukCiz();
    const baslik = m.liste_baslik || d.listeler.find((l) => l.id === d.liste)?.baslik || '';
    gorusmeKokpiti($('#cmDetay'), ctx, m, {
      listeBaslik: baslik,
      // Yazım sonrası: satır rozeti hemen, sayaçlar sessiz tazelenir (kokpit yeniden çizilmez).
      degisti: (k) => {
        const s = d.satirlar.find((x) => x.aranacak_musteri_id === k.aranacak_musteri_id);
        if (s) Object.assign(s, k);
        kuyrukYukle({ sessiz: true });
      },
    });
  }
}
