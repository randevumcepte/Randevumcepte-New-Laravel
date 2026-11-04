<?php

namespace App\Services\CagriMerkezi;

use App\Domain\CagriMerkezi\AramaSonucu;
use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Domain\CagriMerkezi\SantralKaydi;
use App\Support\KvkkMaske;
use App\Support\ListeIstegi;
use Illuminate\Support\Facades\DB;

/**
 * Çalışma Ekranı müşteri KUYRUĞU — eski arama_liste_detay_getir karşılığı (web + Flutter tek uç).
 *
 * Alan adları Flutter AranacakMusteri.fromJson ile BİREBİR (telefonGizlenmis dahil). KVKK:
 * maskeli kullanıcıya ad kısaltılır, telefon maskelenir, `telefon` (ham) null gelir ve telefonla
 * arama yapılamaz. Durum grubu sayaçları (çipler) SUNUCUDA, aramadan sonra, tüm küme üzerinden.
 */
class KuyrukServisi
{
    public function __construct(private CagriMerkeziErisimServisi $erisim) {}

    /**
     * @param array{page:int, per_page:int, ara:?string} $l
     */
    public function listele(CagriMerkeziErisim $e, int $listeId, ?string $grup, array $l): array
    {
        $liste = $this->erisim->liste($listeId, $e);
        $maske = $e->maskele();

        $taban = AramaKaydiSorgusu::kuyrukta(
            DB::table('aranacak_musteriler as am')->leftJoin('users as u', 'u.id', '=', 'am.user_id')->where('am.arama_id', $listeId), 'am');
        if ($desen = ListeIstegi::like($l['ara'] ?? null)) {
            $rakam = preg_replace('/\D/', '', (string) $l['ara']);
            $taban->where(function ($w) use ($desen, $rakam, $maske) {
                $w->where('u.name', 'like', $desen);
                if (! $maske && strlen($rakam) >= 3) {
                    $w->orWhere('u.cep_telefon', 'like', '%'.$rakam.'%');
                }
            });
        }

        $sayim = (clone $taban)->select('am.durum', DB::raw('COUNT(*) as adet'))->groupBy('am.durum')->pluck('adet', 'durum')->all();
        $gruplar = $this->gruplar($sayim);

        $q = clone $taban;
        $secili = collect(AramaSonucu::kuyrukGruplari())->firstWhere('kod', $grup);
        if ($secili && $secili['sonuclar']) {
            AramaKaydiSorgusu::sonucKodlari($q, $secili['sonuclar'], 'am');
        }
        $toplam = (clone $q)->count();
        $satirlar = $q->orderBy('am.id')
            ->forPage($l['page'], $l['per_page'])
            ->get(['am.id', 'am.user_id', 'am.durum', 'am.randevu_olusturuldu', 'am.musteri_not', 'am.tarih', 'am.saat', 'am.ses_kaydi', 'u.name', 'u.cep_telefon']);

        $host = (string) config('cagri_merkezi.ses_host');

        return [
            'liste'   => ['id' => (int) $liste->id, 'baslik' => (string) $liste->arama_baslik],
            'gruplar' => $gruplar,
            'data'    => $satirlar->map(fn ($s) => $this->satir($s, $listeId, $maske, $host, $e->yonetici))->all(),
        ] + ListeIstegi::meta($l['page'], $l['per_page'], $toplam);
    }

    /** Tek kayıt (çalışma ekranına "müşteriye git" / takvimden açılış) — aynı satır biçimi. */
    public function tekil(CagriMerkeziErisim $e, int $aranacakId): array
    {
        $k = $this->erisim->kayit($aranacakId, $e);
        $u = DB::table('users')->where('id', $k->user_id)->first(['name', 'cep_telefon']);
        $k->name = $u->name ?? '';
        $k->cep_telefon = $u->cep_telefon ?? '';

        return $this->satir($k, (int) $k->arama_id, $e->maskele(), (string) config('cagri_merkezi.ses_host'), $e->yonetici)
            + ['liste_baslik' => (string) $k->liste_baslik];
    }

    /**
     * Dönüşüm formları (ön görüşme / randevu) için müşteri kimliği: AD TAM (v2 müşteri aramasıyla
     * aynı politika — eski cagri_musteri_ongorusme_bilgi de maskeyi bu an için kaldırıyordu),
     * telefon yalnız 'musteri.telefon_gor' ile açık; maskeli telefon kaydedilen numarayı ezmez.
     */
    public function musteriBilgisi(CagriMerkeziErisim $e, int $aranacakId): array
    {
        $k = $this->erisim->kayit($aranacakId, $e);
        $u = DB::table('users')->where('id', $k->user_id)->first(['id', 'name', 'cep_telefon']);

        return [
            'id'      => (int) $k->user_id,
            'ad'      => (string) ($u->name ?? ''),
            'telefon' => $e->telefonGor ? (string) ($u->cep_telefon ?? '') : KvkkMaske::telefon($u->cep_telefon ?? ''),
        ];
    }

    /** $sesGor: satirdaki son kayit baska personele ait olabilir; personel sesi gecmisten (kendi dahilisi) gorur. */
    private function satir(object $s, int $listeId, bool $maske, string $host, bool $sesGor): array
    {
        $tel = (string) ($s->cep_telefon ?? '');
        $kod = $s->durum !== null ? (int) $s->durum : null;

        return [
            'aramaListesi'        => $listeId,
            'aranacak_musteri_id' => (int) $s->id,
            'user_id'             => (int) $s->user_id,
            'ad'                  => $maske ? KvkkMaske::adSoyad($s->name) : (string) $s->name,
            'telefon'             => $maske ? null : $tel,                       // ham numara maskeli istemciye ASLA
            'telefonGizlenmis'    => $maske ? KvkkMaske::telefon($tel) : $tel,
            'durum'               => AramaSonucu::metin($kod),
            'durum_kod'           => $kod,
            'renk'                => AramaSonucu::renkKodu($kod),
            'randevu_olusturuldu' => (int) ($s->randevu_olusturuldu ?? 0),
            'not'                 => (string) ($s->musteri_not ?? ''),
            'not_tarih'           => (string) ($s->tarih ?? ''),
            'not_saat'            => $s->saat ? substr((string) $s->saat, 0, 5) : '',
            'ses'                 => $sesGor ? SantralKaydi::url($s->ses_kaydi ?? null, $host) : '',
        ];
    }

    /** Durum sayımından çip sayaçları (0 olan grup gelmez; 'hepsi' daima). */
    private function gruplar(array $sayim): array
    {
        $toplam = array_sum($sayim);
        $out = [];
        foreach (AramaSonucu::kuyrukGruplari() as $g) {
            $adet = $g['sonuclar'] === [] ? $toplam : array_sum(array_map(
                fn ($kod) => (int) ($kod === null ? ($sayim[''] ?? 0) : ($sayim[$kod] ?? 0)), $g['sonuclar']));
            if ($g['kod'] === 'hepsi' || $adet > 0) {
                $out[] = ['kod' => $g['kod'], 'ad' => $g['ad'], 'adet' => $adet];
            }
        }

        return $out;
    }
}
