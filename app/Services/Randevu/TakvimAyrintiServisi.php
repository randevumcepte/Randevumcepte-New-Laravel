<?php

namespace App\Services\Randevu;

use App\Domain\Randevu\TakvimRenkAyari;
use App\Models\DersOturumu;
use App\Models\RandevuHizmetler;
use App\Models\Randevular;
use App\Services\Yetki\YetkiServisi;
use App\Support\KvkkMaske;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GET /randevu/takvim?ayrinti=1 ekleri: RandevuOkumaServisi'nin takvim cevabini baslik, detay metni,
 * nihai renk, saat araligi, personel ve duzenleme listesiyle genisletir. Mevcut alanlar degismez;
 * web ve mobil ayni cevabi kullanir.
 *
 * Yetki: musteri notu yalniz musteri.not_yaz, telefon yalniz musteri.telefon_gor ile gider.
 * Listede yalniz ekranlarin okudugu alanlar doner; saglik, maas ve prim alanlari gonderilmez.
 */
class TakvimAyrintiServisi
{
    private const PERSONEL_YOK_ID = 183;

    private const SIYAH = '0xFF000000';

    private const BEYAZ = '0xFFFFFFFF';

    private const MUSTERI_ALANLARI = ['id', 'name', 'cep_telefon', 'email', 'cinsiyet', 'dogum_tarihi', 'il_id'];

    private const PERSONEL_ALANLARI = ['id', 'personel_adi', 'salon_id', 'unvan', 'cinsiyet', 'renk', 'takvim_sirasi', 'takvimde_gorunsun', 'aktif'];

    public function __construct(
        private RandevuOkumaServisi $okuma,
        private YetkiServisi $yetki,
    ) {}

