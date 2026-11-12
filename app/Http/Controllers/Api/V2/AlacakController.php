<?php

namespace App\Http\Controllers\Api\V2;

use App\Models\Personeller;
use App\Services\Tahsilat\AdisyonOkumaServisi;
use App\Services\Tahsilat\AlacakServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Planlanan alacak (receivable) API v2 — Satış & Tahsilat ekranı "Alacak Oluştur".
 * Sıra: kimlik → kapsam → yetki (finans.alacak_yonet) → yazma bayrağı (tahsilat.yazma_aktif ile aynı kapı).
 * Başka salonun adisyonu/alacağı ile olmayan kayıt aynı 404'ü alır (id yoklama yok).
 */
class AlacakController extends V2Controller
{
    protected ?string $yazmaAlani = 'tahsilat';

    private const YETKI = 'finans.alacak_yonet';

    public function __construct(
        private AlacakServisi $servis,
        private AdisyonOkumaServisi $adisyonServisi,
    ) {}

    /** POST /api/v2/alacak  { salon_id, adisyon_id, tutar, planlanan_odeme_tarihi?, aciklama? } */
    public function ekle(Request $request)
    {
        $adisyonId = $request->integer('adisyon_id');
        [$uid, $salonId] = $this->salondakiKayit($request, self::YETKI, fn () => $this->adisyonServisi->salonIdGetir($adisyonId), 'Adisyon bulunamadı');
        $this->yazmaAcikMi();

        $tutar = (float) $request->input('tutar', 0);
        if ($tutar <= 0) {
            return ApiCevap::hata('tutar 0’dan büyük olmalı', 422);
        }

        $olusturanId = Personeller::where('salon_id', $salonId)
            ->where('yetkili_id', $uid)->value('id');

        $sonuc = $this->servis->olustur(
            $adisyonId,
            $tutar,
            $request->input('planlanan_odeme_tarihi'),
            $request->input('aciklama'),
            ['salon_id' => $salonId, 'olusturan_id' => $olusturanId ? (int) $olusturanId : null]
        );

        return ApiCevap::basarili($sonuc, 'Alacak oluşturuldu.');
    }

    /** PATCH /api/v2/alacak/{id} — planlanan alacağı düzenle. */
    public function guncelle(Request $request, int $id)
    {
        $this->kayitDenetle($this->servis->alacakSalonId($id), self::YETKI, 'Alacak bulunamadı');
        $this->yazmaAcikMi();

        $tutar = (float) $request->input('tutar', 0);
        if ($tutar <= 0) {
            return ApiCevap::hata('tutar 0’dan büyük olmalı', 422);
        }

        $sonuc = $this->servis->guncelle(
            $id, $tutar,
            $request->input('planlanan_odeme_tarihi'),
            $request->input('aciklama')
        );

        return ApiCevap::basarili($sonuc, 'Alacak güncellendi.');
    }

    /** DELETE /api/v2/alacak/{id} — planlanan alacağı iptal et. */
    public function sil(int $id)
    {
        $this->kayitDenetle($this->servis->alacakSalonId($id), self::YETKI, 'Alacak bulunamadı');
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->servis->sil($id), 'Alacak iptal edildi.');
    }
}
