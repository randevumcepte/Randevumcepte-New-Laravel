<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PAYLASILAN 'adisyonlar' tablosu (satis basligi). v2 yeni adisyonu yalniz ac() ile acar. */
class Adisyonlar extends Model
{
    protected $table = 'adisyonlar';

    protected $guarded = [];

    /**
     * Yeni adisyon (satış başlığı) AÇ — TEK KAYNAK. Tüm v2 satış yazıcıları (Yeni Satış,
     * paket satışı, hızlı ürün satışı, tek kalemli satış) adisyonu buradan açar.
     * Kalemler ayrıca KalemServisi::ekle ile eklenir (boş adisyon bırakılmaz — çağıran
     * aynı transaction'da en az bir kalem ekler).
     */
    public static function ac(int $salonId, int $musteriId, ?int $olusturanId, ?string $tarih = null): self
    {
        return self::create([
            'user_id'      => $musteriId,
            'salon_id'     => $salonId,
            'olusturan_id' => $olusturanId,
            'tarih'        => $tarih ?: now()->toDateString(),
        ]);
    }

    public function hizmetler()
    {
        return $this->hasMany(AdisyonHizmetler::class, 'adisyon_id');
    }

    public function urunler()
    {
        return $this->hasMany(AdisyonUrunler::class, 'adisyon_id');
    }

    public function paketler()
    {
        return $this->hasMany(AdisyonPaketler::class, 'adisyon_id');
    }

    public function tahsilatlar()
    {
        return $this->hasMany(Tahsilat::class, 'adisyon_id');
    }

    /** Adisyon sahibi musteri (users.name). */
    public function musteri()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Adisyonun ait oldugu salon/sube (salon_adi + studyo_modu etiketi icin). */
    public function salon()
    {
        return $this->belongsTo(Salonlar::class, 'salon_id');
    }
}
