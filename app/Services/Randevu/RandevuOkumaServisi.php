<?php

namespace App\Services\Randevu;

use App\Exceptions\BulunamadiException;
use App\Models\Personeller;
use App\Models\RandevuHizmetler;
use App\Models\Randevular;
use App\Services\Yetki\YetkiServisi;
use App\Support\Yetki\YetkiSabitleri;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Randevu OKUMA servisi (Faz 1 — salt okuma, hicbir sey yazmaz).
 *
 * KAPSAM (bilinçli): Bu faz, eski mobil takvim ucunun (ApiController@randevular,
 * /randevular/{salon}/0) YAPISAL cekirdegini v2'ye tasir:
 *   - hangi randevu_hizmetler satirlari gorunur (durum<2 + tarih araligi + salon)
 *   - rol-5 (personel) kapsam kisitlamasi + 'randevu.tum_personel_gor' bypass
 *   - her satirin zaman penceresi (start/end), kaynak bagi (resourceId) ve
 *     yuk-tasiyan 'durum' paketlenmis string'i
 *     (durum-geldi-satisOlustu-odemeYapildi-rhId-telafiVar-telafiKaynak-telafiVerildi)
 *   - paket-kaynagi + resource + personel bazli MERGE (tek bar)
 *
 * ERTELENEN (tahsilat Faz 1 felsefesiyle ayni — kozmetik, cutover'da dogrulanir):
 *   renk sirasi/tek-renk ince ayari, "(PAKET)/(KARMA)" etiketleri, 'notes' serbest
 *   metin bloku (KVKK: PII-yogun; burada DONDURULMEZ), resource sayac rozetleri.
 *   Renk burada eski durum-rengi mantiginin cekirdegi kadar uretilir; salon
 *   renk-sirasi/kategori ince ayari cutover fazina birakilmistir.
 *
 * Amac: yazma/kesme oncesi, v2'nin ESKI ile AYNI satir kumesini + ayni zaman/
 * kaynak baglarini urettigini randevu:shadow-takvim ile kanitlamak.
 */
class RandevuOkumaServisi
{
    public function __construct(
        private YetkiServisi $yetki,
    ) {}

    /** Yalnizca salon_id (kapsam denetimi icin ucuz sorgu). */
    public function salonIdGetir(int $randevuId): ?int
    {
        $v = Randevular::where('id', $randevuId)->value('salon_id');

        return $v !== null ? (int) $v : null;
    }

    /**
     * Takvim yuku. Eski /randevular/{salon}/0 ile ayni girdi/cikti sozlesmesi.
     *
     * @param  int|null  $personelId    Rol/kapsam cozumu + filtre icin personel (salon_personelleri.id). Bos = tum salon.
     * @param  int|null  $callerYetkiliId  'randevu.tum_personel_gor' bypass'i icin oturum sahibi (isletmeyetkilileri.id).
     * @return array{randevular: array, resources: array, baslangic: string, bitis: string}
     */
    public function takvim(
        int $salonId,
        ?string $tarih1,
        ?string $tarih2,
        ?int $personelId,
        $takvimTuru,
        ?int $callerYetkiliId,
    ): array {
        $tarih1 = $tarih1 ?: date('Y-m-d');
        $tarih2 = $tarih2 ?: date('Y-m-d');

        // Ekrandan secilen tur (0 kategori / 1 personel / 2 cihaz / 3 oda); gecersiz
        // ya da bossa salonun varsayilan takvim turu.
        $takvimTuru = in_array((string) $takvimTuru, ['0', '1', '2', '3'], true)
            ? (int) $takvimTuru
            : (int) (DB::table('salonlar')->where('id', $salonId)->value('randevu_takvim_turu') ?? 1);

        // GUVENLIK: rol-5 kisiti istekten DEGIL oturum sahibinden zorunlu kilinir.
        // Eski uc kisiti istemcinin gonderdigi personel_id'ye dayandiriyordu
        // (gondermeyen personel tum salonu gorurdu). Yetkisiz personel her zaman
        // yalnizca kendi kaydina sabitlenir; istekteki personel_id yok sayilir.
        if ($callerYetkiliId) {
            $kendi = Personeller::where('salon_id', $salonId)->where('yetkili_id', $callerYetkiliId)->first(['id', 'role_id']);
            if ($kendi && (int) $kendi->role_id === 5
                && ! $this->yetki->yetkiliYetkiVar($callerYetkiliId, $salonId, 'randevu.tum_personel_gor')) {
                $personelId = (int) $kendi->id;
            }
        }

        $personelRolu = $this->personelRolunuCoz($salonId, $personelId, $callerYetkiliId);

        $satirlar = $this->hamSatirlar($salonId, $tarih1, $tarih2, $personelId, $takvimTuru, $personelRolu);
        $merged   = self::birlestir($satirlar);

        return [
            'randevular' => array_values(array_map(fn ($r) => $this->disaSatir($r), $merged)),
            'resources'  => $this->kaynaklar($salonId, $personelId, $takvimTuru, $personelRolu, $satirlar),
            'takvim_turu'=> $takvimTuru,
            'baslangic'  => $tarih1,
            'bitis'      => $tarih2,
        ];
    }

    /**
     * Rol-5 (personel) ise ve caller'da 'randevu.tum_personel_gor' varsa 0'a indir
     * (kisitlama uygulanmasin) — eski randevular() bypass'i ile BIREBIR.
     */
    private function personelRolunuCoz(int $salonId, ?int $personelId, ?int $callerYetkiliId): int
    {
        if (! $personelId) {
            return 0;
        }

        $rol = (int) (Personeller::where('id', $personelId)->value('role_id') ?? 0);
        if ($rol !== 5) {
            return $rol;
        }

        $caller = $callerYetkiliId
            ?: Personeller::where('id', $personelId)->value('yetkili_id');

        if ($caller && $this->yetki->yetkiliYetkiVar($caller, $salonId, 'randevu.tum_personel_gor')) {
            return 0;
        }

        return 5;
    }

    /**
     * Pre-merge satir kumesi — her randevu_hizmetler icin bir olay. shadow komutu
     * ve takvim() bu kaynagi kullanir. Cikti alanlari eski randevular() ile ayni
     * yuk-tasiyan cekirdek (merge/kozmetik haric).
     *
     * @return array<int,array<string,mixed>>
     */
    public function hamSatirlar(
        int $salonId,
        string $tarih1,
        string $tarih2,
        ?int $personelId,
        int $takvimTuru,
        int $personelRolu,
    ): array {
        $q = RandevuHizmetler::with(['randevu.users'])
            ->whereHas('randevu', function ($q) use ($salonId, $tarih1, $tarih2) {
                $q->where('durum', '<', Randevular::IPTAL_EDILDI)
                    ->where('tarih', '>=', $tarih1)
                    ->where('tarih', '<=', $tarih2)
                    ->where('salon_id', $salonId);
            });

        // Rol-5: yalnizca kendi (personel_id) randevulari.
        if ($personelRolu === 5 && $personelId) {
            $q->where('personel_id', $personelId);
        }

        $rhler = $q->get();
        if ($rhler->isEmpty()) {
            return [];
        }

        $randevuIds = $rhler->pluck('randevu_id')->unique()->values();

        // satisOlustu / odemeYapildi (durum string'inin 3. ve 4. segmenti) —
        // eski randevular() ile ayni: adisyon_hizmetler toplami == tahsilat toplami.
        $adisyonHizmetler = DB::table('adisyon_hizmetler')
            ->whereIn('randevu_id', $randevuIds)
            ->get(['id', 'randevu_id', 'fiyat']);
        $ahByRandevu = $adisyonHizmetler->groupBy('randevu_id');
        $ahTutariByRandevu = $ahByRandevu->map(fn ($g) => (float) $g->sum('fiyat'));

        $ahIds = $adisyonHizmetler->pluck('id');
        $tahsilatByAh = DB::table('tahsilat_hizmetler')
            ->whereIn('adisyon_hizmet_id', $ahIds)
            ->select('adisyon_hizmet_id', DB::raw('SUM(tutar) as toplam'))
            ->pluck('toplam', 'adisyon_hizmet_id');

        // APS: paket-kaynagi (merge) + telafi rozeti (6. segment).
        $seanslar = DB::table('adisyon_paket_seanslar')
            ->whereIn('randevu_id', $randevuIds)
            ->get(['randevu_id', 'adisyon_paket_id', 'adisyon_hizmet_id', 'hizmet_id', 'geldi']);
        $seanslarByRandevu = $seanslar->groupBy('randevu_id');

        // Ekranda olay basligi icin hizmet adlari (tek sorgu).
        $hizmetAdlari = DB::table('hizmetler')
            ->whereIn('id', $rhler->pluck('hizmet_id')->filter()->unique()->all())
            ->pluck('hizmet_adi', 'id');

        $cikti = [];
        foreach ($rhler as $rh) {
            $randevu = $rh->randevu;
            if (! $randevu) {
                continue;
            }

            // satisOlustu / odemeYapildi
            $satisOlustu = 0;
            $odemeYapildi = 0;
            if (isset($ahByRandevu[$rh->randevu_id]) && $ahByRandevu[$rh->randevu_id]->isNotEmpty()) {
                $satisOlustu = 1;
                $tahsilatTutari = 0.0;
                foreach ($ahByRandevu[$rh->randevu_id] as $ah) {
                    $tahsilatTutari += (float) ($tahsilatByAh[$ah->id] ?? 0);
                }
                if (round((float) $ahTutariByRandevu[$rh->randevu_id], 0) == round($tahsilatTutari, 0)) {
                    $odemeYapildi = 1;
                }
            }

            $start = Carbon::parse($randevu->tarih.' '.$rh->saat)->toIso8601String();
            $end   = Carbon::parse($randevu->tarih.' '.$rh->saat_bitis)->toIso8601String();

            // resourceId: takvim turune gore kaynak (eski randevular() ile ayni).
            // Rol-5 kendi takvimini gorurken resources personel-bazli -> resourceId=personel_id.
            $kapali = ((int) $randevu->user_id === Randevular::KAPALI_SAAT_USER_ID);
            $resourceId = $this->resourceIdHesapla($rh, $takvimTuru);
            if ($personelRolu === 5) {
                $resourceId = $rh->personel_id ?? 0;
            }

            // Telafi bayraklari (durum string 6/7/8. segment).
            $seansVar = $seanslarByRandevu->get($rh->randevu_id, collect());
            $telafiVar = $seansVar->contains(fn ($s) => (int) $s->geldi === 2) ? 1 : 0;
            $telafiKaynak  = ! empty($rh->telafi_kaynak_rh_id) ? 1 : 0;
            $telafiVerildi = ! empty($rh->telafi_devir_yapildi) ? 1 : 0;

            // Paket kaynagi (merge anahtari) — eski randevular() ile ayni.
            $paketSource = 'single-'.$rh->id;
            foreach ($seansVar as $sv) {
                if ((int) $sv->hizmet_id !== (int) $rh->hizmet_id) {
                    continue;
                }
                if ($sv->adisyon_paket_id) {
                    $paketSource = 'AP:'.$sv->adisyon_paket_id;
                    break;
                }
                if ($sv->adisyon_hizmet_id) {
                    $paketSource = 'AH:'.$sv->adisyon_hizmet_id;
                    break;
                }
            }

            $durumStr = ($randevu->durum !== null ? $randevu->durum : 'na')
                .'-'.($randevu->randevuya_geldi !== null ? $randevu->randevuya_geldi : 'na')
                .'-'.$satisOlustu
                .'-'.$odemeYapildi
                .'-'.$rh->id
                .'-'.$telafiVar
                .'-'.$telafiKaynak
                .'-'.$telafiVerildi;

            $cikti[] = [
                'rh_id'        => (int) $rh->id,
                'id'           => (int) $rh->randevu_id,
                'randevu_id'   => (int) $rh->randevu_id,
                'userid'       => $randevu->user_id !== null ? (int) $randevu->user_id : null,
                'start'        => $start,
                'end'          => $end,
                'bgcolor'      => $kapali ? '0xFF000000' : $this->durumRengi($rh, $randevu),
                'resourceId'   => $resourceId,
                'personelId'   => $rh->personel_id !== null ? (int) $rh->personel_id : null,
                'odaId'        => $rh->oda_id !== null ? (int) $rh->oda_id : null,
                'musteri'      => $kapali ? 'Kapalı Saat' : ($randevu->users->name ?? null),
                'hizmet'       => $kapali ? null : ($hizmetAdlari[$rh->hizmet_id] ?? null),
                'kapali'       => $kapali,
                'ongorusmeid'  => $randevu->on_gorusme_id,
                'satis_olustu' => $satisOlustu,
                'durum'        => $durumStr,
                '_paketSource' => $paketSource,
            ];
        }

        return $cikti;
    }

    private function resourceIdHesapla(RandevuHizmetler $rh, int $takvimTuru)
    {
        return match ($takvimTuru) {
            1       => $rh->personel_id ?? 0,
            2       => $rh->cihaz_id ?? 0,
            3       => $rh->oda_id ?? 0,
            default => $rh->hizmet_id ? (DB::table('hizmetler')->where('id', $rh->hizmet_id)->value('hizmet_kategori_id')) : null,
        };
    }

    /**
     * Durum rengi cekirdegi (eski randevular() mantigi — geldi/gelmedi/bekliyor).
     * Kaynak/kategori rengi ve salon renk-sirasi ince ayari cutover'a ertelendi.
     */
    private function durumRengi(RandevuHizmetler $rh, Randevular $randevu): ?string
    {
        $geldi = $randevu->randevuya_geldi;
        $seansaGeldi = $rh->seansa_geldi;

        if ($geldi === 1 || $seansaGeldi === 1) {
            return '0xFF008000';
        }
        if ($geldi === 0 || $seansaGeldi === 0) {
            return '0xFFff0000';
        }
        if ((int) $randevu->durum === 0) {
            return '0xFFffc107';
        }

        return null; // kaynak/kategori rengi cutover fazinda
    }

    /**
     * Pre-merge satirlari eski randevular() ile ayni anahtarla birlestir:
     * _paketSource | resourceId | personelId  → start=min, end=max.
     *
     * @param  array<int,array<string,mixed>>  $satirlar
     * @return array<int,array<string,mixed>>
     */
    private static function birlestir(array $satirlar): array
    {
        $gruplar = [];
        foreach ($satirlar as $s) {
            $anahtar = ($s['_paketSource'] ?? ('single-'.$s['rh_id']))
                .'|'.($s['resourceId'] ?? '')
                .'|'.($s['personelId'] ?? '');
            $gruplar[$anahtar][] = $s;
        }

        $merged = [];
        foreach ($gruplar as $olaylar) {
            if (count($olaylar) > 1) {
                usort($olaylar, fn ($a, $b) => strcmp($a['start'], $b['start']));
                $ilk = $olaylar[0];
                $ilk['end'] = max(array_column($olaylar, 'end'));
                $merged[] = $ilk;
            } else {
                $merged[] = $olaylar[0];
            }
        }

        return $merged;
    }

    /** Dis sozlesme icin satiri temizle (ic merge anahtarlarini dusur). */
    private function disaSatir(array $r): array
    {
        unset($r['_paketSource'], $r['rh_id']);

        return $r;
    }

    /**
     * Resources (takvim kaynak kolonlari) — eski randevular() ile ayni kaynak
     * kumeleri: personel (takvim_turu 1 veya rol-5) / kategori (0) / cihaz (2) / oda (3).
     *
     * Sayac (eski "Ad (N)") burada AYRI 'sayi' alani olarak, zaten cekilmis
     * satirlardan PHP'de hesaplanir — eski kodun kaynak basina correlated
     * subquery'si yogun salonlarda CPU yukuydu (bkz. cde3ca42).
     *
     * @param  array<int,array<string,mixed>>  $satirlar  pre-merge satirlar
     * @return array<int,array{id:int,name:string,bgcolor:?string,avatar:?string,sayi:int,tur:string}>
     */
    private function kaynaklar(int $salonId, ?int $personelId, int $takvimTuru, int $personelRolu, array $satirlar): array
    {
        $sayilar = [];
        foreach ($satirlar as $s) {
            if (! $s['kapali']) {
                $k = (string) $s['resourceId'];
                $sayilar[$k] = ($sayilar[$k] ?? 0) + 1;
            }
        }

        if ($takvimTuru === 1 || $personelRolu === 5) {
            $tur = 'personel';
            $q = DB::table('salon_personelleri as sp')
                ->join('renk_duzenleri as rd', 'sp.renk', '=', 'rd.id')
                ->join('isletmeyetkilileri as iy', 'sp.yetkili_id', '=', 'iy.id')
                ->where('sp.aktif', true)
                ->where('sp.takvimde_gorunsun', true)
                ->orderBy('sp.takvim_sirasi', 'asc');
            if ($personelRolu === 5 && $personelId) {
                $q->where('sp.id', $personelId);
            } else {
                $q->where(fn ($w) => $w->where('sp.salon_id', $salonId)->orWhere('sp.id', 0));
            }
            $rows = $q->get(['sp.id as id', 'sp.personel_adi as ad', 'rd.renk as renk', 'iy.profil_resim as avatar']);
        } elseif ($takvimTuru === 2 || $takvimTuru === 3) {
            $tur = $takvimTuru === 2 ? 'cihaz' : 'oda';
            [$tablo, $renkTablo, $fk, $adKolon] = $takvimTuru === 2
                ? ['cihazlar', 'salon_cihaz_renkleri', 'cihaz_id', 'cihaz_adi']
                : ['odalar', 'salon_oda_renkleri', 'oda_id', 'oda_adi'];
            $rows = DB::table($tablo.' as k')
                ->join($renkTablo.' as kr', 'kr.'.$fk, '=', 'k.id')
                ->join('renk_duzenleri as rd', 'kr.renk_id', '=', 'rd.id')
                ->where('k.salon_id', $salonId)
                ->where('k.aktifmi', true)
                ->orderBy('k.takvim_sirasi', 'asc')
                ->get(['k.id as id', 'k.'.$adKolon.' as ad', 'rd.renk as renk', DB::raw('NULL as avatar')]);
        } else {
            $tur = 'kategori';
            $rows = DB::table('hizmet_kategorisi as hk')
                ->join('salon_sunulan_hizmetler as ssh', function ($j) use ($salonId) {
                    $j->on('ssh.hizmet_kategori_id', '=', 'hk.id')->where('ssh.salon_id', '=', $salonId)->where('ssh.aktif', true);
                })
                ->leftJoin('salon_hizmet_kategori_renkleri as shk', function ($j) use ($salonId) {
                    $j->on('shk.hizmet_kategori_id', '=', 'hk.id')->where('shk.salon_id', '=', $salonId);
                })
                ->leftJoin('renk_duzenleri as rd', 'shk.renk_id', '=', 'rd.id')
                ->groupBy('hk.id', 'hk.hizmet_kategorisi_adi', 'rd.renk')
                ->get(['hk.id as id', 'hk.hizmet_kategorisi_adi as ad', DB::raw("COALESCE(rd.renk, '#999999') as renk"), DB::raw('NULL as avatar')]);
        }

        return $rows->map(fn ($r) => [
            'id'      => (int) $r->id,
            'name'    => $r->ad,
            'bgcolor' => $r->renk ? str_replace('#', '0xFF', $r->renk) : null,
            'avatar'  => $r->avatar ?: null,
            'sayi'    => $sayilar[(string) $r->id] ?? 0,
            'tur'     => $tur, // personel | kategori | cihaz | oda (ekran ikonu icin)
        ])->values()->all();
    }

    /**
     * Tek randevu detayi (eski randevudetay). Telefon 'musteri.telefon_gor'
     * yetkisi yoksa maskelenir. KVKK: ham model dokumu YOK.
     *
     * @throws BulunamadiException
     */
    public function detay(int $randevuId, bool $telGor): array
    {
        $randevu = Randevular::with('users')->find($randevuId);
        if (! $randevu) {
            throw new BulunamadiException('Randevu bulunamadı');
        }

        $telRaw = (string) ($randevu->users->cep_telefon ?? '');

        $hizmetler = RandevuHizmetler::where('randevu_id', $randevuId)->get();
        $hizmetAdlari  = DB::table('hizmetler')->whereIn('id', $hizmetler->pluck('hizmet_id')->filter()->unique()->all())->pluck('hizmet_adi', 'id');
        $personelAdlari = DB::table('salon_personelleri')->whereIn('id', $hizmetler->pluck('personel_id')->filter()->unique()->all())->pluck('personel_adi', 'id');
        $seansSayisi = DB::table('adisyon_paket_seanslar')->where('randevu_id', $randevuId)->count();

        return [
            'id'            => (int) $randevu->id,
            'salon_id'      => (int) $randevu->salon_id,
            'user_id'       => $randevu->user_id !== null ? (int) $randevu->user_id : null,
            'musteri_ad'    => $randevu->users->name ?? null,
            'telefon'       => $telGor ? ($telRaw ?: null) : YetkiSabitleri::telefonMaskele($telRaw),
            'tarih'         => $randevu->tarih,
            'saat'          => $randevu->saat,
            'durum'         => $randevu->durum !== null ? (int) $randevu->durum : null,
            'randevuya_geldi' => $randevu->randevuya_geldi,
            'on_gorusme_id' => $randevu->on_gorusme_id,
            'personel_notu' => $randevu->personel_notu,
            'kapali'        => (int) $randevu->user_id === Randevular::KAPALI_SAAT_USER_ID,
            'seans_var'     => $seansSayisi > 0,
            'hizmetler'     => $hizmetler->map(fn ($h) => [
                'id'           => (int) $h->id,
                'hizmet_id'    => $h->hizmet_id !== null ? (int) $h->hizmet_id : null,
                'hizmet_adi'   => $hizmetAdlari[$h->hizmet_id] ?? null,
                'personel_id'  => $h->personel_id !== null ? (int) $h->personel_id : null,
                'personel_adi' => $personelAdlari[$h->personel_id] ?? null,
                'oda_id'       => $h->oda_id !== null ? (int) $h->oda_id : null,
                'cihaz_id'     => $h->cihaz_id !== null ? (int) $h->cihaz_id : null,
                'saat'         => $h->saat,
                'saat_bitis'   => $h->saat_bitis,
                'sure_dk'      => $h->sure_dk,
                'fiyat'        => $h->fiyat,
            ])->values()->all(),
        ];
    }
}
