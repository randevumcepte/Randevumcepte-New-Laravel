<?php

namespace App\Services\Panel;

use App\Services\Yetki\KapsamServisi;
use App\Support\EskiPanelLinki;
use App\Support\PanelKisayollar;
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
 * Linkler YALNIZCA aktif v2 sayfalarına gider; eski panele yönlendirme yoktur.
 */
class PanelKabukServisi
{
    public function __construct(
        private MenuGorunurluk $gorunurluk,
        private KapsamServisi $kapsam,
    ) {}

    public function kabuk(int $yetkiliId, int $salonId): array
    {
        $tabanUrl = rtrim(config('panel.eski_panel_url', 'https://app.randevumcepte.com.tr'), '/');
        $salon = $this->salonGetir($salonId) ?? (object) [];
        $roller = $this->kapsam->salonRolleri($yetkiliId, $salonId);
        $rol5 = in_array(5, $roller, true);

        return [
            'taban_url' => $tabanUrl,
            'kullanici' => $this->kullanici($yetkiliId, $tabanUrl),
            'salon'     => [
                'id'          => $salonId,
                'ad'          => trim((string) ($salon->salon_adi ?? '')),
                'lisans_gun'  => self::lisansGun($salon),
                'uyelik_turu' => (int) ($salon->uyelik_turu ?? 0),
                'musteri_etiket' => PanelKisayollar::musteriEtiketi($salon),
            ],
            'subeler' => $this->subeler($yetkiliId, $salonId, $tabanUrl),
            'menu'    => $this->menu($yetkiliId, $salonId, $salon, $rol5, $tabanUrl),
            'hizli_ekle'     => (self::lisansGun($salon) ?? 0) < 0 ? []   // eski: lisans bittiyse + menüsü yok
                : $this->kisayollar(PanelKisayollar::hizliEkle($salon), $yetkiliId, $salonId, $salon, $rol5),
            'kullanici_menu' => $this->kisayollar(PanelKisayollar::kullanici(), $yetkiliId, $salonId, $salon, $rol5),
        ];
    }

    /** Giriş yapmış işletme yetkilisinin görünen adı + avatarı (minimum). */
    private function kullanici(int $yetkiliId, string $tabanUrl): array
    {
        $y = DB::table('isletmeyetkilileri')->where('id', $yetkiliId)->first(['name', 'profil_resim']);
        $avatar = $y ? EskiPanelLinki::mutlak($y->profil_resim) : null;

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
            // Şube değişimi aynı v2 sayfasında ?sube= ile yapılır (kabuk JS kurar); eski panele link YOK.
            'url'   => null,
            'aktif' => (int) $s->id === $aktifSalonId,
        ])->all();
    }

    /** Menüyü yetki/özellik/rol'e göre filtreleyip mutlak linklerle döndür. */
    private function menu(int $yetkiliId, int $salonId, $salon, bool $rol5, string $tabanUrl): array
    {
        $cikti = [];
        foreach (PanelMenu::tanim() as $m) {
            if (! $this->gorunurluk->goster($m, $yetkiliId, $salonId, $salon, $rol5)) {
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

    /**
     * Header kısayolları: görünürlük süzülür, istemciye yalnız kod/etiket/ikon/url gider.
     * url=null → istemci `kod`a göre formu açar.
     *
     * @param  list<array<string,mixed>>  $ogeler
     */
    private function kisayollar(array $ogeler, int $yetkiliId, int $salonId, object $salon, bool $rol5): array
    {
        $gorunen = array_filter($ogeler, fn ($o) => $this->gorunurluk->goster($o, $yetkiliId, $salonId, $salon, $rol5));

        return array_values(array_map(fn ($o) => [
            'kod'    => $o['kod'],
            'etiket' => $o['etiket'],
            'ikon'   => $o['ikon'],
            'url'    => $o['url'] ?? PanelMenu::url($o, $salonId),
        ], $gorunen));
    }

    private function etiketCoz(array $m, $salon): string
    {
        // Müşteriler → Danışanlar (klinik/stüdyo salon türleri — eski menüyle birebir).
        if ($m['slug'] === 'musteriler' && PanelKisayollar::musteriEtiketi($salon) === 'Danışan') {
            return 'Danışanlar';
        }

        return $m['etiket'];
    }

    /**
     * Menü linki YALNIZCA aktif v2 sayfasına gider (aynı host, göreli). v2 karşılığı
     * olmayan öğe null döner → kabuk onu tıklanamaz "yakında" olarak çizer.
     * Eski panele (app.*) yönlendirme YAPILMAZ.
     */
    private function menuUrl(array $m, int $salonId, string $tabanUrl): ?string
    {
        return PanelMenu::url($m, $salonId);
    }

    /** Lisans bitisine kalan gun (kabuk basligi + Ozet) — tek kural. */
    public static function lisansGun($salon): ?int
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

    private function salonGetir(int $salonId)
    {
        return DB::table('salonlar')->where('id', $salonId)->first([
            'id', 'salon_adi', 'uyelik_bitis_tarihi', 'uyelik_turu',
            'santral_aktif', 'grup_dersi_aktif', 'studyo_modu', 'salon_turu_id',
        ]);
    }
}
