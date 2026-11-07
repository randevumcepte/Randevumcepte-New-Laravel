<?php

namespace App\Services\Tahsilat\Mobil;

use App\Domain\Stok\HareketTipi;
use App\Domain\Tahsilat\MobilTahsilatHesabi as Hesap;
use App\Exceptions\GecersizIslemException;
use App\Models\AdisyonHizmetler;
use App\Models\Adisyonlar;
use App\Models\AdisyonPaketler;
use App\Models\AdisyonUrunler;
use App\Models\TahsilatHizmetler;
use App\Models\TahsilatPaketler;
use App\Models\TahsilatUrunler;
use App\Models\Urunler;
use App\Services\Stok\ReceteUygulamaServisi;
use App\Services\Stok\StokHareketServisi;
use App\Support\AktiviteLog;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Eski mobil stok hizli satis (ekrandaki birim fiyat gecer) ve cagri merkezi telefonda satis. Web HizliSatisServisi ve
 * TelefondaSatisServisi'ne dokunulmaz: onlar katalog fiyati kullanir ve tahsilat aktarimi farklidir.
 */
class MobilHizliSatisServisi
{
    public function __construct(
        private MobilKapsam $kapsam,
        private MobilTahsilatYazici $yazici,
        private StokHareketServisi $stok,
        private ReceteUygulamaServisi $recete,
        private MobilHariciTahsilatServisi $katalog,
    ) {}

    public function stokSatisi(int $salonId, int $yetkiliId, array $g): array
    {
        $sepet = $g['sepet'] ?? [];
        if (is_string($sepet)) {
            $sepet = json_decode($sepet, true) ?: [];
        }
        if (! is_array($sepet) || count($sepet) === 0) {
            throw new GecersizIslemException('Sepet boş');
        }
        $musteriId = ($g['musteri_id'] ?? null) ?: ($g['user_id'] ?? null);
        if (! $musteriId) {
            throw new GecersizIslemException('Müşteri seçilmedi');
        }
        $this->kapsam->musteriOlmali($salonId, $musteriId);
        $this->kapsam->personelOlmali($salonId, $g['personel_id'] ?? null);

        return Idempotency::calistir('mobil_stok_hizli_satis', (string) ($g['idempotency_key'] ?? ''), $yetkiliId, $salonId,
            fn () => $this->stokYaz($salonId, $yetkiliId, (int) $musteriId, $sepet, $g));
    }

