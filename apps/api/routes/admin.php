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
use App\Http\Controllers\Admin\Line\AdminLineFriendSettingController;
use App\Http\Controllers\Admin\Payment\AdminPaymentController;
use App\Http\Controllers\Admin\Payment\AdminPaymentReversalController;
use App\Http\Controllers\Admin\Payment\AdminPointPurchasePlanController;
use App\Http\Controllers\Admin\Point\AdminPointAdjustmentController;
use App\Http\Controllers\Admin\Point\AdminPointBalanceSnapshotController;
use App\Http\Controllers\Admin\Prize\AdminUserPrizeController;
use App\Http\Controllers\Admin\Referral\AdminReferralSettingController;
use App\Http\Controllers\Admin\Referral\AdminUserReferralController;
use App\Http\Controllers\Admin\Sales\AdminSalesManagementController;
use App\Http\Controllers\Admin\Shipping\AdminShippingItemController;
use App\Http\Controllers\Admin\Shipping\AdminShippingRequestController;
use App\Http\Controllers\Admin\User\AdminQaDrawPlanController;
use App\Http\Controllers\Admin\User\AdminQaTestUserModeController;
use App\Http\Controllers\Admin\User\AdminUserController;
use App\Http\Middleware\EnsureAdminUser;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\V2\V2AdminAuthController;
use App\Http\Controllers\V2\V2AdminAuthenticationPolicyController;
use App\Http\Controllers\V2\V2AdminCatalogController;
use App\Http\Controllers\V2\V2AdminPermissionController;
use App\Http\Controllers\V2\V2AdminQaDrawController;
use App\Http\Controllers\V2\V2AdminReportingController;
use App\Http\Controllers\V2\V2AdminShippingController;
use App\Http\Controllers\V2\V2AdminUserPrizeController;
use App\Http\Controllers\V2\V2AdminUserController;
use App\Http\Controllers\V2\V2AdminUserStateController;
use App\Http\Controllers\V2\V2AdminUserPointAdjustmentController;
use App\Http\Controllers\V2\V2AdminUserTagController;
use App\Http\Controllers\V2\V2AdminContentContactController;
use App\Http\Controllers\V2\V2AdminLineMessagingController;
use App\Http\Controllers\V2\V2AdminLimitedBonusCampaignController;
use App\Http\Controllers\V2\V2AdminReferralPointSettingController;
use App\Http\Controllers\V2\V2AdminPointPurchasePlanController;

$v2GachaIdentifierPattern = '[A-Za-z0-9]{11}|[0-9a-fA-F-]{36}';

Route::prefix('v2/auth')
    ->middleware('v2.browser:admin')
    ->group(function (): void {
        Route::post('/login', [V2AdminAuthController::class, 'login'])
            ->name('v2.admin.auth.login');
        Route::post('/invitations/accept', [V2AdminAuthController::class, 'acceptInvitation'])
            ->name('v2.admin.auth.invitations.accept');
        Route::post('/mfa/verify', [V2AdminAuthController::class, 'verifyMfa'])
            ->name('v2.admin.auth.mfa.verify');
        Route::post('/logout', [V2AdminAuthController::class, 'logout'])
            ->name('v2.admin.auth.logout');
        Route::get('/session', [V2AdminAuthController::class, 'session'])
            ->name('v2.admin.auth.session');
        Route::post('/mfa/totp', [V2AdminAuthController::class, 'beginTotp'])
            ->name('v2.admin.auth.mfa.totp.begin');
        Route::post('/mfa/totp/confirm', [V2AdminAuthController::class, 'confirmTotp'])
            ->name('v2.admin.auth.mfa.totp.confirm');
        Route::post('/mfa/webauthn/options', [V2AdminAuthController::class, 'webauthnOptions'])
            ->name('v2.admin.auth.mfa.webauthn.options');
        Route::post('/mfa/webauthn', [V2AdminAuthController::class, 'storeWebauthn'])
            ->name('v2.admin.auth.mfa.webauthn.store');
        Route::post('/mfa/recovery-codes/regenerate', [
            V2AdminAuthController::class,
            'regenerateRecoveryCodes',
        ])->name('v2.admin.auth.recovery-codes.regenerate');
        Route::middleware('auth:v2_admin')->group(function (): void {
            Route::get('/permissions', V2AdminPermissionController::class)
                ->name('v2.admin.auth.permissions');
            Route::post('/reauthenticate/webauthn/options', [
                V2AdminAuthController::class,
                'reauthenticationWebauthnOptions',
            ])->name('v2.admin.auth.reauthenticate.webauthn.options');
            Route::post('/reauthenticate', [
                V2AdminAuthController::class,
                'reauthenticate',
            ])->name('v2.admin.auth.reauthenticate');
            Route::get('/policy', [V2AdminAuthenticationPolicyController::class, 'show'])
                ->name('v2.admin.auth.policy.show');
            Route::put('/policy', [V2AdminAuthenticationPolicyController::class, 'update'])
                ->name('v2.admin.auth.policy.update');
            Route::post('/admins', [V2AdminAuthenticationPolicyController::class, 'createAdmin'])
                ->name('v2.admin.auth.admins.store');
        });
    });

