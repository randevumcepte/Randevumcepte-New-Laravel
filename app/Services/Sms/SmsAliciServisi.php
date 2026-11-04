<?php

namespace App\Services\Sms;

use App\Services\Musteri\MusteriListeServisi;
use App\Services\Musteri\MusteriOkumaServisi;
use App\Support\ListeIstegi;
use App\Support\Yetki\YetkiSabitleri;
use Illuminate\Database\Query\Builder;

/**
 * SMS ALICILARI — salonun aktif portföyü (+ personel kapsamı): seçici listesi, "tümünü seç" kimlikleri,
 * seçilen kimliklerin gönderilebilir telefonlara çözümü. Kapsam ve arama müşteri domaininden gelir.
 */
class SmsAliciServisi
{
    public function __construct(
        private MusteriOkumaServisi $okuma,
        private MusteriListeServisi $musteriListe,
    ) {}

    /** Seçici sayfası: {data:[{id,name,telefon,kara_liste}], …meta}. */
    public function liste(int $salonId, ?int $kisit, ListeIstegi $l, ?string $cinsiyet, bool $telGor): array
    {
        $q = $this->sorgu($salonId, $kisit, $l->ara, $cinsiyet);
        $toplam = (clone $q)->count();

        return [
            'data' => $q->orderBy('u.name')->forPage($l->sayfa, $l->sayfaBoyu)
                ->get(['u.id', 'u.name', 'u.cep_telefon', 'mp.kara_liste'])
                ->map(fn ($r) => [
                    'id'         => (int) $r->id,
                    'name'       => (string) $r->name,
                    'telefon'    => $telGor ? (string) $r->cep_telefon : YetkiSabitleri::telefonMaskele($r->cep_telefon),
                    'kara_liste' => (int) $r->kara_liste === 1,
                ])->all(),
        ] + ListeIstegi::meta($l->sayfa, $l->sayfaBoyu, $toplam);
    }

    /** "Tümünü seç": filtreye uyan bütün müşteri kimlikleri. @return int[] */
    public function idler(int $salonId, ?int $kisit, ?string $ara, ?string $cinsiyet): array
    {
        return $this->sorgu($salonId, $kisit, $ara, $cinsiyet)->pluck('u.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Seçilen kimlikler → gönderilebilir telefonlar. Portföy/kapsam dışı kimlikler sessizce düşer
     * (başka işletmenin müşterisine gönderilemez); kara liste her sağlayıcıda elenir.
     *
     * @param  int[]  $idler
     * @return array{telefonlar:string[], kara_liste:int, gecersiz:int}
     */
    public function cozumle(int $salonId, ?int $kisit, array $idler): array
    {
        $idler = array_values(array_unique(array_filter(array_map('intval', $idler))));
        $telefonlar = [];
        $kara = 0;
        foreach (array_chunk($idler, 1000) as $parca) {
            $satirlar = $this->sorgu($salonId, $kisit, null, null)->whereIn('u.id', $parca)->get(['u.cep_telefon', 'mp.kara_liste']);
            foreach ($satirlar as $s) {
                if ((int) $s->kara_liste === 1) {
                    $kara++;
                } else {
                    $telefonlar[] = (string) $s->cep_telefon;
                }
            }
        }
        $telefonlar = array_values(array_unique($telefonlar));

        return ['telefonlar' => $telefonlar, 'kara_liste' => $kara, 'gecersiz' => count($idler) - count($telefonlar) - $kara];
    }

    private function sorgu(int $salonId, ?int $kisit, ?string $ara, ?string $cinsiyet): Builder
    {
        $q = $this->okuma->temelSorgu($salonId, $kisit)->whereNotNull('u.cep_telefon')->where('u.cep_telefon', '!=', '');
        if ($cinsiyet !== null && $cinsiyet !== '') {
            $q->where('u.cinsiyet', $cinsiyet);
        }

        return $this->musteriListe->aramaUygula($q, $ara);
    }
}
