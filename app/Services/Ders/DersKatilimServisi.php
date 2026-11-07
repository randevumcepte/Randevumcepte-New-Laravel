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
    public const ZATEN_KAYITLI = 'Bu kişi derse zaten kayıtlı.';

    public const ZATEN_EKLI_MOBIL = 'Bu musteri zaten ekli.';

    private const ZATEN_KAYITLI_DANISAN_MOBIL = 'Bu derse zaten kayıtlısınız.';

    private const DERS_YOK_DANISAN_MOBIL = 'Ders bulunamadı veya iptal edilmiş.';

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
    public function ekle(int $oturumId, int $userId, ?int $ekleyenPersonelId, ?int $yetkiliId, string $zatenKayitliMesaj = self::ZATEN_KAYITLI): array
    {
        return DB::transaction(function () use ($oturumId, $userId, $ekleyenPersonelId, $yetkiliId, $zatenKayitliMesaj) {
            $oturum = $this->kilitliOturum($oturumId);
            $this->referans->musteriDogrula((int) $oturum->salon_id, $userId);
            $k = $this->kayitAc($oturum, $userId, ['ekleyen_personel_id' => $ekleyenPersonelId], $zatenKayitliMesaj);
            $durum = $k->durumu();
            $telafi = $durum !== KatilimciDurumu::BEKLEME && $this->seans->telafiBagla($oturum, $k);
            $this->bildirim->kayit($oturum, $userId, $durum, $yetkiliId);

            return ['katilimci_id' => (int) $k->id, 'katilimci_durum' => $k->durum, 'telafi_baglandi' => $telafi];
        });
    }

    /**
     * Online rezervasyon (musteri): kontenjan tutar, dusum yok (dusum "Geldi"de), bildirim yok.
     * $mobilMetni: mobil uygulamanin bugunku hata metinleri.
     *
     * @return array{katilimci_id:int,katilimci_durum:string}
     */
    public function rezerveEt(int $oturumId, int $salonId, int $userId, bool $mobilMetni = false): array
    {
        return DB::transaction(function () use ($oturumId, $salonId, $userId, $mobilMetni) {
            $oturum = DersOturumu::whereKey($oturumId)->lockForUpdate()->first();
            if (! $oturum || (int) $oturum->salon_id !== $salonId) {
                throw new BulunamadiException($mobilMetni ? self::DERS_YOK_DANISAN_MOBIL : 'Ders bulunamadı');
            }
            if ($mobilMetni && $oturum->iptalMi()) {
                throw new BulunamadiException(self::DERS_YOK_DANISAN_MOBIL);
            }
            if ($oturum->basladiMi()) {
                throw new GecersizIslemException('Geçmiş bir derse rezervasyon yapılamaz.');
            }
            $k = $this->kayitAc($oturum, $userId, ['not' => 'online'], $mobilMetni ? self::ZATEN_KAYITLI_DANISAN_MOBIL : self::ZATEN_KAYITLI);

            return ['katilimci_id' => (int) $k->id, 'katilimci_durum' => $k->durum];
        });
    }

    /**
     * Dersten cikar: hak geri verilir (telafi korunur), kontenjan bosalir, ilk bekleyen terfi eder.
     * $mobilTerfi: mobilin bugunku davranisi, tek kisi terfi eder ve mobil metni gider.
     */
    public function cikar(int $katilimciId, ?int $yetkiliId, bool $mobilTerfi = false): array
    {
        return DB::transaction(function () use ($katilimciId, $yetkiliId, $mobilTerfi) {
            [$k, $oturum] = $this->kilitliKatilimci($katilimciId);
            $dusum = $k->hak_dusuldu ? $this->seans->hakGeriVer($k) : null;
            $k->update(['durum' => KatilimciDurumu::IPTAL->value]);
            $this->terfiEt($oturum, $yetkiliId, $mobilTerfi);

            return ['katilimci_id' => (int) $k->id, 'dusum' => $dusum];
        });
    }

    /**
     * Elle durum isareti. Bekleme/iptal satiri isaretlenemez (kapasiteyi asmasin).
     * $mobilKurali: mobilin bugunku davranisi, her satir isaretlenir ve iade telafiyi de siler.
     */
    public function durumGuncelle(int $katilimciId, KatilimciDurumu $yeni, ?int $yetkiliId, bool $mobilKurali = false): array
    {
        return DB::transaction(function () use ($katilimciId, $yeni, $yetkiliId, $mobilKurali) {
            [$k, $oturum] = $this->kilitliKatilimci($katilimciId);

            return $this->isaretle($oturum, $k, $yeni, $yetkiliId, $mobilKurali);
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
    public function terfiEt(DersOturumu $oturum, ?int $yetkiliId, bool $mobilTerfi = false): void
    {
        if ($oturum->iptalMi()) {
            return;
        }
        $bos = (int) $oturum->kapasite - $oturum->dolulukSay();
        if ($bos <= 0) {
            return;
        }
        $bekleyenler = DersKatilimci::where('oturum_id', $oturum->id)->where('durum', KatilimciDurumu::BEKLEME->value)->orderBy('id')->limit($mobilTerfi ? 1 : $bos)->get();
        foreach ($bekleyenler as $bekleyen) {
            $bekleyen->update(['durum' => KatilimciDurumu::REZERVE->value]);
            $this->bildirim->terfi($oturum, (int) $bekleyen->user_id, $yetkiliId, $mobilTerfi);
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

    private function isaretle(DersOturumu $oturum, DersKatilimci $k, KatilimciDurumu $yeni, ?int $yetkiliId, bool $mobilKurali = false): array
    {
        if (! $mobilKurali) {
            if ($oturum->iptalMi()) {
                throw new CakismaException('Bu ders iptal edilmiş.'); // iptalde satirlar 'rezerve' kalir
            }
            if (! $k->durumu()->kontenjanTutar()) {
                throw new CakismaException($k->durumu() === KatilimciDurumu::IPTAL ? 'Bu kayıt iptal edilmiş.' : 'Bekleme listesindeki kişi işaretlenemez.');
            }
        }
        $k->update(['durum' => $yeni->value]);

        return ['katilimci_id' => (int) $k->id, 'durum' => $yeni->value, 'dusum' => $this->seans->durumaUygula($oturum, $k, $yeni, $yetkiliId, ! $mobilKurali)];
    }

    private function kayitAc(DersOturumu $oturum, int $userId, array $ek, string $zatenKayitliMesaj = self::ZATEN_KAYITLI): DersKatilimci
    {
        if ($oturum->iptalMi()) {
            throw new CakismaException('Bu ders iptal edilmiş.');
        }
        $zatenVar = DersKatilimci::where('oturum_id', $oturum->id)->where('user_id', $userId)
            ->where('durum', '!=', KatilimciDurumu::IPTAL->value)->exists();
        if ($zatenVar) {
            throw new CakismaException($zatenKayitliMesaj);
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
