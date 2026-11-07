<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Services\BildirimKuyrugu\BildirimIsleyici;
use App\Services\BildirimKuyrugu\Kanal\FcmIstemcisi;
use App\Services\BildirimKuyrugu\Kanal\PushBildirimi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Yeni reklam kampanyasi: salon personeline push; eski KampanyaYonetimi::olusturmaBildirimiGonder + BildirimController::bildirimGonder.
 * Bu yol NotificationService degildir: yalniz data mesaji, sabit varsayilan Firebase projesi, kendi bildirimler satiri.
 * Kampanya yoksa hata (yeniden denenir); gonderim hatasi loglanir, kalan cihazlar atlanir, kayit tamamlanir (eskisi gibi).
 *
 * Eski hata KORUNUR: token'lar isletme_yetkili_id'de yetkili id tasir, sorgu salon_personelleri.id ile arar.
 * Token sorgusunda eskideki gibi aktif filtresi yoktur. Metindeki 'olşturdunuz' yazimi eskiyle aynidir.
 */
class KampanyaOlusturIsleyici implements BildirimIsleyici
{
    private const BASLIK = 'Yeni Reklam Kampanyası Oluşturma';

    private const GORSEL = '/public/yeni_panel/vendors/images/eczane24-icon.jpg';

    public function __construct(private FcmIstemcisi $fcm) {}

    public function isle(object $kayit, array $payload): void
    {
        $kampanya = DB::table('kampanya_yonetimi')->where('id', (int) ($payload['kampanya_id'] ?? 0))->first();
        if (! $kampanya) {
            throw new RuntimeException('kampanya_olustur: kampanya yok: '.($payload['kampanya_id'] ?? '-'));
        }

        try {
            $personeller = DB::table('salon_personelleri')->where('salon_id', $kampanya->salon_id)->pluck('id');
            $tokenlar = DB::table('bildirim_kimlikleri')->whereIn('isletme_yetkili_id', $personeller)->whereNotNull('bildirim_id')
                ->where('salon_id', $kampanya->salon_id)->get();

            $metin = 'Sisteme '.$kampanya->paket_isim.' ile ilgili reklam kaydını başarıyla olşturdunuz.';
            foreach ($tokenlar as $token) {
                $this->cihazaGonder($token->bildirim_id, $metin, $kampanya->salon_id);
            }
        } catch (\Throwable $e) {
            Log::error('[KAMPANYA-KAYDET-HATA] push bildirim basarisiz (kayit korundu)', ['kampanya_id' => $kampanya->id, 'hata' => $e->getMessage()]);
        }
    }

    /** Gecersiz token silinir ve sessizce gecilir; diger hata firlar. */
    private function cihazaGonder(string $token, string $metin, int|string $salonId): void
    {
        try {
            $this->fcm->gonder((string) config('firebase_projects.default'), [
                'token' => $token,
                'data'  => [
                    'title'    => self::BASLIK,
                    'body'     => $metin,
                    'category' => 'reklam',
                    'buttons'  => '[]',
                    'userInfo' => '',
                    'olcum'    => '',
                    'sound'    => 'ring',
                ],
                'android' => ['priority' => 'HIGH'],
                'apns'    => [
                    'headers' => ['apns-priority' => '10'],
                    'payload' => ['aps' => ['sound' => 'ring.caf', 'badge' => 1, 'category' => 'reklam']],
                ],
            ], 0); // eski bildirimGonder zaman asimsiz
            $this->bildirimYaz($salonId, $metin);
        } catch (\Throwable $e) {
            if (! PushBildirimi::gecersizTokenHatasiMi($e->getMessage())) {
                throw $e;
            }
            try {
                DB::table('bildirim_kimlikleri')->where('bildirim_id', $token)->delete();
            } catch (\Throwable $silmeHatasi) {
                Log::warning('bildirimGonder token silme hatasi: '.$silmeHatasi->getMessage());
            }
        }
    }

    /** Eski bildirimKaydet: user_id bos, url 'kampanya'. */
    private function bildirimYaz(int|string $salonId, string $metin): void
    {
        DB::table('bildirimler')->insert([
            'salon_id'       => $salonId,
            'user_id'        => null,
            'baslik'         => self::BASLIK,
            'aciklama'       => $metin,
            'url'            => 'kampanya',
            'img_src'        => self::GORSEL,
            'tarih_saat'     => date('Y-m-d H:i:s'),
            'okundu'         => 0,
            'butonlar'       => '[]',
            'olcum_id'       => null,
            'ilac_id'        => null,
            'adet_duzeni_id' => null,
            'randevu_id'     => null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }
}
