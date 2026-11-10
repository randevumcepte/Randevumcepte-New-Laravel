<?php

namespace App\Services\Ozet;

use App\Domain\Ozet\MobilOzetHesap as Hesap;
use App\Exceptions\BulunamadiException;
use App\Services\Santral\SantralIstemcisi;
use App\Services\Sms\Bakiye\SmsBakiyeServisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\Sema;
use Illuminate\Support\Facades\DB;

/**
 * Mobil ana ekran ozeti — eski ApiController::ozetsayfasi cevabinin birebir karsiligi. Kimlik token'dan, salon
 * kapsamini controller denetler; personel kaydi ve rol istemciden alinmaz. Web'in ozet kartlari bu servisi kullanmaz.
 */
class MobilOzetServisi
{
    private const SANTRAL_LIMIT = 500;

    private const TUR_PAKET = 2;

    private const TUR_URUN = 3;

    private const KAPALI_GIZLE = 'personel.kapali_adisyon_gizle';

    public function __construct(
        private YetkiServisi $yetki,
        private SmsBakiyeServisi $sms,
        private SantralIstemcisi $santral,
    ) {}

    /** Salondaki personel kaydi: rol ve kapsam buradan okunur (eski Personeller::where(yetkili_id, salon)->first()). */
    public function personel(int $yetkiliId, int $salonId): object
    {
        return DB::table('salon_personelleri')->where('yetkili_id', $yetkiliId)->where('salon_id', $salonId)->first()
            ?? throw new BulunamadiException('Bu işletmede personel kaydınız bulunamadı');
    }

    public function ozet(int $yetkiliId, int $salonId): array
    {
        $personel = $this->personel($yetkiliId, $salonId);
        $rol = (int) $personel->role_id;
        $kapsam = $rol === Hesap::PERSONEL_ROLU ? (int) $personel->id : null;
        $bugun = date('Y-m-d');

        $puan = DB::table('salon_puanlar')->where('salon_id', $salonId)->whereBetween('puan', [1, 5]);
        $salon = DB::table('salonlar')->where('id', $salonId)->first();
        $waAktif = $salon && (int) $salon->whatsapp_aktif === 1;
        $randevu = $this->randevuSorgusu($salonId, $bugun, $kapsam);
        $kasa = $rol !== Hesap::KASA_GORMEYEN_ROL ? $this->kasa($yetkiliId, $salonId, $personel) : ['toplam_kasa' => '', 'kalan_tutar' => '', 'prim' => ''];
        $arama = $rol < Hesap::SANTRAL_ROL_SINIRI ? $this->aramalar($salonId, $bugun) : ['gelen_arama' => '0', 'giden_arama' => '0', 'cevapsiz_arama' => '0'];

        return [
            'randevu_sayisi'       => (clone $randevu)->select('randevu_hizmetler.randevu_id')->get()->count(),
            // Eski kod gruplu sorguda count() cagirir: sonuc ilk grubun satir sayisidir. Sayac degismesin diye korunur.
            'ongorusme_sayisi'     => (clone $randevu)->whereNotNull('on_gorusme_id')->count(),
            'urun_satislari'       => $this->satisSayisi($yetkiliId, $salonId, self::TUR_URUN, $bugun, $kapsam, $salon),
            'paket_satislari'      => $this->satisSayisi($yetkiliId, $salonId, self::TUR_PAKET, $bugun, $kapsam, $salon),
            'toplam_kasa'          => $kasa['toplam_kasa'],
            'kalan_tutar'          => $kasa['kalan_tutar'],
            'ajanda'               => $this->ajanda($salonId, (int) $personel->id, $bugun),
            'kalan_sms'            => $this->sms->kalan($salonId) ?? 0,
            'whatsapp_aktif'       => $waAktif ? 1 : 0,
            'whatsapp_bagli'       => $waAktif && $salon->whatsapp_durum === 'connected' ? 1 : 0,
            'whatsapp_numara'      => $salon->whatsapp_numara ?? null,
            'gelen_arama'          => $arama['gelen_arama'],
            'giden_arama'          => $arama['giden_arama'],
            'cevapsiz_arama'       => $arama['cevapsiz_arama'],
            'isletme_adi'          => $salon->salon_adi ?? null,
            'puan'                 => Hesap::puan((float) (clone $puan)->sum('puan'), (clone $puan)->count()),
            'okunmamisbildirimler' => DB::table('bildirimler')->where('salon_id', $salonId)->where('personel_id', $personel->id)->where('okundu', '0')->count(),
            'prim'                 => $kasa['prim'],
        ];
    }

