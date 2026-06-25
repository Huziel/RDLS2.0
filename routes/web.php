<?php

use Illuminate\Support\Facades\Route;

// SPA: serve Vue frontend for root
Route::get('/{any?}', function () {
    $html = public_path('index.html');
    return file_exists($html) ? file_get_contents($html) : view('welcome');
})->where('any', '^(?!api).*$');
