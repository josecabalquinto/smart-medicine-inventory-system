<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');
Route::middleware(['auth', 'verified'])->group(function () {
    foreach (['dashboard', 'medicines', 'count-stock', 'calibration-profiles', 'count-history', 'users', 'inventory-settings', 'simulator'] as $page) {
        Route::livewire($page, 'pages::'.$page)->name($page);
    }
});
require __DIR__.'/settings.php';
