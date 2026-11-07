<?php

namespace App\Services\BildirimKuyrugu;

use App\Services\BildirimKuyrugu\Kanal\SmsKanali;
use App\Services\BildirimKuyrugu\Kanal\WhatsappKanali;
use App\Support\EskiPanelLinki;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Anket linki gonderimi; eski StoreAdminController::anketSmsGonder ile birebir: salon WhatsApp'i bagliysa once WhatsApp,
 * olmazsa Controller::sms_gonder yolu. Gonderim hatasi yutulur, kayit tamamlanmis sayilir (eskisi gibi).
 *
 * Host: eskide HTTP_HOST, yoksa salonlar.domain, o da NULL ise panel host'u. Isleyici konsolda calistigindan HTTP_HOST yoktur.
 * Anket sayfasi /anket/{token} eski monolittedir; yeni app'in adresi (app.url) kullanilmaz.
 * Eski kirik link duzeltilir: bos domain panel host'una duser, sema ve sondaki bolu temizlenir; duzgun domain'de link aynidir.
 */
class AnketLinkGonderici
{
    public function __construct(private WhatsappKanali $whatsapp, private SmsKanali $sms) {}

    /**
     * @param  object  $gonderim  anket_gonderimleri satiri
     * @param  object  $sablon  anket_sablonlari satiri
     * @param  object  $musteri  {id, name}; kayitsiz musteride id null
     */
    public function gonder(object $gonderim, object $sablon, object $musteri): void
    {
        $salon = $this->whatsapp->salon($gonderim->salon_id);
        $mesaj = $this->mesaj($gonderim, $sablon, $musteri, $salon);

        $oturumSalonu = $salon ? $this->whatsapp->oturumSalonu($salon) : null;
        if ($oturumSalonu && $this->whatsapp->bagliMi($oturumSalonu) && $this->whatsappIle($salon, $gonderim, $musteri, $mesaj)) {
            return;
        }

        // Eskideki gibi yalniz \Exception yakalanir.
        try {
            $this->sms->gonder($gonderim->salon_id, [['to' => $gonderim->telefon, 'message' => $mesaj]]);
        } catch (\Exception $e) {
            Log::error('anketSmsGonder hata: '.$e->getMessage());
        }
    }

    private function whatsappIle(object $salon, object $gonderim, object $musteri, string $mesaj): bool
    {
        try {
            $sonuc = $this->whatsapp->gonder($salon, $gonderim->telefon, $mesaj, $gonderim->randevu_id ?? null, $musteri->id ?? null, null, false, 'anket');
            if (! empty($sonuc['ok'])) {
                try {
                    DB::table('anket_gonderimleri')->where('id', $gonderim->id)->update(['gonderim_kanali' => 'whatsapp', 'updated_at' => now()]);
                } catch (\Throwable) {
                    // yoksay
                }
                Log::info('[ANKET-WA] gonderildi', [
                    'salon_id'    => $salon->id,
                    'gonderim_id' => $gonderim->id,
                    'to'          => $gonderim->telefon,
                    'provider'    => $sonuc['provider'] ?? null,
                    'logId'       => $sonuc['logId'] ?? null,
                ]);

                return true;
            }
            Log::warning('[ANKET-WA] basarisiz → SMS fallback', [
                'salon_id'    => $salon->id,
                'gonderim_id' => $gonderim->id,
                'err'         => $sonuc['error'] ?? 'unknown',
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ANKET-WA] istisna → SMS fallback: '.$e->getMessage(), ['salon_id' => $salon->id, 'gonderim_id' => $gonderim->id]);
        }

        return false;
    }

    private function mesaj(object $gonderim, object $sablon, object $musteri, ?object $salon): string
    {
        $domain = rtrim((string) preg_replace('#^https?://#i', '', trim((string) ($salon->domain ?? ''))), '/');
        $host = $domain !== '' ? $domain : EskiPanelLinki::host();
        $link = 'https://'.$host.'/anket/'.$gonderim->token;
        // Yalniz ilk ad: soyad SMS karakter tasarrufu icin atlanir.
        $adSoyad = $musteri->name ?? '';
        $ilkAd = $adSoyad ? trim(explode(' ', $adSoyad)[0]) : '';
        $salonAd = $salon->salon_adi ?? '';
        $odulIfade = $this->odulIfadesi($sablon);

        if ($odulIfade) {
            return 'Merhaba Sn. '.$ilkAd." 🎁\n\n"
                .$salonAd.' deneyiminizi değerlendirin, size özel '.$odulIfade." hediyemiz olsun!\n\n"
                ."Anketi doldurmak (30 sn):\n".$link."\n\n"
                .'Görüşleriniz bizim için çok değerli, teşekkür ederiz.';
        }

        return 'Merhaba Sn. '.$ilkAd.",\n\n"
            .$salonAd."'da yaşadığınız deneyim bizim için çok değerli. Hizmet kalitemizi geliştirebilmemiz adına görüşlerinizi birkaç dakikanızı ayırarak bizimle paylaşabilir misiniz?\n\n"
            ."Anketi doldurmak için:\n".$link."\n\n"
            .'Değerli geri bildiriminiz için şimdiden teşekkür eder, sizi yeniden ağırlamaktan mutluluk duyarız.';
    }

    private function odulIfadesi(object $sablon): ?string
    {
        $tip = $sablon->odul_tipi ?? 'yok';
        if ($tip === 'kupon' && (float) $sablon->odul_kupon_deger > 0) {
            $deger = (float) $sablon->odul_kupon_deger;

            return $sablon->odul_kupon_indirim_tipi === 'tutar'
                ? rtrim(rtrim(number_format($deger, 2, ',', '.'), '0'), ',').' ₺ indirim kuponu'
                : intval($deger).'% indirim kuponu';
        }
        if ($tip === 'puan' && (float) $sablon->odul_puan > 0) {
            return rtrim(rtrim(number_format((float) $sablon->odul_puan, 2, ',', '.'), '0'), ',').' puan';
        }

        return null;
    }
}
