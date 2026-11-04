<?php

namespace App\Services\Sms;

use App\Domain\Sms\MesajUzunlugu;
use App\Domain\Sms\SmsSaglayici;
use App\Domain\Sms\SmsSekmeleri;
use App\Domain\Sms\SmsTuru;
use App\Services\Sms\Bakiye\SmsBakiyeServisi;

/**
 * SMS Yönetimi AÇILIŞ verisi — tek istekte sekmeler, yetkiler, sözlükler, bakiye, şablonlar ve (hesap
 * sahibine) ayarlar. Rapor çekmez: eski ekran raporları yüklerken beyaz sayfa veriyordu; raporlar
 * kendi ucundan sekme açılınca gelir.
 */
class SmsAcilisServisi
{
    public function __construct(
        private SmsHesapServisi $hesap,
        private SmsBakiyeServisi $bakiye,
        private SmsSablonServisi $sablon,
        private SmsAyarServisi $ayar,
    ) {}

    /** @param array{gonder:bool, ayar:bool, telefon_gor:bool, yazabilir:bool} $yetkiler */
    public function veri(int $salonId, array $yetkiler): array
    {
        $salon = $this->hesap->zorunlu($salonId);

        return [
            'sekmeler'         => SmsSekmeleri::gorunur($yetkiler),
            'yetkiler'         => $yetkiler,
            'kalan_sms'        => $this->bakiye->kalan($salonId),
            'sms_baslik'       => (string) ($salon->sms_baslik ?? ''),
            'yeni_sms'         => SmsSaglayici::salondan($salon) === SmsSaglayici::VoiceTelekom,
            'kara_liste_aktif' => ! isset($salon->kara_liste_aktif) || (int) $salon->kara_liste_aktif === 1,
            'rapor_turleri'    => SmsTuru::raporSekmeleri(),
            'parca_esikleri'   => MesajUzunlugu::ESIKLER,
            'yasal_uyari'      => SmsSekmeleri::YASAL_UYARI,
            'taslaklar'        => $this->sablon->liste($salonId),
            'ayar'             => $yetkiler['ayar'] ? $this->ayar->oku($salonId) : null,
        ];
    }
}
