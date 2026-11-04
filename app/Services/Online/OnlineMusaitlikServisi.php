<?php

namespace App\Services\Online;

use App\Domain\Randevu\HizmetZinciri;
use App\Domain\Randevu\OnlineMusaitlik;
use App\Domain\Randevu\OnlineSaatKurali;
use App\Exceptions\GecersizIslemException;
use Illuminate\Support\Facades\DB;

/**
 * Online randevu saat listesi + secilen saatin yerlesimi. Veri toplama burada, karar
 * OnlineMusaitlik'te. Saat listesi ve kayit AYNI yoldan gecer: listede gorunen saat kayitta da gecerlidir.
 *
 * Kalem girdisi: [['hizmet_id' => int, 'personel_id' => int|0]] (0 = farketmez).
 */
class OnlineMusaitlikServisi
{
    private const VARSAYILAN_SURE_DK = 60;
    private const VARSAYILAN_ARALIK_DK = 30;
    private const KAPALI_MESAJ = 'İşletme seçtiğiniz tarihte kapalıdır. Lütfen başka bir tarih seçiniz.';
    private const BOS_MESAJ = 'Seçtiğiniz tarihte uygun randevu saati bulunamadı.';

    public function __construct(private OnlineSalonKapisi $kapi, private OnlineSecenekServisi $secenek) {}

    /** @return array{saatler:array<int,array{saat:string,dolu:string}>,mesaj:?string} v1 alan adlari */
    public function saatler(int $salonId, string $tarih, array $kalemGirdi): array
    {
        $salon = $this->kapi->randevuyaAcikSalon($salonId);
        $this->tarihDogrula($tarih);

        $hazirlik = $this->hazirla($salon, $tarih, $kalemGirdi);
        if ($hazirlik === null) {
            return ['saatler' => [], 'mesaj' => self::KAPALI_MESAJ];
        }
        [$kalemler, $takvim] = $hazirlik;

        $aralik = (int) ($salon->randevu_saat_araligi ?: self::VARSAYILAN_ARALIK_DK);
        $simdi = $tarih === date('Y-m-d') ? HizmetZinciri::dakika(date('H:i')) : -1;
        $pencereler = $this->onlinePencereler($salon, $tarih);

        $bos = [];
        $tumu = [];
        foreach (OnlineMusaitlik::baslangiclar($takvim['salon'][0], $takvim['salon'][1], $aralik) as $t) {
            if ($t < $simdi) {
                continue;
            }
            $tumu[] = $t;
            if (OnlineSaatKurali::izinliMi($pencereler, $t) && OnlineMusaitlik::yerlestir($kalemler, $t, $takvim) !== null) {
                $bos[] = $t;
            }
        }
        if ($pencereler !== null) {
            $bos = OnlineSaatKurali::seyrelt($bos, (int) ($salon->online_gunluk_slot_limiti ?? 0));
        }

        return [
            'saatler' => array_map(fn ($t) => ['saat' => HizmetZinciri::saat($t), 'dolu' => in_array($t, $bos, true) ? '0' : '1'], $tumu),
            'mesaj'   => $bos ? null : self::BOS_MESAJ,
        ];
    }

    /**
     * Secilen saatin yerlesimi (kayit ani). Musteri tarafinda sert kural: sigmiyorsa null.
     * Gunluk seyreltme burada uygulanmaz; o yalniz sunulacak saatleri azaltir.
     */
    public function yerlesim(object $salon, string $tarih, string $saat, array $kalemGirdi): ?array
    {
        $this->tarihDogrula($tarih);
        $t = HizmetZinciri::dakika($saat);
        if ($tarih === date('Y-m-d') && $t < HizmetZinciri::dakika(date('H:i'))) {
            return null;
        }
        $hazirlik = $this->hazirla($salon, $tarih, $kalemGirdi);
        if ($hazirlik === null || ! OnlineSaatKurali::izinliMi($this->onlinePencereler($salon, $tarih), $t)) {
            return null;
        }

        return OnlineMusaitlik::yerlestir($hazirlik[0], $t, $hazirlik[1]);
    }

