<?php

namespace App\Services\BildirimKuyrugu\Kanal;

use Illuminate\Support\Facades\DB;

/**
 * WhatsApp icin zenginlestirilmis (emoji + markdown + konum/tel) mesaj sablonlari.
 * Ayni format panel (StoreAdminController), mobile (ApiController) ve cron
 * (RandevuSMSHatirlatma) tarafindan kullanilir; yer yer duplike etmek yerine
 * bu tek noktadan geliyor.
 *
 * SMS'e giden kisa metin ayni degildir — cagri yerinde ayrica olusturulur;
 * bu class SADECE WA icin uzun/formatli metni uretir.
 */
class WhatsappMesajFormat
{
    /**
     * Müşteri WA mesajının altına "Uygulamamızı İndirin" daveti + link ekler.
     * SADECE müşterinin AKTİF push token'ı YOKSA (=uygulaması yok) eklenir.
     * Link: salonun uygulamalar_kisa_link'i, yoksa cihaz-duyarlı /indir/{salon}.
     * Uygulaması olana / hata / eksik veri durumunda mesaj AYNEN döner.
     * Tüm müşteri randevu mesajlarında (oluşturma/hatırlatma/güncelleme/iptal)
     * tek noktadan kullanılsın diye burada.
     */
    public static function uygulamaDavetiEk($mesaj, $salon, $userId)
    {
        try {
            if (!$salon || !$userId) return $mesaj;
            $appVar = PushBildirimi::markaSuz(DB::table('bildirim_kimlikleri')->where('user_id', $userId)
                ->where('aktif', 1), $salon->id)->exists();
            if ($appVar) return $mesaj; // uygulaması var -> ekleme yok
            $link = trim((string) ($salon->uygulamalar_kisa_link ?? ''));
            // Eski kod url(): eski uygulamanin adresi. Yeni appin APP_URL'i farkli olabilir.
            if ($link === '') $link = rtrim((string) config('panel.eski_panel_url'), '/') . '/indir/' . $salon->id;
            if ($link === '') return $mesaj;
            return $mesaj . "\n\n📲 *Uygulamamızı İndirin!*\n"
                . "Randevularınızı takip edin, otomatik hatırlatma alın ve size özel "
                . "fırsatlardan ilk siz haberdar olun 👉 " . $link;
        } catch (\Throwable $e) {
            return $mesaj;
        }
    }

    /**
     * Randevu olusturuldu bildirimi (WA).
     *
     * @param \App\Salonlar|object $salon salon_adi + konum_linki + telefon_1 alanlari kullanilir
     * @param string $musteriAdi
     * @param string $tarihStr d.m.Y formatinda
     * @param string $saatStr H:i formatinda
     * @param string $hizmetlerStr virgullu hizmet listesi ("solaryum, cilt bakimi")
     */
    /** Klinik turu isletme mi? (salon_turu_id 15/28/29 -> dermatoloji/estetik/tip merkezi) */
    public static function klinikMi($salon)
    {
        return \App\Models\Salonlar::danisanMi($salon->salon_turu_id ?? 0);
    }

    /** WA mesajlarindaki hizmet ikonu: klinikte sirinca, digerinde makas. */
    public static function hizmetIkon($salon)
    {
        return self::klinikMi($salon) ? '💉' : '✂️';
    }

    public static function randevuOlusturuldu($salon, $musteriAdi, $tarihStr, $saatStr, $hizmetlerStr = '')
    {
        $klinikMi = self::klinikMi($salon);
        $hizmetIkon = self::hizmetIkon($salon);

        $mesaj  = "🌸 Merhaba, *" . $musteriAdi . "*,\n\n";
        $mesaj .= ($salon->salon_adi ?? '') . " için randevunuz başarıyla oluşturulmuştur. ✨\n\n";
        $mesaj .= "📅 *Tarih:* " . $tarihStr . "\n";
        $mesaj .= "🕒 *Saat:* " . $saatStr . "\n";
        if (!empty($hizmetlerStr)) {
            $mesaj .= $hizmetIkon . " *Hizmetler:* " . $hizmetlerStr . "\n";
        }
        $mesaj .= "\nRandevunuzun sorunsuz geçmesi için lütfen belirtilen saatten birkaç dakika önce hazır olunuz. 💐\n\n";
        if (!empty($salon->konum_linki)) {
            $mesaj .= "📍 *Konum:* " . $salon->konum_linki . "\n";
        }
        if (!empty($salon->telefon_1)) {
            $tel = ltrim((string) $salon->telefon_1);
            if ($tel !== '' && ctype_digit($tel[0]) && $tel[0] !== '0') {
                $tel = '0' . $tel;
            }
            $mesaj .= "📞 *İletişim:* " . $tel . "\n";
        }
        $mesaj .= "\nRandevunuzu değiştirmek ya da iptal etmek isterseniz bu WhatsApp hattından ya da telefon numaramızdan bize ulaşabilirsiniz.\n\n";
        $mesaj .= "Sizi ağırlamayı sabırsızlıkla bekliyoruz. 💖";
        return $mesaj;
    }

