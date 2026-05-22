<?php

use App\Http\Controllers\DashboardDrillDownCsvController;
use App\Http\Controllers\LeadImportRejectionsController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/dev-login', function () {
    if (! app()->environment('local')) {
        abort(404);
    }
    $user = User::where('email', 'sumit@davya.local')->firstOrFail();
    auth()->login($user, remember: true);

    return redirect(request()->query('to', '/admin'));
});

Route::middleware(['auth', 'signed'])
    ->get('/lead-imports/{batch}/rejections', [LeadImportRejectionsController::class, 'show'])
    ->name('lead-imports.rejections');

Route::middleware(['auth'])->group(function () {
    Route::get('/admin/dashboard/drill-csv', DashboardDrillDownCsvController::class)
        ->name('admin.dashboard.drill-csv');
});

Route::middleware(['web', 'auth'])->group(function () {
    Route::post('/ai/ask', [\App\Http\Controllers\AiAssistantController::class, 'ask'])
        ->name('ai.ask');
});
