<?php

namespace App\Domain\SistemYonetim;

use DateTimeImmutable;

/**
 * Salon uyelik suresi ve paket kurallari. uyelik_turu paket kademesidir (1/2/3), demo DEGIL; demo yalniz demo_hesabi=1.
 * Uzatma: mevcut bitis ileride ise kalan sure korunur, degilse bugunden eklenir.
 */
final class UyelikKurallari
{
    /** Paket adi => uyelik_turu kademesi. */
    public const PAKETLER = ['Başlangıç' => 1, 'Standart' => 2, 'Premium' => 3];

    public const PERIYOTLAR = ['aylik' => 'Aylık', 'yillik' => 'Yıllık'];

    /** Demo lisansi aktif edilince salonun dustugu kademe. */
    public const LISANS_KADEMESI = 1;

    public static function gecerliMi(?string $tarih): bool
    {
        return $tarih && ! str_starts_with($tarih, '0000') && strtotime($tarih) !== false;
    }

    public static function gunUzat(?string $mevcut, int $gun, string $bugun): string
    {
        return self::ekle($mevcut, "+{$gun} days", $bugun);
    }

    public static function ayUzat(?string $mevcut, int $ay, string $bugun): string
    {
        return self::ekle($mevcut, "+{$ay} months", $bugun);
    }

    /** Hediye dahil toplam ay: yillikta adet yildir. */
    public static function toplamAy(string $periyot, int $adet, int $hediyeAy): int
    {
        return ($periyot === 'yillik' ? $adet * 12 : $adet) + $hediyeAy;
    }

    /** @return array{bitis:?string,kalan_gun:?int,renk:string,metin:?string} */
    public static function ozet(?string $bitis, int $simdi): array
    {
        if (! self::gecerliMi($bitis)) {
            return ['bitis' => null, 'kalan_gun' => null, 'renk' => 'muted', 'metin' => null];
        }
        $gun = substr((string) $bitis, 0, 10);
        $kalan = (int) floor((strtotime($gun.' 23:59:59') - $simdi) / 86400);

        return [
            'bitis'     => $gun,
            'kalan_gun' => $kalan,
            'renk'      => $kalan < 0 ? 'danger' : ($kalan <= 7 ? 'warning' : 'success'),
            'metin'     => match (true) {
                $kalan < 0   => abs($kalan).' gün önce doldu',
                $kalan === 0 => 'bugün doluyor',
                default      => $kalan.' gün kaldı',
            },
        ];
    }

    /** Liste durum rozeti. @return array{kod:string,etiket:string,renk:string} */
    public static function rozet(bool $askida, bool $demo, ?int $kalanGun): array
    {
        return match (true) {
            $askida                                 => ['kod' => 'askida', 'etiket' => 'Askıda', 'renk' => 'danger'],
            $demo                                   => ['kod' => 'demo', 'etiket' => 'Demo', 'renk' => 'warning'],
            $kalanGun !== null && $kalanGun >= 0    => ['kod' => 'aktif', 'etiket' => 'Aktif', 'renk' => 'success'],
            default                                 => ['kod' => 'suresi_doldu', 'etiket' => 'Süresi Doldu', 'renk' => 'muted'],
        };
    }

    /** Ekranin sabit listeleri: API'den gider. */
    public static function secenekler(): array
    {
        $sure = fn (int $gun, string $etiket, string $stil = 'soft') => compact('gun', 'etiket', 'stil');

        return [
            'paketler'   => array_keys(self::PAKETLER),
            'periyotlar' => array_map(fn ($kod, $etiket) => compact('kod', 'etiket'), array_keys(self::PERIYOTLAR), self::PERIYOTLAR),
            'hizli_sureler' => [
                'demo'     => [$sure(7, '+7 gün'), $sure(15, '+15 gün'), $sure(30, '+30 gün', 'primary'), $sure(90, '+90 gün'), $sure(365, '+1 yıl')],
                'lisansli' => [$sure(365, '+1 yıl', 'primary'), $sure(730, '+2 yıl')],
            ],
            'lisans_sureleri' => [['gun' => 30, 'etiket' => '1 ay'], ['gun' => 90, 'etiket' => '3 ay'], ['gun' => 180, 'etiket' => '6 ay'], ['gun' => 365, 'etiket' => '1 yıl']],
        ];
    }

    private static function ekle(?string $mevcut, string $sure, string $bugun): string
    {
        $mevcutGun = self::gecerliMi($mevcut) ? substr((string) $mevcut, 0, 10) : null;
        $baz = $mevcutGun !== null && $mevcutGun > $bugun ? $mevcutGun : $bugun;

        return (new DateTimeImmutable($baz))->modify($sure)->format('Y-m-d');
    }
}
