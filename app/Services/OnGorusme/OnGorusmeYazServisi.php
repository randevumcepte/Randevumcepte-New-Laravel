<?php

namespace App\Services\OnGorusme;

use App\Domain\OnGorusme\GorusmeDurumu;
use App\Domain\OnGorusme\GorusmeNedeni;
use App\Domain\Personel\Telefon;
use App\Domain\Stok\UrunTipi;
use App\Domain\Telefon\TelefonNumarasi;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Services\Musteri\MusteriYazServisi;
use App\Services\Paket\PaketSorgusu;
use App\Services\Randevu\RandevuKayitServisi;
use App\Services\Randevu\RandevuOrtak;
use App\Services\Stok\StokKapsam;
use App\Services\Yetki\SalonSahipligi;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use App\Support\Metin;
use Illuminate\Support\Facades\DB;

/**
 * Ön görüşme EKLE / DÜZENLE — eski web ongorusmeekleduzenle ve mobil v1
 * ongorusmeekleguncelle'nin TEK karşılığı (iki kanal artık aynı kuralı yazar).
 *
 * Akış: neden + kaynaklar salona ait mi → ÇAKIŞMA (randevu editörüyle aynı kural/mesaj:
 * RandevuKayitServisi::kaynakCakismasi) → [tek transaction] müşteri bul/oluştur
 * (MusteriYazServisi) → on_gorusmeler satırı → takvimdeki ön görüşme randevusu →
 * outbox (ongorusme_kayit bildirimi + google_takvim) → aktivite logu.
 *
 * Eskiden bilinçli farklar:
 *   - Düzenlemede randevu SİLİNİP yeniden açılmaz; aynı randevu güncellenir (id sabit —
 *     bildirim / takvim bağlantıları kopmaz).
 *   - Müşteri, personel, oda ve neden (paket/ürün/hizmet) istekteki salona ait olmalı.
 *   - Sonuçlanmış (satış yapıldı / yapılmadı) görüşme düzenlenemez.
 *   - Maskeli telefon gelirse kayıtlı numara korunur (telefon_gor yetkisizin düzenlemesi).
 *   - Düzenlemede bildirim yalnız tarih / saat / personel değiştiyse gönderilir
 *     (eski kod her kayıtta "randevunuz düzenlenmiştir" SMS'i atıyordu).
 */
class OnGorusmeYazServisi
{
    public function __construct(
        private SalonSahipligi $sahiplik,
        private MusteriYazServisi $musteri,
        private RandevuKayitServisi $randevu,
        private PaketSorgusu $paketler,
        private StokKapsam $stok,
    ) {}

