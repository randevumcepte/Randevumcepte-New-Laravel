<?php

namespace App\Services\Ders;

use App\Services\Paket\PaketKullanimi;
use App\Domain\Ders\KatilimciDurumu;
use App\Exceptions\GecersizIslemException;
use App\Models\DersKatilimci;
use App\Models\DersOturumu;
use App\Models\DersProgramiSablonu;
use App\Models\DersTekrarliKatilim;
use App\Services\Seans\SeansHavuzu;
use App\Support\AktiviteLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Otomatik ders plani (stüdyo): musterinin seanslarini secilen slotlara ileri tarihli dagitir.
 *
 *   - Dolu oturum: atla, sonraki uygun gune gec (o hafta seans harcanmaz).
 *   - O tarihte oturum yoksa: sablondan uret.
 *   - Yeniden dagitimda GECMIS korunur; gelecekteki rezerve/bekleme silinip yeniden dagitilir.
 *   - Dusum burada YAPILMAZ; "Geldi/Gelmedi" isaretlenince duser.
 */
class DersTekrarliServisi
{
    /** En fazla ~1 yil ileri tara. */
    private const MAKS_GUN = 371;

    public function __construct(
        private SeansHavuzu $havuz,
        private DersSeansServisi $seans,
        private DersKatilimServisi $katilim,
        private DersProgramServisi $program,
        private DersReferansServisi $referans,
    ) {}

    /** Musterinin kalan seansli satislari + slot secici icin haftalik sablonlar. */
    public function kaynaklar(int $salonId, int $userId): array
    {
        return ['kaynaklar' => $this->havuz->kaynaklar($salonId, $userId), 'sablonlar' => $this->program->sablonlar($salonId)];
    }

    /**
     * Mobilin bugunku kaynak listesi: paket kalani hizmet basina ORTALAMA (toplam hak − toplam kullanim) / hizmet
     * sayisi, suresi dolan paket de listelenir. Tek hizmet satislari havuzla ayni.
     *
     * @return array<int,array<string,mixed>>
     */
    public function kaynaklarOrtalama(int $salonId, int $userId): array
    {
        $tekHizmet = array_values(array_filter($this->havuz->kaynaklar($salonId, $userId), fn (array $k) => $k['adisyon_paket_id'] === null));
        $q = DB::table('adisyon_paketler as ap')->join('adisyonlar as a', 'ap.adisyon_id', '=', 'a.id')
            ->join('paketler as p', 'p.id', '=', 'ap.paket_id')
            ->join('paket_hizmetler as ph', 'ph.paket_id', '=', 'ap.paket_id')
            ->join('hizmetler as h', 'h.id', '=', 'ph.hizmet_id')
            ->where('a.user_id', $userId)->where('a.salon_id', $salonId)
            ->where(fn ($w) => $w->whereNull('ap.otomatik_randevu_olusturuldu')->orWhere('ap.otomatik_randevu_olusturuldu', '!=', 1));
        $satirlar = $q->orderBy('ap.id')->get(['ap.id', 'ap.seans_sayisi', 'ph.hizmet_id', 'h.hizmet_adi', 'p.paket_adi']);
        $paketIdler = $satirlar->pluck('id')->unique()->map(fn ($i) => (int) $i)->values()->all();
        $kullanim = DB::table('adisyon_paket_seanslar')->whereIn('adisyon_paket_id', $paketIdler)
            ->groupBy('adisyon_paket_id')->selectRaw('adisyon_paket_id as id, COUNT(*) as adet')->pluck('adet', 'id');
        $durumlar = app(PaketKullanimi::class)->durumlar($paketIdler);
        $hizmetSayisi = $satirlar->groupBy('id')->map->count();

        $paket = [];
        foreach ($satirlar as $s) {
            $adet = (int) $hizmetSayisi[$s->id];
            $sinirsiz = (bool) ($durumlar[$s->id]['sinirsiz'] ?? false);
            $kalan = max(0, (int) floor(((int) $s->seans_sayisi * $adet - (int) ($kullanim[$s->id] ?? 0)) / $adet));
            if ($kalan > 0 || $sinirsiz) {
                $paket[] = [
                    'etiket' => $s->hizmet_adi.' — '.$s->paket_adi.' (Paket)', 'hizmet_id' => (int) $s->hizmet_id, 'hizmet_adi' => $s->hizmet_adi,
                    'kalan_seans' => $kalan, 'sinirsiz' => $sinirsiz, 'adisyon_paket_id' => (int) $s->id, 'adisyon_hizmet_id' => null,
                ];
            }
        }

        return array_merge($tekHizmet, $paket);
    }

