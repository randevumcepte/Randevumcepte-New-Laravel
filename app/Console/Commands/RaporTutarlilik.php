<?php

namespace App\Console\Commands;

use App\Domain\Rapor\RaporDonemi;
use App\Domain\Satis\KalemTuru;
use App\Services\Kasa\GunSonuServisi;
use App\Services\Kasa\KasaRaporServisi;
use App\Services\Kasa\KasaTutar;
use App\Services\Rapor\IsletmeRaporServisi;
use App\Services\Rapor\SatisRaporServisi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * RAPOR TUTARLILIK testi — Satis Raporlari, Isletme Raporlari ve Kasa Raporu servislerini
 * GERCEK veri uzerinde birbirine ve BAGIMSIZ ham SQL'e karsi kurusuna kadar karsilastirir.
 * SALT OKUMA — hicbir sey yazmaz. Bir kontrol bile tutmazsa cikis kodu 1.
 *
 *   php artisan rapor:tutarlilik 20
 *   php artisan rapor:tutarlilik 20 432 --donem=bugun,ay,gecen_ay,yil
 *   php artisan rapor:tutarlilik 20 --donem=ozel --baslangic=2026-01-01 --bitis=2026-06-30
 *   php artisan rapor:tutarlilik 20 --sadece-hata
 *
 * Kontrol gruplari:
 *   SATIS  : sekme bolunmesi, satir aritmetigi, Satis ↔ Isletme raporu, bagimsiz SQL (net/tahsil),
 *            personel prim geliri, "alan musteriler" penceresi ↔ tablo satiri
 *   ISLETME: odeme kovalari, randevu durum kartlari, ozet ↔ randevu sekmesi
 *   KASA   : bagimsiz SQL (gelir/gider/tum zamanlar), sayfali tablolar ↔ kartlar, gun sonu ↔ kasa,
 *            aylik ↔ yil, odeme yontemi filtreleri ↔ toplam, Isletme tahsilati ↔ kasa geliri
 *   BILGI  : eski Satis Raporlari (brut, adisyonlar.tarih) ile fark — beklenen, hata sayilmaz
 */
class RaporTutarlilik extends Command
{
    protected $signature = 'rapor:tutarlilik
        {salon* : bir veya daha cok salon_id}
        {--donem=bugun,ay,gecen_ay,yil : virgullu donem listesi (RaporDonemi anahtarlari)}
        {--baslangic= : donem=ozel icin Y-m-d}
        {--bitis= : donem=ozel icin Y-m-d}
        {--sadece-hata : yalniz tutmayan kontrolleri yaz}';

    protected $description = 'Satis / Isletme / Kasa raporlarinin kendi aralarinda ve ham SQL ile tutarliligi (salt okuma)';

    private const TOLERANS = 0.01;

    private const HEPSI = ['page' => 1, 'per_page' => PHP_INT_MAX, 'ara' => null, 'sirala' => null, 'yon' => 'desc'];

    private array $sonuclar = [];

    public function __construct(
        private SatisRaporServisi $satis,
        private IsletmeRaporServisi $isletme,
        private KasaRaporServisi $kasa,
        private GunSonuServisi $gunSonu,
        private KasaTutar $tutar,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $hata = 0;
        foreach ((array) $this->argument('salon') as $salonId) {
            $salonId = (int) $salonId;
            $ad = DB::table('salonlar')->where('id', $salonId)->value('salon_adi');
            if (! $ad) {
                $this->error("Salon {$salonId} bulunamadı");
                $hata++;

                continue;
            }
            foreach (array_filter(array_map('trim', explode(',', (string) $this->option('donem')))) as $anahtar) {
                $d = RaporDonemi::coz($anahtar, $this->option('baslangic'), $this->option('bitis'));
                $this->sonuclar = [];
                $this->satisKontrolleri($salonId, $d);
                $this->isletmeKontrolleri($salonId, $d);
                $this->kasaKontrolleri($salonId, $d);
                $hata += $this->yazdir("#{$salonId} {$ad} — {$d->dizi()['etiket']} ({$d->baslangic} → {$d->bitis})");
            }
        }

        $hata === 0 ? $this->info('TÜM KONTROLLER TUTARLI.') : $this->error("{$hata} kontrol tutmadı.");

        return $hata === 0 ? self::SUCCESS : self::FAILURE;
    }

