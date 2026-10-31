<?php

namespace App\Services\Randevu;

use App\Domain\Randevu\SeansDurumGecisi;
use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Models\AdisyonPaketSeanslar;
use App\Models\Personeller;
use App\Models\RandevuHizmetler;
use App\Models\Randevular;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Randevu DURUM yazma servisi (Faz 2): geldi / gelmedi / isaret-kaldir / iptal / onayla.
 *
 * Her islem iki modda calisir:
 *   - $yaz=false (DRY-RUN): ne yapilacagini hesaplar ('plan'), HICBIR SEY yazmaz.
 *   - $yaz=true : ayni plani tek transaction'da uygular + aktivite logu yazar.
 *
 * Yuk-tasiyan donus kodlari eski mobil API ile AYNI (Flutter eslemesi drop-in):
 *   geldi   → hatali '0' basarili | '2' dogrulama kodu | '3' KVKK kodu | '4' seans secimi
 *   gelmedi → seansDusmeOnayi=true (paket seansindan dusulsun mu?)
 *
 * Eski monolit referansi: ApiController@randevugeldiisaretle (16060),
 * randevuyagelmedi (16413), randevuiptalet (16480), randevuonayla (21648),
 * randevuGeldiGelmediIsaretiKaldir (30762).
 *
 * Eski davranistan BILINCLI farklar:
 *   - Telafi slotu (APS.geldi=2) gelmedi/kaldir/iptal'de korunur (SeansDurumGecisi).
 *   - Dakika paketi NET hesap (DakikaPaketServisi).
 *   - gelmedi eski kodda yetkisizdi; v2'de randevu.duzenle_iptal (controller).
 *   - iptal yalnizca aktif (durum<2), onayla yalnizca bekleyen (durum=0) randevuda.
 *
 * YAN ETKILER (Faz 2b — outbox): SMS/WA, FCM, uygulama-ici bildirim, dogrulama/
 * KVKK kodu ve seans kullanim push'u v2'de URETILMEZ; 'v2_bildirim_kuyrugu'na
 * DURUM DEGISIKLIGIYLE AYNI TRANSACTION'da yazilir, eski monolitin
 * `v2:bildirim-isle` komutu MEVCUT bildirim koduyla gonderir (kod tekrari yok).
 * Yanittaki 'bildirimler' alani kuyruga yazilan/yazilacak turleri listeler.
 */
class RandevuDurumServisi
{
    public function __construct(
        private DakikaPaketServisi $dakika,
    ) {}

