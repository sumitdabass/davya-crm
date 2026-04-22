<?php

use App\Http\Controllers\LeadImportRejectionsController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware(['auth', 'signed'])
    ->get('/lead-imports/{batch}/rejections', [LeadImportRejectionsController::class, 'show'])
    ->name('lead-imports.rejections');
