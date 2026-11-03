<?php

namespace App\Services\Cark;

use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Çark HATIRLATMA ayarları (cark_hatirlatma_ayarlari) — gün içinde en fazla 3 push. Gönderimi
 * eski monolitin `cark:hatirlatma-gonder` cron'u yapar (bu servis yalnız ayarı okur/yazar).
 *
 * Tablodaki düz kolonlar (saat_1..3, mesaj_1..3, aktif_1..3, baslik_1..3, altyazi_1..3,
 * gonderim_gunleri) API'de okunur yapıya çevrilir: slotlar[{no, aktif, baslik, altyazi, ikon,
 * saat, mesaj}] + atlanan_gunler[] (ISO hafta günü 1=Pzt … 7=Paz; o günler push GİTMEZ).
 * Eski GET kayıt yoksa satır oluşturuyordu; v2 okuma YAZMAZ (varsayılanlar config'ten).
 */
class CarkHatirlatmaServisi
{
    private const TABLO = 'cark_hatirlatma_ayarlari';

    private const GUNLER = [1 => 'Pzt', 2 => 'Sal', 3 => 'Çar', 4 => 'Per', 5 => 'Cum', 6 => 'Cmt', 7 => 'Paz'];

    public function getir(int $salonId): array
    {
        $a = DB::table(self::TABLO)->where('salon_id', $salonId)->first();
        $atlanan = $a && $a->gonderim_gunleri ? (json_decode($a->gonderim_gunleri, true) ?: []) : [];

        return [
            'aktif'            => (int) ($a->aktif ?? 0),
            'bugun_gonderilen' => DB::table('cark_hatirlatma_loglari')->where('salon_id', $salonId)->whereDate('tarih', now()->toDateString())->count(),
            'slotlar'          => array_map(fn (int $no, array $v) => [
                'no'      => $no,
                'aktif'   => (int) ($a ? ($a->{"aktif_$no"} ?? 1) : 1),
                'baslik'  => ($a->{"baslik_$no"} ?? null) ?: $v['baslik'],
                'altyazi' => ($a->{"altyazi_$no"} ?? null) ?: $v['altyazi'],
                'ikon'    => $v['ikon'],
                'saat'    => substr((string) (($a->{"saat_$no"} ?? null) ?: $v['saat']), 0, 5),
                'mesaj'   => ($a->{"mesaj_$no"} ?? null) ?: $v['mesaj'],
            ], array_keys(self::slotlar()), self::slotlar()),
            'atlanan_gunler'   => array_values(array_map('intval', $atlanan)),
            'gunler'           => array_map(fn ($no, $ad) => ['no' => $no, 'ad' => $ad], array_keys(self::GUNLER), self::GUNLER),
            'mesaj_max'        => (int) config('cark.hatirlatma.mesaj_max'),
        ];
    }

    /**
     * @param  array{aktif:bool, slotlar:array<int, array{no:int, aktif:bool, baslik?:?string, altyazi?:?string, saat:string, mesaj:?string}>, atlanan_gunler?:int[]}  $v
     */
    public function kaydet(int $salonId, int $yetkiliId, array $v): array
    {
        $satir = ['aktif' => (int) (bool) $v['aktif'], 'aktif_son' => 0];   // eski 4. aşama kullanılmıyor
        foreach ($v['slotlar'] as $s) {
            $no = (int) $s['no'];
            $satir += [
                "aktif_$no"   => (int) (bool) $s['aktif'],
                "saat_$no"    => $s['saat'],
                "mesaj_$no"   => trim((string) ($s['mesaj'] ?? '')) ?: self::slotlar()[$no]['mesaj'],
                "baslik_$no"  => trim((string) ($s['baslik'] ?? '')) ?: null,
                "altyazi_$no" => trim((string) ($s['altyazi'] ?? '')) ?: null,
            ];
        }
        $gunler = array_values(array_unique(array_map('intval', $v['atlanan_gunler'] ?? [])));
        $satir['gonderim_gunleri'] = $gunler ? json_encode($gunler) : null;

        $id = DB::table(self::TABLO)->where('salon_id', $salonId)->value('id');
        if ($id) {
            DB::table(self::TABLO)->where('id', $id)->update($satir + ['updated_at' => now()]);
        } else {
            $id = DB::table(self::TABLO)->insertGetId($satir + ['salon_id' => $salonId, 'created_at' => now(), 'updated_at' => now()]);
        }

        AktiviteLog::yaz($salonId, $yetkiliId, 'cark_hatirlatma_kaydet', 'cark_hatirlatma', (int) $id, $satir['aktif'] ? 'Aktif' : 'Pasif',
            'Çark hatırlatma ayarları kaydedildi', ['gunler' => $gunler]);

        return $this->getir($salonId);
    }

    /** @return array<int, array{baslik:string, altyazi:string, ikon:string, saat:string, mesaj:string}> */
    public static function slotlar(): array
    {
        return config('cark.hatirlatma.slotlar');
    }
}
