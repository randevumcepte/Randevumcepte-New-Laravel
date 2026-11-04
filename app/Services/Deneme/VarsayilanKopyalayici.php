<?php

namespace App\Services\Deneme;

use App\Models\FormTaslaklari;
use App\Support\ZamanliKayit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sablon salonun form ve hizmetlerini ayni turdeki yeni salona kopyalar (eski varsayilanFormlariKopyala,
 * varsayilanHizmetleriKopyala). Tur eslesmezse hicbir sey yapmaz: guzellik sablonu yalniz guzellik salonuna gider.
 */
class VarsayilanKopyalayici
{
    public function kopyala(int $hedefSalonId, ?int $salonTuruId): void
    {
        $this->formlar($hedefSalonId, $salonTuruId);
        $this->hizmetler($hedefSalonId, $salonTuruId);
    }

    private function formlar(int $hedef, ?int $salonTuruId): void
    {
        $kaynak = (int) config('oturum.deneme.form_sablon_salon_id');
        if (! $this->ayniTur($kaynak, $salonTuruId)) {
            return;
        }
        $sira = 0;
        foreach (FormTaslaklari::where('salon_id', $kaynak)->where('is_dinamik', 1)->orderBy('sira')->orderBy('id')->get() as $form) {
            $yeni = $form->replicate();
            $yeni->salon_id = $hedef;
            $yeni->sira = ++$sira;
            $yeni->save();
        }
    }

    private function hizmetler(int $hedef, ?int $salonTuruId): void
    {
        $kaynak = (int) config('oturum.deneme.hizmet_sablon_salon_id')
            ?: (int) DB::table('salonlar')->where('salon_adi', config('oturum.deneme.hizmet_sablon_salon_adi'))->value('id');
        if (! $this->ayniTur($kaynak, $salonTuruId)) {
            return;
        }
        $aktifVar = Schema::hasColumn('salon_sunulan_hizmetler', 'aktif');
        $hizmetler = DB::table('salon_sunulan_hizmetler')->where('salon_id', $kaynak)
            ->get(['hizmet_id', 'hizmet_kategori_id', 'baslangic_fiyat', 'son_fiyat', 'bolum'])
            ->unique('hizmet_id');
        foreach ($hizmetler as $h) {
            ZamanliKayit::ekle('salon_sunulan_hizmetler', (array) $h + ['salon_id' => $hedef] + ($aktifVar ? ['aktif' => 1] : []));
        }
    }

    private function ayniTur(int $kaynakSalonId, ?int $salonTuruId): bool
    {
        return $kaynakSalonId > 0 && $salonTuruId !== null
            && (int) DB::table('salonlar')->where('id', $kaynakSalonId)->value('salon_turu_id') === $salonTuruId;
    }
}
