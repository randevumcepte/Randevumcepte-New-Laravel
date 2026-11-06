<?php

namespace App\Services\SistemYonetim\Destek;

use App\Support\SorguSayfasi;
use App\Domain\SistemYonetim\HazirCevapKategorisi;
use App\Exceptions\BulunamadiException;
use App\Models\SistemYoneticisi;
use App\Services\SistemYonetim\Denetim;
use App\Support\ListeIstegi;
use App\Support\ZamanliKayit;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Talep yanitlarinda kullanilan sablon mesajlar. */
class HazirCevapServisi
{
    private const TABLO = 'sistemyonetim_hazir_cevaplar';

    private const SECIM_LIMITI = 30;

    public const SIRALAMA = ['kullanim' => 'kullanim_sayisi', 'baslik' => 'baslik', 'kategori' => 'kategori', 'kisayol' => 'kisayol', 'durum' => 'aktif'];

    public function secenekler(): array
    {
        return ['kategoriler' => HazirCevapKategorisi::secenekler(), 'varsayilan_kategori' => HazirCevapKategorisi::Genel->value];
    }

    public function listele(array $liste): array
    {
        $q = DB::table(self::TABLO);
        $this->ara($q, $liste['ara'] ?? null);

        return SorguSayfasi::uret($q, $liste, [], self::SIRALAMA, fn ($h) => [
            'id'              => (int) $h->id,
            'baslik'          => $h->baslik,
            'icerik'          => $h->icerik,
            'ozet'            => Str::limit(strip_tags((string) $h->icerik), 100),
            'kategori'        => HazirCevapKategorisi::rozet($h->kategori),
            'kisayol'         => $h->kisayol,
            'kullanim_sayisi' => (int) $h->kullanim_sayisi,
            'aktif'           => (bool) $h->aktif,
        ]);
    }

    /** Yanit formundaki secici: yalniz aktifler, en cok kullanilan once. */
    public function secim(?string $ara, ?string $kategori): array
    {
        $q = DB::table(self::TABLO)->where('aktif', 1)->when($kategori, fn ($w, $v) => $w->where('kategori', $v));
        $this->ara($q, $ara);

        return $q->orderByDesc('kullanim_sayisi')->limit(self::SECIM_LIMITI)->get(['id', 'baslik', 'icerik', 'kategori', 'kisayol'])->all();
    }

    public function olustur(array $g, SistemYoneticisi $yonetici): int
    {
        $id = ZamanliKayit::ekle(self::TABLO, $this->alanlar($g) + ['olusturan_user_id' => $yonetici->id, 'olusturan_user_name' => $yonetici->name]);
        Denetim::yaz('hazir_cevap_olustur', 'hazir_cevap', $id, $g['baslik']);

        return $id;
    }

    public function guncelle(int $id, array $g): void
    {
        $this->bul($id);
        DB::table(self::TABLO)->where('id', $id)->update($this->alanlar($g) + ['updated_at' => now()]);
        Denetim::yaz('hazir_cevap_guncelle', 'hazir_cevap', $id, $g['baslik']);
    }

    public function sil(int $id): void
    {
        $kayit = $this->bul($id);
        Denetim::yaz('hazir_cevap_sil', 'hazir_cevap', $id, $kayit->baslik);
        DB::table(self::TABLO)->where('id', $id)->delete();
    }

    public function kullanimSay(int $id): void
    {
        $this->bul($id);
        DB::table(self::TABLO)->where('id', $id)->increment('kullanim_sayisi');
    }

    /** Aktif alani verilmezse aktif sayilir. */
    private function alanlar(array $g): array
    {
        return [
            'baslik'   => $g['baslik'],
            'icerik'   => $g['icerik'],
            'kategori' => $g['kategori'],
            'kisayol'  => $g['kisayol'] ?? null,
            'aktif'    => ($g['aktif'] ?? true) ? 1 : 0,
        ];
    }

    private function ara(Builder $q, ?string $ara): void
    {
        if ($like = ListeIstegi::like($ara)) {
            $q->where(fn ($w) => $w->where('baslik', 'like', $like)->orWhere('kisayol', 'like', $like)->orWhere('icerik', 'like', $like));
        }
    }

    private function bul(int $id): object
    {
        return DB::table(self::TABLO)->where('id', $id)->first() ?? throw new BulunamadiException('Hazır cevap bulunamadı');
    }
}
