<?php

namespace App\Services\Sadakat;

use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * PUAN KAZANMA KURALI (salon_sadakat_ayarlari) — "her X TL ödemeye Y puan + gelinen her randevuya
 * Z puan". Eski App\Services\SadakatPuanServisi::ayar/ayarDizi/ayarKaydet ile birebir. Puanların
 * dağıtımı eski monolitin `sadakat:puan-isle` cron'unda (mutabakat) — bu servis yalnız kuralı yazar.
 *
 * Kapalı → açık geçişte `baslangic` şimdi olur: o ana kadarki ödeme/randevular puan kazanmaz
 * (geriye dönük toplu puan dağıtılmaz).
 */
class SadakatKazanimServisi
{
    private const TABLO = 'salon_sadakat_ayarlari';

    /** @return array{aktif:int, harcama_tutari:float, harcama_puani:int, ziyaret_puani:int, baslangic:?string} */
    public function getir(int $salonId): array
    {
        $a = DB::table(self::TABLO)->where('salon_id', $salonId)->first();

        return [
            'aktif'          => (int) ($a->aktif ?? 0),
            'harcama_tutari' => $a ? (float) $a->harcama_tutari : 100.0,
            'harcama_puani'  => (int) ($a->harcama_puani ?? 0),
            'ziyaret_puani'  => (int) ($a->ziyaret_puani ?? 0),
            'baslangic'      => $a->baslangic ?? null,
        ];
    }

    /** @param  array{aktif:bool, harcama_tutari:float, harcama_puani:int, ziyaret_puani:int}  $v */
    public function kaydet(int $salonId, int $yetkiliId, array $v): array
    {
        $eski = $this->getir($salonId);
        $satir = [
            'aktif'          => (int) (bool) $v['aktif'],
            'harcama_tutari' => max(1, (float) $v['harcama_tutari']),
            'harcama_puani'  => max(0, (int) $v['harcama_puani']),
            'ziyaret_puani'  => max(0, (int) $v['ziyaret_puani']),
            'updated_at'     => now(),
        ];
        if ($satir['aktif'] && ! $eski['aktif']) {
            $satir['baslangic'] = now();
        }

        $id = DB::table(self::TABLO)->where('salon_id', $salonId)->value('id');
        if ($id) {
            DB::table(self::TABLO)->where('id', $id)->update($satir);
        } else {
            $id = DB::table(self::TABLO)->insertGetId($satir + ['salon_id' => $salonId, 'created_at' => now()]);
        }

        $yeni = $this->getir($salonId);
        AktiviteLog::yaz($salonId, $yetkiliId, 'sadakat_kazanim_kurali', 'sadakat_ayar', (int) $id,
            $yeni['aktif'] ? 'Açık' : 'Kapalı', 'Puan kazanım kuralı güncellendi', ['eski' => $eski, 'yeni' => $yeni]);

        return $yeni;
    }
}
