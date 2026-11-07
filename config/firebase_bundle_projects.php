<?php

/**
 * app_bundle -> Firebase service account JSON eşlemesi.
 *
 * NEDEN: "Genel" uygulama beyaz-etiket DEĞİL; tek bir app_bundle
 * (com.randevumcepte.randevumcepte) TÜM standart/başlangıç (uyelik 1/2)
 * işletmeleri tarafından paylaşılır. Bu yüzden Firebase projesi salon-bazlı
 * (salonlar.firebase_profile) değil, BUNDLE-bazlı çözülmeli: bundle projeyi
 * deterministik belirler, her yeni işletme için ayrı ayar (SQL) gerekmez.
 *
 * PushBildirimi her mobil token için önce buraya bakar; app_bundle
 * burada eşleşiyorsa mobil push o projeden gider. Eşleşmeyen bundle'lar
 * (marka appler) eski firebase_profile mantığına düşer — geriye dönük uyumlu.
 *
 * Marka appler için buraya EKLEME YAPMA; onlar salonlar.firebase_profile ile
 * yönetilir. Buraya yalnızca "bir bundle = tek proje, çok işletme" durumları girer.
 *
 * NOT: Config anahtarında nokta (.) Laravel'de iç içe anahtar sayılır; bu
 * yüzden okurken config('firebase_bundle_projects') dizisi alınıp bundle ile
 * indexlenir, config('firebase_bundle_projects.com.x.y') KULLANILMAZ.
 */
return [
    // Genel (ortak) app — randevumcepte-uygulamalar projesi.
    // iOS GoogleService-Info + Android google-services AYNI projeden olmalı
    // (project_id = randevumcepte-uygulamalar), yoksa SENDER_ID_MISMATCH olur.
    'com.randevumcepte.randevumcepte' => 'app/firebase/randevumcepte-uygulamalar-0d38a7fc2d78.json',
];
