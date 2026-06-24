<?php

use App\Http\Controllers\Admin\Auth\AdminAuthController;
use App\Http\Controllers\Admin\Asset\AdminAssetUploadController;
use App\Http\Controllers\Admin\Audit\AdminAuditLogController;
use App\Http\Controllers\Admin\Draw\AdminDrawRequestController;
use App\Http\Controllers\Admin\Draw\AdminDrawResultController;
use App\Http\Controllers\Admin\Content\AdminAnnouncementController;
use App\Http\Controllers\Admin\Content\AdminContactRequestController;
use App\Http\Controllers\Admin\Content\AdminStaticPageController;
use App\Http\Controllers\Admin\Gacha\AdminGachaCategoryController;
use App\Http\Controllers\Admin\Gacha\AdminGachaController;
use App\Http\Controllers\Admin\Gacha\AdminGachaPrizeController;
use App\Http\Controllers\Admin\Gacha\AdminGachaRankController;
use App\Http\Controllers\Admin\Gacha\AdminGachaTagController;
use App\Http\Controllers\Admin\Gacha\AdminGachaProfitSimulationController;
use App\Http\Controllers\Admin\Gacha\AdminGachaReadinessController;
use App\Http\Controllers\Admin\Gacha\AdminProbabilityController;
use App\Http\Controllers\Admin\Gacha\AdminRankAssetController;
use App\Http\Controllers\Admin\Gacha\AdminTopBannerController;
use App\Http\Controllers\Admin\Payment\AdminPaymentController;
use App\Http\Controllers\Admin\Payment\AdminPointPurchasePlanController;
use App\Http\Controllers\Admin\Point\AdminPointAdjustmentController;
use App\Http\Controllers\Admin\Prize\AdminUserPrizeController;
use App\Http\Controllers\Admin\Referral\AdminReferralSettingController;
use App\Http\Controllers\Admin\Referral\AdminUserReferralController;
use App\Http\Controllers\Admin\Shipping\AdminShippingItemController;
use App\Http\Controllers\Admin\Shipping\AdminShippingRequestController;
use App\Http\Controllers\Admin\User\AdminUserController;
use App\Http\Middleware\EnsureAdminUser;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AdminAuthController::class, 'login'])->name('admin.api.login');

