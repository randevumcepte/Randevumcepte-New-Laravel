<?php

namespace App\Services\SistemYonetim\Icerik;

use App\Domain\SistemYonetim\KdvHesabi;
use App\Domain\SistemYonetim\SmsPaketKartRengi;
use App\Exceptions\BulunamadiException;
use App\Services\SistemYonetim\Denetim;
use App\Support\SorguSayfasi;
use App\Support\ListeIstegi;
use Illuminate\Support\Facades\DB;

/** Salonlara satilan interaktif duyuru (SMS) paketleri. Tabloda created_at/updated_at yok. */
class SmsPaketServisi
{
    private const TABLO = 'sms_paketleri';

    public const SIRALAMA = ['sms_adet' => 'sms_adet', 'ucret' => 'ucret', 'class' => 'class'];

    public function secenekler(): array
    {
        return [
            'kart_renkleri'   => SmsPaketKartRengi::secenekler(),
            'varsayilan_renk' => SmsPaketKartRengi::Primary->value,
            'kdv_yuzde'       => KdvHesabi::yuzde(),
        ];
    }

    public function listele(array $liste): array
    {
        $q = DB::table(self::TABLO);
        if ($like = ListeIstegi::like($liste['ara'] ?? null)) {
            $q->where(fn ($w) => $w->where('paket_adi', 'like', $like)->orWhere('alt_baslik', 'like', $like)->orWhere('sms_adet', 'like', $like));
        }

        return SorguSayfasi::uret($q, $liste, [], self::SIRALAMA, fn ($p) => [
            'id'         => (int) $p->id,
            'sms_adet'   => (int) $p->sms_adet,
            'paket_adi'  => $p->paket_adi,
            'alt_baslik' => $p->alt_baslik,
            'ucret'      => (float) $p->ucret,
            'class'      => SmsPaketKartRengi::rozet($p->class ?: SmsPaketKartRengi::Primary->value),
        ] + KdvHesabi::dahilTutardan((float) $p->ucret));
    }

    public function olustur(array $g): int
    {
        $alanlar = $this->alanlar($g);
        $id = (int) DB::table(self::TABLO)->insertGetId($alanlar);
        Denetim::yaz('sms_paket_olustur', 'sms_paket', $id, $alanlar['sms_adet'].' SMS', $alanlar['ucret'].' TL');

        return $id;
    }

    public function guncelle(int $id, array $g): void
    {
        $this->bul($id);
        $alanlar = $this->alanlar($g);
        DB::table(self::TABLO)->where('id', $id)->update($alanlar);
        Denetim::yaz('sms_paket_guncelle', 'sms_paket', $id, $alanlar['sms_adet'].' SMS', $alanlar['ucret'].' TL');
    }

    public function sil(int $id): void
    {
        $paket = $this->bul($id);
        Denetim::yaz('sms_paket_sil', 'sms_paket', $id, $paket->sms_adet.' SMS');
        DB::table(self::TABLO)->where('id', $id)->delete();
    }

    private function alanlar(array $g): array
    {
        return [
            'sms_adet'   => (int) $g['sms_adet'],
            'ucret'      => (float) $g['ucret'],
            'class'      => $g['class'] ?? SmsPaketKartRengi::Primary->value,
            'paket_adi'  => $g['paket_adi'] ?? null,
            'alt_baslik' => $g['alt_baslik'] ?? null,
        ];
    }

    private function bul(int $id): object
    {
        return DB::table(self::TABLO)->where('id', $id)->first() ?? throw new BulunamadiException('Paket bulunamadı');
    }
}
