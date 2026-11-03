<?php

namespace App\Domain\Kampanya;

/**
 * Kampanya KATILIMCISI durumu (saf) — rapor sekmeleri (filtre) + satır rozeti TEK yerde.
 *
 * kampanya_katilimcilari alanları (eski arama cron'u yazar):
 *   durum_asistan   1 ulaşıldı·katıldı · 0 ulaşıldı·katılmadı · NULL henüz sonuç yok
 *   kilitli         1 şu an aranıyor
 *   tekrar_aranacak 1 ilk deneme cevapsız → tekrar_arama_tarih_saat'te bir kez daha
 *   tekrar_arandi   1 ikinci deneme de cevapsız → ulaşılamadı (final)
 *   indirim_kodu_kullanildi 1 kupon tahsilatta kullanıldı
 *
 * Filtre kodları eski web (katilimDurumu 1..7) ve Flutter (1..4) sayılarıyla aynı.
 */
enum KatilimDurumu: int
{
    case Tumu = 1;
    case IndirimKullanan = 2;
    case IndirimKullanmayan = 3;
    case Beklenen = 4;
    case Katilan = 5;
    case Katilmayan = 6;
    case Ulasilamadi = 7;

    /** Sayı (eski) ya da kod ('katilan') kabul eder; geçersizse Tümü. */
    public static function coz(mixed $v): self
    {
        if (is_numeric($v)) {
            return self::tryFrom((int) $v) ?? self::Tumu;
        }
        foreach (self::cases() as $c) {
            if ($c->kod() === $v) {
                return $c;
            }
        }

        return self::Tumu;
    }

    public function kod(): string
    {
        return match ($this) {
            self::Tumu               => 'tumu',
            self::IndirimKullanan    => 'indirim_kullanan',
            self::IndirimKullanmayan => 'indirim_kullanmayan',
            self::Beklenen           => 'beklenen',
            self::Katilan            => 'katilan',
            self::Katilmayan         => 'katilmayan',
            self::Ulasilamadi        => 'ulasilamadi',
        };
    }

    public function ad(): string
    {
        return match ($this) {
            self::Tumu               => 'Tümü',
            self::IndirimKullanan    => 'İndirim Kullanan',
            self::IndirimKullanmayan => 'İndirim Kullanmayan',
            self::Beklenen           => 'Beklenenler',
            self::Katilan            => 'Katılan',
            self::Katilmayan         => 'Katılmayan',
            self::Ulasilamadi        => 'Ulaşılamadı',
        };
    }

    /** Yalnız arama kanalında anlamlı sekmeler (durum_asistan'ı arama cron'u yazar). */
    public function yalnizArama(): bool
    {
        return in_array($this, [self::Beklenen, self::Katilan, self::Katilmayan, self::Ulasilamadi], true);
    }

    /** SQL koşulu (alias $k = kampanya_katilimcilari); Tümü için null. */
    public function sqlKosulu(string $k = 'kk'): ?string
    {
        return match ($this) {
            self::Tumu               => null,
            self::IndirimKullanan    => "$k.indirim_kodu_kullanildi = 1",
            self::IndirimKullanmayan => "($k.indirim_kodu_kullanildi IS NULL OR $k.indirim_kodu_kullanildi <> 1)",
            self::Beklenen           => "$k.durum_asistan IS NULL",
            self::Katilan            => "$k.durum_asistan = 1",
            self::Katilmayan         => "$k.durum_asistan = 0",
            self::Ulasilamadi        => "($k.tekrar_arandi = 1 AND $k.durum_asistan IS NULL)",
        };
    }

    /** @return array<int, array{kod:string, no:int, ad:string, yalniz_arama:bool}> */
    public static function sozluk(): array
    {
        return array_map(fn (self $c) => ['kod' => $c->kod(), 'no' => $c->value, 'ad' => $c->ad(), 'yalniz_arama' => $c->yalnizArama()], self::cases());
    }

    /**
     * Satır rozeti (eski kampanyadetay öncelik sırası BİREBİR). Arama dışı kanallarda arama
     * sonucu yoktur → "Gönderim listesinde".
     *
     * @return array{kod:string, etiket:string, renk:string}
     */
    public static function satir(object $r, Kanal $kanal): array
    {
        if ($kanal !== Kanal::Arama) {
            return ['kod' => 'listede', 'etiket' => 'Gönderim listesinde', 'renk' => 'gri'];
        }
        $da = $r->durum_asistan;

        return match (true) {
            $da !== null && (int) $da === 1     => ['kod' => 'katildi', 'etiket' => 'Ulaşıldı · Katıldı', 'renk' => 'yesil'],
            $da !== null && (int) $da === 0     => ['kod' => 'katilmadi', 'etiket' => 'Ulaşıldı · Katılmadı', 'renk' => 'kirmizi'],
            (int) ($r->kilitli ?? 0) === 1      => ['kod' => 'araniyor', 'etiket' => 'Aranıyor…', 'renk' => 'mavi'],
            (int) ($r->tekrar_arandi ?? 0) === 1 => ['kod' => 'ulasilamadi', 'etiket' => 'Ulaşılamadı', 'renk' => 'kirmizi'],
            (int) ($r->tekrar_aranacak ?? 0) === 1 => ['kod' => 'tekrar', 'etiket' => 'Tekrar aranacak'.self::zaman($r->tekrar_arama_tarih_saat ?? null), 'renk' => 'turuncu'],
            default                              => ['kod' => 'sirada', 'etiket' => 'Sırada (aranacak)', 'renk' => 'turuncu'],
        };
    }

    private static function zaman(?string $ts): string
    {
        return $ts ? ' · '.date('d.m.Y H:i', strtotime($ts)) : '';
    }
}
