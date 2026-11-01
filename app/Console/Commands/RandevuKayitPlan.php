<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Services\Randevu\RandevuKayitServisi;
use Illuminate\Console\Command;

/**
 * DRY-RUN — randevu olustur/guncelle PLANINI gosterir (HICBIR SEY YAZMAZ):
 * cakisma sonucu, saat zinciri, oda otomatik atama, seans (havuz / bekleyen
 * telafi / yeni seans), adisyon fiyat senkronu, serbest kalacak seanslar.
 *
 * Govde Flutter editoruyle ayni alanlar (JSON). Guncelleme icin "randevu_id" ver.
 *
 * Kullanim:
 *   php artisan randevu:kayit-plan 20 '{"user_id":123,"randevu_tarihi":"2026-11-05","randevu_saati":"14:00","hizmetler":[{"hizmet_id":161,"personel_id":32,"sure_dk":60,"fiyat":500}]}'
 *   php artisan randevu:kayit-plan 20 @/tmp/randevu.json
 */
class RandevuKayitPlan extends Command
{
    protected $signature = 'randevu:kayit-plan {salon : salon_id} {json : JSON govde ya da @dosya}';

    protected $description = 'v2 randevu olustur/guncelle DRY-RUN planini goster (salt okuma)';

    public function handle(RandevuKayitServisi $servis): int
    {
        $ham = (string) $this->argument('json');
        if (str_starts_with($ham, '@')) {
            $ham = (string) @file_get_contents(substr($ham, 1));
        }
        $girdi = json_decode($ham, true);
        if (! is_array($girdi)) {
            $this->error('Gecersiz JSON');

            return self::FAILURE;
        }

        try {
            $sonuc = $servis->kaydet((int) $this->argument('salon'), $girdi, null, false);
        } catch (ApiException $e) {
            $this->warn("Islem yapilamaz ({$e->durumKodu}): ".$e->getMessage());

            return self::SUCCESS;
        }

        $this->line(json_encode($sonuc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
