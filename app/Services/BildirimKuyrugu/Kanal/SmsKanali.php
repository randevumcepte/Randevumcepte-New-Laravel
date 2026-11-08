<?php

namespace App\Services\BildirimKuyrugu\Kanal;

use App\Domain\Bildirim\SifreMaskesi;
use App\Domain\Sms\SmsSaglayici;
use App\Domain\Sms\SmsTuru;
use App\Services\Sms\SmsHesapServisi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Eski SMS yollari. Birbirinden FARKLI davranirlar; cagri yeri eskide hangisini kullaniyorsa onu cagirir, BIRLESTIRILMEZ:
 *
 *   gonder            Controller::sms_gonder               kara liste; VoiceTelekom TEK paket; Efetech rapor tur=1 durum=0
 *   gonder2           ApiController::sms_gonder_2          kara liste; VoiceTelekom mesaj basina; Efetech rapor durum saglayicidan
 *   apiBildirimli     ApiController::sms_gonder_bildirimli YALNIZ Efetech; kara liste ve WhatsApp yok
 *   panelBildirimli   StoreAdminController::sms_gonder_bildirimli  kara liste + WA-first + yedek SMS
 *   topluVoiceTelekom SMSController::cokluSMSGonderVoiceTelekom    tek paket, baslik parametreden
 *
 * $mesajlar: [['to'=>..., 'message'=>..., 'user_id'?, 'randevu_id'?, 'wa_message'?, 'urgent'?, 'gonderim_tipi'?], ...]
 *
 * Mukerrer korumasi: saglayici mesaji kabul etmediyse (ulasilamadi, gecersiz cevap) hata firlar, outbox yeniden dener. Kabul
 * ettiyse ya da zaman asimiyla gitmis olabilirse sonraki adimlarin (rapor, kayit) hatasi yutulur: eskide burada da firlardi ve
 * ayni SMS en cok 3 kez gidiyordu. Iletim raporuna yazilan metinde sifre maskelenir (SifreMaskesi).
 */
class SmsKanali
{
    private const ILETIM_RAPORU = 'sms_iletim_raporlari';

    private const VT_MUKERRER_HATASI = 'ERR_SMS_PKG_DUPLICATION';

    public function __construct(
        private SmsHesapServisi $hesap,
        private EfetechIstemcisi $efetech,
        private VoiceTelekomIstemcisi $voiceTelekom,
        private WhatsappKanali $whatsapp,
    ) {}

    /** Eski Controller::sms_gonder. Bos $salonId: sistem SMS'i (Efetech, config sistem_sms). @return ?array Efetech cevabi */
    public function gonder(int|string|null $salonId, array $mesajlar, ?string $gonderimTipi = null): ?array
    {
        $mesajlar = KaraListeFiltresi::topluSuz($salonId, $mesajlar, $gonderimTipi);
        if (! $mesajlar) {
            Log::info('[SMS-API] sms_gonder: tumu kara listede, hic gonderim yok', ['salon_id' => $salonId]);

            return null;
        }
        Log::info('[SMS-API] sms_gonder cagrildi', [
            'salon_id' => $salonId, 'alici_sayisi' => count($mesajlar), 'mesaj_uzunluk' => strlen((string) ($mesajlar[0]['message'] ?? '')),
        ]);

        $sistem = (string) $salonId === '';
        $salon = $sistem ? null : $this->salon($salonId);
        if (! $sistem && SmsSaglayici::salondan($salon) === SmsSaglayici::VoiceTelekom) {
            // Eski hata KORUNUR: paket tek metin tasir, farkli metinli mesajlarda herkese ilk metin gider.
            $sonuc = $this->voiceTelekom->gonder($salon, 'Bildirim', (string) $mesajlar[0]['message'], array_column($mesajlar, 'to'));
            Log::info('[SMS-API] VoiceTelekom yanit', ['salon_id' => $salonId, 'pkgID' => $sonuc['pkgID'], 'err' => $sonuc['err']]);

            return null;
        }

        $sistemAyari = (array) config('bildirim_kuyrugu.sistem_sms');
        $cevap = $sistem
            ? $this->efetechGonder((string) ($sistemAyari['apikey'] ?? ''), $sistemAyari['baslik'] ?? null, $mesajlar, $salonId)
            : $this->efetechGonder((string) $salon->sms_apikey, $salon->sms_baslik, $mesajlar, $salonId);
        if ($cevap === null) {
            return null;
        }
        Log::info('[SMS-API] efetech yanit', ['salon_id' => $salonId, 'response_id' => $cevap['response']['message']['id'] ?? null]);

        if (! $sistem) {
            if ($this->efetechCevabiDolu($cevap)) {
                $this->kabulSonrasi($salonId, function () use ($cevap, $salon, $salonId, $mesajlar) {
                    $mesaj = $this->efetechMesaji($cevap);
                    sleep(1);
                    $this->efetech->rapor($salon, $mesaj['id']); // Eskide de sonucu kullanilmaz, durum 0 yazilir.
                    $this->raporYaz($salonId, 1, (string) $mesajlar[0]['message'], $mesaj, 0);
                });
            } else {
                Log::warning('[SMS-API] efetech response bos veya hatali — rapor yazilmadi', ['salon_id' => $salonId]);
            }
        }

        return $cevap;
    }

