<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Asset\AssetController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Content\AnnouncementController;
use App\Http\Controllers\Api\Content\StaticPageController;
use App\Http\Controllers\Api\Content\TopBannerController;
use App\Http\Controllers\Api\ContactRequestController;
use App\Http\Controllers\Api\Gacha\DrawController;
use App\Http\Controllers\Api\Gacha\GachaController;
use App\Http\Controllers\Api\Gacha\GachaTagController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\LineWebhookController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\PointController;
use App\Http\Controllers\Api\PointPurchasePlanController;
use App\Http\Controllers\Api\PointLedgerController;
use App\Http\Controllers\Api\ShippingRequestController;
use App\Http\Controllers\Api\SmsVerificationController;
use App\Http\Controllers\Api\UserPrizeExchangeController;
use App\Http\Controllers\Api\UserPrizeController;
use App\Http\Controllers\Api\UserDrawRequestController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');
Route::get('/assets/{path}', AssetController::class)->where('path', '.*')->name('api.assets.show');
Route::get('/announcements', [AnnouncementController::class, 'index'])->name('api.announcements.index');
Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show'])->name('api.announcements.show');
Route::get('/static-pages/{slug}', [StaticPageController::class, 'show'])->name('api.static-pages.show');
Route::post('/contact-requests', [ContactRequestController::class, 'store'])->name('api.contact-requests.store');
Route::get('/point-purchase-plans', [PointPurchasePlanController::class, 'index'])->name('api.point-purchase-plans.index');
Route::get('/gacha-tags', [GachaTagController::class, 'index'])->name('api.gacha-tags.index');
Route::get('/top-banners', [TopBannerController::class, 'index'])->name('api.top-banners.index');
Route::get('/gachas', [GachaController::class, 'index'])->name('api.gachas.index');
Route::get('/gachas/{gacha}', [GachaController::class, 'show'])->name('api.gachas.show');
Route::post('/register', [AuthController::class, 'register'])->name('api.register');
Route::post('/login', [AuthController::class, 'login'])->name('api.login');
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('api.auth.google.redirect');
Route::post('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('api.auth.google.callback');
Route::post('/auth/google/register', [GoogleAuthController::class, 'register'])->name('api.auth.google.register');
Route::get('/email/verify/{user}/{hash}', [AuthController::class, 'verifyEmail'])->name('api.email.verify');
Route::post('/email/verification-notification', [AuthController::class, 'resendEmailVerification'])->name('api.email.verification.resend');
Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->name('api.password.forgot');
Route::post('/password/reset', [AuthController::class, 'resetPassword'])->name('api.password.reset');
Route::post('/payments/webhook', [PaymentWebhookController::class, 'handle'])->name('api.payments.webhook');
Route::post('/line/webhook', LineWebhookController::class)->name('api.line.webhook');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', [MeController::class, 'show'])->name('api.me');
    Route::put('/me/profile', [MeController::class, 'updateProfile'])->name('api.me.profile.update');
    Route::get('/me/sms-verification', [SmsVerificationController::class, 'show'])->name('api.me.sms-verification.show');
    Route::post('/me/sms-verification', [SmsVerificationController::class, 'send'])->name('api.me.sms-verification.send');
    Route::post('/me/sms-verification/resend', [SmsVerificationController::class, 'resend'])->name('api.me.sms-verification.resend');
    Route::post('/me/sms-verification/verify', [SmsVerificationController::class, 'verify'])->name('api.me.sms-verification.verify');
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('/me/points', [PointController::class, 'index'])->name('api.me.points');
    Route::get('/me/point-ledgers', [PointLedgerController::class, 'index'])->name('api.me.point-ledgers');
    Route::get('/me/draw-requests', [UserDrawRequestController::class, 'index'])->name('api.me.draw-requests.index');
    Route::get('/me/draw-requests/{drawRequest}', [UserDrawRequestController::class, 'show'])->name('api.me.draw-requests.show');
    Route::get('/me/prizes', [UserPrizeController::class, 'index'])->name('api.me.prizes');
    Route::post('/me/prizes/{userPrize}/exchange', [UserPrizeExchangeController::class, 'store'])->name('api.me.prizes.exchange');
    Route::get('/me/shipping-requests', [ShippingRequestController::class, 'index'])->name('api.me.shipping-requests.index');
    Route::get('/me/shipping-requests/{shippingRequest}', [ShippingRequestController::class, 'show'])->name('api.me.shipping-requests.show');
    Route::post('/me/shipping-requests', [ShippingRequestController::class, 'store'])->name('api.me.shipping-requests.store');

    Route::post('/payments', [PaymentController::class, 'store'])->name('api.payments.store');
    Route::post('/payments/{payment}/mock-succeed', [PaymentController::class, 'mockSucceed'])->name('api.payments.mock-succeed');

    Route::post('/gachas/{gacha}/draw', [DrawController::class, 'store'])->name('api.gachas.draw');
});