    /** @return array<int,array<string,mixed>> */
    public function liste(int $salonId, ?int $userId): array
    {
        $kayitlar = DersTekrarliKatilim::where('salon_id', $salonId)->when($userId, fn ($q) => $q->where('user_id', $userId))->orderByDesc('id')->get();
        $yerlesen = DersKatilimci::whereIn('tekrarli_id', $kayitlar->pluck('id'))->where('durum', '!=', KatilimciDurumu::IPTAL->value)
            ->groupBy('tekrarli_id')->selectRaw('tekrarli_id, COUNT(*) as adet')->pluck('adet', 'tekrarli_id');
        $hizmetler = DB::table('hizmetler')->whereIn('id', $kayitlar->pluck('hizmet_id')->filter())->pluck('hizmet_adi', 'id');

        return $kayitlar->map(fn (DersTekrarliKatilim $k) => [
            'id' => (int) $k->id, 'user_id' => (int) $k->user_id, 'hizmet_id' => (int) $k->hizmet_id,
            'hizmet_adi' => $hizmetler[$k->hizmet_id] ?? '#'.$k->hizmet_id,
            'toplam_seans' => (int) $k->toplam_seans, 'yerlesen' => (int) ($yerlesen[$k->id] ?? 0),
            'kalan' => max(0, (int) $k->toplam_seans - (int) ($yerlesen[$k->id] ?? 0)),
            'gunler' => $k->gunler ?? [], 'saatler' => $k->saatler ?? [], 'sablonlar' => $k->sablonlar ?? [],
            'personel_id' => $k->personel_id, 'baslangic' => (string) $k->baslangic_tarihi, 'aktif' => (bool) $k->aktif,
        ])->all();
    }

    /** $mobilKurali: mobilin bugunku dagitimi (paket bitisi sinir degil, terfi yok, sinirsizda yerlesmeyen sayilir). */
    public function kaydet(int $salonId, ?int $kayitId, array $g, ?int $yetkiliId, bool $mobilKurali = false): array
    {
        $this->referans->musteriDogrula($salonId, (int) $g['musteri_id']);
        $this->referans->personelHizmetDogrula($salonId, $g['personel_id'] ?? null, (int) $g['hizmet_id']);
        if (empty($g['gunler']) && empty($g['sablonlar'])) {
            throw new GecersizIslemException('En az bir ders slotu seçin.');
        }
        $alan = [
            'salon_id' => $salonId, 'user_id' => (int) $g['musteri_id'], 'hizmet_id' => (int) $g['hizmet_id'],
            'adisyon_paket_id' => $g['adisyon_paket_id'] ?? null, 'adisyon_hizmet_id' => $g['adisyon_hizmet_id'] ?? null,
            'toplam_seans' => (int) $g['toplam_seans'],
            'gunler' => array_values(array_unique(array_map('intval', $g['gunler'] ?? []))),
            'saatler' => array_values($g['saatler'] ?? []),
            'sablonlar' => array_values(array_unique(array_map('intval', $g['sablonlar'] ?? []))),
            'personel_id' => $g['personel_id'] ?? null, 'baslangic_tarihi' => $g['baslangic'] ?? date('Y-m-d'), 'aktif' => true,
        ];

        $kayit = $kayitId ? DersTekrarliKatilim::where('salon_id', $salonId)->findOrFail($kayitId) : new DersTekrarliKatilim;
        $kayit->fill($alan)->save();
        $sonuc = $this->dagit($kayit, $yetkiliId, $mobilKurali);
        AktiviteLog::yaz($salonId, $yetkiliId, 'ders_plani_kaydet', 'ders_plani', (int) $kayit->id, null, null, $sonuc);

        return ['kayit_id' => (int) $kayit->id, 'sonuc' => $sonuc];
    }

