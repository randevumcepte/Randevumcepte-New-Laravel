<?php

namespace App\Services\Whatsapp;

use App\Domain\Whatsapp\IsletmeLinkleri;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Salon WhatsApp ayarları: hatırlatma kanalı (salon_sms_ayarlari.whatsapp_musteri) + mesaja
 * eklenen işletme bağlantıları (salonlar). Eski whatsappKanal* + whatsappKonumKaydet karşılığı.
 */
class WhatsappAyarServisi
{
    /** Kanal anahtarının yönettiği ayarlar: 1 = aynı gün hatırlatma, 6 = 1 gün önce. */
    private const AYAR_AYNI_GUN = 1;

    private const AYAR_24_SAAT = 6;

    /** @return array{aktif:bool, sms_aktif_aynigun:bool, sms_aktif_24sa:bool} */
    public function kanal(int $salonId): array
    {
        $ayarlar = DB::table('salon_sms_ayarlari')->where('salon_id', $salonId)
            ->whereIn('ayar_id', [self::AYAR_AYNI_GUN, self::AYAR_24_SAAT])
            ->get(['ayar_id', 'musteri', 'whatsapp_musteri'])->keyBy('ayar_id');
        $aynigun = $ayarlar->get(self::AYAR_AYNI_GUN);
        $saat24 = $ayarlar->get(self::AYAR_24_SAAT);

        return [
            'aktif'             => (int) ($aynigun->whatsapp_musteri ?? 0) === 1 || (int) ($saat24->whatsapp_musteri ?? 0) === 1,
            'sms_aktif_aynigun' => (int) ($aynigun->musteri ?? 0) === 1,
            'sms_aktif_24sa'    => (int) ($saat24->musteri ?? 0) === 1,
        ];
    }

    public function kanalDegistir(int $salonId, int $yetkiliId, bool $aktif): array
    {
        $deger = $aktif ? 1 : 0;
        DB::transaction(function () use ($salonId, $deger) {
            foreach ([self::AYAR_AYNI_GUN, self::AYAR_24_SAAT] as $ayarId) {
                $anahtar = ['salon_id' => $salonId, 'ayar_id' => $ayarId];
                DB::table('salon_sms_ayarlari')->where($anahtar)->exists()
                    ? DB::table('salon_sms_ayarlari')->where($anahtar)->update(['whatsapp_musteri' => $deger])
                    : DB::table('salon_sms_ayarlari')->insert($anahtar + ['musteri' => 0, 'personel' => 0, 'whatsapp_musteri' => $deger]);
            }
        });
        AktiviteLog::yaz($salonId, $yetkiliId, 'whatsapp_kanal_degistir', 'whatsapp', $salonId, 'WhatsApp hatırlatma kanalı',
            'WhatsApp hatırlatma kanalı '.($aktif ? 'açıldı' : 'kapatıldı'));

        return $this->kanal($salonId);
    }

    public function linkler(int $salonId): array
    {
        return IsletmeLinkleri::sun(DB::table('salonlar')->where('id', $salonId)->first([
            ...IsletmeLinkleri::LINK_ALANLARI, ...IsletmeLinkleri::BASLIK_ALANLARI,
        ]));
    }

    /** @param array<string,mixed> $girdi doğrulanmış alanlar (kısmi olabilir) */
    public function linkKaydet(int $salonId, int $yetkiliId, array $girdi): array
    {
        $guncelleme = IsletmeLinkleri::guncelleme($girdi);
        if ($guncelleme) {
            DB::table('salonlar')->where('id', $salonId)->update($guncelleme);
            AktiviteLog::yaz($salonId, $yetkiliId, 'whatsapp_link_kaydet', 'whatsapp', $salonId, 'İşletme bağlantıları',
                'WhatsApp işletme bağlantıları güncellendi', ['alanlar' => array_keys($guncelleme)]);
        }

        return $this->linkler($salonId);
    }
}
