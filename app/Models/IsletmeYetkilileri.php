<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Isletme yetkilisi — eski monolitle PAYLASILAN 'isletmeyetkilileri' tablosu.
 *
 * Tahsilat/satis islemlerini yapan ISLETME paneli kullanicisi budur. Eski
 * login (AuthController@login, usertype=1) bu model uzerinden Passport personal
 * access token uretir; yeni app 'isletmeyonetim-api' guard'i ile o token'i
 * dogrulayip kullaniciyi id ile cozer (retrieveById).
 *
 * Giris alani: gsm1. Sema eski sistemce yonetilir; burada migrate YOK.
 */
class IsletmeYetkilileri extends Authenticatable
{
    protected $table = 'isletmeyetkilileri';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];
}
