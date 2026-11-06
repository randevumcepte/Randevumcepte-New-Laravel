<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Domain\SistemYonetim\SistemYetki;
use App\Domain\SistemYonetim\TalepDurumu;
use App\Domain\SistemYonetim\TalepKategorisi;
use App\Domain\SistemYonetim\TalepOnceligi;
use App\Services\SistemYonetim\Destek\TalepServisi;
use App\Support\ApiCevap;
use App\Support\CsvCevabi;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 *   GET  /sistem/talep/secenekler?salon_id=  → {durumlar, durum_filtreleri, oncelikler, kategoriler, ekip, secili_salon, yazabilir, csv}
 *   GET  /sistem/talep?durum=&oncelik=&atanan= + ListeIstegi (sirala: numara|konu|salon|kategori|oncelik|durum|atanan|tarih)
 *   GET  /sistem/talep/csv
 *   GET  /sistem/talep/{id}                  → {talep, mesajlar[], salon}
 *   POST /sistem/talep, /{id}/yanit, /{id}/durum, /{id}/ata, /{id}/oncelik
 */
class TalepController extends DestekTabanController
{
    public function __construct(private TalepServisi $servis) {}

    public function secenekler(Request $request)
    {
        $salonId = $request->integer('salon_id');

        return ApiCevap::basarili($this->servis->secenekler() + [
            'secili_salon' => $salonId ? $this->salon($salonId, ['id', 'salon_adi']) : null,
            'yazabilir'    => $this->izinli(SistemYetki::ISLEM),
            'csv'          => $this->izinli(SistemYetki::YONETIM),
        ]);
    }

    public function liste(Request $request)
    {
        $filtre = $this->dogrula($request, [
            'durum'   => ['nullable', Rule::in(TalepDurumu::filtreKodlari())],
            'oncelik' => ['nullable', Rule::enum(TalepOnceligi::class)],
            'atanan'  => ['nullable', 'regex:/^(bana|atanmamis|\d+)$/'],
        ]);
        $liste = ListeIstegi::istekten($request, 25, TalepServisi::siralanabilir(), null, 'desc')->dizi();

        return ApiCevap::basarili($this->servis->listele($liste, $filtre, $this->yonetici()));
    }

    public function csv()
    {
        return CsvCevabi::indir('ticketlar-'.date('Ymd-His').'.csv', TalepServisi::CSV_BASLIKLARI, $this->servis->csvSatirlari());
    }

    public function detay(int $id)
    {
        return ApiCevap::basarili($this->servis->detay($id));
    }

    public function olustur(Request $request)
    {
        $this->yazmaAcikMi();
        $g = $this->dogrula($request, [
            'konu'             => ['required', 'string', 'min:2', 'max:250'],
            'kategori'         => ['required', Rule::enum(TalepKategorisi::class)],
            'oncelik'          => ['required', Rule::enum(TalepOnceligi::class)],
            'salon_id'         => ['nullable', 'integer', 'min:1'],
            'aciklama'         => ['nullable', 'string', 'max:20000'],
            'iletisim_ad'      => ['nullable', 'string', 'max:120'],
            'iletisim_telefon' => ['nullable', 'string', 'max:30'],
            'iletisim_email'   => ['nullable', 'email', 'max:191'],
        ]);
        $salon = ! empty($g['salon_id']) ? $this->salon((int) $g['salon_id'], ['id', 'salon_adi']) : null;

        return ApiCevap::basarili($this->servis->olustur($g, $salon, $this->yonetici()), 'Talep oluşturuldu.', 201);
    }

    public function yanit(Request $request, int $id)
    {
        $this->yazmaAcikMi();
        $g = $this->dogrula($request, ['mesaj' => ['required', 'string', 'max:20000'], 'ic_not' => ['nullable', 'boolean']]);

        return ApiCevap::basarili($this->servis->yanitla($id, $g['mesaj'], ! empty($g['ic_not']), $this->yonetici()), 'Yanıt eklendi.');
    }

    public function durum(Request $request, int $id)
    {
        $this->yazmaAcikMi();
        $g = $this->dogrula($request, ['durum' => ['required', Rule::enum(TalepDurumu::class)]]);

        return ApiCevap::basarili($this->servis->durumDegistir($id, TalepDurumu::from($g['durum'])), 'Durum güncellendi.');
    }

    public function ata(Request $request, int $id)
    {
        $this->yazmaAcikMi();
        $g = $this->dogrula($request, ['atanan_user_id' => ['nullable', 'integer', 'min:1']]);

        return ApiCevap::basarili($this->servis->ata($id, isset($g['atanan_user_id']) ? (int) $g['atanan_user_id'] : null), 'Atama güncellendi.');
    }

    public function oncelik(Request $request, int $id)
    {
        $this->yazmaAcikMi();
        $g = $this->dogrula($request, ['oncelik' => ['required', Rule::enum(TalepOnceligi::class)]]);

        return ApiCevap::basarili($this->servis->oncelikDegistir($id, TalepOnceligi::from($g['oncelik'])), 'Öncelik güncellendi.');
    }
}
