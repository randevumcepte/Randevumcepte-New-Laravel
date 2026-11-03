<?php

namespace App\Services\Ayar;

use App\Domain\Ayar\AyarSozluk;
use App\Domain\Telefon\TelefonNumarasi;
use App\Exceptions\BulunamadiException;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Isletme bilgileri (kimlik, tanitim, linkler, fatura, SEO) — eski
 * StoreAdminController@isletmebilgiguncelle + ApiController@isletmebilgiguncelle'in TEK karsiligi.
 *
 * Alan adlari salonlar KOLON adlaridir (Flutter fetchSalonSettings'in okudugu anahtarlar).
 * Kaydetme KISMIDIR: yalnizca gonderilen alanlar degisir (web tum formu, Flutter kendi
 * alt kumesini gonderir — biri digerinin alanini sifirlamaz). SEO anahtar kelimeleri
 * gonderildiyse arama_terimleri sil-ve-yaz.
 */
class IsletmeBilgiServisi
{
    /** Duzenlenebilir salonlar kolonlari. */
    /** Telefon kolonlari — TelefonNumarasi::kanonik ile saklanir. */
    private const TELEFON_ALANLARI = ['telefon_1', 'whatsapp'];

    public const ALANLAR = [
        'salon_adi', 'salon_turu_id', 'adres', 'telefon_1', 'whatsapp', 'aciklama',
        'instagram_sayfa', 'facebook_sayfa', 'vergi_adi', 'vergi_no', 'vergi_dairesi',
        'vergi_adresi', 'kdv_orani', 'meta_description',
    ];

    public function __construct(private IsletmeGorselServisi $gorsel) {}

    public function getir(int $salonId): array
    {
        $salon = DB::table('salonlar')->where('id', $salonId)->first([...self::ALANLAR, 'logo', 'domain']);
        if (! $salon) {
            throw new BulunamadiException('İşletme bulunamadı');
        }

        $bilgi = [];
        foreach (self::ALANLAR as $a) {
            $bilgi[$a] = $salon->{$a};
        }
        $bilgi['salon_turu_id'] = $salon->salon_turu_id !== null ? (int) $salon->salon_turu_id : null;

        return $bilgi + [
            'domain'            => $salon->domain,
            'site_url'          => $salon->domain ? 'https://'.$salon->domain : null,
            'logo'              => $salon->logo,
            'logo_url'          => $this->gorsel->url($salon->logo),
            'anahtar_kelimeler' => $this->anahtarKelimeler($salonId),
            'galeri'            => $this->gorsel->galeri($salonId),
            'secenekler'        => [
                'salon_turleri'       => $this->salonTurleri(),
                'aciklama_maks'       => AyarSozluk::ACIKLAMA_MAKS,
                'anahtar_kelime_maks' => AyarSozluk::ANAHTAR_KELIME_MAKS,
                'galeri_maks'         => AyarSozluk::GALERI_MAKS,
            ],
        ];
    }

    /** @param  array  $g  dogrulanmis alanlar (ALANLAR alt kumesi + opsiyonel anahtar_kelimeler[]) */
    public function kaydet(int $salonId, int $yapanId, array $g): array
    {
        $eski = DB::table('salonlar')->where('id', $salonId)->first(['salon_adi', 'telefon_1', 'whatsapp']);
        if (! $eski) {
            throw new BulunamadiException('İşletme bulunamadı');
        }

        foreach (self::TELEFON_ALANLARI as $a) {
            if (array_key_exists($a, $g)) {
                $g[$a] = TelefonNumarasi::kanonik($g[$a]) ?: null;
            }
        }

        DB::transaction(function () use ($salonId, $g) {
            $alanlar = array_intersect_key($g, array_flip(self::ALANLAR));
            if ($alanlar) {
                DB::table('salonlar')->where('id', $salonId)->update($alanlar);
            }
            if (array_key_exists('anahtar_kelimeler', $g)) {
                $this->anahtarKelimeleriYaz($salonId, (array) $g['anahtar_kelimeler']);
            }
        });

        $yeni = $this->getir($salonId);
        AktiviteLog::yaz($salonId, $yapanId, 'ayarlar_guncelle', 'salon', $salonId,
            $yeni['salon_adi'], 'Temel ayarlar güncellendi', [
                'eski' => (array) $eski,
                'yeni' => ['salon_adi' => $yeni['salon_adi'], 'telefon_1' => $yeni['telefon_1'], 'whatsapp' => $yeni['whatsapp']],
            ]);

        return $yeni;
    }

    /** @return list<array{id:int,ad:string}> */
    public function salonTurleri(): array
    {
        return DB::table('salon_turu')->orderBy('salon_turu_adi')->get(['id', 'salon_turu_adi'])
            ->map(fn ($t) => ['id' => (int) $t->id, 'ad' => (string) $t->salon_turu_adi])->all();
    }

    /** @return list<string> */
    private function anahtarKelimeler(int $salonId): array
    {
        return DB::table('arama_terimleri')->where('salon_id', $salonId)->orderBy('id')
            ->pluck('arama_terimi')->map(fn ($k) => (string) $k)->all();
    }

    private function anahtarKelimeleriYaz(int $salonId, array $kelimeler): void
    {
        DB::table('arama_terimleri')->where('salon_id', $salonId)->delete();
        $simdi = now();
        $satirlar = collect($kelimeler)->map(fn ($k) => trim((string) $k))->filter()->unique()
            ->take(AyarSozluk::ANAHTAR_KELIME_MAKS)
            ->map(fn ($k) => ['salon_id' => $salonId, 'arama_terimi' => $k, 'created_at' => $simdi, 'updated_at' => $simdi])
            ->values()->all();
        if ($satirlar) {
            DB::table('arama_terimleri')->insert($satirlar);
        }
    }
}
