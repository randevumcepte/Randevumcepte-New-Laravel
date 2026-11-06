<?php

namespace App\Services\SistemYonetim\Entegrasyon;

use App\Services\SistemYonetim\Denetim;
use App\Services\Whatsapp\WhatsappKontorServisi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** WhatsApp paket/deneme/kontor yazmalari (eski salonPaketDetay, denemeBaslat, denemeIptal, paketSet). Her yazma denetim izine duser. */
class WhatsappPaketServisi
{
    public const KOLONLAR = ['id', 'salon_adi', 'whatsapp_paket', 'whatsapp_paket_periyot', 'whatsapp_paket_baslangic', 'whatsapp_paket_bitis', 'whatsapp_paket_deneme'];

    public function __construct(private WhatsappKontorServisi $kontor) {}

    public function detay(object $salon): array
    {
        $paket = $salon->whatsapp_paket ?: WhatsappPaketKatalogu::BASLANGIC;
        $bitis = $salon->whatsapp_paket_bitis;

        return [
            'salon_id'  => (int) $salon->id,
            'salon_adi' => $salon->salon_adi,
            'paket'     => $paket,
            'paket_ad'  => WhatsappPaketKatalogu::PAKETLER[$paket]['ad'] ?? $paket,
            'periyot'   => $salon->whatsapp_paket_periyot,
            'periyot_ad' => WhatsappPaketKatalogu::PERIYOTLAR[$salon->whatsapp_paket_periyot] ?? null,
            'baslangic' => $this->gun($salon->whatsapp_paket_baslangic),
            'bitis'     => $this->gun($bitis),
            'deneme'    => (bool) $salon->whatsapp_paket_deneme,
            'kalan_gun' => $bitis ? max(0, (int) now()->diffInDays(Carbon::parse($bitis), false)) : null,
        ];
    }

    public function denemeBaslat(object $salon, string $paket, int $gun): array
    {
        $simdi = now();
        $bitis = $simdi->copy()->addDays($gun);
        DB::table('salonlar')->where('id', $salon->id)->update([
            'whatsapp_paket'           => $paket,
            'whatsapp_paket_periyot'   => null,
            'whatsapp_paket_baslangic' => $simdi,
            'whatsapp_paket_bitis'     => $bitis,
            'whatsapp_paket_deneme'    => 1,
        ]);
        Denetim::yaz('whatsapp_deneme_baslat', 'salon', (int) $salon->id, $salon->salon_adi, "WhatsApp denemesi: {$paket}, {$gun} gün", ['paket' => $paket, 'gun' => $gun, 'bitis' => $bitis->toDateString()]);

        return ['paket' => $paket, 'gun' => $gun, 'baslangic' => $simdi->toDateString(), 'bitis' => $bitis->toDateString()];
    }

    public function denemeIptal(object $salon): void
    {
        $this->baslangicaDusur($salon);
        Denetim::yaz('whatsapp_deneme_iptal', 'salon', (int) $salon->id, $salon->salon_adi, 'WhatsApp denemesi iptal edildi', ['eski' => $this->detay($salon)]);
    }

    /** Odeme onayi sonrasi elle paket. Baslangic paketi tum alanlari sifirlar; digerinde bos birakilan alan degismez. */
    public function paketAyarla(object $salon, array $g): void
    {
        if ($g['paket'] === WhatsappPaketKatalogu::BASLANGIC) {
            $this->baslangicaDusur($salon);
        } else {
            DB::table('salonlar')->where('id', $salon->id)->update(array_filter([
                'whatsapp_paket_periyot'   => $g['periyot'] ?? null,
                'whatsapp_paket_baslangic' => $g['baslangic'] ?? null,
                'whatsapp_paket_bitis'     => $g['bitis'] ?? null,
            ]) + ['whatsapp_paket' => $g['paket'], 'whatsapp_paket_deneme' => 0]);
        }
        Denetim::yaz('whatsapp_paket_ayarla', 'salon', (int) $salon->id, $salon->salon_adi, 'WhatsApp paketi: '.$g['paket'], ['eski' => $this->detay($salon), 'yeni' => $g]);
    }

    /** @return array{bakiye:int,mesaj:string} $adet negatifse duser. */
    public function kontorYukle(object $salon, int $adet, ?string $aciklama): array
    {
        $bakiye = $this->kontor->yukle((int) $salon->id, $adet, trim((string) $aciklama) ?: 'manuel-yukleme (panel)');
        Denetim::yaz('whatsapp_kontor_yukle', 'salon', (int) $salon->id, $salon->salon_adi, 'Kontör: '.$adet, ['adet' => $adet, 'bakiye' => $bakiye]);

        return [
            'bakiye' => $bakiye,
            'mesaj'  => number_format($adet, 0, ',', '.').' kontör işlendi. Yeni bakiye: '.number_format($bakiye, 0, ',', '.'),
        ];
    }

    private function baslangicaDusur(object $salon): void
    {
        DB::table('salonlar')->where('id', $salon->id)->update([
            'whatsapp_paket'           => WhatsappPaketKatalogu::BASLANGIC,
            'whatsapp_paket_periyot'   => null,
            'whatsapp_paket_baslangic' => null,
            'whatsapp_paket_bitis'     => null,
            'whatsapp_paket_deneme'    => 0,
        ]);
    }

    private function gun(?string $tarih): ?string
    {
        return $tarih ? substr($tarih, 0, 10) : null;
    }
}
