<?php

namespace App\Services\MusteriHesap;

use App\Support\AktiviteLog;
use App\Support\Sema;
use Illuminate\Support\Facades\DB;

/** Eski musteriYorumlariAdmin + musteriYorumSil: bildirilen yorum uste gelir, puan yalniz 1-5 yildiz satirindan. */
class MusteriYorumYonetimServisi
{
    private const TABLO = 'salon_yorumlar';

    public function liste(int $salonId): array
    {
        $bildirim = Sema::kolonVar(self::TABLO, 'bildirilen_sayisi');
        $q = DB::table(self::TABLO)->where(self::TABLO.'.salon_id', $salonId)
            ->leftJoin('users', self::TABLO.'.user_id', '=', 'users.id')
            ->select(self::TABLO.'.*', 'users.name as kullanici_adi');
        if ($bildirim) {
            $q->orderByDesc(self::TABLO.'.bildirilen_sayisi');
        }
        $satirlar = $q->orderByDesc(self::TABLO.'.updated_at')->get();

        $puanlar = [];
        DB::table('salon_puanlar')->where('salon_id', $salonId)->whereIn('user_id', $satirlar->pluck('user_id')->unique()->values())
            ->whereBetween('puan', [1, 5])->get(['user_id', 'puan'])
            ->each(function ($p) use (&$puanlar) {
                $puanlar[$p->user_id] = (int) round($p->puan);
            });

        $liste = $satirlar->map(fn ($y) => [
            'id'                => (int) $y->id,
            'user_id'           => (int) $y->user_id,
            'kullanici_adi'     => trim((string) ($y->kullanici_adi ?? 'Musteri')),
            'yorum'             => (string) ($y->yorum ?? ''),
            'puan'              => $puanlar[$y->user_id] ?? 0,
            'tarih'             => $y->updated_at ? date('Y-m-d H:i:s', strtotime($y->updated_at)) : null,
            'bildirilen_sayisi' => $bildirim ? (int) ($y->bildirilen_sayisi ?? 0) : 0,
            'bildirim_sebep'    => $bildirim ? ($y->bildirim_sebep ?? null) : null,
            'bildirim_tarihi'   => $bildirim && $y->bildirim_tarihi ? date('Y-m-d H:i:s', strtotime($y->bildirim_tarihi)) : null,
        ]);

        return [
            'yorumlar'        => $liste->values(),
            'toplam'          => $liste->count(),
            'bildirilen_sayi' => $liste->where('bildirilen_sayisi', '>', 0)->count(),
        ];
    }

    public function salonu(int $id): ?int
    {
        $salon = DB::table(self::TABLO)->where('id', $id)->value('salon_id');

        return $salon ? (int) $salon : null;
    }

    public function sil(int $salonId, int $id, int $yetkiliId): void
    {
        DB::table(self::TABLO)->where('id', $id)->where('salon_id', $salonId)->delete();
        AktiviteLog::yaz($salonId, $yetkiliId, 'yorum_sil', 'salon_yorum', $id, null, 'Isletme moderasyonu: yorum silindi');
    }
}
