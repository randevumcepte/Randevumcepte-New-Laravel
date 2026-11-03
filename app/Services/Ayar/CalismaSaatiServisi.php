<?php

namespace App\Services\Ayar;

use App\Domain\Ayar\HaftalikSaat;
use App\Exceptions\GecersizIslemException;
use App\Support\AktiviteLog;
use App\Support\HaftalikSaatDeposu;
use Illuminate\Support\Facades\DB;

/**
 * Isletme calisma + mola saatleri — eski StoreAdminController@calismasaatleriduzenle,
 * v1 salonsaatleri / salonmolasaatleri / calisma_saati_guncelle_ekle / mola_saati_guncelle_ekle
 * uclarinin TEK karsiligi. Tablo erisimi HaftalikSaatDeposu'nda (personel/cihaz ile ortak).
 *
 * Cevap her zaman 7 gun: {haftanin_gunu, gun_adi, calisiyor, baslangic_saati, bitis_saati,
 * mola_var, mola_baslangic_saati, mola_bitis_saati}. Kaydi olmayan gun varsayilanla doner.
 * Eskiden farkli: calisilan gunde / molada saat bos ya da bitis <= baslangic ise 422.
 */
class CalismaSaatiServisi
{
    private const VARSAYILAN = ['bas' => '09:00', 'bit' => '18:00', 'mola_bas' => '12:00', 'mola_bit' => '13:00'];

    public function getir(int $salonId): array
    {
        $calisma = collect(HaftalikSaatDeposu::oku('salon', $salonId, HaftalikSaatDeposu::CALISMA))->keyBy('haftanin_gunu');
        $mola = collect(HaftalikSaatDeposu::oku('salon', $salonId, HaftalikSaatDeposu::MOLA))->keyBy('haftanin_gunu');

        $gunler = [];
        foreach (HaftalikSaat::GUNLER as $gun => $ad) {
            $c = $calisma->get($gun);
            $m = $mola->get($gun);
            $gunler[] = [
                'haftanin_gunu'        => $gun,
                'gun_adi'              => $ad,
                'calisiyor'            => (int) ($c['calisiyor'] ?? 0),
                'baslangic_saati'      => HaftalikSaat::saat($c['baslangic_saati'] ?? null) ?? self::VARSAYILAN['bas'],
                'bitis_saati'          => HaftalikSaat::saat($c['bitis_saati'] ?? null) ?? self::VARSAYILAN['bit'],
                'mola_var'             => (int) ($m['mola_var'] ?? 0),
                'mola_baslangic_saati' => HaftalikSaat::saat($m['baslangic_saati'] ?? null) ?? self::VARSAYILAN['mola_bas'],
                'mola_bitis_saati'     => HaftalikSaat::saat($m['bitis_saati'] ?? null) ?? self::VARSAYILAN['mola_bit'],
            ];
        }

        return ['gunler' => $gunler];
    }

    /** @param  list<array>  $gunler  dogrulanmis 7 gun (CalismaSaatiIstegi) */
    public function kaydet(int $salonId, int $yapanId, array $gunler): array
    {
        $calisma = [];
        $mola = [];
        foreach ($gunler as $g) {
            $gun = (int) $g['haftanin_gunu'];
            $ad = HaftalikSaat::GUNLER[$gun];
            $bas = HaftalikSaat::saat($g['baslangic_saati'] ?? null);
            $bit = HaftalikSaat::saat($g['bitis_saati'] ?? null);
            $mBas = HaftalikSaat::saat($g['mola_baslangic_saati'] ?? null);
            $mBit = HaftalikSaat::saat($g['mola_bitis_saati'] ?? null);
            $calisiyor = ! empty($g['calisiyor']);
            $molaVar = ! empty($g['mola_var']);

            if ($calisiyor && ! HaftalikSaat::sirali($bas, $bit)) {
                throw new GecersizIslemException("{$ad}: çalışma bitiş saati başlangıçtan sonra olmalı.");
            }
            if ($molaVar && ! HaftalikSaat::sirali($mBas, $mBit)) {
                throw new GecersizIslemException("{$ad}: mola bitiş saati başlangıçtan sonra olmalı.");
            }

            $calisma[] = ['haftanin_gunu' => $gun, 'calisiyor' => (int) $calisiyor, 'baslangic_saati' => $bas, 'bitis_saati' => $bit];
            $mola[] = ['haftanin_gunu' => $gun, 'mola_var' => (int) $molaVar, 'baslangic_saati' => $mBas, 'bitis_saati' => $mBit];
        }

        DB::transaction(function () use ($salonId, $calisma, $mola) {
            HaftalikSaatDeposu::yaz('salon', $salonId, HaftalikSaatDeposu::CALISMA, $calisma);
            HaftalikSaatDeposu::yaz('salon', $salonId, HaftalikSaatDeposu::MOLA, $mola);
        });

        AktiviteLog::yaz($salonId, $yapanId, 'calisma_saatleri_guncelle', 'salon', $salonId,
            'Çalışma saatleri', 'İşletme çalışma / mola saatleri güncellendi',
            ['calisilan_gun' => count(array_filter($calisma, fn ($c) => $c['calisiyor']))]);

        return $this->getir($salonId);
    }
}
