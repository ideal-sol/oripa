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

    public function test_unrecoverable_verified_user_fails_closed_with_count_and_reason(): void
    {
        $user = $this->user();
        try {
            DB::transaction(fn () => $this->backfill());
            self::fail('Missing trustworthy registration evidence must require Human review.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('LOGIN_REGISTRATION_BACKFILL_UNRESOLVED count=1 reason=', $exception->getMessage());
        }
        self::assertNull($user->fresh()->first_registration_qualified_at);
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
