<?php

namespace App\Services\Personel;

use App\Exceptions\BulunamadiException;
use App\Models\PersonelCalismaSaatleri;
use App\Models\PersonelMolaSaatleri;
use App\Models\PersonelPrimOrani;
use Illuminate\Support\Facades\DB;

/**
 * Personel SALT-OKUMA servisi (Faz 1).
 *
 * DTO, eski Flutter Personel.fromJson'un bekledigi alanlarin UST-KUMESI'dir
 * (sonraki Flutter gocunde alan uyumsuzlugu olmasin). Is mantigi burada; controller ince.
 */
class PersonelOkumaServisi
{
    /**
     * Personel listesi/tekil icin ortak temel sorgu: salon_personelleri + rol (hesap_turu).
     * hesap_turu = model_has_roles ⋈ roles (salon_id ile scoped, personel satirinin salonu).
     */
    public function temelSorgu()
    {
        return DB::table('salon_personelleri as sp')
            ->leftJoin('isletmeyetkilileri as iy', 'sp.yetkili_id', '=', 'iy.id')
            ->leftJoin('model_has_roles as mhr', function ($j) {
                $j->on('iy.id', '=', 'mhr.model_id')
                  ->on('mhr.salon_id', '=', 'sp.salon_id');
            })
            ->leftJoin('roles as r', 'mhr.role_id', '=', 'r.id')
            ->select([
                'sp.id as id',
                'sp.salon_id as salon_id',
                'sp.personel_adi as personel_adi',
                'sp.profil_resmi as profil_resmi',
                'sp.cinsiyet as cinsiyet',
                'sp.unvan as unvan',
                'sp.hizmet_prim_yuzde as hizmet_prim_yuzde',
                'sp.urun_prim_yuzde as urun_prim_yuzde',
                'sp.paket_prim_yuzde as paket_prim_yuzde',
                'sp.cep_telefon as cep_telefon',
                'sp.renk as renk',
                'sp.maas as maas',
                'sp.takvimde_gorunsun as takvimde_gorunsun',
                'sp.dahili_no as dahili_no',
                'sp.takvim_sirasi as takvim_sirasi',
                'sp.aktif as aktif',
                'r.id as hesap_turu',
            ]);
    }

    /** Kapsam denetimi icin: personelin bagli oldugu salon_id (yoksa null). */
    public function salonIdGetir(int $personelId): ?int
    {
        $salonId = DB::table('salon_personelleri')->where('id', $personelId)->value('salon_id');

        return $salonId ? (int) $salonId : null;
    }

    /** salonIdGetir'in zorunlu hali — kayit yoksa 404 (kapsam sizintisi yok). */
    public function salonIdZorunlu(int $personelId): int
    {
        return $this->salonIdGetir($personelId)
            ?? throw new BulunamadiException('Personel bulunamadı');
    }

    /** Tekil personel DTO (yoksa null). $maasGor false ise maas/prim alanlari maskelenir. */
    public function getir(int $personelId, bool $maasGor = true): ?array
    {
        $row = $this->temelSorgu()
            ->where('sp.id', $personelId)
            ->groupBy('sp.id')
            ->first();

        return $row ? $this->donustur($row, $maasGor) : null;
    }

    /** Ham DB satirini kararli DTO'ya cevir (+ maskeleme). Liste servisi de kullanir. */
    public function donustur($row, bool $maasGor = true): array
    {
        return [
            'id'                 => (int) $row->id,
            'salon_id'           => (int) $row->salon_id,
            'hesap_turu'         => $row->hesap_turu !== null ? (int) $row->hesap_turu : null,
            'personel_adi'       => $row->personel_adi,
            'profil_resmi'       => $row->profil_resmi,
            'cinsiyet'           => $row->cinsiyet !== null ? (int) $row->cinsiyet : null,
            'unvan'              => $row->unvan,
            'cep_telefon'        => $row->cep_telefon,
            'renk'               => $row->renk !== null ? (int) $row->renk : null,
            'dahili_no'          => $row->dahili_no,
            'takvim_sirasi'      => $row->takvim_sirasi !== null ? (int) $row->takvim_sirasi : null,
            'takvimde_gorunsun'  => (int) ($row->takvimde_gorunsun ?? 0),
            'aktif'              => (int) ($row->aktif ?? 0),
            // Hassas alanlar: personel.maas_tutar_gor yoksa null (maskelenir).
            'maas'               => $maasGor ? $row->maas : null,
            'hizmet_prim_yuzde'  => $maasGor ? $row->hizmet_prim_yuzde : null,
            'urun_prim_yuzde'    => $maasGor ? $row->urun_prim_yuzde : null,
            'paket_prim_yuzde'   => $maasGor ? $row->paket_prim_yuzde : null,
        ];
    }

