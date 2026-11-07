<?php

namespace App\Services\Randevu;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sesli randevu musaitlik sorgulari (SALT OKUNUR) — eski SesliRandevuCozService::musaitlikBul,
 * takvimAcikMi ve personelHizmetiVarMi ile ayni kural: calisma saati + mevcut randevulara gore
 * ilk bos slot; tercih saati doluysa ayni gunun erken saati, sonra sonraki gunler.
 */
class SesliRandevuMusaitlikServisi
{
    private const UFUK_GUN = 14;

    private const VARSAYILAN_SURE_DK = 30;

    private const IPTAL_DURUMU = 2;

    /** Personel takvimde gorunur mu ve tanimli hizmeti var mi? */
    public function takvimDurumu(int $personelId): array
    {
        return [
            'acik'       => $this->takvimAcikMi($personelId),
            'hizmet_var' => DB::table('personel_sunulan_hizmetler')->where('personel_id', $personelId)->exists(),
        ];
    }

    /** @return array{bulundu:bool} + tarih, saat, sure_dk, tam_istek, istenen_dolu?, calisma_yok? */
    public function bul(int $salonId, ?int $personelId, int $hizmetId, ?string $tercihTarih, ?string $tercihSaat, ?string $vakit): array
    {
        if (! $personelId || ! $this->takvimAcikMi($personelId)) {
            return ['bulundu' => false, 'calisma_yok' => true];
        }

        $sure = $this->hizmetSuresi($salonId, $hizmetId);
        $adim = max(15, $sure);
        $bugun = Carbon::today();
        $ilkGun = $this->baslangicGunu($tercihTarih, $bugun);
        $tercihDk = $tercihSaat ? $this->dakika($tercihSaat) : null;
        $simdiDk = (int) Carbon::now()->format('H') * 60 + (int) Carbon::now()->format('i');

        for ($g = 0; $g < self::UFUK_GUN; $g++) {
            $gun = $ilkGun->copy()->addDays($g);
            $tarih = $gun->toDateString();
            $calisma = $this->calismaSaati($personelId, $salonId, $gun);
            if (! $calisma) {
                continue;
            }
            [$basDk, $bitDk] = $calisma;

            $aramaBas = $basDk;
            if ($tercihDk !== null && $g === 0) {
                $aramaBas = max($aramaBas, $tercihDk);
            } elseif ($vakit) {
                $aramaBas = max($aramaBas, $this->vakitBaslangici($vakit, $basDk));
            }
            $bugunMu = $tarih === $bugun->toDateString();
            if ($bugunMu) {
                $aramaBas = max($aramaBas, $simdiDk);
            }

            $dolu = $this->doluAraliklar($personelId, $tarih);
            $slot = $this->ilkBosSlot($aramaBas, $bitDk, $sure, $adim, $dolu);
            if ($slot !== null) {
                return $this->sonuc($tarih, $slot, $sure) + ['tam_istek' => $tercihTarih === $tarih && $slot === $tercihDk];
            }

            // Istenen saatten sonrasi doluysa ayni gunun erken saatleri onerilir, ertesi gune atlanmaz.
            if ($g === 0 && $tercihDk !== null) {
                $erkenBas = $bugunMu ? max($basDk, $simdiDk) : $basDk;
                $slot = $this->ilkBosSlot($erkenBas, min($bitDk, $aramaBas + $sure - 1), $sure, $adim, $dolu);
                if ($slot !== null) {
                    return $this->sonuc($tarih, $slot, $sure) + ['tam_istek' => false, 'istenen_dolu' => true];
                }
            }
        }

        return ['bulundu' => false];
    }

    private function sonuc(string $tarih, int $slot, int $sure): array
    {
        return ['bulundu' => true, 'tarih' => $tarih, 'saat' => sprintf('%02d:%02d', intdiv($slot, 60), $slot % 60), 'sure_dk' => $sure];
    }

