<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Contracts\V2EmailVerificationNotifier;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminUserReadService;
use App\Domain\Identity\Services\V2UserAuthenticationService;
use App\Models\V2\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;
use Tests\TestCase;

final class EmailVerificationFailureStateTest extends TestCase
{
    private FailureStateNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifier = new FailureStateNotifier();
        $this->app->instance(V2EmailVerificationNotifier::class, $this->notifier);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_expired_access_is_audited_once_and_visible_through_admin_filter(): void
    {
        $service = app(V2UserAuthenticationService::class);
        $user = $service->register(
            'failure-state@example.test',
            'valid failure state password',
            '/',
            '192.0.2.180'
        );
        $token = $this->notifier->tokens[0];
        $this->travel(61)->minutes();

        $this->assertExpired($service, $user->public_id, $token);
        $audit = DB::table('audit_logs')
            ->where('action_code', 'identity.verification_failure')
            ->where('target_public_id', $user->public_id)
            ->get();
        self::assertCount(1, $audit);
        self::assertSame('expired', $audit->first()->reason_code);
        self::assertSame('verification_failed', $user->refresh()->state->value);

        $this->assertExpired($service, $user->public_id, $token);
        self::assertSame(1, DB::table('audit_logs')
            ->where('action_code', 'identity.verification_failure')
            ->where('target_public_id', $user->public_id)
            ->count());

        $context = new V2AdminAuthorizationContext(
            1,
            (string) Str::uuid7(),
            V2AdminRole::Operator,
            hash('sha256', 'session'),
            hash('sha256', 'correlation'),
            (string) Str::uuid7()
        );
        $date = $user->created_at->setTimezone('Asia/Tokyo')->toDateString();
        $result = app(V2AdminUserReadService::class)->users(
            $context,
            null,
            20,
            $user->public_id,
            V2UserState::VerificationFailed->value,
            $date,
            $date
        );
        self::assertSame($user->public_id, $result['items'][0]['id']);
        self::assertSame('verification_failed', $result['items'][0]['status']);
    }

    private function assertExpired(
        V2UserAuthenticationService $service,
        string $publicId,
        string $token
    ): void {
        try {
            $service->verify($publicId, $token);
            self::fail('Expired verification must fail.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('VERIFICATION_LINK_EXPIRED', $exception->errorCode);
        }
    }
}

final class FailureStateNotifier implements V2EmailVerificationNotifier
{
    /** @var list<string> */
    public array $tokens = [];

    public function send(
        User $user,
        #[SensitiveParameter] string $token,
        string $redirectPath,
        string $deduplicationKey
    ): void {
        $this->tokens[] = $token;
    }
}
