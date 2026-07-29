<?php

use App\Http\Controllers\V2\V2LineMessagingWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/v2/line', V2LineMessagingWebhookController::class)
    ->name('v2.webhook.line');