Route::middleware(['auth:sanctum', EnsureAdminUser::class])->group(function (): void {
    Route::get('/me', [AdminAuthController::class, 'me'])->name('admin.api.me');
    Route::post('/logout', [AdminAuthController::class, 'logout'])->name('admin.api.logout');
    Route::post('/assets/images', AdminAssetUploadController::class)->name('admin.api.assets.images.store');
    Route::post('/assets/videos', AdminAssetUploadController::class)->name('admin.api.assets.videos.store');

    Route::get('/announcements', [AdminAnnouncementController::class, 'index'])->name('admin.api.announcements.index');
    Route::post('/announcements', [AdminAnnouncementController::class, 'store'])->name('admin.api.announcements.store');
    Route::get('/announcements/{announcement}', [AdminAnnouncementController::class, 'show'])->name('admin.api.announcements.show');
    Route::put('/announcements/{announcement}', [AdminAnnouncementController::class, 'update'])->name('admin.api.announcements.update');

    Route::get('/contact-requests', [AdminContactRequestController::class, 'index'])->name('admin.api.contact-requests.index');
    Route::get('/contact-requests/{contactRequest}', [AdminContactRequestController::class, 'show'])->name('admin.api.contact-requests.show');
    Route::put('/contact-requests/{contactRequest}', [AdminContactRequestController::class, 'update'])->name('admin.api.contact-requests.update');

    Route::get('/static-pages', [AdminStaticPageController::class, 'index'])->name('admin.api.static-pages.index');
    Route::get('/static-pages/{staticPage}', [AdminStaticPageController::class, 'show'])->name('admin.api.static-pages.show');
    Route::put('/static-pages/{staticPage}', [AdminStaticPageController::class, 'update'])->name('admin.api.static-pages.update');

    Route::get('/rank-assets', [AdminRankAssetController::class, 'index'])->name('admin.api.rank-assets.index');
    Route::post('/rank-assets', [AdminRankAssetController::class, 'store'])->name('admin.api.rank-assets.store');
    Route::get('/rank-assets/{rankAsset}', [AdminRankAssetController::class, 'show'])->name('admin.api.rank-assets.show');
    Route::put('/rank-assets/{rankAsset}', [AdminRankAssetController::class, 'update'])->name('admin.api.rank-assets.update');

    Route::get('/gacha-categories', [AdminGachaCategoryController::class, 'index'])->name('admin.api.gacha-categories.index');
    Route::post('/gacha-categories', [AdminGachaCategoryController::class, 'store'])->name('admin.api.gacha-categories.store');
    Route::get('/gacha-categories/{category}', [AdminGachaCategoryController::class, 'show'])->name('admin.api.gacha-categories.show');
    Route::put('/gacha-categories/{category}', [AdminGachaCategoryController::class, 'update'])->name('admin.api.gacha-categories.update');

    Route::get('/gacha-tags', [AdminGachaTagController::class, 'index'])->name('admin.api.gacha-tags.index');
    Route::post('/gacha-tags', [AdminGachaTagController::class, 'store'])->name('admin.api.gacha-tags.store');
    Route::get('/gacha-tags/{tag}', [AdminGachaTagController::class, 'show'])->name('admin.api.gacha-tags.show');
    Route::put('/gacha-tags/{tag}', [AdminGachaTagController::class, 'update'])->name('admin.api.gacha-tags.update');

    Route::get('/top-banners', [AdminTopBannerController::class, 'index'])->name('admin.api.top-banners.index');
    Route::post('/top-banners', [AdminTopBannerController::class, 'store'])->name('admin.api.top-banners.store');
    Route::patch('/top-banners/status', [AdminTopBannerController::class, 'bulkStatus'])->name('admin.api.top-banners.bulk-status');
    Route::get('/top-banners/{topBanner}', [AdminTopBannerController::class, 'show'])->name('admin.api.top-banners.show');
    Route::put('/top-banners/{topBanner}', [AdminTopBannerController::class, 'update'])->name('admin.api.top-banners.update');

    Route::get('/gachas', [AdminGachaController::class, 'index'])->name('admin.api.gachas.index');
    Route::post('/gachas', [AdminGachaController::class, 'store'])->name('admin.api.gachas.store');
    Route::get('/gachas/{gacha}', [AdminGachaController::class, 'show'])->name('admin.api.gachas.show');
    Route::put('/gachas/{gacha}', [AdminGachaController::class, 'update'])->name('admin.api.gachas.update');
    Route::get('/gachas/{gacha}/readiness', [AdminGachaReadinessController::class, 'show'])->name('admin.api.gachas.readiness');
    Route::get('/gachas/{gacha}/profit-simulation', [AdminGachaProfitSimulationController::class, 'show'])->name('admin.api.gachas.profit-simulation');

    Route::get('/gacha-ranks', [AdminGachaRankController::class, 'index'])->name('admin.api.gacha-ranks.index');
    Route::post('/gachas/{gacha}/ranks', [AdminGachaRankController::class, 'store'])->name('admin.api.gachas.ranks.store');
    Route::get('/gacha-ranks/{rank}', [AdminGachaRankController::class, 'show'])->name('admin.api.gacha-ranks.show');
    Route::put('/gacha-ranks/{rank}', [AdminGachaRankController::class, 'update'])->name('admin.api.gacha-ranks.update');

    Route::get('/gacha-prizes', [AdminGachaPrizeController::class, 'index'])->name('admin.api.gacha-prizes.index');
    Route::post('/gacha-ranks/{rank}/prizes', [AdminGachaPrizeController::class, 'store'])->name('admin.api.gacha-ranks.prizes.store');
    Route::get('/gacha-prizes/{prize}', [AdminGachaPrizeController::class, 'show'])->name('admin.api.gacha-prizes.show');
    Route::put('/gacha-prizes/{prize}', [AdminGachaPrizeController::class, 'update'])->name('admin.api.gacha-prizes.update');

    Route::get('/gachas/{gacha}/probability-matrix', [AdminProbabilityController::class, 'matrix'])->name('admin.api.gachas.probability-matrix');
    Route::post('/gachas/{gacha}/probability-versions/preview', [AdminProbabilityController::class, 'preview'])->name('admin.api.gachas.probability-versions.preview');
    Route::post('/gachas/{gacha}/probability-versions/publish', [AdminProbabilityController::class, 'publish'])->name('admin.api.gachas.probability-versions.publish');

    Route::get('/shipping-requests', [AdminShippingRequestController::class, 'index'])->name('admin.api.shipping-requests.index');
    Route::get('/shipping-requests/{shippingRequest}', [AdminShippingRequestController::class, 'show'])->name('admin.api.shipping-requests.show');
    Route::put('/shipping-requests/{shippingRequest}', [AdminShippingRequestController::class, 'update'])->name('admin.api.shipping-requests.update');
    Route::put('/shipping-items/{shippingItem}', [AdminShippingItemController::class, 'update'])->name('admin.api.shipping-items.update');

    Route::get('/payments', [AdminPaymentController::class, 'index'])->name('admin.api.payments.index');
    Route::get('/payments/{payment}', [AdminPaymentController::class, 'show'])->name('admin.api.payments.show');
    Route::post('/payments/{payment}/refund', [AdminPaymentController::class, 'refund'])->name('admin.api.payments.refund');
    Route::post('/payments/{payment}/chargeback', [AdminPaymentController::class, 'chargeback'])->name('admin.api.payments.chargeback');
    Route::get('/point-purchase-plans', [AdminPointPurchasePlanController::class, 'index'])->name('admin.api.point-purchase-plans.index');
    Route::post('/point-purchase-plans', [AdminPointPurchasePlanController::class, 'store'])->name('admin.api.point-purchase-plans.store');
    Route::get('/point-purchase-plans/{pointPurchasePlan}', [AdminPointPurchasePlanController::class, 'show'])->name('admin.api.point-purchase-plans.show');
    Route::put('/point-purchase-plans/{pointPurchasePlan}', [AdminPointPurchasePlanController::class, 'update'])->name('admin.api.point-purchase-plans.update');

    Route::get('/draw-requests', [AdminDrawRequestController::class, 'index'])->name('admin.api.draw-requests.index');
    Route::get('/draw-requests/{drawRequest}', [AdminDrawRequestController::class, 'show'])->name('admin.api.draw-requests.show');
    Route::get('/draw-results', [AdminDrawResultController::class, 'index'])->name('admin.api.draw-results.index');
    Route::get('/draw-results/{drawResult}', [AdminDrawResultController::class, 'show'])->name('admin.api.draw-results.show');

    Route::get('/user-prizes', [AdminUserPrizeController::class, 'index'])->name('admin.api.user-prizes.index');
    Route::get('/user-prizes/{userPrize}', [AdminUserPrizeController::class, 'show'])->name('admin.api.user-prizes.show');

    Route::get('/users', [AdminUserController::class, 'index'])->name('admin.api.users.index');
    Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('admin.api.users.show');
    Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('admin.api.users.update');

    Route::get('/point-adjustments', [AdminPointAdjustmentController::class, 'index'])->name('admin.api.point-adjustments.index');
    Route::post('/users/{user}/point-adjustments', [AdminPointAdjustmentController::class, 'store'])->name('admin.api.users.point-adjustments.store');

    Route::get('/referrals', [AdminUserReferralController::class, 'index'])->name('admin.api.referrals.index');
    Route::get('/referral-settings', [AdminReferralSettingController::class, 'show'])->name('admin.api.referral-settings.show');
    Route::put('/referral-settings', [AdminReferralSettingController::class, 'update'])->name('admin.api.referral-settings.update');

    Route::get('/audit-logs', [AdminAuditLogController::class, 'index'])->name('admin.api.audit-logs.index');
});
