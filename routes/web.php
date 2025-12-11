<?php

use App\Http\Controllers\PaymongoReturnController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/payment-success', PaymongoReturnController::class);

// Redirect /storage/ requests to /api/storage/ for backward compatibility
Route::get('/storage/{path}', function ($path) {
    return redirect('/api/storage/' . $path, 301);
})->where('path', '.*');
