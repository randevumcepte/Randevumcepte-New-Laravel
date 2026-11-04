<?php

namespace App\Services\CagriMerkezi;

use App\Domain\CagriMerkezi\AramaSonucu;
use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Domain\CagriMerkezi\SantralKaydi;
use App\Domain\Santral\CdrSiniflandirici;
use App\Models\Personeller;
use App\Services\Santral\SantralErisimServisi;
use App\Services\Santral\SantralIstemcisi;
use Illuminate\Support\Facades\DB;

/**
 * Bir aranacak müşterinin ÇAĞRI GEÇMİŞİ & SES KAYITLARI — eski cagri_musteri_gecmisi karşılığı.
 *
 * Her görüşme notuna KENDİ ses kaydı bağlanır (santral CDR gecikmesine dayanıklı): adaylar bu
 * müşterinin numarasına göre (son 10 hane, herhangi bir bacakta) salonun tüm dahilileri üzerinden
 * çekilir; eşleme saf SantralKaydi::eslestir ile. Nota bağlanmamış kayıtlar "Arandı" satırı olarak
 * listelenir (not kaydedilmeden yapılan aramalar da görünür). Santral erişilemezse mevcut
 * kayıtlar KORUNUR (eşleme yapılmaz) ve liste yine döner.
 *
 * SES GÖRÜNÜRLÜĞÜ: eşleme salonun tamamıyla yapılır ama personel (rol 5) yalnız KENDİ dahilisinin
 * kayıtlarını görür; santral erişilemezse kaydın kimin olduğu bilinemeyeceği için personele ses dönmez.
 */
class GorusmeGecmisiServisi
{
    public function __construct(
        private CagriMerkeziErisimServisi $erisim,
        private SantralIstemcisi $santral,
    ) {}

    public function getir(CagriMerkeziErisim $e, int $aranacakId): array
    {
        $k = $this->erisim->kayit($aranacakId, $e);
        $notlar = DB::table('gorusme_notlari')->where('aranacak_musteri_id', $aranacakId)->orderByDesc('id')->limit(50)
            ->get(['id', 'sonuc', 'not', 'ses_kaydi', 'satis_tutari', 'created_at']);
        $son10 = CdrSiniflandirici::son10((string) DB::table('users')->where('id', $k->user_id)->value('cep_telefon'));

        $gorunen = [];
        $santralHata = false;
        if ($son10 !== '') {
            [$adaylar, $santralHata] = $this->adaylar($e->salonId, $son10);
            if (! $santralHata) {
                $this->sesleriEsle($k, $notlar, $adaylar);
            }
            $gorunen = $adaylar;
            if (! $e->yonetici) {
                [$gorunen, $kendiHata] = $this->adaylar($e->salonId, $son10, null, $e->dahililer);
                $santralHata = $santralHata || $kendiHata;
            }
        }
        $gorunurYol = array_flip(array_column($gorunen, 'path'));
        $kullanilan = array_flip(array_filter($notlar->pluck('ses_kaydi')->all()));
        $host = (string) config('cagri_merkezi.ses_host');
        $rTarih = $k->tarih ? date('d.m.Y', strtotime($k->tarih)) : '';
        $rSaat = $k->saat ? substr((string) $k->saat, 0, 5) : '';

        $satirlar = $notlar->map(function ($n) use ($e, $gorunurYol, $host, $rTarih, $rSaat) {
            $kod = $n->sonuc !== null ? (int) $n->sonuc : null;
            $zamanli = in_array($kod, [AramaSonucu::TEKRAR_ARANACAK->value, AramaSonucu::ON_GORUSME->value], true);

            return [
                '_ts'           => $n->created_at ? strtotime($n->created_at) : 0,
                'tarih'         => $n->created_at ? date('d.m.Y H:i', strtotime($n->created_at)) : '',
                'sonuc_kod'     => $kod,
                'sonuc'         => AramaSonucu::metin($kod),
                'renk'          => AramaSonucu::renkKodu($kod),
                'not'           => (string) ($n->not ?? ''),
                'ses'           => $e->yonetici || isset($gorunurYol[(string) $n->ses_kaydi]) ? SantralKaydi::url($n->ses_kaydi, $host) : '',
                'randevu_tarih' => $zamanli ? $rTarih : '',
                'randevu_saat'  => $zamanli ? $rSaat : '',
                'satis_tutari'  => $kod === AramaSonucu::TELEFONDA_SATIS->value && $n->satis_tutari !== null ? (float) $n->satis_tutari : null,
            ];
        })->all();

        foreach ($gorunen as $c) {
            if (isset($kullanilan[$c['path']])) {
                continue;
            }
            $ts = SantralKaydi::tsDen($c['ts']);
            $satirlar[] = [
                '_ts' => $ts, 'tarih' => $ts ? date('d.m.Y H:i', $ts) : '', 'sonuc_kod' => null, 'sonuc' => AramaSonucu::ARANDI->etiket(),
                'renk' => AramaSonucu::ARANDI->renk(), 'not' => '', 'ses' => SantralKaydi::url($c['path'], $host),
                'randevu_tarih' => '', 'randevu_saat' => '', 'satis_tutari' => null,
            ];
        }
        usort($satirlar, fn ($a, $b) => $b['_ts'] <=> $a['_ts']);

        return [
            'gecmis'       => array_map(fn ($s) => array_diff_key($s, ['_ts' => 1]), $satirlar),
            'santral_hata' => $santralHata,
        ];
    }

