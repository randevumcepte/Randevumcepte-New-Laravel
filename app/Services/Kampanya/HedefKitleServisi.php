<?php

namespace App\Services\Kampanya;

use App\Domain\Kampanya\HedefKitle;
use App\Domain\Musteri\Segment;
use App\Exceptions\GecersizIslemException;
use App\Services\Musteri\MusteriOkumaServisi;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Kampanya HEDEF KİTLE motoru — eski musteriportfoydropliste karşılığı (sihirbazdaki canlı
 * sayaç + kayıtta katılımcı listesi AYNI sorgudan).
 *
 * Yeniden kullanılan kurallar (kopya yok):
 *   - Portföy tabanı + personel kapsamı : MusteriOkumaServisi::temelSorgu (aktif portföy)
 *   - Sadık / aktif / pasif             : Segment::sqlKosulu (müşteri listesi sekmeleriyle aynı)
 *
 * Eskiden bilinçli farklar: telefonsuz ve KARA LİSTEDEKİ müşteri kitleye girmez; pasif
 * (silinmiş) portföy kaydı girmez; aynı kişi iki kez listelenmez (DISTINCT).
 */
class HedefKitleServisi
{
    public function __construct(private MusteriOkumaServisi $musteri) {}

    public function sayi(int $salonId, HedefKitle $h, ?int $kisitPersonel): int
    {
        return (int) DB::query()->fromSub($this->sorgu($salonId, $h, $kisitPersonel), 'h')->count();
    }

    /** @return int[] kitledeki user_id'ler */
    public function idler(int $salonId, HedefKitle $h, ?int $kisitPersonel): array
    {
        return $this->sorgu($salonId, $h, $kisitPersonel)->orderBy('u.id')->pluck('u.id')->map(fn ($i) => (int) $i)->all();
    }

    /** Kitle etiketi (kampanya_yonetimi.musteri_turu) — grup adı DB'den. */
    public function etiket(int $salonId, HedefKitle $h): string
    {
        return $h->etiket($h->grupTuru ? $this->grupAdi($salonId, $h) : null);
    }

    /** Salonun hedeflenebilir grupları (eski form: grup_sms aktif_mi=1). */
    public function gruplar(int $salonId): array
    {
        return DB::table('grup_sms')->where('salon_id', $salonId)->where('aktif_mi', 1)->orderBy('grup_adi')
            ->get(['id', 'grup_adi'])
            ->map(fn ($g) => ['value' => 'haricigrup-'.$g->id, 'label' => (string) $g->grup_adi])->all();
    }

    private function sorgu(int $salonId, HedefKitle $h, ?int $kisitPersonel): Builder
    {
        $q = $this->musteri->temelSorgu($salonId, $kisitPersonel)
            ->whereNotNull('u.cep_telefon')->where('u.cep_telefon', '<>', '')
            ->where(fn ($w) => $w->whereNull('mp.kara_liste')->orWhere('mp.kara_liste', '<>', 1))
            ->select('u.id')->distinct();

        if (($c = $h->cinsiyetDb()) !== null) {
            $q->where('u.cinsiyet', $c);
        }

        if ($h->grupTuru) {
            $this->grupAdi($salonId, $h); // salona ait değilse 422
            $q->whereIn('u.id', fn ($s) => $s->select('user_id')->from('grup_sms_katilimcilari')->where('grup_id', $h->grupId));

            return $q;
        }

        if (($seg = $h->segment()) !== null) {
            $sayi = '(SELECT COUNT(*) FROM tahsilatlar t WHERE t.user_id = mp.user_id AND t.salon_id = '.(int) $salonId.')';
            $q->whereRaw(Segment::sqlKosulu($seg, $sayi));
        } elseif ($bas = $h->kayitBaslangici(now())) {
            $q->where('mp.created_at', '>=', $bas);
        }

        return $q;
    }

    private function grupAdi(int $salonId, HedefKitle $h): string
    {
        $ad = DB::table('grup_sms')->where('id', $h->grupId)->where('salon_id', $salonId)->value('grup_adi');

        return $ad !== null ? (string) $ad : throw new GecersizIslemException('Müşteri grubu bulunamadı');
    }
}
