<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Models\V2\User;
use App\Models\V2\UserEmailVerification;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

final class RegistrationQualificationBackfillTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_backfill_uses_earliest_success_and_proven_external_creation_not_mutable_verification_date(): void
    {
        $email = $this->user();
        foreach (['2026-07-02T00:00:00Z', '2026-07-01T00:00:00Z'] as $usedAt) {
            $instant = CarbonImmutable::parse($usedAt);
            UserEmailVerification::query()->create([
                'user_id' => $email->id, 'token_hash' => hash('sha256', (string) Str::uuid7()),
                'created_at' => $instant->subMinute(), 'expires_at' => $instant->addMinutes(30), 'used_at' => $instant,
            ]);
        }
        $external = $this->user();
        app(V2OutboxService::class)->enqueue('identity.external-user-created', 'user', $external->public_id,
            'identity.external_user.created', ['user_id' => $external->public_id], 'login-backfill-external-fixture');
        $this->backfill();
        self::assertSame('2026-07-01T00:00:00Z', $email->fresh()->first_registration_qualified_at->utc()->toIso8601ZuluString());
        self::assertTrue($external->fresh()->first_registration_qualified_at->equalTo($external->created_at));
    }

    public function test_backfill_qualifies_only_seven_evidenced_users_and_leaves_three_unknown_users_null(): void
    {
        $expected = [];
        for ($index = 0; $index < 6; $index++) {
            $user = $this->user();
            $qualifiedAt = CarbonImmutable::parse('2026-07-01T00:00:00Z')->addDays($index);
            UserEmailVerification::query()->create([
                'user_id' => $user->id, 'token_hash' => hash('sha256', (string) Str::uuid7()),
                'created_at' => $qualifiedAt->subMinute(), 'expires_at' => $qualifiedAt->addMinutes(30), 'used_at' => $qualifiedAt,
            ]);
            $expected[$user->id] = $qualifiedAt;
        }
        $external = $this->user();
        app(V2OutboxService::class)->enqueue('identity.external-user-created', 'user', $external->public_id,
            'identity.external_user.created', ['user_id' => $external->public_id], 'login-backfill-seven-evidenced');
        $expected[$external->id] = $external->created_at;

        $unknown = [$this->user(), $this->user(), $this->user()];
        $unknown[0]->forceFill(['created_at' => CarbonImmutable::parse('2020-01-01T00:00:00Z')])->save();
        UserEmailVerification::query()->create([
            'user_id' => $unknown[1]->id, 'token_hash' => hash('sha256', (string) Str::uuid7()),
            'created_at' => now()->subDays(2), 'expires_at' => now()->subDays(2)->addMinutes(30), 'used_at' => null,
        ]);
        app(V2OutboxService::class)->enqueue('identity.external-identity-linked', 'user', $unknown[2]->public_id,
            'identity.external_identity.linked', ['user_id' => $unknown[2]->public_id], 'login-backfill-unrelated-event');

        $this->backfill();
        self::assertCount(7, $expected);
        foreach ($expected as $userId => $qualifiedAt) {
            self::assertTrue(User::query()->findOrFail($userId)->first_registration_qualified_at->equalTo($qualifiedAt));
        }
        foreach ($unknown as $user) {
            self::assertNotNull($user->fresh()->email_verified_at);
            self::assertNull($user->fresh()->first_registration_qualified_at);
        }
        self::assertSame(7, User::query()->whereIn('id', array_keys($expected))->whereNotNull('first_registration_qualified_at')->count());
        self::assertSame(3, User::query()->whereIn('id', array_map(fn (User $user) => $user->id, $unknown))->whereNull('first_registration_qualified_at')->count());
    }

    public function test_backfill_does_not_overwrite_immutable_non_null_qualification(): void
    {
        $user = $this->user();
        $qualifiedAt = CarbonImmutable::parse('2026-07-01T00:00:00Z');
        $user->forceFill(['first_registration_qualified_at' => $qualifiedAt])->save();
        UserEmailVerification::query()->create([
            'user_id' => $user->id, 'token_hash' => hash('sha256', (string) Str::uuid7()),
            'created_at' => $qualifiedAt->subDay()->subMinute(), 'expires_at' => $qualifiedAt->subDay()->addMinutes(30),
            'used_at' => $qualifiedAt->subDay(),
        ]);
        $this->backfill();
        self::assertTrue($user->fresh()->first_registration_qualified_at->equalTo($qualifiedAt));
        foreach ([null, $qualifiedAt->addDay()->toIso8601String()] as $replacement) {
            try {
                DB::transaction(fn () => DB::table('users')->where('id', $user->id)
                    ->update(['first_registration_qualified_at' => $replacement]));
                self::fail('Non-null registration qualification must remain immutable.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('First registration qualification is immutable', $exception->getMessage());
            }
            self::assertTrue($user->fresh()->first_registration_qualified_at->equalTo($qualifiedAt));
        }
    }

    private function backfill(): void
    {
        $migration = require database_path('migrations-v2/2026_10_04_000078_add_v2_login_gachas.php');
        (new ReflectionMethod($migration, 'backfillRegistrationTimestamp'))->invoke($migration);
    }

    private function user(): User
    {
        $email = 'login-backfill-'.Str::uuid7().'@example.test';

        return User::query()->create([
            'email_display' => $email, 'email_normalized' => $email, 'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('synthetic backfill password'), 'state' => V2UserState::Active,
        ]);
    }
}