    /**
     * GELDI.
     *
     * @param  array{dogrulama_kodu?:mixed,kvkk_kodu?:mixed,seans_secim_destek?:bool,secilen_seans_idler?:array|null,seans_miktarlari?:array}  $g
     */
    public function geldi(int $randevuId, array $g, ?int $yetkiliId, bool $yaz): array
    {
        $randevu = $this->randevu($randevuId);
        $salonId = (int) $randevu->salon_id;

        // Yetim/hayalet seans: ne gecerli adisyon_paket'e ne adisyon_hizmet'e bagli.
        // (Eski kod bunlari geldi oncesi siler; burada da yalniz YAZMA'da silinir.)
        [$seanslar, $yetimIdler] = $this->gecerliSeanslar($randevuId);
        $seansVar = count($seanslar) > 0;

        // (2) Dogrulama kodu kapisi — paket seansi varsa ve salon ayari (16) aciksa.
        if ($seansVar && $this->smsAyari($salonId, 16)) {
            $kod = $g['dogrulama_kodu'] ?? null;
            if ($randevu->dogrulama === null || (string) $randevu->dogrulama !== (string) $kod) {
                // Eski davranis: her kod istendiginde YENI kod uretilip gonderilir
                // (monolit dogrulama_kodu_gonder — kuyruk uzerinden).
                if ($yaz) {
                    $this->kuyruga($salonId, 'dogrulama_kodu', $randevuId, $yetkiliId);
                }

                return [
                    'hatali'         => '2',
                    'mesaj'          => 'Lütfen müşteri/danışanın telefon numarasına gönderilen doğrulama kodunu giriniz!',
                    'kod_gonderildi' => $yaz,
                ];
            }
        }

        // (3) KVKK onay kodu kapisi — salon ayari (22) aciksa ve portfoyde onay yoksa.
        $portfoy = DB::table('musteri_portfoy')
            ->where('user_id', $randevu->user_id)->where('salon_id', $salonId)
            ->first(['id', 'onay_kodu', 'kvkk_onay_alindi']);
        if ($portfoy && $this->smsAyari($salonId, 22) && ! $portfoy->kvkk_onay_alindi
            && (string) ($g['kvkk_kodu'] ?? '') !== (string) $portfoy->onay_kodu) {
            if ($yaz) {
                $this->kuyruga($salonId, 'kvkk_kodu', $randevuId, $yetkiliId);
            }

            return [
                'hatali'         => '3',
                'mesaj'          => 'Lütfen müşteri/danışanın telefon numarasına gönderilen kvkk onay kodunu giriniz!',
                'kod_gonderildi' => $yaz,
            ];
        }

        // (4) Seans secimi — yeni istemci destekliyor ama secim gondermedi.
        $secilen = array_key_exists('secilen_seans_idler', $g) && is_array($g['secilen_seans_idler'])
            ? $g['secilen_seans_idler'] : null;
        if ($seansVar && ! empty($g['seans_secim_destek']) && $secilen === null) {
            return [
                'hatali'      => '4',
                'mesaj'       => 'Seans secimi yapilmali',
                'seanslar'    => $this->seansSecimListesi($randevuId, $seanslar),
                'musteri_adi' => DB::table('users')->where('id', $randevu->user_id)->value('name') ?? '',
                'tarih'       => $randevu->tarih ?? '',
                'saat'        => $randevu->saat ?? '',
            ];
        }

        $seansPlan  = SeansDurumGecisi::geldi($seanslar, $secilen, (array) ($g['seans_miktarlari'] ?? []));
        $dakikaPlan = $this->dakika->dusumPlani($randevuId);

        $plan = [
            'randevu'      => ['randevuya_geldi' => 1],
            'portfoy_kvkk' => $portfoy ? 1 : null,
            'yetim_sil'    => $yetimIdler,
            'seans'        => $seansPlan,
            'dakika_dusum' => $dakikaPlan,
        ];
        $bildirimler = $seansVar ? ['seans_kullanim'] : [];

        if (! $yaz) {
            return $this->sonuc(['hatali' => '0', 'mesaj' => 'DRY-RUN'], $plan, false, $bildirimler);
        }

        $dakikaSonuc = DB::transaction(function () use ($randevuId, $portfoy, $yetimIdler, $seansPlan, $dakikaPlan, $yetkiliId, $salonId, $seansVar) {
            if ($yetimIdler) {
                AdisyonPaketSeanslar::whereIn('id', $yetimIdler)->delete();
            }
            Randevular::where('id', $randevuId)->update(['randevuya_geldi' => 1]);
            if ($portfoy) {
                DB::table('musteri_portfoy')->where('id', $portfoy->id)->update(['kvkk_onay_alindi' => 1]);
            }
            $this->seansPlaniUygula($randevuId, $seansPlan);
            if ($seansVar) {
                $this->kuyruga($salonId, 'seans_kullanim', $randevuId, $yetkiliId);
            }

            return $this->dakika->dusumUygula($randevuId, $dakikaPlan, $this->personelId($salonId, $yetkiliId));
        });

        AktiviteLog::yaz($salonId, $yetkiliId, 'randevu_geldi', 'randevu', $randevuId,
            $this->musteriAdi($randevu), 'Randevu "geldi" olarak işaretlendi');

        $plan['dakika_dusum'] = $dakikaSonuc;

        return $this->sonuc(['hatali' => '0', 'mesaj' => 'Başarılı'], $plan, true, $bildirimler);
    }