    /**
     * Sifre teslim mesaji — OTP formatindan uzak, dogal cumle + rastgele varyasyon.
     * Meta 463 (spam) riskini dusurmek icin: kisa "X sifreniz: 123" yerine
     * konusma tonlu, kisisel bir metin gonderilir. Her cagride farkli sablon.
     */
    public static function sifreMesaji($isletmeAdi, $musteriAdi, $sifre)
    {
        $ad = trim((string) $musteriAdi);
        $selamlama = $ad !== '' ? "Merhaba " . $ad . "," : "Merhaba,";
        $isletme = trim((string) $isletmeAdi) ?: 'İşletmemiz';

        $sablonlar = [
            $selamlama . "\n\n" . $isletme . " için uygulamamıza hoş geldiniz. 🌸\nGiriş yaparken şu şifreyi kullanabilirsiniz: *" . $sifre . "*\n\nGüvenliğiniz için ilk girişten sonra şifrenizi değiştirmenizi öneririz. İyi günler dileriz. 💐",

            $selamlama . "\n\n" . $isletme . " hesabınız oluşturuldu. Uygulamaya girerken şu geçici şifreyi kullanabilirsiniz: *" . $sifre . "*\n\nDilerseniz uygulama içinden yeni bir şifre belirleyebilirsiniz. ✨",

            $selamlama . "\n\n" . $isletme . " uygulamasına giriş için oluşturulan şifreniz: *" . $sifre . "*\n\nHesabınızı kişiselleştirmek için uygulamayı ziyaret edebilirsiniz. Görüşmek üzere. 🌷",

            $selamlama . "\n\n" . $isletme . " ailesine hoş geldiniz. 🌟\nUygulamamıza giriş yaparken şu şifreyi kullanabilirsiniz: *" . $sifre . "*\n\nSorularınız için bize her zaman ulaşabilirsiniz.",
        ];

        return $sablonlar[array_rand($sablonlar)];
    }

    /**
     * Randevu TALEBI ALINDI bildirimi (WA) — musterinin uygulama/web'den olusturdugu
     * talep henuz onaylanmamis. Salon onayladiginda ayrica randevuOnaylandi mesaji gider.
     */
    public static function randevuTalebiAlindi($salon, $musteriAdi, $tarihStr, $saatStr, $hizmetlerStr = '')
    {
        $hizmetIkon = self::hizmetIkon($salon);
        $isyeriKucuk = self::klinikMi($salon) ? 'klinik' : 'salon';

        $mesaj  = "🌸 Merhaba, *" . $musteriAdi . "*,\n\n";
        $mesaj .= ($salon->salon_adi ?? '') . " için *randevu talebiniz alınmıştır*. ⏳\n";
        $mesaj .= "Talebiniz " . $isyeriKucuk . " tarafından onaylandığında ayrıca bilgilendirileceksiniz.\n\n";
        $mesaj .= "📅 *Tarih:* " . $tarihStr . "\n";
        $mesaj .= "🕒 *Saat:* " . $saatStr . "\n";
        if (!empty($hizmetlerStr)) {
            $mesaj .= $hizmetIkon . " *Hizmetler:* " . $hizmetlerStr . "\n";
        }
        if (!empty($salon->telefon_1)) {
            $tel = ltrim((string) $salon->telefon_1);
            if ($tel !== '' && ctype_digit($tel[0]) && $tel[0] !== '0') {
                $tel = '0' . $tel;
            }
            $mesaj .= "📞 *İletişim:* " . $tel . "\n";
        }
        $mesaj .= "\nİlginiz için teşekkür ederiz. 💐";
        return $mesaj;
    }

    /**
     * Randevu talebi ONAYLANDI bildirimi (WA).
     * Musterinin uygulama/web'den olusturdugu talep salon tarafindan onaylanmis;
     * detay + bilgilendirme (randevuOlusturuldu ile ayni gorunum, farkli baslik).
     */
    public static function randevuOnaylandi($salon, $musteriAdi, $tarihStr, $saatStr, $hizmetlerStr = '')
    {
        $hizmetIkon = self::hizmetIkon($salon);

        $mesaj  = "🌸 Merhaba, *" . $musteriAdi . "*,\n\n";
        $mesaj .= ($salon->salon_adi ?? '') . " için randevu talebiniz *onaylanmıştır*. ✅\n\n";
        $mesaj .= "📅 *Tarih:* " . $tarihStr . "\n";
        $mesaj .= "🕒 *Saat:* " . $saatStr . "\n";
        if (!empty($hizmetlerStr)) {
            $mesaj .= $hizmetIkon . " *Hizmetler:* " . $hizmetlerStr . "\n";
        }
        $mesaj .= "\nRandevunuzun sorunsuz geçmesi için lütfen belirtilen saatten birkaç dakika önce hazır olunuz. 💐\n\n";
        if (!empty($salon->konum_linki)) {
            $mesaj .= "📍 *Konum:* " . $salon->konum_linki . "\n";
        }
        if (!empty($salon->telefon_1)) {
            $tel = ltrim((string) $salon->telefon_1);
            if ($tel !== '' && ctype_digit($tel[0]) && $tel[0] !== '0') {
                $tel = '0' . $tel;
            }
            $mesaj .= "📞 *İletişim:* " . $tel . "\n";
        }
        $mesaj .= "\nRandevunuzu değiştirmek ya da iptal etmek isterseniz bu WhatsApp hattından ya da telefon numaramızdan bize ulaşabilirsiniz.\n\n";
        $mesaj .= "Sizi ağırlamayı sabırsızlıkla bekliyoruz. 💖";
        return $mesaj;
    }
}
