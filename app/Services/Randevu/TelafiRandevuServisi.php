<?php

namespace App\Services\Randevu;

use App\Domain\Randevu\TelafiPrim;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Models\RandevuHizmetler;
use App\Models\Randevular;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TELAFI RANDEVUSU (A tipi): ucretsiz duzeltme/garanti randevusu olustur ve
 * personel degistiyse primi devret.
 *
 * Eski App\Services\TelafiRandevuServisi (web + v1 mobil ortak) ile AYNI is
 * kurali; para hesabi saf App\Domain\Randevu\TelafiPrim'de (unit-test'li).
 * Gecis donemi notu: eski web/v1 kendi kopyasini kullanir; v1 uclari
 * emekliye ayrilinca (Faz 4) tek kaynak bu servis olur — tahsilat ile ayni yol.
 *
 * Eski davranistan BILINCLI farklar:
 *   - Orijinal kalem lockForUpdate ile kilitlenir, bayraklar transaction icinde
 *     yeniden kontrol edilir → cift tiklama iki telafi olusturamaz.
 *   - Secilen personel/oda/cihaz AYNI salona ait olmali (eski kod denetlemiyordu).
 *   - DRY-RUN plani (prim devri dahil) yazmadan once gorulebilir.
 *   - Islem aktivite loguna yazilir.
 */
class TelafiRandevuServisi
{
    /** Kapsam denetimi icin: kalemin (randevu_hizmetler) salonu. */
    public function salonIdGetir(int $rhId): ?int
    {
        $v = DB::table('randevu_hizmetler as rh')->join('randevular as r', 'r.id', '=', 'rh.randevu_id')
            ->where('rh.id', $rhId)->value('r.salon_id');

        return $v !== null ? (int) $v : null;
    }

    /** Telafi formu secenekleri — eski telafiDropdownVerisiApi ile ayni alanlar. */
    public function secenekler(int $salonId): array
    {
        return [
            'personeller' => DB::table('salon_personelleri')->where('salon_id', $salonId)->where('aktif', 1)
                ->orderBy('personel_adi')->get(['id', 'personel_adi as ad']),
            'odalar'      => DB::table('odalar')->where('salon_id', $salonId)->orderBy('oda_adi')->get(['id', 'oda_adi as ad']),
            'cihazlar'    => DB::table('cihazlar')->where('salon_id', $salonId)->orderBy('cihaz_adi')->get(['id', 'cihaz_adi as ad']),
        ];
    }

