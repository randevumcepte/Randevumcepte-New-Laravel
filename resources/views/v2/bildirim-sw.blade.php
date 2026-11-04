/* v2 panel FCM service worker. Görünen bildirimi SDK çizer (FCM "notification" alanı) ve tıklamayı
   webpush.fcm_options.link'e yollar; burada yalnız veri mesajı (data-only) çizilir, aksi halde çift bildirim çıkar. */
importScripts('https://www.gstatic.com/firebasejs/10.13.2/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.13.2/firebase-messaging-compat.js');

firebase.initializeApp(@json($ayar));

firebase.messaging().onBackgroundMessage((payload) => {
  if (payload.notification) return;
  const d = payload.data || {};
  self.registration.showNotification(d.title || 'Bildirim', { body: d.body || '', data: d });
});
