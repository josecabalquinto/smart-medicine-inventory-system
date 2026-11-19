<?php

use App\Http\Controllers\DeviceController;
use App\Http\Middleware\AuthenticateDevice;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/device')->middleware(['throttle:120,1', AuthenticateDevice::class])->group(function () {
    Route::post('readings', [DeviceController::class, 'reading']);
    Route::post('calibrations', [DeviceController::class, 'calibration']);
    Route::get('commands', [DeviceController::class, 'commands']);
    Route::post('commands/{command}/ack', [DeviceController::class, 'ack']);
});
