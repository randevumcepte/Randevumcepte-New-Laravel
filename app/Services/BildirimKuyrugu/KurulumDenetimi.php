<?php

namespace App\Services\BildirimKuyrugu;

/**
 * Bayrak acikken eksik kurulum mesajlari sessizce kaybettirir (push ve takvim hatalari yutulur, satir durum=1 olur). Eksik
 * varsa komut hicbir satira dokunmadan durur. Yalniz dosya ve ayar varligina bakar; icerik okunmaz, deger yazdirilmaz.
 */
class KurulumDenetimi
{
    /** Eski monolitin toplu is isleyicisi bu kuyrugu dinler; yeni isler orada kaybolur. */
    private const ESKI_KUYRUK = 'hatirlatmalar';

    /** @return list<string> eksik kurulum maddeleri */
    public function eksikler(): array
    {
        $eksik = [];
        $varsayilan = basename((string) config('firebase_projects.default'));
        $dizin = rtrim((string) config('bildirim_kuyrugu.firebase_anahtar_dizini'), '/');
        if (! is_file($dizin.'/'.$varsayilan)) {
            $eksik[] = 'Firebase varsayilan anahtar dosyasi yok: '.$varsayilan;
        }
        if (trim((string) config('bildirim_kuyrugu.sistem_sms.apikey')) === '') {
            $eksik[] = 'SMS_EFETECH_SISTEM_APIKEY tanimli degil';
        }
        if (! config('bildirim_kuyrugu.google_takvim.client_id') || ! config('bildirim_kuyrugu.google_takvim.client_secret')) {
            $eksik[] = 'GOOGLE_CLIENT_ID veya GOOGLE_CLIENT_SECRET tanimli degil';
        }
        if (config('bildirim_kuyrugu.kuyruk_adi') === self::ESKI_KUYRUK) {
            $eksik[] = 'BILDIRIM_KUYRUK_ADI eski isleyicinin kuyrugu olamaz: '.self::ESKI_KUYRUK;
        }

        return $eksik;
    }

    /** @return list<string> eksik marka anahtarlari: o markanin push'u gitmez, diger markalar calisir */
    public function uyarilar(): array
    {
        $dizin = rtrim((string) config('bildirim_kuyrugu.firebase_anahtar_dizini'), '/');
        $dosyalar = array_map('basename', array_merge(array_values((array) config('firebase_projects')), array_values((array) config('firebase_bundle_projects'))));
        $yok = array_filter(array_unique($dosyalar), fn ($d) => ! is_file($dizin.'/'.$d));

        return array_map(fn ($d) => 'Firebase anahtar dosyasi yok: '.$d, array_values($yok));
    }
}