    // ───────────────────────────── SATIS ─────────────────────────────

    private function satisKontrolleri(int $salonId, RaporDonemi $d): void
    {
        $sekme = [];
        foreach (array_keys(SatisRaporServisi::SEKME_TURLERI) as $s) {
            $sekme[$s] = $this->satis->kalemler($salonId, $d, $s, null, self::HEPSI, false);
        }
        $tum = $sekme['tum']['ozet'];

        // Sekme bolunmesi: Tumu = Hizmet + Urun + Paket
        foreach (['gelir', 'indirim', 'kazanc', 'alacak', 'adet'] as $alan) {
            $this->esit("SATIŞ Tümü.$alan = Hizmet+Ürün+Paket", $tum[$alan],
                $sekme['hizmet']['ozet'][$alan] + $sekme['urun']['ozet'][$alan] + $sekme['paket']['ozet'][$alan]);
        }

        // Satir aritmetigi + ozet = satir toplami
        $bozuk = 0;
        foreach ($sekme['tum']['data'] as $r) {
            if (abs($r['gelir'] - ($r['brut'] - $r['indirim'])) > self::TOLERANS || abs($r['alacak'] - ($r['gelir'] - $r['kazanc'])) > self::TOLERANS) {
                $bozuk++;
            }
        }
        $this->esit('SATIŞ satır: gelir=brüt−indirim, alacak=gelir−tahsil', 0, $bozuk, 'satır');
        $this->esit('SATIŞ Tümü.gelir = Σ satır', $tum['gelir'], array_sum(array_column($sekme['tum']['data'], 'gelir')));
        $h = $sekme['hizmet'];
        $this->esit('SATIŞ Hizmet.kar = Σ (tahsil − maliyet)', (float) $h['ozet']['kar'], $h['ozet']['kazanc'] - (float) $h['ozet']['toplam_maliyet']);

        // Bagimsiz ham SQL (donem kurali farkli yazimla: sargable olmayan COALESCE)
        $ham = $this->hamSatis($salonId, $d);
        $this->esit('SATIŞ Tümü.gelir = ham SQL net', $tum['gelir'], $ham['net']);
        $this->esit('SATIŞ Tümü.indirim = ham SQL', $tum['indirim'], $ham['indirim']);
        $this->esit('SATIŞ Tümü.tahsil = ham SQL kalem tahsilatı', $tum['kazanc'], $ham['odenen']);
        $this->esit('SATIŞ Ürün.adet = ham SQL SUM(adet)', $sekme['urun']['ozet']['adet'], $ham['urun_adet'], 'adet');
        $this->esit('SATIŞ Paket.gelir = paket + seanslı hizmet (ham)', $sekme['paket']['ozet']['gelir'], $ham['paket_net']);

        // Satis ↔ Isletme raporu (ayni kaynak, farkli ekran)
        $oz = $this->isletme->ozet($salonId, $d);
        $ih = $this->isletme->kalemler($salonId, $d, KalemTuru::Hizmet);
        $iu = $this->isletme->kalemler($salonId, $d, KalemTuru::Urun);
        $this->esit('SATIŞ↔İŞLETME net satış', $tum['gelir'], $oz['satis_net']);
        $this->esit('SATIŞ↔İŞLETME hizmet cirosu', $h['ozet']['gelir'], $ih['toplam_ciro']);
        $this->esit('SATIŞ↔İŞLETME hizmet adedi', $h['ozet']['adet'], $ih['toplam_adet'], 'adet');
        $this->esit('SATIŞ↔İŞLETME ürün cirosu', $sekme['urun']['ozet']['gelir'], $iu['toplam_ciro']);
        $this->esit('SATIŞ↔İŞLETME ürün adedi (sekme = özet kartı)', $iu['toplam_adet'], $oz['satilan_urun'], 'adet');
        $this->esit('İŞLETME hizmet adedi = özet "Uygulanan Hizmet"', $ih['toplam_adet'], $oz['uygulanan_hizmet'], 'adet');

        // Personel: Isletme personel cirosu = personelli kalemlerin neti (aktif, arsivsiz)
        $ip = $this->isletme->personeller($salonId, $d);
        $this->esit('İŞLETME personel cirosu = ham SQL (aktif personel neti)', $ip['toplam_ciro'], $ham['personel_net']);
        $sp = $this->satis->personeller($salonId, $d, self::HEPSI);
        $this->esit('SATIŞ personel geliri = ham SQL (aktif personel tahsili)', $sp['ozet']['toplam_gelir'], $ham['personel_odenen']);
        $primBozuk = 0;
        foreach ($sp['data'] as $p) {
            if (abs($p['toplam_prim'] - ($p['hizmet_primi'] + $p['urun_primi'] + $p['paket_primi'])) > self::TOLERANS) {
                $primBozuk++;
            }
        }
        $this->esit('SATIŞ personel toplam prim = Σ kova primi', 0, $primBozuk, 'satır');

        // "Alan musteriler" penceresi ↔ tablo satiri (en buyuk 3 satir)
        $satirlar = $sekme['tum']['data'];
        usort($satirlar, fn ($a, $b) => $b['gelir'] <=> $a['gelir']);
        foreach (array_slice($satirlar, 0, 3) as $r) {
            $m = $this->satis->musteriler($salonId, $d, $r['tur'], $r['kalem_id'], null, ['sirala' => 'satis_tarihi'] + self::HEPSI, true, false);
            $this->esit("SATIŞ pencere↔satır gelir: {$r['ad']}", $r['gelir'], $m['ozet']['gelir']);
            $this->esit("SATIŞ pencere↔satır tahsil: {$r['ad']}", $r['kazanc'], $m['ozet']['odenen']);
        }

        // BILGI: eski Satis Raporlari (brut SUM(fiyat), yalniz adisyonlar.tarih)
        $eski = $this->eskiBrut($salonId, $d);
        $this->bilgi('BİLGİ eski Satış Raporları brüt (adisyonlar.tarih)', $eski, $tum['gelir'] + $tum['indirim'],
            'fark = tarihsiz/geçmişe dönük satışlar; v2 net = brüt − indirim');
    }