    /** Eski ApiController::sms_gonder_2. */
    public function gonder2(array $mesajlar, bool $geriBildirim, int|string $tur, bool $dogrulama, int|string $salonId, bool $sifreGonder = false): array|string|null
    {
        $mesajlar = KaraListeFiltresi::topluSuz($salonId, $mesajlar, $sifreGonder ? 'sifre_sifirlama' : null);
        if (! $mesajlar) {
            Log::info('[sms_gonder_2] tumu kara listede, hic gonderim yok', ['salon_id' => $salonId]);

            return null;
        }
        $salon = $this->salon($salonId);

        if (SmsSaglayici::salondan($salon) === SmsSaglayici::VoiceTelekom) {
            foreach ($mesajlar as $mesaj) {
                $sonuc = $this->voiceTelekom->gonder($salon, 'Bildirim', (string) $mesaj['message'], [$mesaj['to']]);
                $this->voiceTelekomLogla($sonuc);
                // Eski hata KORUNUR: geri bildirim isteniyorsa ilk mesajdan sonra doner, kalanlar gitmez.
                if ($geriBildirim) {
                    return $sonuc['err'] === null
                        ? $this->geriBildirimMetni('')
                        : ['title' => 'Hata', 'status' => 'error', 'text' => 'Mesajınız alıcılarınıza göderilemedi. Hata kodu : '.$sonuc['err']['code']];
                }
            }

            return null;
        }

        if ($salon->sms_baslik === null || $salon->sms_apikey === null) {
            return null;
        }
        $durum = $this->efetechRaporlu($salon, $mesajlar, $tur, $dogrulama, (string) $mesajlar[0]['message'], true);

        return $geriBildirim ? $this->geriBildirimMetni($durum) : '';
    }

    /** Eski ApiController::sms_gonder_bildirimli. Eski hata KORUNUR: saglayici bakilmaz, sms_apikey'i bos salonda sessizce gitmez. */
    public function apiBildirimli(array $mesajlar, bool $geriBildirim, int|string $tur, bool $dogrulama, int|string $salonId): array|string|null
    {
        $salon = $this->salon($salonId);
        if ($salon->sms_baslik === null || $salon->sms_apikey === null) {
            return null;
        }
        $durum = $this->efetechRaporlu($salon, $mesajlar, $tur, $dogrulama, $this->satirSatirAciklama($mesajlar), false);

        return $geriBildirim ? $this->geriBildirimMetni($durum) : '';
    }

