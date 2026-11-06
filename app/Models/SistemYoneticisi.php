<?php

namespace App\Models;

use App\Domain\SistemYonetim\SistemRolu;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** Sistem yonetimi ekibi — eski monolitle PAYLASILAN 'sistemyoneticileri' tablosu. Giris alani: email. */
class SistemYoneticisi extends Authenticatable
{
    protected $table = 'sistemyoneticileri';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    /** Rol kolonu bos eski kayitlarda admin bayragi belirler (eski PanelController::rol). */
    public function rol(): SistemRolu
    {
        return SistemRolu::tryFrom((string) $this->rol) ?? ((int) $this->admin === 1 ? SistemRolu::SuperAdmin : SistemRolu::Destek);
    }
}
