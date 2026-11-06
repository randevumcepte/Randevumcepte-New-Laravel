<?php

namespace App\Services\SistemYonetim\Icerik;

use App\Domain\SistemYonetim\DuyuruHedefi;
use App\Domain\SistemYonetim\DuyuruHedefTipi;
use App\Domain\SistemYonetim\DuyuruTipi;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Models\SistemYoneticisi;
use App\Services\SistemYonetim\Denetim;
use App\Support\SorguSayfasi;
use App\Support\ListeIstegi;
use App\Support\ZamanliKayit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Salonlara gosterilen duyurular. Salon tarafi (banner, okundu isaretleme) eski panelde. */
class DuyuruServisi
{
    private const TABLO = 'sistemyonetim_duyurular';

    private const OKUNDU_TABLOSU = 'sistemyonetim_duyuru_okundu';

    public const SIRALAMA = ['tarih' => 'id', 'baslik' => 'baslik', 'tip' => 'tip', 'hedef' => 'hedef_tipi', 'baslangic' => 'baslangic_tarihi', 'durum' => 'aktif', 'olusturan' => 'olusturan_user_name'];

    public const OKUYAN_SIRALAMA = ['tarih' => 'o.okundu_tarihi', 'salon' => 's.salon_adi', 'yetkili' => 'y.name'];

    public function secenekler(): array
    {
        return [
            'tipler'           => DuyuruTipi::formSecenekleri(),
            'varsayilan_tip'   => DuyuruTipi::Bilgi->value,
            'hedef_tipleri'    => DuyuruHedefTipi::secenekler(),
            'varsayilan_hedef' => DuyuruHedefTipi::Hepsi->value,
            'iller'            => DB::table('il')->orderBy('il_adi')->get(['id', 'il_adi'])->all(),
        ];
    }

    public function listele(array $liste): array
    {
        $q = DB::table(self::TABLO);
        if ($like = ListeIstegi::like($liste['ara'] ?? null)) {
            $q->where(fn ($w) => $w->where('baslik', 'like', $like)->orWhere('icerik', 'like', $like)->orWhere('olusturan_user_name', 'like', $like));
        }

        return SorguSayfasi::uret($q, $liste, [], self::SIRALAMA, fn ($d) => $this->satir($d) + ['ozet' => Str::limit(strip_tags((string) $d->icerik), 80)]);
    }

    public function detay(int $id): array
    {
        $d = $this->bul($id);
        $hedef = $this->hedefSayisi($d);
        $okundu = DB::table(self::OKUNDU_TABLOSU)->where('duyuru_id', $id)->count();

        return [
            'duyuru'        => $this->satir($d) + ['icerik' => $d->icerik, 'cta_metin' => $d->cta_metin, 'cta_link' => $d->cta_link, 'olusturma' => $d->created_at],
            'hedef_sayisi'  => $hedef,
            'okundu_sayisi' => $okundu,
            'okundu_orani'  => $hedef ? round($okundu / $hedef * 100, 1) : null,
        ];
    }

    public function okuyanlar(int $id, array $liste): array
    {
        $this->bul($id);
        $q = DB::table(self::OKUNDU_TABLOSU.' as o')
            ->leftJoin('isletmeyetkilileri as y', 'y.id', '=', 'o.user_id')
            ->leftJoin('salonlar as s', 's.id', '=', 'o.salon_id')
            ->where('o.duyuru_id', $id)
            ->select('o.id', 'o.okundu_tarihi', 'y.name as yetkili_adi', 's.salon_adi');
        if ($like = ListeIstegi::like($liste['ara'] ?? null)) {
            $q->where(fn ($w) => $w->where('s.salon_adi', 'like', $like)->orWhere('y.name', 'like', $like));
        }

        return SorguSayfasi::uret($q, $liste, [], self::OKUYAN_SIRALAMA, fn ($o) => [
            'id' => (int) $o->id, 'salon_adi' => $o->salon_adi, 'yetkili_adi' => $o->yetkili_adi, 'okundu_tarihi' => $o->okundu_tarihi,
        ], 'o.id');
    }

