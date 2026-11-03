<?php

namespace App\Domain\Form;

/**
 * Dinamik form şablonu ELEMAN TİPLERİ kataloğu + soru listesi (formtaslaklari.sorular_json)
 * doğrulama/normalizasyonu — SAF, TEK KAYNAK.
 *
 * Eski sistemde tip listesi, etiketler ve "zorunlu" kuralı form_sablonlari.blade.php
 * JS'inde gömülüydü; sunucu JSON'u olduğu gibi yazıyordu. Burada katalog API ile
 * istemcilere verilir (web + Flutter sabit liste tutmaz) ve `zorunlu` istemciden
 * okunmaz, tipten türetilir.
 *
 * Kanonik öğe: {tip: string, soru: string, zorunlu: bool}.
 * Otomatik (sözleşme) tiplerinde `soru` tip adının kendisidir (eskiyle aynı).
 */
final class SablonSorulari
{
    public const MAKS_SORU = 200;
    public const MAKS_UZUNLUK = 5000;

    public const GRUP_YAPI = 'yapi';
    public const GRUP_OTOMATIK = 'otomatik';
    public const GRUP_CEVAP = 'cevap';

    /**
     * tip => [grup, etiket, ikon (bootstrap-icons), renk, girdi (tek|cok), ipucu, alt_not, zorunlu]
     * zorunlu: true = müşteri doldurmadan gönderemez, false = opsiyonel cevap, null = cevapsız eleman.
     * Sıra = oluşturucudaki buton sırası. 'bilgi_metni' eski tiptir: yalnız mevcut
     * şablonlarda görüntülenir, yeni eklenemez (eklenebilir=false).
     */
    private const TIPLER = [
        'bolum_basligi' => [self::GRUP_YAPI, 'Bölüm Başlığı', 'bi-type-h1', '#343a40', 'tek', 'BÖLÜM BAŞLIĞI (büyük harf önerilir)...', null, null],
        'alt_baslik'    => [self::GRUP_YAPI, 'Alt Başlık', 'bi-type-bold', '#6c757d', 'tek', 'Alt başlık metni (kalın gösterilir)...', null, null],
        'metin_blogu'   => [self::GRUP_YAPI, 'Metin Bloğu', 'bi-paragraph', '#6c757d', 'cok', 'Paragraf metni...', null, null],
        'madde_listesi' => [self::GRUP_YAPI, 'Madde Listesi', 'bi-list-ul', '#6c757d', 'cok', "Her satıra bir madde yazın:\nKızarıklık (eritem).\nYan etki sadece geçici...", 'Her satır ayrı bir madde olarak gösterilir (• işaretiyle)', null],
        'not_kutusu'    => [self::GRUP_YAPI, 'Not Kutusu', 'bi-exclamation-circle', '#17a2b8', 'cok', 'Not kutusu metni (kenarlıklı gri kutuda gösterilir)...', null, null],

        'musteri_bilgi_tablosu' => [self::GRUP_OTOMATIK, 'Müşteri Bilgileri (otomatik)', 'bi-person', '#0dcaf0', null, null, 'Müşteri ad, soyad, telefon ve tarih otomatik gösterilir', null],
        'hizmet_paket_bilgisi'  => [self::GRUP_OTOMATIK, 'Hizmet/Paket (otomatik)', 'bi-bag', '#0dcaf0', null, null, 'Seçilen hizmet veya paket adı otomatik gösterilir', null],
        'ucret_bilgisi'         => [self::GRUP_OTOMATIK, 'Ücret & Kapora (otomatik)', 'bi-cash', '#0dcaf0', null, null, 'Toplam ücret, kapora ve kalan bakiye otomatik gösterilir', null],
        'seans_bilgisi'         => [self::GRUP_OTOMATIK, 'Seans Sayısı (otomatik)', 'bi-calendar-check', '#0dcaf0', null, null, 'Seans sayısı otomatik gösterilir', null],
        'tarih_yer'             => [self::GRUP_OTOMATIK, 'Tarih & İşletme Adresi', 'bi-geo-alt', '#0dcaf0', null, null, 'Sözleşme tarihi ve işletme adresi otomatik gösterilir', null],

        'evet_hayir'    => [self::GRUP_CEVAP, 'Evet/Hayır Sorusu', 'bi-check2-square', '#5C008E', 'tek', 'Soru metni...', null, true],
        'checkbox_grup' => [self::GRUP_CEVAP, 'Seçmeli Kutucuklar', 'bi-ui-checks', '#5C008E', 'cok', "Her satıra bir seçenek yazın:\nHamilelik\nEmzirme\nKalp pili...", 'Her satır ayrı bir işaretlenebilir kutucuk olur — müşteri birden fazla seçebilir', false],
        'onay_kutusu'   => [self::GRUP_CEVAP, 'Onay Kutusu', 'bi-check-lg', '#5C008E', 'tek', 'Onay metni (örn: Tüm maddeleri okudum ve kabul ediyorum)...', 'Tek zorunlu onay kutucuğu — müşteri işaretlemeden formu gönderemez', true],
        'metin'         => [self::GRUP_CEVAP, 'Kısa Metin Girişi', 'bi-dash-lg', '#28a745', 'tek', 'Soru metni...', null, true],
        'metin_ops'     => [self::GRUP_CEVAP, 'Kısa Metin (Opsiyonel)', 'bi-dash-lg', '#28a745', 'tek', 'Alan etiketi (örn: Kullandığınız ilaçlar)...', 'Doldurulması zorunlu olmayan kısa metin alanı', false],
        'uzun_metin'    => [self::GRUP_CEVAP, 'Uzun Metin Girişi', 'bi-text-left', '#ffc107', 'cok', 'Soru metni...', null, true],

        'bilgi_metni'   => [self::GRUP_YAPI, 'Bilgi Metni', 'bi-info-circle', '#17a2b8', 'cok', 'Bilgi/açıklama metni...', null, null],
    ];

