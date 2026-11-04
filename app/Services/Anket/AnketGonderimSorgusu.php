<?php

namespace App\Services\Anket;

use App\Domain\Anket\AnketPuanlama;
use App\Domain\Anket\AnketSorulari;
use App\Domain\Anket\GonderimDurumu;
use App\Domain\Anket\GonderimFiltresi;
use App\Domain\Anket\ReputasyonEsikleri;
use App\Domain\Form\SablonSorulari;
use App\Domain\Rapor\RaporDonemi;
use App\Exceptions\BulunamadiException;
use App\Support\ListeIstegi;
use App\Support\Yetki\YetkiSabitleri;
use Illuminate\Support\Facades\DB;

/**
 * Anket gönderim listesi + cevap detayı — eski anketSonuclari (tüm kayıtları blade'e döküyordu) ve mobil
 * v1 anketGonderimleri / anketGonderimDetayApi'nin tek karşılığı. Telefon yetkiye göre maskelenir;
 * eski detay cevabındaki ham model alanları (ip, user_agent, token) dönmez (KVKK minimizasyon).
 */
class AnketGonderimSorgusu
{
    public const SIRALAMA = ['id' => 'id', 'ad_soyad' => 'ad_soyad', 'gonderim_zamani' => 'gonderim_zamani', 'cevap_zamani' => 'cevap_zamani', 'nps_skoru' => 'nps_skoru', 'csat_skoru' => 'csat_skoru'];

    /** "Düşük puan" koşulu — liste filtresi ile özet sayacı AYNI ifadeyi kullanır. Bağlar: [uyariNps, uyariCsat]. */
    public const DUSUK_PUAN_SQL = '(nps_skoru <= ? OR csat_skoru <= ?)';

    public function salonId(int $id): ?int
    {
        $s = DB::table('anket_gonderimleri')->where('id', $id)->value('salon_id');

        return $s ? (int) $s : null;
    }

    /**
     * @param  array{page:int, per_page:int, ara:?string, sirala:?string, yon:string}  $liste
     * @param  ?RaporDonemi  $donem  gonderim_zamani aralığı; null = tümü (mobil v1 gibi)
     */
    public function listele(int $salonId, array $liste, GonderimFiltresi $filtre, ?RaporDonemi $donem, ReputasyonEsikleri $esik, bool $telGor): array
    {
        $q = DB::table('anket_gonderimleri')->where('salon_id', $salonId);
        if ($donem) {
            $q->whereBetween('gonderim_zamani', [$donem->basSaat(), $donem->bitSaat()]);
        }
        match ($filtre) {
            GonderimFiltresi::CEVAPLI         => $q->where('cevaplandi', 1),
            GonderimFiltresi::GOOGLE_TIKLAYAN => $q->where('cevaplandi', 1)->where('google_yonlendirildi', 1),
            GonderimFiltresi::KOTU_PUAN       => $q->where('cevaplandi', 1)->whereRaw(self::DUSUK_PUAN_SQL, [$esik->uyariNps, $esik->uyariCsat]),
            GonderimFiltresi::TUM             => null,
        };
        if ($like = ListeIstegi::like($liste['ara'] ?? null)) {
            $q->where(function ($w) use ($like, $telGor) {
                $w->where('ad_soyad', 'like', $like)->orWhere('genel_yorum', 'like', $like);
                if ($telGor) {   // maskeli kullanıcı numarayı aramayla çıkaramasın
                    $w->orWhere('telefon', 'like', $like);
                }
            });
        }

        $toplam = (clone $q)->count();
        $satirlar = $q->orderBy(self::SIRALAMA[$liste['sirala'] ?? 'id'] ?? 'id', $liste['yon'] ?? 'desc')
            ->orderByDesc('id')
            ->forPage($liste['page'], $liste['per_page'])->get();

        return ['data' => $satirlar->map(fn ($g) => self::sunum($g, $telGor))->values()->all()]
            + ListeIstegi::meta($liste['page'], $liste['per_page'], $toplam);
    }

    /** Mobil v1 ile aynı zarf: {gonderim, sorular, cevaplar, sablon_ad, musteri_ad}. */
    public function detay(int $id, bool $telGor): array
    {
        $g = $this->satir($id);
        $sablon = DB::table('anket_sablonlari')->where('id', $g->sablon_id)->where('salon_id', $g->salon_id)->first(['ad', 'sorular_json']);
        $musteriAd = $g->user_id ? DB::table('users')->where('id', $g->user_id)->value('name') : null;

        return [
            'gonderim'   => self::sunum($g, $telGor),
            'sorular'    => AnketSorulari::coz($sablon->sorular_json ?? null),
            'cevaplar'   => SablonSorulari::coz($g->cevaplar_json),
            'sablon_ad'  => (string) ($sablon->ad ?? ''),
            'musteri_ad' => $musteriAd ?: $g->ad_soyad,
        ];
    }

    public function getir(int $id, bool $telGor): array
    {
        return self::sunum($this->satir($id), $telGor);
    }

    private function satir(int $id): object
    {
        $g = DB::table('anket_gonderimleri')->where('id', $id)->first();
        if (! $g) {
            throw new BulunamadiException('Anket gönderimi bulunamadı');
        }

        return $g;
    }

    private static function sunum(object $g, bool $telGor): array
    {
        $nps = $g->nps_skoru === null ? null : (int) $g->nps_skoru;
        $csat = $g->csat_skoru === null ? null : (float) $g->csat_skoru;
        $cevaplandi = (bool) $g->cevaplandi;

        return [
            'id'                         => (int) $g->id,
            'sablon_id'                  => (int) $g->sablon_id,
            'user_id'                    => $g->user_id ? (int) $g->user_id : null,
            'ad_soyad'                   => $g->ad_soyad,
            'telefon'                    => $telGor ? $g->telefon : YetkiSabitleri::telefonMaskele($g->telefon),
            'gonderim_kanali'            => $g->gonderim_kanali,
            'gonderim_zamani'            => $g->gonderim_zamani,
            'son_gecerlilik'             => $g->son_gecerlilik,
            'cevaplandi'                 => $cevaplandi,
            'cevap_zamani'               => $g->cevap_zamani,
            'durum'                      => GonderimDurumu::belirle($cevaplandi, $g->son_gecerlilik, now()->toDateTimeString())->dizi(),
            'nps_skoru'                  => $nps,
            'nps_sinifi'                 => AnketPuanlama::npsSinifi($nps),
            'csat_skoru'                 => $csat,
            'genel_yorum'                => $g->genel_yorum,
            'google_yonlendirildi'       => (bool) ($g->google_yonlendirildi ?? false),
            'kotu_puan_uyari_gonderildi' => (bool) ($g->kotu_puan_uyari_gonderildi ?? false),
            'webe_uygun'                 => AnketPuanlama::webeUygunMu($cevaplandi, $g->genel_yorum, $nps, $csat),
            'web_gizle'                  => (bool) ($g->web_gizle ?? false),
        ];
    }
}
