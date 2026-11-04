<?php

namespace App\Services\Anket;

use App\Domain\Anket\AnketOdulu;
use App\Domain\Anket\GoogleYorumBaglantisi;
use App\Domain\Anket\ReputasyonEsikleri;
use App\Domain\Telefon\TelefonNumarasi;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Reputation Booster ayarları (salonlar kolonları) — eski web googleReviewKaydet + reputationPremiumAc
 * ve mobil v1 anketAyarlar'ın tek karşılığı. Kaydetme kısmidir: gönderilmeyen alan korunur.
 * Puan eşiği / ödül değerlendirmesi müşteri cevabında eski monolitte (/anket-kaydet) çalışır.
 */
class AnketAyarServisi
{
    private const KOLONLAR = [
        'id', 'reputation_premium_aktif', 'google_review_url', 'google_place_id',
        'google_review_esik_nps', 'google_review_esik_csat',
        'kotu_puan_uyari_telefon', 'kotu_puan_uyari_esik_nps', 'kotu_puan_uyari_esik_csat',
        'google_odul_tipi', 'google_odul_kupon_indirim_tipi', 'google_odul_kupon_deger',
        'google_odul_kupon_gecerlilik_gun', 'google_odul_puan', 'google_odul_baslik',
    ];

    public function satir(int $salonId): object
    {
        $s = DB::table('salonlar')->where('id', $salonId)->first(self::KOLONLAR);
        if (! $s) {
            throw new BulunamadiException('Salon bulunamadı');
        }

        return $s;
    }

    /** Alan adları mobil v1 anketAyarlar ile aynı (+ premium + uyarı telefonu). */
    public function oku(int $salonId): array
    {
        $s = $this->satir($salonId);

        return [
            'reputation_premium_aktif' => (bool) $s->reputation_premium_aktif,
            'google_url'               => (string) ($s->google_review_url ?? ''),
            'kotu_puan_uyari_telefon'  => (string) ($s->kotu_puan_uyari_telefon ?? ''),
        ] + ReputasyonEsikleri::satirdan($s)->dizi()
          + AnketOdulu::satirdan($s, AnketOdulu::ONEK_GOOGLE)->kolonlar(AnketOdulu::ONEK_GOOGLE);
    }

    /** @return array ayarlar + {uyari: ?string} (harita linki yapıştırıldıysa uyarı) */
    public function kaydet(int $salonId, array $g, int $yetkiliId): array
    {
        $k = [];
        $uyari = null;

        if (array_key_exists('google_url', $g)) {
            $c = GoogleYorumBaglantisi::coz((string) $g['google_url'], fn (string $u) => $this->kisaUrlAc($u));
            if (trim((string) $g['google_url']) !== '' && ! $c['review_url']) {
                throw new GecersizIslemException($c['uyari'] ?? GoogleYorumBaglantisi::GECERSIZ);
            }
            $k['google_review_url'] = $c['review_url'];
            $k['google_place_id'] = $c['place_id'];
            $uyari = $c['uyari'];
        }
        foreach (['google_review_esik_nps', 'kotu_puan_uyari_esik_nps'] as $a) {
            if (array_key_exists($a, $g)) {
                $k[$a] = ReputasyonEsikleri::npsSinirla($g[$a]);
            }
        }
        foreach (['google_review_esik_csat', 'kotu_puan_uyari_esik_csat'] as $a) {
            if (array_key_exists($a, $g)) {
                $k[$a] = ReputasyonEsikleri::csatSinirla($g[$a]);
            }
        }
        if (array_key_exists('kotu_puan_uyari_telefon', $g)) {
            $k['kotu_puan_uyari_telefon'] = TelefonNumarasi::kanonik($g['kotu_puan_uyari_telefon']) ?: null;
        }
        if (array_key_exists('google_odul_tipi', $g)) {
            $k += AnketOdulu::girdiden($g, AnketOdulu::ONEK_GOOGLE)->kolonlar(AnketOdulu::ONEK_GOOGLE);
        }

        if ($k) {
            DB::table('salonlar')->where('id', $salonId)->update($k);
            AktiviteLog::yaz($salonId, $yetkiliId, 'anket_ayar', 'anket_ayar', null, null, 'Anket / Google değerlendirme ayarları kaydedildi');
        }

        return $this->oku($salonId) + ['uyari' => $uyari];
    }

    public function premiumAc(int $salonId, int $yetkiliId): array
    {
        DB::table('salonlar')->where('id', $salonId)->update(['reputation_premium_aktif' => 1]);
        AktiviteLog::yaz($salonId, $yetkiliId, 'anket_premium_ac', 'anket_ayar', null, null, 'Reputation Booster Premium açıldı');

        return $this->oku($salonId);
    }

    /** maps.app.goo.gl gibi kısa bağlantının vardığı adres; ağ hatasında null (çözümleme yine sürer). */
    private function kisaUrlAc(string $url): ?string
    {
        try {
            return (string) Http::timeout(4)->connectTimeout(3)->withUserAgent('Mozilla/5.0 (Linux) GoogleMapsExpander')->head($url)->effectiveUri() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