    /**
     * Plani siler; gelecek otomatik katilimlar temizlenir, bosalan yerlere bekleyenler terfi eder.
     * $terfi=false: mobilin bugunku davranisi, bekleyen terfi etmez.
     */
    public function sil(int $salonId, int $kayitId, ?int $yetkiliId, bool $terfi = true): array
    {
        $kayit = DersTekrarliKatilim::where('salon_id', $salonId)->findOrFail($kayitId);

        return DB::transaction(function () use ($kayit, $salonId, $yetkiliId, $terfi) {
            $oturumlar = $this->gelecekTemizle($kayit);
            if ($terfi) {
                $this->katilim->terfiler($oturumlar, $yetkiliId);
            }
            $kayit->delete();
            AktiviteLog::yaz($salonId, $yetkiliId, 'ders_plani_sil', 'ders_plani', (int) $kayit->id);

            return ['temizlenen' => count($oturumlar)];
        });
    }

    /** @return array{olusan:int,oturum_olusturulan:int,dolu_atlanan:int,yerlesmeyen:int,hedef:int,gecmis:int} */
    private function dagit(DersTekrarliKatilim $kayit, ?int $yetkiliId, bool $mobilKurali = false): array
    {
        $bosalan = $this->gelecekTemizle($kayit);
        try {
            return $this->yerlestir($kayit, $mobilKurali);
        } finally {
            if (! $mobilKurali) {
                $this->katilim->terfiler($bosalan, $yetkiliId); // eski slotta bekleyenler bos yer yaninda kalmasin
            }
        }
    }

    /** @return array{olusan:int,oturum_olusturulan:int,dolu_atlanan:int,yerlesmeyen:int,hedef:int,gecmis:int} */
    private function yerlestir(DersTekrarliKatilim $kayit, bool $mobilKurali = false): array
    {
        $mevcut = DersKatilimci::where('tekrarli_id', $kayit->id)->where('durum', '!=', KatilimciDurumu::IPTAL->value)->count();
        $hedef = (int) $kayit->toplam_seans;
        $kalan = max(0, $hedef - $mevcut);
        $sonuc = ['olusan' => 0, 'oturum_olusturulan' => 0, 'dolu_atlanan' => 0, 'yerlesmeyen' => 0, 'hedef' => $hedef, 'gecmis' => $mevcut];
        if ($kalan <= 0 || ! $kayit->aktif) {
            return $sonuc;
        }

        $gunSablon = $this->sablonlar($kayit)->groupBy(fn ($s) => (int) $s->hafta_gunu);
        if ($gunSablon->isEmpty()) {
            $sonuc['yerlesmeyen'] = $kalan;

            return $sonuc;
        }

        $bugun = Carbon::today();
        $tarih = Carbon::parse($kayit->baslangic_tarihi ?: $bugun)->startOfDay()->max($bugun)->copy();
        $durum = ! $mobilKurali && $kayit->adisyon_paket_id ? app(PaketKullanimi::class)->durum((int) $kayit->adisyon_paket_id) : null;
        // Sure ilk geliste baslar; henuz gelinmediyse plan baslangicindan sayilir.
        $son = $durum ? (\App\Domain\Paket\PaketGecerlilik::bitis($durum['baslangic'] ?? $tarih->toDateString(), $durum['sayi'], $durum['birim'])) : null;
        for ($i = 0; $i < self::MAKS_GUN && $kalan > 0 && ($son === null || $tarih->toDateString() <= $son); $i++, $tarih->addDay()) {
            foreach ($gunSablon->get($tarih->dayOfWeekIso, []) as $s) {
                if ($kalan <= 0) {
                    break;
                }
                $gecti = $tarih->isSameDay($bugun) && strtotime($tarih->toDateString().' '.$s->saat) < time();
                if (! $gecti && $this->slotaYerlestir($kayit, $s, $tarih->toDateString(), $sonuc)) {
                    $kalan--;
                }
            }
        }
        $sonuc['yerlesmeyen'] = ($durum['sinirsiz'] ?? false) ? 0 : $kalan; // sinirsizda hedef teknik tavan

        return $sonuc;
    }

