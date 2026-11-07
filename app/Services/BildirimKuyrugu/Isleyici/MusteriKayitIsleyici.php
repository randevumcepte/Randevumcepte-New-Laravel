<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Services\BildirimKuyrugu\Kanal\SmsKanali;
use App\Support\CinsiyetTahmin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Yeni musteri: hatirlatma feed onbellegi temizlenir, cinsiyet bossa addan tahmin edilir, yeni hesapta sifre uretilip kayit
 * mesajiyla gonderilir; eski V2BildirimIsle::musteriBildirimi + MusteriBildirimServisi::kayitBildirimi. Mesaj SMS ayari
 * ayar_id=4 (musteri) aciksa gider; sifre ayar kapaliyken de yazilir. Portfoye yeniden eklemede mesaj gitmez.
 *
 * Eskiden gelen ve KORUNAN hata: yeniden denemede sifre yeniden uretilir.
 */
class MusteriKayitIsleyici extends MusteriTabanliIsleyici
{
    public function __construct(private SmsKanali $sms) {}

    protected function musteriIsle(object $kayit, array $payload, object $musteri): void
    {
        $salonId = (int) $kayit->salon_id;

        Cache::forget('salon_hatirlatma.'.$salonId);

        if (! empty($payload['cinsiyet_tahmin']) && $musteri->cinsiyet === null) {
            $tahmin = CinsiyetTahmin::tahmin($musteri->name);
            if ($tahmin !== null) {
                DB::table('users')->where('id', $musteri->id)->update(['cinsiyet' => $tahmin]);
            }
        }

        if (! empty($payload['yeni_hesap'])) {
            $sifre = substr(str_shuffle('abcdefghjklmnopqrstuvwxyzABCDEFGHJKLMNOPQRSTUVWXYZ1234567890'), 0, 5);
            DB::table('users')->where('id', $musteri->id)->update(['password' => Hash::make($sifre)]);
            $this->kayitBildirimi($salonId, $musteri, $sifre);
        }
    }

    private function kayitBildirimi(int $salonId, object $musteri, string $sifre): void
    {
        if (! DB::table('salon_sms_ayarlari')->where('salon_id', $salonId)->where('ayar_id', 4)->value('musteri')) {
            return;
        }
        $salon = DB::table('salonlar')->where('id', $salonId)->first(['salon_adi', 'uygulamalar_kisa_link']);
        if (! $salon || ! $musteri->cep_telefon) {
            return;
        }

        $mesaj = 'Sayın '.$musteri->name.'. '.$salon->salon_adi.' tarafından müşteri kaydınız oluşturulmuştur.';
        if ($sifre) {
            $mesaj .= '. Sistem kullanıcı adınız:'.$musteri->cep_telefon.' Şifreniz:'.$sifre;
        }
        if ($salon->uygulamalar_kisa_link) {
            $mesaj .= ' Uygulamamızı indirmek için linke tıklayın. '.$salon->uygulamalar_kisa_link;
        }

        $this->sms->panelBildirimli([['to' => $musteri->cep_telefon, 'message' => $mesaj]], false, 1, false, $salonId);
    }
}
