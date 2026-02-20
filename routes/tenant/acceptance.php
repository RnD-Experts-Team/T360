<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\Acceptance\RejectionsController;

Route::controller(RejectionsController::class)
    ->prefix('acceptance')
    ->name('acceptance.')
    ->group(function () {

        Route::get('/', 'index')
            ->name('index')
            ->middleware('permission:acceptance.view');

        Route::post('/', 'store')
            ->name('store')
            ->middleware('permission:acceptance.create');
        Route::delete('-bulk', 'destroyBulk')
            ->name('destroyBulk')
            ->middleware('permission:acceptance.delete');

        // Import and export actions for users
        Route::post('/validate-advanced-block-import', 'validateAdvancedBlockImport')
            ->name('validateAdvancedBlockImport')
            ->middleware('permission:acceptance.import');

        Route::post('/confirm-advanced-block-import', 'confirmAdvancedBlockImport')
            ->name('confirmAdvancedBlockImport')
            ->middleware('permission:acceptance.import');

        Route::post('/validate-block-import', 'validateBlockImport')
            ->name('validateBlockImport')
            ->middleware('permission:acceptance.import');

        Route::post('/confirm-block-import', 'confirmBlockImport')
            ->name('confirmBlockImport')
            ->middleware('permission:acceptance.import');

        Route::post('/validate-load-import', 'validateLoadImport')
            ->name('validateLoadImport')
            ->middleware('permission:acceptance.import');

        Route::post('/confirm-load-import', 'confirmLoadImport')
            ->name('confirmLoadImport')
            ->middleware('permission:acceptance.import');

        Route::get('/download-error-report', 'downloadErrorReport')
            ->name('downloadErrorReport')
            ->middleware('permission:acceptance.import');

        Route::get('/export', 'export')
            ->name('export')
            ->middleware('permission:acceptance.export');
        Route::put('{rejection}', 'update')
            ->name('update')
            ->middleware('permission:acceptance.update');

        Route::delete('{rejection}', 'destroy')
            ->name('destroy')
            ->middleware('permission:acceptance.delete');
    });
