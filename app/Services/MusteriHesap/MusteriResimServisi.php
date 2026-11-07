<?php

namespace App\Services\MusteriHesap;

use App\Exceptions\BulunamadiException;
use App\Support\AktiviteLog;
use App\Support\DosyaYukleme;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Musterinin kendi islem fotograflari (v1 musteriresimleri, musteriResimEkle, musteriResimSil, musteriResimNot).
 * Kimlik token'dan: v1 uclari kimliksizdi, baskasinin fotografi silinebiliyordu. Dosya yolu DB'de eski bicimde
 * (public/musteri_gorselleri/<ad>) kalir, eski panel ve uygulama ayni dosyayi bulur.
 */
class MusteriResimServisi
{
    private const ALT_DIZIN = 'musteri_gorselleri';
    private const UZANTILAR = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];
    private const MAKS_KB = 20480;
    private const ALANLAR = ['id', 'user_id', 'salon_id', 'tarih', 'islem_fotolari', 'resim_notu'];

    public function __construct(private MusteriSalonKapisi $kapi) {}

    /** Oncelik marka: musteri markanin tum subelerindeki fotograflarini gorur. */
    public function liste(int $userId, ?string $bundle, ?int $salonId): array
    {
        return DB::table('islemler')->where('user_id', $userId)
            ->when($bundle, fn ($q) => $q->whereIn('salon_id', DB::table('salonlar')->where('app_bundle', $bundle)->pluck('id')))
            ->when(! $bundle && $salonId, fn ($q) => $q->where('salon_id', $salonId))
            ->get(self::ALANLAR)->map(fn ($r) => (array) $r)->all();
    }

    public function ekle(int $userId, ?UploadedFile $dosya, ?int $salonId, ?string $not): array
    {
        if ($salonId) {
            $this->kapi->denetle($userId, $salonId);
        }
        $ad = DosyaYukleme::kaydet($dosya, $this->dizin(), self::UZANTILAR, self::MAKS_KB, 'musteri');
        $yol = 'public/'.self::ALT_DIZIN.'/'.$ad;
        $tarih = now()->format('Y-m-d');
        $id = (int) DB::table('islemler')->insertGetId([
            'user_id' => $userId, 'salon_id' => $salonId, 'resim_notu' => $not !== null && trim($not) !== '' ? trim($not) : null,
            'tarih' => $tarih, 'islem_fotolari' => json_encode([$yol]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($salonId) {
            AktiviteLog::yaz($salonId, null, 'musteri_resim_ekle', 'musteri_resim', $id, null, 'Müşteri işlem resmi eklendi');
            $this->isletmeyeBildir($salonId, $userId);
        }

        return ['id' => $id, 'tarih' => $tarih, 'path' => $yol];
    }

    public function sil(int $userId, string $yol): void
    {
        $satir = $this->sahipSatir($userId, $yol, null);
        $kalan = array_values(array_filter($this->yollar($satir), fn ($y) => $y !== $yol));
        $kalan
            ? DB::table('islemler')->where('id', $satir->id)->update(['islem_fotolari' => json_encode($kalan), 'updated_at' => now()])
            : DB::table('islemler')->where('id', $satir->id)->delete();

        $tam = $this->dizin().'/'.basename($yol);
        if (is_file($tam)) {
            @unlink($tam);
        }
        if ($satir->salon_id) {
            AktiviteLog::yaz((int) $satir->salon_id, null, 'musteri_resim_sil', 'musteri_resim', (int) $satir->id, null, 'Müşteri işlem resmi silindi');
        }
    }

    public function notYaz(int $userId, string $yol, ?string $not, ?int $salonId): array
    {
        $satir = $this->sahipSatir($userId, $yol, $salonId);
        DB::table('islemler')->where('id', $satir->id)->update(['resim_notu' => $not, 'updated_at' => now()]);
        if ($satir->salon_id) {
            AktiviteLog::yaz((int) $satir->salon_id, null, 'musteri_resim_not', 'musteri_resim', (int) $satir->id, null, 'Müşteri işlem resmi notu güncellendi');
        }

        return ['path' => $yol, 'not' => $not];
    }

    private function sahipSatir(int $userId, string $yol, ?int $salonId): object
    {
        $satirlar = DB::table('islemler')->where('user_id', $userId)->when($salonId, fn ($q) => $q->where('salon_id', $salonId))->get(['id', 'salon_id', 'islem_fotolari']);
        foreach ($satirlar as $s) {
            if (in_array($yol, $this->yollar($s), true)) {
                return $s;
            }
        }

        throw new BulunamadiException('Resim bulunamadı');
    }

    private function yollar(object $satir): array
    {
        $liste = json_decode((string) $satir->islem_fotolari, true);

        return is_array($liste) ? $liste : [];
    }

    private function dizin(): string
    {
        return rtrim((string) config('ayar.eski_public_kok'), '/').'/'.self::ALT_DIZIN;
    }

    /**
     * Salonun her aktif personelinin kutusuna ayri satir (v1 musteriResimEkle ile ayni metin); kutu personel_id ile
     * okur. v1 ayni olayda push da gonderir: v2_bildirim_kuyrugu'nda bu olayin turu yok, push gitmez.
     */
    private function isletmeyeBildir(int $salonId, int $userId): void
    {
        $ad = DB::table('users')->where('id', $userId)->value('name') ?: 'Müşteri';
        $simdi = now();
        DB::table('bildirimler')->insert(DB::table('salon_personelleri')->where('salon_id', $salonId)->where('aktif', 1)->pluck('id')
            ->map(fn ($personelId) => [
                'aciklama' => $ad.' bir fotoğraf ekledi', 'salon_id' => $salonId, 'personel_id' => (int) $personelId, 'url' => '',
                'tarih_saat' => $simdi->format('Y-m-d H:i:s'), 'okundu' => 0, 'created_at' => $simdi, 'updated_at' => $simdi,
            ])->all());
    }
}