    /**
     * Müşteriyle yapılan TÜM görüşme ses kayıtları (santral CDR, son N gün; eski musteri_ses_kayitlari).
     * Not eşlemesi yapmaz — salt okuma. Personel yalnız kendi dahilisinin kayıtlarını görür.
     */
    public function tumSesler(CagriMerkeziErisim $e, int $aranacakId): array
    {
        $k = $this->erisim->kayit($aranacakId, $e);
        $son10 = CdrSiniflandirici::son10((string) DB::table('users')->where('id', $k->user_id)->value('cep_telefon'));
        if ($son10 === '') {
            return ['kayitlar' => [], 'santral_hata' => false];
        }
        [$adaylar, $hata] = $this->adaylar($e->salonId, $son10, (int) config('cagri_merkezi.tum_ses_gun'), $e->yonetici ? null : $e->dahililer);
        $host = (string) config('cagri_merkezi.cdr_ses_host');

        return [
            'kayitlar' => array_map(function ($c) use ($host) {
                $ts = SantralKaydi::tsDen($c['ts']);

                return ['tarih' => $ts ? date('d.m.Y H:i', $ts) : '', 'url' => SantralKaydi::url($c['path'], $host)];
            }, $adaylar),
            'santral_hata' => $hata,
        ];
    }

    /**
     * @param  array<int,string>|null  $dahililer  null → salonun tüm dahilileri
     * @return array{0: array<int, array{path:string, ts:int}>, 1: bool} [adaylar, santral hatası]
     */
    private function adaylar(int $salonId, string $son10, ?int $gun = null, ?array $dahililer = null): array
    {
        $dahililer ??= SantralErisimServisi::dahililer(
            Personeller::where('salon_id', $salonId)->where(fn ($q) => $q->whereNotNull('dahili_no')->orWhereNotNull('dahili_no_webrtc'))
                ->get(['dahili_no', 'dahili_no_webrtc']));
        if (! $dahililer) {
            return [[], false];
        }
        $trunk = (string) DB::table('sabit_numaralar')->where('salon_id', $salonId)->value('numara');
        $cdr = $this->santral->cdrListesi($dahililer, $trunk ?: null,
            now()->subDays($gun ?? (int) config('cagri_merkezi.gecmis_ses_gun'))->toDateString(), now()->addDay()->toDateString(),
            (int) config('santral.cdr_tavan', 500));

        return [SantralKaydi::adaylar($cdr['satirlar'], $son10), $cdr['hata']];
    }

    /** Eşleşmeyi uygula (yalnız değişen notlar yazılır) + aranacak kaydına en son görüşmenin kaydı. */
    private function sesleriEsle(object $k, $notlar, array $adaylar): void
    {
        $sonuc = SantralKaydi::eslestir(
            $notlar->map(fn ($n) => ['id' => (int) $n->id, 'ts' => $n->created_at ? strtotime($n->created_at) : 0, 'ses' => $n->ses_kaydi])->all(),
            $adaylar,
            now()->subDays((int) config('cagri_merkezi.gecmis_ses_esik_gun'))->getTimestamp(),
        );
        foreach ($sonuc['degisen'] as $notId => $ses) {
            DB::table('gorusme_notlari')->where('id', $notId)->update(['ses_kaydi' => $ses]);
            $notlar->firstWhere('id', $notId)->ses_kaydi = $ses;
        }
        $enSon = $notlar->first()?->ses_kaydi;
        if ($enSon && $enSon !== $k->ses_kaydi) {
            DB::table('aranacak_musteriler')->where('id', $k->id)->update(['ses_kaydi' => $enSon]);
        }
    }
}
