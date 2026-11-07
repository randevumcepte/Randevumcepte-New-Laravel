<?php

namespace App\Services\Whatsapp;

use App\Support\ZamanliKayit;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Mobil paket sekmesi: durum ve yukseltme talebi. Alanlar ve bildirim metni eski WhatsappMobileController ile ayni. */
class WhatsappPaketServisi
{
    public const PAKETLER = ['pro', 'premium'];

    public const PERIYOTLAR = ['aylik', 'yillik'];

    public const TALEP_MESAJI = 'Talebiniz alindi. Musteri temsilcimiz en kisa surede iletisime gececek.';

    private const TEKRAR_KILIT_SN = 10;

    public function durum(int $salonId): array
    {
        $s = DB::table('salonlar')->where('id', $salonId)->first();
        if (! $s) {
            return ['paket' => 'baslangic'];
        }
        $bitis = $s->whatsapp_paket_bitis ?? null;
        $baslangic = $s->whatsapp_paket_baslangic ?? null;

        return [
            'paket'     => $s->whatsapp_paket ?? 'baslangic',
            'periyot'   => $s->whatsapp_paket_periyot ?? null,
            'baslangic' => $baslangic ? Carbon::parse($baslangic)->format('Y-m-d') : null,
            'bitis'     => $bitis ? Carbon::parse($bitis)->format('Y-m-d') : null,
            'deneme'    => (bool) ($s->whatsapp_paket_deneme ?? false),
            'kalan_gun' => $bitis ? max(0, (int) Carbon::now()->diffInDays(Carbon::parse($bitis), false)) : null,
        ];
    }

    /** Cift tiklama ve tekrar deneme ikinci bildirim satiri yazmaz. */
    public function talep(int $salonId, string $paket, string $periyot, string $iletisim): void
    {
        if (! Cache::add('wa.paket.talep.'.$salonId.'.'.$paket.'.'.$periyot, 1, self::TEKRAR_KILIT_SN)) {
            return;
        }
        $salonAd = (string) DB::table('salonlar')->where('id', $salonId)->value('salon_adi');
        ZamanliKayit::ekle('bildirimler', [
            'aciklama'   => "PAKET YUKSELTME TALEBI - {$salonAd} (#{$salonId}) -> ".strtoupper($paket).' / '.ucfirst($periyot).' - Iletisim: '.($iletisim ?: '-'),
            'salon_id'   => $salonId,
            'url'        => '/sistemyonetim/isletmedetay/'.$salonId,
            'tarih_saat' => date('Y-m-d H:i:s'),
            'okundu'     => 0,
        ]);
    }
}
