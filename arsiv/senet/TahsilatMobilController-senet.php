<?php

// ARSIV — calismaz, autoload disinda. Senet sistemi kullanimda degil (kullanici 2026-11-08).
// Geri acmak: README.md.
//
// TahsilatMobilController'dan cikarilan senet yazma metotlari (sinif govdesine aynen eklenir):
//
//     /** POST /mobil-para/senet (eski senetekleguncelle) */
//     public function senetEkle(Request $request, MobilSenetServisi $s)
//     {
//         [$uid, $salonId] = $this->giris($request, true);
//
//         return ApiCevap::basarili($s->ekle($salonId, $uid, $request->all()));
//     }
//
//     /** POST /mobil-para/senet-vade/{id}/ode (eski senetode) */
//     public function senetOde(Request $request, int $id, MobilSenetServisi $s)
//     {
//         $uid = $this->kayitGirisi($request, $s->vadeSalonu($id));
//
//         return ApiCevap::basarili($s->ode($id, $uid, $request->all()));
//     }
//
//     /** PATCH /mobil-para/senet-vade/{id} (eski senetvadeguncelle) */
//     public function senetVadeGuncelle(Request $request, int $id, MobilSenetServisi $s)
//     {
//         $uid = $this->kayitGirisi($request, $s->vadeSalonu($id));
//         $s->vadeGuncelle($id, $uid, $request->all());
//
//         return ApiCevap::basarili(['sonuc' => 'Başarılı']);
//     }
//
// routes/v2/mobil-para.php satirlari:
//     Route::post('/senet', [C::class, 'senetEkle']);
//     Route::post('/senet-vade/{id}/ode', [C::class, 'senetOde'])->whereNumber('id');
//     Route::patch('/senet-vade/{id}', [C::class, 'senetVadeGuncelle'])->whereNumber('id');
