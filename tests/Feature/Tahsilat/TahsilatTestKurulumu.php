<?php

namespace Tests\Feature\Tahsilat;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Tahsilat dagitim testlerinin izole sqlite semasi: yalniz kalem, tahsilat satiri ve vade tablolari. */
trait TahsilatTestKurulumu
{
    protected function tahsilatKur(): void
    {
        config([
            'database.default' => 'tahsilat_sqlite',
            'database.connections.tahsilat_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('tahsilat_sqlite');
        DB::setDefaultConnection('tahsilat_sqlite');

        foreach (['adisyon_hizmetler', 'adisyon_urunler', 'adisyon_paketler'] as $tablo) {
            Schema::create($tablo, function (Blueprint $t) {
                $t->id();
                $t->integer('adisyon_id')->nullable();
                $t->decimal('fiyat', 10, 2)->nullable();
                $t->decimal('indirim_tutari', 10, 2)->nullable();
                foreach (['senet_id', 'taksitli_tahsilat_id', 'hizmet_id', 'urun_id', 'paket_id', 'randevu_id', 'seans_sayisi'] as $k) {
                    $t->integer($k)->nullable();
                }
                $t->timestamps();
            });
        }
        foreach (['tahsilat_hizmetler' => 'adisyon_hizmet_id', 'tahsilat_urunler' => 'adisyon_urun_id', 'tahsilat_paketler' => 'adisyon_paket_id'] as $tablo => $fk) {
            Schema::create($tablo, function (Blueprint $t) use ($fk) {
                $t->id();
                $t->integer('tahsilat_id')->nullable();
                $t->integer($fk)->nullable();
                $t->decimal('tutar', 10, 2)->nullable();
                $t->string('aciklama')->nullable();
                $t->integer('salon_id')->nullable();
                $t->integer('user_id')->nullable();
                $t->timestamps();
            });
        }
        foreach (['taksit_vadeleri' => 'taksitli_tahsilat_id', 'senet_vadeleri' => 'senet_id'] as $tablo => $fk) {
            Schema::create($tablo, function (Blueprint $t) use ($fk) {
                $t->id();
                $t->integer($fk)->nullable();
                $t->decimal('tutar', 10, 2)->nullable();
                $t->integer('odendi')->default(0);
                $t->integer('odeme_yontemi_id')->nullable();
                $t->date('vade_tarih')->nullable();
                $t->timestamps();
            });
        }
        Schema::create('taksitli_tahsilatlar', function (Blueprint $t) {
            $t->id();
            foreach (['user_id', 'adisyon_id', 'vade_sayisi', 'salon_id', 'olusturan_id'] as $k) {
                $t->integer($k)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('alacaklar', function (Blueprint $t) {
            $t->id();
            foreach (['adisyon_id', 'salon_id', 'user_id', 'taksitli_tahsilat_id', 'taksit_vade_id', 'senet_vade_id', 'olusturan_id'] as $k) {
                $t->integer($k)->nullable();
            }
            $t->decimal('tutar', 10, 2)->nullable();
            $t->date('planlanan_odeme_tarihi')->nullable();
            $t->string('aciklama')->nullable();
            $t->timestamps();
        });
        Schema::create('kampanya_katilimcilari', function (Blueprint $t) {
            $t->id();
            $t->integer('indirim_kodu_adisyon_id')->nullable();
            $t->decimal('indirim_kodu_tutar', 10, 2)->nullable();
            $t->integer('indirim_kodu_tahsilata_dusuldu')->default(0);
        });
        Schema::create('tahsilat_indirim_hareketleri', function (Blueprint $t) {
            $t->id();
            $t->integer('tahsilat_id');
            $t->string('kalem_tip');
            $t->integer('kalem_id');
            $t->decimal('indirim_delta', 10, 2);
            $t->timestamps();
        });
    }

    protected function kalem(string $tablo, array $alan): int
    {
        return DB::table($tablo)->insertGetId($alan + ['adisyon_id' => 1]);
    }

    protected function odenmis(string $tablo, string $fk, int $kalemId, string $tutar): void
    {
        DB::table($tablo)->insert(['tahsilat_id' => 999, $fk => $kalemId, 'tutar' => $tutar]);
    }

    /** @return array<int,float> kalem id => yazilan tutar toplami */
    protected function yazilan(string $tablo, string $fk, int $tahsilatId): array
    {
        return DB::table($tablo)->where('tahsilat_id', $tahsilatId)->orderBy($fk)->pluck('tutar', $fk)->map(fn ($v) => (float) $v)->all();
    }
}