    /** Eski StoreAdminController::sms_gonder_bildirimli. */
    public function panelBildirimli(array $mesajlar, bool $geriBildirim, int|string $tur, bool $dogrulama, int|string $salonId, string $gonderimTipi = 'transaksiyonel'): array|string|null
    {
        $mesajlar = KaraListeFiltresi::topluSuz($salonId, $mesajlar, $gonderimTipi);
        if (! $mesajlar) {
            Log::info('[sms_gonder_bildirimli] tumu kara listede, hic gonderim yok', ['salon_id' => $salonId]);

            return $geriBildirim ? ['title' => 'Bilgi', 'status' => 'info', 'text' => 'Alıcıların tamamı kara listede olduğu için mesaj gönderilmedi.'] : null;
        }

        $salon = $this->whatsapp->salon($salonId) ?? throw new RuntimeException('Salon bulunamadi: '.$salonId);

        try {
            $bagli = $this->whatsapp->bagliMi($this->whatsapp->oturumSalonu($salon));
        } catch (\Throwable $e) {
            Log::warning('[Transactional WA] istisna, SMS\'e düşülüyor', ['err' => $e->getMessage(), 'salon_id' => $salon->id]);
            $bagli = false;
        }

        // Hemen basarisiz olan mesaj SMS'e duser; 202 alan listeden cikar, gec hatada eski monolitin webhook'u SMS'e dusurur.
        if ($bagli) {
            $mesajlar = $this->whatsapp->gonderilemeyenler($mesajlar, function (array $mesaj) use ($salon, $tur, $gonderimTipi) {
                $sonuc = $this->whatsapp->gonder($salon, $mesaj['to'], $mesaj['wa_message'] ?? $mesaj['message'], $mesaj['randevu_id'] ?? null,
                    $mesaj['user_id'] ?? null, null, ! empty($mesaj['urgent']), $mesaj['gonderim_tipi'] ?? $gonderimTipi);
                ($sonuc['ok'] ?? false)
                    ? Log::info('[Transactional WA] kuyruğa eklendi', ['salon_id' => $salon->id, 'logId' => $sonuc['logId'] ?? null, 'tur' => $tur])
                    : Log::warning('[Transactional WA] başarısız → SMS\'e düşüyor', ['salon_id' => $salon->id, 'err' => $sonuc['error'] ?? 'unknown']);

                return $sonuc;
            }, 'Transactional WA');
            if (! $mesajlar) {
                return $geriBildirim ? ['title' => 'Başarılı', 'status' => 'success', 'text' => 'Mesajınız alıcılarınıza başarıyla iletildi.'] : null;
            }
        }

        return SmsSaglayici::salondan($salon) === SmsSaglayici::VoiceTelekom
            ? $this->panelVoiceTelekom($salon, $mesajlar, $geriBildirim, $tur)
            : $this->panelEfetech($salon, $mesajlar, $geriBildirim, $tur, $dogrulama);
    }

    /** Eski SMSController::cokluSMSGonderVoiceTelekom. @return string 'başarılı' ya da saglayici hata metni */
    public function topluVoiceTelekom(array $telefonlar, string $mesaj, int|string $salonId, string $baslik): string
    {
        $salon = $this->salon($salonId);
        $sonuc = $this->voiceTelekom->gonder($salon, $baslik, $mesaj, $telefonlar, $this->ozelId());
        if ($sonuc['err'] === null) {
            Log::info('Toplu Mesaj Gönderme Başarlı : '.$sonuc['pkgID'], ['salon_id' => $salonId]);

            return 'başarılı';
        }
        Log::info('Toplu Mesaj Hatası', ['salon_id' => $salonId, 'err' => $sonuc['err']]);

        return $sonuc['err']['message'];
    }

    private function panelVoiceTelekom(object $salon, array $mesajlar, bool $geriBildirim, int|string $tur): ?array
    {
        $baslik = (is_numeric($tur) ? SmsTuru::tryFrom((int) $tur)?->saglayiciBasligi() : null) ?? 'Bildirim';
        $basarili = $this->geriBildirimMetni('');

        foreach ($mesajlar as $mesaj) {
            $sonuc = $this->voiceTelekom->gonder($salon, $baslik, (string) $mesaj['message'], [$mesaj['to']], $this->ozelId());
            $this->voiceTelekomLogla($sonuc);
            if ($sonuc['err'] === null) {
                // Eski hata KORUNUR: geri bildirim isteniyorsa ilk mesajdan sonra doner, kalanlar gitmez.
                if ($geriBildirim) {
                    return $basarili;
                }

                continue;
            }

            if ($sonuc['err']['code'] === self::VT_MUKERRER_HATASI) {
                sleep(1);
                $sonuc = $this->voiceTelekom->gonder($salon, $baslik, (string) $mesaj['message'], [$mesaj['to']], 'retry_'.date('Ymd_His').'_'.rand(10000, 99999));
                $this->voiceTelekomLogla($sonuc);
                if ($sonuc['err'] === null) {
                    if ($geriBildirim) {
                        return $basarili;
                    }

                    continue;
                }
            }

            if ($geriBildirim) {
                return ['title' => 'Hata', 'status' => 'error', 'text' => 'Mesajınız alıcılarınıza gönderilemedi. Hata kodu : '.$sonuc['err']['code']];
            }
        }

        return null;
    }

