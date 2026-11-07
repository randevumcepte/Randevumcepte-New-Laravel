<?php

namespace App\Services\Randevu;

use App\Domain\Randevu\SaatKapama;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Models\RandevuHizmetler;
use App\Models\Randevular;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Takvim uzerinden yazma (Faz 3a): SAAT KAPAMA ekle/sil + SURUKLE-BIRAK/BOYUTLANDIR.
 *
 * Her islem DRY-RUN plan (yazmaz) ya da tek transaction'da yazma + aktivite logu.
 * Yan etkiler outbox'a (BildirimKuyrugu): tasima bildirimleri (push + WA/SMS
 * "Randevu Guncellendi") ve Google Takvim senkronu — eski monolit gonderir.
 *
 * Eski referans: ApiController@saatkapamaekle, kapalisaatsil, surukleBirakRandevuGuncelle.
 * Bilincli farklar:
 *   - Surukle-birak GUN DEGISTIREMEZ (eski kod tarihi sessizce yok sayip randevuyu
 *     eski gunde birakiyordu). Gun degisikligi randevu duzenleme ile yapilir.
 *   - Kapali saat tasininca "musteri"ye (sentinel 2012) bildirim gonderilmez.
 *   - Kaynak (personel/oda/cihaz) ayni salona ait olmali.
 *   - Kapali saat silinince Google Takvim'den de kaldirilir (eski toplu silme
 *     model event'ini tetiklemiyordu).
 *   - Tekrar sikligi beyaz liste + en fazla 52 tekrar (SaatKapama).
 */
class RandevuTakvimYazServisi
{
    private const KAYNAK_KOLON = [1 => 'personel_id', 2 => 'cihaz_id', 3 => 'oda_id'];

    // ─────────────────────────── SAAT KAPAMA ───────────────────────────

