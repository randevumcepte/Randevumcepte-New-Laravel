<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Services\BildirimKuyrugu\AnketLinkGonderici;
use App\Services\BildirimKuyrugu\BildirimIsleyici;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Gecis kalintisi: v2 artik 'anket_link' yazar, bu tur yalniz kuyrukta kalmis satirlar icindir. Eski varsayilanAnketSablonuGetirYaOlustur
 * ve anketGonderimOlustur ile birebir; AnketSablonYazServisi'nin sablonu (ad, 'varsayilan' onceligi, gonder_saat_sonra) farkli
 * oldugu icin ona baglanmadi.
 */
class AnketGonderIsleyici implements BildirimIsleyici
{
    private const TOKEN_UZUNLUK = 8;

    private const GECERLILIK_GUN = 30;

    public function __construct(private AnketLinkGonderici $gonderici) {}

    public function isle(object $kayit, array $payload): void
    {
        $musteri = DB::table('users')->where('id', (int) ($payload['user_id'] ?? 0))->first();
        if (! $musteri || empty($musteri->cep_telefon)) {
            throw new RuntimeException('anket_gonder: musteri/telefon yok: '.($payload['user_id'] ?? '-'));
        }

        $salonId = (int) $kayit->salon_id;
        $sablon = $this->sablon($salonId);
        $gonderimId = (int) DB::table('anket_gonderimleri')->insertGetId([
            'salon_id'        => $salonId,
            'sablon_id'       => $sablon->id,
            'randevu_id'      => null,
            'arsiv_id'        => null,
            'user_id'         => $musteri->id,
            'personel_id'     => $payload['personel_id'] ?? null,
            'token'           => $this->token(),
            'ad_soyad'        => $musteri->name,
            'telefon'         => $musteri->cep_telefon,
            'gonderim_kanali' => $payload['kanal'] ?? 'manuel',
            'gonderim_zamani' => now(),
            'son_gecerlilik'  => now()->addDays(self::GECERLILIK_GUN),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->gonderici->gonder(DB::table('anket_gonderimleri')->where('id', $gonderimId)->first(), $sablon, $musteri);
    }

    private function sablon(int $salonId): object
    {
        $sablon = DB::table('anket_sablonlari')->where('salon_id', $salonId)->where('aktif', 1)->orderBy('id')->first();
        if ($sablon) {
            return $sablon;
        }

        $id = DB::table('anket_sablonlari')->insertGetId([
            'salon_id'     => $salonId,
            'ad'           => 'Varsayilan Memnuniyet Anketi',
            'aciklama'     => 'Sistem tarafindan ilk manuel gonderim sirasinda otomatik olusturuldu.',
            'sorular_json' => json_encode([
                ['tip' => 'nps',  'soru' => 'Bizi bir arkadasiniza tavsiye eder misiniz? (0-10)'],
                ['tip' => 'csat', 'soru' => 'Hizmetimizden ne kadar memnun kaldiniz? (1-5)'],
                ['tip' => 'metin', 'soru' => 'Bize onerileriniz veya yorumlariniz nelerdir?'],
            ], JSON_UNESCAPED_UNICODE),
            'otomatik_gonder'   => 0,
            'gonder_saat_sonra' => 24,
            'aktif'             => 1,
            'varsayilan'        => 1,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return DB::table('anket_sablonlari')->where('id', $id)->first();
    }

    private function token(): string
    {
        do {
            $token = Str::random(self::TOKEN_UZUNLUK);
        } while (DB::table('anket_gonderimleri')->where('token', $token)->exists());

        return $token;
    }
}
