<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2SecurityEventSink;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Models\V2\Admin;
use App\Models\V2\AdminInvitation;
use App\Models\V2\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class InitialOwnerBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_temporary_password_bootstrap_persists_audit_without_mail_delivery(): void
    {
        $outboxCount = DB::table('outbox_messages')->count();
        $this->artisan('v2:identity:create-owner-invitation', [
            'email' => 'owner@example.test',
        ])->assertSuccessful();

        $owner = Admin::query()->sole();
        self::assertSame(V2AdminRole::Owner, $owner->role);
        self::assertSame(V2AdminState::Active, $owner->state);
        self::assertNotNull($owner->email_verified_at);
        self::assertDatabaseCount('admin_invitations', 0);
        self::assertSame($outboxCount, DB::table('outbox_messages')->count());
        $this->assertBootstrapAudit($owner, 'temporary_password');

        $this->artisan('v2:identity:create-owner-invitation', [
            'email' => 'second-owner@example.test',
        ])->assertFailed();

        self::assertSame(1, Admin::query()->where('role', V2AdminRole::Owner->value)->count());
        self::assertSame(1, AuditLog::query()
            ->where('action_code', 'identity.admin_invitation')
            ->where('actor_public_id', $owner->public_id)
            ->count());
    }

    public function test_invitation_bootstrap_persists_audit_and_preserves_invitation_contract(): void
    {
        $outboxCount = DB::table('outbox_messages')->count();
        $policyActor = Admin::query()->create([
            'email_display' => 'policy-actor@example.test',
            'email_normalized' => 'policy-actor@example.test',
            'email_verified_at' => now()->startOfSecond(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid policy actor password'),
            'role' => V2AdminRole::Operator,
            'state' => V2AdminState::Active,
        ]);
        DB::table('admin_authentication_policy')->where('id', 1)->update([
            'invitation_required' => true,
            'revision' => DB::raw('revision + 1'),
            'updated_by_admin_id' => $policyActor->getKey(),
            'last_mutation_request_id' => (string) Str::uuid7(),
            'updated_at' => now()->startOfSecond(),
        ]);

        $this->artisan('v2:identity:create-owner-invitation', [
            'email' => 'invited-owner@example.test',
        ])->assertSuccessful();

        $owner = Admin::query()->where('role', V2AdminRole::Owner->value)->sole();
        self::assertSame(V2AdminRole::Owner, $owner->role);
        self::assertSame(V2AdminState::Invited, $owner->state);
        self::assertNull($owner->email_verified_at);
        self::assertSame(1, AdminInvitation::query()->where('admin_id', $owner->getKey())->count());
        self::assertSame($outboxCount, DB::table('outbox_messages')->count());
        $this->assertBootstrapAudit($owner, 'invitation');
    }

    public function test_audit_failure_rolls_back_initial_owner_creation(): void
    {
        $adminCount = Admin::query()->count();
        $invitationCount = AdminInvitation::query()->count();
        $auditCount = AuditLog::query()->count();
        $this->app->instance(V2SecurityEventSink::class, new FailingInitialOwnerSecurityEventSink());

        try {
            $this->artisan('v2:identity:create-owner-invitation', [
                'email' => 'rollback-owner@example.test',
            ]);
            self::fail('Audit failure must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('synthetic-initial-owner-audit-failure', $exception->getMessage());
        }

        self::assertSame($adminCount, Admin::query()->count());
        self::assertSame($invitationCount, AdminInvitation::query()->count());
        self::assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_persistent_sink_still_rejects_unsupported_events_and_context(): void
    {
        $events = app(V2SecurityEventSink::class);
        $auditCount = AuditLog::query()->count();

        foreach ([
            ['unsupported_owner_bootstrap', ['realm' => 'admin']],
            ['admin_invitation', ['realm' => 'admin', 'unsupported' => 'value']],
        ] as [$event, $context]) {
            try {
                $events->record($event, $context);
                self::fail('Unsupported persistent security audit input must fail closed.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'Security event is outside the persistent audit allowlist.',
                    $exception->getMessage()
                );
            }
        }

        self::assertSame($auditCount, AuditLog::query()->count());
    }

    private function assertBootstrapAudit(Admin $owner, string $mode): void
    {
        $audit = AuditLog::query()
            ->where('action_code', 'identity.admin_invitation')
            ->where('actor_public_id', $owner->public_id)
            ->sole();

        self::assertSame($owner->public_id, $audit->actor_public_id);
        self::assertSame(V2AdminRole::Owner->value, $audit->actor_role);
        self::assertSame('admin', $audit->auth_realm);
        self::assertSame('success', $audit->outcome);
        self::assertSame($mode, $audit->metadata_redacted->mode ?? null);
    }
}

final class FailingInitialOwnerSecurityEventSink implements V2SecurityEventSink
{
    public function record(string $event, array $context): void
    {
        throw new RuntimeException('synthetic-initial-owner-audit-failure');
    }
}
