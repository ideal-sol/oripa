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
use App\Http\Controllers\V2\V2PublicAuthController;
use App\Http\Controllers\V2\V2CatalogController;
use App\Http\Controllers\V2\V2DrawController;
use App\Http\Controllers\V2\V2PrizeShippingController;
use App\Http\Controllers\V2\V2ContentContactController;
use App\Http\Controllers\V2\V2CurrentUserPointController;
use App\Http\Controllers\V2\V2PointProductController;

Route::prefix('v2')->group(function (): void {
    Route::get('/content/banners', [V2ContentContactController::class, 'banners'])
        ->name('v2.public.content.banners');
    Route::get('/content/assets/{assetId}', [V2ContentContactController::class, 'assetContent'])
        ->whereUuid('assetId')->name('v2.public.content.assets.show');
    Route::get('/content/notices', [V2ContentContactController::class, 'notices'])
        ->name('v2.public.content.notices');
    Route::get('/content/notices/{noticeId}', [V2ContentContactController::class, 'notice'])
        ->whereUuid('noticeId')->name('v2.public.content.notices.show');
    Route::get('/content/footer-pages', [V2ContentContactController::class, 'footerPages'])
        ->name('v2.public.content.footer-pages.index');
    Route::get('/content/pages/{slug}', [V2ContentContactController::class, 'staticPage'])
        ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('v2.public.content.pages.show');
    Route::get('/gacha-categories', [V2CatalogController::class, 'categories'])
        ->name('v2.public.catalog.categories');
    Route::get('/gacha-tags', [V2CatalogController::class, 'tags'])
        ->name('v2.public.catalog.tags');
    Route::get('/point-products', [V2PointProductController::class, 'index'])
        ->middleware('v2.browser:user')
        ->name('v2.public.point-products.index');
    Route::get('/gachas', [V2CatalogController::class, 'index'])
        ->middleware('v2.browser:user')
        ->name('v2.public.catalog.gachas');
    Route::get('/gachas/by-slug/{slug}', [V2CatalogController::class, 'showBySlug'])
        ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('v2.public.catalog.gachas.by-slug');
    Route::get('/gachas/{gachaId}', [V2CatalogController::class, 'show'])
        ->where(
            'gachaId',
            '(?:[A-Za-z0-9]{11}|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12})'
        )
        ->name('v2.public.catalog.gachas.show');
});

Route::prefix('v2')
    ->middleware('v2.browser:user')
    ->group(function (): void {
        Route::get('/gacha-presentations/{gachaId}', [
            V2CatalogController::class,
            'presentation',
        ])->where(
            'gachaId',
            '(?:[A-Za-z0-9]{11}|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12})'
        )
            ->name('v2.public.catalog.gachas.presentation');
        Route::post('/contact-inquiries', [V2ContentContactController::class, 'contact'])
            ->name('v2.public.contacts.store');
    });

