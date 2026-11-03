<?php

namespace App\Domain\BildirimReklam;

/**
 * Reklamın hedef kitlesi — bildirim_reklamlari.hedef_kitle ('tumu' | 'segment') +
 * hedef_kosul (JSON) çiftinin TEK okuma/yazma noktası (saf, DB'siz).
 *
 * DB biçimi eskiyle birebir: {"tip":"gelmeyen","gun":60} · {"tip":"hizmet","hizmet_id":5} ·
 * {"tip":"urun","urun_id":7} · {"tip":"cinsiyet","cinsiyet":"0"} · {"tip":"kisi","user_id":42} ·
 * {"tip":"dogum_gunu"}. cinsiyet eskiden de METİN ('0' kadın / '1' erkek) yazılıyordu.
 */
final class HedefKosulu
{
    private function __construct(
        public readonly ?HedefSegment $segment,
        public readonly ?int $deger,
    ) {}

    public static function tumu(): self
    {
        return new self(null, null);
    }

    public static function segment(HedefSegment $segment, ?int $deger): self
    {
        if ($segment === HedefSegment::GELMEYEN && (int) $deger <= 0) {
            $deger = HedefSegment::VARSAYILAN_GUN;
        }
        if ($segment === HedefSegment::CINSIYET) {
            $deger = (int) $deger === 1 ? 1 : 0;
        }

        return new self($segment, $segment->alan() === null ? null : $deger);
    }

    /** DB satırından (hedef_kitle + hedef_kosul). Bozuk / eksik JSON → tüm müşteriler (eski job ile aynı). */
    public static function dbden(?string $hedefKitle, ?string $hedefKosul): self
    {
        if ($hedefKitle !== 'segment' || ! $hedefKosul) {
            return self::tumu();
        }
        $k = json_decode($hedefKosul, true);
        $segment = is_array($k) ? HedefSegment::tryFrom((string) ($k['tip'] ?? '')) : null;
        if (! $segment) {
            return self::tumu();
        }
        $alan = $segment->alan();

        return self::segment($segment, $alan !== null && isset($k[$alan]) && $k[$alan] !== '' ? (int) $k[$alan] : null);
    }

    public function segmentMi(): bool
    {
        return $this->segment !== null;
    }

    /** Yalnızca TEK kişiye hedefli mi (in-app kartı da yalnız ona görünür). */
    public function kisiyeOzelMi(): bool
    {
        return $this->segment === HedefSegment::KISI;
    }

    /** @return array{hedef_kitle:string, hedef_kosul:?string} DB kolonları */
    public function db(): array
    {
        if (! $this->segment) {
            return ['hedef_kitle' => 'tumu', 'hedef_kosul' => null];
        }
        $kosul = ['tip' => $this->segment->value];
        if ($alan = $this->segment->alan()) {
            $kosul[$alan] = $this->segment === HedefSegment::CINSIYET ? (string) $this->deger : (int) $this->deger;
        }

        return ['hedef_kitle' => 'segment', 'hedef_kosul' => json_encode($kosul)];
    }

    /** API sunumu: {kitle, segment, deger} — istemciler JSON çözmez. */
    public function dizi(): array
    {
        return [
            'kitle'   => $this->segment ? 'segment' : 'tumu',
            'segment' => $this->segment?->value,
            'deger'   => $this->deger,
            'etiket'  => $this->segment ? $this->segment->etiket() : 'Tüm müşteriler',
        ];
    }
}
