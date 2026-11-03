<?php

namespace App\Services\OnGorusme;

use App\Domain\OnGorusme\GorusmeDurumu;
use App\Domain\OnGorusme\GorusmeNedeni;
use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Exceptions\YetkisizErisimException;
use App\Models\Adisyonlar;
use App\Services\Musteri\MusteriYazServisi;
use App\Services\Paket\PaketSatisServisi;
use App\Services\Satis\KalemServisi;
use App\Services\Stok\HizliSatisServisi;
use App\Services\Yetki\SalonSahipligi;
use App\Support\AktiviteLog;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;

/**
 * Ön görüşmeyi SONUÇLANDIR — eski web/mobil ongorusmesatisyapildi + ongorusmesatisyapilmadi.
 *
 * "Satış yapıldı" görüşmenin nedenine göre satışı MEVCUT v2 satış yazıcılarıyla açar
 * (kopya adisyon/kalem/stok kodu yok); tahsilat BURADA ALINMAZ — istemci dönen adisyon_id
 * ile tahsilat ekranına gider (stüdyo: PATCH /adisyon/{id}/odeme), Paket Yönetimi ile aynı:
 *   paket  → PaketSatisServisi (katalog fiyatı; farklı fiyat 'satis.indirim_uygula' ister)
 *   ürün   → HizliSatisServisi (katalog fiyatı × adet + stok 'satis' hareketi)
 *   hizmet → adisyon + KalemServisi hizmet kalemi (seans 2+ → seanslı; fiyat kuralı paketle aynı)
 *
 * Eskiden farklar (güvenlik / tutarlılık):
 *   - Yalnız BEKLEMEDE görüşme sonuçlandırılır; satır kilitlenir → çift tıklama / iki cihaz
 *     ikinci adisyonu açamaz (409). İç satış servislerine görüşmeye özgü SABİT idempotency
 *     anahtarı verilir.
 *   - Görüşmenin müşterisi yoksa (eski kayıt) telefonla bulunur / oluşturulur ve görüşmeye bağlanır.
 *   - Satıcı personel salona ait olmalı; boşsa görüşmeyi yapan personel (mobil v1 ile aynı).
 *   - Ürün satışında fiyat katalogdan (istemci fiyatı yok sayılır — hızlı satışla aynı kural).
 */
class OnGorusmeSonucServisi
{
    public function __construct(
        private SalonSahipligi $sahiplik,
        private MusteriYazServisi $musteri,
        private PaketSatisServisi $paketSatis,
        private HizliSatisServisi $urunSatis,
        private KalemServisi $kalem,
    ) {}

    /**
     * @param  array  $v    OnGorusmeSatisIstegi::validated() — tarih?, personel_id?, fiyat?, seans?, adet?
     * @param  array{yetkili_id:int, personel_id:?int, fiyat_degistirebilir:bool}  $kim
     * @return array{adisyon_id:int, musteri_id:int, tur:string, toplam_tutar:float}
     */
    public function satisYapildi(int $salonId, int $id, array $v, array $kim): array
    {
        $yetkiliId = (int) $kim['yetkili_id'];

        $sonuc = DB::transaction(function () use ($salonId, $id, $v, $kim, $yetkiliId) {
            $og = $this->acikKilitli($id);
            $neden = GorusmeNedeni::kolonlardan($og)
                ?? throw new GecersizIslemException('Ön görüşmenin satış nedeni (paket / ürün / hizmet) tanımlı değil.');
            $musteriId = $this->musteriCoz($salonId, $og, $yetkiliId);
            $saticiId = (int) ($v['personel_id'] ?? 0) ?: ((int) $og->personel_id ?: $kim['personel_id']);
            $this->sahiplik->personelOlmali($saticiId, $salonId);

            $ortak = ['musteri_id' => $musteriId, 'personel_id' => $saticiId, 'idempotency_key' => 'ongorusme-'.$id];
            $tarih = $v['tarih'] ?? now()->toDateString();
            $satis = match ($neden->tur) {
                GorusmeNedeni::PAKET => $this->paketSatis->sat($salonId, $ortak + ['tarih' => $tarih, 'kalemler' => [
                    array_filter(['paket_id' => $neden->id, 'seans' => $v['seans'] ?? null, 'fiyat' => $v['fiyat'] ?? null], fn ($x) => $x !== null),
                ]], $kim),
                GorusmeNedeni::URUN => $this->urunSatis->sat($salonId, $ortak + ['satis_tarihi' => $tarih, 'aciklama' => 'Ön görüşmeden ürün satışı',
                    'sepet' => [['urun_id' => $neden->id, 'miktar' => (int) ($v['adet'] ?? 1)]]], ['yetkili_id' => $yetkiliId, 'olusturan_personel_id' => $kim['personel_id']]),
                GorusmeNedeni::HIZMET => $this->hizmetSat($salonId, $neden->id, $ortak + ['tarih' => $tarih] + $v, $kim),
            };

            DB::table('on_gorusmeler')->where('id', $id)->update([
                'durum' => GorusmeDurumu::SATIS_YAPILDI->db(), 'user_id' => $musteriId, 'updated_at' => now(),
            ]);

            return ['adisyon_id' => (int) $satis['adisyon_id'], 'musteri_id' => $musteriId, 'tur' => $neden->tur,
                'toplam_tutar' => (float) ($satis['toplam_tutar'] ?? 0), 'ad' => (string) $og->ad_soyad];
        });

        AktiviteLog::yaz($salonId, $yetkiliId, 'ongorusme_satis_yapildi', 'ongorusme', $id, $sonuc['ad'],
            'Ön görüşme satışa dönüştü', ['adisyon_id' => $sonuc['adisyon_id'], 'tur' => $sonuc['tur']]);
        unset($sonuc['ad']);

        return $sonuc;
    }

