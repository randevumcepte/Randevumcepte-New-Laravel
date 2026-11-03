<?php

namespace App\Services\CagriMerkezi;

use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Domain\CagriMerkezi\SantralKaydi;
use App\Domain\Santral\CdrSiniflandirici;
use App\Models\Personeller;
use App\Services\Santral\SantralErisimServisi;
use App\Services\Santral\SantralIstemcisi;
use App\Support\KvkkMaske;
use App\Support\ListeIstegi;
use Illuminate\Support\Facades\DB;

/**
 * ÇAĞRI KAYITLARI sekmesi — santralin CANLI CDR'ından gerçek çağrı geçmişi (gelen + giden, ses
 * kayıtlarıyla). Eski arama_cagri_kayitlari karşılığı; santral raporundan farkı: çağrı merkezi
 * originate'leri DAHİL (Domain\CagriMerkezi\SantralKaydi::cozumle) ve numara bu salonun arama
 * listelerindeki müşteriyle eşleşirse ad + son görüşme notu eklenir.
 *
 * Personel yalnız kendi dahilisinin (normal + WebRTC) çağrılarını görür; KVKK maskesi uygulanır
 * (maskeli kullanıcıda telefonla arama kapalı). Sayfalama/arama ListeIstegi sözleşmesiyle.
 */
class CagriKayitlariServisi
{
    public function __construct(private SantralIstemcisi $santral) {}

    /**
     * @param array{tarih1:?string, tarih2:?string, yon:?string, page:int, per_page:int, ara:?string} $o
     */
    public function listele(CagriMerkeziErisim $e, array $o): array
    {
        $tarih1 = $o['tarih1'] ?: now()->subDays((int) config('cagri_merkezi.kayitlar_gun'))->toDateString();
        $tarih2 = $o['tarih2'] ?: now()->toDateString();
        $dahililer = $e->yonetici
            ? SantralErisimServisi::dahililer(Personeller::where('salon_id', $e->salonId)
                ->where(fn ($q) => $q->whereNotNull('dahili_no')->orWhereNotNull('dahili_no_webrtc'))->get(['dahili_no', 'dahili_no_webrtc']))
            : $e->dahililer;
        $bos = ['data' => [], 'ozet' => ['toplam' => 0, 'kayitli' => 0, 'gelen' => 0, 'giden' => 0], 'tarih1' => $tarih1, 'tarih2' => $tarih2, 'hata' => false, 'kesildi' => false];
        if (! $dahililer) {
            return $bos + ListeIstegi::meta($o['page'], $o['per_page'], 0);
        }

        $trunk = (string) DB::table('sabit_numaralar')->where('salon_id', $e->salonId)->value('numara');
        $tavan = (int) config('santral.cdr_tavan', 500);
        $cdr = $this->santral->cdrListesi($dahililer, $trunk ?: null, $tarih1, date('Y-m-d', strtotime($tarih2.' +1 day')), $tavan);

        $satirlar = [];
        $gorulen = [];
        foreach ($cdr['satirlar'] as $r) {
            $c = SantralKaydi::cozumle($r, $dahililer, CdrSiniflandirici::son10($trunk));
            if ($c === null || isset($gorulen[$c['son10'].'|'.$c['ts']])) {
                continue;
            }
            $gorulen[$c['son10'].'|'.$c['ts']] = true;
            $satirlar[] = $c;
        }
        usort($satirlar, fn ($a, $b) => $b['ts'] <=> $a['ts']);
        $satirlar = array_slice($satirlar, 0, (int) config('cagri_merkezi.kayitlar_tavan'));

        [$musteriler, $notlar] = $this->musteriler($e->salonId, array_unique(array_column($satirlar, 'son10')));
        $host = (string) config('cagri_merkezi.cdr_ses_host');
        $maske = $e->maskele();
        $satirlar = array_map(function ($c) use ($musteriler, $notlar, $host, $maske) {
            $m = $musteriler[$c['son10']] ?? null;
            $ad = $m ? (string) $m['ad'] : '';

            return [
                'zaman'      => $c['ts'] ? date('Y-m-d H:i:s', $c['ts']) : '',
                'tarih'      => $c['ts'] ? date('d.m.Y H:i', $c['ts']) : '',
                'yon'        => $c['yon'],
                'ad'         => $maske ? KvkkMaske::adSoyad($ad) : $ad,
                'kayitli'    => $m !== null,
                'user_id'    => $m['uid'] ?? null,
                'telefon'    => $maske ? KvkkMaske::telefon($c['son10']) : '0'.$c['son10'],
                'sure_sn'    => $c['sure_sn'],
                'not'        => $m ? ($notlar[$m['uid']] ?? '') : '',
                'ses'        => SantralKaydi::url($c['ses'], $host),
                '_ara'       => mb_strtolower($ad.' '.($maske ? '' : $c['son10'])),
            ];
        }, $satirlar);

        $ozet = ['toplam' => count($satirlar), 'kayitli' => count(array_filter($satirlar, fn ($s) => $s['ses'] !== '')),
            'gelen' => count(array_filter($satirlar, fn ($s) => $s['yon'] === 'gelen')), 'giden' => count(array_filter($satirlar, fn ($s) => $s['yon'] === 'giden'))];

        if (in_array($o['yon'] ?? null, ['gelen', 'giden'], true)) {
            $satirlar = array_values(array_filter($satirlar, fn ($s) => $s['yon'] === $o['yon']));
        }
        if (($ara = mb_strtolower(trim((string) ($o['ara'] ?? '')))) !== '') {
            $rakam = preg_replace('/\D/', '', $ara);
            $satirlar = array_values(array_filter($satirlar, fn ($s) => str_contains($s['_ara'], $ara) || ($rakam !== '' && str_contains($s['_ara'], $rakam))));
        }
        $toplam = count($satirlar);
        $sayfa = array_map(fn ($s) => array_diff_key($s, ['_ara' => 1]), array_slice($satirlar, ($o['page'] - 1) * $o['per_page'], $o['per_page']));

        return ['data' => $sayfa, 'ozet' => $ozet, 'tarih1' => $tarih1, 'tarih2' => $tarih2, 'hata' => $cdr['hata'],
            'kesildi' => count($cdr['satirlar']) >= $tavan] + ListeIstegi::meta($o['page'], $o['per_page'], $toplam);
    }

