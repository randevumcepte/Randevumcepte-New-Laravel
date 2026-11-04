<?php

namespace App\Domain\Sms;

use App\Exceptions\GecersizIslemException;

/**
 * SMS bildirim ayarı KATALOĞU — salon_sms_ayarlari.ayar_id → başlık, açıklama, kime gider.
 * Eski ekranda blade'e gömülüydü ve "$sms_ayarlari[15]" gibi satır sırasına bağlıydı; artık kimlik
 * ayar_id. Web ve Flutter kartları bu listeden çizer. YENİ AYAR: buraya tek satır.
 *
 * hedefler: müşteri ve/veya personel — yalnız tanımlı hedefin kolonu yazılır, diğerine dokunulmaz
 * (eski form da yalnız kartta görünen kolonu güncellerdi). Tek hedefli kart "Açık / Kapalı" görünür.
 */
final class SmsAyarKatalogu
{
    private const M = ['musteri'];

    private const P = ['personel'];

    private const MP = ['musteri', 'personel'];

    /** SMS düğmesi paralel WhatsApp kolonlarını da yöneten ayarlar (eski sms_ayar_kaydet). */
    public const WHATSAPP_ESLENIK = [1, 3, 6, 14];

    /** @var array<int, array{0:string, 1:string, 2:array<int,string>}> */
    private const TANIMLAR = [
        16 => ['Doğrulama Kodu', 'Randevu ve senet işlemlerinde müşterinin cep telefonuna doğrulama kodu gitsin/gitmesin ayarıdır.', self::M],
        2  => ['Randevu Talebi Onaylandığında', 'Gelen online randevu talebi/isteği onaylandığında SMS gitsin/gitmesin ayarıdır.', self::MP],
        3  => ['Aktif Randevu İptalinde', 'Oluşturulan randevu iptal edildiğinde SMS gitsin/gitmesin ayarıdır.', self::MP],
        4  => ['Müşteri Eklendiğinde', "Müşteri kaydı sonrasında müşterinize işletmenizin müşteri listesine kaydedildiğine dair bilgilendirme SMS'i gitsin/gitmesin ayarıdır.", self::M],
        5  => ['Aktif ve 60 Gün Boyunca İşletmenizi Ziyaret Etmemiş Müşteriye Hatırlatma', "60 gün boyunca işletmenizi ziyaret etmemiş müşterilerinize otomatik hatırlatma SMS'i gönderilir.", self::M],
        18 => ['Form SMS Olarak Gönderme', 'Formu müşterinin doldurması için linki SMS olarak gönderilsin ayarıdır.', self::MP],
        21 => ['Müşteri Geldi Bildirimi', 'Personelinize müşterinizin geldiğini bildirmek için SMS olarak gitsin/gitmesin ayarıdır.', self::P],
        22 => ['KVKK Bildirimi', 'KVKK bildirimi SMS olarak gitsin/gitmesin ayarıdır.', self::M],
        6  => ['Bir Gün Önce Randevu Hatırlatma', 'Randevu tarihine bir günden fazla gün varsa, randevu tarihinden bir gün öncesinden SMS gitsin/gitmesin ayarıdır.', self::MP],
        17 => ['Yaklaşan Notu Hatırlatma', 'Notlara dair SMS gönderimlerinin gitsin/gitmesin ayarıdır.', self::P],
        7  => ['Randevu Talebi Reddedildiğinde', 'Gelen online randevu talebi/isteği reddedildiğinde SMS gitsin/gitmesin ayarıdır.', self::MP],
        8  => ['Doğum Günü Gönderimi', "Doğum günü olan müşterilerinize kutlama SMS'i gitsin/gitmesin ayarıdır. Bu ayar işletmenize/kendinize özel gönderici adınızın olması durumunda çalışmaktadır.", self::M],
        9  => ['Randevu Sürükle ve Bırak', 'Randevu sürükle ve bırakıldığında müşteriye SMS gitsin/gitmesin ayarıdır.', self::M],
        10 => ["SMS'den Etkinlik & Kampanya Katılımı İçin Link Gönderimi", 'Müşterinin oluşturduğunuz etkinlik veya kampanyalara katılımını olumlu ya da olumsuz seçmesi için link gönderilir.', self::M],
        20 => ['Seans Bilgisi Bildirimi', 'Müşterinizin seans bilgilerinin SMS olarak gitsin/gitmesin ayarıdır.', self::M],
        23 => ['Satış ve Tahsilat Silme Bildirimi', 'Tahsilat/satış silme işlemlerinde ve tahsil edilmiş kalem düzenlenirken hesap sahibine SMS gitsin/gitmesin ayarıdır.', self::P],
        1  => ['Yaklaşan Randevu Hatırlatma', 'Randevu hatırlatmalarına dair SMS gönderimlerinin gitsin/gitmesin ayarıdır.', self::MP],
        11 => ['Online Randevu Talebi Bilgilendirme', 'Yeni bir online randevu talebi/isteği geldiğinde SMS gitsin/gitmesin ayarıdır.', self::MP],
        12 => ['Randevu Oluşturulduğunda', 'Randevu oluşturulduğu esnada SMS gitsin/gitmesin ayarıdır.', self::MP],
        13 => ['Randevu Sonrası Değerlendirme', "Randevu sonrasında değerlendirme SMS'i gitsin/gitmesin ayarıdır.", self::M],
        14 => ['Randevu Güncelleme', 'Güncellenen randevu saati ve tarihini SMS ile gönder/gönderme ayarıdır.', self::MP],
        15 => ['Kara Liste', 'Müşteri numarası kara listeye eklendiğinde SMS gitsin/gitmesin ayarıdır.', self::M],
        19 => ['Para İşlemleri Bilgilendirme', 'Kasaya para ekleme ve kasadan para alma işlemlerinde hesap sahibine SMS gitsin/gitmesin ayarıdır.', self::P],
    ];

