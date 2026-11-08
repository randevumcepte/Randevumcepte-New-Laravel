<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Services\BildirimKuyrugu\BildirimIsleyici;
use App\Services\BildirimKuyrugu\Kanal\MesajListesi;
use App\Services\BildirimKuyrugu\Kanal\SmsKanali;
use RuntimeException;

/**
 * Duz bildirim (kasa bildirimleri ve onay kodlari, kampanya katilim linki, dogum gunu, mobil para SMS'leri). WhatsApp oncelikli
 * (sistem kurali), gidemeyen salonun saglayicisindan SMS; kara liste uygulanir. dogrulama=true: kod mesaji, acil gider ve
 * iletim raporuna yazilmaz. toplu=true (kampanya duyurusu): WhatsApp denenmez, yalniz SMS (salonun saglayicisi, kara liste
 * uygulanir); toplu SMS Yonetimi zaten ayri yoldan SMS'tir. Eskiden yalniz Efetech'ti: VoiceTelekom salonunda hic gitmiyordu, WhatsApp ve kara liste yoktu.
 */
class SmsIsleyici implements BildirimIsleyici
{
    public function __construct(private SmsKanali $sms) {}

    public function isle(object $kayit, array $payload): void
    {
        $mesajlar = MesajListesi::gecerliler($payload);
        if (! $mesajlar) {
            throw new RuntimeException('sms: mesaj yok');
        }

        $dogrulama = ! empty($payload['dogrulama']);
        if (! empty($payload['toplu'])) {
            $this->sms->gonder2($mesajlar, false, 1, $dogrulama, (int) $kayit->salon_id);

            return;
        }
        if ($dogrulama) {
            $mesajlar = array_map(fn (array $m) => $m + ['urgent' => true], $mesajlar);
        }
        $this->sms->panelBildirimli($mesajlar, false, 1, $dogrulama, (int) $kayit->salon_id, (string) ($payload['gonderim_tipi'] ?? 'transaksiyonel'));
    }
}
