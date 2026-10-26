<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use LibreNmsPlugin\PanosVpn\Http\Controllers\SettingsController;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::post('plugin/panos-vpn/settings', [SettingsController::class, 'update'])->name('panos-vpn.settings');
    Route::post('plugin/panos-vpn/test', [SettingsController::class, 'test'])->name('panos-vpn.test');
});
