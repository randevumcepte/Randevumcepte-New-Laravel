<?php

namespace App\Services\Seans;

use App\Domain\Seans\MobilSeansKurali;
use App\Domain\Seans\SeansKalemTuru;
use App\Exceptions\GecersizIslemException;
use App\Models\Adisyonlar;
use App\Models\AdisyonPaketSeanslar;
use App\Services\Paket\PaketKullanimi;
use App\Support\AktiviteLog;
use App\Support\ListeIstegi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mobil seans takibi — eski ApiController::seans_getir, seansEkle, seansGuncelle ile ayni liste,
 * sayac ve satirlar. Salon kapsami controller'da denetlenir; web SeansTakipServisi ve
 * SeansYazServisi kurallarini kullanir, bu servis yalniz imzali mobil token'la cagrilir.
 * Musteri yalniz {id, name} doner: mobil baska alan okumaz.
 */
class SeansMobilServisi
{
    private const SAYFA_BOYU = 10;

    private const SEANS_ALANLARI = ['id', 'adisyon_paket_id', 'adisyon_hizmet_id', 'hizmet_id', 'seans_no', 'seans_tarih', 'seans_saat', 'geldi', 'iptal', 'randevu_id'];

    public function __construct(private PaketKullanimi $kullanim) {}

    public function liste(int $salonId, string $arama, string $musteriId, int $sayfa): array
    {
        $adisyonIdler = $this->adisyonIdleri($salonId, $arama, $musteriId);
        $kalemler = $this->kalemler('adisyon_paketler', SeansKalemTuru::PAKET, 'paket_id', $adisyonIdler)
            ->merge($this->kalemler('adisyon_hizmetler', SeansKalemTuru::HIZMET, 'hizmet_id', $adisyonIdler))
            ->sortByDesc('siralama')
            ->values();
        $sayfaKalemleri = $kalemler->forPage($sayfa, self::SAYFA_BOYU)->values();

        return [
            'data'         => $this->satirlar($sayfaKalemleri),
            'current_page' => $sayfa,
            'last_page'    => ListeIstegi::meta($sayfa, self::SAYFA_BOYU, $kalemler->count())['last_page'],
            'total'        => $kalemler->count(),
        ];
    }

    /** @return array{hatali:string, mesaj:string, id:int} */
    public function ekle(SeansKalemTuru $tur, object $kalem, int $hizmetId, int $geldi, string $tarih, int $yetkiliId): array
    {
        if ($tur === SeansKalemTuru::PAKET && ! $this->kullanim->seansUygun((int) $kalem->id, $hizmetId, $tarih)) {
            throw new GecersizIslemException('Paketin geçerlilik süresi dolmuş veya günlük kullanım limiti aşılmış.');
        }

        $seans = AdisyonPaketSeanslar::create([
            $tur->seansKolonu() => $kalem->id,
            'hizmet_id'         => $hizmetId,
            'seans_tarih'       => $tarih,
            'geldi'             => $geldi,
        ]);
        AktiviteLog::yaz((int) $kalem->salon_id, $yetkiliId, 'seans_ekle', 'seans', (int) $seans->id, null, 'Seans eklendi');

        return ['hatali' => '0', 'mesaj' => 'Başarılı', 'id' => (int) $seans->id];
    }

    /** geldi null ise seans satiri silinir (randevuya bagli olsa da), degilse deger yazilir. */
    public function durum(object $seans, ?int $geldi, int $salonId, int $yetkiliId): array
    {
        $sorgu = AdisyonPaketSeanslar::whereKey($seans->id);
        $silindi = $geldi === null;
        $silindi ? $sorgu->delete() : $sorgu->update(['geldi' => $geldi]);
        AktiviteLog::yaz($salonId, $yetkiliId, $silindi ? 'seans_sil' : 'seans_guncelle', 'seans', (int) $seans->id, null, $silindi ? 'Seans silindi' : 'Seans güncellendi');

        return ['hatali' => '0', 'mesaj' => 'Başarılı'];
    }

    /** Musteri filtresi aramayi yok sayar; aramada musteri adi ve telefonu taranir. */
    private function adisyonIdleri(int $salonId, string $arama, string $musteriId): array
    {
        $sorgu = Adisyonlar::query()->where('salon_id', $salonId);
        if ($musteriId !== '') {
            $sorgu->where('user_id', $musteriId);
        } else {
            $like = '%'.$arama.'%';
            $sorgu->whereHas('musteri', fn ($q) => $q->where('name', 'like', $like)->orWhere('cep_telefon', 'like', $like));
        }

        return $sorgu->pluck('id')->all();
    }

    private function kalemler(string $tablo, SeansKalemTuru $tur, string $katalogKolonu, array $adisyonIdler): Collection
    {
        return DB::table($tablo)->where('seans_sayisi', '>', 0)->whereIn('adisyon_id', $adisyonIdler)
            ->get(['id', 'adisyon_id', $katalogKolonu.' as katalog_id', 'seans_sayisi', 'bekleyen_seans', 'created_at'])
            ->map(function ($k) use ($tur) {
                $k->tur = $tur;
                $k->siralama = $k->created_at ? strtotime((string) $k->created_at) : null;

                return $k;
            });
    }

