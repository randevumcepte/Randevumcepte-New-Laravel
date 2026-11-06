<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Domain\SistemYonetim\UyelikKurallari;
use App\Services\SistemYonetim\Salon\SalonUyelikServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** POST /sistem/salon/{id}/{sure-uzat,paketli-uzat,lisans-aktif,lisans-kapat} */
class SalonUyelikController extends SistemTabanController
{
    private const UYELIK_KOLONLARI = ['id', 'salon_adi', 'uyelik_bitis_tarihi', 'uyelik_turu', 'demo_hesabi', 'yetkili_telefon', 'telefon_1'];

    private const MAKS_GUN = 3650;

    public function __construct(private SalonUyelikServisi $servis) {}

    public function sureUzat(Request $request, int $id)
    {
        $this->yazmaAcikMi();
        $g = $this->alanlariDogrula($request, [
            'gun'   => ['nullable', 'integer', 'min:1', 'max:'.self::MAKS_GUN],
            'tarih' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->cevap($this->servis->sureUzat($this->uyelikSalonu($id), $g['gun'] ?? null, $g['tarih'] ?? null));
    }

    public function paketliUzat(Request $request, int $id)
    {
        $this->yazmaAcikMi();
        $g = $this->alanlariDogrula($request, [
            'paket'     => ['required', Rule::in(array_keys(UyelikKurallari::PAKETLER))],
            'periyot'   => ['required', Rule::in(array_keys(UyelikKurallari::PERIYOTLAR))],
            'adet'      => ['required', 'integer', 'min:1', 'max:120'],
            'hediye_ay' => ['nullable', 'integer', 'min:0', 'max:120'],
            'ucret'     => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'kdv_dahil' => ['nullable', 'boolean'],
        ]);

        return $this->cevap($this->servis->paketliUzat($this->uyelikSalonu($id), [
            'paket'     => $g['paket'],
            'periyot'   => $g['periyot'],
            'adet'      => (int) $g['adet'],
            'hediye_ay' => (int) ($g['hediye_ay'] ?? 0),
            'ucret'     => round((float) ($g['ucret'] ?? 0), 2),
            'kdv_dahil' => (bool) ($g['kdv_dahil'] ?? false),
        ], $this->yonetici()));
    }

    public function lisansAktif(Request $request, int $id)
    {
        $this->yazmaAcikMi();
        $g = $this->alanlariDogrula($request, ['gun' => ['nullable', 'integer', 'min:1', 'max:'.self::MAKS_GUN]]);

        return $this->cevap($this->servis->lisansAktif($this->uyelikSalonu($id), (int) ($g['gun'] ?? 365)));
    }

    public function lisansKapat(int $id)
    {
        $this->yazmaAcikMi();

        return $this->cevap($this->servis->lisansKapat($this->uyelikSalonu($id)));
    }

    private function uyelikSalonu(int $id): object
    {
        return $this->salon($id, self::UYELIK_KOLONLARI);
    }

    private function cevap(array $sonuc)
    {
        return ApiCevap::basarili($sonuc, $sonuc['mesaj']);
    }
}
