<?php

namespace App\Services\Santral;

use Illuminate\Support\Facades\Log;

/**
 * Santral AMI (Asterisk Manager) istemcisi — click-to-call ORIGINATE'in TEK dış I/O noktası.
 * İş kuralı içermez (kimin hangi numarayı arayabileceği AramaBaslatmaServisi'nde).
 *
 * Agent-first akış (eski cagriMerkeziOriginate ile birebir):
 *   Channel Local/<dahili>@from-internal  → önce personelin dahilisi (Bria) çalar
 *   Application Dial SIP/<trunk>-out/0<no> → açınca müşteri salonun outbound peer'inden aranır
 *   CallerID = salon numarası (müşteri numarası cihaza/tarayıcıya GİTMEZ — KVKK)
 *   Account = 'cmerkezi' (CDR etiketi; santral raporu bu çağrıları ayıklar)
 */
class SantralAmiIstemcisi
{
    /** @return array{basarili:bool, mesaj:string} */
    public function originate(string $dahili, string $trunk, string $tel10): array
    {
        $c = (array) config('cagri_merkezi.ami');
        if (empty($c['host']) || empty($c['kullanici']) || empty($c['secret'])) {
            Log::warning('[CAGRI-MERKEZI v2] AMI ayarlari eksik (SANTRAL_AMI_HOST / KULLANICI / SECRET)');

            return ['basarili' => false, 'mesaj' => 'Santral arama bağlantısı yapılandırılmamış. Sistem yöneticisine bildirin.'];
        }

        $errno = 0;
        $errstr = '';
        $soket = @stream_socket_client('tcp://'.$c['host'].':'.$c['port'], $errno, $errstr, (int) $c['zaman_asimi']);
        if (! $soket) {
            Log::warning('[CAGRI-MERKEZI v2] AMI baglanti hatasi', ['errno' => $errno, 'hata' => $errstr]);

            return ['basarili' => false, 'mesaj' => 'Santrale bağlanılamadı.'];
        }
        stream_set_timeout($soket, (int) $c['zaman_asimi']);

        try {
            $this->gonder($soket, ['Action' => 'Login', 'Username' => $c['kullanici'], 'Secret' => $c['secret'], 'Events' => 'off']);
            if (! str_contains($this->oku($soket, 300000), 'Success')) {
                return ['basarili' => false, 'mesaj' => 'Santral kimlik doğrulaması başarısız.'];
            }
            $this->gonder($soket, [
                'Action'      => 'Originate',
                'Channel'     => 'Local/'.$dahili.'@from-internal',
                'Callerid'    => $trunk,
                'Account'     => (string) $c['hesap'],
                'Application' => 'Dial',
                'Data'        => 'SIP/'.$trunk.'-out/0'.$tel10.','.(int) $c['cevap_sn'],
                'Async'       => 'yes',
            ]);
            $cevap = $this->oku($soket, 400000);
            $this->gonder($soket, ['Action' => 'Logoff']);
        } finally {
            fclose($soket);
        }

        Log::info('[CAGRI-MERKEZI v2] Originate', ['dahili' => $dahili, 'trunk' => $trunk, 'yanit' => trim(preg_replace('/\s+/', ' ', $cevap))]);

        return str_contains($cevap, 'Success')
            ? ['basarili' => true, 'mesaj' => 'Telefonunuz (Bria) çalıyor — açın; ardından müşteri aranacak ve çalma sesini duyacaksınız.']
            : ['basarili' => false, 'mesaj' => 'Santral aramayı başlatamadı.'];
    }

    /** @param resource $soket */
    private function gonder($soket, array $alanlar): void
    {
        $satirlar = '';
        foreach ($alanlar as $k => $v) {
            $satirlar .= $k.': '.str_replace(["\r", "\n"], '', (string) $v)."\r\n";
        }
        fwrite($soket, $satirlar."\r\n");
    }

    /** @param resource $soket */
    private function oku($soket, int $bekleUs): string
    {
        usleep($bekleUs);

        return (string) fread($soket, 8192);
    }
}
