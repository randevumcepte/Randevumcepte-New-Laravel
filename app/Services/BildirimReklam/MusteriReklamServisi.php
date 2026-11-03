<?php

namespace App\Services\BildirimReklam;

use App\Domain\BildirimReklam\HedefKosulu;
use App\Domain\BildirimReklam\ReklamKuponu;
use App\Domain\BildirimReklam\YayinKurali;
use App\Exceptions\GecersizIslemException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Müşteri uygulaması tarafı — eski BildirimReklamApiController@liste + @kuponKap karşılığı.
 *
 * Eskiden farklı olarak müşteri kimliği GÖVDEDEKİ user_id'den değil doğrulanmış token'dan gelir
 * (eski uçlar kimliksizdi: başkası adına kupon kapılabiliyordu). Kişi-başı limit + toplam adet
 * aynı satır kilidi içinde denetlenir (eski: limit kilit DIŞINDA → çift dokunuşta iki kupon).
 * Kupon carkifelek_odulleri'ne yazılır → "Kuponlarım" ekranı ve tahsilatta indirim değişmeden çalışır.
 */
class MusteriReklamServisi
{
    /**
     * @param  int  $salonId  müşterinin salonu (0 → bundle ile)
     * @return list<array> BildirimReklamSunumu::musteri
     */
    public function aktifler(int $salonId, ?string $bundle, int $userId): array
    {
        $q = DB::table('bildirim_reklamlari')->where('durum', 'aktif')
            ->where(fn ($w) => $w->where('kanal_inapp', 1)->orWhere('tam_ekran', 1));

        if ($salonId > 0) {
            // Ön süzgeç: kaynak salon ya da grubunda bu salon geçen reklamlar; kesin grup kuralı YayinKurali'nda.
            $q->where(fn ($w) => $w->where('salon_id', $salonId)->orWhere('gecerli_salonlar', 'like', '%'.$salonId.'%'));
        } elseif ($bundle) {
            $q->whereIn('salon_id', DB::table('salonlar')->where('app_bundle', $bundle)->pluck('id'));
        } else {
            return [];
        }

        $reklamlar = $q->orderByDesc('id')->get();
        $kaptigi = $userId > 0 && $reklamlar->isNotEmpty()
            ? DB::table('carkifelek_odulleri')->where('user_id', $userId)->whereIn('kaynak_reklam_id', $reklamlar->pluck('id'))
                ->groupBy('kaynak_reklam_id')->selectRaw('kaynak_reklam_id, COUNT(*) as n')->pluck('n', 'kaynak_reklam_id')
            : collect();

        $simdi = CarbonImmutable::now();

        return $reklamlar
            ->filter(fn ($r) => YayinKurali::musteriyeGorunurMu($r, $salonId, $userId, (int) ($kaptigi[$r->id] ?? 0), $simdi))
            ->map(fn ($r) => BildirimReklamSunumu::musteri($r))
            ->values()->all();
    }

    /**
     * Görsele dokununca kupon kap (tek dokunuş). Kişi limiti doluysa hata değil: zaten=true.
     *
     * @return array{zaten:bool, kupon:?array{id:int, kod:string, baslik:string, deger:float, gecerlilik_tarihi:?string}}
     */
    public function kuponKap(int $userId, int $reklamId): array
    {
        return DB::transaction(function () use ($userId, $reklamId) {
            $r = DB::table('bildirim_reklamlari')->where('id', $reklamId)->lockForUpdate()->first();
            if (! $r) {
                throw new GecersizIslemException('Reklam bulunamadı.');
            }
            $kupon = ReklamKuponu::satirdan($r);
            if (! $kupon->verirMi()) {
                throw new GecersizIslemException('Bu reklamda kupon yok.');
            }
            $simdi = CarbonImmutable::now();
            $hedef = HedefKosulu::dbden($r->hedef_kitle, $r->hedef_kosul);
            if (! YayinKurali::yayindaMi($r, $simdi) || ($hedef->kisiyeOzelMi() && $hedef->deger !== $userId)) {
                throw new GecersizIslemException('Bu kampanya sona erdi.');
            }

            $mevcut = DB::table('carkifelek_odulleri')->where('kaynak_reklam_id', $reklamId)->where('user_id', $userId)->count();
            if ($mevcut >= YayinKurali::kisiLimiti($r)) {
                return ['zaten' => true, 'kupon' => null];
            }

            $odul = [
                'salon_id'          => (int) $r->salon_id,
                'user_id'           => $userId,
                'kaynak_reklam_id'  => $reklamId,
                'kod'               => $this->kod(),
                'tip'               => $kupon->urunKuponuMu() ? 'urun_indirimi' : 'hizmet_indirimi',
                'deger'             => $kupon->deger,
                'indirim_tipi'      => $kupon->indirimTipi,
                'hizmet_id'         => $kupon->urunKuponuMu() ? null : $kupon->hizmetId,   // null = tüm hizmetler
                'urun_id'           => $kupon->urunId,
                'baslik'            => $kupon->baslik(),
                'gecerlilik_tarihi' => $kupon->gecerlilikTarihi($simdi),
                // Çoklu şube: kupon, reklamın uygulandığı şube grubunu miras alır.
                'gecerli_salonlar'  => $r->gecerli_salonlar ?: json_encode([(int) $r->salon_id]),
                'created_at'        => now(),
                'updated_at'        => now(),
            ];
            $odulId = (int) DB::table('carkifelek_odulleri')->insertGetId($odul);
            DB::table('bildirim_reklamlari')->where('id', $reklamId)->increment('kupon_dagitilan');

            return ['zaten' => false, 'kupon' => [
                'id' => $odulId, 'kod' => $odul['kod'], 'baslik' => $odul['baslik'],
                'deger' => (float) $odul['deger'], 'gecerlilik_tarihi' => $odul['gecerlilik_tarihi'],
            ]];
        });
    }

    /** Benzersiz 8 haneli kupon kodu (carkifelek_odulleri.kod unique). */
    private function kod(): string
    {
        do {
            $kod = strtoupper(Str::random(8));
        } while (DB::table('carkifelek_odulleri')->where('kod', $kod)->exists());

        return $kod;
    }
}
