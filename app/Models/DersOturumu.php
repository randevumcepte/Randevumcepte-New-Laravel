<?php

namespace App\Models;

use App\Domain\Ders\KatilimciDurumu;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eski monolitle PAYLASILAN 'ders_oturumlari': kapasiteli grup dersi oturumu.
 * Randevudan AYRI tablodur (randevular tek musteri tasir). Silme soft: aktif=0 + iptal=1.
 * Sema eski sistemce yonetilir (burada migration YOK).
 */
class DersOturumu extends Model
{
    public const VARSAYILAN_AD = 'Grup Dersi';

    protected $table = 'ders_oturumlari';

    protected $fillable = [
        'salon_id', 'sube_id', 'personel_id', 'ders_tipi', 'hizmet_id', 'tarih', 'saat', 'saat_bitis',
        'kapasite', 'sablon_id', 'iptal', 'aktif', 'renk', 'not', 'olusturan_personel_id',
    ];

    public function katilimcilar(): HasMany
    {
        return $this->hasMany(DersKatilimci::class, 'oturum_id');
    }

    /** Kontenjani dolduranlar (iptal/bekleme haric). */
    public function kontenjandakiler(): HasMany
    {
        return $this->katilimcilar()->whereNotIn('durum', KatilimciDurumu::KONTENJAN_DISI);
    }

    public function personel(): BelongsTo
    {
        return $this->belongsTo(Personeller::class, 'personel_id');
    }

    /** Takvimde/listede gecerli oturumlar (iptal kolonu eski kayitlarda NULL olabilir). */
    public function scopeYayinda(Builder $q): Builder
    {
        return $q->where('aktif', 1)->where(fn ($w) => $w->whereNull('iptal')->orWhere('iptal', 0));
    }

    public function iptalMi(): bool
    {
        return (bool) $this->iptal || ! $this->aktif;
    }

    public function dolulukSay(): int
    {
        return $this->kontenjandakiler()->count();
    }

    public function ad(): string
    {
        return $this->ders_tipi ?: self::VARSAYILAN_AD;
    }

    public function basladiMi(): bool
    {
        return strtotime($this->tarih.' '.$this->saat) < time();
    }
}
