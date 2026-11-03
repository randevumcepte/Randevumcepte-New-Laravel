<?php

namespace App\Services\Asistan;

use App\Domain\Asistan\AsistanAyari;
use App\Exceptions\GecersizIslemException;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * E-Asistan ayarlari (OKUMA + YAZMA) — eski e_asistan (ayar okuma) ve
 * e_asistan_ayar_kaydet'in v2 karsiligi. Tanimlar AsistanAyari enum'unda.
 *
 * Eski sistemden farklar:
 *   - GET yan etkisiz: eski sayfa ilk ziyarette 8 satiri acik_kapali=1 ile
 *     YARATIYORDU. v2 eksik satiri gercek etkisiyle (kapali — cron satir yoksa
 *     aramaz) raporlar; satir ilk kaydette olusur.
 *   - Kayit KISMI: yalniz gonderilen anahtarlar yazilir (eski kayit formda
 *     olmayan stok ayarini (5) her seferinde kapatiyordu).
 */
class AsistanAyarServisi
{
    private const TABLO = 'salon_esistan_ayarlari';

    public function getir(int $salonId): array
    {
        $acik = DB::table(self::TABLO)->where('salon_id', $salonId)->pluck('acik_kapali', 'ayar_id');
        $saat = DB::table('salonlar')->where('id', $salonId)->value('e_asistan_hatirlatma');

        return [
            'ayarlar' => array_map(fn (AsistanAyari $a) => [
                'anahtar'      => $a->anahtar(),
                'ayar_id'      => $a->value,
                'baslik'       => $a->baslik(),
                'aciklama'     => $a->aciklama(),
                'ikon'         => $a->ikon(),
                'saat_secimli' => $a->saatSecimli(),
                'acik'         => (int) ($acik[$a->value] ?? 0) === 1,
            ], AsistanAyari::cases()),
            'tekrar_arama_saat'        => $saat ? (int) $saat : null,
            'tekrar_arama_secenekleri' => range(AsistanAyari::TEKRAR_SAAT_MIN, AsistanAyari::TEKRAR_SAAT_MAX),
        ];
    }

    /**
     * @param array<string,bool> $acikKapali anahtar => acik
     *
     * @throws GecersizIslemException
     */
    public function kaydet(int $salonId, array $acikKapali, ?int $tekrarSaat, ?int $yetkiliId): array
    {
        $ayarlar = [];
        foreach ($acikKapali as $anahtar => $acik) {
            $ayar = AsistanAyari::anahtardan((string) $anahtar);
            if (! $ayar) {
                throw new GecersizIslemException("Bilinmeyen ayar: {$anahtar}");
            }
            $ayarlar[$ayar->value] = (bool) $acik;
        }
        if ($tekrarSaat !== null && ($tekrarSaat < AsistanAyari::TEKRAR_SAAT_MIN || $tekrarSaat > AsistanAyari::TEKRAR_SAAT_MAX)) {
            throw new GecersizIslemException('Tekrar deneme süresi '.AsistanAyari::TEKRAR_SAAT_MIN.'-'.AsistanAyari::TEKRAR_SAAT_MAX.' saat olmalı');
        }

        DB::transaction(function () use ($salonId, $ayarlar, $tekrarSaat) {
            foreach ($ayarlar as $ayarId => $acik) {
                $guncellenen = DB::table(self::TABLO)->where('salon_id', $salonId)->where('ayar_id', $ayarId)
                    ->update(['acik_kapali' => $acik, 'updated_at' => now()]);
                $varMi = $guncellenen || DB::table(self::TABLO)->where('salon_id', $salonId)->where('ayar_id', $ayarId)->exists();
                if (! $varMi) {
                    DB::table(self::TABLO)->insert([
                        'salon_id' => $salonId, 'ayar_id' => $ayarId, 'acik_kapali' => $acik,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            if ($tekrarSaat !== null) {
                DB::table('salonlar')->where('id', $salonId)->update(['e_asistan_hatirlatma' => $tekrarSaat]);
            }
        });

        // Eski kayitla ayni action → eski "Aktivite Gecmisi"nde aynen gorunur.
        AktiviteLog::yaz($salonId, $yetkiliId, 'e_asistan_ayar_kaydet', 'e_asistan_ayar', null,
            'E-Asistan ayarları', 'E-Asistan (otomatik arama / takip) ayarları güncellendi',
            ['arama_saat_sonra' => $tekrarSaat, 'ayarlar' => $ayarlar]);

        return $this->getir($salonId);
    }
}
