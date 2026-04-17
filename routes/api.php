<?php

use App\Http\Controllers\LeadController;
use App\Http\Middleware\VerifyLeadToken;
use Illuminate\Support\Facades\Route;

Route::post('/leads', [LeadController::class, 'store'])
    ->middleware([VerifyLeadToken::class, 'throttle:60,1']);