    /**
     * @param  array  $v  OnGorusmeKaydetIstegi::validated()
     * @return array{cakismavar:string, cakisanunsurlar:string, yazildi:bool, id?:int, randevu_id?:int}
     */
    public function kaydet(int $salonId, ?int $id, array $v, int $yetkiliId): array
    {
        $eski = $id ? $this->acikKayit($id) : null;
        $neden = ! empty($v['neden_tur']) ? GorusmeNedeni::olustur($v['neden_tur'], (int) $v['neden_id']) : null;
        if ($neden) {
            $this->nedenSalonaAitOlmali($neden, $salonId);
        }

        $personelId = ! empty($v['personel_id']) ? (int) $v['personel_id'] : null;
        $odaId = $this->odaCoz($salonId, $v['oda_id'] ?? null);
        if ($personelId) {
            $this->sahiplik->personelOlmali($personelId, $salonId);
        }
        if (! empty($v['musteri_id'])) {
            $this->sahiplik->musteriOlmali((int) $v['musteri_id'], $salonId);
        }

        $tarih = $v['tarih'];
        $saat = substr($v['saat'], 0, 5);
        $bitis = date('H:i', strtotime($saat) + 60 * (int) config('ongorusme.sure_dk', 60));
        $randevuId = $eski ? $this->randevuId((int) $eski->id) : null;

        if (empty($v['cakisanrandevuekle'])) {
            $cakisma = $this->randevu->kaynakCakismasi($salonId, $tarih, $saat, $bitis, ['personel_id' => $personelId, 'oda_id' => $odaId], $randevuId);
            if ($cakisma !== '') {
                return ['cakismavar' => '1', 'cakisanunsurlar' => $cakisma, 'yazildi' => false];
            }
        }

        $zamanDegisti = ! $eski || substr((string) $eski->hatirlatma_tarihi, 0, 10) !== $tarih
            || substr((string) $eski->on_gorusme_saati, 0, 5) !== $saat || (int) $eski->personel_id !== $personelId;

        $sonuc = DB::transaction(function () use ($salonId, $eski, $v, $neden, $personelId, $odaId, $tarih, $saat, $bitis, $randevuId, $yetkiliId, $zamanDegisti) {
            $userId = $this->musteriCoz($salonId, $v, $yetkiliId);
            $tel = $this->telefonCoz($v['telefon'] ?? null, $eski?->cep_telefon, $userId);

            $alanlar = [
                'user_id' => $userId, 'salon_id' => $salonId, 'ad_soyad' => Metin::basHarfBuyut(trim($v['ad_soyad'])),
                'cep_telefon' => $tel, 'email' => $v['email'] ?? null, 'cinsiyet' => $v['cinsiyet'] ?? null,
                'adres' => $v['adres'] ?? null, 'meslek' => $v['meslek'] ?? null, 'il_id' => $v['il_id'] ?? null,
                'aciklama' => $v['aciklama'] ?? null, 'musteri_tipi' => $v['musteri_tipi'] ?? null,
                'hatirlatma_tarihi' => $tarih, 'on_gorusme_saati' => $saat.':00', 'personel_id' => $personelId,
                'updated_at' => now(),
            ] + ($neden?->kolonlar() ?? ['paket_id' => null, 'urun_id' => null, 'hizmet_id' => null]);

            if ($eski) {
                $ogId = (int) $eski->id;
                DB::table('on_gorusmeler')->where('id', $ogId)->update($alanlar);
            } else {
                $ogId = (int) DB::table('on_gorusmeler')->insertGetId($alanlar + ['created_at' => now()]);
            }

            [$randevuId, $yeniRh, $silinenRh] = $this->randevuYaz($salonId, $ogId, $randevuId, $userId, $personelId, $odaId, $tarih, $saat, $bitis, $yetkiliId);

            if ($zamanDegisti) {
                BildirimKuyrugu::yaz($salonId, 'ongorusme_kayit', $randevuId, $yetkiliId, ['on_gorusme_id' => $ogId, 'guncelleme' => (bool) $eski]);
            }
            BildirimKuyrugu::googleTakvim($salonId, $randevuId, $yeniRh, $silinenRh);

            return ['id' => $ogId, 'randevu_id' => $randevuId, 'ad' => $alanlar['ad_soyad']];
        });

        AktiviteLog::yaz($salonId, $yetkiliId, $eski ? 'ongorusme_guncelle' : 'ongorusme_ekle', 'ongorusme', $sonuc['id'], $sonuc['ad'],
            $eski ? 'Ön görüşme güncellendi' : 'Yeni ön görüşme oluşturuldu',
            ['tarih' => $tarih, 'saat' => $saat, 'neden' => $neden?->anahtar(), 'personel_id' => $personelId]);

        return ['cakismavar' => '0', 'cakisanunsurlar' => 'Başarılı', 'yazildi' => true, 'id' => $sonuc['id'], 'randevu_id' => $sonuc['randevu_id']];
    }

    /**
     * Neden bu salonun AKTİF ve SATILABİLİR kataloğunda olmalı — form listesiyle aynı kural:
     * paket aktif; ürün aktif + sarf değil (UrunTipi); hizmet salonda aktif sunuluyor.
     */
    public function nedenSalonaAitOlmali(GorusmeNedeni $neden, int $salonId): void
    {
        match ($neden->tur) {
            GorusmeNedeni::PAKET  => $this->paketler->satisTutari($neden->id, $salonId),
            GorusmeNedeni::URUN   => $this->stok->aitOlmali('urun', $neden->id, $salonId),
            GorusmeNedeni::HIZMET => $this->sahiplik->hizmetOlmali($neden->id, $salonId),
        };
        if ($neden->tur === GorusmeNedeni::URUN && ! UrunTipi::satilabilirMi(DB::table('urunler')->where('id', $neden->id)->value('tip'))) {
            throw new GecersizIslemException('Sarf malzemesi ön görüşme nedeni olamaz.');
        }
        if ($neden->tur === GorusmeNedeni::HIZMET
            && ! DB::table('salon_sunulan_hizmetler')->where('salon_id', $salonId)->where('hizmet_id', $neden->id)->where('aktif', 1)->exists()) {
            throw new GecersizIslemException('Hizmet bu işletmede aktif değil.');
        }
    }

