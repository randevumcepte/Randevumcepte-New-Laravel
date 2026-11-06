<?php

namespace App\Services\SistemYonetim\Izleme;

/** Guvenlik duvari gosterim kurallari (DB'siz): watchdog durumu ve olay satiri. */
final class GuvenlikSunumu
{
    /** Watchdog her dakika heartbeat yazar; bu sureyi asan sessizlik durmus sayilir. */
    private const CANLI_SINIR_SN = 180;

    private const TUR = [
        'flood'       => ['Flood', 'danger'],
        'ssh_brute'   => ['SSH Brute', 'warning'],
        'load_yuksek' => ['Yüksek Yük', 'warning'],
        'cpu_yuksek'  => ['CPU Tavan', 'warning'],
        'http_502'    => ['Site Hatası (502)', 'danger'],
    ];

    private const AKSIYON = ['engellendi' => ['Engellendi', 'danger'], 'uyari' => ['Uyarı', 'warning']];

    /** @return array{durum:string,etiket:string,canli:bool,kuruldu:bool,son:?string,saniye:?int,zaman:string,aktif_ban:?int} */
    public static function watchdog(?string $sonHeartbeat, ?string $aktifBan, ?int $saniye): array
    {
        $kuruldu = $saniye !== null;
        $canli = $kuruldu && $saniye <= self::CANLI_SINIR_SN;
        [$durum, $etiket] = $canli ? ['calisiyor', 'ÇALIŞIYOR'] : ($kuruldu ? ['durmus', 'DURMUŞ'] : ['kurulmamis', 'KURULMAMIŞ']);

        return [
            'durum'     => $durum,
            'etiket'    => $etiket,
            'canli'     => $canli,
            'kuruldu'   => $kuruldu,
            'son'       => $sonHeartbeat,
            'saniye'    => $saniye,
            'zaman'     => self::heartbeatMetni($saniye),
            'aktif_ban' => $aktifBan !== null ? (int) $aktifBan : null,
        ];
    }

    public static function olay(object $o): array
    {
        return [
            'id'           => (int) $o->id,
            'ip'           => $o->ip,
            'tur'          => $o->tur,
            'tur_etiket'   => self::TUR[$o->tur][0] ?? $o->tur,
            'tur_renk'     => self::TUR[$o->tur][1] ?? 'muted',
            'deger'        => $o->deger !== null ? (int) $o->deger : null,
            'deger_metin'  => self::degerMetni($o->tur, $o->deger),
            'aksiyon'      => $o->aksiyon,
            'aksiyon_etiket' => self::AKSIYON[$o->aksiyon][0] ?? $o->aksiyon,
            'aksiyon_renk' => self::AKSIYON[$o->aksiyon][1] ?? 'muted',
            'detay'        => $o->detay,
            'zaman'        => IzlemeZamani::bicim($o->created_at),
        ];
    }

    /** Yuk degeri DB'de 100 katidir. */
    public static function yuk($deger): string
    {
        return number_format(((float) $deger) / 100, 1);
    }

    private static function heartbeatMetni(?int $sn): string
    {
        return match (true) {
            $sn === null => 'heartbeat yok',
            $sn < 90     => 'son çalışma: az önce',
            $sn < 3600   => 'son çalışma: '.floor($sn / 60).' dk önce',
            $sn < 86400  => 'son çalışma: '.floor($sn / 3600).' sa önce',
            default      => 'son çalışma: '.floor($sn / 86400).' gün önce',
        };
    }

    private static function degerMetni(string $tur, $deger): string
    {
        if ($deger === null) {
            return '—';
        }

        return match ($tur) {
            'flood'       => $deger.' bağ.',
            'ssh_brute'   => $deger.' deneme',
            'load_yuksek' => self::yuk($deger),
            'cpu_yuksek'  => '%'.$deger,
            'http_502'    => 'HTTP '.$deger,
            default       => (string) $deger,
        };
    }
}