    /** Slot bazli (secili sablonlar) ya da filtre bazli (gun/saat/hoca tercihi) kaynak sablonlar. */
    private function sablonlar(DersTekrarliKatilim $kayit): Collection
    {
        $q = DersProgramiSablonu::where('salon_id', $kayit->salon_id)->where('aktif', 1)->orderBy('saat');
        if ($kayit->sablonlar) {
            return $q->whereIn('id', $kayit->sablonlar)->get();
        }
        $saatler = $kayit->saatler ?? [];
        $liste = $q->whereIn('hafta_gunu', $kayit->gunler ?? [])
            ->when($kayit->hizmet_id, fn ($w) => $w->where('hizmet_id', $kayit->hizmet_id))
            ->when($kayit->personel_id, fn ($w) => $w->where('personel_id', $kayit->personel_id))
            ->get();

        return $saatler ? $liste->filter(fn ($s) => in_array(substr((string) $s->saat, 0, 5), $saatler, true))->values() : $liste;
    }

    private function slotaYerlestir(DersTekrarliKatilim $kayit, DersProgramiSablonu $s, string $tarih, array &$sonuc): bool
    {
        return DB::transaction(function () use ($kayit, $s, $tarih, &$sonuc) {
            $oturum = DersOturumu::where('salon_id', $kayit->salon_id)->where('sablon_id', $s->id)->where('tarih', $tarih)->lockForUpdate()->first();
            if (! $oturum) {
                $oturum = $this->program->oturumUret($s, $tarih);
                $sonuc['oturum_olusturulan']++;
            } elseif ($oturum->iptalMi()) {
                return false;
            }

            $zaten = DersKatilimci::where('oturum_id', $oturum->id)->where('user_id', $kayit->user_id)
                ->where('durum', '!=', KatilimciDurumu::IPTAL->value)->exists();
            if ($zaten) {
                return false;
            }
            if ($oturum->dolulukSay() >= (int) $oturum->kapasite) {
                $sonuc['dolu_atlanan']++;

                return false;
            }

            DersKatilimci::create([
                'oturum_id' => $oturum->id, 'user_id' => $kayit->user_id, 'salon_id' => $kayit->salon_id,
                'durum' => KatilimciDurumu::REZERVE->value, 'adisyon_paket_id' => $kayit->adisyon_paket_id,
                'tekrarli_id' => $kayit->id, 'not' => 'otomatik',
            ]);
            $sonuc['olusan']++;

            return true;
        });
    }

    /** @return int[] etkilenen oturum id'leri (silinen satir basina bir kez) */
    private function gelecekTemizle(DersTekrarliKatilim $kayit): array
    {
        $satirlar = DersKatilimci::where('tekrarli_id', $kayit->id)
            ->whereIn('durum', [KatilimciDurumu::REZERVE->value, KatilimciDurumu::BEKLEME->value])
            ->whereHas('oturum', fn ($q) => $q->where('tarih', '>=', date('Y-m-d')))->get();

        return $satirlar->map(function (DersKatilimci $k) {
            if ($k->hak_dusuldu) {
                $this->seans->hakGeriVer($k);
            }
            $k->delete();

            return (int) $k->oturum_id;
        })->all();
    }
}
