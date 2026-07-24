<?php

namespace Tests\V2;

use App\Domain\Audit\V2\Services\V2AuditChainVerifier;
use App\Domain\Audit\V2\Services\V2AuditDailyDigestService;
use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2EmailVerificationNotifier;
use App\Domain\Identity\Contracts\V2SecurityEventSink;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Models\V2\AuditDailyDigest;
use App\Models\V2\AuditLog;
use App\Models\V2\OutboxMessage;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class AuditOutboxFoundationTest extends TestCase
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
    }

    public function test_audit_is_append_only_and_hash_chain_is_valid(): void
    {
        $audit = app(V2AuditLogService::class);
        $first = $audit->record('test.audit.first', [
            'occurred_at' => '2026-01-01 00:00:00+00',
            'actor_type' => 'system',
            'auth_realm' => 'system',
            'outcome' => 'success',
            'metadata' => ['fixture' => 'first'],
        ]);
        $second = $audit->record('test.audit.second', [
            'occurred_at' => '2026-01-01 00:00:01+00',
            'actor_type' => 'system',
            'auth_realm' => 'system',
            'outcome' => 'failure',
            'reason_code' => 'fixture_failure',
            'metadata' => ['fixture' => 'second'],
        ]);

        self::assertNull($first->previous_hash);
        self::assertSame($first->record_hash, $second->previous_hash);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $second->record_hash);
        self::assertTrue(app(V2AuditChainVerifier::class)->verify());

        $second->reason_code = 'changed';
        $this->expectException(LogicException::class);
        $second->save();
    }

    public function test_database_rejects_audit_update_delete_and_truncate(): void
    {
        $record = app(V2AuditLogService::class)->record('test.audit.immutable');
        foreach (
            [
                fn (): int => DB::table('audit_logs')->where('id', $record->id)
                    ->update(['reason_code' => 'changed']),
                fn (): int => DB::table('audit_logs')->where('id', $record->id)->delete(),
                function (): int {
                    DB::statement('TRUNCATE TABLE audit_logs');

                    return 1;
                },
            ] as $mutation
        ) {
            try {
                DB::transaction($mutation);
                self::fail('Audit mutation must be rejected by PostgreSQL.');
            } catch (QueryException) {
                self::assertDatabaseHas('audit_logs', ['id' => $record->id]);
            }
        }
    }

    public function test_hash_chain_detects_tampering(): void
    {
        $record = app(V2AuditLogService::class)->record('test.audit.tamper');
        DB::beginTransaction();
        try {
            DB::statement('ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_reject_mutation');
            DB::table('audit_logs')->where('id', $record->id)
                ->update(['record_hash' => str_repeat('0', 64)]);
            self::assertFalse(app(V2AuditChainVerifier::class)->verify());
        } finally {
            DB::rollBack();
        }
        self::assertTrue(app(V2AuditChainVerifier::class)->verify());
    }

    public function test_daily_digest_is_hmac_protected_and_immutable(): void
    {
        $audit = app(V2AuditLogService::class);
        $audit->record('test.digest.first', ['occurred_at' => '2026-02-02 00:00:00+09']);
        $audit->record('test.digest.second', ['occurred_at' => '2026-02-02 01:00:00+09']);

        $digest = app(V2AuditDailyDigestService::class)->generate('2026-02-02');
        self::assertSame(2, $digest->record_count);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $digest->digest_hash);
        self::assertDatabaseHas('audit_daily_digests', ['business_date' => '2026-02-02']);
        $this->expectException(LogicException::class);
        $digest->record_count = 1;
        $digest->save();
    }

    public function test_audit_rejects_sensitive_metadata_and_does_not_store_hmac_key(): void
    {
        self::assertFalse(SchemaInspector::columnExists('audit_logs', 'hmac_key'));
        $this->expectException(RuntimeException::class);
        app(V2AuditLogService::class)->record('test.audit.sensitive', [
            'metadata' => ['password_hash' => 'prohibited'],
        ]);
    }

    public function test_audit_concurrent_writes_are_serialized_without_chain_forks(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the concurrency test.');
        }
        $children = [];
        for ($index = 0; $index < 4; $index++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                DB::disconnect();
                DB::reconnect();
                app(V2AuditLogService::class)->record('test.audit.concurrent', [
                    'metadata' => ['writer' => $index],
                ]);
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $child) {
            pcntl_waitpid($child, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        DB::disconnect();
        DB::reconnect();
        self::assertSame(
            4,
            AuditLog::query()->where('action_code', 'test.audit.concurrent')->count()
        );
        self::assertTrue(app(V2AuditChainVerifier::class)->verify());
    }

    public function test_outbox_requires_transaction_rolls_back_and_deduplicates(): void
    {
        $outbox = app(V2OutboxService::class);
        try {
            $outbox->enqueue(
                'test.topic',
                'test',
                null,
                'test.rolled_back',
                ['message_ciphertext' => 'encrypted'],
                'test:rollback'
            );
            self::fail('Outbox enqueue outside a transaction must fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('active domain transaction', $exception->getMessage());
        }

        DB::beginTransaction();
        $outbox->enqueue(
            'test.topic',
            'test',
            null,
            'test.rolled_back',
            ['message_ciphertext' => 'encrypted'],
            'test:rollback'
        );
        DB::rollBack();
        self::assertDatabaseMissing('outbox_messages', ['deduplication_key' => 'test:rollback']);

        DB::transaction(function () use ($outbox): void {
            $first = $outbox->enqueue(
                'test.topic',
                'test',
                null,
                'test.deduplicated',
                ['message_ciphertext' => 'encrypted'],
                'test:deduplicated'
            );
            $second = $outbox->enqueue(
                'test.topic',
                'test',
                null,
                'test.deduplicated',
                ['message_ciphertext' => 'encrypted'],
                'test:deduplicated'
            );
            self::assertSame($first->id, $second->id);
        });
        self::assertSame(
            1,
            OutboxMessage::query()
                ->where('deduplication_key', 'test:deduplicated')
                ->count()
        );
    }

    public function test_outbox_claim_lease_retry_success_and_failure_boundaries(): void
    {
        CarbonImmutable::setTestNow('2026-03-03 00:00:00+00');
        $outbox = app(V2OutboxService::class);
        DB::transaction(function () use ($outbox): void {
            foreach (['success', 'retry', 'failure'] as $name) {
                $outbox->enqueue(
                    'test.delivery',
                    'test',
                    null,
                    'test.'.$name,
                    ['message_ciphertext' => 'encrypted-'.$name],
                    'test:delivery:'.$name
                );
            }
        });

        $claimed = $outbox->claim('worker-a', 2, 30);
        self::assertCount(2, $claimed);
        self::assertCount(1, $outbox->claim('worker-b', 2, 30));
        $outbox->markDelivered($claimed[0]->public_id, 'worker-a');
        $outbox->retry($claimed[1]->public_id, 'worker-a', 'temporary_failure', 60);
        self::assertDatabaseHas('outbox_messages', [
            'public_id' => $claimed[0]->public_id,
            'status' => 'delivered',
        ]);
        self::assertDatabaseHas('outbox_messages', [
            'public_id' => $claimed[1]->public_id,
            'status' => 'pending',
            'last_error_code' => 'temporary_failure',
        ]);

        CarbonImmutable::setTestNow('2026-03-03 00:01:01+00');
        $retried = $outbox->claim('worker-c', 1, 30)->sole();
        self::assertSame($claimed[1]->public_id, $retried->public_id);
        $outbox->markFailed($retried->public_id, 'worker-c', 'permanent_failure');
        self::assertDatabaseHas('outbox_messages', [
            'public_id' => $retried->public_id,
            'status' => 'failed',
        ]);
        CarbonImmutable::setTestNow();
    }

    public function test_outbox_concurrent_claim_does_not_deliver_the_same_message_twice(): void
    {
        DB::table('outbox_messages')->delete();
        $outbox = app(V2OutboxService::class);
        DB::transaction(function () use ($outbox): void {
            foreach (range(1, 4) as $index) {
                $outbox->enqueue(
                    'test.concurrent-delivery',
                    'test',
                    null,
                    'test.concurrent',
                    ['message_ciphertext' => 'encrypted-'.$index],
                    'test:concurrent:'.$index
                );
            }
        });

        $script = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $claimed = app(App\Domain\Outbox\Services\V2OutboxService::class)
                ->claim($argv[1], 2, 30);
            exit($claimed->count() === 2 ? 0 : 1);
            PHP;
        $processes = [];
        foreach (['worker-concurrent-a', 'worker-concurrent-b'] as $worker) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, '-r', $script, $worker],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                base_path()
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        foreach ($processes as [$process, $pipes]) {
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process));
        }
        self::assertSame(
            4,
            OutboxMessage::query()
                ->where('topic', 'test.concurrent-delivery')
                ->where('status', 'processing')
                ->where('attempts', 1)
                ->count()
        );
        self::assertSame(
            2,
            OutboxMessage::query()
                ->where('topic', 'test.concurrent-delivery')
                ->distinct()
                ->count('locked_by')
        );
    }

    public function test_email_verification_outbox_payload_is_encrypted(): void
    {
        $user = User::query()->create([
            'email_display' => 'outbox@example.test',
            'email_normalized' => 'outbox@example.test',
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::PendingVerification,
        ]);
        DB::transaction(function () use ($user): void {
            app(V2EmailVerificationNotifier::class)->send(
                $user,
                'verification-token-plaintext',
                '/',
                'email-verification:test-encrypted'
            );
        });
        $message = OutboxMessage::query()
            ->where('deduplication_key', 'email-verification:test-encrypted')
            ->firstOrFail();
        $serialized = json_encode($message->payload, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('outbox@example.test', $serialized);
        self::assertStringNotContainsString('verification-token-plaintext', $serialized);
        $decrypted = json_decode(
            Crypt::decryptString($message->payload['message_ciphertext']),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        self::assertSame('outbox@example.test', $decrypted['recipient']);
        self::assertSame('verification-token-plaintext', $decrypted['verification_token']);
    }

    public function test_authentication_and_mfa_security_events_are_persisted(): void
    {
        $events = app(V2SecurityEventSink::class);
        $subject = (string) \Illuminate\Support\Str::uuid();
        $cases = [
            ['register', ['realm' => 'user', 'subject_id' => $subject, 'result' => 'pending_verification']],
            ['verification_success', ['realm' => 'user', 'subject_id' => $subject]],
            ['verification_failure', ['realm' => 'user', 'reason' => 'invalid_link']],
            ['login_success', ['realm' => 'user', 'subject_id' => $subject]],
            ['login_failure', ['realm' => 'user']],
            ['logout', ['realm' => 'user', 'subject_id' => $subject]],
            ['admin_invitation', ['realm' => 'admin', 'subject_id' => $subject, 'role' => 'owner']],
            ['mfa_enrollment', ['realm' => 'admin', 'subject_id' => $subject, 'method' => 'totp']],
            ['mfa_failure', ['realm' => 'admin', 'subject_id' => $subject]],
            ['recovery_code_use', ['realm' => 'admin', 'subject_id' => $subject]],
        ];
        foreach ($cases as [$event, $context]) {
            $events->record($event, $context);
        }
        self::assertSame(
            count($cases),
            AuditLog::query()->where('action_code', 'like', 'identity.%')->count()
        );
        self::assertTrue(app(V2AuditChainVerifier::class)->verify());
    }
}

final class SchemaInspector
{
    public static function columnExists(string $table, string $column): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn($table, $column);
    }
}
