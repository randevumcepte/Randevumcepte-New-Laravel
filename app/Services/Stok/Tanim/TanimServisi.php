<?php

namespace App\Services\Stok\Tanim;

use App\Services\Stok\StokKapsam;
use App\Support\AktiviteLog;
use App\Support\DogrulamaMetinleri;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Stok TANIM kayitlari (kategori / depo / tedarikci / sarf recetesi) icin ORTAK
 * akis — listele / kaydet / sil (pasif). Dort tanimin eski kodu ayni seyi dort
 * kez yaziyordu; burada tek sablon (template method), alt sinif yalniz farki verir:
 *
 *   kaynak()      : StokKapsam kaynak adi (sahiplik + 404 mesaji)
 *   model()       : Eloquent sinifi
 *   kurallar()    : dogrulama kurallari (yeni kayitta 'required', guncellemede kismi)
 *   sunum()       : JSON bicimi (StokSunumu)
 *   yazmaYetkisi(): kaydet/sil icin gereken yetki anahtari
 *   kancalar      : sorgu() / hazirla() / silmedenOnce()
 *
 * Silme FIZIKSEL DEGIL: aktif=0 (eski kayitlara bagli hareket/urun kirilmasin).
 */
abstract class TanimServisi
{
    public function __construct(protected StokKapsam $kapsam) {}

    abstract public function kaynak(): string;

    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** @return array<string, array<int,mixed>> */
    abstract protected function kurallar(): array;

    abstract protected function sunum(Model $m): array;

    public function yazmaYetkisi(): string
    {
        return 'urun.tanim_olustur';
    }

    /** Aktivite logu eylem oneki ('depo' → depo_ekle / depo_guncelle / depo_sil). */
    protected function logOneki(): string
    {
        return $this->kaynak();
    }

    /** Liste sorgusu (aktif + salon). Alt sinif siralama/ek kolon/filtre ekler. */
    protected function sorgu(int $salonId, array $filtre): Builder
    {
        return ($this->model())::query()->where('salon_id', $salonId)->where('aktif', 1)->orderBy('id');
    }

    /** Kayit oncesi ek is kurali / alan hazirligi (orn. tek varsayilan depo). */
    protected function hazirla(int $salonId, Model $m, array $veri): void {}

    /** Silme oncesi engel (orn. stoklu depo). GecersizIslemException firlatir. */
    protected function silmedenOnce(Model $m): void {}

    /** Log etiketi (kaydin okunur adi). */
    abstract protected function etiket(Model $m): string;

    public function listele(int $salonId, array $filtre = []): array
    {
        return $this->sorgu($salonId, $filtre)->get()->map(fn (Model $m) => $this->sunum($m))->values()->all();
    }

    public function salonId(int $id): int
    {
        return $this->kapsam->salonId($this->kaynak(), $id);
    }

    /**
     * Yeni ($id=null) ya da mevcut kaydi yaz. Guncellemede yalniz GONDERILEN alanlar
     * degisir (eski kod gonderilmeyeni null'a ceviriyordu).
     */
    public function kaydet(int $salonId, ?int $id, array $girdi, int $yetkiliId): array
    {
        $yeni = $id === null;
        $veri = Validator::make($girdi, $this->kurallarModu($yeni), DogrulamaMetinleri::mesajlar(), DogrulamaMetinleri::alanlar())->validate();

        $m = DB::transaction(function () use ($salonId, $id, $yeni, $veri) {
            $sinif = $this->model();
            $m = $yeni ? new $sinif(['salon_id' => $salonId, 'aktif' => 1]) : $sinif::query()->lockForUpdate()->findOrFail($id);
            $m->fill($veri);
            $this->hazirla($salonId, $m, $veri);
            $m->save();

            return $m;
        });

        AktiviteLog::yaz($salonId, $yetkiliId, $this->logOneki().($yeni ? '_ekle' : '_guncelle'), $this->kaynak(),
            (int) $m->id, $this->etiket($m), $yeni ? 'Yeni kayıt eklendi' : 'Kayıt güncellendi');

        return $this->sunum($this->tazele($m));
    }

    public function sil(int $id, int $yetkiliId): array
    {
        $m = DB::transaction(function () use ($id) {
            $m = ($this->model())::query()->lockForUpdate()->findOrFail($id);
            if ($m->aktif) {
                $this->silmedenOnce($m);
                $m->aktif = 0;
                $m->save();
            }

            return $m;
        });

        AktiviteLog::yaz((int) $m->salon_id, $yetkiliId, $this->logOneki().'_sil', $this->kaynak(),
            (int) $m->id, $this->etiket($m), 'Kayıt pasif yapıldı');

        return ['id' => (int) $m->id, 'aktif' => false];
    }

    /** Sunum icin taze kayit (ek kolonlar/iliskiler sorgu()'dan). */
    protected function tazele(Model $m): Model
    {
        return $this->sorgu((int) $m->salon_id, [])->whereKey($m->getKey())->first() ?? $m;
    }

    /** Guncellemede 'required' → 'sometimes' (kismi PATCH). */
    private function kurallarModu(bool $yeni): array
    {
        if ($yeni) {
            return $this->kurallar();
        }

        return array_map(
            fn (array $k) => array_map(fn ($r) => $r === 'required' ? 'sometimes' : $r, $k),
            $this->kurallar(),
        );
    }
}