    /** "Satış yapılmadı" + sebep notu (zorunlu). */
    public function satisYapilmadi(int $salonId, int $id, string $not, int $yetkiliId): array
    {
        $ad = DB::transaction(function () use ($id, $not) {
            $og = $this->acikKilitli($id);
            DB::table('on_gorusmeler')->where('id', $id)->update([
                'durum' => GorusmeDurumu::SATIS_YAPILMADI->db(), 'satisyapilmadi_not' => $not, 'updated_at' => now(),
            ]);

            return (string) $og->ad_soyad;
        });

        AktiviteLog::yaz($salonId, $yetkiliId, 'ongorusme_satis_yapilmadi', 'ongorusme', $id, $ad, 'Ön görüşme: satış yapılmadı', ['not' => $not]);

        return ['id' => $id, 'durum' => GorusmeDurumu::SATIS_YAPILMADI->db()];
    }

    // ───────────────────────────── iç adımlar ─────────────────────────────

    /** Beklemedeki görüşme (FOR UPDATE); yoksa 404, sonuçlanmışsa 409. */
    private function acikKilitli(int $id): object
    {
        $og = DB::table('on_gorusmeler')->where('id', $id)->lockForUpdate()->first()
            ?? throw new BulunamadiException('Ön görüşme bulunamadı');
        if (! GorusmeDurumu::dbden($og->durum)->acikMi()) {
            throw new CakismaException('Bu ön görüşme zaten sonuçlandırılmış.');
        }

        return $og;
    }

    /** Görüşmenin müşterisi bu salonun portföyündeyse o; değilse telefonla bul / oluştur. */
    private function musteriCoz(int $salonId, object $og, int $yetkiliId): int
    {
        if ($og->user_id && DB::table('musteri_portfoy')->where('salon_id', $salonId)->where('user_id', $og->user_id)->where('aktif', 1)->exists()) {
            return (int) $og->user_id;
        }

        return $this->musteri->bulVeyaOlustur([
            'salon_id' => $salonId, 'yapan_id' => $yetkiliId, 'ad_soyad' => (string) $og->ad_soyad, 'telefon' => $og->cep_telefon,
            'email' => $og->email, 'cinsiyet' => $og->cinsiyet, 'musteri_tipi' => $og->musteri_tipi,
        ])['id'];
    }

    /**
     * Hizmet satışı: adisyon + tek hizmet kalemi (KalemServisi). Fiyat kuralı paket satışıyla
     * aynı: katalogdan farklı fiyat yalnız 'satis.indirim_uygula' yetkisiyle.
     */
    private function hizmetSat(int $salonId, int $hizmetId, array $g, array $kim): array
    {
        $katalog = (float) DB::table('salon_sunulan_hizmetler')->where('salon_id', $salonId)->where('hizmet_id', $hizmetId)->max('baslangic_fiyat');
        $fiyat = isset($g['fiyat']) ? round((float) $g['fiyat'], 2) : $katalog;
        if ($fiyat !== round($katalog, 2) && ! $kim['fiyat_degistirebilir']) {
            throw new YetkisizErisimException('Hizmet fiyatını değiştirme yetkiniz yok');
        }
        $yetkiliId = (int) $kim['yetkili_id'];

        return Idempotency::calistir('ongorusme_hizmet_satis', $g['idempotency_key'], $yetkiliId, $salonId, function () use ($salonId, $hizmetId, $g, $fiyat, $yetkiliId) {
            $adisyon = Adisyonlar::create([
                'user_id' => $g['musteri_id'], 'salon_id' => $salonId, 'olusturan_id' => $yetkiliId, 'tarih' => $g['tarih'],
            ]);
            $this->kalem->ekle((int) $adisyon->id, 'hizmet', $hizmetId, [
                'personel_id' => $g['personel_id'], 'fiyat' => $fiyat, 'seans' => $g['seans'] ?? null, 'tarih' => $g['tarih'],
            ]);

            return ['adisyon_id' => (int) $adisyon->id, 'toplam_tutar' => $fiyat];
        });
    }
}
