<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Stok\Tanim\DepoServisi;
use App\Services\Stok\Tanim\KategoriServisi;
use App\Services\Stok\Tanim\ReceteServisi;
use App\Services\Stok\Tanim\TanimServisi;
use App\Services\Stok\Tanim\TedarikciServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Stok TANIM uclari — dort tanim tek controller, tek akis (servis TanimServisi sablonu):
 *   GET    /stok/{tur}?salon_id=[&hizmet_id=&hizmet_tipi=]
 *   POST   /stok/{tur}        {salon_id, ...}
 *   PATCH  /stok/{tur}/{id}
 *   DELETE /stok/{tur}/{id}   (pasif)
 * tur: kategoriler | depolar | tedarikciler | receteler
 */
class StokTanimController extends StokTabanController
{
    public const TURLER = [
        'kategoriler'  => KategoriServisi::class,
        'depolar'      => DepoServisi::class,
        'tedarikciler' => TedarikciServisi::class,
        'receteler'    => ReceteServisi::class,
    ];

    private const MESAJ = [
        'kategoriler' => 'Kategori', 'depolar' => 'Depo', 'tedarikciler' => 'Tedarikçi', 'receteler' => 'Reçete',
    ];

    public function liste(Request $request, string $tur)
    {
        [, $salonId] = $this->salonYetki($request, self::GORUNTULE);

        return ApiCevap::basarili($this->servis($tur)->listele($salonId, $request->only(['hizmet_id', 'hizmet_tipi'])));
    }

    public function ekle(Request $request, string $tur)
    {
        $s = $this->servis($tur);
        [$uid, $salonId] = $this->salonYetki($request, $s->yazmaYetkisi());
        $this->yazilabilir();

        return ApiCevap::basarili($s->kaydet($salonId, null, $request->except(['salon_id', 'sube']), $uid), self::MESAJ[$tur].' eklendi.', 201);
    }

    public function guncelle(Request $request, string $tur, int $id)
    {
        $s = $this->servis($tur);
        [$uid, $salonId] = $this->kayitYetki($s->kaynak(), $id, $s->yazmaYetkisi());
        $this->yazilabilir();

        return ApiCevap::basarili($s->kaydet($salonId, $id, $request->except(['salon_id', 'sube']), $uid), self::MESAJ[$tur].' güncellendi.');
    }

    public function sil(string $tur, int $id)
    {
        $s = $this->servis($tur);
        [$uid] = $this->kayitYetki($s->kaynak(), $id, $s->yazmaYetkisi());
        $this->yazilabilir();

        return ApiCevap::basarili($s->sil($id, $uid), self::MESAJ[$tur].' silindi.');
    }

    private function servis(string $tur): TanimServisi
    {
        return app(self::TURLER[$tur]);
    }
}
