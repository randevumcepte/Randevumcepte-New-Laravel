<?php

namespace App\Services\Paket;

use App\Domain\Paket\PaketGecerlilik;
use App\Models\AdisyonPaketler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satilmis paketin sure / gunluk limit durumu — kural PaketGecerlilik'te, burada yalniz veri.
 * Randevu, grup dersi ve seans takibi seans acmadan once buradan sorar.
 */
class PaketKullanimi
{
    private const ALANLAR = ['sinirsiz', 'gecerlilik_sayi', 'gecerlilik_birim', 'gunluk_limit'];

    private static ?bool $kolonVar = null;

    /** Sema eski monolitte; kolonlar henuz yoksa eski davranis (uygulama patlamasin). */
    public static function kolonVar(): bool
    {
        return self::$kolonVar ??= Schema::hasColumn('adisyon_paketler', 'sinirsiz');
    }

    /** AdisyonPaketler::saving — paket_id yazildiginda (satis / yukseltme) katalog kopyalanir. */
    public static function satisaKopyala(AdisyonPaketler $ap): void
    {
        if (! self::kolonVar() || ! $ap->paket_id || ! $ap->isDirty('paket_id')) {
            return;
        }
        $p = DB::table('paketler')->where('id', $ap->paket_id)->first(self::ALANLAR);
        if (! $p) {
            return;
        }
        foreach (self::ALANLAR as $alan) {
            $ap->{$alan} = $p->{$alan};
        }
        if ((int) $p->sinirsiz === 1) {
            $tavan = PaketGecerlilik::ustSinir($p->gecerlilik_sayi, $p->gecerlilik_birim, $p->gunluk_limit);
            $ap->seans_sayisi = $tavan;
            $ap->bekleyen_seans = $tavan - (int) $ap->kullanilan_seans - (int) $ap->kullanilmayan_seans;
        }
    }

    /**
     * Sayfa icin toplu durum (satir basina sorgu yok).
     *
     * @param  int[]  $adisyonPaketIdler
     * @return array<int,array{sinirsiz:bool,sure:string,baslangic:?string,bitis:?string,gunluk_limit:int,suresi_doldu:bool,sayi:int,birim:?string}>
     */
    public function durumlar(array $adisyonPaketIdler): array
    {
        if (! $adisyonPaketIdler || ! self::kolonVar()) {
            return [];
        }
        $ilkler = DB::table('adisyon_paket_seanslar')->whereIn('adisyon_paket_id', $adisyonPaketIdler)
            ->where('geldi', 1)->where('seans_tarih', '>', '2000-01-01')
            ->groupBy('adisyon_paket_id')->selectRaw('adisyon_paket_id as id, MIN(seans_tarih) as ilk')->pluck('ilk', 'id');
        $bugun = now()->toDateString();

        $sonuc = [];
        foreach (DB::table('adisyon_paketler')->whereIn('id', $adisyonPaketIdler)->get(array_merge(['id'], self::ALANLAR)) as $s) {
            $ilk = isset($ilkler[$s->id]) && (int) $s->gecerlilik_sayi > 0 ? substr((string) $ilkler[$s->id], 0, 10) : null;
            $bitis = PaketGecerlilik::bitis($ilk, $s->gecerlilik_sayi, $s->gecerlilik_birim);
            $sonuc[(int) $s->id] = [
                'sinirsiz'     => (int) $s->sinirsiz === 1,
                'sure'         => PaketGecerlilik::sureMetni($s->gecerlilik_sayi, $s->gecerlilik_birim),
                'baslangic'    => $ilk,
                'bitis'        => $bitis,
                'gunluk_limit' => (int) $s->gunluk_limit,
                'suresi_doldu' => ! PaketGecerlilik::tarihGecerli($bitis, $bugun),
                'sayi'         => (int) $s->gecerlilik_sayi,
                'birim'        => $s->gecerlilik_birim,
            ];
        }

        return $sonuc;
    }

    public function durum(int $adisyonPaketId): ?array
    {
        return $this->durumlar([$adisyonPaketId])[$adisyonPaketId] ?? null;
    }

    /**
     * Bu tarihte YENI seans acilabilir mi? (sure + gunluk limit; seans adedi cagirana ait)
     *
     * @param  int  $gunRezerve  ayni istekte bu gun icin ayrilmis, henuz yazilmamis seans
     */
    public function seansUygun(int $adisyonPaketId, int $hizmetId, ?string $tarih, int $gunRezerve = 0): bool
    {
        $d = $this->durum($adisyonPaketId);
        if (! $d) {
            return true;
        }
        $gun = substr($tarih ?: now()->toDateString(), 0, 10);
        if (! PaketGecerlilik::tarihGecerli($d['bitis'], $gun)) {
            return false;
        }
        if ($d['sinirsiz'] && $d['gunluk_limit'] > 0) {
            $o = DB::table('adisyon_paket_seanslar')->where('adisyon_paket_id', $adisyonPaketId)
                ->where('hizmet_id', $hizmetId)->whereDate('seans_tarih', $gun)->count();

            return $o + $gunRezerve < $d['gunluk_limit'];
        }

        return true;
    }

    /** Ayni istekte gun bazli rezerve anahtari (SeansHavuzu::yeniAday $rezerve dizisinde). */
    public static function gunAnahtari(int $adisyonPaketId, int $hizmetId, ?string $tarih): string
    {
        return 'G:'.$adisyonPaketId.':'.$hizmetId.':'.substr((string) $tarih, 0, 10);
    }
}