    private function panelEfetech(object $salon, array $mesajlar, bool $geriBildirim, int|string $tur, bool $dogrulama): array|string
    {
        if ($salon->sms_baslik === null || $salon->sms_apikey === null) {
            Log::warning('[sms_gonder_bildirimli] SMS ayarlari yapilandirilmamis, mesaj gonderilemedi', ['salon_id' => $salon->id, 'tur' => $tur]);

            return $geriBildirim ? ['title' => 'Hata', 'status' => 'error', 'text' => 'Mesaj gönderilemedi. İşletmenize ait SMS veya WhatsApp bağlantısı bulunmuyor.'] : '';
        }

        $cevap = $this->efetechGonder((string) $salon->sms_apikey, $salon->sms_baslik, $mesajlar, $salon->id);
        $durum = '';
        if ($cevap !== null && $this->efetechCevabiDolu($cevap) && ! $dogrulama) {
            $durum = $this->kabulSonrasi($salon->id, function () use ($salon, $tur, $mesajlar, $cevap) {
                $this->raporYaz($salon->id, $tur, $this->satirSatirAciklama($mesajlar), $this->efetechMesaji($cevap), 0);

                return 0;
            }, '');
        }

        return $geriBildirim ? $this->geriBildirimMetni($durum) : '';
    }

    /** Efetech gonderimi + iletim raporu (durum saglayicidan). @return int|string iletim durumu; rapor yazilmadiysa '' */
    private function efetechRaporlu(object $salon, array $mesajlar, int|string $tur, bool $dogrulama, string $aciklama, bool $raporOncesiBekle): int|string
    {
        $cevap = $this->efetechGonder((string) $salon->sms_apikey, $salon->sms_baslik, $mesajlar, $salon->id);
        if ($cevap === null || ! $this->efetechCevabiDolu($cevap) || $dogrulama) {
            return '';
        }

        return $this->kabulSonrasi($salon->id, function () use ($cevap, $salon, $tur, $aciklama, $raporOncesiBekle) {
            $mesaj = $this->efetechMesaji($cevap);
            if ($raporOncesiBekle) {
                sleep(1);
            }
            $rapor = $this->efetech->rapor($salon, $mesaj['id']);
            $durum = $rapor['response']['message']['status'] ?? throw new RuntimeException('SMS iletim durumu alinamadi: '.$mesaj['id']);
            $this->raporYaz($salon->id, $tur, $aciklama, $mesaj, $durum);

            return $durum;
        }, '');
    }

    /**
     * Efetech gonderimi. Saglayici mesaji almadiysa (ulasilamadi, gecersiz cevap) firlar: outbox yeniden dener, mukerrer olmaz.
     * Zaman asimi belirsizdir, mesaj gitmis olabilir: yeniden denenmez, null doner.
     */
    private function efetechGonder(string $apiKey, ?string $baslik, array $mesajlar, int|string|null $salonId): ?array
    {
        $cevap = $this->efetech->gonder($apiKey, $baslik, $mesajlar);
        if ($cevap === null && $this->efetech->sonIstekZamanAsimi) {
            Log::warning('[SMS] Efetech zaman asimi: mesaj gitmis olabilir, yeniden denenmez', ['salon_id' => $salonId, 'alici' => count($mesajlar)]);

            return null;
        }
        $this->efetechCevabiDolu($cevap);

        return $cevap;
    }

