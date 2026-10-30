<?php

use App\Http\Controllers\Api\LeadController;
use App\Http\Middleware\AuthenticateLeadApiToken;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:60,1', AuthenticateLeadApiToken::class])
    ->post('/leads', [LeadController::class, 'store'])
    ->name('api.leads.store');
