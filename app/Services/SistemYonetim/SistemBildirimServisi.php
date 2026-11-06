<?php

namespace App\Services\SistemYonetim;

use App\Services\Oturum\PlatformSms;
use App\Services\Whatsapp\Kopru\WhatsappKoprusu;
use App\Support\JsonAyarDosyasi;
use Illuminate\Support\Facades\Log;

/**
 * Sistem bildirimleri (eski App\Services\SistemBildirim): salon-bagimsiz "sistem" WhatsApp oturumu + platform SMS'i.
 * Ayar sistem_wa.json'da, eski monolitle ortak. WhatsApp bagli degilse SMS yine gider. Hata firlatmaz.
 */
class SistemBildirimServisi
{
    private const VARSAYILAN_OTURUM = 'sistem';

    public function __construct(private WhatsappKoprusu $kopru, private PlatformSms $sms) {}

    /** @return array{numara:string,gonderen_numara:string,aktif:bool,session_id:string} */
    public function ayar(): array
    {
        $d = JsonAyarDosyasi::oku((string) config('sistem.wa_ayar_dosyasi'));

        return [
            'numara'          => (string) ($d['numara'] ?? ''),
            'gonderen_numara' => (string) ($d['gonderen_numara'] ?? ''),
            'aktif'           => ! empty($d['aktif']),
            'session_id'      => (string) (($d['session_id'] ?? '') ?: self::VARSAYILAN_OTURUM),
        ];
    }

    public function ayarYaz(?string $alicilar, bool $aktif, ?string $gonderen): array
    {
        return JsonAyarDosyasi::guncelle((string) config('sistem.wa_ayar_dosyasi'), [
            'numara'          => implode(',', self::parcala((string) $alicilar)),
            'gonderen_numara' => self::normalize((string) $gonderen),
            'aktif'           => $aktif,
        ]);
    }

    public function oturumId(): string
    {
        return $this->ayar()['session_id'];
    }

    /** Oturum kapatilinca: kopru eski kimligi saklasa da yeni baglantida mutlaka yeni QR ciksin. */
    public function yeniOturumId(): string
    {
        $yeni = self::VARSAYILAN_OTURUM.'-'.substr((string) time(), -6);
        JsonAyarDosyasi::guncelle((string) config('sistem.wa_ayar_dosyasi'), ['session_id' => $yeni]);

        return $yeni;
    }

    /** @return list<string> normalize alicilar (90...) */
    public function alicilar(): array
    {
        return self::parcala($this->ayar()['numara']);
    }

    /** Ayardaki tum alicilara. @return array{ok:bool,ozet?:array<string,array{wa:bool,sms:bool}>,neden?:string} */
    public function gonder(string $mesaj): array
    {
        $alicilar = $this->alicilar();
        if (! $this->ayar()['aktif'] || ! $alicilar) {
            Log::warning('[SistemBildirim] atlandi: kapali veya alici yok');

            return ['ok' => false, 'neden' => 'kapali-veya-numara-yok'];
        }
        $ozet = [];
        foreach ($alicilar as $numara) {
            $ozet[$numara] = $this->tekNumaraya($numara, $mesaj);
        }

        return ['ok' => true, 'ozet' => $ozet];
    }

    /** Tek numaraya (or. salon yetkilisine). @return array{ok:bool,wa:bool,sms:bool} */
    public function gonderTekil(string $numara, string $mesaj): array
    {
        $hedef = self::normalize($numara);
        if ($hedef === '') {
            return ['ok' => false, 'wa' => false, 'sms' => false];
        }
        $sonuc = $this->tekNumaraya($hedef, $mesaj);

        return ['ok' => $sonuc['wa'] || $sonuc['sms']] + $sonuc;
    }

    /** @return array{wa:bool,sms:bool} */
    private function tekNumaraya(string $numara, string $mesaj): array
    {
        return [
            'wa'  => $this->kopru->gonder($this->oturumId(), $numara, $mesaj)->basarili(),
            'sms' => $this->sms->gonder((string) preg_replace('/^90/', '', $numara), $mesaj, (string) config('oturum.platform_sms.baslik')),
        ];
    }

    /** 05xx / 5xx / +90... → 90xxxxxxxxxx */
    public static function normalize(string $tel): string
    {
        $t = (string) preg_replace('/\D/', '', $tel);
        if (strlen($t) === 10 && $t[0] === '5') {
            return '90'.$t;
        }

        return strlen($t) === 11 && $t[0] === '0' ? '90'.substr($t, 1) : $t;
    }

    /** @return list<string> */
    private static function parcala(string $ham): array
    {
        $parcalar = preg_split('/[\s,;]+/', $ham, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(array_map(self::normalize(...), $parcalar))));
    }
}
