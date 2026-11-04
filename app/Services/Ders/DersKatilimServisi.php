<?php

namespace App\Services\Ders;

use App\Domain\Ders\KatilimciDurumu;
use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Models\DersKatilimci;
use App\Models\DersOturumu;
use Illuminate\Support\Facades\DB;

/**
 * Oturuma katilim yasam dongusu: ekle (kapasite dolu → bekleme), cikar (bekleyen terfi),
 * durum (geldi/gelmedi/telafi/rezerve → seans etkisi). Panel, danisan ve online rezervasyon
 * AYNI cekirdegi kullanir. Her yazma oturum kilidi altinda (kapasite yarisi olmaz).
 */
class DersKatilimServisi
{
    public function __construct(
        private DersSeansServisi $seans,
        private DersBildirim $bildirim,
        private DersReferansServisi $referans,
    ) {}

    /**
     * Panelden ekleme: hak sartsiz; bekleyen telafi varsa baglanir; musteriye bildirim gider.
     *
     * @return array{katilimci_id:int,katilimci_durum:string,telafi_baglandi:bool}
     */
    public function ekle(int $oturumId, int $userId, ?int $ekleyenPersonelId, ?int $yetkiliId): array
    {
        return DB::transaction(function () use ($oturumId, $userId, $ekleyenPersonelId, $yetkiliId) {
            $oturum = $this->kilitliOturum($oturumId);
            $this->referans->musteriDogrula((int) $oturum->salon_id, $userId);
            $k = $this->kayitAc($oturum, $userId, ['ekleyen_personel_id' => $ekleyenPersonelId]);
            $durum = $k->durumu();
            $telafi = $durum !== KatilimciDurumu::BEKLEME && $this->seans->telafiBagla($oturum, $k);
            $this->bildirim->kayit($oturum, $userId, $durum, $yetkiliId);

            return ['katilimci_id' => (int) $k->id, 'katilimci_durum' => $k->durum, 'telafi_baglandi' => $telafi];
        });
    }

    /**
     * Online rezervasyon (musteri): kontenjan tutar, dusum yok (dusum "Geldi"de), bildirim yok.
     *
     * @return array{katilimci_id:int,katilimci_durum:string}
     */
    public function rezerveEt(int $oturumId, int $salonId, int $userId): array
    {
        return DB::transaction(function () use ($oturumId, $salonId, $userId) {
            $oturum = $this->kilitliOturum($oturumId);
            if ((int) $oturum->salon_id !== $salonId) {
                throw new BulunamadiException('Ders bulunamadı');
            }
            if ($oturum->basladiMi()) {
                throw new GecersizIslemException('Geçmiş bir derse rezervasyon yapılamaz.');
            }
            $k = $this->kayitAc($oturum, $userId, ['not' => 'online']);

            return ['katilimci_id' => (int) $k->id, 'katilimci_durum' => $k->durum];
        });
    }

    /** Dersten cikar: hak geri verilir (telafi korunur), kontenjan bosalir, ilk bekleyen terfi eder. */
    public function cikar(int $katilimciId, ?int $yetkiliId): array
    {
        return DB::transaction(function () use ($katilimciId, $yetkiliId) {
            [$k, $oturum] = $this->kilitliKatilimci($katilimciId);
            $dusum = $k->hak_dusuldu ? $this->seans->hakGeriVer($k) : null;
            $k->update(['durum' => KatilimciDurumu::IPTAL->value]);
            $this->terfiEt($oturum, $yetkiliId);

            return ['katilimci_id' => (int) $k->id, 'dusum' => $dusum];
        });
    }

    /** Elle durum isareti. Bekleme/iptal satiri isaretlenemez (kapasiteyi asmasin). */
    public function durumGuncelle(int $katilimciId, KatilimciDurumu $yeni, ?int $yetkiliId): array
    {
        return DB::transaction(function () use ($katilimciId, $yeni, $yetkiliId) {
            [$k, $oturum] = $this->kilitliKatilimci($katilimciId);

            return $this->isaretle($oturum, $k, $yeni, $yetkiliId);
        });
    }

