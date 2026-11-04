<?php

namespace App\Services\Hesabim;

use App\Domain\Hesabim\UyelikKatalogu;
use App\Support\EskiPanelLinki;
use Illuminate\Support\Facades\DB;

/**
 * Hesabım → ödeme kayıtları + satıştaki duyuru paketleri. Salon başına az kayıt olduğu için
 * tek cevapta döner (web tabloyu yerel modda sayfalar).
 *
 * Eski koddan fark: eski ekran salon_uyelik_odemeleri'ni tabloda OLMAYAN kolonlarla okuyordu
 * (odeme_tarihi, tutar, durum, dosya_url); hata try/catch'te yutulduğu için liste hep boştu.
 * Burada gerçek kolonlardan (ucret, created_at) okunur; JSON alanları eski sözleşmeyle aynı.
 */
class OdemeGecmisi
{
    private const LIMIT = 100;

    /** @return list<array<string,mixed>> */
    public function uyelikOdemeleri(int $salonId): array
    {
        return DB::table('salon_uyelik_odemeleri')->where('salon_id', $salonId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(self::LIMIT)
            ->get(['id', 'paket', 'periyot', 'ucret', 'kdv_dahil', 'yeni_tarih', 'aciklama', 'created_at'])
            ->map(fn ($o) => [
                'id'          => (int) $o->id,
                'odeme_tarihi' => substr((string) $o->created_at, 0, 10),
                'aciklama'    => (string) ($o->aciklama ?: $o->paket),
                'periyot'     => UyelikKatalogu::periyotKodAdi($o->periyot),
                'tutar'       => (float) $o->ucret,
                'kdv_dahil'   => (bool) $o->kdv_dahil,
                'gecerlilik'  => $o->yeni_tarih,
                'durum'       => 'odendi',
                'dosya_url'   => null,
            ])->all();
    }

    /** Başarılı duyuru paketi satın alımları. @return list<array<string,mixed>> */
    public function smsSiparisleri(int $salonId): array
    {
        return DB::table('sms_paket_siparisleri')->where('salon_id', $salonId)->where('durum', 1)
            ->orderByDesc('id')->limit(self::LIMIT)
            ->get(['id', 'sms_adet', 'tutar', 'yukleme_durumu', 'fatura_url', 'created_at'])
            ->map(fn ($s) => [
                'id'             => (int) $s->id,
                'created_at'     => (string) $s->created_at,
                'sms_adet'       => (int) $s->sms_adet,
                'tutar'          => (float) $s->tutar,
                'yukleme_durumu' => (int) $s->yukleme_durumu,
                'fatura_url'     => EskiPanelLinki::mutlak($s->fatura_url),
            ])->all();
    }

    /**
     * Satıştaki duyuru paketleri. Ödeme (PayTR) eski panelde kalır → satın alma linki oraya gider.
     *
     * @return list<array<string,mixed>>
     */
    public function smsPaketleri(int $salonId): array
    {
        return DB::table('sms_paketleri')->orderBy('sms_adet')
            ->get(['id', 'sms_adet', 'paket_adi', 'alt_baslik', 'ucret'])
            ->map(fn ($p) => [
                'id'          => (int) $p->id,
                'ad'          => (string) ($p->paket_adi ?: number_format((float) $p->sms_adet, 0, ',', '.').' SMS'),
                'alt_baslik'  => (string) ($p->alt_baslik ?: 'İnteraktif Duyuru Paketi'),
                'sms_adet'    => (int) $p->sms_adet,
                'ucret'       => (float) $p->ucret,
                'satin_al_url' => EskiPanelLinki::url('isletmeyonetim/smspaketsatinal/'.$p->id, ['sube' => $salonId]),
            ])->all();
    }
}