    /** Duzenleme formunun degerleri: tarihler form biciminde, secili hedefler adlariyla. */
    public function formVerisi(int $id): array
    {
        $d = $this->bul($id);
        $idler = DuyuruHedefi::coz($d->hedef_ids);
        $seciliMi = $d->hedef_tipi === DuyuruHedefTipi::Secili->value;

        return [
            'baslik'           => $d->baslik,
            'icerik'           => $d->icerik,
            'tip'              => $d->tip,
            'hedef_tipi'       => $d->hedef_tipi,
            'baslangic_tarihi' => $this->formTarihi($d->baslangic_tarihi),
            'bitis_tarihi'     => $this->formTarihi($d->bitis_tarihi),
            'aktif'            => (bool) $d->aktif,
            'sticky'           => (bool) $d->sticky,
            'cta_metin'        => $d->cta_metin,
            'cta_link'         => $d->cta_link,
            'il_ids'           => $d->hedef_tipi === DuyuruHedefTipi::Il->value ? $idler : [],
            'secili_salonlar'  => $seciliMi ? DB::table('salonlar')->whereIn('id', $idler)->orderBy('salon_adi')->get(['id', 'salon_adi'])->all() : [],
        ];
    }

    public function olustur(array $g, SistemYoneticisi $yonetici): int
    {
        $alanlar = $this->alanlar($g);
        $id = ZamanliKayit::ekle(self::TABLO, $alanlar + ['olusturan_user_id' => $yonetici->id, 'olusturan_user_name' => $yonetici->name]);
        Denetim::yaz('duyuru_olustur', 'duyuru', $id, $g['baslik'], "Hedef: {$g['hedef_tipi']}");

        return $id;
    }

    public function guncelle(int $id, array $g): void
    {
        $this->bul($id);
        DB::table(self::TABLO)->where('id', $id)->update($this->alanlar($g) + ['updated_at' => now()]);
        Denetim::yaz('duyuru_guncelle', 'duyuru', $id, $g['baslik']);
    }

    public function sil(int $id): void
    {
        $d = $this->bul($id);
        Denetim::yaz('duyuru_sil', 'duyuru', $id, $d->baslik);
        DB::table(self::TABLO)->where('id', $id)->delete();
    }

    private function alanlar(array $g): array
    {
        $tip = DuyuruHedefTipi::from($g['hedef_tipi']);
        $idler = DuyuruHedefi::idler($tip, $g['salon_ids'] ?? [], $g['il_ids'] ?? []);
        if ($tip !== DuyuruHedefTipi::Hepsi && ! $idler) {
            throw new GecersizIslemException($tip === DuyuruHedefTipi::Il ? 'En az bir il seçin.' : 'En az bir salon seçin.');
        }
        $baslangic = $g['baslangic_tarihi'] ?? null;
        $bitis = $g['bitis_tarihi'] ?? null;
        if ($baslangic && $bitis && $bitis < $baslangic) {
            throw new GecersizIslemException('Bitiş tarihi başlangıçtan önce olamaz.');
        }

        return [
            'baslik'           => $g['baslik'],
            'icerik'           => $g['icerik'],
            'tip'              => $g['tip'],
            'hedef_tipi'       => $tip->value,
            'hedef_ids'        => $idler ? json_encode($idler) : null,
            'baslangic_tarihi' => $baslangic,
            'bitis_tarihi'     => $bitis,
            'aktif'            => ! empty($g['aktif']) ? 1 : 0,
            'sticky'           => ! empty($g['sticky']) ? 1 : 0,
            'cta_metin'        => $g['cta_metin'] ?? null,
            'cta_link'         => $g['cta_link'] ?? null,
        ];
    }

    private function hedefSayisi(object $d): int
    {
        return match (DuyuruHedefTipi::tryFrom((string) $d->hedef_tipi)) {
            DuyuruHedefTipi::Hepsi  => DB::table('salonlar')->count(),
            DuyuruHedefTipi::Secili => count(DuyuruHedefi::coz($d->hedef_ids)),
            DuyuruHedefTipi::Il     => DB::table('salonlar')->whereIn('il_id', DuyuruHedefi::coz($d->hedef_ids))->count(),
            default                 => 0,
        };
    }

    private function satir(object $d): array
    {
        return [
            'id'                  => (int) $d->id,
            'baslik'              => $d->baslik,
            'tip'                 => DuyuruTipi::rozet($d->tip),
            'hedef'               => DuyuruHedefTipi::rozet($d->hedef_tipi),
            'baslangic_tarihi'    => $d->baslangic_tarihi,
            'bitis_tarihi'        => $d->bitis_tarihi,
            'aktif'               => (bool) $d->aktif,
            'sticky'              => (bool) $d->sticky,
            'olusturan_user_name' => $d->olusturan_user_name,
        ];
    }

    private function formTarihi(?string $tarih): ?string
    {
        return $tarih ? Carbon::parse($tarih)->format('Y-m-d H:i') : null;
    }

    private function bul(int $id): object
    {
        return DB::table(self::TABLO)->where('id', $id)->first() ?? throw new BulunamadiException('Duyuru bulunamadı');
    }
}
