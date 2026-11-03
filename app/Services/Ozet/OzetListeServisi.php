<?php

namespace App\Services\Ozet;

use App\Domain\Ozet\OzetSekmesi;
use App\Domain\Randevu\RandevuDurumu;
use App\Domain\Rapor\RaporDonemi;
use App\Models\Randevular;
use App\Services\Randevu\RandevuListeServisi;
use App\Services\Rapor\SatisKalemSorgusu;
use App\Services\Satis\SatisListeServisi;
use App\Services\Tahsilat\AlacakServisi;
use App\Support\Yetki\YetkiSabitleri;
use Illuminate\Support\Facades\DB;

/**
 * Ozet alt paneli LISTE SEKMELERI — eski StoreAdminController::dashboardSekme karsiligi.
 * Kolon sozlesmesi OzetSekmesi::kolonlar() ile ayni anahtarlar.
 *
 * Eskiden duzeltilenler: randevu x hizmet JOIN'i ayni randevuyu hizmet sayisi kadar tekrar
 * ediyordu → randevu basina TEK satir (hizmetler birlesik); acik adisyon odeneni kalem-split
 * (Satis Takibi ile ayni, SatisListeServisi); alacak odenmis vade kalintisini gostermiyor
 * (AlacakServisi::acikSorgu); dogum gunu yil donumunu (28 Ara → 5 Oca) kaciriyordu ve pasif
 * portfoyu de listeliyordu. Telefon musteri.telefon_gor yoksa maskeli. Hicbir sey YAZMAZ.
 */
class OzetListeServisi
{
    private const DOGUM_GUNU_ILERI = 10;

    public function __construct(private RandevuListeServisi $randevuListe) {}

    /** @param ?int $personelId randevu sekmelerinde yalniz bu personelin (asil) randevulari */
    public function liste(int $salonId, OzetSekmesi $sekme, bool $telGor, ?int $personelId = null): array
    {
        $satirlar = match ($sekme) {
            OzetSekmesi::OnlineTalep    => $this->randevular($salonId, $personelId, true),
            OzetSekmesi::BugunkuRandevu => $this->randevular($salonId, $personelId, false),
            OzetSekmesi::AcikAdisyon    => $this->acikAdisyonlar($salonId),
            OzetSekmesi::Alacak         => $this->alacaklar($salonId),
            OzetSekmesi::DogumGunu      => $this->dogumGunleri($salonId),
        };

        if (! $telGor) {
            foreach ($satirlar as &$s) {
                $s['telefon'] = $s['telefon'] ? YetkiSabitleri::telefonMaskele($s['telefon']) : null;
            }
            unset($s);
        }

        return ['sekme' => $sekme->value, 'liste' => $satirlar];
    }

    /** Online talep (web/uygulama kaynakli, bugun ve sonrasi, beklemede/onayli) veya bugunun randevulari. */
    private function randevular(int $salonId, ?int $personelId, bool $online): array
    {
        $bugun = date('Y-m-d');
        $q = DB::table('randevular as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.salon_id', $salonId)
            ->where('r.user_id', '!=', Randevular::KAPALI_SAAT_USER_ID)
            ->when($online, fn ($q) => $q->where('r.tarih', '>=', $bugun)
                ->whereIn('r.durum', [Randevular::BEKLENIYOR, Randevular::ONAYLANDI])
                ->where(fn ($w) => $w->where('r.web', 1)->orWhere('r.uygulama', 1)), fn ($q) => $q->where('r.tarih', $bugun))
            ->when($personelId, fn ($q) => $q->whereExists(fn ($e) => $e->selectRaw('1')->from('randevu_hizmetler as rk')
                ->whereColumn('rk.randevu_id', 'r.id')->where('rk.personel_id', $personelId)
                ->whereRaw('COALESCE(rk.yardimci_personel,0) != 1')))
            ->orderBy('r.tarih')->orderBy('r.saat')
            ->limit($online ? OzetSekmesi::LIMIT : 50);

        $randevular = $q->get(['r.id', 'r.tarih', 'r.saat', 'r.durum', 'r.randevuya_geldi', 'r.user_id', 'u.name', 'u.cep_telefon']);
        $kalem = $this->randevuListe->kalemler($randevular->pluck('id')->all());

        return $randevular->map(fn ($r) => [
            'id'         => (int) $r->id,
            'musteri_id' => $r->user_id ? (int) $r->user_id : null,
            'tarih'      => $r->tarih,
            'saat'       => $r->saat ? substr((string) $r->saat, 0, 5) : null,
            'musteri'    => $r->name,
            'telefon'    => $r->cep_telefon,
            'hizmet'     => implode(', ', $kalem[$r->id]['hizmetler'] ?? []) ?: null,
            'personel'   => implode(', ', $kalem[$r->id]['kaynaklar'] ?? []) ?: null,
            'durum'      => RandevuDurumu::etiket($r->durum, $r->randevuya_geldi),
        ])->all();
    }

