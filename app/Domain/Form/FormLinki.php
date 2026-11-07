<?php

namespace App\Domain\Form;

/**
 * Müşteriye / personele gönderilen form doldurma linki ve SMS/WhatsApp metni —
 * TEK KAYNAK, SAF. Eski sistemde bu eşleme 4 yerde farklı kopyalanmıştı
 * (web arsivformekleme, web formutekrargonder, v1 arsivformekleguncelle, v1 formgonder);
 * yeniden gönderimde form 9-13 özel linkleri kayboluyordu. Burada her akış aynı kuralı
 * kullanır.
 *
 * Doldurma sayfaları eski monolitte kalır (/musteriformdoldurma*, /onam-form, /sozlesme).
 */
final class FormLinki
{
    /**
     * Müşteri mesajı.
     *
     * $genelLink: mobil Tekrar Gönder her klasik formda genel sayfayı gönderir (form 9-13 dahil).
     *
     * @param array{id:int,user_id:int,form_id:int,is_sozlesme?:mixed,dogrulama_kodu:string} $arsiv
     */
    public static function musteriMesaji(string $host, array $arsiv, bool $dinamik, bool $genelLink = false): string
    {
        $id = (int) $arsiv['id'];
        $uid = (int) $arsiv['user_id'];
        $kod = (string) $arsiv['dogrulama_kodu'];
        $kok = 'https://'.$host.'/';

        if ((int) ($arsiv['is_sozlesme'] ?? 0) === 1) {
            return ' Hizmet Sözleşmenizi imzalamak için: '.$kok.'sozlesme/'.$id.'/'.$uid.' | Onay Kodu: '.$kod;
        }
        if ($dinamik) {
            return ' Onam formunu doldurmak için: '.$kok.'onam-form/'.$id.'/'.$uid.' | Onay Kodu: '.$kod;
        }
        $sayfa = ($genelLink ? null : SabitFormlar::sayfa((int) $arsiv['form_id'])) ?? 'musteriformdoldurma';

        return ' Formu doldurmak için : '.$kok.$sayfa.'/'.$id.'/'.$uid.' Onay Kodu:'.$kod;
    }

    /**
     * Personel imza mesajı — yalnız klasik (dinamik olmayan, özel sayfası olmayan)
     * formlarda vardır; diğerlerinde null.
     *
     * @param array{id:int,form_id:int,is_sozlesme?:mixed} $arsiv
     */
    public static function personelMesaji(string $host, array $arsiv, bool $dinamik, int $personelId): ?string
    {
        if ($personelId <= 0 || $dinamik || (int) ($arsiv['is_sozlesme'] ?? 0) === 1
            || SabitFormlar::sayfa((int) $arsiv['form_id']) !== null) {
            return null;
        }

        return ' İmza atmak için : https://'.$host.'/personelformdoldurma/'.(int) $arsiv['id'].'/'.$personelId;
    }

    /** 4 haneli onay kodu (eski: str_shuffle('1234567890') ilk 4 hane). */
    public static function dogrulamaKodu(): string
    {
        return substr(str_shuffle('1234567890'), 0, 4);
    }
}
