<?php

use Illuminate\Support\Facades\Route;

// FASE 6B: la raiz de la API y su trailing slash devuelven 404 JSON.
// Sin esto, /api y /api/ caerian en el catch-all SPA (any='' matchea la regex).
Route::match(['get', 'post'], 'api', function () {
    return response()->json(['message' => 'Not Found.'], 404);
});
Route::match(['get', 'post'], 'api/', function () {
    return response()->json(['message' => 'Not Found.'], 404);
});

// SPA: serve Vue frontend for root
Route::get('/{any?}', function () {
    $html = public_path('index.html');

    return file_exists($html) ? file_get_contents($html) : view('welcome');
})->where('any', '^(?!api).*$');
