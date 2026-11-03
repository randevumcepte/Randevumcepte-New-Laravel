<?php

namespace App\Services\Asistan;

use App\Domain\Asistan\GorevSunumu;
use App\Domain\Asistan\GorevTuru;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * E-Asistan gorevini iptal et (YAZMA) — eski Controller::gorevIptalEt/gorevIptalEt2.
 *
 * Eski sistemden farklar (v2 guvenlik): kaydin salonu cozulur (controller kapsam
 * denetler; eski uc baska salonun kaydini iptal edebiliyordu) ve listedeki AYNI
 * iptal-edilebilirlik kurali sunucuda zorlanir (aranmis/ulasilmis gorev iptal edilmez).
 */
class AsistanGorevIptalServisi
{
    /** Kaydin salonu (yoksa null → controller 404). */
    public function salonId(GorevTuru $tur, int $id): ?int
    {
        $salon = DB::table($tur->tablo())->where('id', $id)->value('salon_id');

        return $salon ? (int) $salon : null;
    }

    /**
     * @throws BulunamadiException
     * @throws GecersizIslemException
     */
    public function iptalEt(GorevTuru $tur, int $id, int $salonId, ?int $yetkiliId): string
    {
        $kolon = $tur->iptalKolonu();
        if ($kolon === null) {
            throw new GecersizIslemException('Bu görev iptal edilemez');
        }

        DB::transaction(function () use ($tur, $id, $kolon) {
            $satir = DB::table($tur->tablo())->where('id', $id)->lockForUpdate()->first();
            if (! $satir) {
                throw new BulunamadiException('Görev bulunamadı');
            }
            if (! GorevSunumu::iptalEdilebilir($tur, (array) $satir)) {
                throw new GecersizIslemException('Bu görev artık iptal edilemez (arama yapılmış ya da zaten iptal edilmiş)');
            }
            DB::table($tur->tablo())->where('id', $id)->update([$kolon => 1]);
        });

        AktiviteLog::yaz($salonId, $yetkiliId, 'e_asistan_gorev_iptal', $tur->value, $id,
            $tur->baslik(), 'E-Asistan görevi iptal edildi');

        return $tur->iptalMesaji();
    }
}