    /**
     * GELMEDI. $seansDusumu: null = sor (paket seansi varsa seansDusmeOnayi doner),
     * true = telafi-disi seanslar geldi=0, false = seanslara dokunma.
     */
    public function gelmedi(int $randevuId, ?bool $seansDusumu, ?int $yetkiliId, bool $yaz): array
    {
        $randevu = $this->randevu($randevuId);
        $salonId = (int) $randevu->salon_id;
        $seanslar = $this->seanslar($randevuId);

        if ($seanslar && $seansDusumu === null) {
            return [
                'mesaj'           => $this->hizmetAdlariListesi($randevuId).' seansından düşülsün mü?',
                'seansDusmeOnayi' => true,
            ];
        }

        $seansPlan = ($seanslar && $seansDusumu) ? SeansDurumGecisi::gelmedi($seanslar) : ['guncelle' => [], 'sil' => []];
        $plan = [
            'randevu'             => ['randevuya_geldi' => 0],
            'seans'               => $seansPlan,
            'kalem_seansa_geldi'  => ($seanslar && $seansDusumu) ? 0 : null,
            'dakika_iade'         => $this->dakika->iadePlani($randevuId),
        ];

        if (! $yaz) {
            return $this->sonuc(['mesaj' => 'DRY-RUN'], $plan, false);
        }

        DB::transaction(function () use ($randevuId, $plan, $seansPlan, $salonId, $yetkiliId) {
            Randevular::where('id', $randevuId)->update(['randevuya_geldi' => 0]);
            $this->seansPlaniUygula($randevuId, $seansPlan);
            if ($plan['kalem_seansa_geldi'] === 0) {
                RandevuHizmetler::where('randevu_id', $randevuId)->update(['seansa_geldi' => 0]);
            }
            $this->dakika->iadeUygula($randevuId, $plan['dakika_iade'], $this->personelId($salonId, $yetkiliId));
        });

        AktiviteLog::yaz($salonId, $yetkiliId, 'randevu_gelmedi', 'randevu', $randevuId,
            $this->musteriAdi($randevu), 'Randevu "gelmedi" olarak işaretlendi');

        return $this->sonuc(['mesaj' => 'Başarılı'], $plan, true);
    }

    /** GELDI/GELMEDI ISARETINI KALDIR (telafi slotu korunur). */
    public function isaretKaldir(int $randevuId, ?int $yetkiliId, bool $yaz): array
    {
        $randevu = $this->randevu($randevuId);
        $salonId = (int) $randevu->salon_id;
        $seansPlan = SeansDurumGecisi::isaretKaldir($this->seanslar($randevuId));
        $plan = ['randevu' => ['randevuya_geldi' => null], 'kalem_seansa_geldi' => null, 'seans' => $seansPlan];

        if (! $yaz) {
            return $this->sonuc(['mesaj' => 'DRY-RUN'], $plan, false);
        }

        DB::transaction(function () use ($randevuId, $seansPlan) {
            Randevular::where('id', $randevuId)->update(['randevuya_geldi' => null]);
            RandevuHizmetler::where('randevu_id', $randevuId)->update(['seansa_geldi' => null]);
            $this->seansPlaniUygula($randevuId, $seansPlan);
        });

        AktiviteLog::yaz($salonId, $yetkiliId, 'randevu_geldi_iptal', 'randevu', $randevuId,
            $this->musteriAdi($randevu), 'Randevu geldi/gelmedi isareti kaldirildi');

        return $this->sonuc(['mesaj' => 'Başarılı'], $plan, true);
    }

    /**
     * IPTAL (isletme tarafi, durum=2). Bekleyen (durum=0) bir talepte bu RED'dir.
     * Telafi-disi seanslar silinir → paket bakiyesi geri doner.
     */
    public function iptal(int $randevuId, ?int $yetkiliId, bool $yaz): array
    {
        $randevu = $this->randevu($randevuId);
        if ((int) $randevu->durum >= Randevular::IPTAL_EDILDI) {
            throw new CakismaException('Randevu zaten iptal edilmiş / reddedilmiş');
        }
        $salonId = (int) $randevu->salon_id;
        $red = (int) $randevu->durum === Randevular::BEKLENIYOR;
        $seansPlan = SeansDurumGecisi::iptal($this->seanslar($randevuId));
        $plan = ['randevu' => ['durum' => Randevular::IPTAL_EDILDI], 'red' => $red, 'seans' => $seansPlan];
        $bildirimler = ['randevu_iptal'];

        if (! $yaz) {
            return $this->sonuc(['mesaj' => 'DRY-RUN'], $plan, false, $bildirimler);
        }

        DB::transaction(function () use ($randevuId, $seansPlan, $salonId, $yetkiliId, $red) {
            Randevular::where('id', $randevuId)->update(['durum' => Randevular::IPTAL_EDILDI]);
            $this->seansPlaniUygula($randevuId, $seansPlan);
            $this->kuyruga($salonId, 'randevu_iptal', $randevuId, $yetkiliId, ['red' => $red]);
        });

        AktiviteLog::yaz($salonId, $yetkiliId, $red ? 'randevu_red' : 'randevu_iptal', 'randevu', $randevuId,
            $this->musteriAdi($randevu), $red ? 'Randevu talebi reddedildi' : 'Randevu iptal edildi');

        return $this->sonuc(['mesaj' => 'Başarılı'], $plan, true, $bildirimler);
    }