    /**
     * son10 → {ad, uid} (bu salonun arama listelerindeki müşteriler) + uid → son görüşme notu.
     *
     * @return array{0: array<string, array{ad:string, uid:int}>, 1: array<int,string>}
     */
    private function musteriler(int $salonId, array $son10lar): array
    {
        if (! $son10lar) {
            return [[], []];
        }
        $harita = [];
        DB::table('aranacak_musteriler as am')->join('arama_listesi as al', 'al.id', '=', 'am.arama_id')->join('users as u', 'u.id', '=', 'am.user_id')
            ->where('al.salon_id', $salonId)->whereNotNull('u.cep_telefon')
            ->select('u.id', 'u.name', 'u.cep_telefon')->distinct()->orderBy('u.id')
            ->chunk(2000, function ($rows) use (&$harita, $son10lar) {
                $istenen = array_flip($son10lar);
                foreach ($rows as $m) {
                    $k = CdrSiniflandirici::son10((string) $m->cep_telefon);
                    if ($k !== '' && isset($istenen[$k])) {
                        $harita[$k] = ['ad' => (string) $m->name, 'uid' => (int) $m->id];
                    }
                }
            });
        $notlar = [];
        if ($harita) {
            foreach (DB::table('gorusme_notlari')->where('salon_id', $salonId)->whereIn('user_id', array_column($harita, 'uid'))
                ->whereNotNull('not')->where('not', '!=', '')->orderByDesc('id')->get(['user_id', 'not']) as $g) {
                $notlar[(int) $g->user_id] ??= (string) $g->not;
            }
        }

        return [$harita, $notlar];
    }
}
