<?php

namespace App\Support\Yetki;

/**
 * Personel yetki sabitleri ve sablonlari — eski monolitin
 * App\PersonelYetkiSabitleri dosyasindan BIREBIR portlanmistir.
 *
 * Web + Mobil + bu yeni API hep bu tanimlardan beslenir. Yeni anahtar EKLENMEZ
 * (tahsilat mevcut satis.* anahtarlarini kullanir); eklenirse ESKI monolitte de
 * ayni anahtar eklenmeli (tanimlar + sablonlar + mobilGizliAnahtarlar), yoksa
 * web/mobil paritesi bozulur ve Flutter sahte "zorla cikis" tetikler.
 *
 * Onemli: musteri.telefon_gor TUM sablonlarda default KAPALI.
 */
class YetkiSabitleri
{
    /** Tum yetki tanimi: kategori + key + Turkce etiket + aciklama. */
    public static function tanimlar(): array
    {
        return [
            // === RANDEVU & TAKVIM ===
            ['key' => 'randevu.takvim_gor',          'kategori' => 'randevu', 'label' => 'Takvimi görüntüle',                'aciklama' => 'Randevu takvim sayfasını açabilir'],
            ['key' => 'randevu.tum_personel_gor',    'kategori' => 'randevu', 'label' => 'Tüm personellerin randevularını gör', 'aciklama' => 'Kapalıysa sadece kendi randevularını görür'],
            ['key' => 'randevu.olustur',             'kategori' => 'randevu', 'label' => 'Randevu oluştur',                 'aciklama' => 'Yeni randevu ekleyebilir'],
            ['key' => 'randevu.onayla',              'kategori' => 'randevu', 'label' => 'Randevu talebini onayla',        'aciklama' => 'Bekleyen (online) randevu taleplerini onaylayabilir. Kapalıysa onayla butonları gizlenir'],
            ['key' => 'randevu.duzenle_iptal',       'kategori' => 'randevu', 'label' => 'Randevu düzenle / iptal et',      'aciklama' => 'Mevcut randevuda değişiklik yapabilir'],
            ['key' => 'randevu.kapanis_blok_ekle',   'kategori' => 'randevu', 'label' => 'Kapanış / blok ekle',             'aciklama' => 'Takvime saat bloku ekleyebilir'],
            ['key' => 'randevu.online_ayar',         'kategori' => 'randevu', 'label' => 'Online randevu ayarları',         'aciklama' => 'Online randevu ayarlarını değiştirebilir'],

            // === MUSTERI ===
            ['key' => 'musteri.liste_gor',           'kategori' => 'musteri', 'label' => 'Müşteri listesi görüntüle',       'aciklama' => 'Müşteri listesi sayfasına erişebilir'],
            ['key' => 'musteri.tum_portfoy_gor',     'kategori' => 'musteri', 'label' => 'Tüm müşterileri gör',             'aciklama' => 'Kapalıysa sadece kendi portföyünü görür'],
            ['key' => 'musteri.detay_gor',           'kategori' => 'musteri', 'label' => 'Müşteri detay sayfası',           'aciklama' => 'Müşteri detay sayfasını açabilir'],
            ['key' => 'musteri.ekle_duzenle',        'kategori' => 'musteri', 'label' => 'Müşteri ekle / düzenle',          'aciklama' => 'Yeni müşteri ekleyip mevcut bilgileri değiştirebilir'],
            ['key' => 'musteri.sil',                 'kategori' => 'musteri', 'label' => 'Müşteri sil',                     'aciklama' => 'Müşteri kaydını silebilir'],
            ['key' => 'musteri.telefon_gor',         'kategori' => 'musteri', 'label' => 'Müşteri telefon numarası göster', 'aciklama' => 'Kapalıysa numara maskelenir (0XXX XXX XX 47)'],
            ['key' => 'musteri.not_yaz',             'kategori' => 'musteri', 'label' => 'Müşteri notu yaz / oku',          'aciklama' => 'Müşteri notlarına erişebilir'],
            ['key' => 'musteri.gecmis_satis_gor',    'kategori' => 'musteri', 'label' => 'Müşteri geçmiş satışı gör',       'aciklama' => 'Müşterinin tüm geçmiş randevu/satışlarını görür'],

            // === SATIS & TAHSILAT ===
            ['key' => 'satis.adisyon_olustur',       'kategori' => 'satis',   'label' => 'Adisyon oluştur',                 'aciklama' => 'Satış adisyonu açabilir'],
            ['key' => 'satis.tahsilat_al',           'kategori' => 'satis',   'label' => 'Tahsilat al',                     'aciklama' => 'Ödeme alabilir'],
            ['key' => 'satis.tahsilat_sil',          'kategori' => 'satis',   'label' => 'Tahsilat sil / iade',             'aciklama' => 'Alınan tahsilatı silebilir'],
            ['key' => 'satis.adisyon_sil',           'kategori' => 'satis',   'label' => 'Adisyon sil',                     'aciklama' => 'Açık adisyonu silebilir'],
            ['key' => 'satis.indirim_uygula',        'kategori' => 'satis',   'label' => 'İndirim uygula',                  'aciklama' => 'Satışa indirim uygulayabilir'],
            ['key' => 'satis.hediye_isle',           'kategori' => 'satis',   'label' => 'Hediye işle',                     'aciklama' => 'Hediye/promosyon olarak işleyebilir'],
            ['key' => 'satis.senet_olustur',         'kategori' => 'satis',   'label' => 'Senet / taksitli satış',          'aciklama' => 'Taksitli ödeme planı oluşturabilir'],
            ['key' => 'satis.tum_satis_gor',         'kategori' => 'satis',   'label' => 'Tüm satışları gör',               'aciklama' => 'Kapalıysa sadece kendi satışlarını görür'],

            // === PAKET / URUN / HIZMET ===
            ['key' => 'paket.sat',                   'kategori' => 'urun_paket', 'label' => 'Paket sat',                    'aciklama' => 'Pakete satış kaydedebilir'],
            ['key' => 'paket.tanim_olustur',         'kategori' => 'urun_paket', 'label' => 'Paket tanımı oluştur',         'aciklama' => 'Yeni paket tanımı eklenebilir'],
            ['key' => 'paket.seans_takip',           'kategori' => 'urun_paket', 'label' => 'Seans takibi',                 'aciklama' => 'Paket seanslarını takip edebilir'],
            ['key' => 'urun.sat',                    'kategori' => 'urun_paket', 'label' => 'Ürün sat',                     'aciklama' => 'Ürün satışı yapabilir'],
            ['key' => 'urun.tanim_olustur',          'kategori' => 'urun_paket', 'label' => 'Ürün tanımı oluştur',          'aciklama' => 'Yeni ürün/marka ekleyebilir'],
            ['key' => 'urun.stok_giris',             'kategori' => 'urun_paket', 'label' => 'Stok girişi yap',              'aciklama' => 'Alış girişi / transfer / manuel hareket'],
            ['key' => 'urun.stok_sayim',             'kategori' => 'urun_paket', 'label' => 'Sayım yap',                    'aciklama' => 'Depo sayımı yapabilir'],
            ['key' => 'urun.tedarikci_yonet',        'kategori' => 'urun_paket', 'label' => 'Tedarikçi yönet',              'aciklama' => 'Tedarikçi ekle/düzenle/sil'],
            ['key' => 'hizmet.tanim_olustur',        'kategori' => 'urun_paket', 'label' => 'Hizmet tanımı oluştur',        'aciklama' => 'Yeni hizmet ekleyebilir'],
            ['key' => 'hizmet.kategori_yonet',       'kategori' => 'urun_paket', 'label' => 'Hizmet kategorileri yönet',    'aciklama' => 'Kategori ekle/düzenle/sil'],

            // === PERSONEL & MAAS ===
            ['key' => 'personel.liste_gor',          'kategori' => 'personel', 'label' => 'Personel listesi görüntüle',    'aciklama' => 'Personeller sayfasına erişebilir'],
            ['key' => 'personel.ekle_duzenle',       'kategori' => 'personel', 'label' => 'Personel ekle / düzenle',       'aciklama' => 'Yeni personel ekleyip mevcudu değiştirebilir'],
            ['key' => 'personel.sil',                'kategori' => 'personel', 'label' => 'Personel sil / arşivle',        'aciklama' => 'Personel kaydını arşivleyebilir'],
            ['key' => 'personel.prim_hakedis_gor',   'kategori' => 'personel', 'label' => 'Prim & Hak Ediş ekranı gör',    'aciklama' => 'Prim hakediş paneline erişebilir'],
            ['key' => 'personel.maas_tutar_gor',     'kategori' => 'personel', 'label' => 'Maaş / prim tutarını gör',      'aciklama' => 'Kapalıysa tutarlar **** ile maskelenir'],
            ['key' => 'personel.odeme_yap',          'kategori' => 'personel', 'label' => 'Ödeme / bonus / kesinti yap',   'aciklama' => 'Maaş/prim ödemesi ve hareket girebilir'],
            ['key' => 'personel.yetki_yonet',        'kategori' => 'personel', 'label' => 'Yetki yönetimi',                'aciklama' => 'Diğer personellerin yetkilerini ayarlayabilir'],
            ['key' => 'personel.kendi_ciro_gor',     'kategori' => 'personel', 'label' => 'Personel kendi cirosunu gör',   'aciklama' => 'Kapalıysa personel dashboardunda günlük/haftalık/aylık/yıllık ciro (karşılaştırma) bölümü gizlenir'],
            ['key' => 'personel.kapali_adisyon_gizle', 'kategori' => 'satis', 'label' => 'Kapalı adisyonları gizle', 'aciklama' => 'Kapalı adisyonları gizle'],

            // === RAPORLAR ===
            ['key' => 'rapor.satis',                 'kategori' => 'rapor', 'label' => 'Satış raporları',                 'aciklama' => 'Hizmet/ürün/paket satış raporları'],
            ['key' => 'rapor.kasa',                  'kategori' => 'rapor', 'label' => 'Kasa raporu / günlük kapanış',    'aciklama' => 'Günlük kasa özetine erişebilir'],
            ['key' => 'rapor.tahsilat',              'kategori' => 'rapor', 'label' => 'Tahsilat raporu',                 'aciklama' => 'Tahsilat dökümlerini görür'],
            ['key' => 'rapor.personel_performans',   'kategori' => 'rapor', 'label' => 'Personel performans raporu',      'aciklama' => 'Personel bazlı performans raporları'],
            ['key' => 'rapor.musteri',               'kategori' => 'rapor', 'label' => 'Müşteri raporları',               'aciklama' => 'Müşteri istatistikleri ve dökümleri'],
            ['key' => 'rapor.ciro_kar_gor',          'kategori' => 'rapor', 'label' => 'Salon cirosu / kâr-zarar göster', 'aciklama' => 'Kapalıysa ciro/kar tutarları **** ile maskelenir'],

            // === FINANS ===
            ['key' => 'finans.kasa_giris_cikis',     'kategori' => 'finans', 'label' => 'Kasa giriş / çıkış',             'aciklama' => 'Kasa hareketleri kaydedebilir'],
            ['key' => 'finans.masraf_ekle',          'kategori' => 'finans', 'label' => 'Masraf ekle',                    'aciklama' => 'Yeni masraf kaydı oluşturabilir'],
            ['key' => 'finans.masraf_gor',           'kategori' => 'finans', 'label' => 'Masraf listesi görüntüle',       'aciklama' => 'Masraflar sayfasına erişebilir'],
            ['key' => 'finans.alacak_yonet',         'kategori' => 'finans', 'label' => 'Alacak / senet yönet',           'aciklama' => 'Senet/alacak yönetimi'],

            // === PAZARLAMA ===
            ['key' => 'pazarlama.sms_gonder',        'kategori' => 'pazarlama', 'label' => 'SMS gönder',                  'aciklama' => 'Müşteriye SMS yollayabilir'],
            ['key' => 'pazarlama.whatsapp_gonder',   'kategori' => 'pazarlama', 'label' => 'WhatsApp mesajı gönder',      'aciklama' => 'WhatsApp ile iletişim'],
            ['key' => 'pazarlama.toplu_sms',         'kategori' => 'pazarlama', 'label' => 'Toplu SMS / kampanya',        'aciklama' => 'Listeye toplu mesaj atabilir'],
            ['key' => 'pazarlama.kampanya_yonet',    'kategori' => 'pazarlama', 'label' => 'Kampanya yönet',              'aciklama' => 'Kampanya oluştur/düzenle/iptal'],
            ['key' => 'pazarlama.cark_yonet',        'kategori' => 'pazarlama', 'label' => 'Çark-ı Felek yönet',          'aciklama' => 'Çark ayarları ve kuponlar'],
            ['key' => 'pazarlama.anket_yonet',       'kategori' => 'pazarlama', 'label' => 'Anket / değerlendirme',       'aciklama' => 'Anket şablonları ve sonuçları'],

            // === ON GORUSME & FORM ===
            ['key' => 'gorusme.liste_gor',           'kategori' => 'gorusme', 'label' => 'Ön görüşme listesi',            'aciklama' => 'Ön görüşmeler sayfasına erişebilir'],
            ['key' => 'gorusme.ekle_duzenle',        'kategori' => 'gorusme', 'label' => 'Ön görüşme ekle / düzenle',     'aciklama' => 'Görüşme kaydı oluşturup düzenleyebilir'],
            ['key' => 'form.olustur',                'kategori' => 'gorusme', 'label' => 'Form / sözleşme oluştur',       'aciklama' => 'Yeni form/sözleşme şablonu'],
            ['key' => 'form.gonder',                 'kategori' => 'gorusme', 'label' => 'Form / sözleşme gönder',        'aciklama' => 'Müşteriye form gönderebilir'],

            // === AYARLAR ===
            ['key' => 'ayar.salon_bilgi',            'kategori' => 'ayar', 'label' => 'Salon bilgilerini düzenle',       'aciklama' => 'Logo, ad, adres, iletişim'],
            ['key' => 'ayar.sube_yonet',             'kategori' => 'ayar', 'label' => 'Şube yönetimi',                   'aciklama' => 'Şube ekle/düzenle'],
            ['key' => 'ayar.cihaz_oda_yonet',        'kategori' => 'ayar', 'label' => 'Cihaz / oda yönetimi',            'aciklama' => 'Cihaz ve oda tanımlarını yönet'],
        ];
    }

