<?php

use App\Http\Controllers\Api\V2\FormPdfController;
use Illuminate\Support\Facades\Route;

/*
 | FORM PDF — işletme token'ı. routes/api.php 'auth:isletmeyonetim-api' grubundan dahil edilir.
 */
Route::get('/form/arsiv/{id}/pdf', [FormPdfController::class, 'arsiv'])->whereNumber('id');
Route::get('/form/sablon/{id}/bos-pdf-link', [FormPdfController::class, 'bosLink'])->whereNumber('id');
