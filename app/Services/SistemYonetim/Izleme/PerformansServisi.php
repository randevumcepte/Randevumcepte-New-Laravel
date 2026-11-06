<?php

namespace App\Services\SistemYonetim\Izleme;

use App\Support\DiziSayfasi;
use App\Models\SistemYoneticisi;
use Illuminate\Support\Facades\DB;

/** Ekip performansi ve SLA. Eski kullanici basina 4 sorgu yerine her olcum tek GROUP BY; satirlar PHP'de birlesir. */
class PerformansServisi
{
    public const SIRALAMA = [
        'ad' => 'ad', 'rol' => 'rol_etiket', 'toplam' => 'toplam', 'cozulen' => 'cozulen', 'acik' => 'acik', 'ilk_yanit' => 'ort_yanit_dk',
        'cozum' => 'ort_cozum_saat', 'mesaj' => 'mesaj_sayisi', 'salon_girisi' => 'imp_sayisi', 'aktivite' => 'aktivite_sayisi',
    ];

    private const GUNLER = [7, 30, 90, 180];

    private const VARSAYILAN_GUN = 30;

    private const CEVAP_KOLONLARI = "
        COUNT(*) as toplam,
        SUM(CASE WHEN durum IN ('cozumlendi','kapali') THEN 1 ELSE 0 END) as cozulen,
        SUM(CASE WHEN durum NOT IN ('cozumlendi','kapali') THEN 1 ELSE 0 END) as acik,
        AVG(CASE WHEN ilk_yanit_tarihi IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, ilk_yanit_tarihi) END) as ort_yanit_dk,
        AVG(CASE WHEN cozumlenme_tarihi IS NOT NULL THEN TIMESTAMPDIFF(HOUR, created_at, cozumlenme_tarihi) END) as ort_cozum_saat
    ";

    public function rapor(int $gun, array $liste): array
    {
        if ($gun < 1 || $gun > 365) {
            $gun = self::VARSAYILAN_GUN;
        }
        $sinir = IzlemeZamani::gunBasi($gun);

        return [
            'gun'    => $gun,
            'gunler' => self::GUNLER,
            'genel'  => $this->genel($sinir),
        ] + DiziSayfasi::sayfala($this->satirlar($sinir), $liste, fn (array $s) => $s['ad'].' '.$s['rol_etiket'], self::SIRALAMA);
    }

    private function genel(string $sinir): array
    {
        $g = DB::table('sistemyonetim_destek_talepleri')->where('created_at', '>=', $sinir)->selectRaw(self::CEVAP_KOLONLARI)->first();
        $toplam = (int) ($g->toplam ?? 0);
        $cozulen = (int) ($g->cozulen ?? 0);

        return [
            'toplam'         => $toplam,
            'cozulen'        => $cozulen,
            'cozum_orani'    => $toplam > 0 ? round($cozulen / $toplam * 100, 1) : 0,
            'ort_yanit_dk'   => self::yuvarla($g->ort_yanit_dk ?? null),
            'ort_cozum_saat' => self::yuvarla($g->ort_cozum_saat ?? null),
        ];
    }

    private function satirlar(string $sinir): array
    {
        $talep = DB::table('sistemyonetim_destek_talepleri')->where('created_at', '>=', $sinir)->whereNotNull('atanan_user_id')
            ->groupBy('atanan_user_id')->selectRaw('atanan_user_id, '.self::CEVAP_KOLONLARI)->get()->keyBy('atanan_user_id');
        $mesaj = $this->kullaniciBasina('sistemyonetim_destek_mesajlari', 'created_at', $sinir);
        $aktivite = $this->kullaniciBasina('sistemyonetim_audit_log', 'created_at', $sinir);
        $salonGirisi = $this->kullaniciBasina('sistemyonetim_impersonation_loglari', 'baslangic_tarihi', $sinir);

        return SistemYoneticisi::where('aktif', 1)->orderBy('name')->get(['id', 'name', 'rol', 'admin'])
            ->map(function (SistemYoneticisi $u) use ($talep, $mesaj, $aktivite, $salonGirisi) {
                $t = $talep[$u->id] ?? null;

                return [
                    'id'              => (int) $u->id,
                    'ad'              => (string) $u->name,
                    'rol_etiket'      => $u->rol()->etiket(),
                    'toplam'          => (int) ($t->toplam ?? 0),
                    'cozulen'         => (int) ($t->cozulen ?? 0),
                    'acik'            => (int) ($t->acik ?? 0),
                    'ort_yanit_dk'    => self::yuvarla($t->ort_yanit_dk ?? null),
                    'ort_cozum_saat'  => self::yuvarla($t->ort_cozum_saat ?? null),
                    'mesaj_sayisi'    => $mesaj[$u->id] ?? 0,
                    'aktivite_sayisi' => $aktivite[$u->id] ?? 0,
                    'imp_sayisi'      => $salonGirisi[$u->id] ?? 0,
                ];
            })->all();
    }

    /** @return array<int,int> user_id => adet */
    private function kullaniciBasina(string $tablo, string $tarihKolonu, string $sinir): array
    {
        return DB::table($tablo)->where($tarihKolonu, '>=', $sinir)->whereNotNull('user_id')->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as adet')->pluck('adet', 'user_id')->map(fn ($a) => (int) $a)->all();
    }

    private static function yuvarla($deger): ?float
    {
        return $deger !== null ? round((float) $deger, 1) : null;
    }
}