    public function ekle(array $takvim, int $salonId, ?string $tarih1, ?string $tarih2, ?int $personelId, int $yetkiliId): array
    {
        $tarih1 = $tarih1 ?: date('Y-m-d');
        $tarih2 = $tarih2 ?: date('Y-m-d');
        $tur = (int) $takvim['takvim_turu'];
        $kapsam = $this->okuma->kapsam($salonId, $personelId, $tur, $yetkiliId);

        $renk = TakvimRenkAyari::salondan(DB::table('salonlar')->where('id', $salonId)->first());
        $yetki = [
            'not' => $this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, 'musteri.not_yaz'),
            'tel' => $this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, 'musteri.telefon_gor'),
        ];

        $rhler = RandevuHizmetler::with('randevu.users')->whereIn('id', array_column($takvim['randevular'], 'rh_id'))->get()->keyBy('id');
        $s = $this->sozluk($salonId, $rhler);

        $olaylar = array_map(fn ($o) => $this->olay($o, $rhler[$o['rh_id']], $tur, $renk, $yetki, $s), $takvim['randevular']);
        [$kaynaklar, $olaylar, $siraRenk, $siraYazi] = $this->boya($takvim['resources'], $olaylar, $renk);

        $takvim['randevular'] = array_map(function ($o) {
            unset($o['_normalRenk']);

            return $o;
        }, $olaylar);
        $takvim['resources'] = $kaynaklar;
        $takvim['ayrinti'] = [
            'saat_araligi'  => $this->saatAraligi($salonId, $tarih1, $olaylar),
            'personeller'   => DB::table('salon_personelleri')->where('salon_id', $salonId)->where('takvimde_gorunsun', true)->get(self::PERSONEL_ALANLARI),
            'randevu_liste' => $this->liste($salonId, $tarih1, $tarih2, $yetki),
            'ders_bloklari' => $takvim['grup_dersi_aktif'] && $tur === 1
                ? $this->dersBloklari($salonId, $tarih1, $tarih2, $kapsam['personelRolu'] === 5 ? $kapsam['personelId'] : null, $renk, $siraRenk, $siraYazi, $s)
                : [],
        ];

        return $takvim;
    }

    /** Satir basina ekstra sorgu olmasin diye ad ve renkler toplu okunur. */
    private function sozluk(int $salonId, Collection $rhler): array
    {
        $randevular = $rhler->pluck('randevu')->filter();
        $ongorusmeler = DB::table('on_gorusmeler')->whereIn('id', $randevular->pluck('on_gorusme_id')->filter()->unique())
            ->get(['id', 'paket_id', 'urun_id', 'hizmet_id', 'durum'])->keyBy('id');
        $seanslar = DB::table('adisyon_paket_seanslar')->whereIn('randevu_id', $rhler->pluck('randevu_id')->unique())
            ->get(['randevu_id', 'adisyon_paket_id', 'adisyon_hizmet_id', 'hizmet_id']);
        $seansliHizmet = DB::table('adisyon_hizmetler')->whereIn('id', $seanslar->pluck('adisyon_hizmet_id')->filter()->unique())
            ->where('seans_sayisi', '>', 0)->pluck('id')->flip();

        $paketli = [];
        foreach ($seanslar as $sk) {
            if ($sk->hizmet_id && ($sk->adisyon_paket_id || isset($seansliHizmet[$sk->adisyon_hizmet_id]))) {
                $paketli[$sk->randevu_id][(int) $sk->hizmet_id] = true;
            }
        }

        $renkler = fn (string $tablo, string $fk) => DB::table($tablo.' as t')->join('renk_duzenleri as rd', 't.renk_id', '=', 'rd.id')
            ->where('t.salon_id', $salonId)->pluck('rd.renk', 't.'.$fk);

        return [
            'hizmet'        => DB::table('hizmetler')->whereIn('id', $rhler->pluck('hizmet_id')->merge($ongorusmeler->pluck('hizmet_id'))->filter()->unique())->get(['id', 'hizmet_adi', 'hizmet_kategori_id'])->keyBy('id'),
            'personel'      => DB::table('salon_personelleri as p')->leftJoin('renk_duzenleri as rd', 'p.renk', '=', 'rd.id')->where('p.salon_id', $salonId)->get(['p.id', 'p.personel_adi', 'rd.renk'])->keyBy('id'),
            'cihaz'         => DB::table('cihazlar')->whereIn('id', $rhler->pluck('cihaz_id')->filter()->unique())->pluck('cihaz_adi', 'id'),
            'oda'           => DB::table('odalar')->whereIn('id', $rhler->pluck('oda_id')->filter()->unique())->pluck('oda_adi', 'id'),
            'olusturan'     => DB::table('isletmeyetkilileri')->whereIn('id', $randevular->pluck('olusturan_personel_id')->filter()->unique())->pluck('name', 'id'),
            'ongorusme'     => $ongorusmeler,
            'paket'         => DB::table('paketler')->whereIn('id', $ongorusmeler->pluck('paket_id')->filter()->unique())->pluck('paket_adi', 'id'),
            'urun'          => DB::table('urunler')->whereIn('id', $ongorusmeler->pluck('urun_id')->filter()->unique())->pluck('urun_adi', 'id'),
            'paketli'       => $paketli,
            'kategori_renk' => $renkler('salon_hizmet_kategori_renkleri', 'hizmet_kategori_id'),
            'cihaz_renk'    => $renkler('salon_cihaz_renkleri', 'cihaz_id'),
            'oda_renk'      => $renkler('salon_oda_renkleri', 'oda_id'),
        ];
    }

    private function olay(array $olay, RandevuHizmetler $rh, int $tur, TakvimRenkAyari $renk, array $yetki, array $s): array
    {
        $r = $rh->randevu;
        $kapali = (int) $r->user_id === Randevular::KAPALI_SAAT_USER_ID;
        [$bg, $normal] = $kapali ? [self::SIYAH, false] : $this->renk($rh, $r, $tur, $renk, $s);
        [$baslik, $olusturan] = $kapali ? ['Kapalı Saat', ''] : $this->baslik($rh, $r, $s);

        return array_merge($olay, [
            'baslik'      => $baslik,
            'detay'       => $this->detay($rh, $r, $olusturan, $yetki, $s),
            'renk'        => $bg,
            '_normalRenk' => $normal,
            'yazi_rengi'  => $renk->yaziRengi !== null ? ($normal ? $renk->yaziRengi : self::BEYAZ) : '',
        ]);
    }

    /** @return array{0:string,1:bool} renk ve "kaynak rengi mi" (renk sirasi yalniz kaynak rengini ezer) */
    private function renk(RandevuHizmetler $rh, Randevular $r, int $tur, TakvimRenkAyari $renk, array $s): array
    {
        $geldi = $r->randevuya_geldi === null ? null : (int) $r->randevuya_geldi;
        $seans = $rh->seansa_geldi === null ? null : (int) $rh->seansa_geldi;

        if ($geldi === 1 || $seans === 1) {
            return ['0xFF008000', false];
        }
        if ($geldi === 0 || $seans === 0) {
            return ['0xFFff0000', false];
        }
        if ((int) $r->durum === Randevular::BEKLENIYOR) {
            return ['0xFFffc107', false];
        }
        if ($this->gelecek($r)) {
            return ['0xFFEF7400', false];
        }
        if ($rh->iptal) {
            return [self::SIYAH, false];
        }

        return [$renk->tekRenk ?? $this->kaynakRengi($rh, $tur, $s), true];
    }

    private function gelecek(Randevular $r): bool
    {
        return $r->randevuya_gelecek !== null && (int) $r->randevuya_gelecek === 1 && $r->randevuya_geldi === null;
    }

    private function kaynakRengi(RandevuHizmetler $rh, int $tur, array $s): string
    {
        $hex = match ($tur) {
            1 => (int) $rh->personel_id !== self::PERSONEL_YOK_ID ? ($s['personel'][$rh->personel_id]->renk ?? null) : null,
            2 => $s['cihaz_renk'][$rh->cihaz_id] ?? null,
            3 => $s['oda_renk'][$rh->oda_id] ?? null,
            default => $s['kategori_renk'][$s['hizmet'][$rh->hizmet_id]->hizmet_kategori_id ?? 0] ?? null,
        };

        return $hex ? str_replace('#', '0xFF', $hex) : self::SIYAH;
    }

    /** @return array{0:string,1:string} kart basligi ve olusturan adi */
    private function baslik(RandevuHizmetler $rh, Randevular $r, array $s): array
    {
        $baslik = (string) $r->users?->name;

        if (isset($s['paketli'][$rh->randevu_id][(int) $rh->hizmet_id])) {
            $baslik .= ' (PAKET)';
        } elseif ($r->on_gorusme_id !== null) {
            $baslik .= " (ÖN GÖRÜŞME)\nÖn Görüşme Nedeni:".$this->ongorusmeNedeni($r, $s, '');
        }

        $baslik .= "\n".($s['hizmet'][$rh->hizmet_id]->hizmet_adi ?? '')."\nOluşturan:";
        $olusturan = '';
        if ($r->web) {
            $baslik .= $olusturan = 'Müşteri (Web)';
        }
        if ($r->salon) {
            $baslik .= $olusturan = $s['olusturan'][$r->olusturan_personel_id] ?? 'Belirtilmemiş';
        }
        if ($r->uygulama) {
            $baslik .= $olusturan = 'Müşteri (Uygulama)';
        }
        if ($r->easistan) {
            $baslik .= $olusturan = 'Müşteri (Asistan)';
        }
        if ($this->gelecek($r)) {
            $baslik .= "\nRANDEVUYA GELECEK";
        }

        return [$baslik, $olusturan];
    }

    private function ongorusmeNedeni(Randevular $r, array $s, string $yoksa): string
    {
        $og = $s['ongorusme'][$r->on_gorusme_id] ?? null;

        return ($og?->paket_id ? ($s['paket'][$og->paket_id] ?? null) : null)
            ?? ($og?->urun_id ? ($s['urun'][$og->urun_id] ?? null) : null)
            ?? ($og?->hizmet_id ? ($s['hizmet'][$og->hizmet_id]->hizmet_adi ?? null) : null)
            ?? $yoksa;
    }

    /** Randevu detay penceresindeki metin; satirlar ve sirasi ekranla birebir. */
    private function detay(RandevuHizmetler $rh, Randevular $r, string $olusturan, array $yetki, array $s): string
    {
        $telefon = (string) ($r->users?->cep_telefon ?? '');
        $metin = 'Telefon : '.($yetki['tel'] ? $telefon : KvkkMaske::telefon($telefon));
        $zaman = date('d.m.Y', strtotime($r->tarih)).' '.date('H:i', strtotime($rh->saat));
        $personel = $s['personel'][$rh->personel_id]->personel_adi ?? null;

        if ($r->on_gorusme_id !== null) {
            $durum = $s['ongorusme'][$r->on_gorusme_id]->durum ?? null;
            $durumMetni = $durum === null ? 'Beklemede' : ((int) $durum === 1 ? 'Satış Yapıldı' : 'Satış Yapılmadı');

            // "Süre" ile olusturan adi arasinda alt satir olmamasi mevcut ekranin gorunumudur.
            return $metin
                ."\nÖn Görüşme Nedeni : ".$this->ongorusmeNedeni($r, $s, 'Belirtilmemiş')
                ."\nGörüşmeyi Yapan : ".$personel
                ."\nZaman : ".$zaman
                ."\nSüre(dk) : ".$rh->sure_dk
                .($s['olusturan'][$r->olusturan_personel_id] ?? 'Belirtilmemiş')
                ."\nDurum : ".$durumMetni;
        }

        $geldi = $r->randevuya_geldi === null ? 'Beklemede' : ((int) $r->randevuya_geldi === 1 ? 'Geldi' : 'Gelmedi');
        $personelMetni = $personel !== null && (int) $rh->personel_id !== self::PERSONEL_YOK_ID ? $personel : 'Belirtilmemiş';

        return $metin
            ."\nHizmet : ".($s['hizmet'][$rh->hizmet_id]->hizmet_adi ?? 'Belirtilmemiş')
            ."\nPersonel : ".$personelMetni
            ."\nCihaz : ".($s['cihaz'][$rh->cihaz_id] ?? 'Belirtilmemiş')
            ."\nOda : ".($s['oda'][$rh->oda_id] ?? 'Belirtilmemiş')
            ."\nZaman : ".$zaman
            ."\nSüre(dk) : ".$rh->sure_dk
            ."\nFiyat(₺) : ".number_format((float) $rh->fiyat, 2, ',', '.')
            ."\nOluşturan : ".$olusturan
            ."\nGeldi mi? : ".$geldi
            .($yetki['not'] ? "\nMüşteri Notu : ".$r->notlar : '')
            ."\nPersonel Notu : ".$r->personel_notu;
    }

    /**
     * Salon renk ayarini kaynaklara ve olaylara uygular.
     *
     * @return array{0:array,1:array,2:array<string,string>,3:array<string,string>}
     */
    private function boya(array $kaynaklar, array $olaylar, TakvimRenkAyari $renk): array
    {
        $siraRenk = [];
        $siraYazi = [];
        foreach ($kaynaklar as $i => &$k) {
            $k['renk'] = $renk->tekRenk ?? $k['bgcolor'];
            if ($renk->sira) {
                $cift = $renk->sira[$i % count($renk->sira)];
                $k['renk'] = $cift['bg'];
                $siraRenk[(string) $k['id']] = $cift['bg'];
                if (($cift['text'] ?? $renk->yaziRengi) !== null) {
                    $siraYazi[(string) $k['id']] = $cift['text'] ?? $renk->yaziRengi;
                }
            }
        }
        unset($k);

        if ($renk->sira) {
            $olaylar = array_map(function ($o) use ($siraRenk, $siraYazi) {
                $id = (string) ($o['resourceId'] ?? '');
                if ($o['_normalRenk'] && isset($siraRenk[$id])) {
                    $o['renk'] = $siraRenk[$id];
                    $o['yazi_rengi'] = $siraYazi[$id] ?? $o['yazi_rengi'];
                } else {
                    $o['yazi_rengi'] = self::BEYAZ;
                }

                return $o;
            }, $olaylar);
        }

        return [$kaynaklar, $olaylar, $siraRenk, $siraYazi];
    }

    /** Iptal edilen dersler de gri olarak gosterilir. */
    private function dersBloklari(int $salonId, string $tarih1, string $tarih2, ?int $personelId, TakvimRenkAyari $renk, array $siraRenk, array $siraYazi, array $s): array
    {
        return DersOturumu::withCount('kontenjandakiler')->where('salon_id', $salonId)->where('aktif', true)
            ->whereBetween('tarih', [$tarih1, $tarih2])
            ->when($personelId, fn ($q) => $q->where('personel_id', $personelId))
            ->get()->map(function (DersOturumu $o) use ($renk, $siraRenk, $siraYazi, $s) {
                $iptal = (bool) $o->iptal;
                $personel = (string) ($o->personel_id ?? '');
                $bg = $iptal ? '#95a5a6' : ($s['personel'][$o->personel_id]->renk ?? ($o->renk ?: '#5d6d7e'));
                $bg = str_starts_with(strtolower($bg), '0x') ? $bg : '0xFF'.strtoupper(ltrim($bg, '#'));
                if (! $iptal) {
                    $bg = $renk->tekRenk ?? ($siraRenk[$personel] ?? $bg);
                }

                return [
                    'id'         => 'ders-'.$o->id,
                    'baslik'     => $o->ad().' '.$o->kontenjandakiler_count.'/'.(int) $o->kapasite.' dolu',
                    'start'      => Carbon::parse($o->tarih.' '.$o->saat)->toIso8601String(),
                    'end'        => Carbon::parse($o->tarih.' '.$o->saat_bitis)->toIso8601String(),
                    'renk'       => $bg,
                    'resourceId' => $o->personel_id ?? 0,
                    'durum'      => 'ders',
                    'yazi_rengi' => $iptal ? self::BEYAZ : ($siraYazi[$personel] ?? $renk->yaziRengi ?? self::BEYAZ),
                ];
            })->all();
    }

    /** Takvimin ust ve alt siniri (15 dk dilimli): calisma saati, randevu varsa ona gore genisler. */
    private function saatAraligi(int $salonId, string $tarih, array $olaylar): array
    {
        $cs = DB::table('salon_calisma_saatleri')->where('salon_id', $salonId)
            ->where('haftanin_gunu', (int) date('N', strtotime($tarih)))->where('calisiyor', 1)->first(['baslangic_saati', 'bitis_saati']);
        $bas = (string) ($cs->baslangic_saati ?? '08:00');
        $bit = (string) ($cs->bitis_saati ?? '20:00');

        foreach ($olaylar as $o) {
            $s1 = substr($o['start'], 11, 5);
            $s2 = $o['end'] <= $o['start'] ? '23:45' : substr($o['end'], 11, 5);
            $bas = $s1 < $bas ? $s1 : $bas;
            $bit = $s2 > $bit ? $s2 : $bit;
        }

        $bas = self::yuvarla($bas, false);
        $bit = self::yuvarla($bit, true);

        return $bit <= $bas ? ['baslangic' => '08:00', 'bitis' => '20:00'] : ['baslangic' => $bas, 'bitis' => $bit];
    }

    private static function yuvarla(string $saat, bool $yukari): string
    {
        [$s, $d] = array_map('intval', array_slice(explode(':', $saat.':0'), 0, 2));
        $dk = $s * 60 + $d;
        $dk = $yukari ? min((int) ceil($dk / 15) * 15, 23 * 60 + 45) : $dk - $dk % 15;

        return sprintf('%02d:%02d', intdiv($dk, 60), $dk % 60);
    }

    /** Duzenleme ve tahsilat penceresinin okudugu randevular. Kapali saat bu listede yoktur. */
    private function liste(int $salonId, string $tarih1, string $tarih2, array $yetki): array
    {
        $randevular = Randevular::with('hizmetler')->where('salon_id', $salonId)
            ->where('user_id', '!=', Randevular::KAPALI_SAAT_USER_ID)
            ->where('tarih', '>=', $tarih1)->where('tarih', '<=', $tarih2)->get();
        $kalemler = $randevular->pluck('hizmetler')->flatten(1);

        $hizmet = DB::table('hizmetler')->whereIn('id', $kalemler->pluck('hizmet_id')->filter()->unique())->get()->keyBy('id');
        $personel = DB::table('salon_personelleri')->whereIn('id', $kalemler->pluck('personel_id')->filter()->unique())->get(self::PERSONEL_ALANLARI)->keyBy('id');
        $cihaz = DB::table('cihazlar')->whereIn('id', $kalemler->pluck('cihaz_id')->filter()->unique())->get()->keyBy('id');
        $oda = DB::table('odalar')->whereIn('id', $kalemler->pluck('oda_id')->filter()->unique())->get()->keyBy('id');
        $musteri = DB::table('users')->whereIn('id', $randevular->pluck('user_id')->filter()->unique())->get(self::MUSTERI_ALANLARI)->keyBy('id');

        return $randevular->map(function (Randevular $r) use ($hizmet, $personel, $cihaz, $oda, $musteri, $yetki) {
            $satir = $r->attributesToArray();
            if (! $yetki['not']) {
                unset($satir['notlar'], $satir['musteri_notu']);
            }
            if (! $yetki['tel'] && ! empty($satir['telefon'])) {
                $satir['telefon'] = KvkkMaske::telefon($satir['telefon']);
            }

            $m = (array) ($musteri[$r->user_id] ?? []);
            if ($m && ! $yetki['tel']) {
                $m['cep_telefon'] = KvkkMaske::telefon($m['cep_telefon']);
            }
            $satir['users'] = $m ?: ['id' => $r->user_id, 'name' => null];
            $satir['hizmetler'] = $r->hizmetler->map(fn (RandevuHizmetler $k) => $k->attributesToArray() + [
                'hizmetler'   => $hizmet[$k->hizmet_id] ?? null,
                'personeller' => $personel[$k->personel_id] ?? null,
                'cihaz'       => $cihaz[$k->cihaz_id] ?? null,
                'oda'         => $oda[$k->oda_id] ?? null,
            ])->all();

            return $satir;
        })->all();
    }
}
