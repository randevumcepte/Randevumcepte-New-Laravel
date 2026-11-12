<?php

namespace App\Services\Satis;

use App\Domain\Satis\MusteriDanisanTuru;
use App\Exceptions\BulunamadiException;
use App\Support\KvkkMaske;
use Illuminate\Support\Facades\DB;

/** Mobil dashboard satis listeleri, musteri turu ve tahsilat ekrani musteri karti — eski ApiController karsiliklari. */
class MobilSatisOkumaServisi
{
    private const SAYFA_BOYU = 10;

    /** MusteriDanisan.fromJson alanlari; sifre ve anahtar gitmez. */
    private const MUSTERI_ALANLARI = [
        'id', 'il_id', 'name', 'cep_telefon', 'cinsiyet', 'ozel_notlar', 'email', 'dogum_tarihi',
        'baslangic_kg', 'baslangic_gogus', 'baslangic_gobek', 'baslangic_kalca', 'baslangic_basen', 'baslangic_bel', 'baslangic_sirt', 'baslangic_diger',
        'kil_yapisi', 'ten_rengi', 'adres', 'hemofili_hastaligi_var', 'seker_hastaligi_var', 'hamile', 'yakin_zamanda_ameliyat_gecirildi',
        'alerji_var', 'alkol_alimi_yapildi', 'regl_doneminde', 'deri_yumusak_doku_hastaligi_var', 'surekli_kullanilan_ilac_Var',
        'surekli_kullanilan_ilac_aciklama', 'kemoterapi_goruyor', 'daha_once_uygulama_yaptirildi', 'daha_once_yaptirilan_uygulama_aciklama',
        'ek_saglik_sorunu', 'cilt_tipi', 'tc_kimlik_no', 'musteri_tipi', 'meslek', 'bildirim_kimligi', 'kayit_tarihi', 'referans', 'profil_resim',
    ];

    /** Eski paketsatisget: bugun olusan paket kalemleri, faturasiz gizleme uygulanmaz. $kisitPersonel: SatisListeKapsami kisiti. */
    public function gunlukPaketler(int $salonId, ?string $ara, ?int $kisitPersonel = null): array
    {
        return $this->gunlukTaban('adisyon_paketler', $salonId, $ara, $kisitPersonel)
            ->join('paketler', 'adisyon_paketler.paket_id', '=', 'paketler.id')
            ->select(
                'adisyon_paketler.id as id',
                'adisyon_paketler.fiyat as fiyat',
                'paketler.paket_adi as paket_adi',
                'salon_personelleri.personel_adi as satan',
                'users.name as musteri',
                DB::raw('DATE_FORMAT(adisyon_paketler.created_at,"%d.%m.%Y") as tarih'),
            )
            ->paginate(self::SAYFA_BOYU)->toArray();
    }

    /** Eski urunsatisget: bugun olusan urun kalemleri. */
    public function gunlukUrunler(int $salonId, ?string $ara, ?int $kisitPersonel = null): array
    {
        return $this->gunlukTaban('adisyon_urunler', $salonId, $ara, $kisitPersonel)
            ->join('urunler', 'adisyon_urunler.urun_id', '=', 'urunler.id')
            ->select(
                'adisyon_urunler.id as id',
                'adisyon_urunler.fiyat as fiyat',
                'urunler.urun_adi as urun_adi',
                'salon_personelleri.personel_adi as satan',
                'users.name as musteri',
            )
            ->paginate(self::SAYFA_BOYU)->toArray();
    }

    private function gunlukTaban(string $tablo, int $salonId, ?string $ara, ?int $kisitPersonel)
    {
        return DB::table($tablo)
            ->join('salon_personelleri', $tablo.'.personel_id', '=', 'salon_personelleri.id')
            ->join('adisyonlar', $tablo.'.adisyon_id', '=', 'adisyonlar.id')
            ->join('users', 'adisyonlar.user_id', '=', 'users.id')
            ->where('users.name', 'like', '%'.$ara.'%')
            ->where('adisyonlar.salon_id', $salonId)
            ->where($tablo.'.created_at', 'like', '%'.date('Y-m-d').'%')
            ->when($kisitPersonel, fn ($q, $pid) => $q->where($tablo.'.personel_id', $pid));
    }

    public function musteriTuru(int $musteriId, int $salonId): int
    {
        $q = fn () => DB::table('tahsilatlar')->where('user_id', $musteriId)->where('salon_id', $salonId);

        return MusteriDanisanTuru::hesapla($q()->count(), $q()->orderByDesc('id')->value('created_at'), time());
    }

    /** Eski musteritahsilat satiri: yalniz salon portfoyundeki musteri; telefon yetkisi yoksa maskeli. */
    public function musteriKarti(int $musteriId, int $salonId, bool $telefonGor): array
    {
        $portfoyde = DB::table('musteri_portfoy')->where('user_id', $musteriId)->where('salon_id', $salonId)->exists();
        $u = $portfoyde ? DB::table('users')->where('id', $musteriId)->first() : null;
        if (! $u) {
            throw new BulunamadiException('Müşteri bulunamadı');
        }
        $kart = array_intersect_key((array) $u, array_flip(self::MUSTERI_ALANLARI));
        if (! $telefonGor && isset($kart['cep_telefon'])) {
            $kart['cep_telefon'] = KvkkMaske::telefon($kart['cep_telefon']);
        }

        return $kart;
    }
}
