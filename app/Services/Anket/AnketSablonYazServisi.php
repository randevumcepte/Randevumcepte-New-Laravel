<?php

namespace App\Services\Anket;

use App\Domain\Anket\AnketOdulu;
use App\Domain\Anket\AnketSorulari;
use App\Exceptions\GecersizIslemException;
use App\Services\Yetki\SalonSahipligi;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Anket şablonu YAZMA — eski web (anketSablonKaydet/Guncelle/Sil) + mobil v1 (anketSablonOlustur/Guncelle/Sil)
 * kopyalarının tek karşılığı. Güncelleme kısmidir (gönderilmeyen alan korunur; mobil v1 ile aynı).
 * Salonda en fazla bir varsayılan şablon olur (transaction içinde).
 */
class AnketSablonYazServisi
{
    public function __construct(private SalonSahipligi $sahiplik) {}

    /** @return int şablon id */
    public function kaydet(int $salonId, ?int $id, array $g, int $yetkiliId): int
    {
        $yeni = $id === null;
        $kolonlar = $this->kolonlar($salonId, $g, $yeni);

        $id = DB::transaction(function () use ($salonId, $id, $kolonlar) {
            if (! empty($kolonlar['varsayilan'])) {
                DB::table('anket_sablonlari')->where('salon_id', $salonId)->when($id, fn ($q) => $q->where('id', '!=', $id))->update(['varsayilan' => 0]);
            }
            if ($id) {
                DB::table('anket_sablonlari')->where('id', $id)->update($kolonlar + ['updated_at' => now()]);

                return $id;
            }

            return (int) DB::table('anket_sablonlari')->insertGetId($kolonlar + ['salon_id' => $salonId, 'created_at' => now(), 'updated_at' => now()]);
        });

        AktiviteLog::yaz($salonId, $yetkiliId, $yeni ? 'anket_sablon_ekle' : 'anket_sablon_guncelle', 'anket_sablon', $id,
            (string) ($kolonlar['ad'] ?? DB::table('anket_sablonlari')->where('id', $id)->value('ad')),
            $yeni ? 'Yeni memnuniyet anketi şablonu oluşturuldu' : 'Anket şablonu güncellendi');

        return $id;
    }

    /**
     * Gönderimde kullanılmış şablon silinmez, pasifleşir (geçmiş cevaplar soruları buradan okur).
     *
     * @return array{silindi:bool, pasiflestirildi:bool, kullanim:int}
     */
    public function sil(int $id, int $salonId, int $yetkiliId): array
    {
        $ad = (string) DB::table('anket_sablonlari')->where('id', $id)->value('ad');
        $kullanim = DB::table('anket_gonderimleri')->where('sablon_id', $id)->count();

        if ($kullanim > 0) {
            DB::table('anket_sablonlari')->where('id', $id)->update(['aktif' => 0, 'updated_at' => now()]);
            AktiviteLog::yaz($salonId, $yetkiliId, 'anket_sablon_pasifle', 'anket_sablon', $id, $ad,
                'Anket şablonu kullanımda olduğu için pasifleştirildi', ['kullanim_sayisi' => $kullanim]);

            return ['silindi' => false, 'pasiflestirildi' => true, 'kullanim' => $kullanim];
        }

        DB::table('anket_sablonlari')->where('id', $id)->delete();
        AktiviteLog::yaz($salonId, $yetkiliId, 'anket_sablon_sil', 'anket_sablon', $id, $ad, 'Anket şablonu silindi');

        return ['silindi' => true, 'pasiflestirildi' => false, 'kullanim' => 0];
    }

    /**
     * Hızlı gönderimin şablonu: varsayılan işaretli aktif şablon, yoksa ilk aktif şablon, o da yoksa
     * oluşturulur — kullanıcıdan şablon kurması beklenmez (eski varsayilanAnketSablonuGetirYaOlustur).
     */
    public function varsayilanGetirYaOlustur(int $salonId): object
    {
        $s = DB::table('anket_sablonlari')->where('salon_id', $salonId)->where('aktif', 1)
            ->orderByDesc('varsayilan')->orderBy('id')->first();
        if ($s) {
            return $s;
        }

        $id = DB::table('anket_sablonlari')->insertGetId([
            'salon_id'          => $salonId,
            'ad'                => 'Varsayılan Memnuniyet Anketi',
            'aciklama'          => 'Sistem tarafından ilk manuel gönderim sırasında otomatik oluşturuldu.',
            'sorular_json'      => json_encode(AnketSorulari::hazirSablon(), JSON_UNESCAPED_UNICODE),
            'otomatik_gonder'   => 0,
            'gonder_saat_sonra' => 0,
            'aktif'             => 1,
            'varsayilan'        => 1,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return DB::table('anket_sablonlari')->where('id', $id)->first();
    }

    private function kolonlar(int $salonId, array $g, bool $yeni): array
    {
        $k = [];
        if (array_key_exists('ad', $g)) {
            $k['ad'] = trim((string) $g['ad']);
        }
        if (array_key_exists('aciklama', $g)) {
            $k['aciklama'] = $g['aciklama'];
        }
        if (array_key_exists('sorular', $g)) {
            try {
                $k['sorular_json'] = json_encode(AnketSorulari::normalize($g['sorular']), JSON_UNESCAPED_UNICODE);
            } catch (\InvalidArgumentException $e) {
                throw new GecersizIslemException($e->getMessage());
            }
        }
        if ($yeni || array_key_exists('otomatik_gonder', $g)) {
            $k['otomatik_gonder'] = empty($g['otomatik_gonder']) ? 0 : 1;
        }
        if ($yeni || array_key_exists('gonder_saat_sonra', $g)) {
            $k['gonder_saat_sonra'] = (int) ($g['gonder_saat_sonra'] ?? 0);
        }
        if ($yeni || array_key_exists('varsayilan', $g)) {
            $k['varsayilan'] = empty($g['varsayilan']) ? 0 : 1;
        }
        if ($yeni) {
            $k['aktif'] = 1;
        } elseif (array_key_exists('aktif', $g)) {
            $k['aktif'] = empty($g['aktif']) ? 0 : 1;
        }
        if ($yeni || array_key_exists('odul_tipi', $g)) {
            $odul = AnketOdulu::girdiden($g, AnketOdulu::ONEK_SABLON);
            if ($odul->hizmetId) {
                $this->sahiplik->hizmetOlmali($odul->hizmetId, $salonId);
            }
            $k += $odul->kolonlar(AnketOdulu::ONEK_SABLON);
        }

        return $k;
    }
}
