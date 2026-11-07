<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Models\Randevular;
use App\Services\BildirimKuyrugu\Randevu\RandevuMesajKanali;
use App\Services\BildirimKuyrugu\Randevu\RandevuVerisi;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** kvkk_kodu: eski V2BildirimIsle kvkk dali + ApiController::kvkkOnayKoduGonder. Yalniz SMS, dogrulama=true (iletim raporu yazilmaz). */
class KvkkKoduIsleyici extends RandevuTabanliIsleyici
{
    public function __construct(
        private RandevuVerisi $veri,
        private RandevuMesajKanali $kanal,
    ) {}

    protected function randevuIsle(object $kayit, array $payload, Randevular $randevu): void
    {
        $onayKodu = DB::table('musteri_portfoy')
            ->where('user_id', $randevu->user_id)->where('salon_id', $randevu->salon_id)
            ->value('onay_kodu');
        if (! $onayKodu) {
            throw new RuntimeException('Portfoy onay_kodu yok');
        }

        $salon = $this->veri->salon($randevu->salon_id);
        $onayMesaji = 'Sn. '.$randevu->users->name.', '.$onayKodu.' numarali onay kodunu ilgiliye soyleyerek, '.$salon->kvkk_link." kvkk linkinde yer alan KVKK Aydinlatma Metni'ne gore kisisel verilerinizin islenmesine ve sistemlerimizde kayitli iletisim adresleriniz uzerinden SMS, e-posta ve aramalar vasitasiyla ticari elektronik ileti gonderimine izin vermis olacaksiniz";

        $this->kanal->sms([['to' => $randevu->users->cep_telefon, 'message' => $onayMesaji]], $randevu->salon_id, true);
    }
}
