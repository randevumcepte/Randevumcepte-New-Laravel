<?php

namespace App\Services\Lisans;

use App\Domain\Lisans\LisansKurali;
use App\Exceptions\LisansKapaliException;
use App\Http\Middleware\LisansKapisi;
use App\Services\Yetki\KapsamServisi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Isletme yetkilisinin istegi lisansi kapali bir salona mi gidiyor? Bitis tarihi HER cagrida salonlar tablosundan
 * okunur; onbellek yoktur. Boylece sistem yonetimi sureyi uzattigi anda ayni token'la gelen bir sonraki istek gecer
 * ("hizmet disi" takili kalmaz).
 *
 * Iki kapi, tek kural (LisansKurali):
 *   - kapaliSalon(): middleware (LisansKapisi) istekteki salon alanlarina ve kullanicinin bagli salonlarina bakar.
 *   - erisimDenetle(): salon erisiminin tek gecidi (GuvenlikKapisi::kapsamDenetle) erisilen salonu denetler. Kayit
 *     id'li uclar salonu kayittan aldigi icin istekteki salon_id ile oynamak lisansi atlatamaz.
 */
class LisansServisi
{
    public function __construct(private KapsamServisi $kapsam) {}

    /**
     * @param  int[]  $istenenler  istekteki salon alanlari (LisansKapisi::istenenSalonlar)
     * @return array{kod:string,salon_id:?int,uyelik_bitis_tarihi:?string,aktif_olmadi:bool}|null Kapaliysa 402 verisi, aciksa null
     */
    public function kapaliSalon(int $yetkiliId, array $istenenler): ?array
    {
        $bagli = $this->kapsam->kullaniciSalonlari($yetkiliId);
        if ($bagli === []) {
            return null;
        }

        // Yalniz bagli salonlarin tarihi okunur: baska salonun lisans durumu hicbir yoldan sizmaz.
        return LisansKurali::kapaliSalon($bagli, $istenenler, $this->bitisler($bagli), now(), (string) config('app.timezone'));
    }

    /**
     * Kapsam gecidi: bu istekte lisans denetlenmeliyse (LisansKapisi isaretledi; muaf uc, salon hesabina giris ve
     * isletme disi istek degil) erisilen salonun lisansi kapaliysa 402.
     *
     * @throws LisansKapaliException
     */
    public function erisimDenetle(int $salonId, ?Request $istek = null): void
    {
        $istek ??= app()->bound('request') ? app('request') : null;
        if (! $salonId || ! LisansKapisi::denetlenir($istek)) {
            return;
        }
        $tarih = $this->bitisler([$salonId])[$salonId] ?? null;
        if (! LisansKurali::acikMi($tarih, now(), (string) config('app.timezone'))) {
            throw new LisansKapaliException(LisansKurali::cevapVerisi($salonId, $tarih));
        }
    }

    /** @param  int[]  $salonlar  @return array<int,mixed> */
    private function bitisler(array $salonlar): array
    {
        return DB::table('salonlar')->whereIn('id', $salonlar)->pluck('uyelik_bitis_tarihi', 'id')->all();
    }
}
