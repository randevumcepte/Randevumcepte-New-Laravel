<?php

namespace App\Services\Sadakat;

use App\Domain\Cark\PuanOdulTipi;
use App\Exceptions\CakismaException;
use App\Support\AktiviteLog;
use App\Support\SalonIdListesi;
use Illuminate\Support\Facades\DB;

/**
 * PUAN ÖDÜLÜ MERDİVENİ (salon_puan_odulleri) — müşteri biriktirdiği puanla eşiğe ulaşınca
 * basamağı talep eder, kupon oluşur (talep akışı eski monolitte). Eski web puanOdulleri/
 * puanOdulKaydet/puanOdulSil/puanOrneklerEkle + mobil carkAdminPuanOdul*'un tek karşılığı.
 *
 * Çoklu şube: YENİ ödül seçilen her şubeye ayrı satır olarak yazılır, grup gecerli_salonlar'a
 * konur (kupon miras alır). Düzenlemede grup yalnız açık seçim gelirse değişir.
 */
class PuanOdulServisi
{
    private const TABLO = 'salon_puan_odulleri';

    public function liste(int $salonId): array
    {
        return DB::table(self::TABLO)->where('salon_id', $salonId)->orderBy('puan_esigi')->orderBy('sira')->get()
            ->map(fn ($o) => $this->sun($o))->all();
    }

    public function salonId(int $id): ?int
    {
        $s = DB::table(self::TABLO)->where('id', $id)->value('salon_id');

        return $s ? (int) $s : null;
    }

    /**
     * @param  array{puan_esigi:int, baslik:string, aciklama?:?string, tip:string, deger?:?float, aktif?:bool, sira?:?int}  $v
     * @param  int[]  $hedefSubeler
     * @return int ana (istekteki şubedeki) kayıt id
     */
    public function ekle(int $salonId, int $yetkiliId, array $v, array $hedefSubeler): int
    {
        $satir = $this->satir($v) + ['gecerli_salonlar' => json_encode(array_values($hedefSubeler))];
        $anaId = DB::transaction(function () use ($satir, $hedefSubeler, $salonId) {
            $ana = null;
            foreach ($hedefSubeler as $hedef) {
                $id = DB::table(self::TABLO)->insertGetId($satir + ['salon_id' => (int) $hedef, 'created_at' => now(), 'updated_at' => now()]);
                if ($ana === null || (int) $hedef === $salonId) {
                    $ana = $id;
                }
            }

            return $ana;
        });
        AktiviteLog::yaz($salonId, $yetkiliId, 'cark_puan_odulu_ekle', 'cark_puan_odulu', $anaId,
            $satir['baslik'].' — '.$satir['puan_esigi'].' puan', 'Yeni puan merdiveni ödülü eklendi ('.count($hedefSubeler).' şube)', $satir);

        return $anaId;
    }

    /** @param  int[]|null  $hedefSubeler  null → mevcut şube grubu korunur */
    public function guncelle(int $salonId, int $yetkiliId, int $id, array $v, ?array $hedefSubeler): void
    {
        $satir = $this->satir($v);
        if ($hedefSubeler) {
            $satir['gecerli_salonlar'] = json_encode(array_values($hedefSubeler));
        }
        DB::table(self::TABLO)->where('id', $id)->update($satir + ['updated_at' => now()]);
        AktiviteLog::yaz($salonId, $yetkiliId, 'cark_puan_odulu_guncelle', 'cark_puan_odulu', $id,
            $satir['baslik'].' — '.$satir['puan_esigi'].' puan', 'Puan merdiveni ödülü güncellendi', $satir);
    }

    public function sil(int $salonId, int $yetkiliId, int $id): void
    {
        $o = DB::table(self::TABLO)->where('id', $id)->first(['baslik', 'puan_esigi']);
        DB::table(self::TABLO)->where('id', $id)->delete();
        AktiviteLog::yaz($salonId, $yetkiliId, 'cark_puan_odulu_sil', 'cark_puan_odulu', $id,
            ($o->baslik ?? '').' — '.($o->puan_esigi ?? '').' puan', 'Puan merdiveni ödülü silindi');
    }

    /** Merdiven boşken config'teki 6 örnek basamağı ekler (eski "Örnekleri Yükle"). */
    public function ornekleriEkle(int $salonId, int $yetkiliId): int
    {
        return DB::transaction(function () use ($salonId, $yetkiliId) {
            // Salonun mevcut satırlarını kilitle: iki tık ikinci kez örnek eklemesin.
            if (DB::table(self::TABLO)->where('salon_id', $salonId)->lockForUpdate()->first(['id'])) {
                throw new CakismaException('Bu salonda zaten ödüller var.');
            }
            $ornekler = config('cark.puan_odul_ornekleri');
            DB::table(self::TABLO)->insert(array_map(fn ($o, $i) => $this->satir($o + ['sira' => $i + 1]) + [
                'salon_id' => $salonId, 'created_at' => now(), 'updated_at' => now(),
            ], $ornekler, array_keys($ornekler)));
            AktiviteLog::yaz($salonId, $yetkiliId, 'cark_puan_odulu_ekle', 'cark_puan_odulu', null, count($ornekler).' örnek', 'Örnek puan ödülleri yüklendi');

            return count($ornekler);
        });
    }

    // ───────────────────────────── yardımcılar ─────────────────────────────

    private function satir(array $v): array
    {
        $tip = (string) $v['tip'];

        return [
            'puan_esigi' => max(1, (int) $v['puan_esigi']),
            'baslik'     => trim((string) $v['baslik']),
            'aciklama'   => trim((string) ($v['aciklama'] ?? '')) ?: null,
            'tip'        => $tip,
            'deger'      => PuanOdulTipi::degerliMi($tip) && isset($v['deger']) && $v['deger'] !== '' ? (float) $v['deger'] : null,
            'aktif'      => (int) (bool) ($v['aktif'] ?? true),
            'sira'       => (int) ($v['sira'] ?? 0),
        ];
    }

    private function sun(object $o): array
    {
        return [
            'id'               => (int) $o->id,
            'salon_id'         => (int) $o->salon_id,
            'puan_esigi'       => (int) $o->puan_esigi,
            'baslik'           => (string) $o->baslik,
            'aciklama'         => $o->aciklama,
            'tip'              => (string) $o->tip,
            'deger'            => $o->deger !== null ? (float) $o->deger : null,
            'aktif'            => (int) $o->aktif,
            'sira'             => (int) $o->sira,
            'gecerli_salonlar' => SalonIdListesi::coz($o->gecerli_salonlar ?? null),
        ];
    }
}