    /** ONAYLA (bekleyen online talep → onaylandi). */
    public function onayla(int $randevuId, ?int $yetkiliId, bool $yaz): array
    {
        $randevu = $this->randevu($randevuId);
        if ((int) $randevu->durum !== Randevular::BEKLENIYOR) {
            throw new CakismaException('Yalnızca onay bekleyen randevu onaylanabilir');
        }
        $salonId = (int) $randevu->salon_id;
        $plan = ['randevu' => ['durum' => Randevular::ONAYLANDI]];
        $bildirimler = ['randevu_onay'];

        if (! $yaz) {
            return $this->sonuc(['mesaj' => 'DRY-RUN'], $plan, false, $bildirimler);
        }

        DB::transaction(function () use ($randevuId, $salonId, $yetkiliId) {
            Randevular::where('id', $randevuId)->update(['durum' => Randevular::ONAYLANDI]);
            $this->kuyruga($salonId, 'randevu_onay', $randevuId, $yetkiliId);
        });

        AktiviteLog::yaz($salonId, $yetkiliId, 'randevu_onayla', 'randevu', $randevuId,
            $this->musteriAdi($randevu), 'Randevu talebi onaylandı');

        return $this->sonuc(['mesaj' => 'Başarılı'], $plan, true, $bildirimler);
    }

    // ------------------------------------------------------------------

    private function randevu(int $id): Randevular
    {
        $r = Randevular::find($id);
        if (! $r) {
            throw new BulunamadiException('Randevu bulunamadı');
        }

        return $r;
    }

    /** @return array<int,array{id:int,geldi:mixed,...}> */
    private function seanslar(int $randevuId): array
    {
        return AdisyonPaketSeanslar::where('randevu_id', $randevuId)
            ->get(['id', 'geldi', 'hizmet_id', 'adisyon_paket_id', 'adisyon_hizmet_id', 'seans_no', 'dusulen_miktar'])
            ->map(fn ($s) => $s->toArray())->all();
    }

    /** @return array{0: array, 1: int[]} [gecerli seanslar, yetim idler] */
    private function gecerliSeanslar(int $randevuId): array
    {
        $tum = $this->seanslar($randevuId);
        $apIds = DB::table('adisyon_paketler')->whereIn('id', array_filter(array_column($tum, 'adisyon_paket_id')))->pluck('id')->flip();
        $ahIds = DB::table('adisyon_hizmetler')->whereIn('id', array_filter(array_column($tum, 'adisyon_hizmet_id')))->pluck('id')->flip();

        $gecerli = [];
        $yetim = [];
        foreach ($tum as $s) {
            if (($s['adisyon_paket_id'] && isset($apIds[$s['adisyon_paket_id']]))
                || ($s['adisyon_hizmet_id'] && isset($ahIds[$s['adisyon_hizmet_id']]))) {
                $gecerli[] = $s;
            } else {
                $yetim[] = (int) $s['id'];
            }
        }

        return [$gecerli, $yetim];
    }

    private function seansPlaniUygula(int $randevuId, array $plan): void
    {
        foreach ($plan['guncelle'] as $id => $degerler) {
            AdisyonPaketSeanslar::where('id', $id)->where('randevu_id', $randevuId)->update($degerler);
        }
        if ($plan['sil']) {
            AdisyonPaketSeanslar::whereIn('id', $plan['sil'])->where('randevu_id', $randevuId)->delete();
        }
    }

