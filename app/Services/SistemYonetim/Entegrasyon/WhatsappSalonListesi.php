<?php

namespace App\Services\SistemYonetim\Entegrasyon;

use App\Domain\Whatsapp\BaglantiDurumu;
use App\Domain\Whatsapp\GonderimDurumu;
use App\Models\SistemYoneticisi;
use App\Services\SistemYonetim\SalonKapsami;
use App\Support\ListeIstegi;
use App\Support\Sema;
use Illuminate\Support\Facades\DB;

/**
 * WhatsApp kullanmis salonlar (eski salonlarData). Eski ekran tum salonlari ceker tarayicida suzerdi; burada sayfalama
 * sunucuda ve istatistik yalniz sayfadaki salonlar icin hesaplanir. Migration'i kosmamis kurulumlar icin
 * whatsapp_bridge_tipi / whatsapp_kontor kolonlari yoklanir.
 */
class WhatsappSalonListesi
{
    public const SIRALAMA = ['id' => 'id', 'salon' => 'salon_adi', 'durum' => 'whatsapp_durum', 'limit' => 'whatsapp_gunluk_limit'];

    private const LOG = 'whatsapp_gonderim_loglari';

    private const VARSAYILAN_GUNLUK_LIMIT = 150;

    private const LOG_PENCERESI_GUN = 30;

    private const HAFTA_GUN = 6;

    public function __construct(private SalonKapsami $kapsam) {}

    public function liste(array $l, ?string $durum, SistemYoneticisi $yonetici): array
    {
        $kopruVar = Sema::kolonVar('salonlar', 'whatsapp_bridge_tipi');
        $kontorVar = Sema::kolonVar('salonlar', 'whatsapp_kontor');

        $q = $this->kapsam->sorguyaUygula($this->kullananlar(), $yonetici);
        if ($durum !== null && $durum !== '') {
            $q->where('whatsapp_durum', $durum);
        }
        if ($desen = ListeIstegi::like($l['ara'])) {
            $q->where(fn ($w) => $w->where('salon_adi', 'like', $desen)->orWhere('whatsapp_numara', 'like', $desen));
        }

        $toplam = (clone $q)->count();
        $kolon = self::SIRALAMA[$l['sirala'] ?? ''] ?? null;
        $kolon ? $q->orderBy($kolon, $l['yon'] === 'asc' ? 'asc' : 'desc') : $q->orderByDesc('whatsapp_aktif')->orderBy('id');

        $salonlar = $q->forPage($l['page'], $l['per_page'])->get(array_merge(
            ['id', 'salon_adi', 'whatsapp_aktif', 'whatsapp_durum', 'whatsapp_numara', 'whatsapp_baglanti_tarihi', 'whatsapp_son_hata', 'whatsapp_gunluk_limit', 'whatsapp_saglayici'],
            $kopruVar ? ['whatsapp_bridge_tipi'] : [],
            $kontorVar ? ['whatsapp_kontor'] : [],
        ));

        [$bugun, $hafta] = $this->istatistik($salonlar->pluck('id')->all());

        return ['data' => $salonlar->map(fn ($s) => $this->satir($s, $bugun[$s->id] ?? [], (int) ($hafta[$s->id] ?? 0)))->all()]
            + ListeIstegi::meta($l['page'], $l['per_page'], $toplam);
    }

    private function kullananlar()
    {
        $esik = now()->subDays(self::LOG_PENCERESI_GUN)->toDateString().' 00:00:00';

        return DB::table('salonlar')->where(fn ($q) => $q->where('whatsapp_aktif', 1)
            ->orWhereNotNull('whatsapp_durum')
            ->orWhereExists(fn ($s) => $s->selectRaw('1')->from(self::LOG)->whereColumn(self::LOG.'.salon_id', 'salonlar.id')->where(self::LOG.'.created_at', '>=', $esik)));
    }

    /** @return array{0:array<int,array<int,int>>,1:array<int,int>} salon → durum → adet; salon → hafta toplami */
    private function istatistik(array $salonIdler): array
    {
        $bugun = [];
        DB::table(self::LOG)->whereIn('salon_id', $salonIdler)->where('created_at', '>=', now()->toDateString().' 00:00:00')
            ->selectRaw('salon_id, durum, COUNT(*) as adet')->groupBy('salon_id', 'durum')->get()
            ->each(function ($r) use (&$bugun) {
                $bugun[$r->salon_id][(int) $r->durum] = (int) $r->adet;
            });

        $hafta = DB::table(self::LOG)->whereIn('salon_id', $salonIdler)->where('created_at', '>=', now()->subDays(self::HAFTA_GUN)->toDateString().' 00:00:00')
            ->selectRaw('salon_id, COUNT(*) as adet')->groupBy('salon_id')->pluck('adet', 'salon_id')->all();

        return [$bugun, $hafta];
    }

    private function satir(object $s, array $bugun, int $hafta): array
    {
        $basari = $bugun[GonderimDurumu::Gonderildi->value] ?? 0;
        $fail = $bugun[GonderimDurumu::Basarisiz->value] ?? 0;
        $fallback = $bugun[GonderimDurumu::SmsDustu->value] ?? 0;
        $durum = $s->whatsapp_durum ? BaglantiDurumu::tanim($s->whatsapp_durum) : ['ad' => 'Pasif', 'renk' => 'gri'];

        return [
            'id'              => (int) $s->id,
            'salon_adi'       => $s->salon_adi,
            'aktif'           => (int) $s->whatsapp_aktif,
            'durum'           => $s->whatsapp_durum,
            'durum_ad'        => $durum['ad'],
            'durum_renk'      => $durum['renk'],
            'saglayici'       => $this->saglayici($s),
            'kontor'          => (int) ($s->whatsapp_kontor ?? 0),
            'numara'          => $s->whatsapp_numara,
            'baglanti_tarihi' => $s->whatsapp_baglanti_tarihi,
            'son_hata'        => $s->whatsapp_son_hata,
            'gunluk_limit'    => (int) (($s->whatsapp_gunluk_limit ?? 0) ?: self::VARSAYILAN_GUNLUK_LIMIT),
            'bugun_toplam'    => $basari + $fail + $fallback,
            'bugun_basari'    => $basari,
            'bugun_fail'      => $fail,
            'bugun_fallback'  => $fallback,
            'hafta_toplam'    => $hafta,
            'yonet_yol'       => '/v2/whatsapp?sube='.$s->id,
        ];
    }

    /** @return array{kod:string,ad:string} */
    private function saglayici(object $s): array
    {
        if (($s->whatsapp_saglayici ?? '') === 'cloud_api') {
            return ['kod' => 'cloud', 'ad' => 'Cloud API'];
        }

        return ($s->whatsapp_bridge_tipi ?? null) === 'baileys' ? ['kod' => 'baileys', 'ad' => 'Baileys'] : ['kod' => 'whatsmeow', 'ad' => 'whatsmeow'];
    }
}
