<?php

namespace App\Services\Musteri;

use App\Domain\Musteri\Vki;
use App\Exceptions\BulunamadiException;
use App\Support\Sema;
use Illuminate\Support\Facades\DB;

/**
 * Vucut olcumu (studyo modu) — eski StoreAdminController::musteri_olcum_* ve Flutter
 * /musteri-olcum-* uclarinin karsiligi. Tablo: musteri_olcumleri (salon_id kapsamli).
 * VKI sunucuda hesaplanir (Domain\Musteri\Vki) — istemci gondermez.
 */
class OlcumServisi
{
    /** Sayisal olcum alanlari (Flutter VucutOlcum ile ayni adlar). */
    public const ALANLAR = ['boy', 'kilo', 'yas', 'yag_orani', 'odem', 'kas_puani', 'kas_kg', 'ic_yaglanma'];

    public function liste(int $salonId, int $userId): array
    {
        $saat = Sema::kolonVar('musteri_olcumleri', 'olcum_saati');

        return DB::table('musteri_olcumleri')->where('salon_id', $salonId)->where('user_id', $userId)
            ->orderByDesc('olcum_tarihi')->when($saat, fn ($q) => $q->orderByDesc('olcum_saati'))->orderByDesc('id')
            ->get()->map(fn ($o) => $this->donustur($o, $saat))->all();
    }

    /** Danisanin kendi olcumleri: grafik icin ARTAN tarih; salon verilmezse tum isletmeler. */
    public function danisanListe(int $userId, ?int $salonId): array
    {
        $saat = Sema::kolonVar('musteri_olcumleri', 'olcum_saati');

        return DB::table('musteri_olcumleri')->where('user_id', $userId)->when($salonId, fn ($q) => $q->where('salon_id', $salonId))
            ->orderBy('olcum_tarihi')->orderBy('id')
            ->get()->map(fn ($o) => $this->donustur($o, $saat))->all();
    }

    public function ekle(int $salonId, int $userId, array $g, ?int $olcenPersonelId): array
    {
        $saat = Sema::kolonVar('musteri_olcumleri', 'olcum_saati');
        $kayit = ['salon_id' => $salonId, 'user_id' => $userId, 'olcum_tarihi' => $g['olcum_tarihi'] ?? date('Y-m-d')];
        foreach (self::ALANLAR as $k) {
            $kayit[$k] = isset($g[$k]) && $g[$k] !== '' ? $g[$k] : null;
        }
        $kayit['vki'] = Vki::hesapla($kayit['boy'], $kayit['kilo']);
        $kayit['not'] = ($n = trim((string) ($g['not'] ?? ''))) === '' ? null : mb_substr($n, 0, 1000);
        $kayit['olcen_personel_id'] = $olcenPersonelId;
        if ($saat) {
            $kayit['olcum_saati'] = $g['olcum_saati'] ?? date('H:i:s');
        }

        $id = DB::table('musteri_olcumleri')->insertGetId($kayit + ['created_at' => now(), 'updated_at' => now()]);

        return $this->donustur(DB::table('musteri_olcumleri')->find($id), $saat);
    }

    public function sil(int $salonId, int $olcumId): array
    {
        $silinen = DB::table('musteri_olcumleri')->where('salon_id', $salonId)->where('id', $olcumId)->delete();
        if (! $silinen) {
            throw new BulunamadiException('Ölçüm bulunamadı');
        }

        return ['id' => $olcumId];
    }

    /** Olcumun salonu + musterisi (kapsam denetimi icin); yoksa null. */
    public function sahibi(int $olcumId): ?object
    {
        return DB::table('musteri_olcumleri')->where('id', $olcumId)->first(['salon_id', 'user_id']);
    }

    private function donustur(object $o, bool $saat): array
    {
        $sayi = fn ($v) => $v === null ? null : (float) $v;
        $vki = $sayi($o->vki);

        return [
            'id'           => (int) $o->id,
            'olcum_tarihi' => $o->olcum_tarihi,
            'olcum_saati'  => $saat && $o->olcum_saati ? substr((string) $o->olcum_saati, 0, 5) : null,
            'boy'          => $sayi($o->boy),
            'kilo'         => $sayi($o->kilo),
            'yas'          => $o->yas === null ? null : (int) $o->yas,
            'vki'          => $vki,
            'vki_sinif'    => Vki::sinif($vki),
            'yag_orani'    => $sayi($o->yag_orani),
            'odem'         => $sayi($o->odem),
            'kas_puani'    => $sayi($o->kas_puani),
            'kas_kg'       => $sayi($o->kas_kg),
            'ic_yaglanma'  => $sayi($o->ic_yaglanma),
            'not'          => $o->not,
        ];
    }
}