Route::prefix('v2')
    ->middleware(['v2.browser:user', 'v2.realm:user'])
    ->group(function (): void {
        Route::post('/gachas/{gachaId}/draws', [V2DrawController::class, 'store'])
            ->whereUuid('gachaId')
            ->name('v2.public.draws.store');
        Route::get('/draw-requests/{drawRequestId}', [V2DrawController::class, 'show'])
            ->whereUuid('drawRequestId')
            ->name('v2.public.draws.show');
        Route::get('/me/draws', [V2DrawController::class, 'history'])
            ->name('v2.public.draws.history');
        Route::get('/me/wallet', [V2CurrentUserPointController::class, 'wallet'])
            ->name('v2.public.wallet.show');
        Route::get('/me/point-ledgers', [V2CurrentUserPointController::class, 'history'])
            ->name('v2.public.point-ledgers.index');
        Route::get('/me/prizes', [V2PrizeShippingController::class, 'prizes'])
            ->name('v2.public.prizes.index');
        Route::get('/me/prizes/{prizeId}', [V2PrizeShippingController::class, 'prize'])
            ->whereUuid('prizeId')
            ->name('v2.public.prizes.show');
        Route::post('/me/prizes/exchange', [V2PrizeShippingController::class, 'exchange'])
            ->name('v2.public.prizes.exchange');
        Route::get('/me/shipping-addresses', [V2PrizeShippingController::class, 'addresses'])
            ->name('v2.public.shipping-addresses.index');
        Route::post('/me/shipping-addresses', [V2PrizeShippingController::class, 'createAddress'])
            ->name('v2.public.shipping-addresses.store');
        Route::get('/me/shipping-addresses/{addressId}', [V2PrizeShippingController::class, 'address'])
            ->whereUuid('addressId')
            ->name('v2.public.shipping-addresses.show');
        Route::put('/me/shipping-addresses/{addressId}', [V2PrizeShippingController::class, 'updateAddress'])
            ->whereUuid('addressId')
            ->name('v2.public.shipping-addresses.update');
        Route::delete('/me/shipping-addresses/{addressId}', [V2PrizeShippingController::class, 'deleteAddress'])
            ->whereUuid('addressId')
            ->name('v2.public.shipping-addresses.destroy');
        Route::get('/me/shipping-requests', [V2PrizeShippingController::class, 'shippingRequests'])
            ->name('v2.public.shipping-requests.index');
        Route::post('/me/shipping-requests', [V2PrizeShippingController::class, 'createShippingRequest'])
            ->name('v2.public.shipping-requests.store');
        Route::get('/me/shipping-requests/{shippingRequestId}', [V2PrizeShippingController::class, 'shippingRequest'])
            ->whereUuid('shippingRequestId')
            ->name('v2.public.shipping-requests.show');
        Route::get('/me/sms-verification', [V2PublicAuthController::class, 'smsStatus'])
            ->name('v2.public.sms-verification.show');
        Route::post('/me/sms-verification', [V2PublicAuthController::class, 'sendSms'])
            ->name('v2.public.sms-verification.send');
        Route::post('/me/sms-verification/resend', [V2PublicAuthController::class, 'resendSms'])
            ->name('v2.public.sms-verification.resend');
        Route::post('/me/sms-verification/verify', [V2PublicAuthController::class, 'verifySms'])
            ->name('v2.public.sms-verification.verify');
        Route::get('/me/external-identities', [V2PublicAuthController::class, 'linkedIdentities'])
            ->name('v2.public.external-identities.index');
        Route::post('/me/external-identities/google/link', [
            V2PublicAuthController::class,
            'startGoogleLink',
        ])->name('v2.public.external-identities.google.link');
        Route::post('/me/external-identities/google/reauthenticate', [
            V2PublicAuthController::class,
            'startGoogleReauthentication',
        ])->name('v2.public.external-identities.google.reauthenticate');
        Route::delete('/me/external-identities/google', [
            V2PublicAuthController::class,
            'unlinkGoogle',
        ])->name('v2.public.external-identities.google.destroy');
        Route::post('/me/external-identities/line/link', [
            V2PublicAuthController::class,
            'startLineLink',
        ])->name('v2.public.external-identities.line.link');
        Route::post('/me/external-identities/line/reauthenticate', [
            V2PublicAuthController::class,
            'startLineReauthentication',
        ])->name('v2.public.external-identities.line.reauthenticate');
        Route::delete('/me/external-identities/line', [
            V2PublicAuthController::class,
            'unlinkLine',
        ])->name('v2.public.external-identities.line.destroy');
        Route::post('/me/password/reauthenticate', [
            V2PublicAuthController::class,
            'reauthenticatePassword',
        ])->name('v2.public.password.reauthenticate');
    });

Route::prefix('v2/auth')
    ->middleware('v2.browser:user')
    ->group(function (): void {
        Route::post('/register', [V2PublicAuthController::class, 'register'])
            ->name('v2.public.auth.register');
        Route::post('/login', [V2PublicAuthController::class, 'login'])
            ->name('v2.public.auth.login');
        Route::post('/external/google/start', [
            V2PublicAuthController::class,
            'startGoogleLogin',
        ])->name('v2.public.auth.external.google.start');
        Route::get('/external/google/callback', [
            V2PublicAuthController::class,
            'completeGoogle',
        ])->name('v2.public.auth.external.google.callback');
        Route::post('/external/line/start', [
            V2PublicAuthController::class,
            'startLineLogin',
        ])->name('v2.public.auth.external.line.start');
        Route::get('/external/line/callback', [
            V2PublicAuthController::class,
            'completeLine',
        ])->name('v2.public.auth.external.line.callback');
        Route::post('/password/forgot', [
            V2PublicAuthController::class,
            'requestPasswordReset',
        ])->name('v2.public.auth.password-reset.request');
        Route::post('/password/reset', [
            V2PublicAuthController::class,
            'confirmPasswordReset',
        ])->name('v2.public.auth.password-reset.confirm');
        Route::post('/logout', [V2PublicAuthController::class, 'logout'])
            ->name('v2.public.auth.logout');
        Route::post('/email/verification-notification', [
            V2PublicAuthController::class,
            'resendVerification',
        ])->name('v2.public.auth.verification.resend');
        Route::get('/email/verify/{userId}/{hash}', [V2PublicAuthController::class, 'verify'])
            ->whereUuid('userId')
            ->where('hash', '[0-9a-f]{64}')
            ->name('v2.public.auth.verification.verify');
        Route::get('/session', [V2PublicAuthController::class, 'session'])
            ->name('v2.public.auth.session');
    });

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
