<?php

namespace App\Services\Personel;

use App\Domain\Personel\GirisTelefonu;
use App\Domain\Personel\Telefon;
use App\Exceptions\GecersizIslemException;
use Illuminate\Support\Facades\DB;

/**
 * GirisTelefonu saf kuralinin olgu cozucusu — giris telefonu (gsm1) degisimi ve personel satirini mevcut bir giris
 * hesabina baglamanin TEK denetim noktasi. ProfilServisi (kendi numarasi) ve PersonelYazServisi (ekle/duzenle; web ve
 * mobil ayni servis) buradan gecer. Engel varsa 422; yazmadan ONCE cagrilir.
 */
class GirisTelefonuDenetimi
{
    /** Hesabin gsm1'i $tel'e cevrilecek: numara baska hesapta kayitliysa 422. */
    public function numaraDenetle(?int $hesapId, string $tel): void
    {
        $this->at(GirisTelefonu::numaraEngeli($hesapId, $this->numaraSahibi($tel, $hesapId)));
    }

    /**
     * Personel ekle/duzenle: satir ($oncekiHesapId: satirin su anki giris hesabi) $hesapId hesabiyla kaydedilecek ve
     * hesabin gsm1'i $tel olacak.
     *  - $hesapId != $oncekiHesapId: telefonla bulunan mevcut hesaba BAGLAMA — hesap baska salonda kayitliysa 422.
     *  - ayni hesap ve gercek (maskesiz) numara degisiyor: numara baska hesapta olmamali; hesap baska salonlarda da
     *    kayitliysa yalniz kendisi degistirir.
     */
    public function personelDenetle(int $salonId, int $hesapId, ?int $oncekiHesapId, string $gsm, string $tel, ?int $yapanId): void
    {
        if ($hesapId !== $oncekiHesapId) {
            $this->at(GirisTelefonu::baglamaEngeli($this->hesapSalonlari($hesapId), $salonId));

            return;
        }
        if ($tel === '' || Telefon::maskeliMi($tel) || Telefon::normalize($gsm) === $tel) {
            return; // gsm1 yazilmaz ya da degismez
        }
        $this->numaraDenetle($hesapId, $tel);
        $this->at(GirisTelefonu::degisimEngeli($this->hesapSalonlari($hesapId), $salonId, $yapanId !== null && $yapanId === $hesapId));
    }

    /** gsm1'i $tel olan (haric tutulan disinda) hesap; bos numara: null. */
    public function numaraSahibi(string $tel, ?int $haricHesapId = null): ?int
    {
        if ($tel === '') {
            return null;
        }
        $id = DB::table('isletmeyetkilileri')->where('gsm1', $tel)
            ->when($haricHesapId !== null, fn ($q) => $q->where('id', '!=', $haricHesapId))
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /** @return int[] hesabin personel (pasif/arsiv dahil) ya da rol satiri olan salonlar */
    public function hesapSalonlari(int $hesapId): array
    {
        $personel = DB::table('salon_personelleri')->where('yetkili_id', $hesapId)->pluck('salon_id')->all();
        $rol = DB::table('model_has_roles')->where('model_id', $hesapId)->pluck('salon_id')->all();

        return array_values(array_unique(array_map('intval', [...$personel, ...$rol])));
    }

    private function at(?string $engel): void
    {
        if ($engel !== null) {
            throw new GecersizIslemException($engel);
        }
    }
}
