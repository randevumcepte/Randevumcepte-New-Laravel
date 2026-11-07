<?php

namespace App\Services\Randevu;

use App\Services\Secici\SeciciServisi;
use App\Support\Yetki\YetkiSabitleri;
use Illuminate\Support\Facades\DB;

/**
 * Uygulamadaki randevu, on gorusme ve form ekranlarinin ortak verisi (form-verisi?mobil=1). Eski
 * randevuIcinGerekliVeriler ile ayni listeler ve filtreler; satirlar beyaz listeden gecer: sifre, kimlik no,
 * maas, prim, saglik alanlari donmez. Telefon musteri.telefon_gor kapaliysa maskelidir.
 */
class RandevuMobilFormServisi
{
    private const MUSTERI_LIMIT = 100;

    private const PERSONEL_ALANLARI = ['id', 'hesap_turu', 'salon_id', 'personel_adi', 'profil_resmi', 'cinsiyet', 'unvan', 'cep_telefon', 'renk', 'takvimde_gorunsun', 'dahili_no', 'takvim_sirasi', 'aktif'];

    private const MUSTERI_ALANLARI = ['id', 'name', 'cep_telefon', 'cinsiyet', 'email', 'dogum_tarihi', 'il_id', 'musteri_tipi', 'meslek', 'profil_resim'];

    private const PAKET_ALANLARI = ['id', 'paket_adi', 'aktif', 'miktar', 'fiyat', 'sure', 'sinirsiz', 'gecerlilik_sayi', 'gecerlilik_birim', 'gunluk_limit'];

    private const PAKET_HIZMET_ALANLARI = ['id', 'paket_id', 'hizmet_id', 'seans', 'fiyat', 'sure'];

    private const URUN_ALANLARI = ['id', 'urun_adi', 'barkod', 'fiyat', 'aktif', 'stok_adedi', 'salon_id'];

    private const HIZMET_ALANLARI = ['hizmet_id', 'sure_dk', 'baslangic_fiyat', 'bolum'];

    public function __construct(
        private RandevuFormServisi $form,
        private SeciciServisi $secici,
    ) {}

    public function veri(int $salonId, ?int $randevuId, string $musteriAra, bool $telGor): array
    {
        return [
            'personeller' => $this->personeller($salonId, $telGor),
            'hizmetler'   => $this->hizmetler($salonId, $randevuId),
            'odalar'      => $this->secici->odalar($salonId),
            'cihazlar'    => $this->secici->cihazlar($salonId),
            'musteriler'  => $this->musteriler($salonId, $musteriAra, $telGor),
            'urunler'     => $this->suzulmus(DB::table('urunler')->where('salon_id', $salonId)->where('aktif', 1)->get(), self::URUN_ALANLARI),
            'paketler'    => $this->paketler($salonId),
            'sehirler'    => $this->secici->sehirler(),
            'formlar'     => DB::table('formtaslaklari')->where(fn ($w) => $w->where('salon_id', $salonId)->orWhereNull('salon_id'))->get(['id', 'form_adi'])->all(),
        ] + $this->form->haritalar($salonId);
    }

    private function personeller(int $salonId, bool $telGor): array
    {
        $satirlar = $this->suzulmus(DB::table('salon_personelleri')->where('salon_id', $salonId)->where('aktif', 1)->get(), self::PERSONEL_ALANLARI);

        return array_map(fn ($p) => $this->telefonlu($p, $telGor), $satirlar);
    }

    /** Hizmet basina son kayit; ekran hizmet adini `hizmetler`, kategoriyi `hizmet_kategorisi` altindan okur. */
    private function hizmetler(int $salonId, ?int $randevuId): array
    {
        $satirlar = DB::table('salon_sunulan_hizmetler')->whereIn('id', $this->form->sonHizmetKayitlari($salonId, $randevuId))->orderBy('id')->get();
        $hizmet = DB::table('hizmetler')->whereIn('id', $satirlar->pluck('hizmet_id')->filter()->unique())
            ->get(['id', 'hizmet_adi', 'hizmet_kategori_id'])->keyBy('id');
        $kategori = DB::table('hizmet_kategorisi')->whereIn('id', $satirlar->pluck('hizmet_kategori_id')->filter()->unique())
            ->get(['id', 'hizmet_kategorisi_adi'])->keyBy('id');

        return $satirlar->map(fn ($s) => array_intersect_key((array) $s, array_flip(self::HIZMET_ALANLARI)) + [
            'hizmetler'         => $hizmet[$s->hizmet_id] ?? null,
            'hizmet_kategorisi' => $kategori[$s->hizmet_kategori_id] ?? null,
        ])->all();
    }

    private function musteriler(int $salonId, string $ara, bool $telGor): array
    {
        $satirlar = DB::table('users as u')->join('musteri_portfoy as mp', 'mp.user_id', '=', 'u.id')
            ->where('mp.salon_id', $salonId)->where('mp.aktif', 1)
            ->when($ara !== '', fn ($q) => $q->where(fn ($w) => $w->where('u.name', 'like', '%'.$ara.'%')->orWhere('u.cep_telefon', 'like', '%'.$ara.'%')))
            ->orderBy('u.id')->limit(self::MUSTERI_LIMIT)->select('u.*')->get();

        return array_map(fn ($m) => $this->telefonlu($m, $telGor), $this->suzulmus($satirlar, self::MUSTERI_ALANLARI));
    }

    private function paketler(int $salonId): array
    {
        $paketler = DB::table('paketler')->where('salon_id', $salonId)->where('aktif', 1)->get();
        $hizmetler = DB::table('paket_hizmetler')->whereIn('paket_id', $paketler->pluck('id'))->get()->groupBy('paket_id');

        return array_map(fn ($p) => $p + [
            'hizmetler' => $this->suzulmus($hizmetler[$p['id']] ?? collect(), self::PAKET_HIZMET_ALANLARI),
        ], $this->suzulmus($paketler, self::PAKET_ALANLARI));
    }

    /** SELECT * sonrasi suzme: semada olmayan kolon 500 vermesin, listede olmayan alan cikmasin. */
    private function suzulmus($satirlar, array $alanlar): array
    {
        return collect($satirlar)->map(fn ($s) => array_intersect_key((array) $s, array_flip($alanlar)))->values()->all();
    }

    private function telefonlu(array $satir, bool $telGor): array
    {
        if (! $telGor && isset($satir['cep_telefon'])) {
            $satir['cep_telefon'] = YetkiSabitleri::telefonMaskele((string) $satir['cep_telefon']);
        }

        return $satir;
    }
}