    private function randevuSorgusu(int $salonId, string $bugun, ?int $kapsam)
    {
        return DB::table('randevular')
            ->join('randevu_hizmetler', 'randevu_hizmetler.randevu_id', '=', 'randevular.id')
            ->where('randevular.salon_id', $salonId)
            ->where('randevular.tarih', $bugun)
            ->where('randevular.durum', '<=', 1)
            ->where('randevular.user_id', '!=', 2012)
            ->when($kapsam, fn ($q) => $q->where('randevu_hizmetler.personel_id', $kapsam))
            ->groupBy('randevu_hizmetler.randevu_id');
    }

    /** Bu ayin tahsilati eksi masrafi, bu ayin alacagi ve kendi primi. */
    private function kasa(int $yetkiliId, int $salonId, object $personel): array
    {
        $personelRolu = (int) $personel->role_id === Hesap::PERSONEL_ROLU;
        $kapsam = $personelRolu ? (int) $personel->id : null;
        $ayBasi = date('Y-m-01');
        $bugun = date('Y-m-d');

        $toplam = Hesap::tahsilatToplami($this->tahsilatKalemleri($salonId, $ayBasi, $bugun, $kapsam), $personel);
        // Masraf salon geneli: personel rolunde dusulmez, yalniz kendi cirosu gorunur.
        $masraf = $personelRolu ? 0.0 : (float) DB::table('masraflar')->where('salon_id', $salonId)
            ->where('tarih', '>=', $ayBasi)->where('tarih', '<=', $bugun)->sum('tutar');

        $alacak = DB::table('alacaklar')->where('alacaklar.salon_id', $salonId)
            ->where('alacaklar.planlanan_odeme_tarihi', '>=', $ayBasi)
            ->where('alacaklar.planlanan_odeme_tarihi', '<=', $bugun);
        MobilKarsilastirmaServisi::alacakPersonelSuzgeci($alacak, $kapsam);

        $kasaGizli = ! $personelRolu && ! $this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, 'rapor.kasa');

