<?php

namespace App\Services\Ders;

use App\Domain\Ders\DersMesaji;
use App\Domain\Ders\KatilimciDurumu;
use App\Models\DersOturumu;
use App\Support\BildirimKuyrugu;
use Illuminate\Support\Facades\DB;

/**
 * Grup dersi bildirimleri — v2 gondermez, outbox'a yazar ('ders_bildirim'); eski monolit
 * DersBildirimServisi ile WA-first/SMS gonderir. Cagiran transaction icindeyse kayit
 * onunla birlikte commit/rollback olur (hayalet bildirim gitmez).
 */
class DersBildirim
{
    public function kayit(DersOturumu $o, int $userId, KatilimciDurumu $durum, ?int $yetkiliId): void
    {
        $metin = $durum === KatilimciDurumu::BEKLEME ? DersMesaji::bekleme(...) : DersMesaji::kayit(...);
        $this->oturumMesaji($o, $userId, $metin, 'ders_bildirim', $yetkiliId);
    }

    public function terfi(DersOturumu $o, int $userId, ?int $yetkiliId): void
    {
        $this->oturumMesaji($o, $userId, DersMesaji::terfi(...), 'ders_bekleme_terfi', $yetkiliId);
    }

    /** @param int[] $userIdler */
    public function iptal(DersOturumu $o, array $userIdler, ?int $yetkiliId): void
    {
        foreach ($userIdler as $userId) {
            $this->oturumMesaji($o, (int) $userId, DersMesaji::iptal(...), 'ders_iptal', $yetkiliId);
        }
    }

    /** Dusumden sonra kalan esik altindaysa "N dersiniz kaldi" (yeniden satis tetikleyici). */
    public function kalanUyari(int $salonId, int $userId, int $hizmetId, int $kalan, ?int $yetkiliId): void
    {
        $hizmet = DB::table('hizmetler')->where('id', $hizmetId)->value('hizmet_adi') ?: 'ders';
        $mesaj = DersMesaji::kalanUyari($this->ad($userId), $hizmet, $kalan);
        if ($mesaj !== null) {
            $this->yaz($salonId, $userId, $mesaj, 'ders_kalan_uyari', $yetkiliId);
        }
    }

    private function oturumMesaji(DersOturumu $o, int $userId, callable $metin, string $tip, ?int $yetkiliId): void
    {
        $this->yaz((int) $o->salon_id, $userId, $metin($this->ad($userId), $o->tarih, $o->saat, $o->ders_tipi), $tip, $yetkiliId);
    }

    private function yaz(int $salonId, int $userId, string $mesaj, string $tip, ?int $yetkiliId): void
    {
        BildirimKuyrugu::yaz($salonId, 'ders_bildirim', null, $yetkiliId, ['user_id' => $userId, 'mesaj' => $mesaj, 'gonderim_tipi' => $tip]);
    }

    private function ad(int $userId): string
    {
        return (string) DB::table('users')->where('id', $userId)->value('name');
    }
}
