<?php

/*
 * Control panel routes. Craft loads this file with the `web, craft, craft.cp, auth, can:accessCp`
 * middleware and the CP trigger prefix.
 */

use craftquest\featureflags\Http\Controllers\AuditController;
use craftquest\featureflags\Http\Controllers\FlagsController;
use Illuminate\Support\Facades\Route;

Route::prefix('feature-flags')->name('feature-flags.')->middleware('can:featureFlags:view')->group(function () {
    Route::get('/', [FlagsController::class, 'index'])->name('index');
    Route::get('audit/{flagId?}', [AuditController::class, 'index'])->whereNumber('flagId')->name('audit');

    // Toggle or manage; checked in the controller.
    Route::post('status', [FlagsController::class, 'setStatus'])->name('status');

    Route::middleware('can:featureFlags:manage')->group(function () {
        Route::get('new', [FlagsController::class, 'create'])->name('create');
        Route::post('/', [FlagsController::class, 'store'])->name('store');
        Route::post('ui', [FlagsController::class, 'renderUi'])->name('render-ui');
        Route::delete('{flagId}', [FlagsController::class, 'destroy'])->whereNumber('flagId')->name('destroy');
        Route::get('{flagId}', [FlagsController::class, 'edit'])->whereNumber('flagId')->name('edit');
    });

    // The 1.x settings URL.
    Route::get('settings', fn() => redirect()->to(\CraftCms\Cms\cp_url('settings/plugins/feature-flags')))->name('settings');
});
