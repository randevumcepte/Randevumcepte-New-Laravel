<?php

namespace App\Domain\Studyo;

use App\Domain\Ders\YayinAraligi;

/**
 * Stüdyo modu (Pilates/kurs) kurallari — SAF, TEK kaynak. Salon bayraklarindan
 * "bu isletmede ne gecerli" sorusunu yanitlar; web ve Flutter bayragi yorumlamak yerine
 * bu kararlari okur (GET /studyo/politika). Yeni kural buraya eklenir, istemciye degil.
 *
 * Fiyat gizleme MODEL B: fiyat sistemde TUTULUR, hicbir ekranda gosterilmez; "Odeme Alindi"
 * adisyonu tam tahsilatla kapatir. Stüdyoda fiyat pratikte 0 girildigi icin acik/kapali
 * kalandan TUREMEZ — tek sinyal adisyonlar.odendi.
 */
final class StudyoPolitikasi
{
    /** Menu disinda gizlenen moduller (menudekiler PanelMenu.studyo_gizle'den gelir). */
    private const GIZLI_OZELLIKLER = ['prim_hakedis', 'urun_satisi', 'fatura', 'belgeler', 'gap_kampanya'];

    public function __construct(
        public readonly bool $studyo,
        public readonly bool $grupDersi,
        public readonly bool $onlineRandevu,
    ) {}

    public function fiyatGizli(): bool
    {
        return $this->studyo;
    }

    /** 'ikili' = Odeme Alindi/Alinmadi; 'tutar' = normal tahsilat. */
    public function odemeModu(): string
    {
        return $this->studyo ? 'ikili' : 'tutar';
    }

    public function acikKapaliOdendidenMi(): bool
    {
        return $this->studyo;
    }

    public function noShowSeansDuser(): bool
    {
        return $this->studyo;
    }

    public function tekrarliKatilim(): bool
    {
        return $this->studyo && $this->grupDersi;
    }

    public function onlineRezervasyon(): bool
    {
        return $this->studyo && $this->grupDersi && $this->onlineRandevu;
    }

    /** @return string[] */
    public function gizliOzellikler(): array
    {
        return $this->studyo ? self::GIZLI_OZELLIKLER : [];
    }

    public function dizi(): array
    {
        return [
            'studyo_modu'         => $this->studyo,
            'grup_dersi_aktif'    => $this->grupDersi,
            'fiyat_gizli'         => $this->fiyatGizli(),
            'odeme_modu'          => $this->odemeModu(),
            'no_show_seans_duser' => $this->noShowSeansDuser(),
            'tekrarli_katilim'    => $this->tekrarliKatilim(),
            'online_rezervasyon'  => $this->onlineRezervasyon(),
            'yayin_maks_hafta'    => YayinAraligi::maksHafta($this->studyo),
            'yayin_bitis_tarihi'  => $this->studyo,
            'gizli_ozellikler'    => $this->gizliOzellikler(),
        ];
    }
}
