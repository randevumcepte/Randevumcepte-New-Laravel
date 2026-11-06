<?php

namespace App\Services\SistemYonetim\Salon;

use App\Domain\SistemYonetim\SistemRolu;
use App\Exceptions\BulunamadiException;
use App\Exceptions\YetkisizErisimException;
use App\Models\SistemYoneticisi;
use App\Services\SistemYonetim\Denetim;
use App\Support\ZamanliKayit;
use Illuminate\Support\Facades\DB;

/** Salon ic notlari. Salon kapsami cagiranda denetlenir. */
class SalonNotServisi
{
    private const TABLO = 'sistemyonetim_salon_notlari';

    public function bul(int $notId): object
    {
        $not = DB::table(self::TABLO)->where('id', $notId)->first(['id', 'salon_id', 'user_id', 'pinned']);
        if (! $not) {
            throw new BulunamadiException('Not bulunamadı');
        }

        return $not;
    }

    /** @param array{baslik?:?string,icerik:string,tip?:?string,pinned?:?bool} $g */
    public function ekle(object $salon, array $g, SistemYoneticisi $yonetici): void
    {
        ZamanliKayit::ekle(self::TABLO, [
            'salon_id'  => $salon->id,
            'user_id'   => $yonetici->id,
            'user_name' => $yonetici->name,
            'baslik'    => $g['baslik'] ?? null,
            'icerik'    => $g['icerik'],
            'tip'       => $g['tip'] ?? 'genel',
            'pinned'    => empty($g['pinned']) ? 0 : 1,
        ]);
        Denetim::yaz('not_ekle', 'salon', (int) $salon->id, $salon->salon_adi, $g['baslik'] ?? null);
    }

    public function sil(object $not, SistemYoneticisi $yonetici): void
    {
        if ($yonetici->rol() !== SistemRolu::SuperAdmin && (int) $not->user_id !== (int) $yonetici->id) {
            throw new YetkisizErisimException('Sadece kendi notunuzu silebilirsiniz.');
        }
        DB::table(self::TABLO)->where('id', $not->id)->delete();
        Denetim::yaz('not_sil', 'salon', (int) $not->salon_id, null, '#'.$not->id);
    }

    public function sabitDegistir(object $not): void
    {
        $sabit = (int) $not->pinned === 1 ? 0 : 1;
        DB::table(self::TABLO)->where('id', $not->id)->update(['pinned' => $sabit, 'updated_at' => now()]);
        Denetim::yaz('not_pin', 'salon', (int) $not->salon_id, null, '#'.$not->id, ['pinned' => $sabit]);
    }
}
