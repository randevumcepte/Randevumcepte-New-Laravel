<?php

namespace App\Services\Sms;

use App\Domain\Sms\MesajUzunlugu;
use App\Domain\Sms\SmsSaglayici;
use App\Domain\Sms\SmsTuru;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Services\Sms\Bakiye\SmsBakiyeServisi;
use App\Services\Sms\Gonderim\SmsKuyrukServisi;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use Illuminate\Support\Facades\Cache;

/**
 * TOPLU / FİLTRELİ SMS — alıcı çözümü, maliyet önizlemesi, kuyruğa yazma.
 *
 * Gönderim v2'nin kendi kuyruğuyla yapılır (SmsKuyrukServisi + `sms:kuyruk-isle`); canlı monolite kod
 * gerektirmez. VoiceTelekom bu kuyruktadır, cevap "kuyruğa alındı"dır ve sonuç GET /sms/gonderim/{id} ile izlenir.
 * Efetech salonları eski job yoluna gider (`sms_toplu` outbox satırı, SmsTopluGonderIsi): izlenecek
 * gönderim kaydı yoktur, `gonderim_id` null döner.
 */
class SmsGonderimServisi
{
    public function __construct(
        private SmsHesapServisi $hesap,
        private SmsAliciServisi $alici,
        private SmsBakiyeServisi $bakiye,
        private SmsKuyrukServisi $kuyruk,
    ) {}

    /** Gönderim öncesi özet (onay kutusu): kaç kişiye gider, kaç kredi tutar, bakiye yeter mi. */
    public function onizle(int $salonId, ?int $kisit, array $idler, string $mesaj): array
    {
        return $this->ozet($salonId, $this->alici->cozumle($salonId, $kisit, $idler), $mesaj);
    }

    public function gonder(int $yetkiliId, int $salonId, ?int $kisit, SmsTuru $tur, array $idler, string $mesaj): array
    {
        $salon = $this->hesap->zorunlu($salonId);
        $saglayici = SmsSaglayici::salondan($salon);
        if (! $saglayici->hesapTanimli($salon)) {
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

        $icerik = [
            'telefonlar' => $cozum['telefonlar'],
            'mesaj'      => $mesaj,
            'tur'        => $tur->value,
            'baslik'     => $tur->saglayiciBasligi(),
        ];
        $gonderimId = null;
        if ($saglayici === SmsSaglayici::VoiceTelekom) {
            $gonderimId = $this->kuyruk->ekle($salonId, $yetkiliId, $icerik);
        } else {
            BildirimKuyrugu::yaz($salonId, 'sms_toplu', null, $yetkiliId, $icerik);
        }
        $adet = count($cozum['telefonlar']);
        AktiviteLog::yaz($salonId, $yetkiliId, 'toplu_sms_gonder', 'toplu_sms', null, $adet.' alıcı', 'Toplu SMS gönderimi kuyruğa alındı', [
            'alici_sayisi' => $adet, 'tur' => $tur->kod(), 'mesaj_ozet' => mb_substr($mesaj, 0, 140),
        ]);

        return ['gonderim_id' => $gonderimId] + $this->ozet($salonId, $cozum, $mesaj);
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
