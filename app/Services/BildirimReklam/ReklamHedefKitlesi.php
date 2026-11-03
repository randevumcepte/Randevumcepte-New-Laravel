<?php

namespace App\Services\BildirimReklam;

use App\Domain\BildirimReklam\HedefKosulu;
use App\Domain\BildirimReklam\HedefSegment;
use App\Support\SalonIdListesi;
use Illuminate\Support\Facades\DB;

/**
 * Reklamın PUSH alıcıları — eski BildirimReklamGonderJob::hedefKullanicilar + alicilar ile
 * birebir kural, v2'de TEK kaynak (gönderim ucu + "kaç kişiye gider" önizlemesi aynı sonucu görür).
 *
 *   taban    : şube grubundaki (yoksa kaynak salon) AKTİF portföy (musteri_portfoy.aktif=1)
 *   segment  : kişi · cinsiyet · bu ay doğum günü · hizmeti alan · ürünü alan · X gündür gelmeyen
 *   alıcılar : hedef ∩ aktif FCM token'ı olanlar (salonun app_bundle'ı varsa yalnız o uygulama)
 *
 * Hizmet / ürün / gelmeyen segmentlerinde adisyon araması eskiyle aynı şekilde KAYNAK salonda.
 */
class ReklamHedefKitlesi
{
    private const PARCA = 2000;

    /** @return array{hedef:int, alici:int} */
    public function sayim(object $reklam): array
    {
        $hedef = $this->hedefKullanicilar($reklam);

        return ['hedef' => count($hedef), 'alici' => count($this->pushAlabilenler($hedef, (int) $reklam->salon_id))];
    }

    /** @return list<int> push gönderilecek user_id'ler */
    public function alicilar(object $reklam): array
    {
        return $this->pushAlabilenler($this->hedefKullanicilar($reklam), (int) $reklam->salon_id);
    }

    /** @return list<int> */
    public function hedefKullanicilar(object $reklam): array
    {
        $salonId = (int) $reklam->salon_id;
        $grup = SalonIdListesi::coz($reklam->gecerli_salonlar ?? null) ?: [$salonId];
        $portfoy = DB::table('musteri_portfoy')->whereIn('salon_id', $grup)->where('aktif', 1)
            ->distinct()->pluck('user_id')->map(fn ($v) => (int) $v)->all();

        $hedef = HedefKosulu::dbden($reklam->hedef_kitle, $reklam->hedef_kosul);
        if (! $hedef->segmentMi() || ! $portfoy) {
            return $portfoy;
        }
        $deger = (int) $hedef->deger;

        $sonuc = match ($hedef->segment) {
            // Test / tekil gönderim: yalnız seçilen kişi (eski: portföy şartı yok).
            HedefSegment::KISI => $deger > 0 ? [$deger] : [],
            HedefSegment::CINSIYET => $this->parcali($portfoy, fn ($p) => DB::table('users')->whereIn('id', $p)->where('cinsiyet', (string) $deger)->pluck('id')->all()),
            HedefSegment::DOGUM_GUNU => $this->parcali($portfoy, fn ($p) => DB::table('users')->whereIn('id', $p)
                ->whereNotNull('dogum_tarihi')->whereRaw('MONTH(dogum_tarihi) = ?', [(int) now()->format('m')])->pluck('id')->all()),
            HedefSegment::HIZMET => $deger > 0 ? $this->kalemAlanlar($salonId, $portfoy, 'adisyon_hizmetler', 'hizmet_id', $deger) : $portfoy,
            HedefSegment::URUN => $deger > 0 ? $this->kalemAlanlar($salonId, $portfoy, 'adisyon_urunler', 'urun_id', $deger) : $portfoy,
            HedefSegment::GELMEYEN => $this->gelmeyenler($salonId, $portfoy, $deger),
        };

        return array_values(array_unique(array_map('intval', $sonuc)));
    }

    /** @param list<int> $userIds @return list<int> */
    private function pushAlabilenler(array $userIds, int $salonId): array
    {
        if (! $userIds) {
            return [];
        }
        $bundle = DB::table('salonlar')->where('id', $salonId)->value('app_bundle');

        return array_values(array_unique(array_map('intval', $this->parcali($userIds, fn ($p) => DB::table('bildirim_kimlikleri')
            ->whereIn('user_id', $p)->where('aktif', 1)
            ->whereNotNull('bildirim_id')->where('bildirim_id', '!=', '')
            ->when($bundle, fn ($q) => $q->where('app_bundle', $bundle))
            ->distinct()->pluck('user_id')->all()))));
    }

    /** Kaynak salonda belirli hizmeti / ürünü satın almış portföy müşterileri. */
    private function kalemAlanlar(int $salonId, array $portfoy, string $tablo, string $kolon, int $id): array
    {
        return $this->parcali($portfoy, fn ($p) => DB::table('adisyonlar')
            ->join($tablo, 'adisyonlar.id', '=', $tablo.'.adisyon_id')
            ->where('adisyonlar.salon_id', $salonId)->whereIn('adisyonlar.user_id', $p)
            ->where($tablo.'.'.$kolon, $id)
            ->distinct()->pluck('adisyonlar.user_id')->all());
    }

    /** Son $gun günde kaynak salonda adisyonu (ziyareti) OLMAYAN portföy müşterileri. */
    private function gelmeyenler(int $salonId, array $portfoy, int $gun): array
    {
        $esik = now()->subDays($gun > 0 ? $gun : HedefSegment::VARSAYILAN_GUN)->toDateTimeString();
        $gelen = array_flip(array_map('intval', $this->parcali($portfoy, fn ($p) => DB::table('adisyonlar')
            ->where('salon_id', $salonId)->whereIn('user_id', $p)->where('created_at', '>=', $esik)
            ->distinct()->pluck('user_id')->all())));

        return array_values(array_filter($portfoy, fn ($uid) => ! isset($gelen[$uid])));
    }

    /** Büyük whereIn listelerini parçalar (placeholder sınırı). */
    private function parcali(array $idler, callable $sorgu): array
    {
        $out = [];
        foreach (array_chunk($idler, self::PARCA) as $parca) {
            array_push($out, ...$sorgu($parca));
        }

        return $out;
    }
}
