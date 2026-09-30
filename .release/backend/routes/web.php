<?php

use App\Http\Controllers\PublicImageController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/sitemap.xml', SitemapController::class);
Route::get('/storage/{directory}/{filename}', PublicImageController::class)
    ->whereIn('directory', ['products', 'categories'])
    ->where('filename', '[^/]+');
