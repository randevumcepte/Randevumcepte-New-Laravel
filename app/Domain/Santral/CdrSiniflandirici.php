<?php

namespace App\Domain\Santral;

/**
 * Santral CDR satirini siniflandiran SAF mantik (DB/HTTP yok, birim-test edilebilir).
 *
 * Eskiden ayni is iki yerde, FARKLI kurallarla yapiliyordu:
 *   - web : StoreAdminController@santral_raporlari  (HTML rozet uretiyordu)
 *   - mobil: ApiController@cdrRaporLatest           (durum kodu '0'..'9')
 * v2'de TEK kaynak burasidir. Kurallar web santral sayfasindan (canli kaynak)
 * alinmis, durum kodlari Flutter Cdr.durum ile birebir korunmustur.
 *
 * dcontext → durum:
 *   from-internal  NO ANSWER/BUSY → 1 Ulaşılamadı, aksi halde 2 Giden
 *   ext-local      NO ANSWER/BUSY → 0 Cevapsız,    aksi halde 3 Gelen
 *   ana-menu 4 Sonuçsuz · yol-tarifi 5 · kampanya-tanitimyeni 6 ·
 *   yeni-randevu-menusu 7 · randevu-guncelleme-menu(su) 8 · randevu-iptal-menusu 9
 *   Diger baglamlar (from-trunk-custom vb. ara bacaklar) rapora ALINMAZ.
 * Cagri merkezi originate'leri (accountcode/userfield = 'cmerkezi') rapora alinmaz.
 */
final class CdrSiniflandirici
{
    /** kod => [etiket, kategori (sekme/sayac), renk (UI token)] */
    public const DURUMLAR = [
        '0' => ['etiket' => 'Cevapsız',           'kategori' => 'cevapsiz',    'renk' => 'kirmizi'],
        '1' => ['etiket' => 'Ulaşılamadı',        'kategori' => 'ulasilamadi', 'renk' => 'kirmizi'],
        '2' => ['etiket' => 'Giden',              'kategori' => 'giden',       'renk' => 'mor'],
        '3' => ['etiket' => 'Gelen',              'kategori' => 'gelen',       'renk' => 'yesil'],
        '4' => ['etiket' => 'Sonuçsuz',           'kategori' => 'cevapsiz',    'renk' => 'koyu'],
        '5' => ['etiket' => 'Yol Tarifi',         'kategori' => 'gelen',       'renk' => 'mavi'],
        '6' => ['etiket' => 'Kampanya Tanıtımı',  'kategori' => 'gelen',       'renk' => 'turuncu'],
        '7' => ['etiket' => 'Randevu Alma',       'kategori' => 'gelen',       'renk' => 'turkuaz'],
        '8' => ['etiket' => 'Randevu Güncelleme', 'kategori' => 'gelen',       'renk' => 'turkuaz'],
        '9' => ['etiket' => 'Randevu İptali',     'kategori' => 'gelen',       'renk' => 'turkuaz'],
    ];

    /** Sekme/sayac kategorileri (gosterim sirasi). */
    public const KATEGORILER = ['gelen', 'giden', 'cevapsiz', 'ulasilamadi'];

    /** Sesli asistan menuleri: cevaplanmis sayilir ama arayani personel karsilamaz. */
    private const MENU_BAGLAMLARI = [
        'ana-menu'                  => '4',
        'yol-tarifi'                => '5',
        'kampanya-tanitimyeni'      => '6',
        'yeni-randevu-menusu'       => '7',
        'randevu-guncelleme-menusu' => '8',
        'randevu-guncelleme-menu'   => '8',
        'randevu-iptal-menusu'      => '9',
    ];

    private const ULASILAMADI = ['NO ANSWER', 'BUSY'];

