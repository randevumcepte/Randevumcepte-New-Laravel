<?php

namespace App\Support;

/**
 * Sistem yonetimi ekranlari — TEK liste. Her ekran ayni kabuk gorunumu + kendi modulu:
 * public/js/sistem/<modul>.js (varsa public/css/sistem/<modul>.css). {id}'li yolda kayit id'si
 * #sy-ekran[data-id]'den okunur. Yeni ekran = buraya bir satir + modul dosyasi.
 */
final class SistemSayfalari
{
    /** @return array<string,array{modul:string,baslik:string,aktif:string}> */
    public static function liste(): array
    {
        return [
            'dashboard'                 => ['modul' => 'dashboard', 'baslik' => 'Sistem Yönetim Paneli', 'aktif' => 'dashboard'],
            'salonlar'                  => ['modul' => 'salonlar', 'baslik' => 'Salonlar', 'aktif' => 'salonlar'],
            'salon-ekle'                => ['modul' => 'salon-ekle', 'baslik' => 'Yeni Salon (Demo)', 'aktif' => 'salonlar'],
            'salon/{id}'                => ['modul' => 'salon-detay', 'baslik' => 'Salon', 'aktif' => 'salonlar'],
            'dakika'                    => ['modul' => 'dakika', 'baslik' => 'Dakika Yönetimi', 'aktif' => 'dakika'],
            'ai-kredi'                  => ['modul' => 'ai-kredi', 'baslik' => 'AI Kredi & Kullanım', 'aktif' => 'aikredi'],
            'asistan-egitim'            => ['modul' => 'asistan-egitim', 'baslik' => 'Asistan Eğitimi', 'aktif' => 'asistanegitim'],
            'ticket'                    => ['modul' => 'ticketlar', 'baslik' => 'Destek Talepleri', 'aktif' => 'ticket'],
            'ticket/yeni'               => ['modul' => 'ticket-yeni', 'baslik' => 'Yeni Destek Talebi', 'aktif' => 'ticket'],
            'ticket/{id}'               => ['modul' => 'ticket-detay', 'baslik' => 'Destek Talebi', 'aktif' => 'ticket'],
            'whatsapp'                  => ['modul' => 'whatsapp', 'baslik' => 'WhatsApp Yönetim', 'aktif' => 'whatsapp'],
            'sistem-whatsapp'           => ['modul' => 'sistem-whatsapp', 'baslik' => 'Sistem WhatsApp', 'aktif' => 'sistem-whatsapp'],
            'duyuru'                    => ['modul' => 'duyurular', 'baslik' => 'Duyurular', 'aktif' => 'duyuru'],
            'duyuru/yeni'               => ['modul' => 'duyuru-form', 'baslik' => 'Yeni Duyuru', 'aktif' => 'duyuru'],
            'duyuru/{id}'               => ['modul' => 'duyuru-detay', 'baslik' => 'Duyuru', 'aktif' => 'duyuru'],
            'duyuru/{id}/duzenle'       => ['modul' => 'duyuru-form', 'baslik' => 'Duyuru Düzenle', 'aktif' => 'duyuru'],
            'risk'                      => ['modul' => 'risk', 'baslik' => 'Risk Altındaki Salonlar', 'aktif' => 'risk'],
            'hazir-cevap'               => ['modul' => 'hazir-cevaplar', 'baslik' => 'Hazır Cevaplar', 'aktif' => 'hazircevap'],
            'manuel-odeme-linki'        => ['modul' => 'manuel-odeme', 'baslik' => 'Manuel Ödeme Linki', 'aktif' => 'manuelodeme'],
            'sms-paket'                 => ['modul' => 'sms-paketleri', 'baslik' => 'İnteraktif Duyuru Paketleri', 'aktif' => 'smspaket'],
            'duyuru-paketi-siparisleri' => ['modul' => 'paket-siparisleri', 'baslik' => 'Duyuru Paketi Siparişleri', 'aktif' => 'duyurusiparis'],
            'ekip'                      => ['modul' => 'ekip', 'baslik' => 'Ekip & Roller', 'aktif' => 'ekip'],
            'performans'                => ['modul' => 'performans', 'baslik' => 'Ekip Performansı', 'aktif' => 'performans'],
            'aktivite-log'              => ['modul' => 'aktivite-log', 'baslik' => 'Aktivite Logu', 'aktif' => 'aktivite'],
            'guvenlik/girisler'         => ['modul' => 'giris-loglari', 'baslik' => 'Giriş Logları', 'aktif' => 'guvenlik'],
            'guvenlik/impersonation'    => ['modul' => 'impersonation-loglari', 'baslik' => 'Salon Hesabına Giriş Logları', 'aktif' => 'guvenlik'],
            'guvenlik-duvari'           => ['modul' => 'guvenlik-duvari', 'baslik' => 'Güvenlik Duvarı', 'aktif' => 'guvenlik-duvari'],
            'sistem-saglik'             => ['modul' => 'sistem-saglik', 'baslik' => 'Sistem Sağlık', 'aktif' => 'saglik'],
            'profil'                    => ['modul' => 'profil', 'baslik' => 'Profilim', 'aktif' => 'profil'],
        ];
    }
}