    private function stokYaz(int $salonId, int $yetkiliId, int $musteriId, array $sepet, array $g): array
    {
        $tarih = ($g['satis_tarihi'] ?? null) ?: now()->toDateString();
        $adisyon = Adisyonlar::ac($salonId, $musteriId, $yetkiliId, $tarih);
        $batch = (string) Str::uuid();
        $kalemler = [];
        $toplam = 0;

        foreach ($sepet as $k) {
            $urun = ! empty($k['urun_id']) ? Urunler::where('id', $k['urun_id'])->where('salon_id', $salonId)->first() : null;
            $satir = Hesap::hizliSatisSatiri($k['birim_fiyat'] ?? null, $urun?->fiyat, $k['miktar'] ?? 1);
            if (! $urun || $satir['adet'] <= 0) {
                continue;
            }

            $u = new AdisyonUrunler();
            $u->islem_tarihi = $tarih;
            $u->adisyon_id = $adisyon->id;
            $u->urun_id = $urun->id;
            $u->personel_id = ($g['personel_id'] ?? null) ?: null;
            $u->adet = $satir['adet'];
            $u->fiyat = $satir['tutar'];
            $u->save();

            $this->stok->kaydet([
                'salon_id' => $salonId, 'urun_id' => $urun->id, 'depo_id' => $k['depo_id'] ?? $urun->varsayilan_depo_id,
                'miktar' => $satir['adet'], 'tip' => HareketTipi::Satis, 'referans_tip' => 'adisyon_urun', 'referans_id' => $u->id, 'batch_uuid' => $batch,
                'birim_satis_fiyati' => $satir['birim'], 'birim_alis_fiyati' => $urun->alis_fiyati, 'aciklama' => 'Hızlı satış', 'kullanici_id' => $yetkiliId,
            ]);

            $kalemler[$u->id] = $satir['tutar'];
            $toplam += $satir['tutar'];
        }

        $tahsilatId = null;
        $tahsilatYap = filter_var($g['tahsilat_yap'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if ($tahsilatYap && $toplam > 0) {
            $odeme = ($g['odeme_yontemi_id'] ?? null) ?: (($g['odeme_yontemi'] ?? null) ?: null);
            $t = $this->yazici->tahsilatYaz([
                'salon_id' => $salonId, 'yetkili_id' => $yetkiliId, 'adisyon_id' => $adisyon->id, 'musteri_id' => $musteriId, 'tutar' => $toplam,
                'komisyon' => null, 'odeme_tarihi' => $tarih, 'odeme_yontemi' => $odeme, 'notlar' => 'Hızlı satış',
            ]);
            $tahsilatId = (string) $t->id;
            foreach ($kalemler as $kalemId => $tutar) {
                $o = new TahsilatUrunler();
                $o->adisyon_urun_id = $kalemId;
                $o->tahsilat_id = $t->id;
                $o->tutar = $tutar;
                $o->salon_id = $salonId;
                $o->user_id = $musteriId;
                $o->save();
            }
        }

        return ['status' => 'ok', 'batch_uuid' => $batch, 'adisyon_id' => (string) $adisyon->id, 'tahsilat_id' => $tahsilatId, 'toplam_tutar' => (string) $toplam];
    }

    /** Eski cagri_satis_kalemler: harici katalogla ayni, odeme yontemleri eklenir. */
    public function cagriKalemleri(int $salonId): array
    {
        return $this->katalog->katalog($salonId) + [
            'odeme_yontemleri' => DB::table('odeme_yontemleri')->select('id', 'odeme_yontemi as ad')->orderBy('id')->get(),
        ];
    }

    public function salonPremiumMu(int $salonId): bool
    {
        return (int) DB::table('salonlar')->where('id', $salonId)->value('uyelik_turu') === 3;
    }

    /** Eksik alan cevabi eskisi gibi 200 + success:false; sheet `message` gosterir. */
    public function cagriSatisi(int $salonId, int $yetkiliId, array $g): array
    {
        $tip = $g['kalem_tip'] ?? null;
        $itemId = $g['kalem_id'] ?? null;
        $fiyat = Hesap::telefonFiyati($g['fiyat'] ?? null);
        $odeme = $g['odeme_yontemi'] ?? null;
        $musteriId = $g['musteri_id'] ?? null;
        if (empty($musteriId) || empty($itemId) || $fiyat <= 0 || empty($odeme) || ! in_array($tip, ['hizmet', 'urun', 'paket'], true)) {
            return ['success' => false, 'message' => 'Müşteri, kalem (hizmet/ürün/paket), fiyat ve ödeme yöntemi zorunludur.'];
        }
        $this->kapsam->musteriOlmali($salonId, $musteriId);
        $this->kapsam->katalogOlmali($salonId, [['tip' => $tip, 'id' => $itemId]]);

        $sonuc = Idempotency::calistir('mobil_cagri_hizli_satis', (string) ($g['idempotency_key'] ?? ''), $yetkiliId, $salonId,
            fn () => $this->cagriYaz($salonId, $yetkiliId, (int) $musteriId, (string) $tip, $itemId, $fiyat, $odeme, max(1, (int) ($g['adet'] ?? 1))));

        if (! $sonuc['replay']) {
            $ad = DB::table('users')->where('id', $musteriId)->value('name') ?: 'Müşteri #'.$musteriId;
            AktiviteLog::yaz($salonId, $yetkiliId, 'cagri_hizli_satis', 'adisyon', (int) $sonuc['adisyon_id'], $ad.' — '.number_format($fiyat, 2, ',', '.').' ₺', 'Telefonda satış (çağrı merkezi)');
        }

        return $sonuc;
    }

    private function cagriYaz(int $salonId, int $yetkiliId, int $musteriId, string $tip, mixed $itemId, float $fiyat, mixed $odeme, int $adet): array
    {
        $tarih = date('Y-m-d');
        $adisyonId = (int) Adisyonlar::ac($salonId, $musteriId, $yetkiliId, $tarih)->id;

        if ($tip === 'hizmet') {
            $kalemId = $this->cagriHizmeti($adisyonId, $itemId, $fiyat, $tarih, 1);
            // Eski kod gibi: recete hatasi satisi ve tahsilati geri almaz.
            try {
                $this->recete->uygula($salonId, (int) $itemId, 'islem_recete', null, null, 'personel');
            } catch (\Throwable $e) {
                report($e);
            }
            [$model, $fk] = [TahsilatHizmetler::class, 'adisyon_hizmet_id'];
        } elseif ($tip === 'urun') {
            $u = new AdisyonUrunler();
            $u->adisyon_id = $adisyonId;
            $u->urun_id = $itemId;
            $u->adet = $adet;
            $u->fiyat = $fiyat;
            $u->indirim_tutari = 0;
            $u->save();
            $kalemId = (int) $u->id;
            [$model, $fk] = [TahsilatUrunler::class, 'adisyon_urun_id'];
        } else {
            $hizmetler = DB::table('paket_hizmetler')->where('paket_id', $itemId)->get(['hizmet_id', 'seans']);
            $toplamSeans = (int) $hizmetler->sum(fn ($h) => (int) ($h->seans ?? 0));
            $p = new AdisyonPaketler();
            $p->adisyon_id = $adisyonId;
            $p->paket_id = $itemId;
            $p->fiyat = $fiyat;
            $p->indirim_tutari = 0;
            $p->seans_sayisi = $toplamSeans;
            $p->bekleyen_seans = $toplamSeans;
            $p->kullanilan_seans = 0;
            $p->kullanilmayan_seans = 0;
            $p->otomatik_randevu_olusturuldu = false;
            $p->baslangic_tarihi = $tarih;
            $p->seans_araligi = 7;
            $p->save();
            foreach ($hizmetler as $h) {
                $this->cagriHizmeti($adisyonId, $h->hizmet_id, 0, $tarih, max(1, (int) ($h->seans ?? 1)));
            }
            $kalemId = (int) $p->id;
            [$model, $fk] = [TahsilatPaketler::class, 'adisyon_paket_id'];
        }

        $t = $this->yazici->tahsilatYaz([
            'salon_id' => $salonId, 'yetkili_id' => $yetkiliId, 'adisyon_id' => $adisyonId, 'musteri_id' => $musteriId, 'tutar' => $fiyat,
            'komisyon' => null, 'odeme_tarihi' => $tarih, 'odeme_yontemi' => $odeme, 'notlar' => 'Telefonda satış (çağrı merkezi)',
        ]);
        $o = new $model();
        $o->{$fk} = $kalemId;
        $o->tahsilat_id = $t->id;
        $o->tutar = $fiyat;
        $o->save();

        return ['success' => true, 'message' => 'Satış oluşturuldu ve kasaya işlendi.', 'adisyon_id' => $adisyonId];
    }

    private function cagriHizmeti(int $adisyonId, mixed $hizmetId, float $fiyat, string $tarih, int $seans): int
    {
        $h = new AdisyonHizmetler();
        $h->adisyon_id = $adisyonId;
        $h->hizmet_id = $hizmetId;
        $h->fiyat = $fiyat;
        $h->islem_tarihi = $tarih;
        $h->islem_saati = date('H:i:s');
        $h->seans_sayisi = $seans;
        $h->bekleyen_seans = $seans;
        $h->kullanilan_seans = 0;
        $h->kullanilmayan_seans = 0;
        $h->indirim_tutari = 0;
        $h->otomatik_randevu_olusturuldu = false;
        $h->save();

        return (int) $h->id;
    }
}
