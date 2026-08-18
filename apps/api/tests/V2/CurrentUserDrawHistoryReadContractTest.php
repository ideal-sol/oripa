<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Draw\Services\V2CryptographicRandomSource;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CurrentUserDrawHistoryReadContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-08-15T00:00:00Z');
        config([
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('h', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_history_reuses_canonical_draw_and_version_presentation_with_stable_cursor(): void
    {
        [$user, $gachaId] = $this->fixture(totalCount: 8);
        $other = $this->user('other');
        CarbonImmutable::setTestNow('2026-08-14T23:59:00Z');
        $otherDraw = $this->draw($other, $gachaId, 1, 'draw-history-other-key');
        CarbonImmutable::setTestNow('2026-08-15T00:00:00Z');
        $firstDraw = $this->draw($user, $gachaId, 5, 'draw-history-first-key');
        $secondDraw = $this->draw($user, $gachaId, 5, 'draw-history-second-key');
        DB::table('catalog_gachas')->where('public_id', $gachaId)->update([
            'current_title' => 'Changed Current Presentation',
            'revision' => DB::raw('revision + 1'),
        ]);
        Auth::guard('v2_user')->setUser($user);
        $requestCount = DB::table('draw_requests')->count();
        $resultCount = DB::table('draw_results')->count();

        $firstPage = $this->getJson('/api/v2/me/draws?limit=1')
            ->assertOk()
            ->assertHeader('Vary', 'Cookie')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $secondDraw['id'])
            ->assertJsonPath('items.0.gacha.id', $gachaId)
            ->assertJsonPath('items.0.gacha.title', 'Fixture Catalog Gacha')
            ->assertJsonPath(
                'items.0.gacha.presentation_asset.id',
                '0198a001-0000-7000-8000-000000000005'
            )
            ->assertJsonPath('items.0.occurred_at', '2026-08-15T00:00:00Z')
            ->assertJsonPath('items.0.requested_count', 5)
            ->assertJsonPath('items.0.executed_count', $secondDraw['executed_count'])
            ->assertJsonPath('items.0.status', ['code' => 'completed', 'label' => '完了']);
        $this->assertPrivateNoStore($firstPage);
        $cursor = $firstPage->json('next_cursor');
        self::assertIsString($cursor);

        $secondPage = $this->getJson('/api/v2/me/draws?limit=1&cursor='.$cursor)
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $firstDraw['id'])
            ->assertJsonPath('items.0.requested_count', 5)
            ->assertJsonPath('items.0.executed_count', $firstDraw['executed_count'])
            ->assertJsonPath('next_cursor', null);
        $this->getJson('/api/v2/me/draws?limit=1')
            ->assertJsonPath('items.0.id', $secondDraw['id']);

        $serialized = (string) $firstPage->getContent().$secondPage->getContent();
        self::assertStringNotContainsString($otherDraw['id'], $serialized);
        foreach ([
            'user_id',
            'gacha_version_id',
            'gacha_draw_state_id',
            'probability_version_id',
            'idempotency_record_id',
            'request_hash',
            'response_data',
            'is_qa_draw',
            'event_code',
            'internal_id',
        ] as $internalField) {
            self::assertStringNotContainsString($internalField, $serialized);
        }
        self::assertSame($requestCount, DB::table('draw_requests')->count());
        self::assertSame($resultCount, DB::table('draw_results')->count());
    }

    public function test_history_empty_invalid_cursor_pagination_and_owner_boundary_are_typed(): void
    {
        [$other, $gachaId] = $this->fixture(totalCount: 10);
        $otherDraw = $this->draw($other, $gachaId, 1, 'draw-history-owner-key');
        $user = $this->user('empty');
        Auth::guard('v2_user')->setUser($user);

        $this->getJson('/api/v2/me/draws')
            ->assertOk()
            ->assertExactJson(['items' => [], 'next_cursor' => null]);
        $this->getJson('/api/v2/me/draws?cursor=invalid-cursor')
            ->assertUnprocessable()
            ->assertHeader('Vary', 'Cookie')
            ->assertJsonPath('code', 'INVALID_CURSOR');
        $otherCursor = rtrim(strtr(base64_encode($otherDraw['id']), '+/', '-_'), '=');
        $this->getJson('/api/v2/me/draws?cursor='.$otherCursor)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_CURSOR');
        $this->getJson('/api/v2/me/draws?limit=101')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_PAGINATION');
        $problem = $this->getJson('/api/v2/me/draws?limit=not-an-integer')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_PAGINATION');
        $this->assertPrivateNoStore($problem);
    }

    public function test_history_requires_current_user_session_and_problem_is_private(): void
    {
        $response = $this->getJson('/api/v2/me/draws')
            ->assertUnauthorized()
            ->assertHeader('Vary', 'Cookie')
            ->assertJsonPath('code', 'AUTHENTICATION_REQUIRED');
        $this->assertPrivateNoStore($response);
    }

    /** @return array{User, string} */
    private function fixture(int $totalCount): array
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $fixture['gachas'][0]['sold_count'] = 0;
        $fixture['versions'][0]['total_count'] = $totalCount;
        $fixture['versions'][0]['allowed_draw_counts'] = [1, 5, 10];
        $quantities = [0, $totalCount];
        foreach ($fixture['gacha_prizes'] as $index => &$relation) {
            $relation['initial_inventory'] = $quantities[$index] ?? 0;
        }
        unset($relation);
        app(V2CatalogFixtureImporter::class)->import($fixture);
        $this->app->instance(
            V2CryptographicRandomSource::class,
            new V2CryptographicRandomSource(
                static fn (int $minimum, int $maximum): int =>
                    intdiv(150_000 * $maximum, 1_000_000) + 1
            )
        );

        return [$this->user('owner'), $fixture['gachas'][0]['public_id']];
    }

    private function user(string $label): User
    {
        $email = 'draw-history-'.$label.'-'.Str::uuid7().'@example.test';
        $user = User::query()->create([
            'display_name' => 'Synthetic draw history reader',
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid user password'),
            'state' => V2UserState::Active,
        ]);
        app(V2PointService::class)->grantFree(
            $user->id,
            10_000,
            'draw-history-points-'.Str::uuid7()
        );

        return $user;
    }

    /** @return array<string, mixed> */
    private function draw(User $user, string $gachaId, int $count, string $key): array
    {
        return app(V2DrawService::class)->create(
            $user,
            $gachaId,
            $count,
            $key,
            (string) Str::uuid7()
        );
    }

    private function assertPrivateNoStore(mixed $response): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
    }
}
