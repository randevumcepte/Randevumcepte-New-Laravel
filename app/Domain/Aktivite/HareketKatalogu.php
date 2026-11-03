<?php

namespace App\Domain\Aktivite;

/**
 * Log hareketi KATALOĞU — işlem kodu (action) ve hedef tipi (target_type) → okunur etiket +
 * kategori. Yazıcı (App\Support\AktiviteLog::yaz) serbest kod kabul eder; bu sınıf okuma
 * tarafında o kodu anlamlandırır.
 *
 * YENİ HAREKET EKLEMEK (kod tekrarı yok, ekran/uç değişmez):
 *   1) İşlemin yapıldığı serviste:  AktiviteLog::yaz($salonId, $uid, 'kampanya_yayinla', 'kampanya', $id, $ad);
 *   2) Hiçbir şey yapmasanız da liste, filtre, kategori ve etiket OTOMATİK çalışır:
 *        etiket   = koddaki kelimeler Türkçeleştirilir ('kampanya_yayinla' → 'Kampanya yayınla')
 *        kategori = HareketKategorisi anahtar kelime kuralı (sil/iptal/ekle/guncelle/onay/gonder/giris)
 *   3) Etiket daha iyi olmalıysa ya da kural yanlış kategoriye düşürüyorsa İSLEMLER'e tek satır ekleyin.
 *   4) Koddaki yeni bir kelime ASCII kalıyorsa KELIMELER'e ekleyin (tüm kodlarda birden düzelir).
 * Hedef tipleri için aynı kural: HEDEFLER (yoksa kelime sözlüğüyle).
 */
final class HareketKatalogu
{
    /**
     * Özel tanımlar: kod → [etiket?, kategori?]. Yalnız otomatik sonuç yetmediğinde ekleyin.
     *
     * @var array<string, array{etiket?:string, kategori?:HareketKategorisi}>
     */
    private const ISLEMLER = [
        'login'                   => ['etiket' => 'Giriş yaptı'],
        'logout'                  => ['etiket' => 'Çıkış yaptı'],
        'csv_export'              => ['etiket' => 'CSV dışa aktarma', 'kategori' => HareketKategorisi::GONDERIM],
        'kasa_para_giris'         => ['etiket' => 'Kasaya para girişi', 'kategori' => HareketKategorisi::ODEME],
        'kasa_para_cikis'         => ['etiket' => 'Kasadan para çıkışı', 'kategori' => HareketKategorisi::ODEME],
        'stok_alis_girisi'        => ['etiket' => 'Stok alış girişi', 'kategori' => HareketKategorisi::EKLEME],
        'stok_alis_fisi'          => ['etiket' => 'Stok alış fişi', 'kategori' => HareketKategorisi::EKLEME],
        'stok_hizli_satis'        => ['etiket' => 'Hızlı satış', 'kategori' => HareketKategorisi::ODEME],
        'paket_satis'             => ['etiket' => 'Paket satışı', 'kategori' => HareketKategorisi::ODEME],
        'ongorusme_satis_yapildi' => ['etiket' => 'Ön görüşme: satış yapıldı', 'kategori' => HareketKategorisi::ODEME],
        'ongorusme_satis_yapilmadi' => ['etiket' => 'Ön görüşme: satış yapılmadı'],
        'randevu_red'             => ['etiket' => 'Randevu reddedildi', 'kategori' => HareketKategorisi::IPTAL],
        'randevu_geldi'           => ['etiket' => 'Randevu: geldi'],
        'randevu_gelmedi'         => ['etiket' => 'Randevu: gelmedi'],
        'randevu_geldi_iptal'     => ['etiket' => 'Randevu: geldi geri alındı'],
        'personel_arsiv'          => ['etiket' => 'Personel arşivlendi', 'kategori' => HareketKategorisi::SILME],
        'sadakat_puan_kazanim'    => ['etiket' => 'Sadakat puanı kazanıldı', 'kategori' => HareketKategorisi::ODEME],
    ];

    /** Hedef tipi → etiket (yoksa kelime sözlüğüyle). */
    private const HEDEFLER = [
        'adisyon'      => 'Satış',
        'salon'        => 'İşletme',
        'salon_hizmet' => 'Hizmet',
        'form_sablon'  => 'Form şablonu',
        'arsiv'        => 'Form arşivi',
        'ongorusme'    => 'Ön görüşme',
        'santral_ayar' => 'Santral ayarı',
        'sms'          => 'SMS',
    ];

