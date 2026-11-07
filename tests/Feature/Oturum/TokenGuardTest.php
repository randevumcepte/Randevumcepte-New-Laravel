<?php

namespace Tests\Feature\Oturum;

use Illuminate\Auth\RequestGuard;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TokenGuardTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function guardlar(): array
    {
        return [
            'musteri' => ['api'],
            'isletme' => ['isletmeyonetim-api'],
            'sistem' => ['sistemyonetim-api'],
        ];
    }

    #[DataProvider('guardlar')]
    public function test_guard_kurulur(string $guard): void
    {
        $this->assertInstanceOf(RequestGuard::class, Auth::guard($guard));
    }

    public function test_tokensiz_istek_401_doner(): void
    {
        $this->getJson('/api/v2/sms/bakiye')->assertUnauthorized();
    }

    public function test_gecersiz_token_401_doner(): void
    {
        $this->withToken('gecersiz')->getJson('/api/v2/sms/bakiye')->assertUnauthorized();
    }

    public function test_randevu_listesi_ayrintisi_tokensiz_401_doner(): void
    {
        $this->getJson('/api/v2/randevu/liste?salon_id=20&ayrinti=1')->assertUnauthorized();
    }

    public function test_musteri_uclari_tokensiz_401_doner(): void
    {
        $this->getJson('/api/v2/musteri?salon_id=20&ayrinti=1')->assertUnauthorized();
        $this->getJson('/api/v2/musteri/ara?salon_id=20&tumu=1')->assertUnauthorized();
        $this->getJson('/api/v2/musteri/sayilar?salon_id=20')->assertUnauthorized();
    }

    public function test_takvim_ayrintisi_tokensiz_401_doner(): void
    {
        $this->getJson('/api/v2/randevu/takvim?salon_id=20&ayrinti=1')->assertUnauthorized();
    }
}