    /** Kilitlenecek personel id'leri (es zamanli kayit icin). */
    public function adayPersoneller(int $salonId, array $kalemGirdi): array
    {
        $idler = [];
        foreach ($this->kalemler($salonId, $kalemGirdi) as $k) {
            array_push($idler, ...$k['adaylar']);
        }
        $idler = array_values(array_unique($idler));
        sort($idler);

        return $idler;
    }

    public function hizmetDetaylari(int $salonId, array $hizmetIdler): array
    {
        $son = DB::table('salon_sunulan_hizmetler')->where('salon_id', $salonId)->whereIn('hizmet_id', $hizmetIdler)
            ->where('aktif', 1)->groupBy('hizmet_id')->selectRaw('MAX(id) as id')->pluck('id');

        return DB::table('salon_sunulan_hizmetler')->whereIn('id', $son)->get(['hizmet_id', 'sure_dk', 'baslangic_fiyat'])->keyBy('hizmet_id')->all();
    }

    private function tarihDogrula(string $tarih): void
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $tarih);
        if (! $d || $d->format('Y-m-d') !== $tarih) {
            throw new GecersizIslemException('Tarih geçersiz.');
        }
        $bugun = new \DateTimeImmutable('today');
        if ($d < $bugun || $d > $bugun->modify('+'.(int) config('online.maks_gun').' days')) {
            throw new GecersizIslemException('Bu tarih için randevu alınamaz.');
        }
    }

    /** @return array{0:array,1:array}|null salon o gun kapaliysa null */
    private function hazirla(object $salon, string $tarih, array $kalemGirdi): ?array
    {
        $salonId = (int) $salon->id;
        $gun = (int) date('N', strtotime($tarih));
        $calisma = DB::table('salon_calisma_saatleri')->where('salon_id', $salonId)->where('haftanin_gunu', $gun)->where('calisiyor', 1)->first();
        if (! $calisma || ! $calisma->baslangic_saati || ! $calisma->bitis_saati) {
            return null;
        }
        $kalemler = $this->kalemler($salonId, $kalemGirdi);
        $personeller = array_values(array_unique(array_merge(...array_column($kalemler, 'adaylar'))));

        return [$kalemler, $this->takvim($salonId, $tarih, $gun, $calisma, $personeller)];
    }

    private function kalemler(int $salonId, array $girdi): array
    {
        if (! $girdi) {
            throw new GecersizIslemException('En az bir hizmet seçin.');
        }
        $detay = $this->hizmetDetaylari($salonId, array_map(fn ($k) => (int) ($k['hizmet_id'] ?? 0), $girdi));

        return array_map(function ($k) use ($salonId, $detay) {
            $hizmetId = (int) ($k['hizmet_id'] ?? 0);
            if (! isset($detay[$hizmetId])) {
                throw new GecersizIslemException('Seçilen hizmet bu işletmede sunulmuyor.');
            }
            $uygun = $this->secenek->uygunPersonelIdleri($salonId, $hizmetId);
            $personel = (int) ($k['personel_id'] ?? 0);
            if ($personel && ! in_array($personel, $uygun, true)) {
                throw new GecersizIslemException('Seçilen personel bu hizmeti vermiyor.');
            }
            $sure = (int) $detay[$hizmetId]->sure_dk;

            return [
                'hizmet_id'      => $hizmetId,
                'adaylar'        => $personel ? [$personel] : $uygun,
                'sure'           => $sure >= 5 ? $sure : self::VARSAYILAN_SURE_DK,
                'hizmet_odalari' => $this->hizmetOdalari($salonId, $hizmetId),
            ];
        }, array_values($girdi));
    }

    private function hizmetOdalari(int $salonId, int $hizmetId): array
    {
        $odalar = DB::table('oda_sunulan_hizmetler')->where('salon_id', $salonId)->where('hizmet_id', $hizmetId)->pluck('oda_id');

        return DB::table('odalar')->whereIn('id', $odalar)->where('salon_id', $salonId)->where('aktifmi', 1)
            ->orderBy('takvim_sirasi')->pluck('id')->map(fn ($x) => (int) $x)->all();
    }

    private function takvim(int $salonId, string $tarih, int $gun, object $calisma, array $personeller): array
    {
        $salonPencere = [$this->dk($calisma->baslangic_saati), $this->dk($calisma->bitis_saati)];

        // Kayit yok = salon saatleri; calisiyor=0 = o gun kapali (null).
        $pencere = [];
        foreach (DB::table('personel_calisma_saatleri')->whereIn('personel_id', $personeller)->where('haftanin_gunu', $gun)->get() as $c) {
            $pencere[(int) $c->personel_id] = (int) $c->calisiyor === 1 && $c->baslangic_saati && $c->bitis_saati
                ? [max($salonPencere[0], $this->dk($c->baslangic_saati)), min($salonPencere[1], $this->dk($c->bitis_saati))]
                : null;
        }

        $dolu = [];
        $randevuKalemleri = DB::table('randevu_hizmetler as rh')->join('randevular as r', 'r.id', '=', 'rh.randevu_id')
            ->where('r.salon_id', $salonId)->where('r.tarih', $tarih)->where('r.durum', '<', 2)
            ->whereNotNull('rh.saat')->whereNotNull('rh.saat_bitis')
            ->get(['rh.personel_id', 'rh.oda_id', 'rh.saat', 'rh.saat_bitis']);
        $odaDolu = [];
        foreach ($randevuKalemleri as $k) {
            $aralik = [$this->dk($k->saat), $this->dk($k->saat_bitis)];
            if ($k->personel_id) {
                $dolu[(int) $k->personel_id][] = $aralik;
            }
            if ($k->oda_id) {
                $odaDolu[(int) $k->oda_id][] = $aralik;
            }
        }
        foreach (DB::table('personel_mola_saatleri')->whereIn('personel_id', $personeller)->where('haftanin_gunu', $gun)->where('mola_var', 1)->get() as $m) {
            if ($m->baslangic_saati && $m->bitis_saati) {
                $dolu[(int) $m->personel_id][] = [$this->dk($m->baslangic_saati), $this->dk($m->bitis_saati)];
            }
        }
        $salonMola = [];
        foreach (DB::table('salon_mola_saatleri')->where('salon_id', $salonId)->where('haftanin_gunu', $gun)->where('mola_var', 1)->get() as $m) {
            if ($m->baslangic_saati && $m->bitis_saati) {
                $salonMola[] = [$this->dk($m->baslangic_saati), $this->dk($m->bitis_saati)];
            }
        }

        return [
            'salon'        => $salonPencere,
            'salon_mola'   => $salonMola,
            'pencere'      => $pencere,
            'dolu'         => $dolu,
            'personel_oda' => DB::table('odalar')->where('salon_id', $salonId)->where('aktifmi', 1)->whereIn('personel_id', $personeller)
                ->pluck('id', 'personel_id')->map(fn ($x) => (int) $x)->all(),
            'oda_dolu'     => $odaDolu,
        ];
    }

    /** Salon sahibinin online saat kurasyonu: null = kisitlama yok, [] = kapali, yoksa pencereler. */
    private function onlinePencereler(object $salon, string $tarih): ?array
    {
        if ((int) ($salon->online_saat_kisitlama_aktif ?? 0) !== 1) {
            return null;
        }
        $istisnalar = DB::table('salon_online_randevu_istisnalari')->where('salon_id', $salon->id)->where('tarih', $tarih)->get();
        if ($istisnalar->isNotEmpty()) {
            if ($istisnalar->contains(fn ($i) => $i->tip === 'kapali')) {
                return [];
            }

            return $this->pencereDizisi($istisnalar->where('tip', 'ozel'));
        }

        return $this->pencereDizisi(DB::table('salon_online_randevu_saatleri')->where('salon_id', $salon->id)
            ->where('haftanin_gunu', (int) date('N', strtotime($tarih)))->get());
    }

    private function pencereDizisi($satirlar): array
    {
        $sonuc = [];
        foreach ($satirlar as $s) {
            if ($s->baslangic_saati && $s->bitis_saati) {
                $sonuc[] = [$this->dk($s->baslangic_saati), $this->dk($s->bitis_saati)];
            }
        }

        return $sonuc;
    }

    private function dk(string $saat): int
    {
        return HizmetZinciri::dakika(substr($saat, 0, 5));
    }
}
