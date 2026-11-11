/**
 * Seans Takibi (v2) — build'siz island (sade ES module).
 *
 * Eski /isletmeyonetim/seanstakip ekranının v2 karşılığı: sayfa başlığı + ortak seans
 * bileşeni (lib/v2-seans.js — müşteri detay "Seanslar" sekmesi de aynısını kullanır).
 * Veri /api/v2/seans* (web + Flutter AYNI uç). Yazmalar SEANS_V2_YAZMA_AKTIF kapalıyken
 * 409 döner; UI toast gösterir.
 */
import { qp } from '/js/lib/v2-ortak.js?v=7';
import { seansTakibi } from '/js/lib/v2-seans.js?v=9';

const kok = document.getElementById('rc-seans-takibi');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;
if (salonId && window.rcPanel) window.rcPanel.salonAyarla(salonId);

kok.innerHTML = `
  <div class="rc-sayfa-ust">
    <div class="rc-sayfa-baslik">
      <span class="rc-sayfa-ikon"><i class="bi bi-check2-square"></i></span>
      <div><h1>Seans Takibi</h1><div class="rc-sayfa-alt">Seanslı paket ve hizmet satışlarının kullanım durumu</div></div>
    </div>
  </div>
  <div class="st-kap"></div>`;

const kap = kok.querySelector('.st-kap');
if (salonId) seansTakibi(kap, { salonId });
else kap.innerHTML = '<div class="st-bos">Şube seçili değil.</div>';
