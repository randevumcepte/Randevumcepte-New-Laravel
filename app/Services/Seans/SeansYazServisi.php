<?php

namespace App\Services\Seans;

use App\Domain\Seans\SeansDurumu;
use App\Domain\Seans\SeansKalemTuru;
use App\Domain\Seans\SeansSayaci;
use App\Exceptions\GecersizIslemException;
use App\Models\AdisyonPaketSeanslar;
use App\Services\Paket\PaketKullanimi;
use Illuminate\Support\Facades\DB;

/**
 * Seans takibi YAZMA — bos daireye kullanim ekleme ve mevcut seansin durumunu
 * degistirme. Eski web/v1 seansEkle + seansGuncelle'nin TEK karsiligi.
 *
 * Eski uclardan BILINCLI farklar (v2 duzeltmesi):
 *   - Hizmetin o satista olmasi ve seans hakkinin dolmamis olmasi denetlenir
 *     (eski uc her istegi yaziyordu; cift tikta fazla seans olusuyordu).
 *   - Eski web seansGuncelle, istekteki degil MEVCUT geldi'ye bakiyordu: PHP 7'de
 *     0 == '' oldugu icin "Gelmedi" seansina yapilan HER guncelleme satiri siliyordu.
 *   - "Beklemede": randevuya bagli seans silinmez, isareti kaldirilir (geldi=null);
 *     silinseydi randevunun paket bagi kopardi. Sayaclar iki yolda da ayni.
 */
class SeansYazServisi
{
    public function __construct(private SeansKapsam $kapsam, private PaketKullanimi $kullanim) {}

    /** @return array{id:int, geldi:?int, durum:string} */
    public function ekle(SeansKalemTuru $tur, object $kalem, int $hizmetId, SeansDurumu $durum, string $tarih): array
    {
        if ($durum === SeansDurumu::BEKLEMEDE) {
            throw new GecersizIslemException('Yeni seans için Geldi, Gelmedi veya Telafi seçin');
        }
        if (! array_key_exists($hizmetId, $this->kapsam->hizmetler($tur, $kalem))) {
            throw new GecersizIslemException('Bu hizmet seçilen paket / hizmet satışında yok');
        }

        if ($tur === SeansKalemTuru::PAKET) {
            $this->telafiDenetle((int) $kalem->id, $durum);
            if (! $this->kullanim->seansUygun((int) $kalem->id, $hizmetId, $tarih)) {
                throw new GecersizIslemException('Paketin geçerlilik süresi dolmuş veya günlük kullanım limiti aşılmış');
            }
        }

        $seans = DB::transaction(function () use ($tur, $kalem, $hizmetId, $durum, $tarih) {
            // Ayni kalem icin es zamanli eklemeler sirayla — hak denetimi yarissiz.
            $toplam = (int) DB::table($tur->tablo())->where('id', $kalem->id)->lockForUpdate()->value('seans_sayisi');
            $mevcut = DB::table('adisyon_paket_seanslar')->where($tur->seansKolonu(), $kalem->id)
                ->when($tur === SeansKalemTuru::PAKET, fn ($q) => $q->where('hizmet_id', $hizmetId))
                ->count();
            if (SeansSayaci::bosHak($toplam, $mevcut) === 0) {
                throw new GecersizIslemException('Bu hizmetin tüm seans hakları kullanılmış');
            }

            return AdisyonPaketSeanslar::create([
                $tur->seansKolonu() => $kalem->id,
                'hizmet_id'         => $hizmetId,
                'seans_tarih'       => $tarih,
                'geldi'             => $durum->geldi(),
            ]);
        });

        return ['id' => (int) $seans->id, 'geldi' => $durum->geldi(), 'durum' => $durum->value];
    }

    /** @return array{id:int, silindi:bool, geldi:?int, durum:string} */
    public function durumGuncelle(object $seans, SeansDurumu $durum): array
    {
        if ($seans->adisyon_paket_id ?? null) {
            $this->telafiDenetle((int) $seans->adisyon_paket_id, $durum);
        }
        $silindi = $durum === SeansDurumu::BEKLEMEDE && ! $seans->randevu_id;
        $sorgu = AdisyonPaketSeanslar::whereKey($seans->id);
        $silindi ? $sorgu->delete() : $sorgu->update(['geldi' => $durum->geldi()]);

        return ['id' => (int) $seans->id, 'silindi' => $silindi, 'geldi' => $durum->geldi(), 'durum' => $durum->value];
    }

    /** Sinirsiz pakette telafi anlamsiz: eksilen hak yok ki telafisi olsun. */
    private function telafiDenetle(int $adisyonPaketId, SeansDurumu $durum): void
    {
        if ($durum === SeansDurumu::TELAFI && ($this->kullanim->durum($adisyonPaketId)['sinirsiz'] ?? false)) {
            throw new GecersizIslemException('Sınırsız pakette telafi kullanılmaz');
        }
    }
}