    /** Kategori etiketleri (UI'da grup basligi icin) */
    public static function kategoriEtiketleri(): array
    {
        return [
            'randevu'    => ['ad' => 'Randevu & Takvim',      'ikon' => 'event'],
            'musteri'    => ['ad' => 'Müşteriler',            'ikon' => 'people'],
            'satis'      => ['ad' => 'Satış & Tahsilat',      'ikon' => 'payments'],
            'urun_paket' => ['ad' => 'Ürün / Paket / Hizmet', 'ikon' => 'inventory_2'],
            'personel'   => ['ad' => 'Personel & Maaş',       'ikon' => 'badge'],
            'rapor'      => ['ad' => 'Raporlar',              'ikon' => 'bar_chart'],
            'finans'     => ['ad' => 'Finans / Kasa',         'ikon' => 'account_balance_wallet'],
            'pazarlama'  => ['ad' => 'Pazarlama',             'ikon' => 'campaign'],
            'gorusme'    => ['ad' => 'Ön Görüşme & Form',     'ikon' => 'phone_in_talk'],
            'ayar'       => ['ad' => 'Ayarlar',               'ikon' => 'settings'],
        ];
    }

    /**
     * Hazir sablonlar (yonetici / sekreter / personel).
     * NOT: musteri.telefon_gor TUM sablonlarda KAPALI.
     */
    public static function sablonlar(): array
    {
        return [
            'yonetici' => [
                'ad' => 'Yönetici',
                'aciklama' => 'Geniş yetki: tüm operasyon, raporlar ve finans açık. Telefon ve yetki yönetimi kapalı (salon sahibi açabilir).',
                'ikon' => 'verified_user',
                'ayarlar' => [
                    'randevu.takvim_gor' => true, 'randevu.tum_personel_gor' => true,
                    'randevu.olustur' => true, 'randevu.onayla' => true, 'randevu.duzenle_iptal' => true,
                    'randevu.kapanis_blok_ekle' => true, 'randevu.online_ayar' => true,
                    'musteri.liste_gor' => true, 'musteri.tum_portfoy_gor' => true,
                    'musteri.detay_gor' => true, 'musteri.ekle_duzenle' => true,
                    'musteri.sil' => true, 'musteri.telefon_gor' => false,
                    'musteri.not_yaz' => true, 'musteri.gecmis_satis_gor' => true,
                    'satis.adisyon_olustur' => true, 'satis.tahsilat_al' => true,
                    'satis.tahsilat_sil' => true, 'satis.adisyon_sil' => true,
                    'satis.indirim_uygula' => true, 'satis.hediye_isle' => true,
                    'satis.senet_olustur' => true, 'satis.tum_satis_gor' => true,
                    'paket.sat' => true, 'paket.tanim_olustur' => true, 'paket.seans_takip' => true,
                    'urun.sat' => true, 'urun.tanim_olustur' => true,
                    'urun.stok_giris' => true, 'urun.stok_sayim' => true, 'urun.tedarikci_yonet' => true,
                    'hizmet.tanim_olustur' => true, 'hizmet.kategori_yonet' => true,
                    'personel.liste_gor' => true, 'personel.ekle_duzenle' => true,
                    'personel.sil' => true, 'personel.prim_hakedis_gor' => true,
                    'personel.maas_tutar_gor' => true, 'personel.odeme_yap' => true,
                    'personel.yetki_yonet' => false,
                    'personel.kendi_ciro_gor' => true,
                    'personel.kapali_adisyon_gizle' => false,
                    'rapor.satis' => true, 'rapor.kasa' => true, 'rapor.tahsilat' => true,
                    'rapor.personel_performans' => true, 'rapor.musteri' => true,
                    'rapor.ciro_kar_gor' => true,
                    'finans.kasa_giris_cikis' => true, 'finans.masraf_ekle' => true,
                    'finans.masraf_gor' => true, 'finans.alacak_yonet' => true,
                    'pazarlama.sms_gonder' => true, 'pazarlama.whatsapp_gonder' => true,
                    'pazarlama.toplu_sms' => true, 'pazarlama.kampanya_yonet' => true,
                    'pazarlama.cark_yonet' => true, 'pazarlama.anket_yonet' => true,
                    'gorusme.liste_gor' => true, 'gorusme.ekle_duzenle' => true,
                    'form.olustur' => true, 'form.gonder' => true,
                    'ayar.salon_bilgi' => true, 'ayar.sube_yonet' => true, 'ayar.cihaz_oda_yonet' => true,
                ],
            ],

            'sekreter' => [
                'ad' => 'Sekreter / Resepsiyon',
                'aciklama' => 'Randevu + müşteri + tahsilat + ön görüşme + SMS hepsi açık. Personel/maaş/finans/ayarlar kapalı.',
                'ikon' => 'support_agent',
                'ayarlar' => [
                    'randevu.takvim_gor' => true, 'randevu.tum_personel_gor' => true,
                    'randevu.olustur' => true, 'randevu.onayla' => true, 'randevu.duzenle_iptal' => true,
                    'randevu.kapanis_blok_ekle' => true, 'randevu.online_ayar' => false,
                    'musteri.liste_gor' => true, 'musteri.tum_portfoy_gor' => true,
                    'musteri.detay_gor' => true, 'musteri.ekle_duzenle' => true,
                    'musteri.sil' => false, 'musteri.telefon_gor' => false,
                    'musteri.not_yaz' => true, 'musteri.gecmis_satis_gor' => true,
                    'satis.adisyon_olustur' => true, 'satis.tahsilat_al' => true,
                    'satis.tahsilat_sil' => false, 'satis.adisyon_sil' => false,
                    'satis.indirim_uygula' => true, 'satis.hediye_isle' => true,
                    'satis.senet_olustur' => true, 'satis.tum_satis_gor' => true,
                    'paket.sat' => true, 'paket.tanim_olustur' => false, 'paket.seans_takip' => true,
                    'urun.sat' => true, 'urun.tanim_olustur' => false,
                    'urun.stok_giris' => false, 'urun.stok_sayim' => false, 'urun.tedarikci_yonet' => false,
                    'hizmet.tanim_olustur' => false, 'hizmet.kategori_yonet' => false,
                    'personel.liste_gor' => false, 'personel.ekle_duzenle' => false,
                    'personel.sil' => false, 'personel.prim_hakedis_gor' => false,
                    'personel.maas_tutar_gor' => false, 'personel.odeme_yap' => false,
                    'personel.yetki_yonet' => false,
                    'personel.kendi_ciro_gor' => true,
                    'personel.kapali_adisyon_gizle' => false,
                    'rapor.satis' => true, 'rapor.kasa' => true, 'rapor.tahsilat' => true,
                    'rapor.personel_performans' => false, 'rapor.musteri' => true,
                    'rapor.ciro_kar_gor' => false,
                    'finans.kasa_giris_cikis' => true, 'finans.masraf_ekle' => true,
                    'finans.masraf_gor' => true, 'finans.alacak_yonet' => true,
                    'pazarlama.sms_gonder' => true, 'pazarlama.whatsapp_gonder' => true,
                    'pazarlama.toplu_sms' => true, 'pazarlama.kampanya_yonet' => true,
                    'pazarlama.cark_yonet' => true, 'pazarlama.anket_yonet' => true,
                    'gorusme.liste_gor' => true, 'gorusme.ekle_duzenle' => true,
                    'form.olustur' => false, 'form.gonder' => true,
                    'ayar.salon_bilgi' => false, 'ayar.sube_yonet' => false, 'ayar.cihaz_oda_yonet' => false,
                ],
            ],

            'personel' => [
                'ad' => 'Personel',
                'aciklama' => 'Sade çalışma yetkisi. Sadece kendi randevu/müşteri/satışlarını görür. Hassas alanlar kapalı.',
                'ikon' => 'person',
                'ayarlar' => [
                    'randevu.takvim_gor' => true, 'randevu.tum_personel_gor' => false,
                    'randevu.olustur' => true, 'randevu.onayla' => false, 'randevu.duzenle_iptal' => true,
                    'randevu.kapanis_blok_ekle' => false, 'randevu.online_ayar' => false,
                    'musteri.liste_gor' => true, 'musteri.tum_portfoy_gor' => false,
                    'musteri.detay_gor' => true, 'musteri.ekle_duzenle' => true,
                    'musteri.sil' => false, 'musteri.telefon_gor' => false,
                    'musteri.not_yaz' => true, 'musteri.gecmis_satis_gor' => false,
                    'satis.adisyon_olustur' => true, 'satis.tahsilat_al' => true,
                    'satis.tahsilat_sil' => false, 'satis.adisyon_sil' => false,
                    'satis.indirim_uygula' => false, 'satis.hediye_isle' => false,
                    'satis.senet_olustur' => false, 'satis.tum_satis_gor' => false,
                    'paket.sat' => true, 'paket.tanim_olustur' => false, 'paket.seans_takip' => true,
                    'urun.sat' => true, 'urun.tanim_olustur' => false,
                    'urun.stok_giris' => false, 'urun.stok_sayim' => false, 'urun.tedarikci_yonet' => false,
                    'hizmet.tanim_olustur' => false, 'hizmet.kategori_yonet' => false,
                    'personel.liste_gor' => false, 'personel.ekle_duzenle' => false,
                    'personel.sil' => false, 'personel.prim_hakedis_gor' => false,
                    'personel.maas_tutar_gor' => false, 'personel.odeme_yap' => false,
                    'personel.yetki_yonet' => false,
                    'personel.kendi_ciro_gor' => false,
                    'personel.kapali_adisyon_gizle' => false,
                    'rapor.satis' => false, 'rapor.kasa' => false, 'rapor.tahsilat' => false,
                    'rapor.personel_performans' => false, 'rapor.musteri' => false,
                    'rapor.ciro_kar_gor' => false,
                    'finans.kasa_giris_cikis' => false, 'finans.masraf_ekle' => false,
                    'finans.masraf_gor' => false, 'finans.alacak_yonet' => false,
                    'pazarlama.sms_gonder' => false, 'pazarlama.whatsapp_gonder' => false,
                    'pazarlama.toplu_sms' => false, 'pazarlama.kampanya_yonet' => false,
                    'pazarlama.cark_yonet' => false, 'pazarlama.anket_yonet' => false,
                    'gorusme.liste_gor' => true, 'gorusme.ekle_duzenle' => true,
                    'form.olustur' => false, 'form.gonder' => false,
                    'ayar.salon_bilgi' => false, 'ayar.sube_yonet' => false, 'ayar.cihaz_oda_yonet' => false,
                ],
            ],
        ];
    }