    /** Saglayici mesaji kabul ettikten sonraki adim (rapor, kayit) hata verirse yeniden denenmez: SMS gitmistir. */
    private function kabulSonrasi(int|string|null $salonId, callable $adim, mixed $varsayilan = null): mixed
    {
        try {
            return $adim();
        } catch (\Throwable $e) {
            Log::warning('[SMS] gonderim sonrasi adim basarisiz, yeniden denenmez', ['salon_id' => $salonId, 'hata' => $e->getMessage()]);

            return $varsayilan;
        }
    }

    /** Eski count($decoded["response"]) != 0 kosulu: gecersiz cevapta eskide de hata firlardi. */
    private function efetechCevabiDolu(?array $cevap): bool
    {
        if (! isset($cevap['response']) || ! is_array($cevap['response'])) {
            throw new RuntimeException('SMS saglayicisi gecersiz cevap verdi');
        }

        return count($cevap['response']) !== 0;
    }

    /** @return array{id:mixed, count:mixed, total_price:mixed} */
    private function efetechMesaji(array $cevap): array
    {
        $m = $cevap['response']['message'] ?? null;
        if (! is_array($m) || ! array_key_exists('id', $m) || ! array_key_exists('count', $m) || ! array_key_exists('total_price', $m)) {
            throw new RuntimeException('SMS saglayicisi eksik cevap verdi');
        }

        return $m;
    }

    private function raporYaz(int|string $salonId, int|string $tur, string $aciklama, array $efetechMesaji, int|string $durum): void
    {
        DB::table(self::ILETIM_RAPORU)->insert([
            'salon_id'   => $salonId,
            'tur'        => $tur,
            'aciklama'   => SifreMaskesi::uygula($aciklama),
            'rapor_id'   => $efetechMesaji['id'],
            'adet'       => $efetechMesaji['count'],
            'kredi'      => $efetechMesaji['total_price'],
            'durum'      => $durum,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Eski hata KORUNUR: ayrac tek tirnakli '\n', yani satir sonu degil iki karakter. */
    private function satirSatirAciklama(array $mesajlar): string
    {
        $aciklama = '';
        foreach ($mesajlar as $mesaj) {
            $aciklama .= $mesaj['message'].'\n';
        }

        return $aciklama;
    }

    private function geriBildirimMetni(int|string $iletimDurumu): array
    {
        return match ((int) ($iletimDurumu === '' ? -1 : $iletimDurumu)) {
            91      => ['title' => 'Hata', 'status' => 'error', 'text' => 'Mesajınız bakiyeniz yetersiz olduğu için alıcılarınıza gönderilemedi.'],
            92      => ['title' => 'Hata', 'status' => 'error', 'text' => 'Mesajınız gönderimlerin sağlayıcımız tarafından durudurulması nedeniyle alıcılarınıza gönderilemedi. Lütfen daha sonra tekrar deneyiniz.'],
            93      => ['title' => 'Hata', 'status' => 'error', 'text' => 'Mesajınız teknik bir arıza nedeniye alıcılarınıza gönderilemedi. Lütfen daha sonra tekrar deneyiniz.'],
            94      => ['title' => 'Hata', 'status' => 'error', 'text' => 'Mesajınız gönderiminiz engellendiği için alıcılarınıza gönderilemedi. Lütfen sistem yöneticisine başvurunuz.'],
            default => ['title' => 'Başarılı', 'status' => 'success', 'text' => 'Mesajınız alıcılarınıza başarıyla gönderildi.'],
        };
    }

    private function voiceTelekomLogla(array $sonuc): void
    {
        $sonuc['err'] === null
            ? Log::info('MessageId: '.$sonuc['pkgID'])
            : Log::info('SMS hatasi', $sonuc['err']);
    }

    private function ozelId(): string
    {
        return 'sms_'.date('Ymd_His').'_'.substr(md5(microtime()), 0, 8);
    }

    /** Eskide salon yoksa null uzerinde alan erisimi hatasi firlardi. */
    private function salon(int|string|null $salonId): object
    {
        return $this->hesap->bul((int) $salonId) ?? throw new RuntimeException('Salon bulunamadi: '.$salonId);
    }
}