    private function satirlar(Collection $kalemler): array
    {
        $paketKalemleri = $kalemler->filter(fn ($k) => $k->tur === SeansKalemTuru::PAKET);
        $hizmetKalemleri = $kalemler->filter(fn ($k) => $k->tur === SeansKalemTuru::HIZMET);

        $musteriler = DB::table('adisyonlar as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->whereIn('a.id', $kalemler->pluck('adisyon_id')->unique()->all())
            ->get(['a.id', 'u.id as musteri_id', 'u.name'])->keyBy('id');
        $paketAdlari = DB::table('paketler')->whereIn('id', $paketKalemleri->pluck('katalog_id')->unique()->all())->pluck('paket_adi', 'id');
        $hizmetAdlari = DB::table('hizmetler')->whereIn('id', $hizmetKalemleri->pluck('katalog_id')->unique()->all())->pluck('hizmet_adi', 'id');
        $paketSeanslari = $this->seanslar('adisyon_paket_id', $paketKalemleri->pluck('id')->all(), true);
        $hizmetSeanslari = $this->seanslar('adisyon_hizmet_id', $hizmetKalemleri->pluck('id')->all(), false);
        $paketHizmetleri = DB::table('paket_hizmetler')->join('hizmetler', 'paket_hizmetler.hizmet_id', '=', 'hizmetler.id')
            ->whereIn('paket_hizmetler.paket_id', $paketKalemleri->pluck('katalog_id')->unique()->all())
            ->get(['paket_hizmetler.paket_id as paket_id', 'hizmetler.id as id', 'hizmetler.hizmet_adi'])
            ->groupBy('paket_id');
        $gecerlilik = $this->kullanim->durumlar($paketKalemleri->pluck('id')->map(fn ($id) => (int) $id)->all());

        return $kalemler->map(function ($k) use ($musteriler, $paketAdlari, $hizmetAdlari, $paketSeanslari, $hizmetSeanslari, $paketHizmetleri, $gecerlilik) {
            $paketMi = $k->tur === SeansKalemTuru::PAKET;
            $seanslar = ($paketMi ? $paketSeanslari : $hizmetSeanslari)[$k->id] ?? [];
            $durum = $paketMi ? ($gecerlilik[(int) $k->id] ?? ['sinirsiz' => false, 'sure' => '', 'baslangic' => null, 'bitis' => null]) : null;
            $sayac = MobilSeansKurali::sayac($seanslar, $k->bekleyen_seans, $durum['sinirsiz'] ?? false);
            $musteri = $musteriler[$k->adisyon_id] ?? null;
            $ad = ($paketMi ? $paketAdlari : $hizmetAdlari)[$k->katalog_id] ?? '';

            $satir = [
                'adisyon'               => (int) $k->adisyon_id,
                'musteri'               => $musteri && $musteri->musteri_id ? ['id' => (int) $musteri->musteri_id, 'name' => $musteri->name] : null,
                'paket'                 => $ad.($paketMi ? ' (P)' : ' (H)'),
                'seanslar'              => $seanslar,
                'toplamSeansSayisi'     => (int) $k->seans_sayisi,
                'bekleyenSeansSayisi'   => $sayac['bekleyen'],
                'gelinenSeansSayisi'    => $sayac['gelinen'],
                'gelinmeyenSeansSayisi' => $sayac['gelinmeyen'],
                'tip'                   => $k->tur->value,
                'paketHizmetId'         => (int) $k->id,
            ];

            return $paketMi
                ? $satir + [
                    'sinirsiz'  => $durum['sinirsiz'] ? 1 : 0,
                    'sure'      => $durum['sure'],
                    'baslangic' => $durum['baslangic'],
                    'bitis'     => $durum['bitis'],
                    'hizmetler' => ($paketHizmetleri[$k->katalog_id] ?? collect())->values()->all(),
                ]
                : $satir + ['hizmetler' => [['id' => (int) $k->katalog_id, 'hizmet_adi' => $hizmetAdlari[$k->katalog_id] ?? null]]];
        })->all();
    }

    /** @return array<int,list<array<string,mixed>>> kalem id => seans satirlari */
    private function seanslar(string $kolon, array $kalemIdler, bool $personelAdi): array
    {
        if (! $kalemIdler) {
            return [];
        }
        $alanlar = self::SEANS_ALANLARI;
        if ($personelAdi) {
            $alanlar[] = DB::raw('(SELECT personel_adi FROM salon_personelleri sp WHERE sp.id = adisyon_paket_seanslar.personel_id) as personel_adi');
        }
        $satirlar = DB::table('adisyon_paket_seanslar')->whereIn($kolon, $kalemIdler)->select($alanlar)->get();
        $adlar = DB::table('hizmetler')->whereIn('id', $satirlar->pluck('hizmet_id')->filter()->unique()->all())->pluck('hizmet_adi', 'id');

        $sonuc = [];
        foreach ($satirlar as $s) {
            $sonuc[$s->{$kolon}][] = (array) $s + [
                'hizmet' => isset($adlar[$s->hizmet_id]) ? ['id' => (int) $s->hizmet_id, 'hizmet_adi' => $adlar[$s->hizmet_id]] : null,
            ];
        }

        return $sonuc;
    }
}
