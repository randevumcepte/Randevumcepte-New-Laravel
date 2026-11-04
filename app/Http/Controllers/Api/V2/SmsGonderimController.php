<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Sms\SmsTuru;
use App\Services\Randevu\RandevuFormServisi;
use App\Services\Sms\Gonderim\SmsKuyrukServisi;
use App\Services\Sms\SmsAliciServisi;
use App\Services\Sms\SmsGonderimServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * Alıcı seçimi + gönderim.
 *
 *   GET  /sms/alicilar?salon_id=&cinsiyet=0|1 + ListeIstegi → {data:[{id,name,telefon,kara_liste}], …meta}
 *   GET  /sms/alicilar/idler?salon_id=&ara=&cinsiyet=      → {idler[]} ("tümünü seç")
 *   GET  /sms/musteri-ara?salon_id=&q=                     → [{id,ad,telefon}] (v2-secici sözleşmesi)
 *   POST /sms/gonderim/onizle {musteri_idler[], mesaj}     → {alici, kara_liste, gecersiz, parca, tahmini_kredi, kalan_sms, bakiye_yeterli}
 *   POST /sms/gonderim        {musteri_idler[], mesaj, tur=toplu|filtre} → gonderim_id + aynı özet (202: kuyruğa alındı)
 *   GET  /sms/gonderim/{id}?salon_id=                      → {id, durum, etiket, tamamlandi, hata}
 */
class SmsGonderimController extends SmsTabanController
{
    public function __construct(private SmsAliciServisi $alici, private SmsGonderimServisi $gonderim) {}

    public function alicilar(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::GONDERIM);

        return ApiCevap::basarili($this->alici->liste(
            $salonId, $this->kisit($uid, $salonId), ListeIstegi::istekten($request, 50), $this->cinsiyet($request), $this->telefonGor($uid, $salonId),
        ));
    }

    public function aliciIdleri(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::GONDERIM);

        return ApiCevap::basarili(['idler' => $this->alici->idler(
            $salonId, $this->kisit($uid, $salonId), ListeIstegi::istekten($request)->ara, $this->cinsiyet($request),
        )]);
    }

    public function musteriAra(Request $request, RandevuFormServisi $form)
    {
        [$uid, $salonId] = $this->salon($request);

        return ApiCevap::basarili($form->musteriAra($salonId, (string) $request->input('q', ''), $this->telefonGor($uid, $salonId)));
    }

    public function onizle(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::GONDERIM);
        $v = $this->dogrula($request);

        return ApiCevap::basarili($this->gonderim->onizle($salonId, $this->kisit($uid, $salonId), $v['musteri_idler'], trim($v['mesaj'])));
    }

    public function gonder(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::GONDERIM);
        $v = $this->dogrula($request);
        $tur = SmsTuru::gonderilebilir((string) $request->input('tur', SmsTuru::Toplu->kod()));
        $this->yazmaAcikMi();

        return ApiCevap::basarili(
            $this->gonderim->gonder($uid, $salonId, $this->kisit($uid, $salonId), $tur, $v['musteri_idler'], trim($v['mesaj'])),
            'Gönderim kuyruğa alındı. Sonucu SMS Raporları\'nda görebilirsiniz.',
            202,
        );
    }

    public function durum(Request $request, int $id, SmsKuyrukServisi $kuyruk)
    {
        [, $salonId] = $this->salonDenetle($request, self::GONDERIM);

        return ApiCevap::basarili($kuyruk->durum($salonId, $id));
    }

    /** @return array{musteri_idler:int[], mesaj:string} */
    private function dogrula(Request $request): array
    {
        return $request->validate([
            'musteri_idler'   => ['required', 'array', 'min:1'],
            'musteri_idler.*' => ['integer', 'min:1'],
            'mesaj'           => ['required', 'string', 'max:2000'],
        ], [
            'musteri_idler.required' => 'Lütfen en az 1 müşteri seçiniz.',
            'musteri_idler.min'      => 'Lütfen en az 1 müşteri seçiniz.',
            'mesaj.required'         => 'Mesaj içeriği boş olamaz.',
        ]);
    }

    private function cinsiyet(Request $request): ?string
    {
        $c = $request->input('cinsiyet');

        return in_array((string) $c, ['0', '1'], true) ? (string) $c : null;
    }
}