    /** Kartta "kaç saat önce" seçimi gösterilen ayar (salonlar.randevu_sms_hatirlatma). */
    public const SAAT_SECIMLI = 1;

    public const SAAT_MIN = 1;

    public const SAAT_MAKS = 23;

    /**
     * Kart listesi + salonun değerleri.
     *
     * @param  array<int, array{musteri:bool, personel:bool}>  $degerler  ayar_id → değer
     */
    public static function kartlar(array $degerler): array
    {
        $kartlar = [];
        foreach (self::TANIMLAR as $id => [$baslik, $aciklama, $hedefler]) {
            $kartlar[] = [
                'ayar_id'  => $id,
                'baslik'   => $baslik,
                'aciklama' => $aciklama,
                'hedefler' => $hedefler,
                'musteri'  => (bool) ($degerler[$id]['musteri'] ?? false),
                'personel' => (bool) ($degerler[$id]['personel'] ?? false),
            ];
        }

        return $kartlar;
    }

    /**
     * İstek satırı → yazılacak kolonlar. Yalnız kartın hedefleri yazılır; WhatsApp eşlenikli
     * ayarlarda aynı değer whatsapp_* kolonuna da gider. Katalogda olmayan ayar_id → 422.
     *
     * @param  array{ayar_id?:mixed, musteri?:mixed, personel?:mixed}  $satir
     * @return array<string, int>
     */
    public static function kolonlar(array $satir): array
    {
        $id = (int) ($satir['ayar_id'] ?? 0);
        if (! isset(self::TANIMLAR[$id])) {
            throw new GecersizIslemException('Geçersiz SMS ayarı: '.$id);
        }
        $kolonlar = [];
        foreach (self::TANIMLAR[$id][2] as $hedef) {
            if (! array_key_exists($hedef, $satir)) {
                continue;
            }
            $deger = filter_var($satir[$hedef], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            $kolonlar[$hedef] = $deger;
            if (in_array($id, self::WHATSAPP_ESLENIK, true)) {
                $kolonlar['whatsapp_'.$hedef] = $deger;
            }
        }

        return $kolonlar;
    }
}