    /**
     * @param  array{tarih:string,saat:string,personel_id?:?int,oda_id?:?int,cihaz_id?:?int,sure_dk?:?int,not?:?string}  $g
     */
    public function olustur(int $origRhId, array $g, ?int $yetkiliId, bool $yaz): array
    {
        $orig = $this->origKalem($origRhId, false);
        $salonId = (int) $orig->salon_id;

        $tarih = trim((string) ($g['tarih'] ?? ''));
        $saat = substr(trim((string) ($g['saat'] ?? '')), 0, 5);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih)) {
            throw new GecersizIslemException('Tarih seçilmedi veya geçersiz.');
        }
        if (! preg_match('/^\d{2}:\d{2}$/', $saat)) {
            throw new GecersizIslemException('Saat seçilmedi veya geçersiz formatta.');
        }

        $yeniPersonelId = (int) ($g['personel_id'] ?? 0) ?: (int) $orig->personel_id;
        $odaId = (int) ($g['oda_id'] ?? 0) ?: null;
        $cihazId = (int) ($g['cihaz_id'] ?? 0) ?: null;
        $this->salonaAitMi($salonId, $yeniPersonelId, $odaId, $cihazId);

        $sure = (int) ($g['sure_dk'] ?? 0) ?: ((int) $orig->sure_dk ?: 15);
        $saatBitis = date('H:i', strtotime('+'.$sure.' minutes', strtotime($saat)));
        $not = trim((string) ($g['not'] ?? ''));

        $prim = $this->primPlani($orig, $yeniPersonelId);
        $plan = [
            'yeni_randevu' => [
                'tarih' => $tarih, 'saat' => $saat, 'saat_bitis' => $saatBitis, 'sure_dk' => $sure,
                'hizmet_id' => (int) $orig->hizmet_id, 'personel_id' => $yeniPersonelId,
                'oda_id' => $odaId, 'cihaz_id' => $cihazId, 'fiyat' => 0,
            ],
            'prim' => $prim,
        ];

        if (! $yaz) {
            return ['ok' => true, 'yazildi' => false, 'mesaj' => 'DRY-RUN', 'plan' => $plan, 'prim' => $prim['ozet']];
        }

        $yeniRh = DB::transaction(function () use ($origRhId, $tarih, $saat, $saatBitis, $sure, $yeniPersonelId, $odaId, $cihazId, $not, $yetkiliId, $salonId) {
            // Kilit + bayraklari YENIDEN denetle (es zamanli ikinci istek ayni telafiyi acamasin).
            $orig = $this->origKalem($origRhId, true);
            $prim = $this->primPlani($orig, $yeniPersonelId); // kilit altinda taze plan

            $randevuId = DB::table('randevular')->insertGetId([
                'user_id'               => $orig->user_id,
                'salon_id'              => $salonId,
                'tarih'                 => $tarih,
                'saat'                  => $saat,
                'durum'                 => Randevular::ONAYLANDI,
                'salon'                 => 1,
                'sms_hatirlatma'        => $orig->sms_hatirlatma,
                'olusturan_personel_id' => $yetkiliId, // eski servisle ayni (yetkili id)
                'personel_notu'         => trim(($not !== '' ? $not.' ' : '').'[Telafi: orijinal #'.$orig->randevu_id.']'),
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);

            $rhId = DB::table('randevu_hizmetler')->insertGetId([
                'randevu_id'          => $randevuId,
                'hizmet_id'           => $orig->hizmet_id,
                'personel_id'         => $yeniPersonelId,
                'cihaz_id'            => $cihazId,
                'oda_id'              => $odaId,
                'sure_dk'             => $sure,
                'fiyat'               => 0, // ucretsiz telafi
                'saat'                => $saat,
                'saat_bitis'          => $saatBitis,
                'telafi_kaynak_rh_id' => $orig->id,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            $this->primUygula($prim, $orig, $randevuId, $yeniPersonelId, $yetkiliId, $salonId);

            DB::table('randevu_hizmetler')->where('id', $orig->id)->update(['telafi_devir_yapildi' => 1, 'updated_at' => now()]);

            return ['randevu_id' => $randevuId, 'randevu_hizmet_id' => $rhId, 'prim' => $prim];
        });

        AktiviteLog::yaz($salonId, $yetkiliId, 'randevu_telafi_olustur', 'randevu', $yeniRh['randevu_id'],
            DB::table('users')->where('id', $orig->user_id)->value('name'),
            'Telafi randevusu oluşturuldu (orijinal #'.$orig->randevu_id.')', ['orijinal_rh_id' => $orig->id]);

        // Eski mobil yanit alanlari korunur (ok, randevu_id, randevu_hizmet_id, prim).
        return [
            'ok'                => true,
            'yazildi'           => true,
            'mesaj'             => 'Telafi randevusu oluşturuldu',
            'randevu_id'        => $yeniRh['randevu_id'],
            'randevu_hizmet_id' => $yeniRh['randevu_hizmet_id'],
            'prim'              => $yeniRh['prim']['ozet'],
            'plan'              => ['prim' => $yeniRh['prim']],
        ];
    }

    // ------------------------------------------------------------------

    private function origKalem(int $rhId, bool $kilit): object
    {
        $q = DB::table('randevu_hizmetler as rh')->join('randevular as r', 'r.id', '=', 'rh.randevu_id')
            ->where('rh.id', $rhId)
            ->select('rh.*', 'r.user_id', 'r.salon_id', 'r.tarih as randevu_tarih', 'r.sms_hatirlatma');
        if ($kilit) {
            $q->lockForUpdate();
        }
        $o = $q->first();
        if (! $o) {
            throw new BulunamadiException('Orijinal randevu kaydı bulunamadı.');
        }
        if ($o->telafi_kaynak_rh_id) {
            throw new GecersizIslemException('Bu randevu zaten bir telafi randevusu.');
        }
        if ($o->telafi_devir_yapildi) {
            throw new GecersizIslemException('Bu randevu için zaten telafi oluşturulmuş.');
        }

        return $o;
    }

    private function salonaAitMi(int $salonId, int $personelId, ?int $odaId, ?int $cihazId): void
    {
        if (! DB::table('salon_personelleri')->where('id', $personelId)->where('salon_id', $salonId)->exists()) {
            throw new GecersizIslemException('Seçilen personel bu işletmeye ait değil.');
        }
        if ($odaId && ! DB::table('odalar')->where('id', $odaId)->where('salon_id', $salonId)->exists()) {
            throw new GecersizIslemException('Seçilen oda bu işletmeye ait değil.');
        }
        if ($cihazId && ! DB::table('cihazlar')->where('id', $cihazId)->where('salon_id', $salonId)->exists()) {
            throw new GecersizIslemException('Seçilen cihaz bu işletmeye ait değil.');
        }
    }

    /**
     * Prim devri PLANI (yazmaz). Eski primTransfer'in kayit secimi BIREBIR:
     * YOL 1 satis kalemleri (randevu_id → fallback A randevusuz → fallback B tarih ±),
     * YOL 2 paket seanslari (APS → adisyon_paketler).
     */
    private function primPlani(object $orig, int $yeniPersonelId): array
    {
        $ozet = ['ah' => 0, 'aps' => 0, 'hareket' => 0, 'adisyon_degisti' => 0, 'uyari' => null];
        $bos = ['satis_devri' => [], 'paket_devri' => [], 'geri_devir' => [], 'ozet' => $ozet];
        $origPersonelId = (int) $orig->personel_id;
        if (! $yeniPersonelId || $yeniPersonelId === $origPersonelId) {
            return $bos; // ayni personel → devir yok
        }

        $salonId = (int) $orig->salon_id;
        $musteriId = (int) ($orig->user_id ?? 0);
        $donemOdenmis = [];
        $primOdenmisMi = function (string $donem) use (&$donemOdenmis, $origPersonelId, $salonId): bool {
            return $donemOdenmis[$donem] ??= ((float) DB::table('personel_maas_odemeleri')
                ->where('personel_id', $origPersonelId)->where('salon_id', $salonId)
                ->where('odeme_tipi', 'prim')->where('donem', $donem)->sum('tutar')) > 0;
        };

        // YOL 1 — tek seferlik satis kalemleri
        // leftJoin: adisyonu silinmis kalem de ana sorguda kapsanir (eski Eloquent sorgusu gibi);
        // fallback'lerde a.* kosullari zaten inner etkisi yapar (eski whereHas).
        $ahSec = fn () => DB::table('adisyon_hizmetler as ah')->leftJoin('adisyonlar as a', 'a.id', '=', 'ah.adisyon_id')
            ->select('ah.id', 'ah.hizmet_id', 'a.tarih as adisyon_tarih');
        $ahlar = $ahSec()->where('ah.randevu_id', $orig->randevu_id)->where('ah.hizmet_id', $orig->hizmet_id)->get();
        if ($ahlar->isEmpty() && $musteriId > 0) { // fallback A: randevuya baglanmamis satir
            $ahlar = $ahSec()->where('a.user_id', $musteriId)->where('a.salon_id', $salonId)
                ->where('ah.hizmet_id', $orig->hizmet_id)->where('ah.personel_id', $origPersonelId)
                ->whereNull('ah.randevu_id')->get();
        }
        if ($ahlar->isEmpty() && $musteriId > 0) { // fallback B: tarih penceresi (−7/+1 gun)
            $q = $ahSec()->where('a.user_id', $musteriId)->where('a.salon_id', $salonId)
                ->where('ah.hizmet_id', $orig->hizmet_id)->where('ah.personel_id', $origPersonelId);
            if ($orig->randevu_tarih) {
                $q->whereBetween('a.tarih', [
                    date('Y-m-d', strtotime($orig->randevu_tarih.' -7 days')),
                    date('Y-m-d', strtotime($orig->randevu_tarih.' +1 days')),
                ]);
            }
            $ahlar = $q->get();
        }

        $satis = [];
        $geri = [];
        foreach ($ahlar as $ah) {
            $satis[] = (int) $ah->id;
            $odenen = (float) DB::table('tahsilat_hizmetler')->where('adisyon_hizmet_id', $ah->id)->sum('tutar');
            $donem = substr((string) ($ah->adisyon_tarih ?: date('Y-m-d')), 0, 7);
            $tutar = TelafiPrim::hizmetGeriDevir($odenen, $this->primYuzdesi($origPersonelId, (int) $ah->hizmet_id), $primOdenmisMi($donem));
            if ($tutar > 0) {
                $geri[] = ['personel_id' => $origPersonelId, 'tutar' => -$tutar, 'tur' => 'hizmet', 'kaynak_adisyon_hizmet_id' => (int) $ah->id];
            }
        }

        // YOL 2 — paket seanslari
        $paket = [];
        $apslar = DB::table('adisyon_paket_seanslar as s')
            ->join('adisyon_paketler as ap', 'ap.id', '=', 's.adisyon_paket_id')
            ->leftJoin('adisyonlar as a', 'a.id', '=', 'ap.adisyon_id')
            ->where('s.randevu_id', $orig->randevu_id)->where('s.hizmet_id', $orig->hizmet_id)
            ->get(['s.id', 'ap.id as ap_id', 'ap.seans_sayisi', 'a.tarih as adisyon_tarih']);
        $yuzdePaket = $apslar->isNotEmpty() ? $this->primYuzdesi($origPersonelId, (int) $orig->hizmet_id) : 0.0;
        foreach ($apslar as $s) {
            $paket[] = (int) $s->id;
            $tahsilat = (float) DB::table('tahsilat_paketler')->where('adisyon_paket_id', $s->ap_id)->sum('tutar');
            $donem = substr((string) ($s->adisyon_tarih ?: date('Y-m-d')), 0, 7);
            $tutar = TelafiPrim::paketGeriDevir($tahsilat, (int) ($s->seans_sayisi ?: 0), $yuzdePaket, $primOdenmisMi($donem));
            if ($tutar > 0) {
                $geri[] = ['personel_id' => $origPersonelId, 'tutar' => -$tutar, 'tur' => 'paket', 'kaynak_adisyon_paket_id' => (int) $s->ap_id];
            }
        }

        $ozet['ah'] = count($satis);
        $ozet['aps'] = count($paket);
        $ozet['adisyon_degisti'] = count($satis) + count($paket);
        $ozet['hareket'] = count($geri);
        if (! $satis && ! $paket) {
            $ozet['uyari'] = 'Bu randevuya bağlı adisyon kaydı bulunamadı (ne satış kalemi ne paket seansı). Prim devri yapılamadı.';
        }

        return ['satis_devri' => $satis, 'paket_devri' => $paket, 'geri_devir' => $geri, 'ozet' => $ozet];
    }

    private function primUygula(array $prim, object $orig, int $yeniRandevuId, int $yeniPersonelId, ?int $yetkiliId, int $salonId): void
    {
        if ($prim['satis_devri']) {
            DB::table('adisyon_hizmetler')->whereIn('id', $prim['satis_devri'])->update(['personel_id' => $yeniPersonelId]);
        }
        if ($prim['paket_devri']) {
            DB::table('adisyon_paket_seanslar')->whereIn('id', $prim['paket_devri'])->update(['personel_id' => $yeniPersonelId]);
        }

        $aciklama = 'Telafi randevusu #'.$yeniRandevuId.' – orijinal randevu #'.$orig->randevu_id;
        foreach ($prim['geri_devir'] as $h) {
            $veri = [
                'personel_id'        => $h['personel_id'],
                'salon_id'           => $salonId,
                'tarih'              => date('Y-m-d'),
                'tip'                => 'telafi_devir',
                'tutar'              => $h['tutar'],
                'aciklama'           => $aciklama.($h['tur'] === 'paket' ? ' (paket telafi: ödenen prim geri devri)' : ' (telafi: ödenen prim geri devri)'),
                'ekleyen_yetkili_id' => (int) $yetkiliId,
                'created_at'         => now(),
                'updated_at'         => now(),
            ];
            // Kaynak referanslari (kolon varsa) — satis silinince hak ediste yetim kalmasin.
            $kaynak = ['kaynak_randevu_id' => $yeniRandevuId]
                + array_intersect_key($h, array_flip(['kaynak_adisyon_hizmet_id', 'kaynak_adisyon_paket_id']));
            foreach ($kaynak as $k => $v) {
                if (Schema::hasColumn('personel_prim_hareketleri', $k)) {
                    $veri[$k] = $v;
                }
            }
            DB::table('personel_prim_hareketleri')->insert($veri);
        }
    }

    /** Eski primYuzdesi: hizmet_prim_detayli ise hizmet bazli oran, yoksa genel yuzde. */
    private function primYuzdesi(int $personelId, int $hizmetId): float
    {
        $sp = DB::table('salon_personelleri')->where('id', $personelId)->first(['hizmet_prim_detayli', 'hizmet_prim_yuzde']);
        if (! $sp) {
            return 0.0;
        }
        if (! empty($sp->hizmet_prim_detayli)) {
            $y = DB::table('personel_prim_oranlari')->where('personel_id', $personelId)
                ->where('tur', 'hizmet')->where('kalem_id', $hizmetId)->value('yuzde');
            if ($y !== null) {
                return (float) $y;
            }
        }

        return (float) ($sp->hizmet_prim_yuzde ?? 0);
    }
}
