<?php

namespace App\Services\SistemYonetim\Entegrasyon;

use App\Domain\Whatsapp\BaglantiDurumu;
use App\Domain\Whatsapp\GonderimDurumu;
use App\Domain\Whatsapp\GonderimOzeti;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tum salonlar WhatsApp istatistikleri (eski WhatsAppPanelController dashboardData, grafikData, tipDagilim).
 * Ekran 30 sn'de bir yoklar ve created_at tek basina indeksli degil: sonuclar kisa sureli onbellekli, tarih kosulu aralik.
 */
class WhatsappPanelIstatistik
{
    private const TABLO = 'whatsapp_gonderim_loglari';

    private const BAN_RISK_DURUMLARI = ['banned-or-loggedout', 'auto-paused-ban-risk', 'rate-limited'];

    private const OZET_ONBELLEK_SN = 30;

    private const DAGILIM_ONBELLEK_SN = 300;

    private const EN_COK_SALON = 10;

    public const DAGILIM_MAKS_GUN = 365;

    /** bugun/hafta/ay + basari orani + 30 gunluk trend (gunler) + aktif ve riskli salon sayisi. */
    public function ozet(): array
    {
        return Cache::remember('sy.wa.ozet', self::OZET_ONBELLEK_SN, function () {
            $bugun = date('Y-m-d');
            $satirlar = DB::table(self::TABLO)
                ->selectRaw('DATE(created_at) as tarih, durum, COUNT(*) as adet')
                ->where('created_at', '>=', GonderimOzeti::ilkGun($bugun).' 00:00:00')
                ->groupBy('tarih', 'durum')
                ->get();

            return GonderimOzeti::hesapla($satirlar, $bugun) + [
                'aktif_salon'     => DB::table('salonlar')->where('whatsapp_aktif', 1)->where('whatsapp_durum', BaglantiDurumu::BAGLI)->count(),
                'ban_risk_salon'  => DB::table('salonlar')->whereIn('whatsapp_durum', self::BAN_RISK_DURUMLARI)->count(),
            ];
        });
    }

    /** Mesaj turu dagilimi (mesaj kalibina gore) + en cok gonderen salonlar. */
    public function dagilim(int $gun): array
    {
        return Cache::remember('sy.wa.dagilim.'.$gun, self::DAGILIM_ONBELLEK_SN, function () use ($gun) {
            $bas = now()->subDays($gun - 1)->toDateString().' 00:00:00';
            $satirlar = DB::table(self::TABLO)
                ->selectRaw("CASE
                    WHEN mesaj LIKE 'Yarın%' OR mesaj LIKE '%Yarın%saatinde%' THEN '1 gün öncesi hatırlatma'
                    WHEN mesaj LIKE 'Bugün%' OR mesaj LIKE '%Bugün%saatinde%' THEN 'Yaklaşan hatırlatma'
                    WHEN mesaj LIKE '%iptal edilmiştir%' OR mesaj LIKE '%reddedilmiştir%' THEN 'İptal/Red'
                    WHEN mesaj LIKE '%güncellenmiştir%' THEN 'Güncelleme'
                    ELSE 'Diğer'
                END as tip, durum, COUNT(*) as adet")
                ->where('created_at', '>=', $bas)
                ->groupBy('tip', 'durum')
                ->get();

            $en = DB::table(self::TABLO.' as wl')
                ->leftJoin('salonlar as s', 's.id', '=', 'wl.salon_id')
                ->selectRaw('wl.salon_id, s.salon_adi, COUNT(*) as adet')
                ->where('wl.created_at', '>=', $bas)
                ->groupBy('wl.salon_id', 's.salon_adi')
                ->orderByDesc('adet')
                ->limit(self::EN_COK_SALON)
                ->get();

            return [
                'gun'       => $gun,
                'tipler'    => $this->tipleriTopla($satirlar),
                'en_cok'    => $en->map(fn ($s) => ['salon_id' => (int) $s->salon_id, 'salon_adi' => $s->salon_adi ?: '#'.$s->salon_id, 'adet' => (int) $s->adet])->all(),
            ];
        });
    }

    private function tipleriTopla(iterable $satirlar): array
    {
        $tipler = [];
        foreach ($satirlar as $r) {
            $tipler[$r->tip] ??= ['tip' => $r->tip, 'toplam' => 0, 'basari' => 0, 'fail' => 0, 'fallback' => 0];
            $adet = (int) $r->adet;
            $tipler[$r->tip]['toplam'] += $adet;
            match (GonderimDurumu::tryFrom((int) $r->durum)) {
                GonderimDurumu::Gonderildi => $tipler[$r->tip]['basari'] += $adet,
                GonderimDurumu::Basarisiz  => $tipler[$r->tip]['fail'] += $adet,
                GonderimDurumu::SmsDustu   => $tipler[$r->tip]['fallback'] += $adet,
                default                    => null,
            };
        }
        usort($tipler, fn ($a, $b) => $b['toplam'] <=> $a['toplam']);

        return $tipler;
    }
}
