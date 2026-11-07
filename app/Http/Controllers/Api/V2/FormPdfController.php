<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Form\ArsivIslemServisi;
use App\Services\Form\ArsivPdfServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Form ve sözleşme PDF'leri. v1 formgoster/formindir kimliksizdi: arsivid bilen herkese PDF
 * veriyordu. Burada token, salon kapsamı ve form yetkisi zorunludur.
 *
 *   GET /form/arsiv/{id}/pdf?indir=1           token + form.olustur VEYA form.gonder
 *   GET /form/sablon/{id}/bos-pdf-link?salon_id token + form.olustur VEYA form.gonder
 *   GET /form/sablon/{id}/bos-pdf?sube         imzalı kısa ömürlü link (kişisel veri yok)
 */
class FormPdfController extends V2Controller
{
    private const SAYFA = ['form.olustur', 'form.gonder'];

    private const LINK_DAKIKA = 5;

    public function __construct(
        private ArsivPdfServisi $pdf,
        private ArsivIslemServisi $islem,
        private GuvenlikKapisi $kapi,
        private YetkiServisi $yetki,
    ) {}

    public function arsiv(Request $request, int $id)
    {
        $uid = (int) $this->kullanici()->id;
        $salonId = $this->bulundu($this->islem->salonId($id), 'Kayıt bulunamadı.');
        $this->kapi->denetle($uid, $salonId, self::SAYFA);
        $belge = $this->pdf->belge($id, $this->yetki->yetkiliYetkiVar($uid, $salonId, 'musteri.telefon_gor'));
        $indir = $request->boolean('indir');

        if (isset($belge['dosya'])) {
            return $indir ? response()->download($belge['dosya']) : response()->file($belge['dosya']);
        }

        return $this->pdfCevabi($belge, $indir);
    }

    /** Harici tarayıcı token taşıyamaz; ekran bu linki açar. */
    public function bosLink(Request $request, int $id)
    {
        [, $salonId] = $this->salonDenetle($request, self::SAYFA);
        $this->pdf->bosTaslak($salonId, $id);

        return ApiCevap::basarili(['url' => url(URL::temporarySignedRoute(
            'v2.form.bos-pdf',
            now()->addMinutes(self::LINK_DAKIKA),
            ['id' => $id, 'sube' => $salonId],
            false,
        ))]);
    }

    public function bos(Request $request, int $id)
    {
        return $this->pdfCevabi($this->pdf->bosForm($request->integer('sube'), $id), true);
    }

    /** @param array{ad:string,pdf:mixed} $belge */
    private function pdfCevabi(array $belge, bool $indir)
    {
        $ad = $belge['ad'].'.pdf';

        return $indir ? $belge['pdf']->download($ad) : $belge['pdf']->stream($ad);
    }
}