    /** Son 30 gunun (satis tarihi) kalani olan adisyonlari — net/odenen Satis Takibi ile ayni. */
    private function acikAdisyonlar(int $salonId): array
    {
        $net = SatisListeServisi::netSql();
        $odenen = SatisListeServisi::odenenSql();
        $q = DB::table('adisyonlar')->leftJoin('users as u', 'u.id', '=', 'adisyonlar.user_id')
            ->where('adisyonlar.salon_id', $salonId);

        return SatisKalemSorgusu::donemFiltresi($q, RaporDonemi::coz('son30', null, null), 'adisyonlar')
            ->selectRaw('adisyonlar.id, adisyonlar.user_id, '.SatisKalemSorgusu::satisTarihiSql('adisyonlar').' as tarih, u.name, u.cep_telefon, '
                ."{$net} as net, {$odenen} as odenen")
            ->havingRaw('net > odenen AND net > 0')
            ->orderByDesc('tarih')->orderByDesc('adisyonlar.id')
            ->limit(OzetSekmesi::LIMIT)->get()
            ->map(fn ($a) => [
                'id'         => (int) $a->id,
                'musteri_id' => $a->user_id ? (int) $a->user_id : null,
                'tarih'      => $a->tarih,
                'musteri'    => $a->name,
                'telefon'    => $a->cep_telefon,
                'toplam'     => round((float) $a->net, 2),
                'kalan'      => round((float) $a->net - (float) $a->odenen, 2),
            ])->all();
    }

    /** Acik alacaklar — vadesi en eski ustte; vadesi_gecmis rozeti. */
    private function alacaklar(int $salonId): array
    {
        $bugun = date('Y-m-d');

        return AlacakServisi::acikSorgu($salonId)->leftJoin('users as u', 'u.id', '=', 'alacaklar.user_id')
            ->orderBy('alacaklar.planlanan_odeme_tarihi')->limit(OzetSekmesi::LIMIT)
            ->get(['alacaklar.id', 'alacaklar.adisyon_id', 'alacaklar.user_id', 'alacaklar.planlanan_odeme_tarihi', 'alacaklar.tutar', 'u.name', 'u.cep_telefon'])
            ->map(fn ($a) => [
                'id'                     => (int) $a->id,
                'adisyon_id'             => $a->adisyon_id ? (int) $a->adisyon_id : null,
                'musteri_id'             => $a->user_id ? (int) $a->user_id : null,
                'planlanan_odeme_tarihi' => $a->planlanan_odeme_tarihi,
                'musteri'                => $a->name,
                'telefon'                => $a->cep_telefon,
                'tutar'                  => round((float) $a->tutar, 2),
                'vadesi_gecmis'          => $a->planlanan_odeme_tarihi && $a->planlanan_odeme_tarihi < $bugun,
            ])->all();
    }

    /** Bugun + 10 gun icinde dogum gunu olan AKTIF portfoy musterileri (yil donumu dahil). */
    private function dogumGunleri(int $salonId): array
    {
        $bugun = new \DateTimeImmutable('today');
        $gunler = [];
        for ($i = 0; $i <= self::DOGUM_GUNU_ILERI; $i++) {
            $gunler[$bugun->modify("+{$i} day")->format('m-d')] = $i;
        }

        return DB::table('users as u')->join('musteri_portfoy as mp', 'mp.user_id', '=', 'u.id')
            ->where('mp.salon_id', $salonId)->where('mp.aktif', 1)
            ->whereNotNull('u.dogum_tarihi')
            ->whereIn(DB::raw("DATE_FORMAT(u.dogum_tarihi, '%m-%d')"), array_keys($gunler))
            ->get(['u.id', 'u.name', 'u.cep_telefon', 'u.dogum_tarihi'])
            ->map(fn ($m) => [
                'musteri_id'   => (int) $m->id,
                'dogum_tarihi' => substr((string) $m->dogum_tarihi, 0, 10),
                'musteri'      => $m->name,
                'telefon'      => $m->cep_telefon,
                'kalan_gun'    => $gunler[substr((string) $m->dogum_tarihi, 5, 5)] ?? null,
            ])->sortBy('kalan_gun')->values()->take(OzetSekmesi::LIMIT)->all();
    }
}
