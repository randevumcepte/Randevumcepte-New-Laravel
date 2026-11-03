<?php

namespace App\Services\Stok;

use App\Domain\Stok\HareketTipi;
use App\Domain\Stok\Miktar;
use App\Exceptions\GecersizIslemException;
use App\Models\Urunler;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Stok ISLEMLERI — manuel hareket, alis girisi (mal kabul), depolar arasi transfer,
 * sayim. Her islem TEK transaction (eskiden alis/sayim kalem kalem yaziliyordu;
 * ortada hata olursa yarim fis kaliyordu) ve bir batch_uuid ile gruplanir.
 *
 * Girdiler FormRequest'te dogrulanmis gelir; burada SALON SAHIPLIGI + is kurallari.
 */
class StokIslemServisi
{
    public function __construct(
        private StokKapsam $kapsam,
        private StokHareketServisi $hareket,
    ) {}

    /** Manuel hareket (fire / iade / sarf / manuel duzeltme). */
    public function manuel(int $salonId, array $v, int $yetkiliId): array
    {
        $this->kapsam->aitOlmali('urun', $v['urun_id'], $salonId);
        $this->kapsam->aitOlmali('depo', $v['depo_id'] ?? null, $salonId);
        $tip = HareketTipi::from($v['hareket_tipi']);
        $urun = Urunler::findOrFail($v['urun_id']);

        $h = $this->hareket->kaydet([
            'salon_id'           => $salonId,
            'urun_id'            => (int) $urun->id,
            'depo_id'            => $this->hareket->depoCoz($salonId, $v['depo_id'] ?? null, $urun->varsayilan_depo_id),
            'miktar'             => $v['miktar'],
            'tip'                => $tip,
            'birim_alis_fiyati'  => $v['birim_alis_fiyati'] ?? null,
            'birim_satis_fiyati' => $v['birim_satis_fiyati'] ?? null,
            'aciklama'           => $v['aciklama'] ?? null,
            'kullanici_id'       => $yetkiliId,
        ]);

        AktiviteLog::yaz($salonId, $yetkiliId, 'stok_manuel_hareket', 'stok_hareket', (int) $h->id,
            $urun->urun_adi.' — '.($h->miktar > 0 ? '+' : '').(float) $h->miktar,
            'Manuel stok hareketi ('.$tip->value.')',
            ['urun_id' => (int) $urun->id, 'depo_id' => (int) $h->depo_id, 'miktar' => (float) $h->miktar, 'tip' => $tip->value]);

        return ['hareket_id' => (int) $h->id, 'miktar' => (float) $h->miktar, 'stok_adedi' => $this->toplam((int) $urun->id)];
    }

    /**
     * Alis girisi: kalemler [{urun_id, miktar, birim_alis_fiyati?, depo_id?}].
     * Birim alis fiyati verilen kalemde urunun alis_fiyati guncellenir; tedarikci
     * verildiyse urunun tedarikcisi o olur.
     */
    public function alis(int $salonId, array $v, int $yetkiliId): array
    {
        $kalemler = $v['kalemler'];
        $this->kapsam->aitOlmali('urun', array_column($kalemler, 'urun_id'), $salonId);
        $this->kapsam->aitOlmali('depo', array_merge([$v['depo_id'] ?? null], array_column($kalemler, 'depo_id')), $salonId);
        $this->kapsam->aitOlmali('tedarikci', $v['tedarikci_id'] ?? null, $salonId);

        $tedarikciId = $v['tedarikci_id'] ?? null;
        $batch = $this->hareket->yeniBatch();
        $aciklama = ($v['aciklama'] ?? null) ?: ($tedarikciId ? 'Tedarikçi: '.DB::table('tedarikciler')->where('id', $tedarikciId)->value('ad') : null);

        DB::transaction(function () use ($salonId, $v, $kalemler, $tedarikciId, $batch, $aciklama, $yetkiliId) {
            $depoId = $this->hareket->depoCoz($salonId, $v['depo_id'] ?? null);
            foreach ($kalemler as $k) {
                $fiyat = isset($k['birim_alis_fiyati']) && $k['birim_alis_fiyati'] !== '' ? (float) $k['birim_alis_fiyati'] : null;
                $this->hareket->kaydet([
                    'salon_id'          => $salonId,
                    'urun_id'           => (int) $k['urun_id'],
                    'depo_id'           => (int) (($k['depo_id'] ?? null) ?: $depoId),
                    'miktar'            => $k['miktar'],
                    'tip'               => HareketTipi::Alis,
                    'referans_tip'      => 'alis_fisi',
                    'batch_uuid'        => $batch,
                    'birim_alis_fiyati' => $fiyat,
                    'aciklama'          => $aciklama,
                    'kullanici_id'      => $yetkiliId,
                ]);
                $guncelle = array_filter(['alis_fiyati' => $fiyat > 0 ? $fiyat : null, 'tedarikci_id' => $tedarikciId]);
                if ($guncelle) {
                    Urunler::whereKey($k['urun_id'])->update($guncelle);
                }
            }
        });

        AktiviteLog::yaz($salonId, $yetkiliId, 'stok_alis_girisi', 'stok_alis_fisi', null,
            count($kalemler).' kalem alış', 'Tedarikçiden mal kabul / alış girişi yapıldı',
            ['kalem_sayisi' => count($kalemler), 'tedarikci_id' => $tedarikciId, 'batch' => $batch]);

        return ['batch_uuid' => $batch, 'kalem_sayisi' => count($kalemler)];
    }

