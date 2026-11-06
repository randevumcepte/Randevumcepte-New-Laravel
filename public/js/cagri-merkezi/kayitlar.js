/**
 * Çağrı Merkezi › ÇAĞRI KAYITLARI (eski /isletmeyonetim/arama-cagri-kayitlari).
 * Santralin canlı CDR'ından gerçek çağrı geçmişi + ses kayıtları; numara arama listesindeki
 * müşteriyle eşleşirse ad + son görüşme notu. Tablo ortak veriTablosu (10/25/50/100, tablo içi
 * arama, sayfalama SUNUCUDA). Personel yalnız kendi dahilisini görür; maske sunucuda.
 * Veri: GET /cagri-merkezi/cagri-kayitlari · "Ara": POST /cagri-merkezi/ara {user_id|numara}.
 */
import { esc, tarihStr } from '/js/lib/v2-ortak.js?v=2';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=2';
import { ozetKart, kilitle } from '/js/lib/v2-bilesen.js?v=2';
import { tarihBagla } from '/js/lib/v2-ongorusme.js?v=1';
import { sure, sesOynatici, aramaBaslat } from '/js/cagri-merkezi/ortak.js?v=6';

const YONLER = [{ kod: '', ad: 'Tümü', ikon: 'bi-telephone' }, { kod: 'gelen', ad: 'Gelen', ikon: 'bi-telephone-inbound' }, { kod: 'giden', ad: 'Giden', ikon: 'bi-telephone-outbound' }];

export async function ciz(kap, ctx) {
  const bugun = new Date();
  const d = { yon: '', tarih1: tarihStr(new Date(bugun.getTime() - 30 * 864e5)), tarih2: tarihStr(bugun), satirlar: [] };
  const dahili = ctx.acilis.erisim.dahili_var;

  kap.innerHTML = `
    <div class="cm-filtre">
      <label>Başlangıç <input id="cmT1" value="${d.tarih1}" readonly></label>
      <label>Bitiş <input id="cmT2" value="${d.tarih2}" readonly></label>
      <div class="cm-ciplar" id="cmYon"></div>
    </div>
    <div class="rc-ozet" id="cmKOzet"></div>
    <div class="cm-uyari" id="cmKUyari" hidden></div>
    <div id="cmKTablo"></div>`;
  const $ = (s) => kap.querySelector(s);
  const yonCiz = () => { $('#cmYon').innerHTML = YONLER.map((y) => `<button type="button" class="cm-cip-btn${d.yon === y.kod ? ' aktif' : ''}" data-yon="${y.kod}"><i class="bi ${y.ikon}"></i> ${y.ad}</button>`).join(''); };
  yonCiz();

  const tablo = veriTablosu($('#cmKTablo'), {
    anahtar: 'cagri_kayitlari', birim: 'çağrı', varsayilanBoy: 25, bosMesaj: 'Bu aralıkta çağrı kaydı yok.',
    araIpucu: 'Müşteri adı veya numara…',
    kolonlar: [{ baslik: 'Tarih' }, { baslik: 'Yön' }, { baslik: 'Müşteri' }, { baslik: 'Telefon' }, { baslik: 'Süre', sinif: 'rc-sag' },
      { baslik: 'Son Görüşme Notu' }, { baslik: 'Ses Kaydı' }, { baslik: '', sinif: 'rc-orta' }],
    kaynak: (p) => ctx.oku('/cagri-kayitlari', { ...p, yon: d.yon, tarih1: d.tarih1, tarih2: d.tarih2 }),
    satir: (s, i) => `<tr>
      <td class="rc-nowrap">${esc(s.tarih)}</td>
      <td><span class="rc-rozet rc-rozet-${s.yon === 'gelen' ? 'yesil' : 'mor'}"><i class="bi bi-telephone-${s.yon === 'gelen' ? 'inbound' : 'outbound'}"></i> ${s.yon === 'gelen' ? 'Gelen' : 'Giden'}</span></td>
      <td>${s.kayitli ? `<a class="rc-rd-musteri" href="/v2/musteri/${s.user_id}?sube=${ctx.salonId}" target="_blank" rel="noopener">${esc(s.ad)}</a>` : '<span class="cm-soluk">Kayıtsız numara</span>'}</td>
      <td class="rc-nowrap">${esc(s.telefon)}</td>
      <td class="rc-sag rc-nowrap">${sure(s.sure_sn)}</td>
      <td class="cm-not-hucre">${esc(s.not || '')}</td>
      <td>${sesOynatici(s.ses) || '<span class="cm-soluk">—</span>'}</td>
      <td class="rc-orta">${dahili && (s.kayitli || ctx.acilis.erisim.telefon_gor) ? `<button type="button" class="rc-islem rc-olumlu" data-ara="${i}" title="Ara"><i class="bi bi-telephone-fill"></i></button>` : ''}</td>
    </tr>`,
    cizildi: (liste, v) => {
      d.satirlar = liste;
      const o = v?.ozet || {};
      $('#cmKOzet').innerHTML = ozetKart('bi-telephone', 'Toplam Çağrı', o.toplam ?? 0) + ozetKart('bi-telephone-inbound', 'Gelen', o.gelen ?? 0, 'yesil')
        + ozetKart('bi-telephone-outbound', 'Giden', o.giden ?? 0, 'mavi') + ozetKart('bi-soundwave', 'Ses Kayıtlı', o.kayitli ?? 0, 'turuncu');
      const uyari = v?.hata ? 'Santrale şu an ulaşılamıyor; liste eksik olabilir.' : (v?.kesildi ? 'Aralıkta çok fazla çağrı var; en yeni kayıtlar gösteriliyor — aralığı daraltın.' : '');
      $('#cmKUyari').hidden = !uyari;
      $('#cmKUyari').textContent = uyari;
    },
  });

  tarihBagla($('#cmT1'), { onChange: (_, s) => { d.tarih1 = s; tablo.yenile(); } });
  tarihBagla($('#cmT2'), { onChange: (_, s) => { d.tarih2 = s; tablo.yenile(); } });
  $('#cmYon').addEventListener('click', (e) => {
    const b = e.target.closest('[data-yon]');
    if (b) { d.yon = b.dataset.yon; yonCiz(); tablo.yenile(); }
  });
  $('#cmKTablo').addEventListener('click', (e) => {
    const b = e.target.closest('[data-ara]');
    const s = b && d.satirlar[Number(b.dataset.ara)];
    if (!s) return;
    kilitle(b, '', () => aramaBaslat(ctx, '/ara', s.kayitli ? { user_id: s.user_id } : { numara: s.telefon }));
  });
  tablo.yenile();
}
