<?php

namespace App\Services\Cark;

use App\Domain\Cark\DilimSeti;
use App\Domain\Cark\DilimTipi;
use App\Exceptions\GecersizIslemException;
use App\Support\AktiviteLog;
use App\Support\SalonIdListesi;
use App\Support\BildirimKuyrugu;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Çarkıfelek AYARI (carkifelek_sistemi + carkifelek_dilimleri) — okuma + kaydet + aktiflik +
 * müşteri duyurusu. Eski web carkverilerigetir/carkdilimekle ile mobil carkSistemGetir/
 * carkDilimKaydet/carkAktifToggle/carkBildirimGonder'in tek karşılığı.
 *
 * Çoklu şube: kaydet seçilen şubelerin HER BİRİNE aynı çarkı yazar ve grubu gecerli_salonlar'a
 * koyar (kazanılan kupon bu grubu miras alır — müşteri çark ekranı eski monolitte).
 */
class CarkAyarServisi
{
    private const SISTEM = 'carkifelek_sistemi';
    private const DILIM = 'carkifelek_dilimleri';

    /**
     * @return array{sistem: array, dilimler: array}
     *   sistem.id null → salon henüz çark kurmamış (editör varsayilan_dilimler ile açılır)
     */
    public function getir(int $salonId): array
    {
        $s = DB::table(self::SISTEM)->where('salon_id', $salonId)->first();
        $kurallar = trim((string) ($s->kullanim_kurallari ?? ''));

        return [
            'sistem' => [
                'id'                        => $s ? (int) $s->id : null,
                'aktifmi'                   => $s ? (int) $s->aktifmi : 1,
                'kurallar'                  => $kurallar !== '' ? $kurallar : config('cark.varsayilan_kurallar'),
                'kurallar_varsayilan'       => $kurallar === '',
                'cevirme_araligi_gun'       => max(1, (int) ($s->cevirme_araligi_gun ?? 1)),
                'kupon_cark_gecerlilik_gun' => (int) ($s->kupon_cark_gecerlilik_gun ?? 0),
                'kupon_puan_gecerlilik_gun' => (int) ($s->kupon_puan_gecerlilik_gun ?? 0),
                'gecerli_salonlar'          => SalonIdListesi::coz($s->gecerli_salonlar ?? null),
            ],
            'dilimler' => $s ? $this->dilimler((int) $s->id) : [],
        ];
    }

    /**
     * Dilimler + ayarlar seçilen şubelere ATOMİK yazılır (dilimler silinip yeniden yazılır;
     * hata olursa hiçbir şube değişmez).
     *
     * @param  array{dilimler:array, aktifmi?:bool, kurallar?:?string, cevirme_araligi_gun?:int,
     *               kupon_cark_gecerlilik_gun?:int, kupon_puan_gecerlilik_gun?:int}  $v
     * @param  int[]  $hedefSubeler
     * @return int[] yazılan şubeler
     */
    public function kaydet(int $salonId, int $yetkiliId, array $v, array $hedefSubeler): array
    {
        try {
            $dilimler = DilimSeti::olustur($v['dilimler'], config('cark.dilim_min'), config('cark.dilim_max'), config('cark.renkler'));
        } catch (InvalidArgumentException $e) {
            throw new GecersizIslemException($e->getMessage());
        }

        // Yalnız gönderilen alanlar yazılır (eksik alan mevcut değeri korur).
        $ayar = ['gecerli_salonlar' => json_encode(array_values($hedefSubeler))];
        if (isset($v['aktifmi'])) {
            $ayar['aktifmi'] = (int) (bool) $v['aktifmi'];
        }
        if (array_key_exists('kurallar', $v)) {
            $ayar['kullanim_kurallari'] = self::kurallariTemizle($v['kurallar']);
        }
        if (isset($v['cevirme_araligi_gun'])) {
            $ayar['cevirme_araligi_gun'] = max(1, (int) $v['cevirme_araligi_gun']);
        }
        foreach (['kupon_cark_gecerlilik_gun', 'kupon_puan_gecerlilik_gun'] as $alan) {
            if (isset($v[$alan])) {
                $ayar[$alan] = max(0, (int) $v[$alan]) ?: null;   // 0 → süresiz (NULL), eskiyle aynı
            }
        }

        $anaCarkId = DB::transaction(function () use ($salonId, $hedefSubeler, $ayar, $dilimler) {
            $anaId = null;
            foreach ($hedefSubeler as $hedef) {
                $id = $this->sistemYaz((int) $hedef, $ayar);
                DB::table(self::DILIM)->where('cark_id', $id)->delete();
                DB::table(self::DILIM)->insert(array_map(fn ($d) => [
                    'cark_id'        => $id,
                    'dilim_ismi'     => $d['name'],
                    'dilim_olasilik' => $d['probability'],
                    'renk_kodu'      => $d['color'],
                    'tip'            => $d['tip'],
                    'deger'          => $d['deger'],
                    'indirim_tipi'   => $d['indirim_tipi'],
                    'kupon_mu'       => $d['kupon_mu'],
                    'sira'           => $d['sira'],
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ], $dilimler));
                if ((int) $hedef === $salonId || $anaId === null) {
                    $anaId = $id;
                }
            }

            return $anaId;
        });

        $kazanan = collect($dilimler)->firstWhere('probability', 100);
        AktiviteLog::yaz($salonId, $yetkiliId, 'cark_dilim_kaydet', 'cark', $anaCarkId, count($dilimler).' dilim',
            'Çark dilimleri (ödüller) güncellendi ('.count($hedefSubeler).' şube)',
            ['subeler' => $hedefSubeler, 'kazanan' => $kazanan['name'] ?? null]);

        return $hedefSubeler;
    }

