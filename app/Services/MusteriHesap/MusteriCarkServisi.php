<?php

namespace App\Services\MusteriHesap;

use App\Domain\Cark\CarkOdulBasligi;
use App\Domain\Cark\DilimTipi;
use App\Exceptions\GecersizIslemException;
use App\Support\SalonIdListesi;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Carkifelek + puan odulleri, musteri tarafi (eski CarkifelekApiController). Kimlik token'dan: eski uclar
 * govdedeki user_id ile baskasi adina cevirme ve puan harcamaya izin veriyordu. Cevirme ve puan harcama
 * satir kilidi altinda: cift dokunus iki hak harcayamaz, puan eksiye dusmez.
 * Hata metinleri uygulamanin bugun gosterdigi metinlerdir.
 */
class MusteriCarkServisi
{
    private const INDIRIM_TIPLERI = [DilimTipi::HIZMET, DilimTipi::URUN, DilimTipi::PAKET];

    public function __construct(private MusteriSalonKapisi $kapi) {}

    public function durum(int $userId, int $salonId): array
    {
        $salon = DB::table('salonlar')->where('id', $salonId)->first(['id', 'salon_adi']);
        if (! $salon) {
            throw new GecersizIslemException('Salon bulunamadı.');
        }
        $salonOzet = ['id' => (int) $salon->id, 'salon_adi' => $salon->salon_adi];
        $cark = $this->cark($salonId);
        if (! $cark) {
            return ['aktif' => false, 'message' => 'Bu salonda çarkıfelek aktif değil.', 'salon' => $salonOzet];
        }
        $dilimler = $this->dilimler($cark);
        if ($dilimler->count() < 2) {
            return ['aktif' => false, 'message' => 'Çark henüz hazırlanmamış.', 'salon' => $salonOzet];
        }
        $sonraki = $this->sonrakiHak($cark, $salonId, $userId);
        $kilitli = $sonraki !== null && Carbon::today()->lt($sonraki);

        return [
            'aktif'    => true,
            'salon'    => $salonOzet,
            'cark_id'  => (int) $cark->id,
            'dilimler' => $dilimler->map(fn ($d) => [
                'id' => (int) $d->id, 'ismi' => $d->dilim_ismi, 'renk' => $d->renk_kodu, 'tip' => $d->tip ?? DilimTipi::BOS,
                'deger' => $d->deger !== null ? (float) $d->deger : null, 'indirim_tipi' => $d->indirim_tipi ?? DilimTipi::YUZDE, 'sira' => (int) $d->sira,
            ])->values()->all(),
            'kalanHak'          => $kilitli ? 0 : 1,
            'randevuIdleri'     => [],
            'bugunCevirdi'      => $kilitli,
            'cevirmeAraligiGun' => $this->aralik($cark),
            'sonrakiHakTarihi'  => $sonraki?->format('d.m.Y'),
            'yarinSaat'         => ($sonraki ?? Carbon::tomorrow())->format('d.m.Y H:i'),
        ];
    }

