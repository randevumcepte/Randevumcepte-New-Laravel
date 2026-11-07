<?php

use App\Http\Controllers\Api\V2\HatirlatmaController;
use Illuminate\Support\Facades\Route;

/* SALON HATIRLATMA KARTLARI — routes/api.php korumalı grubundan (auth:isletmeyonetim-api) dahil edilir. */
Route::get('/hatirlatma-feed', [HatirlatmaController::class, 'feed']);