    /** Depolar arasi transfer — kaynak depoda yeterli stok sart (fiziksel tasima eksiye dusemez). */
    public function transfer(int $salonId, array $v, int $yetkiliId): array
    {
        $this->kapsam->aitOlmali('urun', $v['urun_id'], $salonId);
        $this->kapsam->aitOlmali('depo', [$v['kaynak_depo_id'], $v['hedef_depo_id']], $salonId);

        $urunId = (int) $v['urun_id'];
        $kaynak = (int) $v['kaynak_depo_id'];
        $hedef = (int) $v['hedef_depo_id'];
        $miktar = Miktar::yuvarla(abs((float) $v['miktar']));
        $adlar = DB::table('depolar')->whereIn('id', [$kaynak, $hedef])->pluck('depo_adi', 'id');
        $batch = $this->hareket->yeniBatch();

        DB::transaction(function () use ($salonId, $urunId, $kaynak, $hedef, $miktar, $adlar, $batch, $yetkiliId) {
            $mevcut = $this->hareket->mevcut($urunId, $kaynak, true);
            if ($mevcut + Miktar::ESIK < $miktar) {
                throw new GecersizIslemException("Kaynak depoda yeterli stok yok (mevcut: {$mevcut}).");
            }
            $ortak = ['salon_id' => $salonId, 'urun_id' => $urunId, 'miktar' => $miktar, 'batch_uuid' => $batch, 'kullanici_id' => $yetkiliId];
            $this->hareket->kaydet($ortak + ['depo_id' => $kaynak, 'tip' => HareketTipi::TransferCikis, 'aciklama' => 'Hedef depo: '.$adlar[$hedef]]);
            $this->hareket->kaydet($ortak + ['depo_id' => $hedef, 'tip' => HareketTipi::TransferGiris, 'aciklama' => 'Kaynak depo: '.$adlar[$kaynak]]);
        });

        $urunAdi = (string) DB::table('urunler')->where('id', $urunId)->value('urun_adi');
        AktiviteLog::yaz($salonId, $yetkiliId, 'stok_depo_transfer', 'stok_transfer', null, $urunAdi.' — '.$miktar,
            'Depolar arası stok transferi', ['urun_id' => $urunId, 'kaynak_depo_id' => $kaynak, 'hedef_depo_id' => $hedef, 'miktar' => $miktar, 'batch' => $batch]);

        return ['batch_uuid' => $batch];
    }

    /**
     * Sayim: kalemler [{urun_id, sayilan_miktar, depo_id?}] + ust duzey depo_id.
     * Yalniz farkli olan kalemlere (sayilan − mevcut) 'sayim' hareketi yazilir.
     */
    public function sayim(int $salonId, array $v, int $yetkiliId): array
    {
        $kalemler = $v['kalemler'];
        $this->kapsam->aitOlmali('urun', array_column($kalemler, 'urun_id'), $salonId);
        $this->kapsam->aitOlmali('depo', array_merge([$v['depo_id'] ?? null], array_column($kalemler, 'depo_id')), $salonId);
        $batch = $this->hareket->yeniBatch();

        [$sayilan, $toplamFark] = DB::transaction(function () use ($salonId, $v, $kalemler, $batch, $yetkiliId) {
            $varsayilan = $this->hareket->depoCoz($salonId, $v['depo_id'] ?? null);
            $sayilan = 0;
            $toplamFark = 0.0;
            foreach ($kalemler as $k) {
                $depoId = (int) (($k['depo_id'] ?? null) ?: $varsayilan);
                $mevcut = $this->hareket->mevcut((int) $k['urun_id'], $depoId, true);
                $fark = Miktar::fark((float) $k['sayilan_miktar'], $mevcut);
                if (Miktar::sifirMi($fark)) {
                    continue;
                }
                $this->hareket->kaydet([
                    'salon_id'     => $salonId,
                    'urun_id'      => (int) $k['urun_id'],
                    'depo_id'      => $depoId,
                    'miktar'       => $fark,
                    'tip'          => HareketTipi::Sayim,
                    'batch_uuid'   => $batch,
                    'aciklama'     => "Sayım düzeltme: mevcut {$mevcut}, sayılan ".(float) $k['sayilan_miktar'],
                    'kullanici_id' => $yetkiliId,
                ]);
                $sayilan++;
                $toplamFark += $fark;
            }

            return [$sayilan, Miktar::yuvarla($toplamFark)];
        });

        if ($sayilan > 0) {
            AktiviteLog::yaz($salonId, $yetkiliId, 'stok_sayim_uygula', 'stok_sayim', null,
                $sayilan.' kalem sayım — fark '.number_format($toplamFark, 2, ',', '.'), 'Stok sayım sonucu uygulandı',
                ['sayilan_kalem' => $sayilan, 'toplam_fark' => $toplamFark, 'batch' => $batch]);
        }

        return ['batch_uuid' => $batch, 'sayilan_kalem' => $sayilan, 'toplam_fark' => $toplamFark];
    }

    private function toplam(int $urunId): float
    {
        return (float) DB::table('urunler')->where('id', $urunId)->value('stok_adedi');
    }
}
