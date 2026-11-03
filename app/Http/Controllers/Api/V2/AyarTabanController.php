<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Ayar\AyarSozluk;
use App\Exceptions\GecersizIslemException;
use App\Services\Yetki\GuvenlikKapisi;
use Illuminate\Http\Request;

/**
 * Ayar controller'larinin ortak tabani — sekme yetkisi (AyarSozluk) + yazma kill-switch'i
 * (config('ayar.yazma_aktif')) TEK yerde. Is mantigi Services/Ayar altinda.
 */
abstract class AyarTabanController extends V2Controller
{
    protected ?string $yazmaAlani = 'ayar';

    protected string $yazmaKapaliMesaj = 'Ayar kaydı henüz etkin değil (AYAR_V2_YAZMA_AKTIF kapalı)';

    /**
     * Yalnizca kimlik + kapsam (yetki anahtari aranmaz) — salonun her uyesinin gorebilecegi
     * veriler (ozet, tema) icin.
     *
     * @return array{0:int,1:int} [yetkili id, salon id]
     */
    protected function kapsamSalonu(Request $request): array
    {
        $yetkiliId = (int) $this->kullanici()->id;
        $salonId = $request->integer('salon_id');
        if (! $salonId) {
            throw new GecersizIslemException('salon_id zorunlu');
        }
        app(GuvenlikKapisi::class)->kapsamDenetle($yetkiliId, $salonId);

        return [$yetkiliId, $salonId];
    }

    /** @return int salon id (kimlik + kapsam + sekme yetkisi) */
    protected function okumaSalonu(Request $request, string $sekme): int
    {
        return $this->salonDenetle($request, AyarSozluk::yetki($sekme))[1];
    }

    /** @return array{0:int,1:int} [yetkili id, salon id] — sekme yetkisi + kill-switch */
    protected function yazmaSalonu(Request $request, string $sekme): array
    {
        return $this->yetkiliYazma($request, AyarSozluk::yetki($sekme));
    }

    /**
     * Sekmeden daha dar yetki isteyen yazmalar (orn. Hizmetler: tanim_olustur / kategori_yonet ayri).
     *
     * @param  array<string>|string  $yetki
     * @return array{0:int,1:int} [yetkili id, salon id]
     */
    protected function yetkiliYazma(Request $request, array|string $yetki): array
    {
        $kimlik = $this->salonDenetle($request, $yetki);
        $this->yazmaAcikMi();

        return $kimlik;
    }
}
