<?php

namespace App\Services\OnGorusme;

use App\Domain\OnGorusme\GorusmeDurumu;
use App\Domain\OnGorusme\GorusmeNedeni;
use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Services\Musteri\MusteriYazServisi;
use App\Services\Satis\TekKalemSatisServisi;
use App\Services\Yetki\SalonSahipligi;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Ön görüşmeyi SONUÇLANDIR — eski web/mobil ongorusmesatisyapildi + ongorusmesatisyapilmadi.
 *
 * "Satış yapıldı" görüşmenin nedenine göre satışı ortak Satis\TekKalemSatisServisi ile açar
 * (paket / ürün / hizmet → mevcut v2 satış yazıcıları; Çağrı Merkezi "Telefonda Satış" ile AYNI
 * yazıcı); tahsilat BURADA ALINMAZ — istemci dönen adisyon_id ile tahsilat ekranına gider.
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
        private TekKalemSatisServisi $satis,
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
            $satis = $this->satis->sat($salonId, $neden->tur, $neden->id,
                $ortak + ['tarih' => $tarih, 'aciklama' => 'Ön görüşmeden ürün satışı'] + $v, $kim);

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
}
