/**
 * Ayarlar → takvim kaynağı (Cihazlar / Odalar) sekmesi — iki sekmenin ORTAK tablo + işlem
 * akışı (eski panelde cihaz_* / oda_* iki kopyaydı). Türe özel olanlar yapılandırmayla gelir:
 *   { yol, etiket, ikon, anahtar, ekKolonlar:[{baslik, hucre(k)}], formGovde(detay, formVerisi), formOku(ov) }
 * Uçlar: GET {yol} (veriTablosu sözleşmesi), GET {yol}/form-verisi, GET/PATCH/DELETE {yol}/{id},
 * PATCH {yol}/{id}/durum, PATCH {yol}/{id}/sira, POST {yol}.
 */
import { api, el, esc, onay, yazSonuc } from '/js/lib/v2-ortak.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=8';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';

export function kaynakSekmesi(cfg) {
  return {
    async ciz(kap, ctx) {
      const P = { salon_id: ctx.salonId };
      kap.innerHTML = `
        <div class="rc-sayfa-ust ay-kaynak-ust">
          <p class="ay-kart-alt">Takvimdeki sırası oklarla değiştirilebilir; "Müsait Değil" işaretlenen ${esc(cfg.etiket.toLocaleLowerCase('tr'))} randevuya kapanır.</p>
          <div class="rc-sayfa-aksiyon"><button class="rc-btn rc-btn-birincil" data-yeni><i class="bi bi-plus-lg"></i> Yeni ${esc(cfg.etiket)}</button></div>
        </div>
        <div data-tablo></div>`;

      const tablo = veriTablosu(el('[data-tablo]', kap), {
        anahtar: cfg.anahtar,
        varsayilanBoy: 25,
        birim: cfg.etiket.toLocaleLowerCase('tr'),
        araIpucu: `${cfg.etiket} adı, açıklama…`,
        bosMesaj: `Henüz ${cfg.etiket.toLocaleLowerCase('tr')} eklenmemiş.`,
        kolonlar: [
          { baslik: 'Sıra', sinif: 'rc-orta' }, { baslik: `${cfg.etiket} Adı`, sirala: 'ad' },
          ...cfg.ekKolonlar.map((k) => ({ baslik: k.baslik })),
          { baslik: 'Durum', sirala: 'durum_etiket' }, { baslik: 'Açıklama' }, { baslik: 'İşlemler', sinif: 'rc-orta' },
        ],
        satir: (k) => `<tr data-id="${k.id}">
          <td class="rc-orta"><span class="rc-islemler">
            <button class="rc-islem" data-sira="-1" ${k.yukari ? '' : 'disabled'} title="Yukarı"><i class="bi bi-chevron-up"></i></button>
            <button class="rc-islem" data-sira="1" ${k.asagi ? '' : 'disabled'} title="Aşağı"><i class="bi bi-chevron-down"></i></button></span></td>
          <td><b>${esc(k.ad)}</b></td>
          ${cfg.ekKolonlar.map((c) => `<td>${c.hucre(k)}</td>`).join('')}
          <td><span class="rc-rozet rc-rozet-${k.musait ? 'yesil' : 'kirmizi'}">${esc(k.durum_etiket)}</span></td>
          <td>${esc(k.aciklama || '—')}</td>
          <td class="rc-orta"><span class="rc-islemler">
            ${k.musait
              ? '<button class="rc-islem" data-durum="0" title="Müsait Değil"><i class="bi bi-slash-circle"></i></button>'
              : '<button class="rc-islem rc-olumlu" data-durum="1" title="Müsait"><i class="bi bi-check2-circle"></i></button>'}
            <button class="rc-islem" data-duzenle title="Düzenle"><i class="bi bi-pencil"></i></button>
            <button class="rc-islem rc-tehlike" data-sil title="Sil"><i class="bi bi-trash"></i></button></span></td>
        </tr>`,
        kaynak: (p) => api('GET', cfg.yol, { params: { ...p, ...P } }),
      });
      tablo.yenile();

      const yaz = async (method, yol, body, params) => {
        const r = await api(method, yol, { body, params });
        if (yazSonuc(r, r.govde?.mesaj)) { tablo.yenile(false); return r.govde.veri; }
        return null;
      };

      kap.addEventListener('click', async (e) => {
        if (e.target.closest('[data-yeni]')) return formAc(null);
        const tr = e.target.closest('tr[data-id]');
        if (!tr) return;
        const id = tr.dataset.id;
        const b = e.target.closest('button');
        if (!b || b.disabled) return;
        if (b.dataset.sira) return yaz('PATCH', `${cfg.yol}/${id}/sira`, { ...P, delta: Number(b.dataset.sira) });
        if (b.dataset.durum === '1') return yaz('PATCH', `${cfg.yol}/${id}/durum`, { ...P, musait: true });
        if (b.dataset.durum === '0') return musaitDegilAc(id);
        if (b.hasAttribute('data-duzenle')) return formAc(id);
        if (b.hasAttribute('data-sil') && await onay(`Bu ${cfg.etiket.toLocaleLowerCase('tr')} kaldırılsın mı? Takvimden de kalkar.`)) {
          return yaz('DELETE', `${cfg.yol}/${id}`, undefined, P);
        }
      });

      function musaitDegilAc(id) {
        const ov = modalAc(`${cfg.etiket} Müsait Değil`, `
          <div class="rc-alan rc-tam"><label>Açıklama</label><textarea id="ayAciklama" rows="3" maxlength="500" placeholder="Örn: bakımda, arızalı…"></textarea></div>`,
          '<button class="rc-btn rc-btn-ikincil" data-iptal>Vazgeç</button><button class="rc-btn rc-btn-birincil" data-tamam>Kaydet</button>',
          { ikon: 'slash-circle', dar: true });
        el('[data-iptal]', ov).onclick = modalKapat;
        el('[data-tamam]', ov).onclick = async () => {
          if (await yaz('PATCH', `${cfg.yol}/${id}/durum`, { ...P, musait: false, aciklama: el('#ayAciklama', ov).value })) modalKapat();
        };
      }

      async function formAc(id) {
        const [d, f] = await Promise.all([
          id ? api('GET', `${cfg.yol}/${id}`, { params: P }) : Promise.resolve(null),
          api('GET', `${cfg.yol}/form-verisi`, { params: P }),
        ]);
        if ((d && !yazSonuc(d)) || !yazSonuc(f)) return;
        const detay = d ? d.govde.veri : null;
        const ov = modalAc(id ? `${cfg.etiket} Düzenle` : `Yeni ${cfg.etiket}`, `<form novalidate>${cfg.formGovde(detay, f.govde.veri)}</form>`,
          '<button class="rc-btn rc-btn-ikincil" data-iptal>Vazgeç</button><button class="rc-btn rc-btn-birincil" data-tamam><i class="bi bi-check2"></i> Kaydet</button>',
          { ikon: cfg.ikon, genis: !!cfg.genisForm, sabit: true });
        if (cfg.formBagla) cfg.formBagla(ov);
        el('[data-iptal]', ov).onclick = modalKapat;
        el('[data-tamam]', ov).onclick = async (e) => {
          e.currentTarget.disabled = true;
          const ok = await yaz(id ? 'PATCH' : 'POST', id ? `${cfg.yol}/${id}` : cfg.yol, { ...P, ...cfg.formOku(ov) });
          if (ok) modalKapat(); else e.currentTarget.disabled = false;
        };
      }
    },
  };
}
