<?php

namespace App\Services\Ders;

use App\Domain\Ders\KatilimciDurumu;
use App\Domain\Ders\SeansEtkisi;
use App\Domain\Seans\SeansDurumu;
use App\Models\AdisyonPaketSeanslar;
use App\Models\DersKatilimci;
use App\Models\DersOturumu;
use App\Models\Salonlar;
use App\Services\Seans\SeansHavuzu;
use Illuminate\Support\Facades\DB;

/**
 * Grup dersi ↔ seans hakki (adisyon_paket_seanslar). Kalan her yerde tutarli olsun diye
 * randevuyla AYNI tabloya yazar; aday secimi SeansHavuzu'ndan.
 *
 * Cagiranlar transaction + kilit icinde cagirir (DersKatilimServisi). Eski koddaki
 * try/catch yutma yok: dusum patlarsa durum da yazilmaz.
 *
 * TELAFI KORUMASI (randevu SeansDurumGecisi ile ayni ilke): iade yollarinda geldi=2 satiri
 * SILINMEZ; tarihi gecmise alinip bag cozulur, hak tekrar "bekleyen" olur. Eski kod ders
 * iptalinde ve Rezerve'ye donuste telafi hakkini siliyordu.
 */
class DersSeansServisi
{
    /** Bagi cozulen telafinin tarihi: yeni dersten once kalir, bekleyenTelafi yeniden bulur. */
    private const BOSTA_TELAFI_TARIHI = '2000-01-01';

    public const DUSULDU = 'dusuldu';

    public const HAK_YOK = 'hak_yok';

    public const HIZMET_BAGLI_DEGIL = 'hizmet_bagli_degil';

    public const IADE = 'iade';

    public const TELAFI_KORUNDU = 'telafi_korundu';

    public function __construct(
        private SeansHavuzu $havuz,
        private DersBildirim $bildirim,
    ) {}

    /** Yeni duruma gore dus ya da iade et. Donus: yukaridaki sabitler ya da null (etki yok). */
    public function durumaUygula(DersOturumu $o, DersKatilimci $k, KatilimciDurumu $yeni, ?int $yetkiliId, bool $telafiKoru = true): ?string
    {
        $geldi = SeansEtkisi::geldiDegeri($yeni, Salonlar::studyoModu((int) $o->salon_id));
        if ($geldi === null) {
            return $k->hak_dusuldu ? $this->hakGeriVer($k, $telafiKoru) : null;
        }

        return $this->dus($o, $k, $geldi, $yetkiliId);
    }

    /** Kapasite acildiysa eklenen katilimciya bekleyen telafiyi bagla (yeni seans dusmez). */
    public function telafiBagla(DersOturumu $o, DersKatilimci $k): bool
    {
        $hizmetId = (int) $o->hizmet_id;
        $telafiId = $hizmetId > 0 ? $this->havuz->bekleyenTelafi((int) $o->salon_id, (int) $k->user_id, $hizmetId, $o->tarih) : null;
        if (! $telafiId) {
            return false;
        }
        AdisyonPaketSeanslar::whereKey($telafiId)->update([
            'seans_tarih' => $o->tarih, 'seans_saat' => $o->saat, 'personel_id' => $o->personel_id,
        ]);
        // Hak artik bu derste: telafinin isaretlendigi eski katilimci ayni satiri tutmaya devam etmesin.
        DersKatilimci::where('aps_id', $telafiId)->update(['aps_id' => null, 'hak_dusuldu' => false]);
        $k->update(['aps_id' => $telafiId, 'hak_dusuldu' => true, 'durum' => KatilimciDurumu::TELAFI->value, 'not' => DersKatilimci::NOT_TELAFI_BAGLI]);

        return true;
    }

    /**
     * Dusumu geri al: normal seans silinir (hak geri doner), telafi korunur.
     * $telafiKoru=false: mobilin elle durum degisikligindeki bugunku davranis, telafi de silinir.
     */
    public function hakGeriVer(DersKatilimci $k, bool $telafiKoru = true): string
    {
        $aps = $k->aps_id ? AdisyonPaketSeanslar::find($k->aps_id) : null;
        $telafi = $telafiKoru && $aps && (int) $aps->geldi === SeansDurumu::TELAFI->geldi();
        if ($telafi) {
            $aps->update(['seans_tarih' => self::BOSTA_TELAFI_TARIHI]);
        } else {
            $aps?->delete();
        }
        $k->update(['aps_id' => null, 'hak_dusuldu' => false]);

        return $telafi ? self::TELAFI_KORUNDU : self::IADE;
    }

    public function hakVarMi(int $salonId, int $userId, ?int $hizmetId): bool
    {
        return $this->havuz->hakVarMi($salonId, $userId, (int) $hizmetId);
    }

    private function dus(DersOturumu $o, DersKatilimci $k, int $geldi, ?int $yetkiliId): string
    {
        // Zaten dusulmus: tekrar dusme, yalniz isareti guncelle (satir Seans Takibi'nden silinmisse yeniden dus).
        // (update() sonucuna bakilmaz: deger degismezse MySQL 0 doner, cift dusum olurdu.)
        if ($k->hak_dusuldu && $k->aps_id && AdisyonPaketSeanslar::whereKey($k->aps_id)->exists()) {
            AdisyonPaketSeanslar::whereKey($k->aps_id)->update(['geldi' => $geldi]);

            return self::DUSULDU;
        }

        $hizmetId = (int) $o->hizmet_id;
        if ($hizmetId <= 0) {
            return self::HIZMET_BAGLI_DEGIL;
        }
        $salonId = (int) $o->salon_id;
        $userId = (int) $k->user_id;
        // Oturum kilidi yetmez, havuz musteri bazli: es zamanli iki ders son seansi birlikte secmesin.
        DB::table('adisyonlar')->where('user_id', $userId)->where('salon_id', $salonId)->lockForUpdate()->pluck('id');
        $rezerve = [];
        $aday = $this->havuz->yeniAday($salonId, $userId, $hizmetId, $rezerve, (string) $o->tarih);
        if (! $aday) {
            $k->update(['aps_id' => null, 'hak_dusuldu' => false]);

            return self::HAK_YOK;
        }

        $aps = AdisyonPaketSeanslar::create([
            'seans_tarih' => $o->tarih, 'seans_saat' => $o->saat, 'personel_id' => $o->personel_id,
            'randevu_id' => null, 'hizmet_id' => $hizmetId, 'seans_no' => $aday['seans_no'],
            'adisyon_hizmet_id' => $aday['adisyon_hizmet_id'] ?? null, 'adisyon_paket_id' => $aday['adisyon_paket_id'] ?? null,
            'geldi' => $geldi, 'dusulen_miktar' => 1,
        ]);
        $k->update(['aps_id' => $aps->id, 'hak_dusuldu' => true]);
        $this->bildirim->kalanUyari($salonId, $userId, $hizmetId, $this->havuz->kalanToplam($salonId, $userId, $hizmetId), $yetkiliId);

        return self::DUSULDU;
    }
}
