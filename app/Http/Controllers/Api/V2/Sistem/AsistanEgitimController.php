<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Services\SistemYonetim\Denetim;
use App\Services\SistemYonetim\Entegrasyon\AsistanEgitimServisi as Servis;
use App\Domain\SistemYonetim\SistemYetki;
use App\Services\SistemYonetim\Entegrasyon\PdfKalipCikarici;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * Okuma tum roller; yazma super_admin,yonetici,destek (rota).
 *   GET    /sistem/asistan-egitim                → tablo_yok, ozet, izinler
 *   GET    /sistem/asistan-egitim/kaliplar       + ListeIstegi
 *   GET    /sistem/asistan-egitim/cozulmeyenler  + ListeIstegi
 *   POST   /sistem/asistan-egitim/kalip          {tetikleyiciler, cevap, kategori?, aktif?, cozulmeyen_id?}
 *   PUT    /sistem/asistan-egitim/kalip/{id}
 *   DELETE /sistem/asistan-egitim/kalip/{id}
 *   DELETE /sistem/asistan-egitim/cozulmeyen/{id}
 *   POST   /sistem/asistan-egitim/pdf-coz        form: pdf → maddeler, dosya (kaydetmez)
 *   POST   /sistem/asistan-egitim/pdf-kaydet     {maddeler:[{tetikleyiciler, cevap, kategori?}], dosya?}
 */
class AsistanEgitimController extends SistemTabanController
{
    private const MAKS_METIN = 65000;

    protected const ETIKETLER = ['tetikleyiciler' => 'Tetikleyiciler', 'cevap' => 'Cevap', 'kategori' => 'Kategori', 'aktif' => 'Aktif', 'maddeler' => 'Kaydedilecek madde'];

    public function __construct(private Servis $servis, private PdfKalipCikarici $pdf) {}

    public function acilis()
    {
        return ApiCevap::basarili($this->servis->acilis() + ['izinler' => ['yaz' => $this->izinli(SistemYetki::ISLEM)]]);
    }

    public function kaliplar(Request $request)
    {
        return ApiCevap::basarili($this->servis->kaliplar(ListeIstegi::istekten($request, 25, array_keys(Servis::KALIP_SIRALAMA), 'kullanim', 'desc')->dizi()));
    }

    public function cozulmeyenler(Request $request)
    {
        return ApiCevap::basarili($this->servis->cozulmeyenler(ListeIstegi::istekten($request, 25, array_keys(Servis::COZULMEYEN_SIRALAMA), 'adet', 'desc')->dizi()));
    }

    public function kalipEkle(Request $request)
    {
        $this->yazmaAcikMi();
        $g = $this->dogrula($request, $this->kalipKurallari() + ['cozulmeyen_id' => ['nullable', 'integer', 'min:1']]);
        $this->servis->kalipEkle($g);

        return ApiCevap::basarili(null, 'Kalıp eklendi. Artık bu sorular AI’ya gitmeden bedava cevaplanır.'.Servis::YANSIMA_NOTU);
    }

    public function kalipGuncelle(Request $request, int $id)
    {
        $this->yazmaAcikMi();
        $this->servis->kalipGuncelle($id, $this->dogrula($request, $this->kalipKurallari()));

        return ApiCevap::basarili(null, 'Kalıp güncellendi.'.Servis::YANSIMA_NOTU);
    }

    public function kalipSil(int $id)
    {
        $this->yazmaAcikMi();
        $this->servis->kalipSil($id);

        return ApiCevap::basarili(null, 'Kalıp silindi.'.Servis::YANSIMA_NOTU);
    }

    public function cozulmeyenSil(int $id)
    {
        $this->yazmaAcikMi();
        $this->servis->cozulmeyenSil($id);

        return ApiCevap::basarili(null, 'Soru listeden kaldırıldı.');
    }

    public function pdfCoz(Request $request)
    {
        $this->yazmaAcikMi();
        $dosya = $request->file('pdf');
        $maddeler = $this->pdf->cikar($dosya);
        Denetim::yaz('asistan_pdf_coz', 'asistan_kalip', null, $dosya->getClientOriginalName(), count($maddeler).' madde çıkarıldı', ['adet' => count($maddeler)]);

        return ApiCevap::basarili(['maddeler' => $maddeler, 'dosya' => $dosya->getClientOriginalName()],
            count($maddeler).' soru-cevap çıkarıldı. Kontrol edip kaydedin (istemediğinizin işaretini kaldırın).');
    }

    public function pdfKaydet(Request $request)
    {
        $this->yazmaAcikMi();
        $g = $this->dogrula($request, [
            'maddeler'                  => ['required', 'array', 'min:1'],
            'maddeler.*.tetikleyiciler' => ['nullable', 'string', 'max:'.self::MAKS_METIN],
            'maddeler.*.cevap'          => ['nullable', 'string', 'max:'.self::MAKS_METIN],
            'maddeler.*.kategori'       => ['nullable', 'string', 'max:40'],
            'dosya'                     => ['nullable', 'string', 'max:255'],
        ]);
        $adet = $this->servis->topluEkle($g['maddeler'], $g['dosya'] ?? null);

        return ApiCevap::basarili(['eklenen' => $adet], $adet.' kalıp eklendi. Bu sorular artık AI’ya gitmeden bedava cevaplanır.'.Servis::YANSIMA_NOTU);
    }

    private function kalipKurallari(): array
    {
        return [
            'tetikleyiciler' => ['required', 'string', 'max:'.self::MAKS_METIN],
            'cevap'          => ['required', 'string', 'max:'.self::MAKS_METIN],
            'kategori'       => ['nullable', 'string', 'max:40'],
            'aktif'          => ['nullable', 'boolean'],
        ];
    }
}
