<?php

namespace App\Services\Sms;

use App\Exceptions\BulunamadiException;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * SMS ŞABLONLARI (sms_taslaklari) — salonun kendi şablonları + sistem geneli (salon_id NULL) salt okunur
 * şablonlar. Reklam Yönetimi şablon kataloğu da aynı tabloyu okur.
 */
class SmsSablonServisi
{
    public function liste(int $salonId): array
    {
        return DB::table('sms_taslaklari')
            ->where(fn ($w) => $w->where('salon_id', $salonId)->orWhereNull('salon_id'))
            ->orderByDesc('id')->get(['id', 'baslik', 'taslak_icerik', 'salon_id'])
            ->map(fn ($t) => [
                'id'             => (int) $t->id,
                'baslik'         => (string) $t->baslik,
                'taslak_icerik'  => (string) $t->taslak_icerik,
                'duzenlenebilir' => $t->salon_id !== null,
            ])->all();
    }

    /** @return array<int,array> güncel liste (istemci yeniden istemez). */
    public function ekle(int $yetkiliId, int $salonId, string $baslik, string $icerik): array
    {
        $id = (int) DB::table('sms_taslaklari')->insertGetId([
            'salon_id' => $salonId, 'baslik' => $baslik, 'taslak_icerik' => $icerik, 'created_at' => now(), 'updated_at' => now(),
        ]);
        AktiviteLog::yaz($salonId, $yetkiliId, 'sms_taslak_kaydet', 'sms_taslak', $id, $baslik, 'SMS şablonu kaydedildi');

        return $this->liste($salonId);
    }

    public function guncelle(int $yetkiliId, int $salonId, int $id, string $baslik, string $icerik): array
    {
        $this->kendiKaydi($salonId, $id)->update(['baslik' => $baslik, 'taslak_icerik' => $icerik, 'updated_at' => now()]);
        AktiviteLog::yaz($salonId, $yetkiliId, 'sms_taslak_kaydet', 'sms_taslak', $id, $baslik, 'SMS şablonu güncellendi');

        return $this->liste($salonId);
    }

    public function sil(int $yetkiliId, int $salonId, int $id): array
    {
        $this->kendiKaydi($salonId, $id)->delete();
        AktiviteLog::yaz($salonId, $yetkiliId, 'sms_taslak_sil', 'sms_taslak', $id, null, 'SMS şablonu silindi');

        return $this->liste($salonId);
    }

    /** Yalnız salonun kendi şablonu değiştirilebilir; başkasınınki / sistem şablonu → 404. */
    private function kendiKaydi(int $salonId, int $id)
    {
        $q = DB::table('sms_taslaklari')->where('id', $id)->where('salon_id', $salonId);
        if (! (clone $q)->exists()) {
            throw new BulunamadiException('Şablon bulunamadı');
        }

        return $q;
    }
}
