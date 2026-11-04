<?php

namespace App\Models;

use App\Domain\Ders\KatilimciDurumu;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Eski monolitle PAYLASILAN 'ders_katilimcilar': oturuma yazili musteri (users.id). */
class DersKatilimci extends Model
{
    /** not kolonu: bu katilimci bekleyen bir telafiye baglandi (telafi baska derse tekrar baglanmaz). */
    public const NOT_TELAFI_BAGLI = 'telafi_bagli';

    protected $table = 'ders_katilimcilar';

    protected $fillable = [
        'oturum_id', 'user_id', 'salon_id', 'durum', 'adisyon_paket_id', 'ekleyen_personel_id',
        'not', 'aps_id', 'hak_dusuldu', 'tekrarli_id',
    ];

    public function oturum(): BelongsTo
    {
        return $this->belongsTo(DersOturumu::class, 'oturum_id');
    }

    public function musteri(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function durumu(): KatilimciDurumu
    {
        return KatilimciDurumu::tryFrom((string) $this->durum) ?? KatilimciDurumu::REZERVE;
    }
}
