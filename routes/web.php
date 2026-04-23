<?php

use App\Http\Controllers\DashboardDrillDownCsvController;
use App\Http\Controllers\LeadImportRejectionsController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware(['auth', 'signed'])
    ->get('/lead-imports/{batch}/rejections', [LeadImportRejectionsController::class, 'show'])
    ->name('lead-imports.rejections');

Route::middleware(['auth'])->group(function () {
    Route::get('/admin/dashboard/drill-csv', DashboardDrillDownCsvController::class)
        ->name('admin.dashboard.drill-csv');
});
