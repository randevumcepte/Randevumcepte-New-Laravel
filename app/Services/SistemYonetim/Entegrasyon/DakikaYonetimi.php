<?php

namespace App\Services\SistemYonetim\Entegrasyon;

use App\Models\SistemYoneticisi;
use App\Services\Santral\DakikaServisi;
use App\Services\SistemYonetim\Denetim;
use App\Services\SistemYonetim\SalonKapsami;
use App\Support\DiziSayfasi;
use App\Support\ListeIstegi;
use Illuminate\Support\Facades\DB;

/**
 * E-santral musterilerinin dakika havuzu (eski PanelController::dakika). Kullanim santralden hesaplandigi icin
 * siralama, toplam ve sayfalama hesap sonrasi bellekte; havuz hesabi DakikaServisi'nde.
 */
class DakikaYonetimi
{
    private const ESANTRAL_UYELIK = 3;

    public const SIRALAMA = ['salon' => 'salon_adi', 'sayim' => 'sayim_baslangic', 'tanimli' => 'tanimli', 'kullanilan' => 'kullanilan', 'kalan' => 'kalan', 'yuzde' => 'yuzde'];

    public function __construct(private DakikaServisi $dakika, private SalonKapsami $kapsam) {}

    public function liste(array $l, SistemYoneticisi $yonetici): array
    {
        $trunklu = DB::table('sabit_numaralar')->whereNotNull('numara')->where('numara', '!=', '')->select('salon_id');
        $q = $this->kapsam->sorguyaUygula(DB::table('salonlar'), $yonetici)
            ->whereIn('id', $trunklu)
            ->where('uyelik_turu', self::ESANTRAL_UYELIK)
            ->where('uyelik_bitis_tarihi', '>=', date('Y-m-d'));
        if ($desen = ListeIstegi::like($l['ara'])) {
            $q->where(fn ($w) => $w->where('salon_adi', 'like', $desen)->orWhere('yetkili_adi', 'like', $desen)->orWhere('yetkili_telefon', 'like', $desen));
        }
        $adlar = $q->pluck('salon_adi', 'id');

        $satirlar = [];
        foreach ($this->dakika->yonetimOzetleri($adlar->keys()->map(fn ($id) => (int) $id)->all()) as $salonId => $ozet) {
            $satirlar[] = ['salon_id' => $salonId, 'salon_adi' => (string) $adlar[$salonId]] + $ozet;
        }
        $tanimli = array_sum(array_column($satirlar, 'tanimli'));
        $kullanilan = round(array_sum(array_column($satirlar, 'kullanilan')), 1);

        return ['ozet' => ['tanimli' => $tanimli, 'kullanilan' => $kullanilan, 'kalan' => round($tanimli - $kullanilan, 1)]]
            + DiziSayfasi::sayfala($satirlar, ['ara' => null] + $l, fn () => '', self::SIRALAMA);
    }

    public function kaydet(object $salon, int $tanimli, ?string $sayim, SistemYoneticisi $yonetici): void
    {
        $eski = DB::table('salon_dakika_paketi')->where('salon_id', $salon->id)->first(['tanimli_dakika', 'sayim_baslangic']);
        DB::table('salon_dakika_paketi')->updateOrInsert(['salon_id' => $salon->id], [
            'tanimli_dakika'  => $tanimli,
            'sayim_baslangic' => $sayim ?: null,
            'guncelleyen'     => $yonetici->name,
            'updated_at'      => now(),
        ] + ($eski ? [] : ['created_at' => now()]));

        Denetim::yaz('salon_dakika_kaydet', 'salon', (int) $salon->id, $salon->salon_adi, 'Tanımlı dakika: '.$tanimli, [
            'eski' => ['tanimli' => $eski->tanimli_dakika ?? null, 'sayim' => $eski->sayim_baslangic ?? null],
            'yeni' => ['tanimli' => $tanimli, 'sayim' => $sayim ?: null],
        ]);
    }
}
