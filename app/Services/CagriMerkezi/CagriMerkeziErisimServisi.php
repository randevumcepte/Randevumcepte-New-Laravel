<?php

namespace App\Services\CagriMerkezi;

use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Domain\CagriMerkezi\CagriMerkeziEylemi;
use App\Exceptions\BulunamadiException;
use App\Exceptions\YetkisizErisimException;
use App\Models\Personeller;
use App\Services\Santral\SantralErisimServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\KapsamServisi;
use App\Services\Yetki\YetkiServisi;
use Illuminate\Support\Facades\DB;

/**
 * Çağrı merkezi uçlarının ORTAK giriş denetimi — kimlik + kapsam + üyelik paketi + rol/dahili +
 * kayıt düzeyi (liste / aranacak müşteri) erişimi. Her uç bunu çağırır; controller'da tekrarlanmaz.
 *
 * Eskiye göre güvenlik farkları (v1 açıkları kapatıldı):
 *   - arama_liste_detay_getir / santral_not_ekle / cagri_musteri_gecmisi kimlik+salon denetimi
 *     YAPMIYORDU (liste id'si bilen herkes başka salonun listesini okuyup yazabiliyordu).
 *   - Personel yalnız KENDİNE atanan listenin kayıtlarını işler (eski cagriListeYetkiliMi yalnız
 *     salon üyeliğine bakıyordu).
 */
class CagriMerkeziErisimServisi
{
    public function __construct(
        private GuvenlikKapisi $kapi,
        private KapsamServisi $kapsam,
        private YetkiServisi $yetki,
    ) {}

    /** @throws YetkisizErisimException kapsam dışı salon / paket dışı salon */
    public function coz(int $yetkiliId, int $salonId): CagriMerkeziErisim
    {
        $this->kapi->kapsamDenetle($yetkiliId, $salonId);

        // Eski 4 sayfa da uyelik_turu === 3 (Premium) şartıyla açılıyordu; Flutter menü kapısı da aynı.
        if ((int) DB::table('salonlar')->where('id', $salonId)->value('uyelik_turu') !== 3) {
            throw new YetkisizErisimException('Çağrı merkezi bu işletmenin paketinde yok');
        }

        $roller = $this->kapsam->salonRolleri($yetkiliId, $salonId);
        $personel = Personeller::where('salon_id', $salonId)->where('yetkili_id', $yetkiliId)
            ->first(['id', 'dahili_no', 'dahili_no_webrtc']);

        $yetkiler = [];
        foreach (CagriMerkeziEylemi::cases() as $eylem) {
            if ($eylem->anahtarlar() !== []) {
                $yetkiler[$eylem->value] = array_filter($eylem->anahtarlar(),
                    fn ($anahtar) => $this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, $anahtar)) !== [];
            }
        }

        return new CagriMerkeziErisim(
            salonId: $salonId,
            yetkiliId: $yetkiliId,
            yonetici: ! in_array(5, $roller, true),
            personelId: $personel ? (int) $personel->id : null,
            dahililer: SantralErisimServisi::dahililer($personel ? [$personel] : []),
            telefonGor: $this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, 'musteri.telefon_gor'),
            yetkiler: $yetkiler,
        );
    }

    /** Eylem kapısı (rol + yetki anahtarı; kural CagriMerkeziEylemi'nde). */
    public function eylemZorunlu(CagriMerkeziErisim $e, CagriMerkeziEylemi $eylem): void
    {
        if (! $e->yapabilir($eylem)) {
            throw new YetkisizErisimException($eylem->redMesaji());
        }
    }

    /**
     * Arama listesi — bu salonun olmalı (değilse 404, kapsam sızıntısı yok) + kullanıcı erişmeli.
     */
    public function liste(int $listeId, CagriMerkeziErisim $e): object
    {
        $liste = DB::table('arama_listesi')->where('id', $listeId)->first();
        if (! $liste || (int) $liste->salon_id !== $e->salonId) {
            throw new BulunamadiException('Arama listesi bulunamadı');
        }
        if (! $e->listeyeErisir($liste->personel_id !== null ? (int) $liste->personel_id : null)) {
            throw new YetkisizErisimException('Bu listeye erişim yetkiniz yok');
        }

        return $liste;
    }

    /**
     * Aranacak müşteri kaydı + listesinin salon/personel bilgisi (am.*, liste_salon_id, liste_personel_id).
     * $kilitli = true → transaction içinde FOR UPDATE (yazma akışları).
     */
    public function kayit(int $aranacakId, CagriMerkeziErisim $e, bool $kilitli = false): object
    {
        $q = DB::table('aranacak_musteriler as am')->join('arama_listesi as al', 'al.id', '=', 'am.arama_id')
            ->where('am.id', $aranacakId)
            ->select('am.*', 'al.salon_id as liste_salon_id', 'al.personel_id as liste_personel_id', 'al.arama_baslik as liste_baslik');
        if ($kilitli) {
            $q->lockForUpdate();
        }
        $k = $q->first();
        if (! $k || (int) $k->liste_salon_id !== $e->salonId) {
            throw new BulunamadiException('Arama kaydı bulunamadı');
        }
        if (! $e->listeyeErisir($k->liste_personel_id !== null ? (int) $k->liste_personel_id : null)) {
            throw new YetkisizErisimException('Bu kaydı işleme yetkiniz yok');
        }

        return $k;
    }

    /** Personel bu salona ait mi? (atama hedefleri) */
    public function personel(int $personelId, CagriMerkeziErisim $e): object
    {
        $p = DB::table('salon_personelleri')->where('id', $personelId)->where('salon_id', $e->salonId)->first(['id', 'personel_adi']);
        if (! $p) {
            throw new BulunamadiException('Geçerli bir personel seçin');
        }

        return $p;
    }
}
