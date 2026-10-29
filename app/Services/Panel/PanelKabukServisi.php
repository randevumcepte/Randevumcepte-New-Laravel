<?php

namespace App\Services\Panel;

use App\Services\Yetki\KapsamServisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\PanelMenu;
use Illuminate\Support\Facades\DB;

/**
 * Panel kabuğu (shell) servisi — menü + header için gereken MİNİMUM veri.
 *
 * Eski panelin (layout_isletmeadmin) sidebar + header'ının v2 karşılığını besler.
 * KVKK: yalnızca gerekli alanlar döner (ham model dökümü YOK). Kapsam/kimlik
 * denetimi controller + GuvenlikKapisi'nde; burada iş mantığı (menü filtreleme,
 * lisans günü, şube listesi, mutlak link üretimi).
 *
 * Linkler eski panele (app.*) gider → v2 ekranı eski panelle PARALEL kullanılır.
 */
class PanelKabukServisi
{
    public function __construct(
        private YetkiServisi $yetki,
        private KapsamServisi $kapsam,
    ) {}

    public function kabuk(int $yetkiliId, int $salonId): array
    {
        $tabanUrl = rtrim(config('panel.eski_panel_url', 'https://app.randevumcepte.com.tr'), '/');
        $salon = $this->salonGetir($salonId);
        $roller = $this->salonRolleri($yetkiliId, $salonId);
        $rol5 = in_array(5, $roller, true);

        return [
            'taban_url' => $tabanUrl,
            'kullanici' => $this->kullanici($yetkiliId, $tabanUrl),
            'salon'     => [
                'id'          => $salonId,
                'ad'          => trim((string) ($salon->salon_adi ?? '')),
                'lisans_gun'  => $this->lisansGun($salon),
                'uyelik_turu' => (int) ($salon->uyelik_turu ?? 0),
            ],
            'subeler' => $this->subeler($yetkiliId, $salonId, $tabanUrl),
            'menu'    => $this->menu($yetkiliId, $salonId, $salon, $rol5, $tabanUrl),
        ];
    }

    /** Giriş yapmış işletme yetkilisinin görünen adı + avatarı (minimum). */
    private function kullanici(int $yetkiliId, string $tabanUrl): array
    {
        $y = DB::table('isletmeyetkilileri')->where('id', $yetkiliId)->first(['name', 'profil_resim']);
        $avatar = $y && $y->profil_resim ? $this->mutlak($y->profil_resim, $tabanUrl) : null;

        return [
            'ad'     => trim((string) ($y->name ?? 'Kullanıcı')),
            'avatar' => $avatar,
        ];
    }

    /** Kullanıcının eriştiği salonlar (şube seçici). */
    private function subeler(int $yetkiliId, int $aktifSalonId, string $tabanUrl): array
    {
        $idler = $this->kapsam->kullaniciSalonlari($yetkiliId);
        if (! $idler) {
            $idler = [$aktifSalonId];
        }
        $satirlar = DB::table('salonlar')->whereIn('id', $idler)->orderBy('salon_adi')->get(['id', 'salon_adi']);

        return $satirlar->map(fn ($s) => [
            'id'    => (int) $s->id,
            'ad'    => trim((string) $s->salon_adi),
            'url'   => $tabanUrl.'/isletmeyonetim?sube='.(int) $s->id,
            'aktif' => (int) $s->id === $aktifSalonId,
        ])->all();
    }

    /** Menüyü yetki/özellik/rol'e göre filtreleyip mutlak linklerle döndür. */
    private function menu(int $yetkiliId, int $salonId, $salon, bool $rol5, string $tabanUrl): array
    {
        $cikti = [];
        foreach (PanelMenu::tanim() as $m) {
            if (! $this->menuGoster($m, $yetkiliId, $salonId, $salon, $rol5)) {
                continue;
            }
            $cikti[] = [
                'etiket'    => $this->etiketCoz($m, $salon),
                'ikon'      => $m['ikon'],
                'slug'      => $m['slug'],
                'url'       => $this->menuUrl($m, $salonId, $tabanUrl),
                'pageindex' => $m['pageindex'] ?? null,
                'bolum'     => $m['bolum'] ?? null,
            ];
        }

        return $cikti;
    }

    private function menuGoster(array $m, int $yetkiliId, int $salonId, $salon, bool $rol5): bool
    {
        if (($m['rol5_gizle'] ?? false) && $rol5) {
            return false;
        }
        if (($m['studyo_gizle'] ?? false) && (int) ($salon->studyo_modu ?? 0) === 1) {
            return false;
        }
        if (! empty($m['ozellik']) && ! $this->ozellikVar($m['ozellik'], $salon)) {
            return false;
        }
        if (! empty($m['yetki'])) {
            foreach ((array) $m['yetki'] as $anahtar) {
                if ($this->yetki->menuYetki($yetkiliId, $salonId, $anahtar)) {
                    return true; // OR-gate
                }
            }

            return false;
        }

        return true;
    }

    private function ozellikVar(string $ozellik, $salon): bool
    {
        return match ($ozellik) {
            'santral_aktif'       => (int) ($salon->santral_aktif ?? 0) === 1,
            'grup_dersi_aktif'    => (int) ($salon->grup_dersi_aktif ?? 0) === 1,
            'uyelik_turu_premium' => (int) ($salon->uyelik_turu ?? 0) > 2,
            default               => true,
        };
    }

    private function etiketCoz(array $m, $salon): string
    {
        // Müşteriler → Danışanlar (klinik/stüdyo salon türleri — eski menüyle birebir).
        if ($m['slug'] === 'musteriler' && in_array((int) ($salon->salon_turu_id ?? 0), [15, 28, 29], true)) {
            return 'Danışanlar';
        }

        return $m['etiket'];
    }

    private function menuUrl(array $m, int $salonId, string $tabanUrl): string
    {
        $yol = $m['slug'] === '' ? '/isletmeyonetim' : '/isletmeyonetim/'.$m['slug'];
        $sorgu = [];
        if (! empty($m['ek_sorgu'])) {
            parse_str($m['ek_sorgu'], $sorgu);
        }
        $sorgu['sube'] = $salonId;

        return $tabanUrl.$yol.'?'.http_build_query($sorgu);
    }

    private function lisansGun($salon): ?int
    {
        $bitis = $salon->uyelik_bitis_tarihi ?? null;
        if (! $bitis) {
            return null;
        }
        $to = strtotime($bitis.' 23:59:59');
        if (! $to) {
            return null;
        }

        return (int) round(($to - time()) / 86400);
    }

    /** Bu kullanıcının bu salondaki rolleri (rol 5 = personel tespiti için). */
    private function salonRolleri(int $yetkiliId, int $salonId): array
    {
        return DB::table('model_has_roles')
            ->where('model_id', $yetkiliId)
            ->where('salon_id', $salonId)
            ->pluck('role_id')
            ->map(fn ($r) => (int) $r)
            ->all();
    }

    private function salonGetir(int $salonId)
    {
        return DB::table('salonlar')->where('id', $salonId)->first([
            'id', 'salon_adi', 'uyelik_bitis_tarihi', 'uyelik_turu',
            'santral_aktif', 'grup_dersi_aktif', 'studyo_modu', 'salon_turu_id',
        ]);
    }

    /** Göreli yol → mutlak (eski panel asset'leri). */
    private function mutlak(string $yol, string $tabanUrl): string
    {
        if (preg_match('#^https?://#i', $yol)) {
            return $yol;
        }

        return $tabanUrl.'/'.ltrim($yol, '/');
    }
}
