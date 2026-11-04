<?php

namespace App\Services\Anket;

use App\Domain\Telefon\TelefonNumarasi;
use App\Exceptions\GecersizIslemException;
use App\Services\Yetki\SalonSahipligi;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Anket GÖNDERME — tüm v2 giriş noktalarının (şablondan manuel/test, müşteriye hızlı gönderim, Çağrı
 * Merkezi) tek yolu. Gönderim kaydı + token burada, aynı transaction'da outbox `anket_link`: linki eski
 * monolitin `v2:bildirim-isle` komutu MEVCUT anketSmsGonder ile yollar (WhatsApp-first, SMS yedek; kanalı
 * kayda yazar). Böylece istemci gönderim id'sini hemen alır, kayıt varsa bildirim kaybolmaz.
 */
class AnketGonderServisi
{
    private const TOKEN_UZUNLUK = 8;     // SMS karakter tasarrufu (eski anketKisaToken)
    private const GECERLILIK_GUN = 30;
    private const KANAL_MANUEL = 'manuel';

    public function __construct(
        private AnketSablonYazServisi $sablonlar,
        private SalonSahipligi $sahiplik,
    ) {}

    /**
     * Seçili aktif şablonu verilen numaraya gönderir ("Test Gönder" / mobil v1 anketManuelGonder).
     *
     * @param  array{telefon:string, user_id?:?int, ad_soyad?:?string, randevu_id?:?int, personel_id?:?int}  $g
     * @return array{gonderim_id:int, kuyrukta:bool, kanal:string}
     */
    public function manuel(int $salonId, int $sablonId, array $g, int $yetkiliId): array
    {
        $aktif = DB::table('anket_sablonlari')->where('id', $sablonId)->where('salon_id', $salonId)->where('aktif', 1)->exists();
        if (! $aktif) {
            throw new GecersizIslemException('Aktif anket şablonu bulunamadı.');
        }

        $userId = ((int) ($g['user_id'] ?? 0)) ?: null;
        $ad = trim((string) ($g['ad_soyad'] ?? ''));
        if ($userId) {
            $this->sahiplik->musteriOlmali($userId, $salonId);
            $ad = (string) DB::table('users')->where('id', $userId)->value('name') ?: $ad;
        }
        $randevuId = ((int) ($g['randevu_id'] ?? 0)) ?: null;
        if ($randevuId && ! DB::table('randevular')->where('id', $randevuId)->where('salon_id', $salonId)->exists()) {
            throw new GecersizIslemException('Randevu bu işletmeye ait değil');
        }
        $personelId = ((int) ($g['personel_id'] ?? 0)) ?: null;
        $this->sahiplik->personelOlmali($personelId, $salonId);

        $sonuc = $this->olustur($salonId, $sablonId, $userId, $ad ?: 'Test', TelefonNumarasi::kanonik($g['telefon']), $randevuId, $personelId, $yetkiliId);
        AktiviteLog::yaz($salonId, $yetkiliId, 'anket_manuel_gonder', 'anket_gonderim', $sonuc['gonderim_id'], $ad ?: 'Test',
            'Manuel memnuniyet anketi gönderildi', ['sablon_id' => $sablonId]);

        return $sonuc;
    }

    /** Portföydeki müşteriye tek dokunuşla (müşteri kartı, takvim — eski anketHizliGonder). */
    public function hizli(int $salonId, int $userId, int $yetkiliId): array
    {
        $this->sahiplik->musteriOlmali($userId, $salonId);
        $musteri = DB::table('users')->where('id', $userId)->first(['id', 'name', 'cep_telefon']);
        if (! $musteri) {
            throw new GecersizIslemException('Müşteri bulunamadı.');
        }

        $sonuc = $this->musteriyeGonder($salonId, $musteri, $yetkiliId);
        AktiviteLog::yaz($salonId, $yetkiliId, 'anket_hizli_gonder', 'anket_gonderim', $sonuc['gonderim_id'], (string) $musteri->name ?: null,
            'Müşteriye hızlı anket gönderildi', ['user_id' => $userId]);

        return $sonuc;
    }

    /**
     * Salonun varsayılan şablonuyla müşteriye gönderir. Sahiplik ve log ÇAĞIRANA aittir (Çağrı Merkezi
     * müşteriyi kendi aranacak kaydından çözer); numara sunucuda çözülür → personel ham numarayı görmez.
     *
     * @param  object  $musteri  {id, name, cep_telefon}
     */
    public function musteriyeGonder(int $salonId, object $musteri, int $yetkiliId, ?int $personelId = null): array
    {
        if (trim((string) $musteri->cep_telefon) === '') {
            throw new GecersizIslemException('Müşterinin telefon numarası kayıtlı değil.');
        }
        $sablon = $this->sablonlar->varsayilanGetirYaOlustur($salonId);

        return $this->olustur($salonId, (int) $sablon->id, (int) $musteri->id, (string) $musteri->name, (string) $musteri->cep_telefon, null, $personelId, $yetkiliId);
    }

    /** @return array{gonderim_id:int, kuyrukta:bool, kanal:string} */
    private function olustur(int $salonId, int $sablonId, ?int $userId, string $ad, string $telefon, ?int $randevuId, ?int $personelId, int $yetkiliId): array
    {
        $id = DB::transaction(function () use ($salonId, $sablonId, $userId, $ad, $telefon, $randevuId, $personelId, $yetkiliId) {
            $id = (int) DB::table('anket_gonderimleri')->insertGetId([
                'salon_id'        => $salonId,
                'sablon_id'       => $sablonId,
                'randevu_id'      => $randevuId,
                'user_id'         => $userId,
                'personel_id'     => $personelId,
                'token'           => $this->token(),
                'ad_soyad'        => mb_substr($ad, 0, 150),
                'telefon'         => mb_substr($telefon, 0, 20),
                'gonderim_kanali' => self::KANAL_MANUEL,
                'gonderim_zamani' => now(),
                'son_gecerlilik'  => now()->addDays(self::GECERLILIK_GUN),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
            BildirimKuyrugu::yaz($salonId, 'anket_link', null, $yetkiliId, ['gonderim_id' => $id]);

            return $id;
        });

        return ['gonderim_id' => $id, 'kuyrukta' => true, 'kanal' => self::KANAL_MANUEL];
    }

    /** Base62 kısa token; UNIQUE sütunda çakışmaya karşı yeniden üretilir. */
    private function token(): string
    {
        do {
            $token = Str::random(self::TOKEN_UZUNLUK);
        } while (DB::table('anket_gonderimleri')->where('token', $token)->exists());

        return $token;
    }
}
