<?php

namespace App\Services\Hesabim;

use App\Domain\Hesabim\UyelikKatalogu;
use App\Exceptions\BulunamadiException;
use App\Services\Panel\PanelKabukServisi;
use App\Services\Profil\ProfilServisi;
use App\Support\EskiPanelLinki;
use Illuminate\Support\Facades\DB;

/**
 * Hesabım ekranı verisi. Anahtarlar Flutter HesabimVeri.fromJson ile aynı (isletme, kullanici,
 * hizmetler, faturalar, sms_siparisleri); web'in ek alanları (sekmeler, sms_paketleri, uyelik_url)
 * eski istemciyi etkilemez. Satın alma linkleri yalnız web içindir.
 */
class HesabimServisi
{
    private const SEKMELER = [
        ['kod' => 'uyelik', 'ad' => 'Üyelik Bilgileri', 'ikon' => 'bi-person-vcard'],
        ['kod' => 'hizmetler', 'ad' => 'Aldığım Hizmetler', 'ikon' => 'bi-grid'],
        ['kod' => 'duyuru', 'ad' => 'Duyuru Paketleri', 'ikon' => 'bi-megaphone'],
        ['kod' => 'fatura-bilgi', 'ad' => 'Fatura Bilgileri', 'ikon' => 'bi-file-earmark-text'],
        ['kod' => 'faturalar', 'ad' => 'Faturalarım', 'ikon' => 'bi-receipt'],
    ];

    public function __construct(
        private ProfilServisi $profil,
        private HesabimHizmetleri $hizmetler,
        private OdemeGecmisi $odemeler,
    ) {}

    public function getir(int $yetkiliId, int $salonId): array
    {
        $salon = DB::table('salonlar')->where('id', $salonId)->first();
        if (! $salon) {
            throw new BulunamadiException('İşletme bulunamadı');
        }

        return [
            'sekmeler'        => self::SEKMELER,
            'isletme'         => $this->isletme($salon),
            'kullanici'       => $this->profil->getir($yetkiliId),
            'hizmetler'       => $this->hizmetler->listele($salon),
            'faturalar'       => $this->odemeler->uyelikOdemeleri($salonId),
            'sms_siparisleri' => $this->odemeler->smsSiparisleri($salonId),
            'sms_paketleri'   => $this->odemeler->smsPaketleri($salonId),
            'uyelik_url'      => EskiPanelLinki::url('isletmeyonetim/uyelik', ['sube' => $salonId]),
        ];
    }

    private function isletme(object $salon): array
    {
        return [
            'id'                  => (int) $salon->id,
            'salon_adi'           => (string) $salon->salon_adi,
            'uyelik_turu'         => (int) $salon->uyelik_turu,
            'uyelik_turu_adi'     => UyelikKatalogu::paketAdi($salon->uyelik_turu) ?? '-',
            'uyelik_periyodu'     => (int) $salon->uyelik_periyodu,
            'uyelik_periyodu_adi' => UyelikKatalogu::periyotAdi($salon->uyelik_periyodu) ?? '-',
            'uyelik_bitis_tarihi' => $salon->uyelik_bitis_tarihi,
            'kayit_tarihi'        => $salon->created_at ? substr((string) $salon->created_at, 0, 10) : null,
            'kalan_gun'           => PanelKabukServisi::lisansGun($salon),   // header rozetiyle aynı kural
            'vergi_adi'           => $salon->vergi_adi,
            'vergi_no'            => $salon->vergi_no,
            'vergi_adresi'        => $salon->vergi_adresi,
            'kdv_orani'           => $salon->kdv_orani,
        ];
    }
}