    /** Belli bir sablonun ayar dizisini dondur (yoksa personel default). */
    public static function sablonAyarlari(string $sablon): array
    {
        // Backward compat: eski key isimleri yeniye map et
        $eskidenYeniye = [
            'personel_sade' => 'personel',
            'personel_tam'  => 'yonetici',
            'demo'          => 'personel',
        ];
        if (isset($eskidenYeniye[$sablon])) {
            $sablon = $eskidenYeniye[$sablon];
        }
        $sablonlar = self::sablonlar();
        return $sablonlar[$sablon]['ayarlar'] ?? $sablonlar['personel']['ayarlar'];
    }

    /** Tum yetki anahtarlarinin listesi */
    public static function tumAnahtarlar(): array
    {
        return array_map(fn ($t) => $t['key'], self::tanimlar());
    }

    /**
     * Mobil (Flutter) build'inin henuz bilmedigi, /benimYetkilerim cevabindan
     * GIZLENECEK anahtarlar (sahte "zorla cikis" onlemek icin). Eski monolitle ayni.
     */
    public static function mobilGizliAnahtarlar(): array
    {
        return [
            'randevu.onayla',
        ];
    }

    /** Telefon maskeleme: "0532 123 45 67" -> "0532 *** ** 67" */
    public static function telefonMaskele(?string $tel): string
    {
        if ($tel === null || $tel === '') return '';
        $digits = preg_replace('/\D/', '', $tel);
        if (strlen($digits) < 2) return $tel;
        $son2 = substr($digits, -2);
        $bas4 = strlen($digits) >= 4
            ? substr($digits, 0, 4)
            : substr($digits, 0, strlen($digits) - 2);
        return $bas4 . ' *** ** ' . $son2;
    }
}