Route::prefix('v2')
    ->middleware(['v2.browser:admin', 'auth:v2_admin'])
    ->group(function () use ($v2GachaIdentifierPattern): void {
        Route::get('/users', [V2AdminUserController::class, 'index'])
            ->name('v2.admin.users.index');
        Route::get('/user-tags', [V2AdminUserTagController::class, 'index'])
            ->name('v2.admin.user-tags.index');
        Route::post('/user-tags', [V2AdminUserTagController::class, 'store'])
            ->name('v2.admin.user-tags.store');
        Route::put('/user-tags/{tagId}', [V2AdminUserTagController::class, 'update'])
            ->whereUuid('tagId')->name('v2.admin.user-tags.update');
        Route::get('/users/{userId}', [V2AdminUserController::class, 'show'])
            ->whereUuid('userId')->name('v2.admin.users.show');
        Route::put('/users/{userId}/state', V2AdminUserStateController::class)
            ->whereUuid('userId')->name('v2.admin.users.state.update');
        Route::get('/users/{userId}/tags', [V2AdminUserTagController::class, 'userTags'])
            ->whereUuid('userId')->name('v2.admin.users.tags.index');
        Route::post('/users/{userId}/tags/{tagId}', [V2AdminUserTagController::class, 'assign'])
            ->whereUuid('userId')->whereUuid('tagId')->name('v2.admin.users.tags.assign');
        Route::delete('/users/{userId}/tags/{tagId}', [V2AdminUserTagController::class, 'detach'])
            ->whereUuid('userId')->whereUuid('tagId')->name('v2.admin.users.tags.detach');
        Route::get('/users/{userId}/gacha-history', [V2AdminUserController::class, 'gachaHistory'])
            ->whereUuid('userId')->name('v2.admin.users.gacha-history.index');
        Route::post('/users/{userId}/point-adjustments', V2AdminUserPointAdjustmentController::class)
            ->whereUuid('userId')->name('v2.admin.users.point-adjustments.store');
        Route::get('/identity/line-messaging', [V2AdminLineMessagingController::class, 'show'])
            ->name('v2.admin.identity.line-messaging.show');
        Route::put('/identity/line-messaging', [V2AdminLineMessagingController::class, 'update'])
            ->name('v2.admin.identity.line-messaging.update');
        Route::post('/identity/line-messaging/preview', [V2AdminLineMessagingController::class, 'preview'])
            ->name('v2.admin.identity.line-messaging.preview');
        Route::get('/settings/referral-points', [V2AdminReferralPointSettingController::class, 'show'])
            ->name('v2.admin.settings.referral-points.show');
        Route::put('/settings/referral-points', [V2AdminReferralPointSettingController::class, 'update'])
            ->name('v2.admin.settings.referral-points.update');
        Route::get('/point-purchase-plans', [V2AdminPointPurchasePlanController::class, 'index'])
            ->name('v2.admin.point-purchase-plans.index');
        Route::post('/point-purchase-plans', [V2AdminPointPurchasePlanController::class, 'store'])
            ->name('v2.admin.point-purchase-plans.store');
        Route::get('/point-purchase-plans/{planId}', [V2AdminPointPurchasePlanController::class, 'show'])
            ->whereUuid('planId')->name('v2.admin.point-purchase-plans.show');
        Route::put('/point-purchase-plans/{planId}', [V2AdminPointPurchasePlanController::class, 'update'])
            ->whereUuid('planId')->name('v2.admin.point-purchase-plans.update');
        Route::get('/point-purchase-plans/{planId}/limited-bonus-campaigns', [V2AdminLimitedBonusCampaignController::class, 'index'])
            ->whereUuid('planId')->name('v2.admin.point-purchase-plans.limited-bonus-campaigns.index');
        Route::post('/point-purchase-plans/{planId}/limited-bonus-campaigns', [V2AdminLimitedBonusCampaignController::class, 'store'])
            ->whereUuid('planId')->name('v2.admin.point-purchase-plans.limited-bonus-campaigns.store');
        Route::put('/point-purchase-plans/{planId}/limited-bonus-campaigns/{campaignId}', [V2AdminLimitedBonusCampaignController::class, 'update'])
            ->whereUuid('planId')->whereUuid('campaignId')
            ->name('v2.admin.point-purchase-plans.limited-bonus-campaigns.update');
        Route::get('/catalog/categories', [V2AdminCatalogController::class, 'categories'])
            ->name('v2.admin.catalog.categories.index');
        Route::post('/catalog/categories', [V2AdminCatalogController::class, 'createCategory'])
            ->name('v2.admin.catalog.categories.store');
        Route::get('/catalog/categories/{catalogResourceId}', [V2AdminCatalogController::class, 'category'])
            ->whereUuid('catalogResourceId')->name('v2.admin.catalog.categories.show');
        Route::put('/catalog/categories/{catalogResourceId}', [V2AdminCatalogController::class, 'updateCategory'])
            ->whereUuid('catalogResourceId')->name('v2.admin.catalog.categories.update');
        Route::post('/catalog/categories/{catalogResourceId}/archive', [V2AdminCatalogController::class, 'archiveCategory'])
            ->whereUuid('catalogResourceId')->name('v2.admin.catalog.categories.archive');
        Route::get('/catalog/tags', [V2AdminCatalogController::class, 'tags'])
            ->name('v2.admin.catalog.tags.index');
        Route::post('/catalog/tags', [V2AdminCatalogController::class, 'createTag'])
            ->name('v2.admin.catalog.tags.store');
        Route::get('/catalog/tags/{catalogResourceId}', [V2AdminCatalogController::class, 'tag'])
            ->whereUuid('catalogResourceId')->name('v2.admin.catalog.tags.show');
        Route::put('/catalog/tags/{catalogResourceId}', [V2AdminCatalogController::class, 'updateTag'])
            ->whereUuid('catalogResourceId')->name('v2.admin.catalog.tags.update');
        Route::post('/catalog/tags/{catalogResourceId}/archive', [V2AdminCatalogController::class, 'archiveTag'])
            ->whereUuid('catalogResourceId')->name('v2.admin.catalog.tags.archive');
        Route::get('/catalog/ranks', [V2AdminCatalogController::class, 'ranks'])
            ->name('v2.admin.catalog.ranks.index');
        Route::post('/catalog/ranks', [V2AdminCatalogController::class, 'createRank'])
            ->name('v2.admin.catalog.ranks.store');
        Route::get('/catalog/ranks/{catalogResourceId}', [V2AdminCatalogController::class, 'rank'])
            ->whereUuid('catalogResourceId')->name('v2.admin.catalog.ranks.show');
        Route::put('/catalog/ranks/{catalogResourceId}', [V2AdminCatalogController::class, 'updateRank'])
            ->whereUuid('catalogResourceId')->name('v2.admin.catalog.ranks.update');
        Route::post('/catalog/ranks/{catalogResourceId}/archive', [V2AdminCatalogController::class, 'archiveRank'])
            ->whereUuid('catalogResourceId')->name('v2.admin.catalog.ranks.archive');
        Route::get('/catalog/prizes', [V2AdminCatalogController::class, 'prizes'])
            ->name('v2.admin.catalog.prizes.index');
        Route::post('/catalog/prizes', [V2AdminCatalogController::class, 'createPrize'])
            ->name('v2.admin.catalog.prizes.create');
        Route::get('/catalog/prizes/{catalogResourceId}', [V2AdminCatalogController::class, 'prize'])
            ->whereUuid('catalogResourceId')->name('v2.admin.catalog.prizes.show');
        Route::put('/catalog/prizes/{catalogResourceId}', [V2AdminCatalogController::class, 'updatePrize'])
            ->whereUuid('catalogResourceId')->name('v2.admin.catalog.prizes.update');
        Route::post('/catalog/prizes/{catalogResourceId}/archive', [V2AdminCatalogController::class, 'archivePrize'])
            ->whereUuid('catalogResourceId')->name('v2.admin.catalog.prizes.archive');
        Route::get('/catalog/presentation-assets', [V2AdminCatalogController::class, 'assets'])
            ->name('v2.admin.catalog.presentation-assets.index');
        Route::post('/catalog/presentation-assets', [V2AdminCatalogController::class, 'createAsset'])
            ->name('v2.admin.catalog.presentation-assets.create');
        Route::get('/catalog/presentation-assets/{catalogResourceId}', [V2AdminCatalogController::class, 'asset'])
            ->whereUuid('catalogResourceId')->name('v2.admin.catalog.presentation-assets.show');
        Route::put('/catalog/presentation-assets/{catalogResourceId}', [V2AdminCatalogController::class, 'updateAsset'])
            ->whereUuid('catalogResourceId')->name('v2.admin.catalog.presentation-assets.update');
        Route::post('/catalog/presentation-assets/{catalogResourceId}/archive', [V2AdminCatalogController::class, 'archiveAsset'])
            ->whereUuid('catalogResourceId')->name('v2.admin.catalog.presentation-assets.archive');
        Route::get('/catalog/rank-effects', [V2AdminCatalogController::class, 'rankEffects'])
            ->name('v2.admin.catalog.rank-effects.index');
        Route::post('/catalog/rank-effects', [V2AdminCatalogController::class, 'createRankEffect'])
            ->name('v2.admin.catalog.rank-effects.create');
        Route::get('/catalog/rank-effects/{catalogResourceId}', [V2AdminCatalogController::class, 'rankEffect'])
            ->whereUuid('catalogResourceId')->name('v2.admin.catalog.rank-effects.show');
        Route::put('/catalog/rank-effects/{catalogResourceId}', [V2AdminCatalogController::class, 'updateRankEffect'])
            ->whereUuid('catalogResourceId')->name('v2.admin.catalog.rank-effects.update');
        Route::get('/catalog/gachas', [V2AdminCatalogController::class, 'gachas'])
            ->name('v2.admin.catalog.gachas.index');
        Route::post('/catalog/gachas', [V2AdminCatalogController::class, 'createGacha'])
            ->name('v2.admin.catalog.gachas.create');
        Route::post('/catalog/gachas/core', [V2AdminCatalogController::class, 'createGachaCore'])
            ->name('v2.admin.catalog.gachas.core.create');
        Route::post('/catalog/gacha-thumbnails', [V2AdminCatalogController::class, 'uploadGachaThumbnail'])
            ->name('v2.admin.catalog.gacha-thumbnails.create');
        Route::get('/catalog/presentation-assets/{catalogResourceId}/content', [V2AdminCatalogController::class, 'presentationAssetContent'])
            ->whereUuid('catalogResourceId')->name('v2.admin.catalog.presentation-assets.content');
        Route::get('/catalog/gachas/{gachaId}', [V2AdminCatalogController::class, 'gacha'])
            ->where('gachaId', $v2GachaIdentifierPattern)->name('v2.admin.catalog.gachas.show');
        Route::get('/catalog/gachas/{gachaId}/history', [V2AdminCatalogController::class, 'gachaUsageHistory'])
            ->where('gachaId', $v2GachaIdentifierPattern)->name('v2.admin.catalog.gachas.history.index');
        Route::get('/catalog/gachas/{gachaId}/history/{drawRequestId}', [V2AdminCatalogController::class, 'gachaUsageHistoryDetail'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('drawRequestId')
            ->name('v2.admin.catalog.gachas.history.show');
        Route::put('/catalog/gachas/{gachaId}', [V2AdminCatalogController::class, 'updateGacha'])
            ->where('gachaId', $v2GachaIdentifierPattern)->name('v2.admin.catalog.gachas.update');
        Route::post('/catalog/gachas/{gachaId}/archive', [V2AdminCatalogController::class, 'archiveGacha'])
            ->where('gachaId', $v2GachaIdentifierPattern)->name('v2.admin.catalog.gachas.archive');
        Route::get('/catalog/gachas/{gachaId}/publish-state', [V2AdminCatalogController::class, 'gachaPublishState'])
            ->where('gachaId', $v2GachaIdentifierPattern)->name('v2.admin.catalog.gachas.publish-state');
        Route::get('/catalog/gachas/{gachaId}/sales-state', [V2AdminCatalogController::class, 'gachaSalesState'])
            ->where('gachaId', $v2GachaIdentifierPattern)->name('v2.admin.catalog.gachas.sales-state');
        Route::post('/catalog/gachas/{gachaId}/sales-pause/preflight', [V2AdminCatalogController::class, 'preflightGachaSalesPause'])
            ->where('gachaId', $v2GachaIdentifierPattern)->name('v2.admin.catalog.gachas.sales-pause.preflight');
        Route::post('/catalog/gachas/{gachaId}/sales-pause', [V2AdminCatalogController::class, 'pauseGachaSales'])
            ->where('gachaId', $v2GachaIdentifierPattern)->name('v2.admin.catalog.gachas.sales-pause');
        Route::post('/catalog/gachas/{gachaId}/sales-resume/preflight', [V2AdminCatalogController::class, 'preflightGachaSalesResume'])
            ->where('gachaId', $v2GachaIdentifierPattern)->name('v2.admin.catalog.gachas.sales-resume.preflight');
        Route::post('/catalog/gachas/{gachaId}/sales-resume', [V2AdminCatalogController::class, 'resumeGachaSales'])
            ->where('gachaId', $v2GachaIdentifierPattern)->name('v2.admin.catalog.gachas.sales-resume');
        Route::get('/catalog/gachas/{gachaId}/unpublish-state', [V2AdminCatalogController::class, 'gachaUnpublishState'])
            ->where('gachaId', $v2GachaIdentifierPattern)->name('v2.admin.catalog.gachas.unpublish-state');
        Route::post('/catalog/gachas/{gachaId}/unpublish/preflight', [V2AdminCatalogController::class, 'preflightGachaUnpublish'])
            ->where('gachaId', $v2GachaIdentifierPattern)->name('v2.admin.catalog.gachas.unpublish.preflight');
        Route::post('/catalog/gachas/{gachaId}/unpublish', [V2AdminCatalogController::class, 'unpublishGacha'])
            ->where('gachaId', $v2GachaIdentifierPattern)->name('v2.admin.catalog.gachas.unpublish');
        Route::get('/catalog/gachas/{gachaId}/versions', [V2AdminCatalogController::class, 'gachaVersions'])
            ->where('gachaId', $v2GachaIdentifierPattern)->name('v2.admin.catalog.gacha-versions.index');
        Route::post('/catalog/gachas/{gachaId}/versions', [V2AdminCatalogController::class, 'createGachaDraft'])
            ->where('gachaId', $v2GachaIdentifierPattern)->name('v2.admin.catalog.gacha-versions.create');
        Route::get('/catalog/gachas/{gachaId}/versions/{versionId}', [V2AdminCatalogController::class, 'gachaVersion'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')
            ->name('v2.admin.catalog.gacha-versions.show');
        Route::get('/catalog/gachas/{gachaId}/versions/{versionId}/ranks', [V2AdminCatalogController::class, 'gachaVersionRanks'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')
            ->name('v2.admin.catalog.gacha-version-ranks.index');
        Route::post('/catalog/gachas/{gachaId}/versions/{versionId}/ranks', [V2AdminCatalogController::class, 'createGachaVersionRank'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')
            ->name('v2.admin.catalog.gacha-version-ranks.store');
        Route::put('/catalog/gachas/{gachaId}/versions/{versionId}/ranks/{rankId}', [V2AdminCatalogController::class, 'updateGachaVersionRank'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')->whereUuid('rankId')
            ->name('v2.admin.catalog.gacha-version-ranks.update');
        Route::get('/catalog/gachas/{gachaId}/versions/{versionId}/prizes', [V2AdminCatalogController::class, 'gachaVersionPrizes'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')
            ->name('v2.admin.catalog.gacha-version-prizes.index');
        Route::post('/catalog/gachas/{gachaId}/versions/{versionId}/prizes', [V2AdminCatalogController::class, 'createGachaVersionPrize'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')
            ->name('v2.admin.catalog.gacha-version-prizes.store');
        Route::put('/catalog/gachas/{gachaId}/versions/{versionId}/prizes/{prizeId}', [V2AdminCatalogController::class, 'updateGachaVersionPrize'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')->whereUuid('prizeId')
            ->name('v2.admin.catalog.gacha-version-prizes.update');
        Route::put('/catalog/gachas/{gachaId}/versions/{versionId}', [V2AdminCatalogController::class, 'updateGachaDraft'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')
            ->name('v2.admin.catalog.gacha-versions.update');
        Route::post('/catalog/gachas/{gachaId}/versions/{versionId}/clone', [V2AdminCatalogController::class, 'cloneGachaDraft'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')
            ->name('v2.admin.catalog.gacha-versions.clone');
        Route::post('/catalog/gachas/{gachaId}/versions/{versionId}/archive', [V2AdminCatalogController::class, 'archiveGachaDraft'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')
            ->name('v2.admin.catalog.gacha-versions.archive');
        Route::get('/catalog/gachas/{gachaId}/versions/{versionId}/probability-versions', [V2AdminCatalogController::class, 'probabilityVersions'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')
            ->name('v2.admin.catalog.probability-versions.index');
        Route::post('/catalog/gachas/{gachaId}/versions/{versionId}/probability-versions', [V2AdminCatalogController::class, 'createProbabilityDraft'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')
            ->name('v2.admin.catalog.probability-versions.create');
        Route::get('/catalog/gachas/{gachaId}/versions/{versionId}/probability-versions/{probabilityVersionId}', [V2AdminCatalogController::class, 'probabilityVersion'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')->whereUuid('probabilityVersionId')
            ->name('v2.admin.catalog.probability-versions.show');
        Route::get('/catalog/gachas/{gachaId}/versions/{versionId}/published-probability-candidates', [V2AdminCatalogController::class, 'publishedProbabilityCandidates'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')
            ->name('v2.admin.catalog.gacha-versions.probability-candidates');
        Route::get('/catalog/gachas/{gachaId}/versions/{versionId}/probability-selection', [V2AdminCatalogController::class, 'gachaProbabilitySelection'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')
            ->name('v2.admin.catalog.gacha-versions.probability-selection.show');
        Route::put('/catalog/gachas/{gachaId}/versions/{versionId}/probability-selection', [V2AdminCatalogController::class, 'selectGachaProbability'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')
            ->name('v2.admin.catalog.gacha-versions.probability-selection.update');
        Route::post('/catalog/gachas/{gachaId}/versions/{versionId}/publish-preflight', [V2AdminCatalogController::class, 'preflightGachaPublish'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')
            ->name('v2.admin.catalog.gacha-versions.publish-preflight');
        Route::post('/catalog/gachas/{gachaId}/versions/{versionId}/publish', [V2AdminCatalogController::class, 'publishGachaVersionImmediately'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')
            ->name('v2.admin.catalog.gacha-versions.publish');
        Route::get('/catalog/gachas/{gachaId}/versions/{versionId}/publish-schedule', [V2AdminCatalogController::class, 'gachaPublishSchedule'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')
            ->name('v2.admin.catalog.gacha-versions.publish-schedule.show');
        Route::post('/catalog/gachas/{gachaId}/versions/{versionId}/publish-schedule/preflight', [V2AdminCatalogController::class, 'preflightGachaPublishSchedule'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')
            ->name('v2.admin.catalog.gacha-versions.publish-schedule.preflight');
        Route::post('/catalog/gachas/{gachaId}/versions/{versionId}/publish-schedule', [V2AdminCatalogController::class, 'scheduleGachaVersionPublish'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')
            ->name('v2.admin.catalog.gacha-versions.publish-schedule.create');
        Route::post('/catalog/gachas/{gachaId}/versions/{versionId}/publish-schedule/{scheduleId}/cancel', [V2AdminCatalogController::class, 'cancelGachaVersionPublishSchedule'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')->whereUuid('scheduleId')
            ->name('v2.admin.catalog.gacha-versions.publish-schedule.cancel');
        Route::post('/catalog/gachas/{gachaId}/versions/{versionId}/probability-versions/{probabilityVersionId}/clone', [V2AdminCatalogController::class, 'cloneProbabilityDraft'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')->whereUuid('probabilityVersionId')
            ->name('v2.admin.catalog.probability-versions.clone');
        Route::put('/catalog/gachas/{gachaId}/versions/{versionId}/probability-versions/{probabilityVersionId}/entries', [V2AdminCatalogController::class, 'replaceProbabilityEntries'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')->whereUuid('probabilityVersionId')
            ->name('v2.admin.catalog.probability-versions.entries.replace');
        Route::post('/catalog/gachas/{gachaId}/versions/{versionId}/probability-versions/{probabilityVersionId}/validate', [V2AdminCatalogController::class, 'validateProbabilityDraft'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')->whereUuid('probabilityVersionId')
            ->name('v2.admin.catalog.probability-versions.validate');
        Route::post('/catalog/gachas/{gachaId}/versions/{versionId}/probability-versions/{probabilityVersionId}/publish-preflight', [V2AdminCatalogController::class, 'preflightProbabilityPublish'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')->whereUuid('probabilityVersionId')
            ->name('v2.admin.catalog.probability-versions.publish-preflight');
        Route::post('/catalog/gachas/{gachaId}/versions/{versionId}/probability-versions/{probabilityVersionId}/publish', [V2AdminCatalogController::class, 'publishProbabilityDraft'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')->whereUuid('probabilityVersionId')
            ->name('v2.admin.catalog.probability-versions.publish');
        Route::post('/catalog/gachas/{gachaId}/versions/{versionId}/probability-versions/{probabilityVersionId}/archive', [V2AdminCatalogController::class, 'archiveProbabilityDraft'])
            ->where('gachaId', $v2GachaIdentifierPattern)->whereUuid('versionId')->whereUuid('probabilityVersionId')
            ->name('v2.admin.catalog.probability-versions.archive');
        Route::get('/content/banners', [V2AdminContentContactController::class, 'banners']);
        Route::post('/content/banners', [V2AdminContentContactController::class, 'createBanner']);
        Route::get('/content/banners/{contentId}', [V2AdminContentContactController::class, 'banner'])->whereUuid('contentId');
        Route::post('/content/banners/{contentId}/versions', [V2AdminContentContactController::class, 'createBannerVersion'])->whereUuid('contentId');
        Route::post('/content/banners/{contentId}/versions/{versionId}/publish', [V2AdminContentContactController::class, 'publishBanner'])->whereUuid('contentId')->whereUuid('versionId');
        Route::post('/content/banners/{contentId}/unpublish', [V2AdminContentContactController::class, 'unpublishBanner'])->whereUuid('contentId');
        Route::post('/content/banners/{contentId}/archive', [V2AdminContentContactController::class, 'archiveBanner'])->whereUuid('contentId');
        Route::get('/banner-management/categories', [V2AdminContentContactController::class, 'bannerCategories']);
        Route::post('/banner-management/categories', [V2AdminContentContactController::class, 'createBannerCategory']);
        Route::post('/banner-management/assets', [V2AdminContentContactController::class, 'uploadBannerAsset']);
        Route::get('/banner-management/assets/{assetId}/content', [V2AdminContentContactController::class, 'bannerAssetContent'])->whereUuid('assetId');
        Route::get('/banner-management/banners', [V2AdminContentContactController::class, 'managedBanners']);
        Route::post('/banner-management/banners', [V2AdminContentContactController::class, 'createManagedBanner']);
        Route::put('/banner-management/banners/{bannerId}', [V2AdminContentContactController::class, 'updateManagedBanner'])->whereUuid('bannerId');
        Route::delete('/banner-management/banners/{bannerId}', [V2AdminContentContactController::class, 'deleteManagedBanner'])->whereUuid('bannerId');
        Route::get('/page-management/categories', [V2AdminContentContactController::class, 'pageCategories']);
        Route::post('/page-management/categories', [V2AdminContentContactController::class, 'createPageCategory']);
        Route::get('/page-management/pages', [V2AdminContentContactController::class, 'managedPages']);
        Route::post('/page-management/pages', [V2AdminContentContactController::class, 'createManagedPage']);
        Route::post('/page-management/pages/preview', [V2AdminContentContactController::class, 'previewManagedPage']);
        Route::get('/page-management/pages/{pageId}', [V2AdminContentContactController::class, 'managedPage'])->whereUuid('pageId');
        Route::put('/page-management/pages/{pageId}', [V2AdminContentContactController::class, 'updateManagedPage'])->whereUuid('pageId');
        Route::get('/content/notices', [V2AdminContentContactController::class, 'notices']);
        Route::post('/content/notices', [V2AdminContentContactController::class, 'createNotice']);
        Route::post('/content/notices/preview', [V2AdminContentContactController::class, 'previewNotice']);
        Route::get('/content/notices/{contentId}', [V2AdminContentContactController::class, 'notice'])->whereUuid('contentId');
        Route::post('/content/notices/{contentId}/versions', [V2AdminContentContactController::class, 'createNoticeVersion'])->whereUuid('contentId');
        Route::post('/content/notices/{contentId}/versions/{versionId}/publish', [V2AdminContentContactController::class, 'publishNotice'])->whereUuid('contentId')->whereUuid('versionId');
        Route::post('/content/notices/{contentId}/unpublish', [V2AdminContentContactController::class, 'unpublishNotice'])->whereUuid('contentId');
        Route::post('/content/notices/{contentId}/archive', [V2AdminContentContactController::class, 'archiveNotice'])->whereUuid('contentId');
        Route::get('/content/static-pages', [V2AdminContentContactController::class, 'staticPages']);
        Route::post('/content/static-pages', [V2AdminContentContactController::class, 'createStaticPage']);
        Route::get('/content/static-pages/{contentId}', [V2AdminContentContactController::class, 'staticPage'])->whereUuid('contentId');
        Route::post('/content/static-pages/{contentId}/versions', [V2AdminContentContactController::class, 'createStaticPageVersion'])->whereUuid('contentId');
        Route::post('/content/static-pages/{contentId}/versions/{versionId}/publish', [V2AdminContentContactController::class, 'publishStaticPage'])->whereUuid('contentId')->whereUuid('versionId');
        Route::post('/content/static-pages/{contentId}/unpublish', [V2AdminContentContactController::class, 'unpublishStaticPage'])->whereUuid('contentId');
        Route::post('/content/static-pages/{contentId}/archive', [V2AdminContentContactController::class, 'archiveStaticPage'])->whereUuid('contentId');
        Route::get('/contact-inquiries', [V2AdminContentContactController::class, 'contacts']);
        Route::get('/contact-inquiries/{contactId}', [V2AdminContentContactController::class, 'contact'])->whereUuid('contactId');
        Route::put('/contact-inquiries/{contactId}/status', [V2AdminContentContactController::class, 'updateContactStatus'])->whereUuid('contactId');
        Route::post('/contact-inquiries/{contactId}/internal-notes', [V2AdminContentContactController::class, 'addContactNote'])->whereUuid('contactId');
        Route::post('/contact-inquiries/{contactId}/reply-requests', [V2AdminContentContactController::class, 'requestContactReply'])->whereUuid('contactId');
        Route::get('/users/{userId}/qa-mode', [V2AdminQaDrawController::class, 'showMode'])
            ->whereUuid('userId')->name('v2.admin.qa-mode.show');
        Route::put('/users/{userId}/qa-mode', [V2AdminQaDrawController::class, 'saveMode'])
            ->whereUuid('userId')->name('v2.admin.qa-mode.save');
        Route::delete('/users/{userId}/qa-mode', [V2AdminQaDrawController::class, 'disableMode'])
            ->whereUuid('userId')->name('v2.admin.qa-mode.disable');
        Route::get('/users/{userId}/qa-draw-plans', [V2AdminQaDrawController::class, 'plans'])
            ->whereUuid('userId')->name('v2.admin.qa-plans.index');
        Route::post('/users/{userId}/qa-draw-plans', [V2AdminQaDrawController::class, 'createPlan'])
            ->whereUuid('userId')->name('v2.admin.qa-plans.store');
        Route::get('/qa-draw-plans/{planId}', [V2AdminQaDrawController::class, 'showPlan'])
            ->whereUuid('planId')->name('v2.admin.qa-plans.show');
        Route::put('/qa-draw-plans/{planId}', [V2AdminQaDrawController::class, 'updatePlan'])
            ->whereUuid('planId')->name('v2.admin.qa-plans.update');
        Route::post('/qa-draw-plans/{planId}/pause', [V2AdminQaDrawController::class, 'pausePlan'])
            ->whereUuid('planId')->name('v2.admin.qa-plans.pause');
        Route::post('/qa-draw-plans/{planId}/activate', [V2AdminQaDrawController::class, 'activatePlan'])
            ->whereUuid('planId')->name('v2.admin.qa-plans.activate');
        Route::post('/qa-draw-plans/{planId}/disable', [V2AdminQaDrawController::class, 'disablePlan'])
            ->whereUuid('planId')->name('v2.admin.qa-plans.disable');
        Route::get('/qa-draw-executions', [V2AdminQaDrawController::class, 'executions'])
            ->name('v2.admin.qa-executions.index');
        Route::get('/qa-draw-executions/{executionId}', [V2AdminQaDrawController::class, 'showExecution'])
            ->whereUuid('executionId')->name('v2.admin.qa-executions.show');
        Route::get('/qa/plans', [V2AdminQaDrawController::class, 'managementPlans'])
            ->name('v2.admin.qa-management.plans.index');
        Route::post('/qa/plans', [V2AdminQaDrawController::class, 'createManagementPlan'])
            ->name('v2.admin.qa-management.plans.store');
        Route::get('/qa/plans/{planId}', [V2AdminQaDrawController::class, 'showManagementPlan'])
            ->whereUuid('planId')->name('v2.admin.qa-management.plans.show');
        Route::put('/qa/plans/{planId}', [V2AdminQaDrawController::class, 'updateManagementPlan'])
            ->whereUuid('planId')->name('v2.admin.qa-management.plans.update');
        Route::post('/qa/plans/{planId}/enable', [V2AdminQaDrawController::class, 'enableManagementPlan'])
            ->whereUuid('planId')->name('v2.admin.qa-management.plans.enable');
        Route::post('/qa/plans/{planId}/disable', [V2AdminQaDrawController::class, 'disableManagementPlan'])
            ->whereUuid('planId')->name('v2.admin.qa-management.plans.disable');
        Route::post('/qa/plans/{planId}/archive', [V2AdminQaDrawController::class, 'archiveManagementPlan'])
            ->whereUuid('planId')->name('v2.admin.qa-management.plans.archive');
        Route::get('/qa/plans/{planId}/preflight', [V2AdminQaDrawController::class, 'preflightManagementPlan'])
            ->whereUuid('planId')->name('v2.admin.qa-management.plans.preflight');
        Route::post('/qa/plans/{planId}/executions/preflight', [V2AdminQaDrawController::class, 'preflightExecution'])
            ->whereUuid('planId')->name('v2.admin.qa-management.executions.preflight');
        Route::post('/qa/plans/{planId}/executions', [V2AdminQaDrawController::class, 'execute'])
            ->whereUuid('planId')->name('v2.admin.qa-management.executions.store');
        Route::post('/qa/plans/{planId}/assignments', [V2AdminQaDrawController::class, 'assignManagementPlan'])
            ->whereUuid('planId')->name('v2.admin.qa-management.assignments.assign');
        Route::post('/qa/plans/{planId}/assignments/unassign', [V2AdminQaDrawController::class, 'unassignManagementPlan'])
            ->whereUuid('planId')->name('v2.admin.qa-management.assignments.unassign');
        Route::get('/qa/test-users', [V2AdminQaDrawController::class, 'managementTestUsers'])
            ->name('v2.admin.qa-management.test-users.index');
        Route::get('/qa/test-user-candidates', [V2AdminQaDrawController::class, 'managementTestUserCandidates'])
            ->name('v2.admin.qa-management.test-users.candidates');
        Route::put('/qa/test-users/{userId}', [V2AdminQaDrawController::class, 'saveManagementTestUser'])
            ->whereUuid('userId')->name('v2.admin.qa-management.test-users.save');
        Route::post('/qa/test-users/{userId}/disable', [V2AdminQaDrawController::class, 'disableManagementTestUser'])
            ->whereUuid('userId')->name('v2.admin.qa-management.test-users.disable');
        Route::get('/catalog/gachas/{gachaId}/qa-guarantees', [V2AdminQaDrawController::class, 'gachaGuarantees'])
            ->name('v2.admin.qa-management.gacha-guarantees.index');
        Route::put('/catalog/gachas/{gachaId}/qa-guarantees', [V2AdminQaDrawController::class, 'saveGachaGuarantee'])
            ->name('v2.admin.qa-management.gacha-guarantees.save');
        Route::post('/catalog/gachas/{gachaId}/qa-guarantees/{userId}/disable', [V2AdminQaDrawController::class, 'disableGachaGuarantee'])
            ->whereUuid('userId')->name('v2.admin.qa-management.gacha-guarantees.disable');
        Route::get('/shipping-requests', [V2AdminShippingController::class, 'index'])
            ->name('v2.admin.shipping-requests.index');
        Route::get('/shipping-requests/{shippingRequestId}', [V2AdminShippingController::class, 'show'])
            ->whereUuid('shippingRequestId')
            ->name('v2.admin.shipping-requests.show');
        Route::put('/shipping-requests/{shippingRequestId}', [V2AdminShippingController::class, 'update'])
            ->whereUuid('shippingRequestId')
            ->name('v2.admin.shipping-requests.update');
        Route::get('/user-prizes', [V2AdminUserPrizeController::class, 'index'])
            ->name('v2.admin.user-prizes.index');
        Route::get('/user-prizes/{userPrizeId}', [V2AdminUserPrizeController::class, 'show'])
            ->whereUuid('userPrizeId')
            ->name('v2.admin.user-prizes.show');
        Route::get('/reports/sales/monthly', [V2AdminReportingController::class, 'monthlySales'])
            ->name('v2.admin.reporting.sales.monthly');
        Route::get('/reports/dashboard/sales/monthly', [V2AdminReportingController::class, 'dashboardMonthlySales'])
            ->name('v2.admin.reporting.dashboard.sales.monthly');
        Route::get('/reports/dashboard/sales/daily', [V2AdminReportingController::class, 'dashboardDailySales'])
            ->name('v2.admin.reporting.dashboard.sales.daily');
        Route::get('/reports/dashboard/points/monthly', [V2AdminReportingController::class, 'dashboardMonthlyPoints'])
            ->name('v2.admin.reporting.dashboard.points.monthly');
        Route::get('/reports/dashboard/points/daily', [V2AdminReportingController::class, 'dashboardDailyPoints'])
            ->name('v2.admin.reporting.dashboard.points.daily');
        Route::get('/reports/dashboard/reversals', [V2AdminReportingController::class, 'dashboardReversals'])
            ->name('v2.admin.reporting.dashboard.reversals');
        Route::get('/reports/sales/daily', [V2AdminReportingController::class, 'dailySales'])
            ->name('v2.admin.reporting.sales.daily');
        Route::get('/reports/adjustments', [V2AdminReportingController::class, 'adjustments'])
            ->name('v2.admin.reporting.adjustments.index');
        Route::get('/reports/points/monthly', [V2AdminReportingController::class, 'points'])
            ->name('v2.admin.reporting.points.monthly');
        Route::get('/reports/gachas/monthly', [V2AdminReportingController::class, 'gachas'])
            ->name('v2.admin.reporting.gachas.monthly');
        Route::get('/reports/draw-requests', [V2AdminReportingController::class, 'draws'])
            ->name('v2.admin.reporting.draws.index');
        Route::get('/reports/draw-results', [V2AdminReportingController::class, 'drawResults'])
            ->name('v2.admin.reporting.draw-results.index');
        Route::get('/reports/point-balance-snapshots', [
            V2AdminReportingController::class,
            'snapshots',
        ])->name('v2.admin.reporting.snapshots.index');
        Route::get('/reports/point-balance-snapshots/{date}', [
            V2AdminReportingController::class,
            'snapshot',
        ])->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')
            ->name('v2.admin.reporting.snapshots.show');
        Route::post('/reports/exports/stream', [V2AdminReportingController::class, 'stream'])
            ->name('v2.admin.reporting.exports.stream');
        Route::post('/reports/export-jobs', [V2AdminReportingController::class, 'createJob'])
            ->name('v2.admin.reporting.export-jobs.store');
        Route::get('/reports/export-jobs', [V2AdminReportingController::class, 'jobs'])
            ->name('v2.admin.reporting.export-jobs.index');
        Route::get('/reports/export-jobs/{exportJobId}', [
            V2AdminReportingController::class,
            'job',
        ])->whereUuid('exportJobId')->name('v2.admin.reporting.export-jobs.show');
        Route::post('/reports/export-jobs/{exportJobId}/download', [
            V2AdminReportingController::class,
            'download',
        ])->whereUuid('exportJobId')->name('v2.admin.reporting.export-jobs.download');
        Route::get('/reports/export-jobs/{exportJobId}/file', [
            V2AdminReportingController::class,
            'file',
        ])->middleware('signed')->whereUuid('exportJobId')
            ->name('v2.admin.reporting.export-jobs.file');
    });

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
    Route::get('/payments/{payment}/refund-eligibility', [AdminPaymentController::class, 'refundEligibility'])->name('admin.api.payments.refund-eligibility');
    Route::post('/payments/{payment}/refund', [AdminPaymentController::class, 'refund'])->name('admin.api.payments.refund');
    Route::post('/payments/{payment}/chargeback', [AdminPaymentController::class, 'chargeback'])->name('admin.api.payments.chargeback');
    Route::get('/payment-reversals', [AdminPaymentReversalController::class, 'index'])->name('admin.api.payment-reversals.index');
    Route::get('/payment-reversals/{paymentReversal}', [AdminPaymentReversalController::class, 'show'])->name('admin.api.payment-reversals.show');
    Route::post('/payment-reversals/{paymentReversal}/release-holds', [AdminPaymentReversalController::class, 'releaseHolds'])->name('admin.api.payment-reversals.release-holds');
    Route::post('/payment-reversals/{paymentReversal}/send-return-request-mail', [AdminPaymentReversalController::class, 'sendReturnRequestMail'])->name('admin.api.payment-reversals.send-return-request-mail');
    Route::post('/payment-reversal-prize-actions/{action}/mark-returned', [AdminPaymentReversalController::class, 'markReturned'])->name('admin.api.payment-reversal-prize-actions.mark-returned');
    Route::get('/sales/monthly.csv', [AdminSalesManagementController::class, 'monthlyCsv'])->name('admin.api.sales.monthly.csv');
    Route::get('/sales/monthly', [AdminSalesManagementController::class, 'monthly'])->name('admin.api.sales.monthly');
    Route::get('/sales/daily-payments.csv', [AdminSalesManagementController::class, 'dailyPaymentsCsv'])->name('admin.api.sales.daily-payments.csv');
    Route::get('/sales/daily-payments', [AdminSalesManagementController::class, 'dailyPayments'])->name('admin.api.sales.daily-payments');
    Route::get('/sales/daily-adjustments.csv', [AdminSalesManagementController::class, 'dailyAdjustmentsCsv'])->name('admin.api.sales.daily-adjustments.csv');
    Route::get('/sales/daily-adjustments', [AdminSalesManagementController::class, 'dailyAdjustments'])->name('admin.api.sales.daily-adjustments');
    Route::get('/sales/monthly-point-consumption.csv', [AdminSalesManagementController::class, 'monthlyPointConsumptionCsv'])->name('admin.api.sales.monthly-point-consumption.csv');
    Route::get('/sales/monthly-point-consumption', [AdminSalesManagementController::class, 'monthlyPointConsumption'])->name('admin.api.sales.monthly-point-consumption');
    Route::get('/sales/daily-point-consumption.csv', [AdminSalesManagementController::class, 'dailyPointConsumptionCsv'])->name('admin.api.sales.daily-point-consumption.csv');
    Route::get('/sales/daily-point-consumption', [AdminSalesManagementController::class, 'dailyPointConsumption'])->name('admin.api.sales.daily-point-consumption');
    Route::get('/sales/draw-requests/{drawRequest}', [AdminSalesManagementController::class, 'drawRequest'])->name('admin.api.sales.draw-requests.show');
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
    Route::get('/users/{user}/qa-test-mode', [AdminQaTestUserModeController::class, 'show'])->name('admin.api.users.qa-test-mode.show');
    Route::put('/users/{user}/qa-test-mode', [AdminQaTestUserModeController::class, 'upsert'])->name('admin.api.users.qa-test-mode.upsert');
    Route::delete('/users/{user}/qa-test-mode', [AdminQaTestUserModeController::class, 'destroy'])->name('admin.api.users.qa-test-mode.destroy');
    Route::get('/users/{user}/qa-draw-plans', [AdminQaDrawPlanController::class, 'index'])->name('admin.api.users.qa-draw-plans.index');
    Route::post('/users/{user}/qa-draw-plans', [AdminQaDrawPlanController::class, 'store'])->name('admin.api.users.qa-draw-plans.store');
    Route::get('/qa-draw-plans/{plan}', [AdminQaDrawPlanController::class, 'show'])->name('admin.api.qa-draw-plans.show');
    Route::put('/qa-draw-plans/{plan}', [AdminQaDrawPlanController::class, 'update'])->name('admin.api.qa-draw-plans.update');
    Route::delete('/qa-draw-plans/{plan}', [AdminQaDrawPlanController::class, 'destroy'])->name('admin.api.qa-draw-plans.destroy');
    Route::post('/qa-draw-plans/{plan}/pause', [AdminQaDrawPlanController::class, 'pause'])->name('admin.api.qa-draw-plans.pause');
    Route::post('/qa-draw-plans/{plan}/activate', [AdminQaDrawPlanController::class, 'activate'])->name('admin.api.qa-draw-plans.activate');

    Route::get('/point-adjustments', [AdminPointAdjustmentController::class, 'index'])->name('admin.api.point-adjustments.index');
    Route::post('/users/{user}/point-adjustments', [AdminPointAdjustmentController::class, 'store'])->name('admin.api.users.point-adjustments.store');
    Route::get('/point-balance-snapshots/latest', [AdminPointBalanceSnapshotController::class, 'latest'])->name('admin.api.point-balance-snapshots.latest');
    Route::get('/point-balance-snapshots', [AdminPointBalanceSnapshotController::class, 'index'])->name('admin.api.point-balance-snapshots.index');
    Route::get('/point-balance-snapshots/base-dates', [AdminPointBalanceSnapshotController::class, 'baseDates'])->name('admin.api.point-balance-snapshots.base-dates');

    Route::get('/referrals', [AdminUserReferralController::class, 'index'])->name('admin.api.referrals.index');
    Route::get('/referral-settings', [AdminReferralSettingController::class, 'show'])->name('admin.api.referral-settings.show');
    Route::put('/referral-settings', [AdminReferralSettingController::class, 'update'])->name('admin.api.referral-settings.update');
    Route::get('/line-friend-settings', [AdminLineFriendSettingController::class, 'show'])->name('admin.api.line-friend-settings.show');
    Route::put('/line-friend-settings', [AdminLineFriendSettingController::class, 'update'])->name('admin.api.line-friend-settings.update');

    Route::get('/audit-logs', [AdminAuditLogController::class, 'index'])->name('admin.api.audit-logs.index');
});
