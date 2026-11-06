<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Services\SistemYonetim\Izleme\SistemSaglikServisi;
use App\Support\ApiCevap;

/** GET /sistem/sistem-saglik → {veritabani, php_surum, laravel_surum, disk, whatsapp_aktif, log_hatalari[]} */
class SistemSaglikController extends SistemTabanController
{
    public function __construct(private SistemSaglikServisi $servis) {}

    public function rapor()
    {
        return ApiCevap::basarili($this->servis->rapor());
    }
}