        return [
            'toplam_kasa' => $kasaGizli ? Hesap::MASKE : Hesap::tutarMetni($toplam['tutar'] - $masraf),
            'kalan_tutar' => Hesap::tutarMetni((float) $alacak->sum('alacaklar.tutar')),
            'prim'        => Hesap::tutarMetni($toplam['prim']),
        ];
    }

    /** @return array<int,array{hizmet:list<object>,urun:list<object>,paket:list<object>}> */
    private function tahsilatKalemleri(int $salonId, string $bas, string $bit, ?int $kapsam): array
    {
        $kalemTablolari = [
            'hizmet' => ['tahsilat_hizmetler', 'adisyon_hizmetler', 'adisyon_hizmet_id'],
            'urun'   => ['tahsilat_urunler', 'adisyon_urunler', 'adisyon_urun_id'],
            'paket'  => ['tahsilat_paketler', 'adisyon_paketler', 'adisyon_paket_id'],
        ];

        $idler = DB::table('tahsilatlar')->where('salon_id', $salonId)
            ->where('odeme_tarihi', '>=', $bas)->where('odeme_tarihi', '<=', $bit)
            ->when($kapsam, fn ($q) => $q->where(function ($dis) use ($kapsam, $kalemTablolari) {
                foreach ($kalemTablolari as [$tablo, $kalem, $kolon]) {
                    $dis->orWhereExists(fn ($alt) => $alt->select(DB::raw(1))->from($tablo)
                        ->join($kalem, $kalem.'.id', '=', $tablo.'.'.$kolon)
                        ->whereColumn($tablo.'.tahsilat_id', 'tahsilatlar.id')
                        ->where($kalem.'.personel_id', $kapsam));
                }
            }))
            ->pluck('id')->all();

        $sonuc = array_fill_keys($idler, ['hizmet' => [], 'urun' => [], 'paket' => []]);
        foreach ($kalemTablolari as $tur => [$tablo, $kalem, $kolon]) {
            foreach (array_chunk($idler, 1000) as $parca) {
                $satirlar = DB::table($tablo)->leftJoin($kalem, $kalem.'.id', '=', $tablo.'.'.$kolon)
                    ->whereIn($tablo.'.tahsilat_id', $parca)
                    ->get([$tablo.'.*', $kalem.'.personel_id as kalem_personel_id']);
                foreach ($satirlar as $s) {
                    $sonuc[$s->tahsilat_id][$tur][] = $s;
                }
            }
        }

        return array_values($sonuc);
    }

    /** Eski adisyon_yukle(sayfala=false) liste adedi: fatura gizleme ve kapali adisyon yetkisi dahil. */
    private function satisSayisi(int $yetkiliId, int $salonId, int $tur, string $bugun, ?int $kapsam, ?object $salon): int
    {
        $personelKalemi = fn ($tablo) => fn ($q) => $q->select(DB::raw(1))->from($tablo)
            ->whereColumn($tablo.'.adisyon_id', 'adisyonlar.id')->where($tablo.'.personel_id', $kapsam);

        $q = DB::table('adisyonlar')->where('adisyonlar.salon_id', $salonId)
            ->whereBetween('adisyonlar.tarih', [$bugun.' 00:00:00', $bugun.' 23:59:59'])
            ->when($kapsam, fn ($q) => $q->where(fn ($d) => $d
                ->whereExists($personelKalemi('adisyon_hizmetler'))
                ->orWhereExists($personelKalemi('adisyon_urunler'))
                ->orWhereExists($personelKalemi('adisyon_paketler'))));

        if ($tur === self::TUR_URUN) {
            $q->whereExists(fn ($a) => $a->select(DB::raw(1))->from('adisyon_urunler')->whereColumn('adisyon_urunler.adisyon_id', 'adisyonlar.id'));
        } else {
            $q->where(fn ($d) => $d
                ->whereExists(fn ($a) => $a->select(DB::raw(1))->from('adisyon_paketler')->whereColumn('adisyon_paketler.adisyon_id', 'adisyonlar.id'))
                ->orWhereExists(fn ($a) => $a->select(DB::raw(1))->from('adisyon_hizmetler')->whereColumn('adisyon_hizmetler.adisyon_id', 'adisyonlar.id')
                    ->whereNull('randevu_id')->where('seans_sayisi', '>', 0)));
        }
        if ((bool) ($salon->faturasiz_gizle ?? false)) {
            $q->where('fatura_kesildi', 1);
        }
        if ($this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, self::KAPALI_GIZLE)) {
            $this->yalnizAcik($q, $kapsam, $salon);
        }

        return $q->count();
    }

    /** Studyoda acik/kapali odendi bayragindan, digerlerinde kalandan belirlenir (eski SQL). */
    private function yalnizAcik($q, ?int $kapsam, ?object $salon): void
    {
        if (Sema::kolonVar('adisyonlar', 'odendi') && (int) ($salon->studyo_modu ?? 0) === 1) {
            $q->whereRaw('COALESCE(adisyonlar.odendi,0) <> 1');

            return;
        }
        $p = $kapsam ? ' AND personel_id = ?' : '';
        $q->whereRaw("
            ROUND((
                COALESCE((SELECT SUM(COALESCE(fiyat,0) - COALESCE(indirim_tutari,0)) FROM adisyon_hizmetler WHERE adisyon_id = adisyonlar.id$p), 0) +
                COALESCE((SELECT SUM(COALESCE(fiyat,0) - COALESCE(indirim_tutari,0)) FROM adisyon_urunler WHERE adisyon_id = adisyonlar.id$p), 0) +
                COALESCE((SELECT SUM(COALESCE(fiyat,0) - COALESCE(indirim_tutari,0)) FROM adisyon_paketler WHERE adisyon_id = adisyonlar.id$p), 0)
            ) - (
                COALESCE((SELECT SUM(t.tutar) FROM tahsilat_hizmetler t WHERE t.adisyon_hizmet_id IN (SELECT id FROM adisyon_hizmetler WHERE adisyon_id = adisyonlar.id$p)), 0) +
                COALESCE((SELECT SUM(t.tutar) FROM tahsilat_urunler t WHERE t.adisyon_urun_id IN (SELECT id FROM adisyon_urunler WHERE adisyon_id = adisyonlar.id$p)), 0) +
                COALESCE((SELECT SUM(t.tutar) FROM tahsilat_paketler t WHERE t.adisyon_paket_id IN (SELECT id FROM adisyon_paketler WHERE adisyon_id = adisyonlar.id$p)), 0)
            ), 2) > 0
        ", $kapsam ? array_fill(0, 6, $kapsam) : []);
    }

    /** @return array{gelen_arama:int,giden_arama:int,cevapsiz_arama:int} santrala ulasilamazsa 0 */
    private function aramalar(int $salonId, string $bugun): array
    {
        $dahililer = DB::table('salon_personelleri')->where('salon_id', $salonId)->whereNotNull('dahili_no')->pluck('dahili_no')->map(fn ($d) => (string) $d)->all();
        $trunk = DB::table('sabit_numaralar')->where('salon_id', $salonId)->value('numara');

        return Hesap::aramaSayilari($this->santral->cdrListesi($dahililer, $trunk ? (string) $trunk : null, $bugun, $bugun, self::SANTRAL_LIMIT)['satirlar']);
    }

    /** Eski alanlar ve sira aynen: istemci aciklama HTML'ini de bekler. */
    private function ajanda(int $salonId, int $personelId, string $bugun)
    {
        return DB::table('ajanda')
            ->select(
                'ajanda.id as id',
                'ajanda.ajanda_baslik as title',
                'ajanda.ajanda_icerik as description',
                'ajanda.ajanda_hatirlatma_saat as ajanda_hatirlatama_saat',
                DB::raw("CASE WHEN ajanda.ajanda_hatirlatma=1 THEN '<i class=\'fa fa-check\' style=\'font-size:20px; color:green;margin-left:40px;\'> </i>' WHEN ajanda.ajanda_hatirlatma=0 THEN '<i class=\'fa fa-check\' style=\'font-size:20px; color:green; display:none; margin-left:40px;\'> </i>' END AS ajanda_hatirlatma"),
                DB::raw('CONCAT(ajanda_tarih," ",DATE_FORMAT(ajanda.ajanda_saat, "%H:%i")) as start'),
                DB::raw("CASE WHEN ajanda.ajanda_durum=1 THEN '<button class=\'btn btn-success btn-block\' style=\'line-height:5px\'>Okundu</button>' WHEN ajanda.ajanda_durum=0 THEN '<button class=\'btn btn-danger btn-block\' style=\'line-height:5px\'>Okunmadı</button>' END AS ajanda_durum"),
            )
            ->where('ajanda.aktif', true)
            ->where('ajanda.ajanda_olusturan', $personelId)
            ->where('ajanda.salon_id', $salonId)
            ->where('ajanda.ajanda_tarih', $bugun)
            ->orderBy('ajanda.ajanda_saat', 'asc')
            ->get();
    }
}
