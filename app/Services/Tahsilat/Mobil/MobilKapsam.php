<?php

namespace App\Services\Tahsilat\Mobil;

use App\Exceptions\YetkisizErisimException;
use Illuminate\Support\Facades\DB;

/**
 * Mobil para uclarinda istemciden gelen kimliklerin SALON SAHIPLIGI. Eski uclar bu denetimi yapmazdi: baska salonun
 * kalemine tahsilat yazilabiliyordu. Yetki eski mobille ayni kalir, kapsam her zaman zorunludur.
 */
class MobilKapsam
{
    private const KALEM = [
        'hizmet' => 'adisyon_hizmetler',
        'urun'   => 'adisyon_urunler',
        'paket'  => 'adisyon_paketler',
    ];

    /** @param  array<int|string>  $idler */
    public function kalemlerOlmali(int $salonId, string $tur, array $idler): void
    {
        $idler = $this->temizle($idler);
        if (! $idler) {
            return;
        }
        $bulunan = DB::table(self::KALEM[$tur].' as k')->join('adisyonlar as a', 'a.id', '=', 'k.adisyon_id')
            ->whereIn('k.id', $idler)->where('a.salon_id', $salonId)->distinct()->count('k.id');
        $this->tam($bulunan, $idler, 'Kalem bu işletmeye ait değil');
    }

    /** @param  array<int|string>  $idler */
    public function taksitVadeleriOlmali(int $salonId, array $idler): void
    {
        $idler = $this->temizle($idler);
        if (! $idler) {
            return;
        }
        $bulunan = DB::table('taksit_vadeleri as v')->join('taksitli_tahsilatlar as t', 't.id', '=', 'v.taksitli_tahsilat_id')
            ->whereIn('v.id', $idler)->where('t.salon_id', $salonId)->count();
        $this->tam($bulunan, $idler, 'Taksit vadesi bu işletmeye ait değil');
    }

    /** @param  array<int|string>  $idler */
    public function senetVadeleriOlmali(int $salonId, array $idler): void
    {
        $idler = $this->temizle($idler);
        if (! $idler) {
            return;
        }
        $bulunan = DB::table('senet_vadeleri as v')->join('senetler as s', 's.id', '=', 'v.senet_id')
            ->whereIn('v.id', $idler)->where('s.salon_id', $salonId)->count();
        $this->tam($bulunan, $idler, 'Senet vadesi bu işletmeye ait değil');
    }

    /** Musteri bu salonun portfoyunde ya da adisyonunda olmali (pasif portfoy dahil; eski mobil onlara da satar). */
    public function musteriOlmali(int $salonId, mixed $musteriId): void
    {
        $id = (int) $musteriId;
        if ($id <= 0) {
            throw new YetkisizErisimException('Müşteri bu işletmeye ait değil');
        }
        $var = DB::table('musteri_portfoy')->where('salon_id', $salonId)->where('user_id', $id)->exists()
            || DB::table('adisyonlar')->where('salon_id', $salonId)->where('user_id', $id)->exists();
        if (! $var) {
            throw new YetkisizErisimException('Müşteri bu işletmeye ait değil');
        }
    }

    /** Personel verildiyse bu salonun personeli olmali. */
    public function personelOlmali(int $salonId, mixed $personelId): void
    {
        if (empty($personelId)) {
            return;
        }
        if (! DB::table('salon_personelleri')->where('id', (int) $personelId)->where('salon_id', $salonId)->exists()) {
            throw new YetkisizErisimException('Personel bu işletmeye ait değil');
        }
    }

    /**
     * Satisa yazilacak urun ve paket bu salonun katalogundan olmali. Hizmet katalogu salonlar arasi ortaktir.
     *
     * @param  array<int,mixed>  $kalemler  her biri {tip, id}
     */
    public function katalogOlmali(int $salonId, array $kalemler): void
    {
        foreach (['urun' => 'urunler', 'paket' => 'paketler'] as $tip => $tablo) {
            $idler = [];
            foreach ($kalemler as $k) {
                if (is_array($k) && ($k['tip'] ?? null) === $tip) {
                    $idler[] = $k['id'] ?? 0;
                }
            }
            $idler = $this->temizle($idler);
            if ($idler) {
                $this->tam(DB::table($tablo)->whereIn('id', $idler)->where('salon_id', $salonId)->count(), $idler, 'Kalem bu işletmeye ait değil');
            }
        }
    }

    /** Adisyon verildiyse bu salona ait olmali. */
    public function adisyonOlmali(int $salonId, mixed $adisyonId): void
    {
        if (empty($adisyonId)) {
            return;
        }
        if (! DB::table('adisyonlar')->where('id', (int) $adisyonId)->where('salon_id', $salonId)->exists()) {
            throw new YetkisizErisimException('Adisyon bu işletmeye ait değil');
        }
    }

    /** @return list<int> */
    private function temizle(array $idler): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $idler))));
    }

    private function tam(int $bulunan, array $idler, string $mesaj): void
    {
        if ($bulunan !== count($idler)) {
            throw new YetkisizErisimException($mesaj);
        }
    }
}
