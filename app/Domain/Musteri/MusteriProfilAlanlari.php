<?php

namespace App\Domain\Musteri;

/** Musteri profil ve saglik kolonlari (users). Mobil detay, duzenleme ve profil ekranlari bunlari okur. */
final class MusteriProfilAlanlari
{
    public const LISTE = [
        'kayit_tarihi', 'referans', 'alerji_var', 'alkol_alimi_yapildi', 'baslangic_basen', 'baslangic_bel', 'baslangic_diger', 'baslangic_gobek',
        'baslangic_gogus', 'baslangic_kalca', 'baslangic_kg', 'baslangic_sirt', 'cilt_tipi', 'daha_once_uygulama_yaptirildi',
        'daha_once_yaptirilan_uygulama_aciklama', 'deri_yumusak_doku_hastaligi_var', 'ek_saglik_sorunu', 'hamile', 'hemofili_hastaligi_var', 'il_id',
        'kemoterapi_goruyor', 'kil_yapisi', 'musteri_tipi', 'regl_doneminde', 'seker_hastaligi_var', 'surekli_kullanilan_ilac_aciklama',
        'surekli_kullanilan_ilac_Var', 'tc_kimlik_no', 'ten_rengi', 'yakin_zamanda_ameliyat_gecirildi',
        'cinsiyet', 'dogum_tarihi', 'adres', 'meslek', 'ev_telefon',
    ];
}