    public function cevir(int $userId, int $salonId): array
    {
        $this->kapi->denetle($userId, $salonId);

        return DB::transaction(function () use ($userId, $salonId) {
            $cark = DB::table('carkifelek_sistemi')->where('salon_id', $salonId)->lockForUpdate()->first();
            if (! $cark || ! $cark->aktifmi) {
                throw new GecersizIslemException('Çarkıfelek şu an aktif değil.');
            }
            $sonraki = $this->sonrakiHak($cark, $salonId, $userId);
            if ($sonraki !== null && Carbon::today()->lt($sonraki)) {
                throw new GecersizIslemException($this->aralik($cark) <= 1
                    ? 'Bugün çarkı çevirdiniz. Yarın tekrar deneyebilirsiniz.'
                    : 'Çarkı çevirdiniz. Bir sonraki çevirme: '.$sonraki->format('d.m.Y').'.');
            }
            $dilimler = $this->dilimler($cark);
            if ($dilimler->count() < 2) {
                throw new GecersizIslemException('Çark henüz hazırlanmamış.');
            }
            $secilen = $this->sec($dilimler) ?? throw new GecersizIslemException('Dilim seçilemedi.');
            $baslik = CarkOdulBasligi::uret((string) $secilen->tip, $secilen->deger !== null ? (float) $secilen->deger : null, $secilen->dilim_ismi, $secilen->indirim_tipi ?? null);

            $logId = (int) DB::table('carkifelek_cevirme_loglari')->insertGetId([
                'cark_id' => $cark->id, 'salon_id' => $salonId, 'user_id' => $userId, 'randevu_id' => null, 'dilim_id' => $secilen->id,
                'tip' => $secilen->tip, 'deger' => $secilen->deger, 'dilim_ismi' => $secilen->dilim_ismi, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $kupon = null;
            if ($secilen->tip === DilimTipi::PUAN && $secilen->deger) {
                $this->puanEkle($salonId, $userId, (float) $secilen->deger);
            } elseif (in_array($secilen->tip, self::INDIRIM_TIPLERI, true) && $secilen->deger) {
                $kupon = $this->kuponYaz([
                    'log_id' => $logId, 'salon_id' => $salonId, 'user_id' => $userId, 'tip' => $secilen->tip, 'deger' => $secilen->deger, 'baslik' => $baslik,
                    'gecerlilik_tarihi' => $this->kuponBitisi($cark, 'kupon_cark_gecerlilik_gun'),
                    'indirim_tipi' => ($secilen->indirim_tipi ?? null) === DilimTipi::TUTAR ? DilimTipi::TUTAR : DilimTipi::YUZDE,
                    'gecerli_salonlar' => $cark->gecerli_salonlar ?: json_encode([$salonId]),
                ]);
            }

            return [
                'dilimIndex' => (int) $dilimler->search(fn ($d) => $d->id === $secilen->id),
                'dilim' => [
                    'id' => (int) $secilen->id, 'ismi' => $secilen->dilim_ismi, 'tip' => $secilen->tip,
                    'deger' => $secilen->deger !== null ? (float) $secilen->deger : null, 'baslik' => $baslik,
                ],
                'odulKodu' => $kupon['kod'] ?? null,
                'kalanHak' => 0,
            ] + $this->subeBilgisi($kupon, $salonId);
        });
    }

    public function odullerim(int $userId, ?string $bundle): array
    {
        $satirlar = DB::table('carkifelek_odulleri')->where('user_id', $userId)
            ->when($bundle, fn ($q) => $q->whereIn('salon_id', DB::table('salonlar')->where('app_bundle', $bundle)->pluck('id')))
            ->orderByDesc('created_at')->get();
        $adlar = DB::table('salonlar')->whereIn('id', $satirlar->pluck('salon_id'))->pluck('salon_adi', 'id');

        return $satirlar->map(fn ($o) => [
            'id' => (int) $o->id, 'salon_id' => (int) $o->salon_id, 'salon_adi' => $adlar[$o->salon_id] ?? 'Salon', 'kod' => $o->kod, 'tip' => $o->tip,
            'deger' => $o->deger !== null ? (float) $o->deger : null, 'baslik' => $o->baslik, 'kullanildi' => (int) $o->kullanildi,
            'kullanim_tarihi' => $o->kullanim_tarihi, 'gecerlilik_tarihi' => $o->gecerlilik_tarihi ? substr((string) $o->gecerlilik_tarihi, 0, 10) : null,
            'created_at' => $o->created_at,
        ] + $this->gosterim((array) $o, (int) $o->salon_id))->all();
    }

    public function puanOdullerim(int $userId, ?string $bundle, int $salonId): array
    {
        $markaSalonlari = $bundle ? DB::table('salonlar')->where('app_bundle', $bundle)->pluck('id')->map(fn ($x) => (int) $x)->all() : null;
        if ($markaSalonlari !== null && $salonId > 0 && ! in_array($salonId, $markaSalonlari, true)) {
            $salonId = 0;
        }
        $kayitlar = DB::table('salon_sadakat_puanlari')->where('user_id', $userId)->where('puan', '>', 0)
            ->when($markaSalonlari !== null, fn ($q) => $q->whereIn('salon_id', $markaSalonlari))->get(['salon_id', 'puan']);
        if ($kayitlar->isEmpty() && $salonId === 0) {
            return ['bos' => true, 'puanKayitlari' => [], 'odulSeviyeleri' => []];
        }
        $salonId = $salonId ?: (int) $kayitlar->first()->salon_id;
        $salon = DB::table('salonlar')->where('id', $salonId)->first(['id', 'salon_adi']);
        if (! $salon) {
            throw new GecersizIslemException('Salon bulunamadı.');
        }
        $adlar = DB::table('salonlar')->whereIn('id', $kayitlar->pluck('salon_id'))->pluck('salon_adi', 'id');

        return [
            'bos'            => false,
            'salon'          => ['id' => (int) $salon->id, 'salon_adi' => $salon->salon_adi],
            'puanBakiyesi'   => (float) (DB::table('salon_sadakat_puanlari')->where('user_id', $userId)->where('salon_id', $salonId)->value('puan') ?: 0),
            'odulSeviyeleri' => DB::table('salon_puan_odulleri')->where('salon_id', $salonId)->where('aktif', 1)->orderBy('puan_esigi')->get()->map(fn ($o) => [
                'id' => (int) $o->id, 'salon_id' => (int) $o->salon_id, 'puan_esigi' => (int) $o->puan_esigi, 'baslik' => $o->baslik, 'aciklama' => $o->aciklama,
                'tip' => $o->tip, 'deger' => $o->deger !== null ? (float) $o->deger : null, 'sira' => (int) $o->sira,
            ])->all(),
            'puanKayitlari'  => $kayitlar->map(fn ($k) => ['salon_id' => (int) $k->salon_id, 'salon_adi' => $adlar[$k->salon_id] ?? 'Salon', 'puan' => (float) $k->puan])->values()->all(),
        ];
    }

    public function puanOdulTalep(int $userId, int $salonId, int $odulId): array
    {
        $this->kapi->denetle($userId, $salonId);

        return DB::transaction(function () use ($userId, $salonId, $odulId) {
            $odul = DB::table('salon_puan_odulleri')->where('id', $odulId)->where('salon_id', $salonId)->where('aktif', 1)->first();
            if (! $odul) {
                throw new GecersizIslemException('Ödül bulunamadı veya pasif.');
            }
            $puan = DB::table('salon_sadakat_puanlari')->where('salon_id', $salonId)->where('user_id', $userId)->lockForUpdate()->first();
            $mevcut = $puan ? (float) $puan->puan : 0;
            if ($mevcut < $odul->puan_esigi) {
                throw new GecersizIslemException('Yetersiz puan. Gerekli: '.$odul->puan_esigi.', mevcut: '.((int) $mevcut));
            }
            $kalan = $mevcut - (float) $odul->puan_esigi;
            DB::table('salon_sadakat_puanlari')->where('id', $puan->id)->update(['puan' => $kalan, 'updated_at' => now()]);

            $cark = DB::table('carkifelek_sistemi')->where('salon_id', $salonId)->first();
            $kupon = $this->kuponYaz([
                'log_id' => null, 'salon_id' => $salonId, 'user_id' => $userId,
                'tip' => in_array($odul->tip, self::INDIRIM_TIPLERI, true) ? $odul->tip : DilimTipi::HIZMET,
                // "Hediye" bedavadir: tahsilatta %100 hizmet indirimi olur.
                'deger' => $odul->tip === 'hediye' ? 100 : ($odul->deger ?: 0),
                'baslik' => $odul->baslik,
                'gecerlilik_tarihi' => $cark ? $this->kuponBitisi($cark, 'kupon_puan_gecerlilik_gun') : null,
                'gecerli_salonlar' => ($odul->gecerli_salonlar ?? null) ?: json_encode([$salonId]),
            ]);

            return ['kod' => $kupon['kod'], 'baslik' => $kupon['baslik'], 'kalanPuan' => (int) $kalan] + $this->subeBilgisi($kupon, $salonId);
        });
    }

    private function cark(int $salonId): ?object
    {
        $cark = DB::table('carkifelek_sistemi')->where('salon_id', $salonId)->first();

        return $cark && $cark->aktifmi ? $cark : null;
    }

    private function dilimler(object $cark)
    {
        return DB::table('carkifelek_dilimleri')->where('cark_id', $cark->id)->orderBy('sira')->get();
    }

    private function aralik(object $cark): int
    {
        $gun = (int) ($cark->cevirme_araligi_gun ?? 1);

        return $gun > 0 ? $gun : 1;
    }

    /** Son gecerli cevirme + aralik; hic cevirmediyse null. "tekrar_dene" hak harcamaz. */
    private function sonrakiHak(object $cark, int $salonId, int $userId): ?Carbon
    {
        $son = DB::table('carkifelek_cevirme_loglari')->where('salon_id', $salonId)->where('user_id', $userId)
            ->where('tip', '!=', DilimTipi::TEKRAR)->orderByDesc('created_at')->value('created_at');

        return $son ? Carbon::parse($son)->startOfDay()->addDays($this->aralik($cark)) : null;
    }

    private function sec($dilimler): ?object
    {
        $toplam = (int) $dilimler->sum('dilim_olasilik');
        if ($toplam <= 0) {
            return null;
        }
        $cekilen = random_int(1, $toplam);
        $birikim = 0;
        foreach ($dilimler as $d) {
            $birikim += (int) $d->dilim_olasilik;
            if ($cekilen <= $birikim) {
                return $d;
            }
        }

        return $dilimler->last();
    }

    private function puanEkle(int $salonId, int $userId, float $deger): void
    {
        $satir = DB::table('salon_sadakat_puanlari')->where('salon_id', $salonId)->where('user_id', $userId)->lockForUpdate()->first();
        $satir
            ? DB::table('salon_sadakat_puanlari')->where('id', $satir->id)->update(['puan' => (float) $satir->puan + $deger, 'updated_at' => now()])
            : DB::table('salon_sadakat_puanlari')->insert(['salon_id' => $salonId, 'user_id' => $userId, 'puan' => $deger, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function kuponBitisi(object $cark, string $alan): ?string
    {
        $gun = (int) ($cark->{$alan} ?? 0);

        return $gun > 0 ? Carbon::now()->addDays($gun)->toDateString() : null;
    }

    private function kuponYaz(array $alanlar): array
    {
        $kupon = $alanlar + ['kod' => strtoupper(Str::random(8)), 'kullanildi' => 0, 'created_at' => now(), 'updated_at' => now()];
        DB::table('carkifelek_odulleri')->insert($kupon);

        return $kupon;
    }

    private function subeBilgisi(?array $kupon, int $salonId): array
    {
        return $kupon ? $this->gosterim($kupon, $salonId) : ['gecerli_coklu' => false, 'gecerli_tumu' => false, 'gecerli_salon_adlari' => []];
    }

    /** Coklu subeli markada kuponun gecerli oldugu subeler; tek subede ekran hic gostermez. */
    private function gosterim(array $kupon, int $salonId): array
    {
        $bundle = DB::table('salonlar')->where('id', $salonId)->value('app_bundle');
        $marka = $bundle ? DB::table('salonlar')->where('app_bundle', $bundle)->pluck('id')->map(fn ($x) => (int) $x)->all() : [];
        if (count($marka) <= 1) {
            return ['gecerli_coklu' => false, 'gecerli_tumu' => false, 'gecerli_salon_adlari' => []];
        }
        $idler = SalonIdListesi::coz($kupon['gecerli_salonlar'] ?? null) ?: $marka;

        return [
            'gecerli_coklu' => true,
            'gecerli_tumu' => array_diff($marka, $idler) === [],
            'gecerli_salon_adlari' => DB::table('salonlar')->whereIn('id', $idler)->orderBy('salon_adi')->pluck('salon_adi')->map(fn ($v) => (string) $v)->values()->all(),
        ];
    }
}
