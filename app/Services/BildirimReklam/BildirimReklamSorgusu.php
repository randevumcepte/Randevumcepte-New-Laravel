<?php

namespace App\Services\BildirimReklam;

use App\Domain\BildirimReklam\HedefKosulu;
use App\Domain\BildirimReklam\ReklamDurumu;
use App\Exceptions\BulunamadiException;
use App\Support\ListeIstegi;
use App\Support\Yetki\YetkiSabitleri;
use Illuminate\Support\Facades\DB;

/**
 * Bildirim reklamı OKUMA (yönetim) — eski bildirim_reklam_liste (blade'e tüm kayıtlar) +
 * mobil v1 adminListe uçlarının TEK karşılığı; çıktı BildirimReklamSunumu'nda.
 * Kapsam: reklamı OLUŞTURAN şube (salon_id) — şube grubu yalnız yayın/push kapsamıdır.
 */
class BildirimReklamSorgusu
{
    public const SIRALAMA = ['id' => 'id', 'baslik' => 'baslik', 'durum' => 'durum', 'created_at' => 'created_at'];

    public function salonId(int $id): ?int
    {
        $s = DB::table('bildirim_reklamlari')->where('id', $id)->value('salon_id');

        return $s ? (int) $s : null;
    }

    /** Ham satır (yazma servisleri için) — yoksa 404. */
    public function satir(int $id, bool $kilitle = false): object
    {
        $q = DB::table('bildirim_reklamlari')->where('id', $id);
        $r = $kilitle ? $q->lockForUpdate()->first() : $q->first();
        if (! $r) {
            throw new BulunamadiException('Reklam bulunamadı');
        }

        return $r;
    }

    /**
     * @param  array{page:int, per_page:int, ara:?string, sirala:?string, yon:string}  $liste
     * @param  array{durum?:?string}  $filtre
     * @return array{data:array, current_page:int, last_page:int, per_page:int, total:int, sayaclar:array<string,int>}
     */
    public function listele(int $salonId, array $liste, array $filtre, bool $telGor): array
    {
        $q = DB::table('bildirim_reklamlari')->where('salon_id', $salonId);
        if ($like = ListeIstegi::like($liste['ara'] ?? null)) {
            $q->where(fn ($w) => $w->where('baslik', 'like', $like)->orWhere('mesaj', 'like', $like));
        }

        // Durum sekme sayaçları: durum filtresi HARİÇ aynı kapsam / arama.
        $sayim = (clone $q)->selectRaw('durum, COUNT(*) as n')->groupBy('durum')->pluck('n', 'durum');
        $sayaclar = ['tumu' => (int) $sayim->sum()];
        foreach (ReklamDurumu::kodlar() as $kod) {
            $sayaclar[$kod] = (int) ($sayim[$kod] ?? 0);
        }

        if ($durum = ReklamDurumu::tryFrom((string) ($filtre['durum'] ?? ''))) {
            $q->where('durum', $durum->value);
        }
        $toplam = (clone $q)->count();
        $satirlar = $q->orderBy(self::SIRALAMA[$liste['sirala'] ?? 'id'] ?? 'id', $liste['yon'] ?? 'desc')
            ->orderByDesc('id')
            ->forPage($liste['page'], $liste['per_page'])->get();

        $kisiler = $this->kisiler($satirlar->all(), $telGor);

        return [
            'data'     => $satirlar->map(fn ($r) => BildirimReklamSunumu::yonetim($r, $kisiler[$this->kisiId($r)] ?? null))->values()->all(),
            'sayaclar' => $sayaclar,
        ] + ListeIstegi::meta($liste['page'], $liste['per_page'], $toplam);
    }

    public function getir(int $id, bool $telGor): array
    {
        $r = $this->satir($id);

        return BildirimReklamSunumu::yonetim($r, $this->kisiler([$r], $telGor)[$this->kisiId($r)] ?? null);
    }

    private function kisiId(object $r): int
    {
        $h = HedefKosulu::dbden($r->hedef_kitle, $r->hedef_kosul);

        return $h->kisiyeOzelMi() ? (int) $h->deger : 0;
    }

    /**
     * "Belirli kişi" hedefli reklamların müşteri özetleri (düzenleme formu seçili kişiyi gösterir).
     * Eski blade tüm portföyü sayfaya gömüyordu; v2 yalnız gerekenleri döner (KVKK minimizasyon).
     *
     * @param  list<object>  $satirlar
     * @return array<int, array{id:int, ad:string, telefon:?string}>
     */
    private function kisiler(array $satirlar, bool $telGor): array
    {
        $idler = array_values(array_filter(array_unique(array_map(fn ($r) => $this->kisiId($r), $satirlar))));
        if (! $idler) {
            return [];
        }

        return DB::table('users')->whereIn('id', $idler)->get(['id', 'name', 'cep_telefon'])
            ->mapWithKeys(fn ($u) => [(int) $u->id => [
                'id'      => (int) $u->id,
                'ad'      => (string) $u->name,
                'telefon' => $telGor ? $u->cep_telefon : YetkiSabitleri::telefonMaskele((string) $u->cep_telefon),
            ]])->all();
    }
}
