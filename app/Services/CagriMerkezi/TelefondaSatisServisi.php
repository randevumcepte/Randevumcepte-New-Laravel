<?php

namespace App\Services\CagriMerkezi;

use App\Domain\CagriMerkezi\AramaSonucu;
use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Services\Satis\TekKalemSatisServisi;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * TELEFONDA SATIŞ — eski cagri_hizli_satis + ardından santral_not_ekle(sonuc=7) zinciri, tek uç.
 *
 * Satış ortak Satis\TekKalemSatisServisi ile açılır (Ön Görüşme satışıyla AYNI yazıcı; kopya
 * adisyon/kalem/tahsilat kodu yok); görüşme aynı transaction'da "Telefonda Satış" (7) + tutar
 * ile işaretlenir. Eskiden farklar:
 *   - Tahsilat burada ALINMAZ: dönen adisyon_id ile v2 tahsilat ekranına gidilir (eski kod ödemeyi
 *     kalem pivotsuz/komisyonsuz yazabiliyordu; v2 tahsilat yazıcısı WEB-kanonik dağıtım yapar).
 *   - Fiyat kuralı v2 satışla aynı: ürün katalog × adet; paket/hizmet katalogdan farklı fiyat
 *     yalnız 'satis.indirim_uygula' ile. Çift tıklamaya karşı idempotency_key.
 */
class TelefondaSatisServisi
{
    public function __construct(
        private CagriMerkeziErisimServisi $erisim,
        private TekKalemSatisServisi $satis,
        private GorusmeSonucServisi $sonuc,
    ) {}

    /**
     * @param array{tur:string, katalog_id:int, fiyat:?float, seans:?int, adet:?int, personel_id:?int,
     *              not:?string, idempotency_key:string} $v
     */
    public function sat(CagriMerkeziErisim $e, int $aranacakId, array $v): array
    {
        $this->erisim->yetkiZorunlu($e, 'satis', 'Satış için yetkiniz yok.');
        $k = $this->erisim->kayit($aranacakId, $e);
        if (! empty($v['personel_id'])) {
            $this->erisim->personel((int) $v['personel_id'], $e);
        }

        $sonuc = DB::transaction(function () use ($e, $k, $aranacakId, $v) {
            $s = $this->satis->sat($e->salonId, $v['tur'], (int) $v['katalog_id'], [
                'musteri_id'      => (int) $k->user_id,
                'personel_id'     => $v['personel_id'] ?? $e->personelId,
                'idempotency_key' => 'cagri-satis-'.$v['idempotency_key'],
                'fiyat'           => $v['fiyat'] ?? null,
                'seans'           => $v['seans'] ?? null,
                'adet'            => $v['adet'] ?? null,
                'aciklama'        => 'Telefonda satış (çağrı merkezi)',
            ], ['yetkili_id' => $e->yetkiliId, 'personel_id' => $e->personelId, 'fiyat_degistirebilir' => $e->yetki('fiyat_degistir')]);

            if (! $s['replay']) {
                $this->sonuc->kaydet($e, $aranacakId, [
                    'sonuc' => AramaSonucu::TELEFONDA_SATIS->value, 'not' => $v['not'] ?? null, 'satis_tutari' => $s['toplam_tutar'],
                ]);
            }

            return $s;
        });

        if (! $sonuc['replay']) {
            AktiviteLog::yaz($e->salonId, $e->yetkiliId, 'cagri_telefonda_satis', 'adisyon', $sonuc['adisyon_id'],
                (string) DB::table('users')->where('id', $k->user_id)->value('name') ?: null,
                'Telefonda satış (çağrı merkezi) — '.number_format($sonuc['toplam_tutar'], 2, ',', '.').' ₺',
                ['aranacak_musteri_id' => $aranacakId, 'tur' => $v['tur']]);
        }

        return ['adisyon_id' => $sonuc['adisyon_id'], 'toplam_tutar' => $sonuc['toplam_tutar']];
    }
}
