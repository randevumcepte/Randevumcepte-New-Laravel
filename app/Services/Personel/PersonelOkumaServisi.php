<?php

namespace App\Services\Personel;

use App\Models\PersonelCalismaSaatleri;
use App\Models\PersonelMolaSaatleri;
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
}
