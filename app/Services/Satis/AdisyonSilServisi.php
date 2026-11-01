<?php

namespace App\Services\Satis;

use App\Exceptions\BulunamadiException;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adisyon SİLME — eski `StoreAdminController@adisyon_sil` (web) kaskadının
 * birebir aynası (web + Flutter AYNI uç; kod tekrarı yok).
 *
 * Tek transaction içinde sırasıyla:
 *   1) Kalem tahsilat payları (tahsilat_hizmetler/urunler/paketler) + kalemler
 *   2) Taksitli tahsilatlar (kalem bağı VEYA adisyon_id) + taksit vadeleri
 *   3) Senetler (kalem bağı VEYA adisyon_id) + senet vadeleri
 *   4) Alacaklar: taksitli_tahsilat_id / senet_id üzerinden (yetim alacak fix)
 *   5) Kalem bağlantılı parent tahsilatlar + adisyon_id'li ÖKSÜZ tahsilatlar
 *   6) Alacaklar (adisyon_id) + komisyon masrafları (masraflar.adisyon_id)
 *   7) Adisyon
 * Sonra ortak AktiviteLog ile salon_aktivite_log'a audit yazılır.
 * Not: eski kod adisyon_paket_seanslar'a dokunmaz; parite için burada da dokunulmaz.
 */
class AdisyonSilServisi
{
    /**
     * @param int|null $yetkiliId  İşlemi yapan yetkili (audit için)
     * @throws BulunamadiException
     */
    public function sil(int $adisyonId, ?int $yetkiliId): array
    {
        $adisyon = DB::table('adisyonlar')->where('id', $adisyonId)->first(['id', 'salon_id', 'user_id']);
        if (! $adisyon) {
            throw new BulunamadiException('Adisyon bulunamadı');
        }

        DB::transaction(function () use ($adisyonId) {
            DB::table('adisyonlar')->where('id', $adisyonId)->lockForUpdate()->first(['id']);

            $senetIdler = [];
            $taksitIdler = [];
            $tahsilatIdler = [];

            foreach ([
                ['adisyon_hizmetler', 'tahsilat_hizmetler', 'adisyon_hizmet_id'],
                ['adisyon_urunler', 'tahsilat_urunler', 'adisyon_urun_id'],
                ['adisyon_paketler', 'tahsilat_paketler', 'adisyon_paket_id'],
            ] as [$kalemTablo, $tahTablo, $fk]) {
                $kalemler = DB::table($kalemTablo)->where('adisyon_id', $adisyonId)
                    ->get(['id', 'senet_id', 'taksitli_tahsilat_id']);
                if ($kalemler->isEmpty()) {
                    continue;
                }
                $kalemIdler = $kalemler->pluck('id')->all();
                array_push($senetIdler, ...$kalemler->pluck('senet_id')->all());
                array_push($taksitIdler, ...$kalemler->pluck('taksitli_tahsilat_id')->all());
                array_push($tahsilatIdler, ...DB::table($tahTablo)->whereIn($fk, $kalemIdler)->pluck('tahsilat_id')->all());

                DB::table($tahTablo)->whereIn($fk, $kalemIdler)->delete();
                DB::table($kalemTablo)->whereIn('id', $kalemIdler)->delete();
            }

            // Taksitli tahsilatlar: kalem bağı VEYA adisyon_id (bazı akışlar kaleme yansıtmıyor).
            $silinenTaksit = DB::table('taksitli_tahsilatlar')
                ->whereIn('id', self::temiz($taksitIdler))
                ->orWhere('adisyon_id', $adisyonId)
                ->pluck('id')->all();
            if ($silinenTaksit) {
                DB::table('taksit_vadeleri')->whereIn('taksitli_tahsilat_id', $silinenTaksit)->delete();
                DB::table('taksitli_tahsilatlar')->whereIn('id', $silinenTaksit)->delete();
            }

            $silinenSenet = DB::table('senetler')
                ->whereIn('id', self::temiz($senetIdler))
                ->orWhere('adisyon_id', $adisyonId)
                ->pluck('id')->all();
            if ($silinenSenet) {
                DB::table('senet_vadeleri')->whereIn('senet_id', $silinenSenet)->delete();
                DB::table('senetler')->whereIn('id', $silinenSenet)->delete();
            }

            // Yetim alacak fix: taksit/senet bağlı alacaklarda adisyon_id NULL olabilir.
            $tumTaksit = self::temiz(array_merge($taksitIdler, $silinenTaksit));
            if ($tumTaksit) {
                DB::table('alacaklar')->whereIn('taksitli_tahsilat_id', $tumTaksit)->delete();
            }
            $tumSenet = self::temiz(array_merge($senetIdler, $silinenSenet));
            if ($tumSenet) {
                DB::table('alacaklar')->whereIn('senet_id', $tumSenet)->delete();
            }

            $tahsilatIdler = self::temiz($tahsilatIdler);
            if ($tahsilatIdler) {
                DB::table('tahsilatlar')->whereIn('id', $tahsilatIdler)->delete();
            }

            // Güvenlik ağı: adisyon_id'li ama dağılım satırı olmayan öksüz tahsilatlar.
            $oksuz = DB::table('tahsilatlar')->where('adisyon_id', $adisyonId)->pluck('id')->all();
            if ($oksuz) {
                DB::table('tahsilat_hizmetler')->whereIn('tahsilat_id', $oksuz)->delete();
                DB::table('tahsilat_urunler')->whereIn('tahsilat_id', $oksuz)->delete();
                DB::table('tahsilat_paketler')->whereIn('tahsilat_id', $oksuz)->delete();
                DB::table('tahsilatlar')->whereIn('id', $oksuz)->delete();
            }

            DB::table('alacaklar')->where('adisyon_id', $adisyonId)->delete();
            if (Schema::hasColumn('masraflar', 'adisyon_id')) {
                DB::table('masraflar')->where('adisyon_id', $adisyonId)->delete();
            }

            DB::table('adisyonlar')->where('id', $adisyonId)->delete();
        });

        $musteri = DB::table('users')->where('id', $adisyon->user_id)->value('name') ?? 'Müşteri #'.$adisyon->user_id;
        AktiviteLog::yaz((int) $adisyon->salon_id, $yetkiliId, 'adisyon_sil', 'adisyon', (int) $adisyon->id,
            $musteri.' — Adisyon #'.$adisyon->id, 'Adisyon ve tüm kalemleri silindi', ['user_id' => $adisyon->user_id]);

        return ['adisyon_id' => $adisyonId, 'salon_id' => (int) $adisyon->salon_id];
    }

    private static function temiz(array $idler): array
    {
        return array_values(array_unique(array_filter($idler)));
    }
}