    /** Personelin haftalik calisma saatleri (7 gun). */
    public function calismaSaatleri(int $personelId): array
    {
        return PersonelCalismaSaatleri::where('personel_id', $personelId)
            ->orderBy('haftanin_gunu')
            ->get()
            ->map(fn ($s) => [
                'id'               => (int) $s->id,
                'haftanin_gunu'    => (int) $s->haftanin_gunu,
                'calisiyor'        => (int) $s->calisiyor,
                'baslangic_saati'  => $s->baslangic_saati,
                'bitis_saati'      => $s->bitis_saati,
            ])
            ->all();
    }

    /** Personelin haftalik mola (ogle arasi) saatleri (7 gun). */
    public function molaSaatleri(int $personelId): array
    {
        return PersonelMolaSaatleri::where('personel_id', $personelId)
            ->orderBy('haftanin_gunu')
            ->get()
            ->map(fn ($s) => [
                'id'               => (int) $s->id,
                'haftanin_gunu'    => (int) $s->haftanin_gunu,
                'mola_var'         => (int) $s->mola_var,
                'baslangic_saati'  => $s->baslangic_saati,
                'bitis_saati'      => $s->bitis_saati,
            ])
            ->all();
    }

    /**
     * Kaleme göre (detayli) prim ekrani verisi — eski personelPrimKalemleriApi port.
     * Dönüş: {flags:{hizmet,urun,paket}, hizmetler:[{id,ad,yuzde}], urunler:[...], paketler:[...]}.
     * hizmetler = personele atanmis (personel_sunulan_hizmetler); urun/paket = salon katalogu.
     * yuzde = kayitli kalem orani (yoksa null → genel yuzde kullanilir).
     */
    public function primKalemleri(int $personelId, int $salonId): array
    {
        $flags = ['hizmet' => 0, 'urun' => 0, 'paket' => 0];
        $p = DB::table('salon_personelleri')->where('id', $personelId)->where('salon_id', $salonId)->first();
        if ($p) {
            $flags['hizmet'] = (int) ($p->hizmet_prim_detayli ?? 0);
            $flags['urun']   = (int) ($p->urun_prim_detayli ?? 0);
            $flags['paket']  = (int) ($p->paket_prim_detayli ?? 0);
        }

        $oranlar = ['hizmet' => [], 'urun' => [], 'paket' => []];
        foreach (PersonelPrimOrani::where('personel_id', $personelId)->get() as $o) {
            if (isset($oranlar[$o->tur])) {
                $oranlar[$o->tur][(int) $o->kalem_id] = (float) $o->yuzde;
            }
        }

        $hizmetler = [];
        foreach (DB::table('personel_sunulan_hizmetler as psh')
            ->join('hizmetler as h', 'psh.hizmet_id', '=', 'h.id')
            ->where('psh.personel_id', $personelId)
            ->orderBy('h.hizmet_adi')
            ->select('h.id', 'h.hizmet_adi')
            ->get() as $r) {
            $hizmetler[] = ['id' => (int) $r->id, 'ad' => $r->hizmet_adi, 'yuzde' => $oranlar['hizmet'][(int) $r->id] ?? null];
        }

        $urunler = [];
        foreach (DB::table('urunler')->where('salon_id', $salonId)->orderBy('urun_adi')->select('id', 'urun_adi')->get() as $u) {
            $urunler[] = ['id' => (int) $u->id, 'ad' => $u->urun_adi, 'yuzde' => $oranlar['urun'][(int) $u->id] ?? null];
        }

        $paketler = [];
        foreach (DB::table('paketler')->where('salon_id', $salonId)->orderBy('paket_adi')->select('id', 'paket_adi')->get() as $pk) {
            $paketler[] = ['id' => (int) $pk->id, 'ad' => $pk->paket_adi, 'yuzde' => $oranlar['paket'][(int) $pk->id] ?? null];
        }

        return ['flags' => $flags, 'hizmetler' => $hizmetler, 'urunler' => $urunler, 'paketler' => $paketler];
    }
}