    /**
     * @param array<string,mixed> $cdr             Santral API satiri (calldate, src, dst, dcontext, channel, disposition, billsec, recording_path…)
     * @param array<int,string>   $salonDahilileri Salonun TUM dahilileri (dahili_no + dahili_no_webrtc) — dahili-dahili aramayi ayirt etmek icin
     * @return array{yon:string, durum:string, kategori:string, etiket:string, renk:string, karsi_numara:string,
     *               personel_dahili:string, dahili_arama:bool, asistan:bool, ses_kaydi:?string, zaman:?string, sure_sn:int}|null
     *         null → rapora alinmaz
     */
    public static function siniflandir(array $cdr, array $salonDahilileri = []): ?array
    {
        if (($cdr['accountcode'] ?? '') === 'cmerkezi' || ($cdr['userfield'] ?? '') === 'cmerkezi') {
            return null;
        }

        $baglam = (string) ($cdr['dcontext'] ?? '');
        $cevapsiz = in_array((string) ($cdr['disposition'] ?? ''), self::ULASILAMADI, true);
        $dst = (string) ($cdr['dst'] ?? '');

        if ($baglam === 'from-internal') {
            $dstRakam = self::rakam($dst);

            return self::sonuc(
                $cdr,
                yon: 'giden',
                durum: $cevapsiz ? '1' : '2',
                karsiNumara: $dst,
                personelDahili: self::kanalDahilisi((string) ($cdr['channel'] ?? '')),
                dahiliArama: $dstRakam !== '' && in_array($dstRakam, $salonDahilileri, true),
                sesKaydiVar: ! $cevapsiz,
            );
        }

        if ($baglam === 'ext-local') {
            return self::sonuc($cdr, 'gelen', $cevapsiz ? '0' : '3', (string) ($cdr['src'] ?? ''), self::rakam($dst), false, ! $cevapsiz);
        }

        if (isset(self::MENU_BAGLAMLARI[$baglam])) {
            return self::sonuc($cdr, 'gelen', self::MENU_BAGLAMLARI[$baglam], (string) ($cdr['src'] ?? ''), self::rakam($dst), false, false, asistan: true);
        }

        return null;
    }

    /** Durum kodunun gosterim bilgisi (bilinmeyen kod → null). */
    public static function durumBilgisi(string $kod): ?array
    {
        return self::DURUMLAR[$kod] ?? null;
    }

    /** Kanal adindan ("PJSIP/101-0000a1" / "SIP/2040-…") dahili numarasi. */
    public static function kanalDahilisi(string $kanal): string
    {
        return preg_match('/^[^\/]+\/([^-\s]+)/', $kanal, $m) ? self::rakam($m[1]) : '';
    }

    /** Numaranin son 10 hanesi (format bagimsiz musteri eslemesi: 0 / 90 / +90 / 0090 farki yok). */
    public static function son10(string $numara): string
    {
        $r = self::rakam($numara);

        return strlen($r) >= 10 ? substr($r, -10) : '';
    }

    private static function rakam(string $s): string
    {
        return (string) preg_replace('/\D/', '', $s);
    }

    private static function sonuc(
        array $cdr,
        string $yon,
        string $durum,
        string $karsiNumara,
        string $personelDahili,
        bool $dahiliArama,
        bool $sesKaydiVar,
        bool $asistan = false,
    ): array {
        $kayit = trim((string) ($cdr['recording_path'] ?? ''));
        $zaman = strtotime((string) ($cdr['calldate'] ?? ''));

        return [
            'yon'             => $yon,
            'durum'           => $durum,
            'kategori'        => self::DURUMLAR[$durum]['kategori'],
            'etiket'          => self::DURUMLAR[$durum]['etiket'],
            'renk'            => self::DURUMLAR[$durum]['renk'],
            'karsi_numara'    => $karsiNumara,
            'personel_dahili' => $personelDahili,
            'dahili_arama'    => $dahiliArama,
            'asistan'         => $asistan,
            'ses_kaydi'       => $sesKaydiVar && $kayit !== '' ? $kayit : null,
            'zaman'           => $zaman ? date('Y-m-d H:i:s', $zaman) : null,
            'sure_sn'         => (int) ($cdr['billsec'] ?? 0),
        ];
    }
}