    /** Aktif/pasif — çarkın uygulandığı şube grubuna da yansır (mobil carkAktifToggle ile aynı). */
    public function aktiflik(int $salonId, int $yetkiliId, bool $aktif): int
    {
        $s = DB::table(self::SISTEM)->where('salon_id', $salonId)->first(['id', 'gecerli_salonlar']);
        if (! $s) {
            throw new GecersizIslemException('Önce çark dilimlerini kaydedin.');
        }
        $grup = array_values(array_unique([$salonId, ...SalonIdListesi::coz($s->gecerli_salonlar)]));
        DB::table(self::SISTEM)->whereIn('salon_id', $grup)->update(['aktifmi' => (int) $aktif, 'updated_at' => now()]);
        AktiviteLog::yaz($salonId, $yetkiliId, 'cark_aktif_toggle', 'cark', (int) $s->id, $aktif ? 'Aktif' : 'Pasif', 'Çark aktiflik durumu değiştirildi');

        return (int) $aktif;
    }

    /**
     * Uygulaması yüklü müşterilere "çarkı çevir" push duyurusu. Gönderim eski monolitte
     * (outbox 'cark_duyuru' → CarkDuyuruServisi: hedef kitle + kişiselleştirme + FCM). Burada
     * yalnız kural (çark aktif olmalı) ve kuyruk kaydı.
     */
    public function duyuruKuyrugaAl(int $salonId, int $yetkiliId, ?string $baslik, ?string $mesaj): void
    {
        if (! DB::table(self::SISTEM)->where('salon_id', $salonId)->where('aktifmi', 1)->exists()) {
            throw new GecersizIslemException('Çark sistemi aktif değil; önce çarkı açın.');
        }
        $mesaj = trim((string) $mesaj) ?: config('cark.duyuru.mesaj');
        BildirimKuyrugu::yaz($salonId, 'cark_duyuru', null, $yetkiliId, ['baslik' => trim((string) $baslik), 'mesaj' => $mesaj]);
        AktiviteLog::yaz($salonId, $yetkiliId, 'cark_duyuru_gonder', 'cark', null, mb_substr($mesaj, 0, 80), 'Çark duyurusu müşterilere gönderilmek üzere kuyruğa alındı');
    }

    // ───────────────────────────── yardımcılar ─────────────────────────────

    private function dilimler(int $carkId): array
    {
        return DB::table(self::DILIM)->where('cark_id', $carkId)->orderBy('sira')->get()
            ->map(fn ($d) => [
                'id'           => (int) $d->id,
                'name'         => (string) $d->dilim_ismi,
                'color'        => (string) $d->renk_kodu,
                'probability'  => (int) $d->dilim_olasilik,
                'tip'          => DilimTipi::gecerli($d->tip),
                'deger'        => $d->deger !== null ? (float) $d->deger : null,
                'indirim_tipi' => DilimTipi::birim(DilimTipi::gecerli($d->tip), $d->indirim_tipi),
                'kupon_mu'     => (int) $d->kupon_mu,
                'sira'         => (int) $d->sira,
            ])->all();
    }

    private function sistemYaz(int $salonId, array $ayar): int
    {
        $id = DB::table(self::SISTEM)->where('salon_id', $salonId)->value('id');
        if ($id) {
            DB::table(self::SISTEM)->where('id', $id)->update($ayar + ['updated_at' => now()]);

            return (int) $id;
        }

        return (int) DB::table(self::SISTEM)->insertGetId($ayar + ['aktifmi' => 1, 'salon_id' => $salonId, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Boş / yalnız madde işareti olan satırlar atılır (eski web temizleKurallar); tamamen boş → NULL (varsayılan kurallar). */
    private static function kurallariTemizle(?string $metin): ?string
    {
        $satirlar = array_filter(array_map('rtrim', preg_split('/\R/u', (string) $metin)), fn ($l) => ! in_array(trim($l), ['', '•', '-'], true));

        return $satirlar ? implode("\n", $satirlar) : null;
    }
}
