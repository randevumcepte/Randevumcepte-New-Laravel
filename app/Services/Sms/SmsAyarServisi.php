<?php

namespace App\Services\Sms;

use App\Domain\Sms\SmsAyarKatalogu;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * SMS BİLDİRİM AYARLARI — salon_sms_ayarlari + salonlar.randevu_sms_hatirlatma.
 * Kart tanımları ve yazılacak kolon kuralı Domain\Sms\SmsAyarKatalogu'nda.
 */
class SmsAyarServisi
{
    public function __construct(private SmsHesapServisi $hesap) {}

    public function oku(int $salonId): array
    {
        $degerler = [];
        foreach (DB::table('salon_sms_ayarlari')->where('salon_id', $salonId)->get(['ayar_id', 'musteri', 'personel']) as $a) {
            $degerler[(int) $a->ayar_id] = ['musteri' => (bool) $a->musteri, 'personel' => (bool) $a->personel];
        }

        return [
            'ayarlar'                => SmsAyarKatalogu::kartlar($degerler),
            'randevu_sms_hatirlatma' => (int) ($this->hesap->zorunlu($salonId)->randevu_sms_hatirlatma ?? 0),
            'saat_secimli_ayar'      => SmsAyarKatalogu::SAAT_SECIMLI,
            'saat_araligi'           => ['min' => SmsAyarKatalogu::SAAT_MIN, 'maks' => SmsAyarKatalogu::SAAT_MAKS],
        ];
    }

    /**
     * @param  array<int, array>  $ayarlar  [{ayar_id, musteri?, personel?}]; listede olmayan ayar değişmez
     * @param  ?int  $hatirlatmaSaat  null ise değişmez
     */
    public function kaydet(int $yetkiliId, int $salonId, array $ayarlar, ?int $hatirlatmaSaat): array
    {
        $yazilacak = [];
        foreach ($ayarlar as $satir) {
            $yazilacak[(int) ($satir['ayar_id'] ?? 0)] = SmsAyarKatalogu::kolonlar($satir); // geçersiz ayar → hiç yazmadan 422
        }

        DB::transaction(function () use ($salonId, $yazilacak, $hatirlatmaSaat) {
            foreach ($yazilacak as $ayarId => $kolonlar) {
                if ($kolonlar) {
                    DB::table('salon_sms_ayarlari')->updateOrInsert(['salon_id' => $salonId, 'ayar_id' => $ayarId], $kolonlar);
                }
            }
            if ($hatirlatmaSaat !== null) {
                DB::table('salonlar')->where('id', $salonId)->update(['randevu_sms_hatirlatma' => $hatirlatmaSaat]);
            }
        });
        AktiviteLog::yaz($salonId, $yetkiliId, 'sms_ayar_kaydet', 'sms_ayar', null, 'SMS / WhatsApp ayarları',
            'SMS ve WhatsApp bildirim ayarları güncellendi', ['hatirlatma_saat' => $hatirlatmaSaat]);

        return $this->oku($salonId);
    }
}
