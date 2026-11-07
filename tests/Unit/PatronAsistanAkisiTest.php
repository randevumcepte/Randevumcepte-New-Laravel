<?php

namespace Tests\Unit;

use App\Exceptions\GecersizIslemException;
use App\Services\PatronAsistan\PatronAsistanAkisi;
use App\Services\PatronAsistan\PatronAsistanServisi;
use Tests\TestCase;

/** Veritabanina dokunmayan kurallar. Sunucuda: php artisan test --filter=PatronAsistanAkisiTest */
class PatronAsistanAkisiTest extends TestCase
{
    public function test_niyet_ve_donem_kural_motorundan_cozulur(): void
    {
        $niyet = app(PatronAsistanServisi::class)->niyetCoz('bu ay kasa ne durumda');

        $this->assertSame('kasa', $niyet['intent']);
        $this->assertSame('ay', $niyet['donem']);
    }

    public function test_kufur_niyet_cozmeden_uyari_doner(): void
    {
        $asistan = app(PatronAsistanServisi::class);

        $this->assertTrue($asistan->kufurMu('aptal'));
        $this->assertNotEmpty($asistan->kufurCevabi()['cevap']);
    }

    public function test_bilinmeyen_islem_reddedilir(): void
    {
        $this->expectException(GecersizIslemException::class);
        $this->expectExceptionMessage('Bilinmeyen işlem.');

        app(PatronAsistanAkisi::class)->uygula(1, 1, 'sil', 'genel', 15);
    }
}