    /** Danisanin kendi bildirimi: yalniz kendi kaydi, gerceklesmis ders, geldi|gelmedi. */
    public function danisanIsaretle(int $katilimciId, int $userId, KatilimciDurumu $yeni): array
    {
        return DB::transaction(function () use ($katilimciId, $userId, $yeni) {
            [$k, $oturum] = $this->kilitliKatilimci($katilimciId);
            if ((int) $k->user_id !== $userId) {
                throw new BulunamadiException('Kayıt bulunamadı.'); // kapsam sizintisi yok
            }
            if ($oturum->tarih > date('Y-m-d')) {
                throw new CakismaException('Ders henüz gerçekleşmedi.');
            }
            if ($k->durum === $yeni->value) {
                return ['katilimci_id' => (int) $k->id, 'durum' => $k->durum, 'dusum' => null]; // cift dokunus
            }
            // Isletme isaretlediyse danisan degistiremez (kendine seans iadesi yapamasin).
            if ($k->durumu() !== KatilimciDurumu::REZERVE) {
                throw new CakismaException('Katılım durumu işletme tarafından işaretlenmiş.');
            }

            return $this->isaretle($oturum, $k, $yeni, null);
        });
    }

    /** Bos kontenjan kadar bekleyeni sirayla rezerveye al + "yeriniz acildi". Cagiran kilidi tutar. */
    public function terfiEt(DersOturumu $oturum, ?int $yetkiliId): void
    {
        if ($oturum->iptalMi()) {
            return;
        }
        $bos = (int) $oturum->kapasite - $oturum->dolulukSay();
        if ($bos <= 0) {
            return;
        }
        $bekleyenler = DersKatilimci::where('oturum_id', $oturum->id)->where('durum', KatilimciDurumu::BEKLEME->value)->orderBy('id')->limit($bos)->get();
        foreach ($bekleyenler as $bekleyen) {
            $bekleyen->update(['durum' => KatilimciDurumu::REZERVE->value]);
            $this->bildirim->terfi($oturum, (int) $bekleyen->user_id, $yetkiliId);
        }
    }

    /**
     * Kontenjani degisen oturumlar icin terfi (kapasite artisi, plan degisikligi).
     *
     * @param  int[]  $oturumIdler
     */
    public function terfiler(array $oturumIdler, ?int $yetkiliId): void
    {
        if (! $oturumIdler) {
            return;
        }
        DB::transaction(function () use ($oturumIdler, $yetkiliId) {
            foreach (DersOturumu::whereIn('id', array_unique($oturumIdler))->lockForUpdate()->get() as $o) {
                $this->terfiEt($o, $yetkiliId);
            }
        });
    }

    private function isaretle(DersOturumu $oturum, DersKatilimci $k, KatilimciDurumu $yeni, ?int $yetkiliId): array
    {
        if ($oturum->iptalMi()) {
            throw new CakismaException('Bu ders iptal edilmiş.'); // iptalde satirlar 'rezerve' kalir
        }
        if (! $k->durumu()->kontenjanTutar()) {
            throw new CakismaException($k->durumu() === KatilimciDurumu::IPTAL ? 'Bu kayıt iptal edilmiş.' : 'Bekleme listesindeki kişi işaretlenemez.');
        }
        $k->update(['durum' => $yeni->value]);

        return ['katilimci_id' => (int) $k->id, 'durum' => $yeni->value, 'dusum' => $this->seans->durumaUygula($oturum, $k, $yeni, $yetkiliId)];
    }

    private function kayitAc(DersOturumu $oturum, int $userId, array $ek): DersKatilimci
    {
        if ($oturum->iptalMi()) {
            throw new CakismaException('Bu ders iptal edilmiş.');
        }
        $zatenVar = DersKatilimci::where('oturum_id', $oturum->id)->where('user_id', $userId)
            ->where('durum', '!=', KatilimciDurumu::IPTAL->value)->exists();
        if ($zatenVar) {
            throw new CakismaException('Bu kişi derse zaten kayıtlı.');
        }

        return DersKatilimci::create([
            'oturum_id' => $oturum->id, 'user_id' => $userId, 'salon_id' => $oturum->salon_id,
            'durum' => KatilimciDurumu::yeniKayit($oturum->dolulukSay(), (int) $oturum->kapasite)->value,
        ] + $ek);
    }

    private function kilitliOturum(int $oturumId): DersOturumu
    {
        return DersOturumu::whereKey($oturumId)->lockForUpdate()->first() ?? throw new BulunamadiException('Ders bulunamadı');
    }

    /** @return array{0:DersKatilimci,1:DersOturumu} */
    private function kilitliKatilimci(int $katilimciId): array
    {
        $oturumId = DersKatilimci::whereKey($katilimciId)->value('oturum_id') ?? throw new BulunamadiException('Katılımcı bulunamadı');
        $oturum = $this->kilitliOturum((int) $oturumId);

        return [DersKatilimci::findOrFail($katilimciId), $oturum];
    }
}
