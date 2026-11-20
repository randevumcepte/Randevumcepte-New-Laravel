<?php

namespace App\Services\BildirimKuyrugu;

use App\Domain\Anket\AnketGonderimKanali;
use App\Services\BildirimKuyrugu\Kanal\SmsKanali;
use App\Services\BildirimKuyrugu\Kanal\WhatsappKanali;
use App\Support\EskiPanelLinki;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Anket linki gonderimi; eski StoreAdminController::anketSmsGonder ile birebir. Kanal sirasi isletmeye ozel
 * (AnketGonderimKanali): varsayilan WhatsApp bagliysa once WA, olmazsa SMS; 'sms' secili salonda once SMS, gonderilemezse WA yedek.
 * Gonderim hatasi yutulur, kayit tamamlanmis sayilir (eskisi gibi).
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
        $smsMesaj = $this->smsMesaji($gonderim, $sablon, $musteri, $salon);

        $oturumSalonu = $salon ? $this->whatsapp->oturumSalonu($salon) : null;
        $waBagli = $oturumSalonu && $this->whatsapp->bagliMi($oturumSalonu);

        if (AnketGonderimKanali::smsOnceMi($salon)) {
            if (! $this->smsIle($gonderim, $smsMesaj) && $waBagli) {
                $this->whatsappIle($salon, $gonderim, $musteri, $mesaj);
            }

            return;
        }

        if ($waBagli && $this->whatsappIle($salon, $gonderim, $musteri, $mesaj)) {
            return;
        }
        $this->smsIle($gonderim, $smsMesaj);
    }

    /** Saglayici kabul ettiyse true; istisna, kara liste veya saglayici reddinde (bakiye/baslik) false. */
    private function smsIle(object $gonderim, string $mesaj): bool
    {
        try {
            $this->sms->gonder($gonderim->salon_id, [['to' => $gonderim->telefon, 'message' => $mesaj]]);
            $ok = $this->sms->sonBasarili;
            Log::info('[ANKET-SMS] '.($ok ? 'saglayici kabul etti' : 'GONDERILEMEDI'), [
                'salon_id' => $gonderim->salon_id, 'gonderim_id' => $gonderim->id, 'to' => $gonderim->telefon,
            ]);

            return $ok;
        } catch (\Throwable $e) {
            Log::error('anketSmsGonder hata: '.$e->getMessage());

            return false;
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
            Log::warning('[ANKET-WA] basarisiz', [
                'salon_id'    => $salon->id,
                'gonderim_id' => $gonderim->id,
                'err'         => $sonuc['error'] ?? 'unknown',
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ANKET-WA] istisna: '.$e->getMessage(), ['salon_id' => $salon->id, 'gonderim_id' => $gonderim->id]);
        }

        return false;
    }

    /** @return array{0:string,1:string,2:string,3:?string} [link, ilkAd, salonAd, odulIfade] */
    private function parcalar(object $gonderim, object $sablon, object $musteri, ?object $salon): array
    {
        $domain = rtrim((string) preg_replace('#^https?://#i', '', trim((string) ($salon->domain ?? ''))), '/');
        $host = $domain !== '' ? $domain : EskiPanelLinki::host();
        // Yalniz ilk ad: soyad karakter tasarrufu icin atlanir.
        $adSoyad = $musteri->name ?? '';

        return ['https://'.$host.'/anket/'.$gonderim->token, $adSoyad ? trim(explode(' ', $adSoyad)[0]) : '', $salon->salon_adi ?? '', $this->odulIfadesi($sablon)];
    }

    /**
     * SMS metni: SABIT (sablon/odul metnine bagli degil), kisa, Turkce karaktersiz (GSM 7-bit, tek parca);
     * Turkce karakter/emoji UCS-2'ye (70 kr/parca) cevirir. Eski StoreAdminController::anketSmsKisaMetin ile ayni metin.
     */
    private function smsMesaji(object $gonderim, object $sablon, object $musteri, ?object $salon): string
    {
        [$link, $ilkAd, $salonAd] = $this->parcalar($gonderim, $sablon, $musteri, $salon);
        $metin = 'Sn. '.$ilkAd.', '.$salonAd.' deneyiminizi degerlendirir misiniz? '.$link;
        $metin = strtr($metin, ['ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I', 'ö' => 'o', 'Ö' => 'O', 'ş' => 's', 'Ş' => 'S', 'ü' => 'u', 'Ü' => 'U', 'â' => 'a', 'Â' => 'A', 'î' => 'i', 'û' => 'u']);

        return (string) preg_replace('/\s+/', ' ', trim((string) preg_replace('/[^\x20-\x7E]/', '', $metin)));
    }

    private function mesaj(object $gonderim, object $sablon, object $musteri, ?object $salon): string
    {
        [$link, $ilkAd, $salonAd, $odulIfade] = $this->parcalar($gonderim, $sablon, $musteri, $salon);

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
