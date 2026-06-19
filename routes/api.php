<?php

use App\Http\Controllers\LeadController;
use App\Http\Controllers\FinancePaymentController;
use App\Http\Controllers\FinanceExpenseController;
use App\Http\Controllers\FinanceInvestmentController;
use App\Http\Controllers\FinanceFailedController;
use App\Http\Controllers\FinanceAssistantController;
use App\Http\Controllers\FinanceNoteController;
use App\Http\Middleware\VerifyLeadToken;
use App\Http\Middleware\VerifyFinanceToken;
use Illuminate\Support\Facades\Route;

// Phase 1 — Lead Capture
Route::post('/leads', [LeadController::class, 'store'])
    ->middleware([VerifyLeadToken::class, 'throttle:60,1']);

// Phase 2 — Finance
Route::prefix('finance')
    ->middleware([VerifyFinanceToken::class, 'throttle:60,1'])
    ->group(function () {
        Route::post('/payments',    [FinancePaymentController::class,    'store']);
        Route::post('/expenses',    [FinanceExpenseController::class,    'store']);
        Route::post('/notes',       [FinanceNoteController::class,       'store']);
        Route::post('/investments', [FinanceInvestmentController::class, 'store']);
        Route::post('/failed',      [FinanceFailedController::class,     'store']);
        Route::post('/assistant',   [FinanceAssistantController::class,  'handle']);
    });
