<?php

namespace App\Services\Ayar;

use App\Domain\Ayar\AyarSozluk;
use App\Domain\Ayar\HexRenk;
use App\Exceptions\BulunamadiException;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Randevu ayarlari (aralik, takvim turu, online randevu, gecmis gizle, cakisma uyarisi,
 * studyo modu, takvim tek renk / yazi rengi / renk sirasi) — eski
 * StoreAdminController@randevuayarguncelle, v1 randevuayarguncelle + takvimRenkGet/Set'in
 * TEK karsiligi (Flutter takvim renk ekrani da bu uca baglanir).
 *
 * Kaydetme KISMIDIR: yalnizca gonderilen alanlar degisir (v1 Flutter studyo/renk alanlarini
 * gondermiyordu; eski web "gelmeyen checkbox = 0" kuraliyla bunlari sifirlayabiliyordu).
 * Gecersiz/bos renk geldiyse MEVCUT renk korunur (eskiyle ayni).
 */
class RandevuAyarServisi
{
    /** Acik/kapali (0/1) kolonlar. */
    public const BAYRAKLAR = [
        'musteri_online_randevu_aktif', 'gecmis_randevulari_gizle', 'cakisma_uyarisi_aktif',
        'studyo_modu', 'takvim_tek_renk_aktif', 'takvim_yazi_rengi_aktif',
    ];

    private const KOLONLAR = [
        'salon_adi', 'randevu_saat_araligi', 'randevu_takvim_turu', ...self::BAYRAKLAR,
        'takvim_tek_renk', 'takvim_yazi_rengi', 'takvim_renk_sirasi',
    ];

    public function getir(int $salonId): array
    {
        $s = $this->salon($salonId);
        $palet = $this->palet();

        $veri = [
            'randevu_saat_araligi' => (int) ($s->randevu_saat_araligi ?: 30),
            'randevu_takvim_turu'  => (int) $s->randevu_takvim_turu,
        ];
        foreach (self::BAYRAKLAR as $b) {
            $veri[$b] = (int) $s->{$b} === 1;
        }

        return $veri + [
            'takvim_tek_renk'    => HexRenk::normalize($s->takvim_tek_renk) ?? HexRenk::TEK_RENK_VARSAYILAN,
            'takvim_yazi_rengi'  => HexRenk::normalize($s->takvim_yazi_rengi) ?? HexRenk::YAZI_RENGI_VARSAYILAN,
            'takvim_renk_sirasi' => HexRenk::siraTamamla(HexRenk::siraCoz($s->takvim_renk_sirasi), $palet),
            'secenekler'         => [
                'saat_araliklari' => AyarSozluk::SAAT_ARALIKLARI,
                'takvim_turleri'  => AyarSozluk::TAKVIM_TURLERI,
                'palet'           => $palet,
            ],
        ];
    }

    /** @param  array  $g  dogrulanmis kismi alanlar (RandevuAyarIstegi) */
    public function kaydet(int $salonId, int $yapanId, array $g): array
    {
        $s = $this->salon($salonId);
        $guncelle = array_intersect_key($g, array_flip(['randevu_saat_araligi', 'randevu_takvim_turu']));
        foreach (self::BAYRAKLAR as $b) {
            if (array_key_exists($b, $g)) {
                $guncelle[$b] = $g[$b] ? 1 : 0;
            }
        }

        // Renk: gecerli geldiyse yaz; aktif edilip hic renk yoksa varsayilan ata (eskiyle ayni).
        foreach (['takvim_tek_renk' => HexRenk::TEK_RENK_VARSAYILAN, 'takvim_yazi_rengi' => HexRenk::YAZI_RENGI_VARSAYILAN] as $alan => $varsayilan) {
            $renk = HexRenk::normalize($g[$alan] ?? null);
            if ($renk !== null) {
                $guncelle[$alan] = $renk;
            } elseif (! empty($guncelle[$alan.'_aktif']) && ! HexRenk::normalize($s->{$alan})) {
                $guncelle[$alan] = $varsayilan;
            }
        }
        if (array_key_exists('takvim_renk_sirasi', $g)) {
            $sira = HexRenk::siraCoz($g['takvim_renk_sirasi'] ?? []);
            $guncelle['takvim_renk_sirasi'] = $sira ? json_encode($sira) : null;
        }

        if ($guncelle) {
            DB::table('salonlar')->where('id', $salonId)->update($guncelle);
        }

        AktiviteLog::yaz($salonId, $yapanId, 'randevu_ayar_guncelle', 'salon', $salonId,
            (string) $s->salon_adi, 'Randevu ayarları güncellendi',
            array_diff_key($guncelle, ['takvim_renk_sirasi' => true]));

        return $this->getir($salonId);
    }

    /** Global 10'lu palet (renk_duzenleri) — eski blade / takvimRenkGet ile ayni. */
    private function palet(): array
    {
        return DB::table('renk_duzenleri')->orderBy('id')->pluck('renk')
            ->map(fn ($h) => HexRenk::normalize($h))->filter()->values()->all();
    }

    private function salon(int $salonId): object
    {
        $s = DB::table('salonlar')->where('id', $salonId)->first(self::KOLONLAR);
        if (! $s) {
            throw new BulunamadiException('İşletme bulunamadı');
        }

        return $s;
    }
}
