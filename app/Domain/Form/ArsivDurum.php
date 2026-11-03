<?php

namespace App\Domain\Form;

/**
 * Arşiv (form / sözleşme / harici belge) satırının TÜRÜ, DURUMU ve izinli
 * İŞLEMLERİ — SAF (DB yok, test edilebilir). Web ve Flutter bu kararları yeniden
 * hesaplamaz; API her satırda `tur`, `durum` ve `aksiyonlar` olarak hazır gönderir.
 *
 * Eski kaynak: StoreAdminController@arsiv_liste_getir (SQL içinde HTML üreten 5
 * kopya CASE) + Flutter colorandtext.dart getStatusColorArsiv.
 *
 * arsiv.durum: NULL = bekliyor, 1 = onaylandı, 0 = iptal.
 * cevapladi / cevapladi2: 1 = müşteri doldurdu (onay bekliyor), 0 = form bekleniyor,
 * ikisi de NULL = harici belge (cevap akışı yok).
 */
final class ArsivDurum
{
    public const TUR_FORM = 'form';
    public const TUR_SOZLESME = 'sozlesme';
    public const TUR_HARICI = 'harici';

    /** Liste sekmeleri (web + Flutter aynı anahtarlar). */
    public const SEKMELER = ['tumu', 'onayli', 'beklenen', 'iptal', 'harici'];

    private const DURUMLAR = [
        'onaylandi'       => ['etiket' => 'Onaylandı',       'renk' => 'yesil'],
        'iptal'           => ['etiket' => 'İptal Edildi',    'renk' => 'kirmizi'],
        'onay_bekleniyor' => ['etiket' => 'Onay Bekleniyor', 'renk' => 'turuncu'],
        'form_bekleniyor' => ['etiket' => 'Form Bekleniyor', 'renk' => 'turuncu'],
        'harici'          => ['etiket' => 'Harici Belge',    'renk' => 'mavi'],
    ];

    /** @param array{form_id?:mixed,is_sozlesme?:mixed} $s */
    public static function tur(array $s): string
    {
        if ((int) ($s['is_sozlesme'] ?? 0) === 1) {
            return self::TUR_SOZLESME;
        }

        return (int) ($s['form_id'] ?? 0) !== 0 ? self::TUR_FORM : self::TUR_HARICI;
    }

    /**
     * @param array{durum?:mixed,cevapladi?:mixed,cevapladi2?:mixed} $s
     * @return array{kod:string,etiket:string,renk:string}
     */
    public static function durum(array $s): array
    {
        $kod = match (true) {
            self::esit($s['durum'] ?? null, 1)                                         => 'onaylandi',
            self::esit($s['durum'] ?? null, 0)                                         => 'iptal',
            self::esit($s['cevapladi'] ?? null, 1) || self::esit($s['cevapladi2'] ?? null, 1) => 'onay_bekleniyor',
            self::esit($s['cevapladi'] ?? null, 0) || self::esit($s['cevapladi2'] ?? null, 0) => 'form_bekleniyor',
            default                                                                   => 'harici',
        };

        return ['kod' => $kod] + self::DURUMLAR[$kod];
    }

    /**
     * Satırın izinli işlemleri (eski web menüsüyle aynı kurallar):
     *   - harici belge  : İndir, Yazdır, İptal Et (zaten iptalse İptal yok)
     *   - bekleyen      : İndir, Yazdır, Formu Gönder, Onayla, İptal Et
     *   - onaylı / iptal: İndir, Yazdır, Formu Gönder (yeniden gönderim bekleyene çevirir)
     * "Formu Gönder" yalnız form.gonder yetkisiyle (eski arsivIslemlerFormGonderUygula).
     *
     * @param array{gonder?:bool} $yetkiler
     * @return array<string,bool>
     */
    public static function aksiyonlar(array $s, array $yetkiler): array
    {
        $harici = self::tur($s) === self::TUR_HARICI;
        $bekliyor = ($s['durum'] ?? null) === null || $s['durum'] === '';

        return [
            'goruntule' => true,
            'indir'     => true,
            'yazdir'    => true,
            'gonder'    => ! $harici && ! empty($yetkiler['gonder']),
            'onayla'    => ! $harici && $bekliyor,
            'iptal'     => $harici ? ! self::esit($s['durum'] ?? null, 0) : $bekliyor,
        ];
    }

    /** Sekme anahtarı geçerli mi (değilse 'tumu'). */
    public static function sekme(?string $sekme): string
    {
        return in_array($sekme, self::SEKMELER, true) ? $sekme : 'tumu';
    }

    /** NULL-güvenli sayısal eşitlik (SQL "NULL = 1" → false semantiği). */
    private static function esit($deger, int $hedef): bool
    {
        return $deger !== null && $deger !== '' && (int) $deger === $hedef;
    }
}