    private function takvimAcikMi(int $personelId): bool
    {
        return (int) DB::table('salon_personelleri')->where('id', $personelId)->value('takvimde_gorunsun') === 1;
    }

    private function baslangicGunu(?string $tercih, Carbon $bugun): Carbon
    {
        try {
            $gun = $tercih ? Carbon::parse($tercih)->startOfDay() : $bugun->copy();
        } catch (\Exception) {
            return $bugun->copy();
        }

        return $gun->lt($bugun) ? $bugun->copy() : $gun;
    }

    /** @param array<int,array{0:int,1:int}> $dolu */
    private function ilkBosSlot(int $bas, int $bit, int $sure, int $adim, array $dolu): ?int
    {
        for ($slot = $bas; $slot + $sure <= $bit; $slot += $adim) {
            if (! array_filter($dolu, fn ($a) => $slot < $a[1] && $slot + $sure > $a[0])) {
                return $slot;
            }
        }

        return null;
    }

    private function vakitBaslangici(string $vakit, int $basDk): int
    {
        return match ($vakit) {
            'ogleden_sonra' => 12 * 60,
            'aksam'         => 17 * 60,
            default         => $basDk,
        };
    }

    /**
     * O gunun calisma penceresi (dk); calisilmiyorsa null. Personel satiri esastir; gun acik ama saat
     * girilmemisse ya da personel satiri yoksa salon saati kullanilir.
     *
     * @return array{0:int,1:int}|null
     */
    private function calismaSaati(int $personelId, int $salonId, Carbon $gun): ?array
    {
        $kolonlar = ['calisiyor', 'baslangic_saati', 'bitis_saati'];
        $personel = DB::table('personel_calisma_saatleri')->where('personel_id', $personelId)->where('haftanin_gunu', $gun->dayOfWeekIso)->first($kolonlar);
        $salon = DB::table('salon_calisma_saatleri')->where('salon_id', $salonId)->where('haftanin_gunu', $gun->dayOfWeekIso)->first($kolonlar);

        $kaynak = $personel ?? $salon;
        if (! $kaynak || (int) $kaynak->calisiyor !== 1) {
            return null;
        }

        return $this->pencere($kaynak) ?? ($salon ? $this->pencere($salon) : null);
    }

    /** @return array{0:int,1:int}|null */
    private function pencere(object $satir): ?array
    {
        $bas = $this->dakika($satir->baslangic_saati);
        $bit = $this->dakika($satir->bitis_saati);

        return $bas !== null && $bit !== null && $bit > $bas ? [$bas, $bit] : null;
    }

    /** @return array<int,array{0:int,1:int}> */
    private function doluAraliklar(int $personelId, string $tarih): array
    {
        $satirlar = DB::table('randevu_hizmetler as rh')->join('randevular as r', 'rh.randevu_id', '=', 'r.id')
            ->where('rh.personel_id', $personelId)->where('r.tarih', $tarih)->where('r.durum', '!=', self::IPTAL_DURUMU)
            ->get(['r.saat', 'r.saat_bitis', 'rh.sure_dk']);

        $araliklar = [];
        foreach ($satirlar as $s) {
            $bas = $this->dakika($s->saat);
            if ($bas !== null) {
                $araliklar[] = [$bas, $this->dakika($s->saat_bitis) ?? $bas + ((int) $s->sure_dk ?: self::VARSAYILAN_SURE_DK)];
            }
        }

        return $araliklar;
    }

    private function hizmetSuresi(int $salonId, int $hizmetId): int
    {
        $sure = (int) DB::table('salon_sunulan_hizmetler')->where('salon_id', $salonId)->where('hizmet_id', $hizmetId)->value('sure_dk');

        return $sure > 0 ? $sure : self::VARSAYILAN_SURE_DK;
    }

    private function dakika(?string $saat): ?int
    {
        return preg_match('/(\d{1,2}):(\d{2})/', (string) $saat, $m) ? (int) $m[1] * 60 + (int) $m[2] : null;
    }
}
