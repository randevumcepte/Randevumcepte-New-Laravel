<?php

namespace App\Domain\Sms;

/**
 * SMS durum kodları → okunur etiket (saf). Eski ekranın DataTable render'ı, Flutter _durumText ve
 * SMSController etiket haritaları TEK kaynakta; istemciler etiketi API'den okur.
 */
final class SmsDurumu
{
    public const BEKLIYOR = 0;

    public const GONDERILDI = 99;

    public const IPTAL = 95;

    /** Paket (rapor satırı) durumu. */
    private const PAKET = [
        0  => 'Bekliyor',
        1  => 'Gönderildi',
        2  => 'Gönderildi',
        3  => 'Gönderildi',
        4  => 'İleri Tarihli',
        10 => 'Onay Bekliyor',
        91 => 'Gönderilemedi (Bakiye Yetersiz)',
        92 => 'Gönderilemedi (Gönderimler Durdurulmuştu)',
        93 => 'Gönderilemedi (Teknik Arıza)',
        94 => 'Gönderim Engellendi',
        95 => 'İptal Edildi',
        99 => 'Gönderildi',
    ];

    private const VOICETELEKOM_ALICI = [
        -2 => 'İptal Edildi',
        -1 => 'Operatör Reddetti',
        0  => 'Bekliyor',
        1  => 'Gönderiliyor',
        2  => 'Gönderildi',
        3  => 'Ulaştı',
        4  => 'Ulaşmadı',
        5  => 'Zaman Aşımı',
    ];

    private const EFETECH_ALICI = [
        0  => 'Bekliyor',
        1  => 'İletildi',
        2  => 'İletilemedi',
        99 => 'Bilgi Yok',
    ];

    /** Boş / null kod "Bekliyor" sayılır (eski render ile aynı). */
    public static function paketEtiketi(int|string|null $kod): string
    {
        return self::PAKET[(int) $kod] ?? '—';
    }

    /** rc-rozet rengi — yeşil gönderildi, kırmızı başarısız, turuncu bekleyen. */
    public static function paketRengi(int|string|null $kod): string
    {
        $k = (int) $kod;

        return match (true) {
            in_array($k, [1, 2, 3, 99], true) => 'yesil',
            $k >= 91 && $k <= 95              => 'kirmizi',
            default                           => 'turuncu',
        };
    }

    public static function voiceTelekomAlici(int $kod): string
    {
        return self::VOICETELEKOM_ALICI[$kod] ?? '—';
    }

    public static function efetechAlici(int $kod): string
    {
        return self::EFETECH_ALICI[$kod] ?? '—';
    }

    /**
     * VoiceTelekom paket durumu → ortak kod (eski vtDurumKodla birebir):
     * negatif = iptal; 2 ve üstü ya da en az bir iletim = gönderildi; aksi halde bekliyor.
     */
    public static function voiceTelekomPaket(int $durum, int $iletilen): int
    {
        if ($durum < 0) {
            return self::IPTAL;
        }

        return $durum >= 2 || $iletilen > 0 ? self::GONDERILDI : self::BEKLIYOR;
    }
}
