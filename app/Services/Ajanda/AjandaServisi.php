<?php

namespace App\Services\Ajanda;

use App\Exceptions\BulunamadiException;
use App\Support\AktiviteLog;
use App\Support\ListeIstegi;
use App\Support\ZamanliKayit;
use Illuminate\Support\Facades\DB;

/**
 * Ajanda notlari — eski ApiController::ajandagetir / notekleduzenle / ajandasil /
 * ajanda_okunduisaretle karsiligi. Not, olusturan personele aittir (eski liste de yalniz
 * kendi notunu gosterir); eski yazma uclarindaki sahiplik acigi burada kapanir.
 */
class AjandaServisi
{
    public function liste(int $salonId, ?int $personelId, ListeIstegi $istek): array
    {
        $q = DB::table('ajanda')->where('salon_id', $salonId)->where('ajanda_olusturan', $personelId ?? 0);
        if ($like = $istek->likeDeseni()) {
            $q->where('ajanda_baslik', 'like', $like);
        }
        $toplam = (clone $q)->count();
        $satirlar = $q->orderByDesc('ajanda_tarih')->orderByDesc('id')
            ->forPage($istek->sayfa, $istek->sayfaBoyu)
            ->get(['id', 'ajanda_baslik', 'ajanda_icerik', 'ajanda_tarih', 'ajanda_saat', 'ajanda_hatirlatma', 'ajanda_hatirlatma_saat', 'ajanda_durum']);

        return ['data' => $satirlar->map(fn ($s) => $this->sunum($s))->all()]
            + ListeIstegi::meta($istek->sayfa, $istek->sayfaBoyu, $toplam);
    }

    public function ekle(int $salonId, int $personelId, int $yetkiliId, array $g): array
    {
        $id = ZamanliKayit::ekle('ajanda', $this->alanlar($g) + ['salon_id' => $salonId, 'ajanda_olusturan' => $personelId]);
        AktiviteLog::yaz($salonId, $yetkiliId, 'not_ekle', 'ajanda', $id, $g['baslik'], 'Ajanda notu kaydedildi.');

        return ['id' => $id];
    }

    public function guncelle(int $id, int $salonId, ?int $personelId, int $yetkiliId, array $g): array
    {
        $this->kendiNotu($id, $salonId, $personelId)->update($this->alanlar($g) + ['updated_at' => now()]);
        AktiviteLog::yaz($salonId, $yetkiliId, 'not_guncelle', 'ajanda', $id, $g['baslik'], 'Ajanda notu kaydedildi.');

        return ['id' => $id];
    }

    public function okundu(int $id, int $salonId, ?int $personelId, int $yetkiliId): void
    {
        $this->kendiNotu($id, $salonId, $personelId)->update(['ajanda_durum' => 1, 'aktif' => 1, 'updated_at' => now()]);
        AktiviteLog::yaz($salonId, $yetkiliId, 'ajanda_okundu', 'ajanda', $id, null, 'Ajanda notu okundu işaretlendi.');
    }

    public function sil(int $id, int $salonId, ?int $personelId, int $yetkiliId): void
    {
        $this->kendiNotu($id, $salonId, $personelId)->delete();
        AktiviteLog::yaz($salonId, $yetkiliId, 'ajanda_sil', 'ajanda', $id, null, 'Ajanda notu silindi.');
    }

    /** Notun salonu (kapsam denetimi icin); yoksa null. */
    public function salonId(int $id): ?int
    {
        $salon = DB::table('ajanda')->where('id', $id)->value('salon_id');

        return $salon ? (int) $salon : null;
    }

    /** Baskasinin notu da yok sayilir: varligi sizdirilmaz. */
    private function kendiNotu(int $id, int $salonId, ?int $personelId)
    {
        $q = DB::table('ajanda')->where('id', $id)->where('salon_id', $salonId)->where('ajanda_olusturan', $personelId ?? 0);
        if (! $q->exists()) {
            throw new BulunamadiException('Not bulunamadı');
        }

        return $q;
    }

    private function alanlar(array $g): array
    {
        return [
            'ajanda_baslik'          => $g['baslik'],
            'ajanda_icerik'          => $g['icerik'] ?? null,
            'ajanda_tarih'           => date('Y-m-d', strtotime($g['tarih'])),
            'ajanda_saat'            => $g['saat'].':00',
            'ajanda_hatirlatma'      => empty($g['hatirlatma']) ? 0 : 1,
            'ajanda_hatirlatma_saat' => $g['hatirlatma_saati'] ?? null,
            'aktif'                  => 1,
        ];
    }

    private function sunum(object $s): array
    {
        return [
            'id'              => (int) $s->id,
            'baslik'          => $s->ajanda_baslik,
            'icerik'          => $s->ajanda_icerik,
            'tarih'           => $s->ajanda_tarih,
            'saat'            => substr((string) $s->ajanda_saat, 0, 5),
            'hatirlatma'      => (int) $s->ajanda_hatirlatma,
            'hatirlatma_saat' => $s->ajanda_hatirlatma_saat,
            'durum'           => (int) $s->ajanda_durum,
        ];
    }
}
