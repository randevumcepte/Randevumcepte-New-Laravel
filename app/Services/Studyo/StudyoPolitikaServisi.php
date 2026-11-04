<?php

namespace App\Services\Studyo;

use App\Domain\Ders\KatilimciDurumu;
use App\Domain\Studyo\StudyoPolitikasi;
use App\Support\PanelMenu;
use Illuminate\Support\Facades\DB;

/** Salon bayraklarindan politika + istemciye (web/Flutter) donen sozlesme. */
class StudyoPolitikaServisi
{
    public function getir(int $salonId): StudyoPolitikasi
    {
        $s = DB::table('salonlar')->where('id', $salonId)->first(['studyo_modu', 'grup_dersi_aktif', 'musteri_online_randevu_aktif']);

        return new StudyoPolitikasi(
            (int) ($s->studyo_modu ?? 0) === 1,
            (int) ($s->grup_dersi_aktif ?? 0) === 1,
            (int) ($s->musteri_online_randevu_aktif ?? 0) === 1,
        );
    }

    public function sozlesme(int $salonId): array
    {
        $politika = $this->getir($salonId);
        $gizliMenuler = $politika->studyo
            ? array_values(array_column(array_filter(PanelMenu::tanim(), fn ($m) => $m['studyo_gizle'] ?? false), 'slug'))
            : [];

        return $politika->dizi() + ['gizli_menuler' => $gizliMenuler, 'katilimci_durumlari' => KatilimciDurumu::sozluk()];
    }
}
