<?php

namespace App\Services\Sms;

use App\Domain\Sms\MesajUzunlugu;
use App\Domain\Sms\SmsSaglayici;
use App\Domain\Sms\SmsTuru;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Services\Sms\Bakiye\SmsBakiyeServisi;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use Illuminate\Support\Facades\Cache;

/**
 * TOPLU / FİLTRELİ SMS — alıcı çözümü, maliyet önizlemesi, kuyruğa yazma.
 *
 * v2 SMS ÜRETMEZ: outbox 'sms_toplu' → eski `v2:bildirim-isle` → SmsTopluGonderJob mevcut gönderim
 * koduyla (VoiceTelekom çoklu paket / Efetech WA-first) yollar. Sonuç anında bilinmediği için cevap
 * "kuyruğa alındı"dır; paket SMS Raporları'nda görünür.
 */
class SmsGonderimServisi
{
    public function __construct(
        private SmsHesapServisi $hesap,
        private SmsAliciServisi $alici,
        private SmsBakiyeServisi $bakiye,
    ) {}

    /** Gönderim öncesi özet (onay kutusu): kaç kişiye gider, kaç kredi tutar, bakiye yeter mi. */
    public function onizle(int $salonId, ?int $kisit, array $idler, string $mesaj): array
    {
        return $this->ozet($salonId, $this->alici->cozumle($salonId, $kisit, $idler), $mesaj);
    }

    public function gonder(int $yetkiliId, int $salonId, ?int $kisit, SmsTuru $tur, array $idler, string $mesaj): array
    {
        $salon = $this->hesap->zorunlu($salonId);
        if (! SmsSaglayici::salondan($salon)->hesapTanimli($salon)) {
            throw new GecersizIslemException('İşletmenin SMS hesabı tanımlı değil.');
        }
        $cozum = $this->alici->cozumle($salonId, $kisit, $idler);
        if (! $cozum['telefonlar']) {
            throw new GecersizIslemException('Seçili müşteriler kara listede ya da telefonsuz olduğu için mesaj gönderilemedi.');
        }

        // Çift tıklama / ağ tekrarı aynı mesajı iki kez ücretlendirmesin (web + mobil aynı koruma).
        $parmakIzi = 'sms.gonderim.'.$salonId.'.'.md5($mesaj.'|'.implode(',', $cozum['telefonlar']));
        if (! Cache::add($parmakIzi, 1, (int) config('sms.tekrar_kilit_sn'))) {
            throw new CakismaException('Bu mesaj az önce aynı alıcılara gönderildi. Birkaç dakika sonra tekrar deneyebilirsiniz.');
        }

        BildirimKuyrugu::yaz($salonId, 'sms_toplu', null, $yetkiliId, [
            'telefonlar' => $cozum['telefonlar'],
            'mesaj'      => $mesaj,
            'tur'        => $tur->value,
            'baslik'     => $tur->saglayiciBasligi(),
        ]);
        $adet = count($cozum['telefonlar']);
        AktiviteLog::yaz($salonId, $yetkiliId, 'toplu_sms_gonder', 'toplu_sms', null, $adet.' alıcı', 'Toplu SMS gönderimi kuyruğa alındı', [
            'alici_sayisi' => $adet, 'tur' => $tur->kod(), 'mesaj_ozet' => mb_substr($mesaj, 0, 140),
        ]);

        return $this->ozet($salonId, $cozum, $mesaj);
    }

    /** @param array{telefonlar:string[], kara_liste:int, gecersiz:int} $cozum */
    private function ozet(int $salonId, array $cozum, string $mesaj): array
    {
        $alici = count($cozum['telefonlar']);
        $parca = MesajUzunlugu::parca($mesaj);
        $kredi = $alici * $parca;
        $kalan = $this->bakiye->kalan($salonId);

        return [
            'alici'          => $alici,
            'kara_liste'     => $cozum['kara_liste'],
            'gecersiz'       => $cozum['gecersiz'],
            'parca'          => $parca,
            'tahmini_kredi'  => $kredi,
            'kalan_sms'      => $kalan,
            'bakiye_yeterli' => $kalan === null ? null : $kalan >= $kredi,
        ];
    }
}
