<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Services\SistemYonetim\Salon\SalonBilgiServisi;
use App\Services\SistemYonetim\Salon\SalonDemoServisi;
use App\Services\SistemYonetim\Salon\SalonDurumServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 *   POST /sistem/salon                   demo salon
 *   POST /sistem/salon/toplu-islem       {ids[], islem: mt_ata|askiya_al|aktif_et, mt_id?, sebep?}
 *   PUT  /sistem/salon/{id}/bilgi        POST /sistem/salon/{id}/{askiya-al,aktif-et,mt-ata}
 */
class SalonYazmaController extends SistemTabanController
{
    private const YAZI = ['nullable', 'string', 'max:255'];

    public function ekle(Request $request, SalonDemoServisi $servis)
    {
        $this->yazmaAcikMi();
        $g = $this->alanlariDogrula($request, [
            'salon_adi'       => ['required', 'string', 'min:2', 'max:255'],
            'salon_turu_id'   => ['required', 'integer', 'exists:salon_turu,id'],
            'yetkili_ad'      => ['required', 'string', 'min:2', 'max:255'],
            'yetkili_telefon' => ['required', 'string', 'max:30'],
            'yetkili_email'   => ['required', 'email', 'max:255', 'unique:isletmeyetkilileri,email'],
            'yetkili_sifre'   => ['nullable', 'string', 'min:'.config('oturum.sifre_min'), 'max:64'],
            'demo_gun'        => ['nullable', 'integer', 'min:1', 'max:3650'],
            'adres'           => self::YAZI,
        ] + $this->konumKurallari($request));

        return ApiCevap::basarili($servis->olustur($g), null, 201);
    }

    public function toplu(Request $request, SalonDurumServisi $servis)
    {
        $this->yazmaAcikMi();
        $g = $this->alanlariDogrula($request, [
            'ids'   => ['nullable', 'array', 'max:500'],
            'ids.*' => ['integer', 'min:1'],
            'islem' => ['nullable', 'string'],
            'mt_id' => ['nullable', 'integer', 'exists:sistemyoneticileri,id'],
            'sebep' => self::YAZI,
        ]);
        $mesaj = $servis->toplu(array_values(array_unique(array_map('intval', $g['ids'] ?? []))), $g['islem'] ?? null, $g, $this->yonetici());

        return ApiCevap::basarili(null, $mesaj);
    }

    public function bilgi(Request $request, int $id, SalonBilgiServisi $servis)
    {
        $this->yazmaAcikMi();
        $salon = $this->salon($id, ['id']);
        $g = $this->alanlariDogrula($request, [
            'salon_adi'     => ['sometimes', 'required', 'string', 'max:255'],
            'domain'        => ['nullable', 'string', 'max:200'],
            'salon_turu_id' => ['nullable', 'integer', 'exists:salon_turu,id'],
            'adres'         => self::YAZI,
            'telefon_1'     => ['nullable', 'string', 'max:200'],
            'telefon_2'     => ['nullable', 'string', 'max:200'],
            'telefon_3'     => ['nullable', 'string', 'max:200'],
            'yetkili_adi'   => self::YAZI,
            'yetkili_telefon' => ['nullable', 'string', 'max:20'],
            'yetkili_mail'  => ['nullable', 'email', 'max:255'],
            'aciklama'      => ['nullable', 'string', 'max:65000'],
            'android_uygulama' => ['nullable', 'string', 'max:2000'],
            'ios_uygulama'     => ['nullable', 'string', 'max:2000'],
            'huawei_uygulama'  => ['nullable', 'string', 'max:2000'],
            'android_son_versiyon' => ['nullable', 'string', 'max:5'],
            'ios_son_versiyon'     => ['nullable', 'string', 'max:5'],
            'huawei_son_versiyon'  => ['nullable', 'string', 'max:5'],
        ] + $this->konumKurallari($request));

        $servis->guncelle((int) $salon->id, $g);

        return ApiCevap::basarili(null, 'İşletme bilgileri güncellendi.');
    }

    public function askiyaAl(Request $request, int $id, SalonDurumServisi $servis)
    {
        $this->yazmaAcikMi();
        $g = $this->alanlariDogrula($request, ['sebep' => ['required', 'string', 'max:255']]);
        $servis->askiyaAl($this->salon($id, ['id', 'salon_adi', 'askiya_alindi', 'askiya_alma_sebebi']), $g['sebep'], $this->yonetici());

        return ApiCevap::basarili(null, 'Salon askıya alındı.');
    }

    public function aktifEt(int $id, SalonDurumServisi $servis)
    {
        $this->yazmaAcikMi();
        $servis->aktifEt($this->salon($id, ['id', 'salon_adi', 'askiya_alindi']));

        return ApiCevap::basarili(null, 'Salon yeniden aktif edildi.');
    }

    public function temsilciAta(Request $request, int $id, SalonDurumServisi $servis)
    {
        $this->yazmaAcikMi();
        $g = $this->alanlariDogrula($request, ['musteri_yetkili_id' => ['nullable', 'integer', 'exists:sistemyoneticileri,id']]);
        $servis->temsilciAta($this->salon($id, ['id', 'salon_adi']), $g['musteri_yetkili_id'] ?? null);

        return ApiCevap::basarili(null, 'Müşteri temsilcisi güncellendi.');
    }

    /** Ilce secilen ile ait olmali. */
    private function konumKurallari(Request $request): array
    {
        $ilce = Rule::exists('ilce', 'id');

        return [
            'il_id'   => ['nullable', 'integer', 'exists:il,id'],
            'ilce_id' => ['nullable', 'integer', $request->filled('il_id') ? $ilce->where('il_id', (int) $request->input('il_id')) : $ilce],
        ];
    }
}