    private const ESKI_TIPLER = ['bilgi_metni'];

    private const GRUPLAR = [
        self::GRUP_YAPI     => ['etiket' => 'Yapı Elemanları', 'not' => null, 'yalniz_sozlesme' => false],
        self::GRUP_OTOMATIK => ['etiket' => 'Otomatik Sözleşme Bilgileri', 'not' => 'gönderirken doldurulur', 'yalniz_sozlesme' => true],
        self::GRUP_CEVAP    => ['etiket' => 'Müşteri Cevaplı Alanlar', 'not' => null, 'yalniz_sozlesme' => false],
    ];

    /** Oluşturucu için katalog (web + Flutter aynı listeyi çizer). */
    public static function katalog(): array
    {
        $tipler = [];
        foreach (self::TIPLER as $tip => [$grup, $etiket, $ikon, $renk, $girdi, $ipucu, $altNot, $zorunlu]) {
            $tipler[] = [
                'tip'        => $tip,
                'grup'       => $grup,
                'etiket'     => $etiket,
                'ikon'       => $ikon,
                'renk'       => $renk,
                'girdi'      => $girdi,      // tek | cok | null (otomatik: metin girilmez)
                'ipucu'      => $ipucu,
                'alt_not'    => $altNot,
                'zorunlu'    => $zorunlu,
                'eklenebilir'=> ! in_array($tip, self::ESKI_TIPLER, true),
            ];
        }
        $gruplar = [];
        foreach (self::GRUPLAR as $anahtar => $g) {
            $gruplar[] = ['grup' => $anahtar] + $g;
        }

        return ['gruplar' => $gruplar, 'tipler' => $tipler];
    }

    /**
     * @param mixed $girdi dizi ya da JSON string
     * @return array<int,array{tip:string,soru:string,zorunlu:bool}>
     *
     * @throws \InvalidArgumentException
     */
    public static function normalize(mixed $girdi): array
    {
        if (is_string($girdi)) {
            $girdi = json_decode($girdi, true);
        }
        if (! is_array($girdi)) {
            throw new \InvalidArgumentException('Form içeriği geçersiz.');
        }

        $sorular = [];
        foreach ($girdi as $s) {
            if (! is_array($s)) {
                continue;
            }
            $tip = (string) ($s['tip'] ?? '');
            if (! isset(self::TIPLER[$tip])) {
                throw new \InvalidArgumentException('Geçersiz eleman tipi: '.$tip);
            }
            $otomatik = self::TIPLER[$tip][0] === self::GRUP_OTOMATIK;
            $metin = $otomatik ? $tip : trim((string) ($s['soru'] ?? ''));
            if ($metin === '') {
                continue;
            }
            $sorular[] = [
                'tip'     => $tip,
                'soru'    => mb_substr($metin, 0, self::MAKS_UZUNLUK),
                'zorunlu' => self::TIPLER[$tip][7] === true,
            ];
        }

        if (! $sorular) {
            throw new \InvalidArgumentException('En az bir eleman ekleyin.');
        }
        if (count($sorular) > self::MAKS_SORU) {
            throw new \InvalidArgumentException('En fazla '.self::MAKS_SORU.' eleman eklenebilir.');
        }

        return $sorular;
    }

    /** Kayıtlı JSON'u güvenle diziye çevirir (bozuk JSON → boş liste). */
    public static function coz(?string $json): array
    {
        $d = $json ? json_decode($json, true) : null;

        return is_array($d) ? array_values(array_filter($d, 'is_array')) : [];
    }
}
