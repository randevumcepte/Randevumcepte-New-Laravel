<?php

namespace App\Domain\CagriMerkezi;

use App\Domain\Santral\CdrSiniflandirici;

/**
 * Çağrı merkezinin santral CDR satırı yorumları — SAF (DB/HTTP yok).
 *
 * Santral raporu (Domain\Santral\CdrSiniflandirici) çağrı merkezi originate'lerini ('cmerkezi')
 * BİLEREK dışarıda bırakır; çağrı merkezi ekranları ise tam olarak o çağrıları ister. Bu yüzden
 * eski arama_cagri_kayitlari / cagriMerkeziKayitAdaylari kuralları burada ayrı tutulur:
 *   - cozumle()        : Çağrı Kayıtları sekmesi satırı (dış numara + yön; dcontext kuralı)
 *   - musteriyeAitMi() : bir müşterinin numarası satırın herhangi bir bacağında geçiyor mu (LENIENT)
 *   - kayitZamani()    : ses dosyası adındaki -YYYYMMDD-HHMMSS- (santral raporuyla aynı saat, TZ'siz)
 *   - eslestir()       : görüşme notlarına ses kaydı ataması (en yeni not ↔ en yeni kayıt, tekrarsız)
 */
final class SantralKaydi
{
    /** Ses kaydı yolu → oynatılabilir URL (tam URL ise olduğu gibi). */
    public static function url(?string $yol, string $host): string
    {
        $yol = trim((string) $yol);
        if ($yol === '') {
            return '';
        }
        if (stripos($yol, 'http') === 0) {
            return $yol;
        }

        return rtrim($host, '/').(str_starts_with($yol, '/') ? '' : '/').$yol;
    }

    /** Müşteri numarası (son 10) satırın kanal/numara alanlarından birinde geçiyor mu? */
    public static function musteriyeAitMi(array $r, string $son10): bool
    {
        if ($son10 === '') {
            return false;
        }
        $samanlik = preg_replace('/\D/', '', ($r['channel'] ?? '').'|'.($r['dstchannel'] ?? '').'|'.($r['src'] ?? '').'|'.($r['dst'] ?? ''));

        return str_contains((string) $samanlik, $son10);
    }

    /** Kayıt zamanı YYYYMMDDHHMMSS (önce dosya adından, yoksa calldate). 0 = bilinmiyor. */
    public static function kayitZamani(string $yol, ?string $calldate): int
    {
        if (preg_match('/-(\d{8})-(\d{6})/', $yol, $m)) {
            return (int) ($m[1].$m[2]);
        }
        $ts = $calldate ? strtotime($calldate) : false;

        return $ts ? (int) date('YmdHis', $ts) : 0;
    }

    /** YYYYMMDDHHMMSS → unix ts (0 → 0). */
    public static function tsDen(int $ymdHis): int
    {
        if ($ymdHis <= 0) {
            return 0;
        }
        $d = \DateTime::createFromFormat('YmdHis', (string) $ymdHis);

        return $d ? $d->getTimestamp() : 0;
    }

    /**
     * Bir müşterinin ses kaydı ADAYLARI (en yeni önce, tekrarsız).
     *
     * @param array<int,array> $satirlar santral CDR satırları
     * @return array<int, array{path:string, ts:int}>
     */
    public static function adaylar(array $satirlar, string $son10): array
    {
        $out = [];
        foreach ($satirlar as $r) {
            $yol = is_array($r) ? trim((string) ($r['recording_path'] ?? '')) : '';
            if ($yol === '' || isset($out[$yol]) || ! self::musteriyeAitMi($r, $son10)) {
                continue;
            }
            $out[$yol] = ['path' => $yol, 'ts' => self::kayitZamani($yol, $r['calldate'] ?? null)];
        }
        $out = array_values($out);
        usort($out, fn ($a, $b) => $b['ts'] <=> $a['ts']);

        return $out;
    }

