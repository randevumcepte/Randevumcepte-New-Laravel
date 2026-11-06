<?php

namespace App\Services\SistemYonetim\Izleme;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Sistem paneli ana ekrani: metrikler, son hareketler, bekleyen talepler ve trend grafikleri. Agir sayimlar 5 dk onbellekte. */
class DashboardServisi
{
    private const ONBELLEK_SN = 300;

    private const ACIK_DURUMLAR = ['acik', 'islemde', 'bekliyor'];

    private const ONCELIK_RENK = ['acil' => 'danger', 'yuksek' => 'warning', 'orta' => 'info', 'dusuk' => 'muted'];

    private const GRAFIK_GUNLERI = [7, 30, 90];

    private const VARSAYILAN_GRAFIK_GUN = 30;

    public function ozet(): array
    {
        return [
            'metrikler'          => Cache::remember('sy.dashboard.metrikler', self::ONBELLEK_SN, fn () => $this->metrikler()),
            'son_aktiviteler'    => DB::table('sistemyonetim_audit_log')->orderByDesc('id')->limit(15)->get(IzlemeSunumu::AKTIVITE_KOLONLARI)
                ->map(fn ($k) => IzlemeSunumu::aktivite($k))->all(),
            'bekleyen_talepler'  => $this->bekleyenTalepler(),
            'son_girisler'       => DB::table('sistemyonetim_login_loglari')->orderByDesc('id')->limit(10)->get(IzlemeSunumu::GIRIS_KOLONLARI)
                ->map(fn ($k) => IzlemeSunumu::giris($k))->all(),
            'trend'              => Cache::remember('sy.dashboard.trend', self::ONBELLEK_SN, fn () => $this->yediGunlukTrend()),
            'grafik_gunleri'     => self::GRAFIK_GUNLERI,
            'grafik_varsayilan'  => self::VARSAYILAN_GRAFIK_GUN,
        ];
    }

    /** Gecersiz gun degeri varsayilana duser (eski dashboardChartData: 7-90). */
    public function grafik(int $gun): array
    {
        if ($gun < 7 || $gun > 90) {
            $gun = self::VARSAYILAN_GRAFIK_GUN;
        }

        return Cache::remember("sy.dashboard.grafik.{$gun}", self::ONBELLEK_SN, fn () => $this->grafikHesapla($gun));
    }

