<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Line\Services\V2ConversionEventService;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestsDatabaseTimeZones;
use Tests\TestCase;

final class LineConversionEventTest extends TestCase
{
    use TestsDatabaseTimeZones;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-11-24T00:00:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    #[DataProvider('databaseTimeZones')]
    public function test_only_one_claim_and_a_new_claim_invalidates_the_old_token(string $zone): void
    {
        $this->assertDatabaseTimeZone($zone);
        $owner = $this->user();
        $id = $this->record($owner, CarbonImmutable::now());
        $service = app(V2ConversionEventService::class);
        self::assertSame([['id' => $id, 'event' => 'line_friend_added', 'occurred_at' => '2026-11-24T00:00:00.000+00:00']],
            $service->pending($owner->id)['data']);
        $first = $service->claim($owner->id, $id)['data'];
        $this->assertStoredInstant(CarbonImmutable::now(), DB::table('user_conversion_events')->where('public_id',$id)->value('updated_at'));
        self::assertSame(120, $first['expires_in_seconds']);
        self::assertSame(hash('sha256', $first['claim_token']), DB::table('user_conversion_events')->where('public_id', $id)->value('claim_token_hash'));
        $this->expectProblem('CONVERSION_CLAIM_HELD', 409, fn () => $service->claim($owner->id, $id));
        self::assertCount(1, $service->pending($owner->id)['data']);

        CarbonImmutable::setTestNow('2026-11-24T00:02:00Z');
        $second = $service->claim($owner->id, $id)['data']['claim_token'];
        self::assertNotSame($first['claim_token'], $second);
        $this->expectProblem('CONVERSION_TOKEN_MISMATCH', 409, fn () => $service->delivered($owner->id, $id, $first['claim_token']));
        $service->delivered($owner->id, $id, $second);
        $service->delivered($owner->id, $id, $first['claim_token']);
        self::assertSame(['data' => []], $service->pending($owner->id));
        $this->expectProblem('CONVERSION_ALREADY_DELIVERED', 409, fn () => $service->claim($owner->id, $id));
    }

    public function test_delivered_accepts_an_expired_but_unreplaced_token_and_hides_ownership(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $id = $this->record($owner, CarbonImmutable::now());
        $service = app(V2ConversionEventService::class);
        $token = $service->claim($owner->id, $id)['data']['claim_token'];
        $this->expectProblem('CONVERSION_NOT_FOUND', 404, fn () => $service->claim($other->id, $id));
        $this->expectProblem('CONVERSION_NOT_FOUND', 404, fn () => $service->claim($owner->id, (string) Str::uuid7()));
        $this->expectProblem('CONVERSION_NOT_FOUND', 404, fn () => $service->delivered($other->id, $id, $token));
        CarbonImmutable::setTestNow('2026-11-24T00:03:00Z');
        $service->delivered($owner->id, $id, $token);
        self::assertNotNull(DB::table('user_conversion_events')->where('public_id', $id)->value('delivered_at'));
    }

    public function test_pending_and_claim_use_the_same_thirty_day_boundary_and_exclude_suppressed(): void
    {
        $user = $this->user();
        $suppressed = $this->user();
        $id = $this->record($user, CarbonImmutable::now()->subDays(30));
        $other = $this->record($suppressed, CarbonImmutable::now(), true);
        $service = app(V2ConversionEventService::class);
        self::assertCount(1, $service->pending($user->id)['data']);
        self::assertNotEmpty($service->claim($user->id, $id)['data']);
        CarbonImmutable::setTestNow('2026-11-24T00:00:00.001Z');
        self::assertSame(['data' => []], $service->pending($user->id));
        $this->expectProblem('CONVERSION_NOT_DELIVERABLE', 409, fn () => $service->claim($user->id, $id));
        $this->expectProblem('CONVERSION_NOT_DELIVERABLE', 409, fn () => $service->claim($suppressed->id, $other));
    }

    public function test_all_routes_require_sms_before_reading_or_claiming(): void
    {
        $owner = $this->user();
        $id = $this->record($owner, CarbonImmutable::now());
        config(['v2_identity.origins.user' => 'https://example.test']);
        Auth::guard('v2_user')->setUser($owner);
        $csrf = str_repeat('a', 64);
        $this->withCredentials()->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf);
        $base = '/api/v2/me/conversion-events/';
        $headers = ['Origin' => 'https://example.test', 'X-XSRF-TOKEN' => $csrf];
        $this->getJson($base.'pending', $headers)->assertForbidden()->assertJsonPath('code', 'SMS_VERIFICATION_REQUIRED');
        $this->postJson($base.$id.'/claim', [], $headers)->assertForbidden()->assertJsonPath('code', 'SMS_VERIFICATION_REQUIRED');
        $this->postJson($base.$id.'/delivered', ['claim_token' => $csrf], $headers)
            ->assertForbidden()->assertJsonPath('code', 'SMS_VERIFICATION_REQUIRED');
        self::assertNull(DB::table('user_conversion_events')->where('public_id', $id)->value('claim_token_hash'));
    }

    private function user(): User
    {
        $email = 'line-conversion-'.Str::uuid7().'@example.test';
        return User::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('synthetic conversion test password'),
            'state' => V2UserState::Active,
        ]);
    }

    private function record(User $user, CarbonImmutable $occurredAt, bool $suppressed = false): string
    {
        $id = (string) Str::uuid7();
        DB::table('user_conversion_events')->insert([
            'public_id' => $id,
            'user_id' => $user->id,
            'event_name' => 'line_friend_added',
            'occurred_at' => $occurredAt->format('Y-m-d H:i:s.vP'),
            'source' => 'transition',
            'suppressed' => $suppressed,
            'suppressed_reason' => $suppressed ? 'before_start' : null,
        ]);
        return $id;
    }

    private function expectProblem(string $code, int $status, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected conversion problem '.$code);
        } catch (V2AuthenticationException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame($status, $e->status);
        }
    }
}