    /** Düzenlenebilir (beklemede) kayıt; yoksa 404, sonuçlanmışsa 422. */
    private function acikKayit(int $id): object
    {
        $r = DB::table('on_gorusmeler')->where('id', $id)->first() ?? throw new BulunamadiException('Ön görüşme bulunamadı');
        if (! GorusmeDurumu::dbden($r->durum)->acikMi()) {
            throw new GecersizIslemException('Sonuçlanmış ön görüşme düzenlenemez.');
        }

        return $r;
    }

    private function randevuId(int $onGorusmeId): ?int
    {
        $r = DB::table('randevular')->where('on_gorusme_id', $onGorusmeId)->max('id');

        return $r ? (int) $r : null;
    }

    /** Oda yalnız takvim odaya göreyse (tür 3) yazılır ve orada ZORUNLUDUR (eski modal). */
    private function odaCoz(int $salonId, mixed $odaId): ?int
    {
        if ((int) DB::table('salonlar')->where('id', $salonId)->value('randevu_takvim_turu') !== 3) {
            return null;
        }
        if (! $odaId) {
            throw new GecersizIslemException('Oda seçiniz.');
        }
        RandevuOrtak::kaynaklarSalonaAit($salonId, ['oda_id' => (int) $odaId]);

        return (int) $odaId;
    }

    /** Seçili müşteri ya da telefonla bul/oluştur (portföye eklenir). */
    private function musteriCoz(int $salonId, array $v, int $yetkiliId): int
    {
        if (! empty($v['musteri_id'])) {
            return (int) $v['musteri_id'];
        }

        return $this->musteri->bulVeyaOlustur([
            'salon_id' => $salonId, 'yapan_id' => $yetkiliId, 'ad_soyad' => $v['ad_soyad'], 'telefon' => $v['telefon'] ?? null,
            'email' => $v['email'] ?? null, 'cinsiyet' => $v['cinsiyet'] ?? null, 'musteri_tipi' => $v['musteri_tipi'] ?? null,
        ])['id'];
    }

    /** Geçerli yeni numara → kanonik; boş / maskeli → mevcut kayıt ya da müşterinin numarası. */
    private function telefonCoz(?string $girilen, ?string $mevcut, int $userId): string
    {
        $tel = TelefonNumarasi::kanonik($girilen);
        if ($tel !== '' && ! Telefon::maskeliMi((string) $girilen)) {
            return $tel;
        }

        return (string) ($mevcut ?: DB::table('users')->where('id', $userId)->value('cep_telefon'));
    }

    /**
     * Takvimdeki ön görüşme randevusu: yoksa açılır, varsa AYNI randevu güncellenir. Tek
     * randevu_hizmetler satırı (hizmet_id = config 'randevu_hizmet_id', süre config).
     *
     * @return array{0:int, 1:int[], 2:int[]} [randevu id, yeni rh id'leri, silinen rh id'leri]
     */
    private function randevuYaz(int $salonId, int $ogId, ?int $randevuId, int $userId, ?int $personelId, ?int $odaId, string $tarih, string $saat, string $bitis, int $yetkiliId): array
    {
        $alanlar = ['user_id' => $userId, 'salon_id' => $salonId, 'tarih' => $tarih, 'saat' => $saat.':00', 'saat_bitis' => $bitis.':00', 'updated_at' => now()];
        $silinen = [];
        if ($randevuId) {
            DB::table('randevular')->where('id', $randevuId)->update($alanlar);
            $silinen = DB::table('randevu_hizmetler')->where('randevu_id', $randevuId)->pluck('id')->map(fn ($x) => (int) $x)->all();
            DB::table('randevu_hizmetler')->where('randevu_id', $randevuId)->delete();
        } else {
            $randevuId = (int) DB::table('randevular')->insertGetId($alanlar + [
                'on_gorusme_id' => $ogId, 'salon' => 1, 'sms_hatirlatma' => 1, 'durum' => 1,
                'olusturan_personel_id' => $yetkiliId, 'created_at' => now(),
            ]);
        }

        $rhId = (int) DB::table('randevu_hizmetler')->insertGetId([
            'randevu_id' => $randevuId, 'hizmet_id' => (int) config('ongorusme.randevu_hizmet_id', 1), 'personel_id' => $personelId,
            'oda_id' => $odaId, 'saat' => $saat.':00', 'saat_bitis' => $bitis.':00', 'sure_dk' => (int) config('ongorusme.sure_dk', 60),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$randevuId, [$rhId], $silinen];
    }
}
