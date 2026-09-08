<?php

use App\Http\Controllers\V2\V2AgencyController;
use App\Http\Controllers\V2\V2AgencyAggregationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['v2.browser:agency', 'v2.realm:agency'])->group(function (): void {
    Route::get('/aggregates/users', [V2AgencyAggregationController::class, 'agencyUsers']);
    Route::get('/aggregates/sales', [V2AgencyAggregationController::class, 'agencySales']);
    Route::get('/auth/session', [V2AgencyController::class, 'session']);
    Route::post('/auth/login', [V2AgencyController::class, 'login']);
    Route::post('/auth/logout', [V2AgencyController::class, 'logout']);
    Route::get('/me', [V2AgencyController::class, 'profile']);
    Route::patch('/me/contact', [V2AgencyController::class, 'contact']);
    Route::post('/me/email', [V2AgencyController::class, 'email']);
    Route::post('/me/password', [V2AgencyController::class, 'password']);
});
