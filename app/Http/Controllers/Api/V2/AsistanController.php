<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Asistan\GorevTuru;
use App\Domain\Asistan\MobilGorevGorunumu;
use App\Exceptions\GecersizIslemException;
use App\Services\Asistan\AsistanAyarServisi;
use App\Services\Asistan\AsistanGorevIptalServisi;
use App\Services\Asistan\AsistanGorevServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * E-Asistan ("Asistanım") API v2 — web /v2/asistan ve Flutter asistanim ekranlari
 * AYNI uclari tuketir.
 *
 * KVKK: kimlik + kapsam + rol 5 (personel) degil (eski personelmi() yonlendirmesi).
 * Mobil gorunum (mobil_gorunum): mobil uygulama gorev LISTESINI rol ayirmadan gosterir (kimlik + salon kapsami; liste
 * ve metinler mobilin bugunkuyle ayni). Gorev iptali web ile ayni kural (rol 5 degil) — 2026-11-12.
 * Yazmalar config('asistan.yazma_aktif') kill-switch arkasinda (kapaliyken 409).
 */
class AsistanController extends V2Controller
{
    protected ?string $yazmaAlani = 'asistan';

    public function __construct(private GuvenlikKapisi $kapi) {}

    /** GET /api/v2/asistan/gorevler?salon_id=&tarih=Y-m-d(varsayilan bugun)&page=&per_page= */
    public function gorevler(Request $request, AsistanGorevServisi $servis)
    {
        $gorunum = MobilGorevGorunumu::tryFrom((string) $request->input('mobil_gorunum'));
        $salonId = $gorunum ? $this->salonDenetle($request, [])[1] : $this->salon($request);
        $tarih = (string) $request->input('tarih', date('Y-m-d'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih) || ! strtotime($tarih)) {
            throw new GecersizIslemException('tarih Y-m-d biçiminde olmalı');
        }
        if ($gorunum) {
            return ApiCevap::basarili($servis->mobilListe($salonId, $tarih, $gorunum, (int) $request->input('page', 1)));
        }
        $sayfaBoyu = max(1, min(100, (int) $request->input('per_page', 25)));

        return ApiCevap::basarili($servis->liste($salonId, $tarih, (int) $request->input('page', 1), $sayfaBoyu));
    }

    /** POST /api/v2/asistan/gorev/{tur}/{id}/iptal  (tur: randevu|alacak|kampanya) */
    public function iptal(Request $request, string $tur, int $id, AsistanGorevIptalServisi $servis)
    {
        $user = $this->kullanici();
        $gorevTuru = GorevTuru::from($tur); // route kisiti gecersiz turu zaten eler
        $salonId = $this->bulundu($servis->salonId($gorevTuru, $id), 'Görev bulunamadı');
        $this->kapi->yoneticiDenetle($user->id, $salonId); // web ve mobil ayni kural (2026-11-12)
        $this->yazmaAcikMi();

        return ApiCevap::basarili(null, $servis->iptalEt($gorevTuru, $id, $salonId, (int) $user->id));
    }

    /** GET /api/v2/asistan/ayarlar?salon_id= */
    public function ayarlar(Request $request, AsistanAyarServisi $servis)
    {
        return ApiCevap::basarili($servis->getir($this->salon($request)));
    }

    /** PATCH /api/v2/asistan/ayarlar  { salon_id, ayarlar: {anahtar: bool}, tekrar_arama_saat?: 1..23 } */
    public function ayarKaydet(Request $request, AsistanAyarServisi $servis)
    {
        $salonId = $this->salon($request);
        $this->yazmaAcikMi();

        $ayarlar = $request->input('ayarlar', []);
        if (! is_array($ayarlar)) {
            throw new GecersizIslemException('ayarlar {anahtar: true|false} biçiminde olmalı');
        }
        $ayarlar = array_map(fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN), $ayarlar);
        $saat = $request->filled('tekrar_arama_saat') ? (int) $request->input('tekrar_arama_saat') : null;

        return ApiCevap::basarili(
            $servis->kaydet($salonId, $ayarlar, $saat, (int) $this->kullanici()->id),
            'E-Asistan ayarları başarıyla kaydedildi.'
        );
    }

    /** salon_id zorunlu + kimlik + kapsam + yonetici (rol 5 degil). */
    private function salon(Request $request): int
    {
        $salonId = (int) $request->input('salon_id');
        if (! $salonId) {
            throw new GecersizIslemException('salon_id zorunlu');
        }
        $this->kapi->yoneticiDenetle($this->kullanici()->id, $salonId);

        return $salonId;
    }
}