    /**
     * Görüşme notlarına ses kaydı ataması (eski cagri_musteri_gecmisi algoritması).
     *  - Eşik (son 2 gün) öncesi notlar yeniden atanmaz; mevcut kayıtları rezerve edilir.
     *  - Eşik sonrası notlar en yeniden eskiye, kullanılmamış en yeni adayı alır; aday yoksa
     *    önceki (yanlış/eski) kayıt TEMİZLENİR.
     *
     * @param array<int, array{id:int, ts:int, ses:?string}> $notlar en yeni önce
     * @param array<int, array{path:string, ts:int}> $adaylar en yeni önce
     * @return array{degisen: array<int, ?string>, kullanilmayan: array<int, array{path:string, ts:int}>}
     */
    public static function eslestir(array $notlar, array $adaylar, int $esikTs): array
    {
        $kullanilan = [];
        foreach ($notlar as $n) {
            if ($n['ts'] < $esikTs && ! empty($n['ses'])) {
                $kullanilan[$n['ses']] = true;
            }
        }
        $degisen = [];
        foreach ($notlar as $n) {
            if ($n['ts'] < $esikTs) {
                continue;
            }
            $sec = null;
            foreach ($adaylar as $c) {
                if (! isset($kullanilan[$c['path']])) {
                    $sec = $c['path'];
                    break;
                }
            }
            if ($sec !== null) {
                $kullanilan[$sec] = true;
            }
            if (($n['ses'] ?: null) !== $sec) {
                $degisen[$n['id']] = $sec;
            }
        }

        return [
            'degisen'       => $degisen,
            'kullanilmayan' => array_values(array_filter($adaylar, fn ($c) => ! isset($kullanilan[$c['path']]))),
        ];
    }

    /**
     * Çağrı Kayıtları satırı: dış numara + yön (eski arama_cagri_kayitlari). Dahili-dahili ve dış
     * numarası çözülemeyen satırlar null.
     *
     * @param array<int,string> $dahiliSet salonun (personelde: kendi) dahilileri
     * @return array{son10:string, yon:string, ts:int, ses:string, sure_sn:int}|null
     */
    public static function cozumle(array $r, array $dahiliSet, string $trunk10): ?array
    {
        $src = (string) preg_replace('/\D/', '', (string) ($r['src'] ?? ''));
        $dst = (string) preg_replace('/\D/', '', (string) ($r['dst'] ?? ''));
        $ic = fn (string $n) => $n === '' || strlen($n) <= 5 || in_array($n, $dahiliSet, true)
            || ($trunk10 !== '' && substr($n, -10) === $trunk10);

        $giden = (string) ($r['dcontext'] ?? '') === 'from-internal';
        $dis = '';
        if ($giden) {
            if (! $ic($dst) && strlen($dst) >= 10) {
                $dis = $dst;
            }
        } elseif (! $ic($src) && strlen($src) >= 10) {
            $dis = $src;
        } elseif (! $ic($dst) && strlen($dst) >= 10) {
            $dis = $dst;
        }
        if ($dis === '') {
            // Son çare: kanaldan son 10 hane (originate'te müşteri numarası kanal adında).
            $saman = preg_replace('/\D/', '', ($r['channel'] ?? '').'|'.($r['dstchannel'] ?? ''));
            if (preg_match('/(\d{10})(?!.*\d)/', (string) $saman, $m)) {
                $dis = $m[1];
            }
        }
        if ($dis === '') {
            return null;
        }

        return [
            'son10'   => CdrSiniflandirici::son10($dis) ?: substr($dis, -10),
            'yon'     => $giden ? 'giden' : 'gelen',
            'ts'      => ! empty($r['calldate']) ? (int) strtotime((string) $r['calldate']) : 0,
            'ses'     => trim((string) ($r['recording_path'] ?? '')),
            'sure_sn' => (int) ($r['billsec'] ?? $r['duration'] ?? 0),
        ];
    }
}
