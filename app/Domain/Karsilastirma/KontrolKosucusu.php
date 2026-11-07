<?php

namespace App\Domain\Karsilastirma;

use LogicException;
use Throwable;

/**
 * Bir esitlik kontrolunde eski ve yeni ucu cagirip alanlari karsilastirir. HTTP disaridan verilir, bu sinif salt hesaptir.
 * Yazma yolu cagrilmaz (MobilTutarKontrolleri::yazmaYoluMu); hata metninde token gorunmez.
 */
final class KontrolKosucusu
{
    public const ESIT = 'esit';

    public const FARK = 'fark';

    public const ATLANDI = 'atlandi';

    public const ELLE = 'elle';

    /** @var callable(string,string,array,array,?string):array{0:int,1:string} */
    private $istek;

    /**
     * @param  callable(string,string,array,array,?string):array{0:int,1:string}  $istek  yontem, adres, sorgu, govde, token => [durum, govde]
     * @param  array<string,mixed>  $degerler  yer tutucu degerleri
     * @param  array<string,string>  $tokenlar  token adi => token
     */
    public function __construct(callable $istek, private array $degerler, private string $eskiTaban, private string $yeniTaban, private array $tokenlar)
    {
        $this->istek = $istek;
    }

    /**
     * @param  array<string,mixed>  $kontrol
     * @return array{ad:string,durum:string,farklar:array<int,string>,neden:?string}
     */
    public function calistir(array $kontrol): array
    {
        $tokenAdi = $kontrol['token'] ?? MobilTutarKontrolleri::TOKEN_ISLETME;
        $token = $this->tokenlar[$tokenAdi] ?? null;
        if ($token === null || $token === '') {
            return $this->sonuc($kontrol, self::ATLANDI, [], "$tokenAdi token'i verilmedi");
        }

        try {
            [$eskiYontem, $eskiYol, $eskiSorgu, $eskiGovde] = array_replace(['POST', '', [], []], $this->doldur($kontrol['eski']));
            [$yeniYol, $yeniSorgu] = array_replace(['', []], $this->doldur($kontrol['yeni']));
        } catch (EksikDeger $e) {
            return $this->sonuc($kontrol, self::ATLANDI, [], 'deger gerekli: '.$e->getMessage());
        }
        if ($eskiYontem !== 'GET' && MobilTutarKontrolleri::yazmaYoluMu($eskiYol)) {
            throw new LogicException('Yazma yolu karsilastirilamaz: '.$eskiYol);
        }

        try {
            [$eskiDurum, $eskiMetin] = ($this->istek)($eskiYontem, $this->eskiTaban.$eskiYol, $eskiSorgu, $eskiGovde, $token);
            [$yeniDurum, $yeniMetin] = ($this->istek)('GET', $this->yeniTaban.$yeniYol, $yeniSorgu, [], $token);
        } catch (Throwable $e) {
            return $this->sonuc($kontrol, self::FARK, ['istek hatasi: '.$this->tokensiz($e->getMessage())]);
        }

        $farklar = [];
        if ($eskiDurum !== 200) {
            $farklar[] = "eski uc HTTP $eskiDurum";
        }
        if ($yeniDurum !== 200) {
            $farklar[] = "yeni uc HTTP $yeniDurum";
        }
        if ($kontrol['pdf'] ?? false) {
            return $this->pdfSonucu($kontrol, $farklar, $eskiMetin, $yeniMetin);
        }
        if ($farklar !== []) {
            return $this->sonuc($kontrol, self::FARK, $farklar);
        }

        $eski = json_decode($eskiMetin, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->sonuc($kontrol, self::FARK, ['eski uc cevabi JSON degil']);
        }
        $yeni = json_decode($yeniMetin, true);
        if (! is_array($yeni) || ($yeni['basarili'] ?? null) !== true) {
            return $this->sonuc($kontrol, self::FARK, ['yeni uc basarili zarf dondurmedi']);
        }

        $farklar = AlanKarsilastirici::farklar(
            $this->kok($eski, $kontrol['eski_kok'] ?? '$'),
            $this->kok($yeni, $kontrol['yeni_kok'] ?? 'veri'),
            $kontrol['alanlar'],
            $kontrol['eslestir'] ?? [],
        );

        return $this->sonuc($kontrol, $farklar === [] ? self::ESIT : self::FARK, $farklar);
    }

    /**
     * @param  array<string,mixed>  $kontrol
     * @param  array<int,string>  $farklar
     */
    private function pdfSonucu(array $kontrol, array $farklar, string $eski, string $yeni): array
    {
        foreach (['eski' => $eski, 'yeni' => $yeni] as $ad => $govde) {
            if (! str_starts_with($govde, '%PDF')) {
                $farklar[] = "$ad uc PDF dondurmedi";
            }
        }

        return $farklar === []
            ? $this->sonuc($kontrol, self::ELLE, [], 'iki uc da PDF dondu; metin ('.implode(', ', $kontrol['alanlar']).') gozle karsilastirilmali')
            : $this->sonuc($kontrol, self::FARK, $farklar);
    }

    private function kok(mixed $govde, string $yol): mixed
    {
        if ($yol === '$') {
            return $govde;
        }

        return is_array($govde) ? ($govde[$yol] ?? null) : null;
    }

    /** @throws EksikDeger */
    private function doldur(mixed $deger): mixed
    {
        if (is_array($deger)) {
            $sonuc = [];
            foreach ($deger as $k => $v) {
                if (is_string($v) && preg_match('/^\{(\w+)~\}$/', $v, $m)) {
                    // Ekran bos degeri hic gondermiyor: alan istekten duser.
                    if ((string) ($this->degerler[$m[1]] ?? '') === '') {
                        continue;
                    }
                    $v = '{'.$m[1].'}';
                }
                $sonuc[$k] = $this->doldur($v);
            }

            return $sonuc;
        }
        if (! is_string($deger)) {
            return $deger;
        }

        return preg_replace_callback('/\{([\w|]+)(\?)?\}/', function (array $m) {
            foreach (explode('|', $m[1]) as $ad) {
                $v = (string) ($this->degerler[$ad] ?? '');
                if ($v !== '') {
                    return $v;
                }
            }
            if (($m[2] ?? '') === '?') {
                return '';
            }

            throw new EksikDeger($m[1]);
        }, $deger);
    }

    private function tokensiz(string $metin): string
    {
        return str_replace(array_values($this->tokenlar), '***', $metin);
    }

    /**
     * @param  array<string,mixed>  $kontrol
     * @param  array<int,string>  $farklar
     * @return array{ad:string,durum:string,farklar:array<int,string>,neden:?string}
     */
    private function sonuc(array $kontrol, string $durum, array $farklar, ?string $neden = null): array
    {
        return ['ad' => $kontrol['ad'], 'durum' => $durum, 'farklar' => $farklar, 'neden' => $neden];
    }
}
