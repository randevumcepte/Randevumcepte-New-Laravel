<?php

namespace App\Domain\SistemYonetim;

/** Salon panelindeki paket kartinin temasi (pricing-table-{class} / btn-{class}). */
enum SmsPaketKartRengi: string
{
    use EtiketliSecenek;

    case Primary = 'primary';
    case Success = 'success';
    case Info = 'info';
    case Warning = 'warning';
    case Danger = 'danger';

    public function etiket(): string
    {
        return ucfirst($this->value);
    }

    public function renk(): string
    {
        return 'info';
    }
}
