<?php

namespace App\Services\Seans;

use App\Domain\Seans\SeansDokumu;
use App\Domain\Seans\SeansKalemTuru;
use App\Models\Salonlar;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Seans dokumu PDF'i — eski StoreAdminController::seansDokumuPdf ile ayni icerik ve blade.
 * Kapsam denetimi controller'dadir; kalem SeansKapsam::kalem ile salonuyla cozulur.
 */
class SeansDokumServisi
{
    private const LOGO_PX = 160;

    /** @return array{pdf:mixed, ad:string} */
    public function pdf(SeansKalemTuru $tur, object $kalem): array
    {
        $paketMi = $tur === SeansKalemTuru::PAKET;
        $satir = DB::table($tur->tablo())->where('id', $kalem->id)->first();
        $seanslar = DB::table('adisyon_paket_seanslar')->where($tur->seansKolonu(), $kalem->id)
            ->orderBy('seans_tarih')->orderBy('seans_no')
            ->get(['id', 'hizmet_id', 'seans_no', 'seans_tarih', 'geldi']);

        $baslik = (string) ($paketMi
            ? DB::table('paketler')->where('id', $kalem->katalog_id)->value('paket_adi')
            : DB::table('hizmetler')->where('id', $kalem->katalog_id)->value('hizmet_adi'));
        $hizmetSayisi = $paketMi ? DB::table('paket_hizmetler')->where('paket_id', $kalem->katalog_id)->count() : 0;
        $musteri = DB::table('users')->where('id', $kalem->user_id)->first(['name', 'cep_telefon', 'tc_kimlik_no']);
        $isletme = Salonlar::whereKey($kalem->salon_id)->first();

        $pdf = Pdf::loadView('seans-pdf.seans_dokumu_pdf', [
            'isletme'    => $isletme,
            'logo'       => $this->logo($isletme?->logo),
            'musteri'    => $musteri,
            'baslik'     => $baslik,
            'toplam'     => SeansDokumu::toplam($satir->seans_sayisi ?? null, $paketMi, $hizmetSayisi, $paketMi && ! empty($satir->sinirsiz)),
            'kullanilan' => $seanslar->where('geldi', 1)->count(),
            'baslangic'  => SeansDokumu::baslangic($paketMi ? ($satir->baslangic_tarihi ?? null) : ($satir->islem_tarihi ?? null), $seanslar->pluck('seans_tarih')),
            'bolgeler'   => SeansDokumu::bolgeler($seanslar->map(fn ($s) => (array) $s), $this->hizmetAdlari($seanslar), $this->cihazVerileri($seanslar->pluck('id')->all())),
        ])->setPaper('a4', 'portrait');

        return ['pdf' => $pdf, 'ad' => 'seans-dokumu-'.$tur->value.'-'.$kalem->id.'.pdf'];
    }

    private function hizmetAdlari($seanslar): array
    {
        return DB::table('hizmetler')->whereIn('id', $seanslar->pluck('hizmet_id')->filter()->unique()->all())->pluck('hizmet_adi', 'id')->all();
    }

    /** @return array<int,list<array<string,mixed>>> */
    private function cihazVerileri(array $seansIdler): array
    {
        $sonuc = [];
        $satirlar = DB::table('seans_cihaz_verileri as v')->leftJoin('salon_personelleri as p', 'p.id', '=', 'v.personel_id')
            ->whereIn('v.seans_id', $seansIdler)->orderBy('v.id')
            ->get(['v.seans_id', 'v.enerji', 'v.hiz', 'v.ms', 'v.atis_sayisi', 'p.personel_adi']);
        foreach ($satirlar as $v) {
            $sonuc[$v->seans_id][] = (array) $v;
        }

        return $sonuc;
    }

    /** Logo kucultulup gomulur: tam boy logo dompdf'i yavaslatir. Dosya yoksa null. */
    private function logo(?string $yol): ?string
    {
        $goreli = ltrim((string) preg_replace('#^/?public/#', '', trim((string) $yol)), '/');
        if ($goreli === '' || str_contains($goreli, '..')) {
            return null;
        }
        $tam = rtrim((string) config('ayar.eski_public_kok'), '/').'/'.$goreli;
        if (! is_file($tam) || ! is_readable($tam)) {
            return null;
        }

        return Cache::remember('seansdokumu_logo_'.md5($tam.'|'.filemtime($tam).'|'.filesize($tam)), now()->addDays(7), function () use ($tam) {
            return $this->kucukLogo($tam) ?? 'data:'.(getimagesize($tam)['mime'] ?? 'image/png').';base64,'.base64_encode((string) file_get_contents($tam));
        });
    }

    private function kucukLogo(string $yol): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! ($bilgi = @getimagesize($yol))) {
            return null;
        }
        $kaynak = @imagecreatefromstring((string) file_get_contents($yol));
        if (! $kaynak) {
            return null;
        }
        $olcek = min(1, self::LOGO_PX / max($bilgi[0], $bilgi[1]));
        $g = max(1, (int) round($bilgi[0] * $olcek));
        $y = max(1, (int) round($bilgi[1] * $olcek));
        $hedef = imagecreatetruecolor($g, $y);
        imagealphablending($hedef, false);
        imagesavealpha($hedef, true);
        imagefilledrectangle($hedef, 0, 0, $g, $y, imagecolorallocatealpha($hedef, 0, 0, 0, 127));
        imagecopyresampled($hedef, $kaynak, 0, 0, 0, 0, $g, $y, $bilgi[0], $bilgi[1]);
        ob_start();
        imagepng($hedef, null, 6);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }
}