    // ───────────────────────────── ISLETME ─────────────────────────────

    private function isletmeKontrolleri(int $salonId, RaporDonemi $d): void
    {
        $oz = $this->isletme->ozet($salonId, $d);
        $this->esit('İŞLETME özet: tahsilat = Σ ödeme kovası', $oz['toplam_gelir'], array_sum($oz['odeme_yontemleri']));

        $r = $this->isletme->randevular($salonId, $d);
        $kartlar = $r['sonuclanan'] + $r['gelmedi'] + $r['onayli'] + $r['iptal'] + $r['bekleyen'];
        $this->esit('İŞLETME randevu: toplam = durum kartları', $r['toplam'], $kartlar, 'adet');
        $this->esit('İŞLETME randevu: toplam = Σ saat dağılımı', $r['toplam'], array_sum(array_column($r['saat_dagilim'], 'adet')), 'adet');
        $this->esit('İŞLETME randevu: toplam = Σ gün dağılımı', $r['toplam'], array_sum(array_column($r['gun_dagilim'], 'adet')), 'adet');
        $this->esit('İŞLETME özet randevu = randevu sekmesi', $oz['toplam_randevu'], $r['toplam'], 'adet');

        $m = $this->isletme->musteriler($salonId, $d, true);
        $this->esit('İŞLETME müşteri: aktif = cinsiyet toplamı', $m['toplam_aktif'], array_sum($m['cinsiyet']), 'adet');
        $this->esit('İŞLETME müşteri: tekrar ≤ aktif', 1, (int) ($m['tekrar_gelen'] <= $m['toplam_aktif']), 'bool');
    }

