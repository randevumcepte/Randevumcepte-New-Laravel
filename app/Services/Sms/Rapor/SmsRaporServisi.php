<?php

namespace App\Services\Sms\Rapor;

use App\Domain\Sms\SmsDurumu;
use App\Domain\Sms\SmsSaglayici;
use App\Domain\Sms\SmsTuru;
use App\Domain\Telefon\TelefonNumarasi;
use App\Services\Sms\SmsHesapServisi;
use App\Support\ListeIstegi;
use App\Support\Yetki\YetkiSabitleri;
use Illuminate\Support\Facades\DB;

/**
 * SMS raporları — sağlayıcı seçimi + ortak zenginleştirme (durum etiketi, alıcı adı, telefon maskesi).
 * Sağlayıcıya ulaşılamazsa liste boş döner ve `uyari` dolar: kullanıcı "hiç rapor yok" sanmaz.
 */
class SmsRaporServisi
{
    public function __construct(
        private SmsHesapServisi $hesap,
        private VoiceTelekomRapor $voiceTelekom,
        private EfetechRapor $efetech,
    ) {}

    /** @param array{page:int, per_page:int, ara:?string, sirala:?string, yon:string} $liste */
    public function paketler(int $salonId, SmsTuru $tur, array $liste, bool $yenile = false): array
    {
        $salon = $this->hesap->zorunlu($salonId);
        $saglayici = $this->saglayici($salon);
        if ($yenile) {
            $saglayici->unut($salon);
        }

        $uyari = null;
        try {
            ['satirlar' => $satirlar, 'toplam' => $toplam] = $saglayici->paketler($salon, $tur, $liste);
        } catch (SaglayiciHatasi $e) {
            [$satirlar, $toplam, $uyari] = [[], 0, $e->getMessage()];
        }

        return [
            'data' => array_map(fn ($s) => $s + [
                'durum_etiket' => SmsDurumu::paketEtiketi($s['status']),
                'durum_renk'   => SmsDurumu::paketRengi($s['status']),
            ], $satirlar),
            'uyari' => $uyari,
        ] + ListeIstegi::meta($liste['page'], $liste['per_page'], $toplam);
    }

    /** @return array{kayitlar:array<int,array>, uyari:?string} */
    public function alicilar(int $salonId, string $paketId, bool $telGor): array
    {
        $salon = $this->hesap->zorunlu($salonId);
        try {
            $kayitlar = $this->saglayici($salon)->alicilar($salon, $paketId);
        } catch (SaglayiciHatasi $e) {
            return ['kayitlar' => [], 'uyari' => $e->getMessage()];
        }

        $adlar = $this->adlar($salonId, array_column($kayitlar, 'telefon'));
        foreach ($kayitlar as &$k) {
            $k['ad'] = $adlar[TelefonNumarasi::kanonik($k['telefon'])] ?? '';
            if (! $telGor) {
                $k['telefon'] = YetkiSabitleri::telefonMaskele($k['telefon']);
            }
        }
        unset($k);

        return ['kayitlar' => $kayitlar, 'uyari' => null];
    }

    /**
     * Numara → portföydeki müşteri adı (kanonik numara anahtarlı). Yalnız bu salonun portföyü: başka
     * işletmenin müşteri adı sızmaz.
     *
     * @param  string[]  $numaralar
     * @return array<string,string>
     */
    private function adlar(int $salonId, array $numaralar): array
    {
        $varyantlar = [];
        foreach (array_unique($numaralar) as $n) {
            array_push($varyantlar, ...TelefonNumarasi::aramaVaryantlari(TelefonNumarasi::kanonik($n)));
        }

        $harita = [];
        foreach (array_chunk(array_values(array_unique($varyantlar)), 1000) as $parca) {
            $satirlar = DB::table('musteri_portfoy as mp')->join('users as u', 'u.id', '=', 'mp.user_id')
                ->where('mp.salon_id', $salonId)->whereIn('u.cep_telefon', $parca)->get(['u.cep_telefon', 'u.name']);
            foreach ($satirlar as $s) {
                $harita[TelefonNumarasi::kanonik($s->cep_telefon)] = (string) $s->name;
            }
        }

        return $harita;
    }

    private function saglayici(object $salon): SmsRaporSaglayici
    {
        return match (SmsSaglayici::salondan($salon)) {
            SmsSaglayici::VoiceTelekom => $this->voiceTelekom,
            SmsSaglayici::Efetech      => $this->efetech,
        };
    }
}
