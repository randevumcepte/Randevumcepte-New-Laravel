<?php

/*
 | Lisans kapisi (App\Http\Middleware\LisansKapisi). Suresi biten salonun isletme uclari 402 +
 | 'lisans_suresi_doldu' doner. Bitis tarihi her istekte veritabanindan okunur; uzatma aninda acilir.
 */
return [
    // Acil durum anahtari: false yapilirsa kapi hic denetlemez (eski davranis: lisans hic kapatmaz).
    'aktif' => (bool) env('LISANS_KAPISI_AKTIF', true),

    /*
     | Lisans bitse de acik kalan isletme uclari (api/v2/ sonrasi, Str::is deseni):
     |  oturum      giris, yenile, cikis
     |  kabuk       panel kabugu (menu, salon adi, sube secici): lisans sayfasi kabukla cizilir
     |  hesabim     uyelik, alinan hizmetler, fatura bilgisi ve faturalar (lisans durumu okuma)
     |  profil      kendi profili
     |  personel-yetki/benim  kendi yetkileri (mobil kabuk acilista tazeler)
     |  bildirim/cihaz        push cihaz kaydi / silme (cikista eski cihaz kalmasin)
     | Sistem yonetimi uclari ayri guard'dadir (sistemyonetim-api) ve kapsam disidir; "salon hesabina
     | gir" (impersonation) token'i kapidan gecer.
     | Muaf uc istek ozniteligiyle (LisansKapisi::OZNITELIK = muaf) isaretlenir; salon erisiminin tek gecidi
     | (GuvenlikKapisi::kapsamDenetle) bu isarette lisansa bakmaz, diger isletme isteklerinde erisilen salonu
     | her cagrida taze denetler (kayit id'li uclar istekteki salon_id ile atlatilamaz).
     */
    'muaf_yollar' => [
        'oturum/*',
        'kabuk',
        'hesabim',
        'hesabim/*',
        'profil',
        'profil/*',
        'personel-yetki/benim',
        'bildirim/cihaz',
    ],
];