    // ───────────────────────────── KASA ─────────────────────────────

    private function kasaKontrolleri(int $salonId, RaporDonemi $d): void
    {
        $faturali = $this->tutar->faturaliMi($salonId);
        $oz = $this->kasa->ozet($salonId, $d, null);

        $hamGelir = (float) DB::selectOne('SELECT COALESCE(SUM(tutar),0) t FROM tahsilatlar WHERE salon_id=? AND odeme_tarihi BETWEEN ? AND ?',
            [$salonId, $d->basSaat(), $d->bitSaat()])->t;
        $hamGider = (float) DB::selectOne('SELECT COALESCE(SUM(tutar),0) t FROM masraflar WHERE salon_id=? AND tarih BETWEEN ? AND ?',
            [$salonId, $d->basSaat(), $d->bitSaat()])->t;
        $tumNet = (float) DB::selectOne('SELECT (SELECT COALESCE(SUM(tutar),0) FROM tahsilatlar WHERE salon_id=?) - (SELECT COALESCE(SUM(tutar),0) FROM masraflar WHERE salon_id=?) t',
            [$salonId, $salonId])->t;

        if ($faturali) {
            $this->bilgi('BİLGİ faturasız gizleme AÇIK — gelir yalnız faturalı kısım', $hamGelir, $oz['gelir'], 'ham SQL tüm tahsilat');
        } else {
            $this->esit('KASA gelir = ham SQL tahsilat', $oz['gelir'], $hamGelir);
            $this->esit('KASA toplam kazanç = ham SQL (tüm zamanlar)', $oz['toplam_kazanc'], $tumNet);
            $this->esit('KASA gelir = İŞLETME dönem tahsilatı', $oz['gelir'], $this->isletme->ozet($salonId, $d)['toplam_gelir']);
        }
        $this->esit('KASA gider = ham SQL masraf', $oz['gider'], $hamGider);
        $this->esit('KASA net = gelir − gider', $oz['net'], $oz['gelir'] - $oz['gider']);

        // Sayfali tablolar (100'luk sayfalarla gezilir) = kartlar
        [$gAdet, $gToplam] = $this->sayfalariTopla(fn ($o) => $this->kasa->gelirler($salonId, $d, null, $o, false));
        [$mAdet, $mToplam] = $this->sayfalariTopla(fn ($o) => $this->kasa->giderler($salonId, $d, null, $o, false));
        $this->esit('KASA Gelirler tablosu Σ = Gelir kartı', $oz['gelir'], $gToplam);
        $this->esit('KASA Giderler tablosu Σ = Gider kartı', $oz['gider'], $mToplam);
        $this->esit('KASA Giderler satır sayısı = ham SQL', (int) DB::table('masraflar')->where('salon_id', $salonId)
            ->whereBetween('tarih', [$d->basSaat(), $d->bitSaat()])->count(), $mAdet, 'adet');
        if (! $faturali) {
            $this->esit('KASA Gelirler satır sayısı = ham SQL', (int) DB::table('tahsilatlar')->where('salon_id', $salonId)
                ->whereBetween('odeme_tarihi', [$d->basSaat(), $d->bitSaat()])->count(), $gAdet, 'adet');
        }

        // Odeme yontemi filtreleri: her yontemin geliri toplami + yontemsiz = toplam
        $yontemler = DB::table('odeme_yontemleri')->pluck('id');
        $filtreli = $yontemler->sum(fn ($id) => $this->kasa->ozet($salonId, $d, (int) $id)['gelir']);
        $yontemsiz = $faturali ? 0.0 : (float) DB::table('tahsilatlar')->where('salon_id', $salonId)
            ->whereBetween('odeme_tarihi', [$d->basSaat(), $d->bitSaat()])
            ->where(fn ($q) => $q->whereNull('odeme_yontemi_id')->orWhereNotIn('odeme_yontemi_id', $yontemler))->sum('tutar');
        $this->esit('KASA Σ ödeme yöntemi filtresi (+yöntemsiz) = gelir', $oz['gelir'], $filtreli + $yontemsiz);

        // Gun sonu ↔ kasa (ayni donem)
        $gs = $this->gunSonu->rapor($salonId, $d);
        $this->esit('GÜN SONU gelir = KASA gelir', $oz['gelir'], $gs['gelir_toplam']);
        $this->esit('GÜN SONU masraf = KASA gider', $oz['gider'], $gs['masraf_toplam']);
        $this->esit('GÜN SONU net = KASA net', $oz['net'], $gs['net_toplam']);
        $this->esit('GÜN SONU Σ satışlar = gelir', $gs['gelir_toplam'], array_sum(array_column($gs['satislar'], 'tutar')));
        $this->esit('GÜN SONU işletme + personel gideri = masraf', $gs['masraf_toplam'], $gs['isletme_masraf_toplam'] + $gs['personel_gider_toplam']);
        $this->esit('GÜN SONU kova netleri = net', $gs['net_toplam'], array_sum($gs['net']));

        // Aylik ↔ yil (donem tam bir yil ise)
        if (in_array($d->anahtar, ['yil', 'gecen_yil'], true)) {
            $a = $this->kasa->aylik($salonId, (int) substr($d->baslangic, 0, 4));
            $this->esit('DEVREDEN AYLAR Σ gelir = yıllık kasa geliri', $oz['gelir'], array_sum(array_column($a['aylar'], 'gelir')));
            $this->esit('DEVREDEN AYLAR Σ gider = yıllık kasa gideri', $oz['gider'], array_sum(array_column($a['aylar'], 'gider')));
        }
    }

