<?php

namespace App\Services\Ayar;

use App\Domain\Ayar\SalonTemaSeed;
use App\Exceptions\BulunamadiException;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Salon temasi (Flutter "Görünüm" + mini site rengi) — v1 salonTemaGet / salonTemaSet'in TEK
 * karsiligi. Cevap {config, seed}: config dogrudan Flutter AppThemeConfig.fromJson bicimidir.
 * tema_config ham saklanir, tema_seed mini site icin sunucuda cozulur (SalonTemaSeed).
 */
class TemaServisi
{
    public function getir(int $salonId): array
    {
        $s = DB::table('salonlar')->where('id', $salonId)->first(['tema_config', 'tema_seed']);
        if (! $s) {
            throw new BulunamadiException('İşletme bulunamadı');
        }
        $config = $s->tema_config ? json_decode($s->tema_config, true) : null;

        return [
            'config'     => is_array($config) ? $config : SalonTemaSeed::varsayilan(),
            'seed'       => $s->tema_seed ?: SalonTemaSeed::VARSAYILAN_SEED,
            'secenekler' => [
                'presetler'         => array_keys(SalonTemaSeed::PRESET_SEED),
                'ozel_preset'       => SalonTemaSeed::OZEL,
                'parlaklik_modlari' => SalonTemaSeed::PARLAKLIK_MODLARI,
            ],
        ];
    }

    /** @param  array{preset:string, customSeedArgb?:?int, brightnessMode?:string}  $g */
    public function kaydet(int $salonId, int $yapanId, array $g): array
    {
        $config = ['preset' => $g['preset'], 'brightnessMode' => $g['brightnessMode'] ?? 'light'];
        if (isset($g['customSeedArgb']) && $g['customSeedArgb'] !== '') {
            $config['customSeedArgb'] = (int) $g['customSeedArgb'];
        }
        $seed = SalonTemaSeed::seed($config);

        DB::table('salonlar')->where('id', $salonId)->update([
            'tema_config' => json_encode($config, JSON_UNESCAPED_UNICODE),
            'tema_seed'   => $seed,
        ]);

        AktiviteLog::yaz($salonId, $yapanId, 'salon_tema_guncelle', 'salon', $salonId,
            $config['preset'], 'Uygulama / mini site teması güncellendi', ['seed' => $seed]);

        return $this->getir($salonId);
    }
}
