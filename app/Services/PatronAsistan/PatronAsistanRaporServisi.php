<?php

namespace App\Services\PatronAsistan;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PATRON ASISTANI — MOBIL veri katmani (salt-okunur).
 *
 * Web tarafinda asistan, StoreAdminController'in dashboard/isletmeRaporlari
 * fonksiyonlarina delege ediyor; ama o fonksiyonlar WEB oturum guard'ina
 * (Auth::guard('isletmeyonetim')) bagli. Mobil uc (auth:isletmeyonetim-api)
 * icin ayni sorgulari, cozulmus $salonId ile bagimsiz calistiran bu servis
 * kullanilir. Sorgular web rapor fonksiyonlariyla BIREBIR ayni (ayni tablo/kolon).
 *
 * Cikti sekilleri PatronAsistanServisi'nin cevapX() metotlarinin bekledigiyle
 * ayni tutulmustur (kasa: nakit/kart/havale/diger/toplam, personel: personeller[]
 * vb.) — boylece niyet+cevap "beyni" web ile mobil arasinda tek yerde paylasilir.
 */
class PatronAsistanRaporServisi
{
    /** Kanonik donem (gun|hafta|ay) -> [t1, t2] tarih araligi (raporPeriodDates ile ayni). */
    public function donemTarih($donem)
    {
        $bugun = date('Y-m-d');
        if ($donem === 'ay') {
            return [date('Y-m-01'), date('Y-m-t')];
        }
        if ($donem === 'hafta') {
            return [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))];
        }
        return [$bugun, $bugun]; // gun
    }

    /** Kasa/ciro — tahsilatlar tablosu, odeme yontemine gore kirilim (dashboardKasa ile ayni). */
    public function kasa($salonId, $t1, $t2)
    {
        $rows = DB::table('tahsilatlar')
            ->leftJoin('odeme_yontemleri', 'tahsilatlar.odeme_yontemi_id', '=', 'odeme_yontemleri.id')
            ->where('tahsilatlar.salon_id', $salonId)
            ->whereBetween('tahsilatlar.odeme_tarihi', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])
            ->select('odeme_yontemleri.odeme_yontemi as yontem', DB::raw('SUM(tahsilatlar.tutar) as toplam'))
            ->groupBy('odeme_yontemleri.odeme_yontemi')
            ->get();

        return $this->tahsilatKirilim($rows);
    }

    /** Personel performansi (isletmeRaporlariPersonel ile ayni). */
    public function personel($salonId, $t1, $t2)
    {
        $hizmetRows = DB::table('adisyon_hizmetler as ah')
            ->join('adisyonlar as a', 'a.id', '=', 'ah.adisyon_id')
            ->where('a.salon_id', $salonId)
            ->whereBetween('a.created_at', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])
            ->whereNotNull('ah.personel_id')
            ->select('ah.personel_id',
                DB::raw('COUNT(*) as hizmet_say'),
                DB::raw('SUM(ah.fiyat - COALESCE(ah.indirim_tutari,0)) as ciro'))
            ->groupBy('ah.personel_id')
            ->get()->keyBy('personel_id');

        $randevuRows = DB::table('randevu_hizmetler as rh')
            ->join('randevular as r', 'r.id', '=', 'rh.randevu_id')
            ->where('r.salon_id', $salonId)
            ->whereBetween('r.tarih', [$t1, $t2])
            ->whereNotNull('rh.personel_id')
            ->where(function ($q) { $q->where('rh.yardimci_personel', '!=', true)->orWhereNull('rh.yardimci_personel'); })
            ->select('rh.personel_id', DB::raw('COUNT(DISTINCT r.id) as randevu_say'))
            ->groupBy('rh.personel_id')
            ->get()->keyBy('personel_id');

        $personeller = DB::table('salon_personelleri')
            ->where('salon_id', $salonId)->where('aktif', 1)
            ->select('id', 'personel_adi')->get();

        $sonuc = [];
        foreach ($personeller as $p) {
            $h = $hizmetRows[$p->id] ?? null;
            $r = $randevuRows[$p->id] ?? null;
            $sonuc[] = [
                'id' => $p->id,
                'personel_adi' => $p->personel_adi,
                'randevu_say' => $r ? (int) $r->randevu_say : 0,
                'hizmet_say' => $h ? (int) $h->hizmet_say : 0,
                'ciro' => $h ? (float) $h->ciro : 0,
            ];
        }
        usort($sonuc, function ($a, $b) { return $b['ciro'] <=> $a['ciro']; });
        return ['personeller' => $sonuc];
    }

    /** Hizmet karlilik (isletmeRaporlariHizmet ile ayni). */
    public function hizmet($salonId, $t1, $t2)
    {
        $rows = DB::table('adisyon_hizmetler as ah')
            ->join('adisyonlar as a', 'a.id', '=', 'ah.adisyon_id')
            ->join('hizmetler as h', 'h.id', '=', 'ah.hizmet_id')
            ->where('a.salon_id', $salonId)
            ->whereBetween('a.created_at', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])
            ->select('h.id', 'h.hizmet_adi',
                DB::raw('COUNT(*) as adet'),
                DB::raw('SUM(ah.fiyat - COALESCE(ah.indirim_tutari,0)) as ciro'))
            ->groupBy('h.id', 'h.hizmet_adi')
            ->orderByDesc('ciro')->limit(20)->get();

        return ['hizmetler' => $this->objToArr($rows)];
    }

    /** Urun satis (isletmeRaporlariUrun ile ayni). */
    public function urun($salonId, $t1, $t2)
    {
        $rows = DB::table('adisyon_urunler as au')
            ->join('adisyonlar as a', 'a.id', '=', 'au.adisyon_id')
            ->leftJoin('urunler as u', 'u.id', '=', 'au.urun_id')
            ->where('a.salon_id', $salonId)
            ->whereBetween('a.created_at', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])
            ->select(
                DB::raw('COALESCE(u.id, 0) as urun_id'),
                DB::raw("COALESCE(u.urun_adi,'Silinmiş Ürün') as urun_adi"),
                DB::raw('SUM(COALESCE(au.adet,1)) as adet'),
                DB::raw('SUM(au.fiyat - COALESCE(au.indirim_tutari,0)) as ciro'))
            ->groupBy('u.id', 'u.urun_adi')
            ->orderByDesc('ciro')->limit(20)->get();

        return ['urunler' => $this->objToArr($rows)];
    }

    /** Musteri ozeti (isletmeRaporlariMusteri ile ayni; hassas top-liste haric). */
    public function musteri($salonId, $t1, $t2)
    {
        $aktifMusteriler = DB::table('randevular')
            ->where('salon_id', $salonId)
            ->whereBetween('tarih', [$t1, $t2])
            ->distinct()->pluck('user_id');
        $toplamAktif = $aktifMusteriler->count();

        $yeniMusteri = 0;
        if (Schema::hasTable('musteri_portfoy')) {
            $yeniMusteri = DB::table('musteri_portfoy')
                ->where('salon_id', $salonId)
                ->whereBetween('created_at', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])
                ->count();
        }
        $tekrarGelen = max(0, $toplamAktif - $yeniMusteri);

        $cinsiyet = ['kadin' => 0, 'erkek' => 0, 'belirsiz' => 0];
        if ($toplamAktif > 0) {
            $cinsiyetRows = DB::table('users')
                ->whereIn('id', $aktifMusteriler)
                ->select('cinsiyet', DB::raw('COUNT(*) as adet'))
                ->groupBy('cinsiyet')->get();
            foreach ($cinsiyetRows as $c) {
                $cins = mb_strtolower($c->cinsiyet ?? '', 'UTF-8');
                if (strpos($cins, 'kad') !== false || $cins === 'k' || $cins === 'female' || $cins === 'f') {
                    $cinsiyet['kadin'] += (int) $c->adet;
                } elseif (strpos($cins, 'erk') !== false || $cins === 'e' || $cins === 'male' || $cins === 'm') {
                    $cinsiyet['erkek'] += (int) $c->adet;
                } else {
                    $cinsiyet['belirsiz'] += (int) $c->adet;
                }
            }
        }

        return [
            'toplam_aktif' => $toplamAktif,
            'yeni_musteri' => $yeniMusteri,
            'tekrar_gelen' => $tekrarGelen,
            'cinsiyet' => $cinsiyet,
        ];
    }

    /** Genel ozet / gun sonu (isletmeRaporlariOzet ile ayni). */
    public function ozet($salonId, $t1, $t2)
    {
        $toplamRandevu = DB::table('randevular')->where('salon_id', $salonId)
            ->whereBetween('tarih', [$t1, $t2])->count();
        $toplamAdisyon = DB::table('adisyonlar')->where('salon_id', $salonId)
            ->whereBetween('created_at', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])->count();
        $satilanUrun = DB::table('adisyon_urunler')
            ->join('adisyonlar', 'adisyonlar.id', '=', 'adisyon_urunler.adisyon_id')
            ->where('adisyonlar.salon_id', $salonId)
            ->whereBetween('adisyonlar.created_at', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])->count();
        $uygulananHizmet = DB::table('adisyon_hizmetler')
            ->join('adisyonlar', 'adisyonlar.id', '=', 'adisyon_hizmetler.adisyon_id')
            ->where('adisyonlar.salon_id', $salonId)
            ->whereBetween('adisyonlar.created_at', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])->count();

        $rows = DB::table('tahsilatlar')
            ->leftJoin('odeme_yontemleri', 'tahsilatlar.odeme_yontemi_id', '=', 'odeme_yontemleri.id')
            ->where('tahsilatlar.salon_id', $salonId)
            ->whereBetween('tahsilatlar.odeme_tarihi', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])
            ->select('odeme_yontemleri.odeme_yontemi as yontem', DB::raw('SUM(tahsilatlar.tutar) as toplam'))
            ->groupBy('odeme_yontemleri.odeme_yontemi')->get();
        $k = $this->tahsilatKirilim($rows);

        return [
            'toplam_randevu' => $toplamRandevu,
            'toplam_adisyon' => $toplamAdisyon,
            'satilan_urun' => $satilanUrun,
            'uygulanan_hizmet' => $uygulananHizmet,
            'toplam_gelir' => $k['toplam'],
            'nakit' => $k['nakit'],
            'kart' => $k['kart'],
            'havale' => $k['havale'],
            'diger' => $k['diger'],
        ];
    }

    /** Bugunku randevular (dashboardBugun ile ayni; telefon KVKK icin cekilmez). */
    public function bugun($salonId)
    {
        $rows = DB::table('randevular')
            ->leftJoin('users', 'randevular.user_id', '=', 'users.id')
            ->leftJoin('randevu_hizmetler', 'randevu_hizmetler.randevu_id', '=', 'randevular.id')
            ->leftJoin('hizmetler', 'randevu_hizmetler.hizmet_id', '=', 'hizmetler.id')
            ->leftJoin('salon_personelleri', 'randevu_hizmetler.personel_id', '=', 'salon_personelleri.id')
            ->where('randevular.salon_id', $salonId)
            ->where('randevular.tarih', date('Y-m-d'))
            ->select(
                'randevular.saat', 'randevular.durum',
                'users.name as musteri',
                'hizmetler.hizmet_adi as hizmet',
                'salon_personelleri.personel_adi as personel'
            )
            ->orderBy('randevular.saat')->limit(50)->get();

        return ['liste' => $this->objToArr($rows)];
    }

    /** Aktif salon personelleri (id + ad) — degerlendirme icin isim eslestirme. */
    public function personelListesi($salonId)
    {
        return DB::table('salon_personelleri')->where('salon_id', $salonId)->where('aktif', 1)
            ->select('id', 'personel_adi')->get();
    }

    /**
     * Bir personelin belirli araliktaki DETAYLI performans verisi (yeni personel
     * degerlendirmesi icin). ciro/islem/musteri/aktif gun, en cok hizmetler, iptal/
     * gelmedi, salon kisi-basi ortalama ve ilk/ikinci yari trendi.
     */
    public function personelDetay($salonId, $personelId, $t1, $t2)
    {
        $ozet = DB::table('adisyon_hizmetler as ah')
            ->join('adisyonlar as a', 'a.id', '=', 'ah.adisyon_id')
            ->where('a.salon_id', $salonId)
            ->where('ah.personel_id', $personelId)
            ->whereBetween('a.created_at', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])
            ->select(
                DB::raw('COUNT(*) as islem'),
                DB::raw('SUM(ah.fiyat - COALESCE(ah.indirim_tutari,0)) as ciro'),
                DB::raw('COUNT(DISTINCT a.user_id) as musteri'),
                DB::raw('COUNT(DISTINCT DATE(a.created_at)) as aktif_gun')
            )->first();

        $hizmetler = DB::table('adisyon_hizmetler as ah')
            ->join('adisyonlar as a', 'a.id', '=', 'ah.adisyon_id')
            ->join('hizmetler as h', 'h.id', '=', 'ah.hizmet_id')
            ->where('a.salon_id', $salonId)->where('ah.personel_id', $personelId)
            ->whereBetween('a.created_at', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])
            ->select('h.hizmet_adi', DB::raw('COUNT(*) as adet'))
            ->groupBy('h.hizmet_adi')->orderByDesc('adet')->limit(3)->get();

        $iptal = (int) DB::table('randevu_hizmetler as rh')
            ->join('randevular as r', 'r.id', '=', 'rh.randevu_id')
            ->where('r.salon_id', $salonId)->where('rh.personel_id', $personelId)
            ->whereBetween('r.tarih', [$t1, $t2])->where('r.durum', '>=', 2)
            ->distinct()->count('r.id');
        $gelmedi = (int) DB::table('randevu_hizmetler as rh')
            ->join('randevular as r', 'r.id', '=', 'rh.randevu_id')
            ->where('r.salon_id', $salonId)->where('rh.personel_id', $personelId)
            ->whereBetween('r.tarih', [$t1, $t2])->where('r.randevuya_geldi', 0)
            ->distinct()->count('r.id');

        // Salon kisi-basi ortalama ciro (ayni donem, hizmeti olan personel sayisina bolunur).
        $salonCiro = (float) DB::table('adisyon_hizmetler as ah')
            ->join('adisyonlar as a', 'a.id', '=', 'ah.adisyon_id')
            ->where('a.salon_id', $salonId)->whereNotNull('ah.personel_id')
            ->whereBetween('a.created_at', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])
            ->sum(DB::raw('ah.fiyat - COALESCE(ah.indirim_tutari,0)'));
        $persSay = (int) DB::table('adisyon_hizmetler as ah')
            ->join('adisyonlar as a', 'a.id', '=', 'ah.adisyon_id')
            ->where('a.salon_id', $salonId)->whereNotNull('ah.personel_id')
            ->whereBetween('a.created_at', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])
            ->distinct()->count('ah.personel_id');
        $ortalama = $persSay > 0 ? $salonCiro / $persSay : 0;

        // Trend: araligi ikiye bol, ilk yari vs ikinci yari ciro.
        $gunSay = max(1, (int) round((strtotime($t2) - strtotime($t1)) / 86400) + 1);
        $mid = date('Y-m-d', strtotime($t1) + (int) floor($gunSay / 2) * 86400);
        $midOnce = date('Y-m-d', strtotime($mid) - 86400);
        $ilkYari = (float) DB::table('adisyon_hizmetler as ah')
            ->join('adisyonlar as a', 'a.id', '=', 'ah.adisyon_id')
            ->where('a.salon_id', $salonId)->where('ah.personel_id', $personelId)
            ->whereBetween('a.created_at', [$t1 . ' 00:00:00', $midOnce . ' 23:59:59'])
            ->sum(DB::raw('ah.fiyat - COALESCE(ah.indirim_tutari,0)'));
        $ikinciYari = (float) DB::table('adisyon_hizmetler as ah')
            ->join('adisyonlar as a', 'a.id', '=', 'ah.adisyon_id')
            ->where('a.salon_id', $salonId)->where('ah.personel_id', $personelId)
            ->whereBetween('a.created_at', [$mid . ' 00:00:00', $t2 . ' 23:59:59'])
            ->sum(DB::raw('ah.fiyat - COALESCE(ah.indirim_tutari,0)'));

        return [
            'ciro'           => (float) ($ozet->ciro ?? 0),
            'islem'          => (int) ($ozet->islem ?? 0),
            'musteri'        => (int) ($ozet->musteri ?? 0),
            'aktif_gun'      => (int) ($ozet->aktif_gun ?? 0),
            'hizmetler'      => $this->objToArr($hizmetler),
            'iptal'          => $iptal,
            'gelmedi'        => $gelmedi,
            'salon_ortalama' => (float) $ortalama,
            'ilk_yari'       => $ilkYari,
            'ikinci_yari'    => $ikinciYari,
        ];
    }

    /**
     * Randevu durum ozeti: iptal + gelmeyen (no-show) sayilari ve en cok iptal/gelmedigi
     * olan personel. randevular.durum>=2 -> iptal (2=salon,3=musteri); randevuya_geldi=0
     * -> gelmedi (NULL=isaretlenmemis, 1=geldi). Personel kirilimi randevu_hizmetler ile.
     */
    public function randevuDurum($salonId, $t1, $t2)
    {
        $iptal = DB::table('randevular')->where('salon_id', $salonId)
            ->whereBetween('tarih', [$t1, $t2])->where('durum', '>=', 2)->count();

        $gelmedi = DB::table('randevular')->where('salon_id', $salonId)
            ->whereBetween('tarih', [$t1, $t2])->where('randevuya_geldi', 0)->count();

        $toplam = DB::table('randevular')->where('salon_id', $salonId)
            ->whereBetween('tarih', [$t1, $t2])->count();

        return [
            'iptal'            => $iptal,
            'gelmedi'          => $gelmedi,
            'toplam'           => $toplam,
            'iptal_personel'   => $this->durumPersonelEnCok($salonId, $t1, $t2, 'iptal'),
            'gelmedi_personel' => $this->durumPersonelEnCok($salonId, $t1, $t2, 'gelmedi'),
        ];
    }

    /** Belirtilen durumda (iptal|gelmedi) en cok randevusu olan personel; yoksa null. */
    protected function durumPersonelEnCok($salonId, $t1, $t2, $tur)
    {
        $q = DB::table('randevu_hizmetler as rh')
            ->join('randevular as r', 'r.id', '=', 'rh.randevu_id')
            ->join('salon_personelleri as sp', 'sp.id', '=', 'rh.personel_id')
            ->where('r.salon_id', $salonId)
            ->whereBetween('r.tarih', [$t1, $t2])
            ->whereNotNull('rh.personel_id');
        if ($tur === 'iptal') {
            $q->where('r.durum', '>=', 2);
        } else {
            $q->where('r.randevuya_geldi', 0);
        }
        $row = $q->select('sp.personel_adi', DB::raw('COUNT(DISTINCT r.id) as adet'))
            ->groupBy('rh.personel_id', 'sp.personel_adi')
            ->orderByDesc('adet')->limit(1)->first();

        if (!$row || (int) $row->adet <= 0) return null;
        return ['personel_adi' => $row->personel_adi, 'adet' => (int) $row->adet];
    }

    /**
     * KARSILASTIRMA icin tek donemin ozet metrikleri (kasa/satis/hizmet/urun/randevu/
     * iptal/gelmedi/yeni musteri). Iki donem cagirilip kiyaslanir. AI YOK.
     */
    public function karsilastirmaVeri($salonId, $t1, $t2)
    {
        $gelir = (float) DB::table('tahsilatlar')->where('salon_id', $salonId)
            ->whereBetween('odeme_tarihi', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])->sum('tutar');

        $randevu = DB::table('randevular')->where('salon_id', $salonId)
            ->whereBetween('tarih', [$t1, $t2])->count();
        $iptal = DB::table('randevular')->where('salon_id', $salonId)
            ->whereBetween('tarih', [$t1, $t2])->where('durum', '>=', 2)->count();
        $gelmedi = DB::table('randevular')->where('salon_id', $salonId)
            ->whereBetween('tarih', [$t1, $t2])->where('randevuya_geldi', 0)->count();

        $adisyon = DB::table('adisyonlar')->where('salon_id', $salonId)
            ->whereBetween('created_at', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])->count();

        $hizmet = DB::table('adisyon_hizmetler as ah')
            ->join('adisyonlar as a', 'a.id', '=', 'ah.adisyon_id')
            ->where('a.salon_id', $salonId)
            ->whereBetween('a.created_at', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])
            ->selectRaw('COUNT(*) as adet, SUM(ah.fiyat - COALESCE(ah.indirim_tutari,0)) as ciro')->first();

        $urun = DB::table('adisyon_urunler as au')
            ->join('adisyonlar as a', 'a.id', '=', 'au.adisyon_id')
            ->where('a.salon_id', $salonId)
            ->whereBetween('a.created_at', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])
            ->selectRaw('SUM(COALESCE(au.adet,1)) as adet, SUM(au.fiyat - COALESCE(au.indirim_tutari,0)) as ciro')->first();

        $yeni = 0;
        if (Schema::hasTable('musteri_portfoy')) {
            $yeni = DB::table('musteri_portfoy')->where('salon_id', $salonId)
                ->whereBetween('created_at', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])->count();
        }

        return [
            'gelir'        => $gelir,
            'randevu'      => $randevu,
            'iptal'        => $iptal,
            'gelmedi'      => $gelmedi,
            'adisyon'      => $adisyon,
            'hizmet_ciro'  => (float) ($hizmet->ciro ?? 0),
            'hizmet_adet'  => (int) ($hizmet->adet ?? 0),
            'urun_ciro'    => (float) ($urun->ciro ?? 0),
            'urun_adet'    => (int) ($urun->adet ?? 0),
            'yeni_musteri' => $yeni,
        ];
    }

    /**
     * PERSONEL iki-donem karsilastirmasi: her aktif personelin bu vs onceki donem
     * cirosu/islemi. bu ciroya gore sirali; iki donemde de hareketsiz olan atlanir.
     */
    public function personelKarsilastir($salonId, $buT1, $buT2, $onT1, $onT2)
    {
        $bu = $this->personelDonemCiro($salonId, $buT1, $buT2);
        $on = $this->personelDonemCiro($salonId, $onT1, $onT2);

        $personeller = DB::table('salon_personelleri')
            ->where('salon_id', $salonId)->where('aktif', 1)
            ->select('id', 'personel_adi')->get();

        $out = [];
        foreach ($personeller as $p) {
            $b = $bu[$p->id] ?? null;
            $o = $on[$p->id] ?? null;
            $bc = $b ? (float) $b->ciro : 0;
            $oc = $o ? (float) $o->ciro : 0;
            if ($bc <= 0 && $oc <= 0) continue; // iki donemde de hareket yoksa gosterme
            $out[] = [
                'id'       => $p->id,
                'ad'       => $p->personel_adi,
                'bu_ciro'  => $bc,
                'on_ciro'  => $oc,
                'bu_islem' => $b ? (int) $b->islem : 0,
                'on_islem' => $o ? (int) $o->islem : 0,
            ];
        }
        usort($out, function ($a, $b) { return $b['bu_ciro'] <=> $a['bu_ciro']; });
        return $out;
    }

    /** Bir donemde personel_id -> {ciro, islem} (adisyon_hizmetler). */
    protected function personelDonemCiro($salonId, $t1, $t2)
    {
        return DB::table('adisyon_hizmetler as ah')
            ->join('adisyonlar as a', 'a.id', '=', 'ah.adisyon_id')
            ->where('a.salon_id', $salonId)->whereNotNull('ah.personel_id')
            ->whereBetween('a.created_at', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])
            ->select('ah.personel_id',
                DB::raw('COUNT(*) as islem'),
                DB::raw('SUM(ah.fiyat - COALESCE(ah.indirim_tutari,0)) as ciro'))
            ->groupBy('ah.personel_id')->get()->keyBy('personel_id');
    }

    /** URUN iki-donem karsilastirmasi: her urunun bu vs onceki ciro/adet. */
    public function urunKarsilastir($salonId, $buT1, $buT2, $onT1, $onT2)
    {
        return $this->kalemBirlestir($this->urunDonem($salonId, $buT1, $buT2), $this->urunDonem($salonId, $onT1, $onT2));
    }

    protected function urunDonem($salonId, $t1, $t2)
    {
        return DB::table('adisyon_urunler as au')
            ->join('adisyonlar as a', 'a.id', '=', 'au.adisyon_id')
            ->leftJoin('urunler as u', 'u.id', '=', 'au.urun_id')
            ->where('a.salon_id', $salonId)
            ->whereBetween('a.created_at', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])
            ->select(DB::raw("COALESCE(u.urun_adi,'Silinmiş Ürün') as ad"),
                DB::raw('SUM(au.fiyat - COALESCE(au.indirim_tutari,0)) as ciro'),
                DB::raw('SUM(COALESCE(au.adet,1)) as adet'))
            ->groupBy('u.urun_adi')->get()->keyBy('ad');
    }

    /** HIZMET iki-donem karsilastirmasi: her hizmetin bu vs onceki ciro/adet. */
    public function hizmetKarsilastir($salonId, $buT1, $buT2, $onT1, $onT2)
    {
        return $this->kalemBirlestir($this->hizmetDonem($salonId, $buT1, $buT2), $this->hizmetDonem($salonId, $onT1, $onT2));
    }

    protected function hizmetDonem($salonId, $t1, $t2)
    {
        return DB::table('adisyon_hizmetler as ah')
            ->join('adisyonlar as a', 'a.id', '=', 'ah.adisyon_id')
            ->join('hizmetler as h', 'h.id', '=', 'ah.hizmet_id')
            ->where('a.salon_id', $salonId)
            ->whereBetween('a.created_at', [$t1 . ' 00:00:00', $t2 . ' 23:59:59'])
            ->select('h.hizmet_adi as ad',
                DB::raw('SUM(ah.fiyat - COALESCE(ah.indirim_tutari,0)) as ciro'),
                DB::raw('COUNT(*) as adet'))
            ->groupBy('h.hizmet_adi')->get()->keyBy('ad');
    }

    /** Iki donem kalem koleksiyonunu birlestir: [ad,bu_ciro,on_ciro,bu_adet,on_adet], sirali, ilk 15. */
    protected function kalemBirlestir($bu, $on)
    {
        $adlar = collect($bu->keys())->merge($on->keys())->unique();
        $out = [];
        foreach ($adlar as $ad) {
            $b = $bu[$ad] ?? null; $o = $on[$ad] ?? null;
            $bc = $b ? (float) $b->ciro : 0; $oc = $o ? (float) $o->ciro : 0;
            if ($bc <= 0 && $oc <= 0) continue;
            $out[] = [
                'ad' => $ad, 'bu_ciro' => $bc, 'on_ciro' => $oc,
                'bu_adet' => $b ? (int) $b->adet : 0, 'on_adet' => $o ? (int) $o->adet : 0,
            ];
        }
        usort($out, function ($a, $b) { return $b['bu_ciro'] <=> $a['bu_ciro']; });
        return array_slice($out, 0, 15);
    }

    /**
     * BILANCO (kar-zarar): son N ay icin GELIR/GIDER DOKUMU + aylik trend.
     * net = tahsilatlar - masraflar (web ile ayni). Gider: salon masrafi + personel
     * gideri (masraflar.personel_gideri). Gelir dokumu: nakit/kart/havale + hizmet/urun.
     */
    public function bilanco($salonId, $aySayisi = 3)
    {
        $aySayisi = max(1, min(24, (int) $aySayisi));
        $aylarTr = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz',
                    'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

        $ilkBas = date('Y-m-01', strtotime('-' . ($aySayisi - 1) . ' months'));
        $sonBit = date('Y-m-t');

        // --- Donem GELIR (tahsilat) + odeme yontemi kirilimi ---
        $tahRows = DB::table('tahsilatlar')
            ->leftJoin('odeme_yontemleri', 'tahsilatlar.odeme_yontemi_id', '=', 'odeme_yontemleri.id')
            ->where('tahsilatlar.salon_id', $salonId)
            ->whereBetween('tahsilatlar.odeme_tarihi', [$ilkBas . ' 00:00:00', $sonBit . ' 23:59:59'])
            ->select('odeme_yontemleri.odeme_yontemi as yontem', DB::raw('SUM(tahsilatlar.tutar) as toplam'))
            ->groupBy('odeme_yontemleri.odeme_yontemi')->get();
        $tah = $this->tahsilatKirilim($tahRows);

        // --- Satis dagilimi: hizmet / urun geliri ---
        $hizmetGeliri = (float) DB::table('adisyon_hizmetler as ah')
            ->join('adisyonlar as a', 'a.id', '=', 'ah.adisyon_id')
            ->where('a.salon_id', $salonId)
            ->whereBetween('a.created_at', [$ilkBas . ' 00:00:00', $sonBit . ' 23:59:59'])
            ->sum(DB::raw('ah.fiyat - COALESCE(ah.indirim_tutari,0)'));
        $urunGeliri = (float) DB::table('adisyon_urunler as au')
            ->join('adisyonlar as a', 'a.id', '=', 'au.adisyon_id')
            ->where('a.salon_id', $salonId)
            ->whereBetween('a.created_at', [$ilkBas . ' 00:00:00', $sonBit . ' 23:59:59'])
            ->sum(DB::raw('au.fiyat - COALESCE(au.indirim_tutari,0)'));

        // --- Gider: salon masrafi (personel_gideri=0/null) + personel gideri (=1) ---
        $personelGideri = (float) DB::table('masraflar')->where('salon_id', $salonId)
            ->whereBetween('tarih', [$ilkBas . ' 00:00:00', $sonBit . ' 23:59:59'])
            ->where('personel_gideri', 1)->sum('tutar');
        $toplamGider = (float) DB::table('masraflar')->where('salon_id', $salonId)
            ->whereBetween('tarih', [$ilkBas . ' 00:00:00', $sonBit . ' 23:59:59'])->sum('tutar');
        $salonMasrafi = $toplamGider - $personelGideri;

        $net = $tah['toplam'] - $toplamGider;
        $marj = $tah['toplam'] > 0 ? ($net / $tah['toplam']) * 100 : 0;

        // --- Aylik trend ---
        $aylar = [];
        for ($i = $aySayisi - 1; $i >= 0; $i--) {
            $bas = date('Y-m-01', strtotime("-$i months"));
            $bit = date('Y-m-t', strtotime($bas));
            $g = (float) DB::table('tahsilatlar')->where('salon_id', $salonId)
                ->whereBetween('odeme_tarihi', [$bas . ' 00:00:00', $bit . ' 23:59:59'])->sum('tutar');
            $m = (float) DB::table('masraflar')->where('salon_id', $salonId)
                ->whereBetween('tarih', [$bas . ' 00:00:00', $bit . ' 23:59:59'])->sum('tutar');
            $aylar[] = [
                'ay_adi' => $aylarTr[(int) date('n', strtotime($bas))] . ' ' . date('Y', strtotime($bas)),
                'gelir'  => $g, 'gider' => $m, 'net' => $g - $m,
            ];
        }

        return [
            'ay_sayisi'       => $aySayisi,
            'toplam_tahsilat' => $tah['toplam'],
            'nakit'           => $tah['nakit'],
            'kart'            => $tah['kart'],
            'havale'          => $tah['havale'],
            'diger'           => $tah['diger'],
            'hizmet_geliri'   => $hizmetGeliri,
            'urun_geliri'     => $urunGeliri,
            'salon_masrafi'   => $salonMasrafi,
            'personel_gideri' => $personelGideri,
            'toplam_gider'    => $toplamGider,
            'net'             => $net,
            'marj'            => $marj,
            'aylar'           => $aylar,
        ];
    }

    /** Salonun haftalik calisma saatleri (salon_calisma_saatleri). Gun yoksa = kapali. */
    public function calismaSaatleri($salonId)
    {
        if (!Schema::hasTable('salon_calisma_saatleri')) return [];
        $rows = DB::table('salon_calisma_saatleri')->where('salon_id', $salonId)
            ->orderBy('haftanin_gunu')->orderBy('baslangic_saati')->get();
        $out = [];
        foreach ($rows as $r) {
            $bas = $r->baslangic_saati ? substr((string) $r->baslangic_saati, 0, 5) : null;
            $bit = $r->bitis_saati ? substr((string) $r->bitis_saati, 0, 5) : null;
            if (!$bas || !$bit) continue;
            $out[] = ['gun' => (int) $r->haftanin_gunu, 'bas' => $bas, 'bit' => $bit];
        }
        return $out;
    }

    // ---- yardimcilar ----

    /** Tahsilat satirlarini nakit/kart/havale/diger/toplam kirilimina cevir (dashboardKasa mantigi). */
    protected function tahsilatKirilim($rows)
    {
        $nakit = 0; $kart = 0; $havale = 0; $diger = 0; $toplam = 0;
        foreach ($rows as $r) {
            $tutar = (float) $r->toplam;
            $toplam += $tutar;
            $yontem = mb_strtolower($r->yontem ?? '', 'UTF-8');
            if (strpos($yontem, 'nakit') !== false || strpos($yontem, 'cash') !== false) {
                $nakit += $tutar;
            } elseif (strpos($yontem, 'kart') !== false || strpos($yontem, 'kredi') !== false || strpos($yontem, 'pos') !== false || strpos($yontem, 'card') !== false) {
                $kart += $tutar;
            } elseif (strpos($yontem, 'havale') !== false || strpos($yontem, 'eft') !== false || strpos($yontem, 'transfer') !== false || strpos($yontem, 'iban') !== false) {
                $havale += $tutar;
            } else {
                $diger += $tutar;
            }
        }
        return ['nakit' => $nakit, 'kart' => $kart, 'havale' => $havale, 'diger' => $diger, 'toplam' => $toplam];
    }

    /** stdClass koleksiyonunu diziye cevir (cevap katmani dizi bekliyor). */
    protected function objToArr($rows)
    {
        $out = [];
        foreach ($rows as $r) { $out[] = (array) $r; }
        return $out;
    }
}
