<?php

namespace App\Services\MusteriHesap;

use App\Exceptions\BulunamadiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Musterinin hesap silme talebi (v1 hesapSilmeTalebiGonderMusteri): teknik ekibe e-posta gider, kayit silinmez. */
class HesapSilmeTalebiServisi
{
    private const ALICI = 'teknik@randevumcepte.com.tr';

    public function gonder(int $userId): void
    {
        $musteri = DB::table('users')->where('id', $userId)->first(['name', 'cep_telefon']);
        if (! $musteri) {
            throw new BulunamadiException('Kullanıcı bulunamadı');
        }
        $salonlar = DB::table('musteri_portfoy')->where('user_id', $userId)->pluck('salon_id')->implode(' ');
        $metin = 'Müşteri Adı : '.$musteri->name.' Telefon : '.$musteri->cep_telefon.' İşletme idler : '.$salonlar;

        Mail::raw($metin, fn ($m) => $m->to(self::ALICI)->subject('Hesap Silme Talebi'));
    }
}