    /** Hatali '4' seans secim listesi — eski mobil alan adlariyla birebir. */
    private function seansSecimListesi(int $randevuId, array $seanslar): array
    {
        $hizmetAdlari = DB::table('hizmetler')->whereIn('id', array_filter(array_column($seanslar, 'hizmet_id')))->pluck('hizmet_adi', 'id');
        $kalemler = RandevuHizmetler::where('randevu_id', $randevuId)->get(['hizmet_id', 'sure_dk', 'dusum_miktari'])->keyBy('hizmet_id');

        $liste = [];
        foreach ($seanslar as $s) {
            $ap = $s['adisyon_paket_id']
                ? DB::table('adisyon_paketler as ap')->leftJoin('paketler as p', 'p.id', '=', 'ap.paket_id')
                    ->where('ap.id', $s['adisyon_paket_id'])->first(['ap.id', 'ap.seans_sayisi', 'p.paket_adi'])
                : null;
            $toplam = $ap ? (int) $ap->seans_sayisi : 0;
            $kullanilan = $ap
                ? (int) DB::table('adisyon_paket_seanslar')->where('adisyon_paket_id', $ap->id)->where('geldi', 1)->sum('dusulen_miktar')
                : 0;
            $rh = $kalemler->get($s['hizmet_id']);

            $liste[] = [
                'id'               => (int) $s['id'],
                'hizmet_adi'       => $hizmetAdlari[$s['hizmet_id']] ?? '',
                'paket_adi'        => $ap->paket_adi ?? '',
                'adisyon_paket_id' => (int) $s['adisyon_paket_id'],
                'kalan_seans'      => max(0, $toplam - $kullanilan),
                'toplam_seans'     => $toplam,
                'seans_no'         => (int) ($s['seans_no'] ?? 0),
                'sure_dk'          => (int) ($rh->sure_dk ?? 0),
                'default_miktar'   => ($rh && $rh->dusum_miktari) ? (int) $rh->dusum_miktari : 1,
                'dusulen_miktar'   => (int) ($s['dusulen_miktar'] ?? 1),
                'simdi_geldi'      => (bool) $s['geldi'], // eski: $s->geldi == true (telafi 2 de true)
            ];
        }

        return $liste;
    }

    /** "A, B ve C" — eski gelmedi onay mesajiyla ayni bicim. */
    private function hizmetAdlariListesi(int $randevuId): string
    {
        $adlar = DB::table('adisyon_paket_seanslar as s')
            ->leftJoin('hizmetler as h', 'h.id', '=', 's.hizmet_id')
            ->where('s.randevu_id', $randevuId)->orderBy('s.id')
            ->pluck('h.hizmet_adi')->map(fn ($a) => (string) $a)->all();
        $son = array_pop($adlar);

        return $adlar ? implode(', ', $adlar).' ve '.$son : (string) $son;
    }

    private function smsAyari(int $salonId, int $ayarId): bool
    {
        return (bool) DB::table('salon_sms_ayarlari')->where('salon_id', $salonId)->where('ayar_id', $ayarId)->value('musteri');
    }

    private function personelId(int $salonId, ?int $yetkiliId): ?int
    {
        if (! $yetkiliId) {
            return null;
        }
        $id = Personeller::where('salon_id', $salonId)->where('yetkili_id', $yetkiliId)->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function musteriAdi(Randevular $r): ?string
    {
        return $r->user_id ? DB::table('users')->where('id', $r->user_id)->value('name') : null;
    }

    private function sonuc(array $kodlar, array $plan, bool $yazildi, array $bildirimler = []): array
    {
        return $kodlar + [
            'yazildi'     => $yazildi,
            'plan'        => $plan,
            'bildirimler' => $bildirimler, // kuyruga yazilan (yazildi=true) / yazilacak (DRY-RUN)
        ];
    }

    /**
     * Yan etkiyi outbox'a yaz ('v2_bildirim_kuyrugu' — sema eski klasorde).
     * Cagiran transaction icindeyse onunla birlikte commit/rollback olur.
     */
    private function kuyruga(int $salonId, string $tur, int $randevuId, ?int $yetkiliId, array $payload = []): void
    {
        DB::table('v2_bildirim_kuyrugu')->insert([
            'salon_id'   => $salonId,
            'tur'        => $tur,
            'randevu_id' => $randevuId,
            'yetkili_id' => $yetkiliId,
            'payload'    => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'durum'      => 0,
            'deneme'     => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
