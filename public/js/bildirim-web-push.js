/**
 * Tarayıcı push kaydı — eski layout'taki Firebase bloğunun v2 karşılığı. Ayar /api/v2/bildirim/web-ayar'dan;
 * sunucuda tanımlı değilse hiçbir şey yüklenmez. Token + salon aynıysa aynı oturumda tekrar yazılmaz.
 */
import { api, mesaj } from '/js/lib/v2-ortak.js?v=1';

const FIREBASE = 'https://www.gstatic.com/firebasejs/10.13.2';
const CIHAZ_ANAHTARI = 'rdv_device_id'; // eski panelle aynı: aynı tarayıcı tek cihaz sayılır
const KAYIT_ANAHTARI = 'rc_push_kayit';

function cihazId() {
  let id = localStorage.getItem(CIHAZ_ANAHTARI);
  if (!id) {
    id = crypto.randomUUID();
    localStorage.setItem(CIHAZ_ANAHTARI, id);
  }
  return id;
}

let _mesajlasma = null;

async function mesajlasma(ayar) {
  if (_mesajlasma) return _mesajlasma;
  const [{ initializeApp }, fm] = await Promise.all([
    import(`${FIREBASE}/firebase-app.js`),
    import(`${FIREBASE}/firebase-messaging.js`),
  ]);
  const { vapidKey, ...firebaseAyar } = ayar;
  const m = fm.getMessaging(initializeApp(firebaseAyar));
  // Sayfa açıkken gelen bildirim: toast + zil sayacı.
  fm.onMessage(m, (p) => {
    if (p.notification?.title) mesaj(p.notification.title);
    document.dispatchEvent(new CustomEvent('rc:bildirim'));
  });
  _mesajlasma = { m, getToken: fm.getToken, vapidKey };
  return _mesajlasma;
}

export async function webPushKur(salonId) {
  if (!('serviceWorker' in navigator) || !('Notification' in window)) return;
  const r = await api('GET', '/bildirim/web-ayar');
  const ayar = r.ok ? r.govde?.veri : null;
  if (!ayar || await Notification.requestPermission() !== 'granted') return;

  const { m, getToken, vapidKey } = await mesajlasma(ayar);
  const kayit = await navigator.serviceWorker.register('/bildirim-sw', { scope: '/' });
  const token = await getToken(m, { vapidKey, serviceWorkerRegistration: kayit });
  if (!token) return;

  const damga = `${salonId}:${token}`;
  if (sessionStorage.getItem(KAYIT_ANAHTARI) === damga) return;
  const k = await api('POST', '/bildirim/cihaz', { params: { salon_id: salonId }, body: { token, platform: 'web', cihaz: cihazId() } });
  if (k.ok) sessionStorage.setItem(KAYIT_ANAHTARI, damga);
}
