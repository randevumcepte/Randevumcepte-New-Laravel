/**
 * Log Hareketleri (v2) — build'siz island. Eski /isletmeyonetim/log-hareketleri ekranının v2
 * karşılığı: renk açıklaması (tıklanınca kategori filtresi), İşlem / Kullanıcı / Hedef / Kaynak /
 * tarih aralığı filtreleri, tablo içi arama, 10/25/50/100 sayfalama, satır detayı (meta).
 * Tüm çizim ortak lib/v2-hareket.js'te (başka ekranlara gömülen hareket tablolarıyla aynı kod).
 */
import { qp } from '/js/lib/v2-ortak.js?v=7';
import { hareketTablosu } from '/js/lib/v2-hareket.js?v=7';

const kok = document.getElementById('rc-hareket');
const salonId = parseInt(kok.dataset.salon, 10) || parseInt(qp.get('sube'), 10) || 0;

kok.innerHTML = `
  <div class="hk-kabuk">
    <div class="rc-sayfa-ust">
      <div class="rc-sayfa-baslik">
        <span class="rc-sayfa-ikon"><i class="bi bi-clock-history"></i></span>
        <div><h1>Log Hareketleri</h1><div class="rc-sayfa-alt">İşletmenizde yapılan tüm işlemleri (kim · ne · ne zaman) buradan takip edin.</div></div>
      </div>
    </div>
    <div id="hkIcerik"></div>
  </div>`;

if (window.rcPanel?.salonAyarla && salonId) window.rcPanel.salonAyarla(salonId);
hareketTablosu(document.getElementById('hkIcerik'), { salonId, anahtar: 'log_hareketleri' });
