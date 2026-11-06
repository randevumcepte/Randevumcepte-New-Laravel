<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Services\SistemYonetim\SistemOturumServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/** POST /sistem/oturum/giris {email, password}; POST /sistem/oturum/cikis. */
class SistemOturumController extends SistemTabanController
{
    public function giris(Request $request, SistemOturumServisi $servis)
    {
        $g = $this->alanlariDogrula($request, ['email' => ['required', 'email', 'max:190'], 'password' => ['required', 'string', 'max:200']]);

        return ApiCevap::basarili($servis->giris($g['email'], $g['password'], (string) $request->ip(), (string) $request->header('User-Agent')));
    }

    public function cikis(SistemOturumServisi $servis)
    {
        $servis->cikis($this->aktifTokenId());

        return ApiCevap::basarili();
    }
}
