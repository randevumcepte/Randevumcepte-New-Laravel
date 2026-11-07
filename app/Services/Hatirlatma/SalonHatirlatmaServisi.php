<?php

namespace App\Services\Hatirlatma;

use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\KapsamServisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\KvkkMaske;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Salon hatırlatma kartları — eski SalonHatirlatmaController@feed karşılığı.
 * Genel kartlar (GenelHatirlatmalar) yalnız rol 1/2/3'e ve salon bazında önbellekli; arama randevusu
 * kullanıcıya özel, her rolde yalnız listenin atandığı personele döner.
 */
class SalonHatirlatmaServisi
{
    private const GENEL_ROLLER = [1, 2, 3];

    private const ONBELLEK_SN = 90;

    private const ARAMA_TAVANI = 20;

    public function __construct(
        private GuvenlikKapisi $kapi,
        private KapsamServisi $kapsam,
        private YetkiServisi $yetki,
        private GenelHatirlatmalar $genel,
    ) {}

    public function feed(int $yetkiliId, int $salonId): array
    {
        $this->kapi->kapsamDenetle($yetkiliId, $salonId);
        $roller = $this->kapsam->salonRolleri($yetkiliId, $salonId);

        $genel = array_intersect(self::GENEL_ROLLER, $roller)
            ? Cache::remember('v2.salon_hatirlatma.'.$salonId, self::ONBELLEK_SN, fn () => $this->genel->topla($salonId))
            : [];
        $liste = array_merge($this->aramaRandevulari($yetkiliId, $salonId, in_array(5, $roller, true)), $genel);
        usort($liste, fn ($a, $b) => $b['oncelik'] <=> $a['oncelik']);

        return ['sayi' => count($liste), 'hatirlatmalar' => $liste];
    }

    /** Bugün saati gelmiş, bu kullanıcının listesindeki arama randevuları. */
    private function aramaRandevulari(int $yetkiliId, int $salonId, bool $maskele): array
    {
        try {
            $personelId = DB::table('salon_personelleri')->where('yetkili_id', $yetkiliId)->where('salon_id', $salonId)->value('id');
            if (! $personelId) {
                return [];
            }
            $kayitlar = DB::table('aranacak_musteriler as am')
                ->join('arama_listesi as al', 'al.id', '=', 'am.arama_id')
                ->join('users as u', 'u.id', '=', 'am.user_id')
                ->where('al.salon_id', $salonId)->where('al.personel_id', $personelId)
                ->whereNotIn('al.durum', [2, 3])   // arşiv ve pasif liste bildirim üretmez
                ->where('am.durum', 3)->where('am.tarih', date('Y-m-d'))->where('am.saat', '<=', date('H:i:s'))
                ->orderBy('am.saat')->limit(self::ARAMA_TAVANI)
                ->get(['am.id', 'am.saat', 'u.name']);
            $notlar = $this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, 'musteri.not_yaz') ? $this->sonNotlar($kayitlar->pluck('id')->all()) : [];

            return $kayitlar->map(function ($k) use ($maskele, $notlar) {
                $ad = $maskele ? KvkkMaske::adSoyad($k->name) : $k->name;
                $not = $notlar[$k->id] ?? '';

                return [
                    'id' => 'arama_randevusu_'.$k->id, 'tip' => 'arama_randevusu', 'oncelik' => 5, 'tema' => 'mavi-cinglir', 'emoji' => '📞',
                    'baslik'   => 'Arama Randevusu',
                    'mesaj'    => $ad.' isimli müşteriniz aramanızı bekliyor.',
                    'altMesaj' => substr($k->saat, 0, 5).' için planlandı.'.($not !== '' ? ' Önceki not: '.mb_substr($not, 0, 80) : ''),
                    'cta_text' => 'Hemen Ara', 'aksiyon' => 'arama_baslat', 'aranacak_musteri_id' => (int) $k->id, 'sayac' => 1,
                ];
            })->all();
        } catch (\Throwable $e) {
            Log::warning('Salon hatırlatma [arama randevusu]: '.$e->getMessage());

            return [];
        }
    }

    /** @return array<int,string> aranacak müşteri id → en son dolu görüşme notu */
    private function sonNotlar(array $idler): array
    {
        if (! $idler) {
            return [];
        }

        return DB::table('gorusme_notlari')->whereIn('aranacak_musteri_id', $idler)
            ->whereNotNull('not')->where('not', '!=', '')->orderByDesc('id')->get(['aranacak_musteri_id', 'not'])
            ->unique('aranacak_musteri_id')->pluck('not', 'aranacak_musteri_id')->all();
    }
}
