<?php

namespace App\Services\SistemYonetim\Salon;

use App\Exceptions\GecersizIslemException;
use App\Models\SistemYoneticisi;
use App\Services\SistemYonetim\Denetim;
use Illuminate\Support\Facades\DB;

/** Askiya alma, aktif etme, temsilci atama: tek salon ve toplu islem ayni alanlari yazar. */
class SalonDurumServisi
{
    private const TOPLU_VARSAYILAN_SEBEP = 'Toplu askıya alma';

    public function askiyaAl(object $salon, string $sebep, SistemYoneticisi $yonetici): void
    {
        $this->yaz([(int) $salon->id], $this->askiAlanlari($sebep, $yonetici));
        Denetim::yaz('salon_askiya_al', 'salon', (int) $salon->id, $salon->salon_adi, "Sebep: {$sebep}", [
            'eski' => ['askiya_alindi' => $salon->askiya_alindi, 'sebep' => $salon->askiya_alma_sebebi],
            'yeni' => ['askiya_alindi' => 1, 'sebep' => $sebep],
        ]);
    }

    public function aktifEt(object $salon): void
    {
        $this->yaz([(int) $salon->id], $this->aktifAlanlari());
        Denetim::yaz('salon_aktif_et', 'salon', (int) $salon->id, $salon->salon_adi, null, ['eski' => ['askiya_alindi' => $salon->askiya_alindi]]);
    }

    public function temsilciAta(object $salon, ?int $temsilciId): void
    {
        $this->yaz([(int) $salon->id], ['musteri_yetkili_id' => $temsilciId]);
        Denetim::yaz('musteri_temsilcisi_ata', 'salon', (int) $salon->id, $salon->salon_adi, null, ['eski_id' => $salon->musteri_yetkili_id, 'yeni_id' => $temsilciId]);
    }

    /**
     * @param  list<int>  $idler
     * @param  array{mt_id?:?int,sebep?:?string}  $g
     * @return string sonuc mesaji
     */
    public function toplu(array $idler, ?string $islem, array $g, SistemYoneticisi $yonetici): string
    {
        if (! $idler) {
            throw new GecersizIslemException('Salon seçilmedi.');
        }

        switch ($islem) {
            case 'mt_ata':
                $temsilciId = $g['mt_id'] ?? null;
                $sayi = $this->yaz($idler, ['musteri_yetkili_id' => $temsilciId]);
                Denetim::yaz('toplu_mt_ata', 'salon', null, "{$sayi} salon", null, ['ids' => $idler, 'mt_id' => $temsilciId]);

                return "{$sayi} salonun müşteri temsilcisi güncellendi.";
            case 'askiya_al':
                $sebep = trim((string) ($g['sebep'] ?? '')) ?: self::TOPLU_VARSAYILAN_SEBEP;
                $sayi = $this->yaz($idler, $this->askiAlanlari($sebep, $yonetici));
                Denetim::yaz('toplu_askiya_al', 'salon', null, "{$sayi} salon", $sebep, ['ids' => $idler]);

                return "{$sayi} salon askıya alındı.";
            case 'aktif_et':
                $sayi = $this->yaz($idler, $this->aktifAlanlari());
                Denetim::yaz('toplu_aktif_et', 'salon', null, "{$sayi} salon", null, ['ids' => $idler]);

                return "{$sayi} salon aktif edildi.";
        }

        throw new GecersizIslemException('Geçersiz işlem.');
    }

    private function yaz(array $idler, array $alanlar): int
    {
        return DB::table('salonlar')->whereIn('id', $idler)->update($alanlar + ['updated_at' => now()]);
    }

    private function askiAlanlari(string $sebep, SistemYoneticisi $yonetici): array
    {
        return ['askiya_alindi' => 1, 'askiya_alma_sebebi' => $sebep, 'askiya_alan_user_id' => $yonetici->id, 'askiya_alma_tarihi' => now()];
    }

    private function aktifAlanlari(): array
    {
        return ['askiya_alindi' => 0, 'askiya_alma_sebebi' => null, 'askiya_alan_user_id' => null, 'askiya_alma_tarihi' => null];
    }
}
