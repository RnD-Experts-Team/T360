<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\Acceptance\RejectionsController;

Route::controller(RejectionsController::class)->group(function () {
    // Acceptance / Rejections
    Route::prefix('acceptance')->group(function () {
        Route::get('/', 'index')->name('acceptance.index.admin');
        Route::post('/', 'store')->name('acceptance.store.admin');
        Route::delete('-bulk', 'destroyBulkAdmin')->name('acceptance.destroyBulk.admin');

        // Import and export actions for superadmin
        Route::post('/validate-advanced-block-import', 'validateAdvancedBlockImport')
            ->name('acceptance.validateAdvancedBlockImport.admin');

        Route::post('/confirm-advanced-block-import', 'confirmAdvancedBlockImport')
            ->name('acceptance.confirmAdvancedBlockImport.admin');

        Route::post('/validate-block-import', 'validateBlockImport')
            ->name('acceptance.validateBlockImport.admin');

        Route::post('/confirm-block-import', 'confirmBlockImport')
            ->name('acceptance.confirmBlockImport.admin');

        Route::post('/validate-load-import', 'validateLoadImport')
            ->name('acceptance.validateLoadImport.admin');

        Route::post('/confirm-load-import', 'confirmLoadImport')
            ->name('acceptance.confirmLoadImport.admin');

        Route::get('/download-error-report', 'downloadErrorReport')
            ->name('acceptance.downloadErrorReport.admin');

        Route::get('/export', 'exportAdmin')->name('acceptance.export.admin');
        Route::put('{rejection}', 'updateAdmin')->name('acceptance.update.admin');
        Route::delete('{rejection}', 'destroyAdmin')->name('acceptance.destroy.admin');
    });
});
