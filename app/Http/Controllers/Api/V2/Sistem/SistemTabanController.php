<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Domain\SistemYonetim\SistemYetki;
use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Api\V2\V2Controller;
use App\Models\SistemYoneticisi;
use App\Services\SistemYonetim\SalonKapsami;
use App\Support\DogrulamaMetinleri;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Sistem yonetimi controller'larinin tabani: kimlik sistemyonetim-api guard'indan. Rol kapisi rotalarda (sistem.rol). */
abstract class SistemTabanController extends V2Controller
{
    protected ?string $yazmaAlani = 'sistem';

    /** @var array<string,string> ekrana ozel alan → Turkce etiket */
    protected const ETIKETLER = [];

    protected function yonetici(): SistemYoneticisi
    {
        $yonetici = auth('sistemyonetim-api')->user();
        if (! $yonetici) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }

        return $yonetici;
    }

    /** Isletme kimligine bakan taban yardimcilari bu panelde kullanilmaz. */
    protected function kullanici()
    {
        return $this->yonetici();
    }

    /** Salon: yoksa 404, destek rolunde kapsam disiysa 403. */
    protected function salon(int $salonId, array $kolonlar = ['*']): object
    {
        return app(SalonKapsami::class)->salonuGetir($salonId, $this->yonetici(), $kolonlar);
    }

    protected function dogrula(Request $request, array $kurallar): array
    {
        return Validator::make($request->all(), $kurallar, DogrulamaMetinleri::mesajlar(), static::ETIKETLER + DogrulamaMetinleri::alanlar())->validate();
    }

    /** Ekrana giden izin bayragi; asil kapi rotada. */
    protected function izinli(string $roller): bool
    {
        return SistemYetki::izinli($this->yonetici()->rol(), $roller);
    }
}
