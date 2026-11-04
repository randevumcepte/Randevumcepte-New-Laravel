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
        // Çarkıfelek + sadakat (v2)
        'cark_dilim_kaydet'          => ['etiket' => 'Çark dilimleri kaydedildi', 'kategori' => HareketKategorisi::GUNCELLEME],
        'cark_aktif_toggle'          => ['etiket' => 'Çark aktif/pasif yapıldı', 'kategori' => HareketKategorisi::GUNCELLEME],
        'cark_duyuru_gonder'         => ['etiket' => 'Çark duyurusu gönderildi', 'kategori' => HareketKategorisi::GONDERIM],
        'cark_kupon_kullan'          => ['etiket' => 'Çark kuponu kullanıldı', 'kategori' => HareketKategorisi::GUNCELLEME],
        'cark_kupon_geri_al'         => ['etiket' => 'Çark kuponu kullanımı geri alındı', 'kategori' => HareketKategorisi::GUNCELLEME],
        'cark_hatirlatma_kaydet'     => ['etiket' => 'Çark hatırlatma ayarları kaydedildi', 'kategori' => HareketKategorisi::GUNCELLEME],
        'cark_puan_odulu_ekle'       => ['etiket' => 'Puan ödülü eklendi', 'kategori' => HareketKategorisi::EKLEME],
        'cark_puan_odulu_guncelle'   => ['etiket' => 'Puan ödülü güncellendi', 'kategori' => HareketKategorisi::GUNCELLEME],
        'cark_puan_odulu_sil'        => ['etiket' => 'Puan ödülü silindi', 'kategori' => HareketKategorisi::SILME],
        'sadakat_kazanim_kurali'     => ['etiket' => 'Puan kazanma kuralı güncellendi', 'kategori' => HareketKategorisi::GUNCELLEME],
        // Memnuniyet Anketi (v2)
        'anket_sablon_ekle'             => ['etiket' => 'Anket şablonu oluşturuldu', 'kategori' => HareketKategorisi::EKLEME],
        'anket_sablon_guncelle'         => ['etiket' => 'Anket şablonu güncellendi', 'kategori' => HareketKategorisi::GUNCELLEME],
        'anket_sablon_pasifle'          => ['etiket' => 'Anket şablonu pasifleştirildi', 'kategori' => HareketKategorisi::GUNCELLEME],
        'anket_sablon_sil'              => ['etiket' => 'Anket şablonu silindi'],
        'anket_manuel_gonder'           => ['etiket' => 'Memnuniyet anketi gönderildi', 'kategori' => HareketKategorisi::GONDERIM],
        'anket_hizli_gonder'            => ['etiket' => 'Memnuniyet anketi gönderildi', 'kategori' => HareketKategorisi::GONDERIM],
        'anket_ayar'                    => ['etiket' => 'Anket ayarları kaydedildi', 'kategori' => HareketKategorisi::GUNCELLEME],
        'anket_premium_ac'              => ['etiket' => 'Reputation Booster açıldı', 'kategori' => HareketKategorisi::GUNCELLEME],

        // Çağrı Merkezi (v2) — tüm yazmalar AktiviteLog::yaz ile; yenisi gerekirse buraya tek satır.
        'cagri_gorusme_sonucu'          => ['etiket' => 'Çağrı: görüşme sonucu', 'kategori' => HareketKategorisi::GUNCELLEME],
        'cagri_arama_baslat'            => ['etiket' => 'Çağrı başlatıldı', 'kategori' => HareketKategorisi::GONDERIM],
        'cagri_randevu_verildi'         => ['etiket' => 'Çağrı: randevu verildi', 'kategori' => HareketKategorisi::EKLEME],
        'cagri_arama_randevu_ekle'      => ['etiket' => 'Arama randevusu eklendi'],
        'cagri_arama_randevu_ertele'    => ['etiket' => 'Arama randevusu ertelendi', 'kategori' => HareketKategorisi::GUNCELLEME],
        'cagri_arama_randevu_iptal'     => ['etiket' => 'Arama randevusu iptal edildi'],
        'cagri_telefonda_satis'         => ['etiket' => 'Telefonda satış', 'kategori' => HareketKategorisi::ODEME],
        'cagri_anket_gonder'            => ['etiket' => 'Memnuniyet anketi gönderildi'],
        'cagri_liste_durum_degistir'    => ['etiket' => 'Arama listesi durumu değişti'],
        'cagri_liste_personel_degistir' => ['etiket' => 'Arama listesi personele atandı'],
        'cagri_liste_atama_kaldir'      => ['etiket' => 'Arama listesi ataması geri alındı', 'kategori' => HareketKategorisi::GUNCELLEME],
        'cagri_liste_segment_tasi'      => ['etiket' => 'Arama listesi segmenti taşındı', 'kategori' => HareketKategorisi::GUNCELLEME],
        'cagri_liste_sil'               => ['etiket' => 'Arama listesi silindi'],
        'cagri_liste_olustur'           => ['etiket' => 'Arama listesi oluşturuldu', 'kategori' => HareketKategorisi::EKLEME],
        'cagri_whatsapp_gonder'         => ['etiket' => 'Çağrı: WhatsApp mesajı'],
        'cagri_script_kaydet'           => ['etiket' => 'Görüşme scripti kaydedildi'],
        'cagri_script_sil'              => ['etiket' => 'Görüşme scripti silindi'],
        'cagri_kategori_kaydet'         => ['etiket' => 'Sonuç kategorisi kaydedildi'],
        'cagri_kategori_sil'            => ['etiket' => 'Sonuç kategorisi silindi'],
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
        'aranacak_musteri' => 'Aranacak müşteri',
        'arama_listesi'    => 'Arama listesi',
        'cagri_script'     => 'Görüşme scripti',
        'cagri_kategori'   => 'Sonuç kategorisi',
    ];

    /** Kod kelimesi → Türkçe yazım (kodlar ASCII; okunur etiket için). */
    private const KELIMELER = [
        'alis' => 'alış', 'arsiv' => 'arşiv', 'askiya' => 'askıya', 'cagri' => 'çağrı', 'cark' => 'çark', 'calisma' => 'çalışma',
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