    /** Kod kelimesi → Türkçe yazım (kodlar ASCII; okunur etiket için). */
    private const KELIMELER = [
        'alis' => 'alış', 'arsiv' => 'arşiv', 'askiya' => 'askıya', 'cark' => 'çark', 'calisma' => 'çalışma',
        'cikis' => 'çıkış', 'degistir' => 'değiştir', 'duzenle' => 'düzenle', 'fis' => 'fiş', 'fisi' => 'fişi',
        'giris' => 'giriş', 'girisi' => 'girişi', 'gonder' => 'gönder', 'gorev' => 'görev', 'gorsel' => 'görsel',
        'gorseli' => 'görseli', 'gorusme' => 'görüşme', 'guncelle' => 'güncelle', 'hatirlatma' => 'hatırlatma',
        'hizli' => 'hızlı', 'isletme' => 'işletme', 'kaldir' => 'kaldır', 'karaliste' => 'kara liste',
        'kayit' => 'kayıt', 'menu' => 'menü', 'musteri' => 'müşteri', 'odeme' => 'ödeme', 'odul' => 'ödül',
        'olustur' => 'oluştur', 'oncelik' => 'öncelik', 'ongorusme' => 'ön görüşme', 'ozel' => 'özel',
        'saglik' => 'sağlık', 'satis' => 'satış', 'sayim' => 'sayım', 'sablon' => 'şablon', 'sifre' => 'şifre',
        'siparis' => 'sipariş', 'sirala' => 'sırala', 'siralama' => 'sıralama', 'sozlesme' => 'sözleşme',
        'sube' => 'şube', 'tasi' => 'taşı', 'urun' => 'ürün', 'yanit' => 'yanıt', 'yapildi' => 'yapıldı',
        'yapilmadi' => 'yapılmadı', 'yayinla' => 'yayınla', 'yonetici' => 'yönetici', 'yonetim' => 'yönetim',
        'baglanti' => 'bağlantı', 'baslat' => 'başlat', 'degerlendirme' => 'değerlendirme', 'gun' => 'gün',
        'hazir' => 'hazır', 'iletisim' => 'iletişim', 'islem' => 'işlem', 'kazanim' => 'kazanım', 'uyelik' => 'üyelik',
        'yoneticisi' => 'yöneticisi',
        'sms' => 'SMS', 'csv' => 'CSV', 'voip' => 'VoIP', 'whatsapp' => 'WhatsApp', 'e' => 'e-', 'mt' => 'MT',
    ];

    /** @return array{kod:string, etiket:string, kategori:HareketKategorisi} */
    public static function islem(string $kod): array
    {
        $t = self::ISLEMLER[$kod] ?? [];

        return [
            'kod'      => $kod,
            'etiket'   => $t['etiket'] ?? self::okunur($kod),
            'kategori' => $t['kategori'] ?? HareketKategorisi::anahtarKelimeden($kod),
        ];
    }

    public static function kategori(string $kod): HareketKategorisi
    {
        return self::islem($kod)['kategori'];
    }

    public static function hedefEtiketi(?string $tip): ?string
    {
        if ($tip === null || $tip === '') {
            return null;
        }

        return self::HEDEFLER[$tip] ?? self::okunur($tip);
    }

    /** 'musteri_saglik_guncelle' → 'Müşteri sağlık güncelle' (cümle düzeni, Türkçe büyük harf). */
    public static function okunur(string $kod): string
    {
        $kelimeler = array_filter(preg_split('/[^a-z0-9]+/', strtolower($kod)) ?: [], fn ($k) => $k !== '');
        $metin = str_replace('e- ', 'e-', implode(' ', array_map(fn ($k) => self::KELIMELER[$k] ?? $k, $kelimeler)));
        if ($metin === '') {
            return 'İşlem';
        }
        $ilk = mb_substr($metin, 0, 1);

        return match ($ilk) { 'i' => 'İ', 'ı' => 'I', default => mb_strtoupper($ilk) }.mb_substr($metin, 1);
    }
}
