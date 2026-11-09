/**
 * Senaryo Sihirbazı (v2) — eski senaryo-sihirbaz-modal + senaryoSihirbazi.js karşılığı.
 * Liste (sistem + salonun senaryoları) → hazır senaryodan / sıfırdan oluştur → adım metinleri +
 * aksiyonlar → canlı konuşma önizlemesi (sunucu) → kaydet. Sistem senaryosu salt okunur; "Kopyala"
 * ile salonun kendi senaryosu olarak kaydedilir. Adım/aksiyon sözlüğü ve hazır senaryolar
 * form-verisi'nden (senaryo.*) — istemcide sabit liste yok.
 *
 *   senaryoSihirbazi({ onKaydet })   // onKaydet: şablon listesi tazelensin diye
 */
import { el, esc, mesaj, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=7';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';
import { kilitle } from '/js/lib/v2-bilesen.js?v=7';
import { alan, secenekler } from '/js/lib/v2-ongorusme.js?v=8';
import { baglam as b } from '/js/reklam/ortak.js?v=1';

export async function senaryoSihirbazi({ onKaydet = () => {} } = {}) {
  const r = await b.oku('/kampanya/senaryo');
  if (!r.ok || !r.govde?.basarili) return mesaj(r.govde?.mesaj || 'Senaryolar alınamadı', 'hata');
  const liste = r.govde.veri;
  const presetler = b.fv.senaryo?.presetler || {};

  const kartlar = (l, f) => l.map(f).join('') || '<div class="rk-soluk">Henüz yok.</div>';
  const govde = `
    <div class="rk-bolum-baslik">Yeni senaryo oluştur</div>
    <div class="rk-kartlar rk-kartlar-3">${Object.entries(presetler).map(([kod, p]) => `
      <button type="button" class="rk-kart" data-preset="${esc(kod)}"><span class="rk-emoji">${esc(p.emoji)}</span><b>${esc(p.ad)}</b><small>${esc(p.aciklama)}</small></button>`).join('')}</div>
    <div class="rk-bolum-baslik rk-ust-bosluk">Senaryolarım</div>
    <div class="rk-senaryolar">${kartlar(liste.filter((s) => s.duzenlenebilir), (s) => satir(s))}</div>
    <div class="rk-bolum-baslik rk-ust-bosluk">Hazır (sistem) senaryolar</div>
    <div class="rk-senaryolar">${kartlar(liste.filter((s) => s.sistem_mi), (s) => satir(s))}</div>`;

  function satir(s) {
    const kanal = b.kanal(s.gorev_turu);
    return `<button type="button" class="rk-senaryo" data-senaryo="${s.id}"><i class="bi bi-magic"></i> <b>${esc(s.ad)}</b>
      <small class="rk-soluk">${esc(kanal?.kisa || '')}${s.sistem_mi ? ' · sistem' : ''}</small></button>`;
  }

  const ov = modalAc('Senaryo Sihirbazı', govde, '<button class="rc-btn rc-btn-ikincil" data-kapat>Kapat</button>', { ikon: 'magic', genis: true });
  el('[data-kapat]', ov).onclick = modalKapat;
  ov.addEventListener('click', (e) => {
    const p = e.target.closest('[data-preset]');
    if (p) { const x = presetler[p.dataset.preset]; editor({ id: null, ad: x.ad, senaryo_tipi: p.dataset.preset, gorev_turu: x.gorev_turu, adimlar: x.adimlar, aksiyonlar: x.aksiyonlar, duzenlenebilir: true }); return; }
    const s = e.target.closest('[data-senaryo]');
    if (s) editor(liste.find((x) => String(x.id) === s.dataset.senaryo));
  });

  function editor(s) {
    const sozluk = b.fv.senaryo || { adimlar: [], aksiyonlar: [] };
    const salt = !s.duzenlenebilir;
    const dis = salt ? ' disabled' : '';
    const ov2 = modalAc(s.id ? s.ad : 'Yeni Senaryo', `
      <div class="rk-senaryo-izgara">
        <div>
          ${salt ? '<div class="rk-bilgi"><i class="bi bi-lock"></i> Sistem senaryosu salt okunur — “Kopyala” ile kendi senaryonuz olarak düzenleyin.</div>' : ''}
          <div class="rc-form-izgara">
            ${alan('Senaryo adı *', `<input id="sAd" maxlength="150" value="${esc(s.ad)}"${dis}>`)}
            ${alan('Kanal', `<select id="sKanal"${dis}>${secenekler(b.fv.kanallar, s.gorev_turu, null, 'kod', 'ad')}</select>`)}
          </div>
          <div id="sAdimlar"></div>
          <div class="rk-aksiyonlar" id="sAksiyon">${sozluk.aksiyonlar.map((a) => `
            <label class="rk-aksiyon"><span class="rc-switch"><input type="checkbox" data-aksiyon="${esc(a.kod)}"${s.aksiyonlar?.[a.kod] ? ' checked' : ''}${dis}><span class="rc-kaydir"></span></span> ${esc(a.ad)}</label>`).join('')}</div>
          <div class="rk-cipler rk-yer">${b.fv.yer_tutucular.map((y) => `<span class="rk-cip" title="${esc(y.aciklama)}">${esc(y.kod)}</span>`).join('')}</div>
        </div>
        <div class="rk-sohbet"><div class="rk-onizleme-bas"><i class="bi bi-chat-dots"></i> Konuşma önizlemesi</div><div id="sDiyalog"></div></div>
      </div>`,
    `<button class="rc-btn rc-btn-ikincil" data-geri>Geri</button>
     ${s.id && s.duzenlenebilir ? '<button class="rc-btn rc-btn-ikincil rc-tehlike" id="sSil"><i class="bi bi-trash"></i> Sil</button>' : ''}
     ${b.yetki('ekle_duzenle') ? `<button class="rc-btn rc-btn-yesil" id="sKaydet"><i class="bi bi-check-lg"></i> ${salt ? 'Kopyala' : 'Kaydet'}</button>` : ''}`,
    { ikon: 'magic', genis: true, sabit: true });
    el('[data-geri]', ov2).onclick = () => senaryoSihirbazi({ onKaydet });

    const adimlar = { ...(s.adimlar || {}) };
    const aksiyonlar = () => Object.fromEntries([...ov2.querySelectorAll('[data-aksiyon]')].map((i) => [i.dataset.aksiyon, i.checked]));
    const kanal = () => Number(el('#sKanal', ov2).value);

    function adimCiz() {
      const arama = kanal() === 1;
      el('#sAksiyon', ov2).hidden = !arama;
      el('#sAdimlar', ov2).innerHTML = sozluk.adimlar.filter((a) => arama || a.kod === 'acilis').map((a) => alan(esc(a.ad),
        `<textarea rows="2" maxlength="1000" data-adim="${esc(a.kod)}"${dis}>${esc(adimlar[a.kod] || '')}</textarea>`, 'rc-tam')).join('');
    }

    let zaman, no = 0;
    const onizle = () => {
      clearTimeout(zaman);
      const n = ++no;
      zaman = setTimeout(async () => {
        const c = await b.yaz('POST', '/kampanya/senaryo/onizle', { adimlar, aksiyonlar: aksiyonlar(), gorev_turu: kanal() });
        if (n !== no) return;
        el('#sDiyalog', ov2).innerHTML = c.ok && c.govde?.basarili
          ? c.govde.veri.diyalog.map((d) => `<div class="rk-mesaj rk-mesaj-${esc(d.kim)}">${esc(d.metin)}</div>`).join('')
          : `<div class="rk-soluk">${esc(c.govde?.mesaj || 'Önizleme alınamadı')}</div>`;
      }, 350);
    };

    ov2.addEventListener('input', (e) => { const a = e.target.dataset.adim; if (a) { adimlar[a] = e.target.value; onizle(); } });
    ov2.addEventListener('change', (e) => { if (e.target.id === 'sKanal') adimCiz(); onizle(); });

    el('#sKaydet', ov2)?.addEventListener('click', (e) => {
      const ad = el('#sAd', ov2).value.trim();
      if (!ad) return mesaj('Senaryo adı zorunlu.', 'hata');
      kilitle(e.currentTarget, 'Kaydediliyor…', async () => {
        const govdeS = { ad: salt ? `${ad} (kopya)` : ad, senaryo_tipi: s.senaryo_tipi, gorev_turu: kanal(), adimlar, aksiyonlar: aksiyonlar() };
        const c = await b.yaz(s.id && !salt ? 'PATCH' : 'POST', s.id && !salt ? `/kampanya/senaryo/${s.id}` : '/kampanya/senaryo', govdeS);
        if (!yazSonuc(c, c.govde?.mesaj)) return;
        await onKaydet();
        senaryoSihirbazi({ onKaydet });
      });
    });
    el('#sSil', ov2)?.addEventListener('click', async () => {
      if (!(await onay(`“${s.ad}” senaryosu silinsin mi?`, { tehlike: true }))) return;
      const c = await b.yaz('DELETE', `/kampanya/senaryo/${s.id}`);
      if (yazSonuc(c, 'Senaryo silindi')) { await onKaydet(); senaryoSihirbazi({ onKaydet }); }
    });

    adimCiz();
    onizle();
  }
}
