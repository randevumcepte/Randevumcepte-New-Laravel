/** Hazir cevaplar: liste + ekle/duzenle penceresi. */
import { el, esc, onay } from '/js/lib/v2-ortak.js?v=2';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=1';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=2';
import { ekran, syApi, syVeri, syYaz } from '/js/lib/v2-sistem.js?v=1';
import { aktifRozet, kilitli, etiketRozet, sayfaBaslik, secenekHtml } from '/js/sistem/ortak.js?v=1';

let s = null;
let tablo = null;
let satirlar = [];

function formAc(k) {
  const govde = `
    <div class="rc-form-izgara">
      <div class="rc-alan"><label>Başlık *</label><input id="hBaslik" maxlength="200" placeholder="Örn: WhatsApp aktivasyonu" value="${esc(k?.baslik || '')}"></div>
      <div class="rc-alan"><label>Kategori *</label><select id="hKategori">${secenekHtml(s.kategoriler, k?.kategori.kod ?? s.varsayilan_kategori)}</select></div>
      <div class="rc-alan rc-tam"><label>Kısayol <small>(opsiyonel)</small></label><input id="hKisayol" maxlength="30" placeholder="/wa-aktif" value="${esc(k?.kisayol || '')}">
        <span class="rc-ipucu">Yanıt formunda hızlı bulmak için (örn: /wa-aktif)</span></div>
      <div class="rc-alan rc-tam"><label>İçerik *</label><textarea id="hIcerik" rows="10"></textarea></div>
      <div class="rc-tam"><label class="sy-flex-row"><input type="checkbox" id="hAktif"${k && !k.aktif ? '' : ' checked'}> Aktif (yanıt formunda görünsün)</label></div>
    </div>`;
  const alt = `<button type="button" class="rc-btn rc-btn-ikincil" id="hVazgec">İptal</button>
    <button type="button" class="rc-btn rc-btn-birincil" id="hKaydet"><i class="bi bi-check-lg"></i> Kaydet</button>`;
  const ov = modalAc(k ? `${k.baslik} — Düzenle` : 'Yeni Hazır Cevap', govde, alt, {
    ikon: 'chat-left-text', sabit: true, altbaslik: 'Şablon değişkenler: {salon_adi}, {musteri_adi}, {ekip_adi}',
  });
  el('#hIcerik', ov).value = k?.icerik || '';
  el('#hVazgec', ov).onclick = modalKapat;
  el('#hKaydet', ov).onclick = (e) => kilitli(e.currentTarget, async () => {
    const govdeVeri = {
      baslik: el('#hBaslik', ov).value.trim(),
      kategori: el('#hKategori', ov).value,
      kisayol: el('#hKisayol', ov).value.trim(),
      icerik: el('#hIcerik', ov).value,
      aktif: el('#hAktif', ov).checked,
    };
    const { tamam } = await syYaz(k ? 'PUT' : 'POST', k ? `/hazir-cevap/${k.id}` : '/hazir-cevap', govdeVeri);
    if (tamam) { modalKapat(); tablo.yenile(false); }
  });
}

async function sil(k) {
  if (!await onay('Bu hazır cevap silinsin mi?', { baslik: 'Hazır Cevabı Sil', tamam: 'Sil' })) return;
  const { tamam } = await syYaz('DELETE', `/hazir-cevap/${k.id}`);
  if (tamam) tablo.yenile(false);
}

async function baslat() {
  s = await syVeri('/hazir-cevap/secenekler');
  if (!s) return;

  ekran().innerHTML = `
    ${sayfaBaslik('Hazır Cevaplar', 'Ticket yanıtlarken tek tıkla kullanabileceğiniz şablon mesajlar',
    s.yazabilir ? '<button type="button" class="sy-btn sy-btn-primary" id="hYeni"><span class="mdi mdi-plus"></span> Yeni Cevap</button>' : '')}
    <div class="sy-card"><div class="sy-card-body" id="hTablo"></div></div>`;

  const kap = el('#hTablo');
  tablo = veriTablosu(kap, {
    anahtar: 'sy_hazir_cevap',
    varsayilanBoy: 25,
    birim: 'cevap',
    araIpucu: 'Başlık, kısayol, içerik...',
    bosMesaj: 'Hazır cevap yok. İlk cevabı ekleyin, ticket yanıtlarken kullanın.',
    sirala: { alan: 'kullanim', yon: 'desc' },
    kolonlar: [
      { baslik: 'Başlık', sirala: 'baslik' }, { baslik: 'Kategori', sirala: 'kategori' }, { baslik: 'Kısayol', sirala: 'kisayol' },
      { baslik: 'İçerik (önizleme)' }, { baslik: 'Kullanım', sirala: 'kullanim', sinif: 'rc-sag' }, { baslik: 'Durum', sirala: 'durum' },
      ...(s.yazabilir ? [{ baslik: 'İşlem', sinif: 'rc-sag' }] : []),
    ],
    satir: (k) => `<tr>
      <td><strong>${esc(k.baslik)}</strong></td>
      <td>${etiketRozet(k.kategori)}</td>
      <td><code>${esc(k.kisayol || '—')}</code></td>
      <td class="sy-fs-13 sy-text-muted">${esc(k.ozet)}</td>
      <td class="rc-sag"><strong>${k.kullanim_sayisi}</strong></td>
      <td>${aktifRozet(k.aktif)}</td>
      ${s.yazabilir ? `<td class="rc-sag">
        <button type="button" class="sy-btn sy-btn-sm sy-btn-soft" data-duzenle="${k.id}" title="Düzenle"><span class="mdi mdi-pencil"></span></button>
        <button type="button" class="sy-btn sy-btn-sm sy-btn-danger" data-sil="${k.id}" title="Sil"><span class="mdi mdi-delete"></span></button>
      </td>` : ''}
    </tr>`,
    kaynak: (p) => syApi('GET', '/hazir-cevap', { params: p }),
    cizildi: (liste) => { satirlar = liste; },
  });

  kap.addEventListener('click', (e) => {
    const b = e.target.closest('[data-duzenle],[data-sil]');
    if (!b) return;
    const k = satirlar.find((x) => String(x.id) === (b.dataset.duzenle ?? b.dataset.sil));
    if (b.dataset.duzenle) formAc(k); else sil(k);
  });
  el('#hYeni')?.addEventListener('click', () => formAc(null));
  tablo.yenile();
}

baslat();