    /**
     * @param  array{tarih:string,saat?:?string,saat_bitis?:?string,personel_id?:?int,oda_id?:?int,cihaz_id?:?int,not?:?string,tekrar_sayisi?:?int,tekrar_sikligi?:?string}  $g
     */
    public function saatKapamaEkle(int $salonId, array $g, ?int $yetkiliId, bool $yaz): array
    {
        $kaynak = [
            'personel_id' => (int) ($g['personel_id'] ?? 0) ?: null,
            'cihaz_id'    => (int) ($g['cihaz_id'] ?? 0) ?: null,
            'oda_id'      => (int) ($g['oda_id'] ?? 0) ?: null,
        ];
        if (! array_filter($kaynak)) {
            throw new GecersizIslemException('Kapatılacak personel, oda veya cihaz seçin.');
        }
        RandevuOrtak::kaynaklarSalonaAit($salonId, $kaynak);

        try {
            $tarihler = SaatKapama::tarihler((string) ($g['tarih'] ?? ''), (int) ($g['tekrar_sayisi'] ?? 1), $g['tekrar_sikligi'] ?? null);
            $bloklar = [];
            foreach ($tarihler as $t) {
                [$bas, $bit] = $this->kapamaSaatleri($salonId, $t, $g['saat'] ?? null, $g['saat_bitis'] ?? null);
                $bloklar[] = ['tarih' => $t, 'saat' => $bas, 'saat_bitis' => $bit, 'sure_dk' => SaatKapama::sureDk($bas, $bit)];
            }
        } catch (InvalidArgumentException $e) {
            throw new GecersizIslemException($e->getMessage());
        }

        $not = trim((string) ($g['not'] ?? '')) ?: null;
        $plan = ['bloklar' => $bloklar, 'kaynak' => $kaynak, 'not' => $not];

        if (! $yaz) {
            return ['yazildi' => false, 'mesaj' => 'DRY-RUN', 'plan' => $plan];
        }

        $randevuIdler = DB::transaction(function () use ($bloklar, $salonId, $kaynak, $not, $yetkiliId) {
            $idler = [];
            foreach ($bloklar as $b) {
                $randevuId = DB::table('randevular')->insertGetId([
                    'user_id'               => Randevular::KAPALI_SAAT_USER_ID,
                    'salon_id'              => $salonId,
                    'durum'                 => Randevular::ONAYLANDI,
                    'tarih'                 => $b['tarih'],
                    'saat'                  => $b['saat'],
                    'saat_bitis'            => $b['saat_bitis'],
                    'personel_notu'         => $not,
                    'olusturan_personel_id' => $yetkiliId,
                    'created_at'            => now(),
                    'updated_at'            => now(),
                ]);
                $rhId = DB::table('randevu_hizmetler')->insertGetId($kaynak + [
                    'randevu_id' => $randevuId,
                    'hizmet_id'  => RandevuHizmetler::KAPALI_SAAT_HIZMET_ID,
                    'sure_dk'    => $b['sure_dk'],
                    'fiyat'      => 0,
                    'saat'       => $b['saat'],
                    'saat_bitis' => $b['saat_bitis'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($kaynak['personel_id']) {
                    BildirimKuyrugu::googleTakvim($salonId, $randevuId, [$rhId]);
                }
                $idler[] = $randevuId;
            }

            return $idler;
        });

        AktiviteLog::yaz($salonId, $yetkiliId, 'saat_kapama_ekle', 'randevu', $randevuIdler[0] ?? null,
            count($randevuIdler).' kayit', 'Takvimde saat kapama eklendi.', ['randevu_idler' => $randevuIdler]);

        return ['yazildi' => true, 'mesaj' => 'Saat kapama eklendi ('.count($randevuIdler).' kayıt)', 'plan' => $plan, 'randevu_idler' => $randevuIdler];
    }

    public function saatKapamaSil(int $randevuId, ?int $yetkiliId, bool $yaz): array
    {
        $r = Randevular::find($randevuId);
        if (! $r) {
            throw new BulunamadiException('Kayıt bulunamadı');
        }
        if ((int) $r->user_id !== Randevular::KAPALI_SAAT_USER_ID) {
            throw new GecersizIslemException('Bu kayıt bir kapalı saat değil.');
        }
        $salonId = (int) $r->salon_id;
        $rhIdler = RandevuHizmetler::where('randevu_id', $randevuId)->pluck('id')->map(fn ($x) => (int) $x)->all();
        $plan = ['randevu_sil' => $randevuId, 'kalem_sil' => $rhIdler];

        if (! $yaz) {
            return ['yazildi' => false, 'mesaj' => 'DRY-RUN', 'plan' => $plan];
        }

        DB::transaction(function () use ($randevuId, $rhIdler, $salonId) {
            DB::table('randevu_hizmetler')->where('randevu_id', $randevuId)->delete();
            DB::table('randevular')->where('id', $randevuId)->delete();
            BildirimKuyrugu::googleTakvim($salonId, $randevuId, [], $rhIdler);
        });

        AktiviteLog::yaz($salonId, $yetkiliId, 'saat_kapama_sil', 'randevu', $randevuId, null, 'Takvimde saat kapama kaldırıldı.');

        return ['yazildi' => true, 'mesaj' => 'Saat kapama kaldırıldı', 'plan' => $plan];
    }

    // ─────────────────────── SURUKLE-BIRAK / BOYUTLANDIR ───────────────────────

    /**
     * @param  array{baslangic:string,bitis:string,takvim_turu?:?int,resource_id?:mixed}  $g
     *         baslangic/bitis: "Y-m-d H:i[:s]" ya da ISO ("Y-m-dTH:i:s")
     */
    public function tasi(int $rhId, array $g, ?int $yetkiliId, bool $yaz): array
    {
        $rh = DB::table('randevu_hizmetler as rh')->join('randevular as r', 'r.id', '=', 'rh.randevu_id')
            ->where('rh.id', $rhId)
            ->first(['rh.*', 'r.salon_id', 'r.tarih as randevu_tarih', 'r.user_id', 'r.durum as randevu_durum', 'r.on_gorusme_id']);
        if (! $rh) {
            throw new BulunamadiException('Randevu kaydı bulunamadı');
        }
        if ((int) $rh->randevu_durum >= Randevular::IPTAL_EDILDI) {
            throw new GecersizIslemException('İptal edilmiş randevu taşınamaz.');
        }
        $salonId = (int) $rh->salon_id;

        [$basTarih, $yeniSaat] = $this->tarihSaat((string) ($g['baslangic'] ?? ''));
        [$bitTarih, $yeniBitis] = $this->tarihSaat((string) ($g['bitis'] ?? ''));
        // Gun sonuna tasinan randevunun bitisi ertesi gun 00:00 olarak gelir; bu gun degisikligi degildir.
        $gunSonu = $yeniBitis === '00:00:00' && $bitTarih === date('Y-m-d', strtotime($rh->randevu_tarih.' +1 day'));
        if ($basTarih !== $rh->randevu_tarih || ($bitTarih !== $rh->randevu_tarih && ! $gunSonu)) {
            throw new GecersizIslemException('Sürükle-bırak ile gün değiştirilemez; günü değiştirmek için randevuyu düzenleyin.');
        }
        try {
            $sure = $gunSonu ? intdiv(strtotime('1970-01-02 00:00:00') - strtotime('1970-01-01 '.$yeniSaat), 60) : SaatKapama::sureDk($yeniSaat, $yeniBitis);
        } catch (InvalidArgumentException $e) {
            throw new GecersizIslemException($e->getMessage());
        }

        // Kaynak degisikligi (personel/cihaz/oda modunda). Kategori modunda kaynak degismez.
        $takvimTuru = isset($g['takvim_turu']) && $g['takvim_turu'] !== '' ? (int) $g['takvim_turu'] : null;
        $kolon = self::KAYNAK_KOLON[$takvimTuru] ?? null;
        $yeniKaynak = $kolon && ($g['resource_id'] ?? '') !== '' ? (int) $g['resource_id'] : null;
        $kalemDegisim = ['saat' => $yeniSaat, 'saat_bitis' => $yeniBitis, 'sure_dk' => $sure];
        if ($kolon && $yeniKaynak && $yeniKaynak !== (int) $rh->{$kolon}) {
            RandevuOrtak::kaynaklarSalonaAit($salonId, [$kolon => $yeniKaynak]);
            $kalemDegisim[$kolon] = $yeniKaynak;
        }

        $kapali = (int) $rh->user_id === Randevular::KAPALI_SAAT_USER_ID;
        // Eski: adisyon satirinda personel (tur 1) / cihaz (tur 2) senkronu.
        $adisyonSenkron = ! $kapali && isset($kalemDegisim[$kolon ?? '']) && in_array($kolon, ['personel_id', 'cihaz_id'], true)
            ? [$kolon => $kalemDegisim[$kolon]] : [];

        $plan = [
            'kalem'             => $kalemDegisim,
            'adisyon_senkron'   => $adisyonSenkron,
            'on_gorusme'        => $rh->on_gorusme_id ? (int) $rh->on_gorusme_id : null,
        ];
        $bildirimler = $kapali ? ['google_takvim'] : ['randevu_tasindi', 'google_takvim'];

        if (! $yaz) {
            return ['yazildi' => false, 'mesaj' => 'DRY-RUN', 'plan' => $plan, 'bildirimler' => $bildirimler];
        }

        DB::transaction(function () use ($rh, $rhId, $kalemDegisim, $adisyonSenkron, $salonId, $yetkiliId, $kapali, $takvimTuru, $yeniSaat, $yeniBitis, $kolon) {
            DB::table('randevu_hizmetler')->where('id', $rhId)->update($kalemDegisim + ['updated_at' => now()]);
            if ($adisyonSenkron) {
                DB::table('adisyon_hizmetler')->where('randevu_id', $rh->randevu_id)->where('hizmet_id', $rh->hizmet_id)->update($adisyonSenkron);
            }
            $ilkSaat = DB::table('randevu_hizmetler')->where('randevu_id', $rh->randevu_id)->min('saat');
            DB::table('randevular')->where('id', $rh->randevu_id)->update(['saat' => $ilkSaat, 'updated_at' => now()]);

            if ($rh->on_gorusme_id) {
                $og = ['hatirlatma_tarihi' => $rh->randevu_tarih, 'on_gorusme_saati' => $ilkSaat, 'updated_at' => now()];
                if ($kolon === 'personel_id' && isset($kalemDegisim['personel_id'])) {
                    $og['personel_id'] = $kalemDegisim['personel_id'];
                }
                DB::table('on_gorusmeler')->where('id', $rh->on_gorusme_id)->update($og);
            }

            if (! $kapali) {
                BildirimKuyrugu::yaz($salonId, 'randevu_tasindi', (int) $rh->randevu_id, $yetkiliId, [
                    'rh_id'            => $rhId,
                    'eski_saat'        => $rh->saat,
                    'eski_bitis'       => $rh->saat_bitis,
                    'eski_personel_id' => $rh->personel_id ? (int) $rh->personel_id : null,
                    'yeni_saat'        => $yeniSaat,
                    'yeni_bitis'       => $yeniBitis,
                    'takvim_turu'      => (int) $takvimTuru,
                    'resource_id'      => $kalemDegisim[$kolon ?? ''] ?? null,
                ]);
            }
            BildirimKuyrugu::googleTakvim($salonId, (int) $rh->randevu_id, [$rhId]);
        });

        AktiviteLog::yaz($salonId, $yetkiliId, 'randevu_tasi', 'randevu', (int) $rh->randevu_id, null,
            'Randevu sürükle-bırak ile taşındı/güncellendi', ['rh_id' => $rhId, 'degisim' => $kalemDegisim]);

        return ['yazildi' => true, 'mesaj' => 'Randevu güncellendi', 'plan' => $plan, 'bildirimler' => $bildirimler];
    }

    // ─────────────────────────────────────────────────────────────

    /** Kapama saatleri: istekte varsa onlar; yoksa salonun o gunku calisma saati; yoksa 09-18. */
    private function kapamaSaatleri(int $salonId, string $tarih, ?string $saat, ?string $bitis): array
    {
        if ($saat && $bitis) {
            return [SaatKapama::saat($saat), SaatKapama::saat($bitis)];
        }
        $cs = DB::table('salon_calisma_saatleri')->where('salon_id', $salonId)
            ->where('haftanin_gunu', (int) date('N', strtotime($tarih)))
            ->first(['baslangic_saati', 'bitis_saati']);

        return [
            SaatKapama::saat(substr((string) ($cs->baslangic_saati ?? '') ?: '09:00', 0, 8)),
            SaatKapama::saat(substr((string) ($cs->bitis_saati ?? '') ?: '18:00', 0, 8)),
        ];
    }

    /** "Y-m-d H:i[:s]" / "Y-m-dTH:i[:s][+03:00]" → [Y-m-d, H:i:s] */
    private function tarihSaat(string $s): array
    {
        if (! preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}(?::\d{2})?)/', trim($s), $m)) {
            throw new GecersizIslemException('Başlangıç/bitiş zamanı geçersiz.');
        }
        try {
            return [$m[1], SaatKapama::saat($m[2])];
        } catch (InvalidArgumentException $e) {
            throw new GecersizIslemException($e->getMessage());
        }
    }
}
