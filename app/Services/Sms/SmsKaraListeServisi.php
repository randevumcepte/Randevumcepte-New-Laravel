<?php

namespace App\Services\Sms;

use App\Services\Musteri\MusteriListeServisi;
use App\Services\Musteri\MusteriOkumaServisi;
use App\Services\Musteri\MusteriYazServisi;
use App\Support\ListeIstegi;
use App\Support\Yetki\YetkiSabitleri;

/**
 * SMS KARA LİSTE — listeleme burada; ekle/çıkar müşteri domaininin TEK yazma yolu
 * (MusteriYazServisi::karaListe: portföy + "kara listeye alındınız" outbox'ı + aktivite logu).
 */
class SmsKaraListeServisi
{
    private const SIRALAMA = ['ad' => 'u.name', 'telefon' => 'u.cep_telefon', 'tarih' => 'mp.updated_at'];

    public const SIRALANABILIR = ['ad', 'telefon', 'tarih'];

    public function __construct(
        private MusteriOkumaServisi $okuma,
        private MusteriListeServisi $musteriListe,
        private MusteriYazServisi $yaz,
    ) {}

    /** {data:[{user_id, ad_soyad, telefon, eklenme_tarihi}], …meta} — alan adları eski uçla aynı. */
    public function liste(int $salonId, ?int $kisit, ListeIstegi $l, bool $telGor): array
    {
        $q = $this->musteriListe->aramaUygula($this->okuma->temelSorgu($salonId, $kisit)->where('mp.kara_liste', 1), $l->ara);
        $toplam = (clone $q)->count();

        return [
            'data' => $q->orderBy(self::SIRALAMA[$l->sirala] ?? 'mp.updated_at', $l->yon)->forPage($l->sayfa, $l->sayfaBoyu)
                ->get(['u.id', 'u.name', 'u.cep_telefon', 'mp.updated_at'])
                ->map(fn ($r) => [
                    'user_id'        => (int) $r->id,
                    'ad_soyad'       => (string) $r->name,
                    'telefon'        => $telGor ? (string) $r->cep_telefon : YetkiSabitleri::telefonMaskele($r->cep_telefon),
                    'eklenme_tarihi' => $r->updated_at ? date('d.m.Y', strtotime((string) $r->updated_at)) : '',
                ])->all(),
        ] + ListeIstegi::meta($l->sayfa, $l->sayfaBoyu, $toplam);
    }

    public function degistir(int $yetkiliId, int $salonId, ?int $kisit, int $userId, bool $kara): array
    {
        $this->okuma->portfoyZorunlu($salonId, $userId, $kisit); // portföy/kapsam dışı → 404

        return $this->yaz->karaListe($salonId, $userId, $kara, $this->okuma->salonAyarlari($salonId)['kara_liste_aktif'], $yetkiliId);
    }
}
