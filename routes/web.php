<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'home');

Route::get('/api-docs', function () {
    return response()->file(public_path('api-docs.html'), [
        'Content-Type' => 'text/html; charset=UTF-8',
        'X-Robots-Tag' => 'noindex, nofollow, noarchive',
    ]);
});

Route::get('/operations-api-docs', function () {
    return response()->file(public_path('operations-api-docs.html'), [
        'Content-Type' => 'text/html; charset=UTF-8',
        'X-Robots-Tag' => 'noindex, nofollow, noarchive',
    ]);
});