    private function metrikler(): array
    {
        $salon = DB::table('salonlar')->selectRaw('
            COUNT(*) as toplam,
            SUM(CASE WHEN askiya_alindi = 0 THEN 1 ELSE 0 END) as aktif,
            SUM(CASE WHEN askiya_alindi = 1 THEN 1 ELSE 0 END) as askida,
            SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as bugun,
            SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as hafta
        ', [IzlemeZamani::gunBasi(), IzlemeZamani::gunBasi(7)])->first();

        $talep = DB::table('sistemyonetim_destek_talepleri')->selectRaw("
            SUM(CASE WHEN durum IN ('acik','islemde','bekliyor') THEN 1 ELSE 0 END) as acik,
            SUM(CASE WHEN durum IN ('acik','islemde') AND oncelik = 'acil' THEN 1 ELSE 0 END) as acil
        ")->first();

        return [
            'toplam_salon'     => (int) $salon->toplam,
            'aktif_salon'      => (int) $salon->aktif,
            'askida_salon'     => (int) $salon->askida,
            'bugun_yeni_salon' => (int) $salon->bugun,
            'hafta_yeni_salon' => (int) $salon->hafta,
            'toplam_yetkili'   => DB::table('isletmeyetkilileri')->count(),
            'toplam_personel'  => DB::table('salon_personelleri')->count(),
            'toplam_randevu'   => $this->yaklasikRandevuSayisi(),
            'bugun_randevu'    => DB::table('randevular')->where('created_at', '>=', IzlemeZamani::gunBasi())->count(),
            'acik_ticket'      => (int) ($talep->acik ?? 0),
            'acil_ticket'      => (int) ($talep->acil ?? 0),
            'aktif_ekip'       => DB::table('sistemyoneticileri')->where('aktif', 1)->count(),
        ];
    }

    /** Buyuk tabloda tam tarama yerine istatistik satiri (yaklasik deger, eskisiyle ayni). */
    private function yaklasikRandevuSayisi(): int
    {
        try {
            return (int) (DB::selectOne("SELECT TABLE_ROWS AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'randevular'")->c ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function bekleyenTalepler(): array
    {
        return DB::table('sistemyonetim_destek_talepleri')->whereIn('durum', self::ACIK_DURUMLAR)
            ->orderByRaw("CASE oncelik WHEN 'acil' THEN 1 WHEN 'yuksek' THEN 2 WHEN 'orta' THEN 3 WHEN 'dusuk' THEN 4 ELSE 0 END")
            ->orderByDesc('id')->limit(8)
            ->get(['id', 'numara', 'konu', 'salon_adi', 'oncelik', 'created_at'])
            ->map(fn ($t) => [
                'id'          => (int) $t->id,
                'numara'      => $t->numara,
                'konu'        => $t->konu,
                'salon_adi'   => $t->salon_adi,
                'oncelik'     => $t->oncelik,
                'oncelik_renk' => self::ONCELIK_RENK[$t->oncelik] ?? 'muted',
                'zaman_once'  => IzlemeZamani::once($t->created_at),
            ])->all();
    }

    private function yediGunlukTrend(): array
    {
        $baslangic = IzlemeZamani::gunBasi(6);
        $salon = $this->gunlukSayim('salonlar', $baslangic);
        $randevu = $this->gunlukSayim('randevular', $baslangic);

        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $gun = IzlemeZamani::simdi()->subDays($i)->format('Y-m-d');
            $trend[] = ['tarih' => $gun, 'salon' => $salon[$gun] ?? 0, 'randevu' => $randevu[$gun] ?? 0];
        }

        return $trend;
    }

    /** Eski kod gun basina 3 sorgu atiyordu (90 gunde 270); her seri tek GROUP BY. */
    private function grafikHesapla(int $gun): array
    {
        $baslangic = IzlemeZamani::gunBasi($gun - 1);
        $salon = $this->gunlukSayim('salonlar', $baslangic);
        $randevu = $this->gunlukSayim('randevular', $baslangic);
        $talep = $this->gunlukSayim('sistemyonetim_destek_talepleri', $baslangic);

        $seri = ['tarihler' => [], 'salon' => [], 'randevu' => [], 'ticket' => []];
        for ($i = $gun - 1; $i >= 0; $i--) {
            $tarih = IzlemeZamani::simdi()->subDays($i);
            $anahtar = $tarih->format('Y-m-d');
            $seri['tarihler'][] = $tarih->format('d.m');
            $seri['salon'][] = $salon[$anahtar] ?? 0;
            $seri['randevu'][] = $randevu[$anahtar] ?? 0;
            $seri['ticket'][] = $talep[$anahtar] ?? 0;
        }

        return $seri + [
            'kategori_dagilim' => $this->dagilim('kategori', IzlemeZamani::gunBasi($gun)),
            'durum_dagilim'    => $this->dagilim('durum'),
        ];
    }

    /** @return array<string,int> Y-m-d => adet */
    private function gunlukSayim(string $tablo, string $baslangic): array
    {
        return DB::table($tablo)->where('created_at', '>=', $baslangic)
            ->selectRaw('DATE(created_at) as tarih, COUNT(*) as adet')->groupBy(DB::raw('DATE(created_at)'))
            ->pluck('adet', 'tarih')->map(fn ($a) => (int) $a)->all();
    }

    /** @return array<string,int> */
    private function dagilim(string $kolon, ?string $baslangic = null): array
    {
        return DB::table('sistemyonetim_destek_talepleri')
            ->when($baslangic, fn ($q, $b) => $q->where('created_at', '>=', $b))
            ->selectRaw("{$kolon}, COUNT(*) as adet")->groupBy($kolon)
            ->pluck('adet', $kolon)->map(fn ($a) => (int) $a)->all();
    }
}
