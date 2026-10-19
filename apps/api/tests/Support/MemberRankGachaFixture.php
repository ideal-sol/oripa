<?php

namespace Tests\Support;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Catalog\Services\V2CatalogMasterMutationService;
use App\Domain\Catalog\Services\V2GachaCompositionService;
use App\Domain\Catalog\Services\V2GachaCopyService;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Point\Services\V2PointService;
use App\Models\V2\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait MemberRankGachaFixture
{
    use MemberRankAccountingFixture;

    protected function importRankGachas(): void
    {
        app(V2CatalogFixtureImporter::class)->import(json_decode(file_get_contents(__DIR__.'/../V2/Fixtures/catalog-alpha.json'), true, flags: JSON_THROW_ON_ERROR));
    }

    protected function rankGachaInput(string $type = 'standard', string $mode = 'none', ?int $level = null): array
    {
        $input = app(V2GachaCopyService::class)->projection('0198a001-0000-7000-8000-000000000011', true);
        $input = [...$input, 'gacha_type' => $type, 'title' => 'Synthetic member rank Gacha',
            'member_rank_mode' => $mode, 'member_rank_level' => $level,
            'publish_start_at' => '2026-07-01T00:00:00Z', 'publish_end_at' => null,
            'price_points' => $type === 'standard' ? 1 : 0,
            'daily_draw_limit' => $type === 'login_daily' ? 1 : 0,
            'audience_code' => 'all_users', 'first_time_eligible_days' => 7, 'allowed_draw_counts' => [1],
            'total_count' => $type === 'standard' ? 200 : null,
            'minimum_exchange_points' => $type === 'login_daily' ? 0 : null];
        if ($type !== 'standard') {
            $input['category_id'] = null;
            $input['tag_ids'] = [];
        }
        foreach ($input['prizes'] as &$prize) {
            $prize['external_id'] = null;
            $prize['initial_inventory'] = 100;
            $prize['percentage'] = $type === 'standard' ? null : '50';
        }
        unset($prize);

        return $input;
    }

    protected function rankGacha(string $type = 'standard', string $mode = 'none', ?int $level = null, array $overrides = []): object
    {
        $context = $this->rankAdmin();
        $service = app(V2GachaCompositionService::class);
        $gacha = DB::transaction(fn (): object => $service->save($service->validate([
            ...$this->rankGachaInput($type, $mode, $level), ...$overrides,
        ]), null, null, $context));
        $version = DB::table('catalog_gacha_versions')->where('gacha_id', $gacha->id)->sole();
        app(V2CatalogMasterMutationService::class)->publishGachaVersionImmediately($context, $gacha->public_id, $version->public_id,
            'm5-publish-'.Str::uuid7(), ['expected_revision' => (int) $version->revision, 'expected_gacha_revision' => (int) $gacha->revision]);

        return DB::table('catalog_gachas')->where('id', $gacha->id)->sole();
    }

    protected function fundedRankUser(int $level): User
    {
        $user = $this->rankUser();
        $phone = '+8190'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT);
        \App\Models\V2\UserPhoneNumber::query()->create([
            'user_id' => $user->id, 'phone_ciphertext' => \Illuminate\Support\Facades\Crypt::encryptString($phone),
            'phone_hmac' => app(\App\Domain\Identity\Services\V2IdentityCorrelation::class)->hash($phone),
            'verified_at' => now(),
        ]);
        $this->seedRankState($user, $level);
        app(V2PointService::class)->grantFree($user->id, 1000, 'm5-fund-'.$user->public_id);

        return $user;
    }

    protected function rankDraw(User $user, object $gacha, ?string $key = null): array
    {
        return app(V2DrawService::class)->create($user, $gacha->public_id, 1, $key ?? 'm5-'.Str::uuid7(), (string) Str::uuid7());
    }
}
