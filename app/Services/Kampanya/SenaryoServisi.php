<?php

namespace App\Services\Kampanya;

use App\Domain\Kampanya\Kanal;
use App\Domain\Kampanya\Senaryo;
use App\Exceptions\BulunamadiException;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * SENARYO SİHİRBAZI — liste / kaydet / sil / önizle (eski senaryo-presetleri|-liste|-kaydet|-sil|-onizle).
 * Kurallar Domain\Kampanya\Senaryo'da. Sistem senaryoları (salon_id NULL) salt okunur; salon
 * yalnız kendi senaryosunu düzenler / siler (silme = aktif=0).
 */
class SenaryoServisi
{
    public function __construct(private KampanyaMesajServisi $mesaj) {}

    public function liste(int $salonId, ?Kanal $kanal): array
    {
        return DB::table('kampanya_senaryolari')->where('aktif', 1)
            ->where(fn ($w) => $w->whereNull('salon_id')->orWhere('salon_id', $salonId))
            ->when($kanal, fn ($w, $k) => $w->where('gorev_turu', $k->value))
            ->orderBy('salon_id')->orderBy('ad')->get()
            ->map(fn ($s) => $this->sun($s, $salonId))->all();
    }

    public function getir(int $salonId, int $id): array
    {
        return $this->sun($this->satir($salonId, $id, false), $salonId);
    }

    /** @param array{ad:string, senaryo_tipi?:?string, gorev_turu?:?int, adimlar:array, aksiyonlar:array} $v */
    public function kaydet(int $salonId, ?int $id, array $v, int $yetkiliId): array
    {
        $yeni = ! $id;
        $kolonlar = [
            'ad'           => trim($v['ad']),
            'senaryo_tipi' => in_array($v['senaryo_tipi'] ?? null, Senaryo::TIPLER, true) ? $v['senaryo_tipi'] : 'ozel',
            'gorev_turu'   => Kanal::degerden($v['gorev_turu'] ?? null)?->value ?? Kanal::Arama->value,
            'adimlar'      => json_encode(Senaryo::adimlariTemizle($v['adimlar'] ?? []), JSON_UNESCAPED_UNICODE),
            'aksiyonlar'   => json_encode(Senaryo::aksiyonlariTemizle($v['aksiyonlar'] ?? []), JSON_UNESCAPED_UNICODE),
            'aktif'        => 1,
            'updated_at'   => now(),
        ];
        if ($id) {
            $this->satir($salonId, $id, true);
            DB::table('kampanya_senaryolari')->where('id', $id)->update($kolonlar);
        } else {
            $id = (int) DB::table('kampanya_senaryolari')->insertGetId($kolonlar + ['salon_id' => $salonId, 'created_at' => now()]);
        }
        AktiviteLog::yaz($salonId, $yetkiliId, $yeni ? 'senaryo_olustur' : 'senaryo_guncelle', 'kampanya_senaryo', $id, $kolonlar['ad'],
            $yeni ? 'Yeni kampanya senaryosu oluşturuldu' : 'Kampanya senaryosu güncellendi', ['senaryo_tipi' => $kolonlar['senaryo_tipi'], 'gorev_turu' => $kolonlar['gorev_turu']]);

        return $this->getir($salonId, $id);
    }

    public function sil(int $salonId, int $id, int $yetkiliId): void
    {
        $s = $this->satir($salonId, $id, true);
        DB::table('kampanya_senaryolari')->where('id', $id)->update(['aktif' => 0, 'updated_at' => now()]);
        AktiviteLog::yaz($salonId, $yetkiliId, 'senaryo_sil', 'kampanya_senaryo', $id, (string) $s->ad, 'Kampanya senaryosu pasif yapıldı');
    }

    /** @param array{adimlar:array, aksiyonlar:array, gorev_turu?:?int, indirim?:?string, gun?:?int, hizmet?:?string} $v */
    public function onizle(int $salonId, array $v): array
    {
        $coz = $this->mesaj->ornekCozucu($salonId, array_filter([
            'indirim' => $v['indirim'] ?? null, 'gun' => $v['gun'] ?? null, 'hizmet' => $v['hizmet'] ?? null,
        ], fn ($x) => $x !== null && $x !== ''));

        return Senaryo::onizle(Senaryo::adimlariTemizle($v['adimlar'] ?? []), Senaryo::aksiyonlariTemizle($v['aksiyonlar'] ?? []),
            Kanal::degerden($v['gorev_turu'] ?? null)?->value ?? Kanal::Arama->value, $coz);
    }

    // ───────────────────────────── iç adımlar ─────────────────────────────

    /** Senaryo satırı; $kendi=true ise yalnız salonun kendi senaryosu (sistem senaryosu → 404). */
    private function satir(int $salonId, int $id, bool $kendi): object
    {
        $q = DB::table('kampanya_senaryolari')->where('id', $id)->where('aktif', 1);
        $kendi ? $q->where('salon_id', $salonId) : $q->where(fn ($w) => $w->whereNull('salon_id')->orWhere('salon_id', $salonId));

        return $q->first() ?? throw new BulunamadiException($kendi ? 'Senaryo bulunamadı veya düzenleme yetkiniz yok' : 'Senaryo bulunamadı');
    }

    private function sun(object $s, int $salonId): array
    {
        $kendi = $s->salon_id !== null && (int) $s->salon_id === $salonId;

        return [
            'id'             => (int) $s->id,
            'value'          => 'senaryo-'.$s->id,   // kampanya sihirbazındaki şablon değeri
            'ad'             => (string) $s->ad,
            'senaryo_tipi'   => (string) $s->senaryo_tipi,
            'gorev_turu'     => (int) $s->gorev_turu,
            'adimlar'        => Senaryo::adimlariTemizle(json_decode((string) $s->adimlar, true) ?: []),
            'aksiyonlar'     => Senaryo::aksiyonlariTemizle(json_decode((string) $s->aksiyonlar, true) ?: []),
            'sistem_mi'      => $s->salon_id === null,
            'duzenlenebilir' => $kendi,
        ];
    }
}