    // ───────────────────────────── Ham SQL ─────────────────────────────

    /** Satis toplamlarinin SatisKalemSorgusu'ndan BAGIMSIZ hesabi (donem: COALESCE ifadesiyle). */
    private function hamSatis(int $salonId, RaporDonemi $d): array
    {
        $tarih = "COALESCE(NULLIF(DATE(a.tarih),'0000-00-00'), DATE(a.created_at)) BETWEEN ? AND ?";
        $aktif = 'k.personel_id IN (SELECT id FROM salon_personelleri WHERE salon_id = a.salon_id AND aktif = 1 AND COALESCE(arsivli,0) = 0)';
        $s = ['net' => 0.0, 'indirim' => 0.0, 'odenen' => 0.0, 'urun_adet' => 0, 'paket_net' => 0.0, 'personel_net' => 0.0, 'personel_odenen' => 0.0];

        foreach (KalemTuru::cases() as $tur) {
            $seansli = $tur === KalemTuru::Hizmet ? KalemTuru::seansliSql('k') : '0';
            $odenen = 'COALESCE((SELECT SUM(x.tutar) FROM '.$tur->tahsilatTablosu().' x WHERE x.'.$tur->tahsilatFk().' = k.id),0)';
            $r = DB::selectOne("SELECT
                    COALESCE(SUM(COALESCE(k.fiyat,0) - COALESCE(k.indirim_tutari,0)),0) net,
                    COALESCE(SUM(COALESCE(k.indirim_tutari,0)),0) indirim,
                    COALESCE(SUM({$odenen}),0) odenen,
                    COALESCE(SUM(".($tur === KalemTuru::Urun ? 'COALESCE(k.adet,1)' : '0').'),0) urun_adet,
                    COALESCE(SUM(CASE WHEN '.($tur === KalemTuru::Paket ? '1=1' : $seansli)." THEN COALESCE(k.fiyat,0) - COALESCE(k.indirim_tutari,0) ELSE 0 END),0) paket_net,
                    COALESCE(SUM(CASE WHEN {$aktif} THEN COALESCE(k.fiyat,0) - COALESCE(k.indirim_tutari,0) ELSE 0 END),0) personel_net,
                    COALESCE(SUM(CASE WHEN {$aktif} THEN {$odenen} ELSE 0 END),0) personel_odenen
                FROM ".$tur->kalemTablosu()." k JOIN adisyonlar a ON a.id = k.adisyon_id
                WHERE a.salon_id = ? AND {$tarih}", [$salonId, $d->baslangic, $d->bitis]);
            foreach ($s as $k => $v) {
                $s[$k] = $v + (float) $r->{$k};
            }
        }

        return array_map(fn ($v) => round($v, 2), $s);
    }

    /** Eski web Satis Raporlari toplami: brut SUM(fiyat), yalniz adisyonlar.tarih araligi. */
    private function eskiBrut(int $salonId, RaporDonemi $d): float
    {
        $t = 0.0;
        foreach (KalemTuru::cases() as $tur) {
            $t += (float) DB::table($tur->kalemTablosu().' as k')->join('adisyonlar as a', 'a.id', '=', 'k.adisyon_id')
                ->where('a.salon_id', $salonId)->whereBetween('a.tarih', [$d->baslangic, $d->bitis])->sum('k.fiyat');
        }

        return round($t, 2);
    }

    /** @return array{0:int,1:float} sayfa sayfa gezilen satir sayisi + tutar toplami */
    private function sayfalariTopla(callable $sayfa): array
    {
        $adet = 0;
        $toplam = 0.0;
        $no = 1;
        do {
            $v = $sayfa(['page' => $no, 'per_page' => 100, 'ara' => null, 'sirala' => null, 'yon' => 'desc']);
            $adet += count($v['data']);
            $toplam += array_sum(array_column($v['data'], 'tutar'));
        } while ($no++ < $v['last_page']);

        return [$adet, round($toplam, 2)];
    }

    // ───────────────────────────── Cikti ─────────────────────────────

    private function esit(string $ad, $beklenen, $bulunan, string $birim = '₺'): void
    {
        $fark = round((float) $bulunan - (float) $beklenen, 2);
        $this->sonuclar[] = [$ad, $this->bicim($beklenen, $birim), $this->bicim($bulunan, $birim), $fark == 0 ? '' : $this->bicim($fark, $birim),
            abs($fark) <= self::TOLERANS ? '<info>TUTARLI</info>' : '<error>FARKLI</error>'];
    }

    private function bilgi(string $ad, $eski, $yeni, string $not): void
    {
        $this->sonuclar[] = [$ad, $this->bicim($eski), $this->bicim($yeni), $this->bicim(round($yeni - $eski, 2)), '<comment>BİLGİ</comment> '.$not];
    }

    private function bicim($n, string $birim = '₺'): string
    {
        return $birim === '₺' ? number_format((float) $n, 2, ',', '.').' ₺' : number_format((float) $n, 0, ',', '.').($birim === 'bool' ? '' : ' '.$birim);
    }

    /** Tabloyu yazar; tutmayan kontrol sayisini dondurur. */
    private function yazdir(string $baslik): int
    {
        $hatali = array_filter($this->sonuclar, fn ($s) => str_contains($s[4], 'FARKLI'));
        $satirlar = $this->option('sadece-hata') ? $hatali : $this->sonuclar;
        $this->line('');
        $this->line("<options=bold>{$baslik}</> — ".(count($this->sonuclar) - count($hatali)).'/'.count($this->sonuclar).' tutarlı');
        if ($satirlar) {
            $this->table(['Kontrol', 'Beklenen', 'Bulunan', 'Fark', 'Sonuç'], $satirlar);
        }

        return count($hatali);
    }
}
