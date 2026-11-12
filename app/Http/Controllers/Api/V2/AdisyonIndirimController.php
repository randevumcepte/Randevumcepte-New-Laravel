<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\BulunamadiException;
use App\Services\Ayar\MusteriIndirimServisi;
use App\Services\Cark\CarkKuponServisi;
use App\Services\Tahsilat\AdisyonOkumaServisi;
use App\Services\Tahsilat\Mobil\MobilKampanyaKoduServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use App\Support\Yetki\IslemYetkileri;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Satış & Tahsilat — KAZANILMIŞ İNDİRİMLER (web + Flutter aynı uçlar). İnce: kimlik → adisyonun salonu →
 * kapı → servis. Kurallar servislerde tek yerde:
 *   müşteri (aktif/sadık) indirimi → MusteriIndirimServisi::adisyonIcin (tahsilatta % olarak uygulanır)
 *   kampanya indirim kodu          → MobilKampanyaKoduServisi (kod yakılır, indirim kaleme yazılır)
 *   çark/ödül kuponu               → CarkKuponServisi::adisyonaUygula
 */
class AdisyonIndirimController extends V2Controller
{
    protected ?string $yazmaAlani = 'tahsilat';

    private const GORME = IslemYetkileri::SATIS_GOR;

    private const UYGULAMA = IslemYetkileri::TAHSILAT;

    public function __construct(
        private AdisyonOkumaServisi $adisyon,
        private GuvenlikKapisi $kapi,
        private MusteriIndirimServisi $musteri,
        private CarkKuponServisi $kupon,
        private MobilKampanyaKoduServisi $kampanya,
    ) {}

    /**
     * GET /api/v2/adisyon/{id}/indirimler
     * → {musteri_indirimi: {tur, etiket, yuzde, tahsilat_sayisi}|null, cark_kuponlari: [...], kampanya_kodlari: [...],
     *    uygulanan: {kampanya: {kod, metin}|null, kuponlar: [...]}}
     */
    public function liste(int $id)
    {
        [$salonId, $userId] = $this->adisyonDenetle($id, self::GORME);
        $kampanya = $this->kampanya->adisyonDurumu($salonId, $id);

        return ApiCevap::basarili([
            'musteri_indirimi' => $this->musteri->adisyonIcin($salonId, $userId, $id),
            'cark_kuponlari'   => $userId ? $this->kupon->musteriKuponlari($salonId, $userId) : [],
            'kampanya_kodlari' => $userId ? $this->kampanya->kodlar($salonId, $userId)['kodlar'] : [],
            'uygulanan'        => [
                'kampanya' => ! empty($kampanya['uygulanmis']) ? ['kod' => $kampanya['kod'], 'metin' => $kampanya['metin']] : null,
                'kuponlar' => $this->kupon->adisyonKuponlari($id),
            ],
        ]);
    }

    /** POST /api/v2/adisyon/{id}/cark-kuponu  body: {kod} */
    public function carkKuponu(Request $request, int $id)
    {
        [$salonId] = $this->adisyonDenetle($id, self::UYGULAMA);
        $this->yazmaAcikMi();
        $sonuc = $this->kupon->adisyonaUygula($salonId, (int) $this->kullanici()->id, $id, (string) $request->input('kod'));
        $sonuc['hesap'] = $this->adisyon->getir($id)['hesap'];

        return ApiCevap::basarili($sonuc, $sonuc['mesaj']);
    }

    /** POST /api/v2/adisyon/{id}/kampanya-kodu  body: {kod, idempotency_key} */
    public function kampanyaKodu(Request $request, int $id)
    {
        [$salonId, $userId] = $this->adisyonDenetle($id, self::UYGULAMA);
        $this->yazmaAcikMi();
        $sonuc = $this->kampanya->kullan($salonId, (int) $this->kullanici()->id, [
            'musteri_id' => $userId, 'kod' => (string) $request->input('kod'), 'adisyon_id' => $id,
            'idempotency_key' => (string) $request->input('idempotency_key'), 'tahsilata_dusulmus' => true,
        ]);
        $sonuc['hesap'] = $this->adisyon->getir($id)['hesap'];

        return ApiCevap::basarili($sonuc, (string) ($sonuc['mesaj'] ?? 'Kod uygulandı.'));
    }

    /** @return array{0:int,1:int} [salon id, müşteri (user) id] */
    private function adisyonDenetle(int $id, array $yetkiler): array
    {
        $user = $this->kullanici();
        $salonId = $this->adisyon->salonIdGetir($id);
        if (! $salonId) {
            throw new BulunamadiException('Adisyon bulunamadı');
        }
        $this->kapi->denetle($user->id, $salonId, $yetkiler);

        return [$salonId, (int) DB::table('adisyonlar')->where('id', $id)->value('user_id')];
    }
}
