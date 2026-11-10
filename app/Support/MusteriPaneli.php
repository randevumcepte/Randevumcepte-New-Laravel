<?php

namespace App\Support;

/**
 * Sitedeki müşteri paneli sayfalarının TEK kaydı: rotalar (routes/web.php), linkler (SiteController::ortak) ve menü
 * (site/hesap/layout) buradan üretilir. Mobil uygulamanın müşteri menüsüyle aynı sayfalar ve aynı görünürlük kuralı
 * (kullanıcı 2026-11-10: "uygulamadaki müşteri işlevlerinin tamamı").
 *
 * Her sayfa: yol (adres parçası) => [anahtar (görünüm site.hesap.{anahtar} ve link anahtarı), başlık, ikon, menüde mi, koşul].
 * Koşul: null (her salon), 'studyo' (yalnız stüdyo modu), 'studyo_degil', 'grup_dersi' (grup dersi açık salon).
 */
final class MusteriPaneli
{
    public const SAYFALAR = [
        'giris'            => ['giris', 'Giriş', 'fa-sign-in', false, null],
        'hesabim'          => ['hesabim', 'Hesabım', 'fa-th-large', true, null],
        'randevularim'     => ['randevular', 'Randevularım', 'fa-calendar', true, null],
        'grup-derslerim'   => ['grup-dersleri', 'Grup Derslerim', 'fa-users', true, 'grup_dersi'],
        'seanslarim'       => ['seanslar', 'Seanslarım', 'fa-hourglass-half', true, null],
        'satin-aldiklarim' => ['satin-aldiklarim', 'Satın Aldıklarım', 'fa-shopping-bag', true, null],
        'odullerim'        => ['oduller', 'Ödüllerim & Kuponlarım', 'fa-gift', true, 'studyo_degil'],
        'belgelerim'       => ['belgeler', 'Sözleşme / Belgelerim', 'fa-file-text-o', true, 'studyo_degil'],
        'saglik'           => ['saglik', 'Sağlık Bilgilerim', 'fa-heartbeat', true, null],
        'gelisimim'        => ['gelisim', 'Gelişimim', 'fa-line-chart', true, 'studyo'],
        'resimlerim'       => ['resimler', 'Resimlerim', 'fa-picture-o', true, null],
        'bildirimler'      => ['bildirimler', 'Bildirimler', 'fa-bell', true, null],
        'profilim'         => ['profil', 'Profilim', 'fa-user', true, null],
        'sifrem'           => ['sifre', 'Şifrem', 'fa-lock', true, null],
    ];

    /** @return array<string,string> anahtar => yol */
    public static function yollar(): array
    {
        return array_combine(array_column(self::SAYFALAR, 0), array_keys(self::SAYFALAR));
    }

    /** Salona göre menü: [anahtar, başlık, ikon, yol][]. */
    public static function menu(object $salon): array
    {
        $menu = [];
        foreach (self::SAYFALAR as $yol => [$anahtar, $baslik, $ikon, $menude, $kosul]) {
            if ($menude && self::kosulTutar($kosul, $salon)) {
                $menu[] = ['anahtar' => $anahtar, 'baslik' => $baslik, 'ikon' => $ikon, 'yol' => $yol];
            }
        }

        return $menu;
    }

    /** Sayfa bu salonda açılabilir mi (menüde gizli sayfa adres yazılarak da açılmaz). */
    public static function acikMi(string $anahtar, object $salon): bool
    {
        foreach (self::SAYFALAR as [$a, , , , $kosul]) {
            if ($a === $anahtar) {
                return self::kosulTutar($kosul, $salon);
            }
        }

        return false;
    }

    private static function kosulTutar(?string $kosul, object $salon): bool
    {
        $studyo = (bool) ($salon->studyo_modu ?? false);

        return match ($kosul) {
            'studyo' => $studyo, 'studyo_degil' => ! $studyo, 'grup_dersi' => (bool) ($salon->grup_dersi_aktif ?? false), default => true,
        };
    }
}
