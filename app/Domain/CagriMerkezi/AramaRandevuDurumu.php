<?php

namespace App\Domain\CagriMerkezi;

/**
 * Arama randevusu (callback, durum=3) ve takvimdeki diğer zamanlı sonuçların (ön görüşme 6,
 * satış 7, randevu oluşturuldu 8, iptal 9) GÖRÜNÜM durumu — saf hesap (DB'siz, saat dışarıdan).
 *
 * Eski arama_randevu_takvim_verileri (renk + metin), arama_randevularim (bekliyor/gecikti/
 * zamaninda/gec) ve cagri_yaklasan_randevular (faz) aynı girdiden ayrı ayrı türetiliyordu;
 * kurallar burada TEK yerde. Renkler hex — Flutter TakvimEvent.color ve web aynı değeri kullanır;
 * `durum_metin` metinleri Flutter'ın `islenmis` kontrolüyle birebir.
 */
final class AramaRandevuDurumu
{
    /** kod => [metin, hex] — takvim lejandı da bu sırayla çizilir. */
    public const TAKVIM = [
        'aranacak'            => ['Aranacak', '#2563eb'],
        'gecikti'             => ['Gecikti', '#dc2626'],
        'zamaninda'           => ['Zamanında arandı', '#16a34a'],
        'gec'                 => ['Geç arandı', '#f59e0b'],
        'ongorusme'           => ['Ön Görüşme', '#7c3aed'],
        'satis'               => ['Satış', '#b8860b'],
        'randevu_olusturuldu' => ['Randevu Oluşturuldu', '#0ea5e9'],
        'iptal'               => ['İptal', '#9a93ad'],
    ];

    /** Takvimde açılınca tekrar sonuç formu GÖSTERİLMEYEN (işlenmiş) durumlar. */
    public const ISLENMIS = ['ongorusme', 'satis', 'randevu_olusturuldu', 'iptal'];

    /** Bir aramanın "geç" sayılması için randevu zamanından sonraki tolerans (popup fazı). */
    private const GECIKME_ESIK_SN = 600;

    /**
     * Takvim kartı durumu (eski renk zinciriyle birebir sıra).
     *
     * @return array{kod:string, metin:string, renk:string, islenmis:bool}
     */
    public static function takvim(?int $durum, int $randevuTs, ?string $tamamlandiAt, bool $geciktiBayragi, int $simdi): array
    {
        $kod = match (true) {
            $durum === AramaSonucu::ON_GORUSME->value => 'ongorusme',
            $durum === AramaSonucu::TELEFONDA_SATIS->value => 'satis',
            $durum === AramaSonucu::RANDEVU_OLUSTURULDU->value => 'randevu_olusturuldu',
            $durum === AramaSonucu::IPTAL->value => 'iptal',
            $tamamlandiAt !== null && $tamamlandiAt !== '' => self::tamamlanma($tamamlandiAt, $randevuTs),
            $durum === AramaSonucu::TEKRAR_ARANACAK->value && ($randevuTs < $simdi || $geciktiBayragi) => 'gecikti',
            default => 'aranacak',
        };

        return self::paket($kod);
    }

    /**
     * Ajanda (Arama Randevularım) durumu: bekliyor | gecikti | zamaninda | gec.
     */
    public static function ajanda(int $randevuTs, ?string $tamamlandiAt, bool $geciktiBayragi, int $simdi): string
    {
        if ($tamamlandiAt !== null && $tamamlandiAt !== '') {
            return self::tamamlanma($tamamlandiAt, $randevuTs);
        }

        return ($randevuTs < $simdi || $geciktiBayragi) ? 'gecikti' : 'bekliyor';
    }

    /** Hatırlatma popup fazı: on5 (yaklaşıyor) | zaman (zamanı geldi) | gecikti (10 dk+ geçti). */
    public static function faz(int $randevuTs, int $simdi): string
    {
        if ($randevuTs > $simdi) {
            return 'on5';
        }

        return $randevuTs < $simdi - self::GECIKME_ESIK_SN ? 'gecikti' : 'zaman';
    }

    /** Lejand / filtre çipleri için sıralı liste. */
    public static function lejand(): array
    {
        return array_map(fn ($kod) => self::paket($kod), array_keys(self::TAKVIM));
    }

    private static function tamamlanma(string $tamamlandiAt, int $randevuTs): string
    {
        $ts = strtotime($tamamlandiAt);

        return $ts !== false && $ts > $randevuTs ? 'gec' : 'zamaninda';
    }

    /** @return array{kod:string, metin:string, renk:string, islenmis:bool} */
    private static function paket(string $kod): array
    {
        [$metin, $renk] = self::TAKVIM[$kod];

        return ['kod' => $kod, 'metin' => $metin, 'renk' => $renk, 'islenmis' => in_array($kod, self::ISLENMIS, true)];
    }
}
