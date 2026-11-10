<?php

namespace Tests\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LineBotMigrationUpgradeTest extends TestCase
{
    public function test_107_existing_terminal_rows_and_guards_survive_108(): void
    {
        self::assertTrue(app()->environment('testing'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        DB::beginTransaction();
        try {
            // A transaction-local schema contains only synthetic fixtures. No old
            // migration is changed, reversed or applied to the application's schema.
            $schema = 'line_d1_upgrade_'.bin2hex(random_bytes(8));
            DB::statement('CREATE SCHEMA '.$schema);
            DB::statement('SET LOCAL search_path TO '.$schema);
            $paths = glob(database_path('migrations-v2/*.php'));
            sort($paths);
            foreach ($paths as $path) {
                if (str_contains($path, '_000108_')) continue;
                (require $path)->up();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                DB::statement('SET CONSTRAINTS ALL DEFERRED');
            }
            $user = DB::table('users')->insertGetId([
                'public_id' => (string) Str::uuid7(), 'email_display' => 'upgrade@example.test',
                'email_normalized' => 'upgrade@example.test', 'password_hash' => '$argon2id$synthetic',
                'state' => 'active', 'email_verified_at' => now(),
            ]);
            $cipher = Crypt::encryptString('synthetic-upgrade-subject');
            $hash = hash('sha256', 'synthetic-upgrade-subject');
            DB::table('external_identity_accounts')->insert([
                'public_id' => (string) Str::uuid7(), 'user_id' => $user, 'provider' => 'line',
                'issuer' => 'https://access.line.me', 'subject_hash' => $hash, 'linked_at' => now(),
                'last_authenticated_at' => now(), 'subject_ciphertext' => $cipher,
                'line_observed_at_ms' => 1000, 'line_observed_friend' => true,
            ]);
            DB::table('line_friendships')->insert([
                'public_id' => (string) Str::uuid7(), 'subject_hash' => $hash, 'user_id' => $user,
                'status' => 'friend', 'followed_at' => now(), 'subject_ciphertext' => $cipher,
            ]);
            DB::table('line_pending_follows')->insert([
                'public_id' => (string) Str::uuid7(), 'subject_hash' => $hash, 'status' => 'claimed',
                'claimed_by_user_id' => $user, 'followed_at' => now(), 'claimed_at' => now(),
                'subject_ciphertext' => $cipher,
            ]);
            DB::table('line_pending_follows')->insert([
                'public_id' => (string) Str::uuid7(), 'subject_hash' => hash('sha256', 'unfollowed'),
                'status' => 'unfollowed', 'followed_at' => now(), 'unfollowed_at' => now(),
            ]);
            foreach (['sent', 'failed', 'skipped'] as $status) {
                DB::table('line_webhook_events')->insert([
                    'public_id' => (string) Str::uuid7(), 'subject_hash' => $hash,
                    'event_id_hash' => hash('sha256', $status), 'event_type' => $status === 'skipped' ? 'unfollow' : 'follow',
                    'reply_status' => $status, 'occurred_at' => now(),
                    'reply_attempted_at' => $status === 'skipped' ? null : now(),
                    'reply_failure_code' => $status === 'failed' ? 'synthetic_failure' : null,
                ]);
            }
            $before = [];
            foreach (['external_identity_accounts', 'line_friendships', 'line_pending_follows', 'line_webhook_events'] as $table) {
                $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            }
            $guards = [];
            foreach (['v2_line_subject_ciphertext_guard', 'v2_line_pending_follow_guard', 'v2_line_webhook_event_guard'] as $name) {
                $guards[$name] = DB::selectOne("SELECT pg_get_functiondef(?::regprocedure) AS definition", [$name.'()'])->definition;
            }
            $migration = require database_path('migrations-v2/2026_11_10_000108_add_v2_line_bot_management.php');
            $migration->up();
            $legacy = DB::table('line_bots')->where('legacy', true)->sole();
            self::assertFalse($legacy->is_active);
            self::assertNull($legacy->channel_id);
            foreach ($before as $table => $rows) {
                $after = DB::table($table)->orderBy('id')->get()->map(function ($row) use ($table, $legacy): array {
                    $row = (array) $row;
                    if ($table !== 'external_identity_accounts') {
                        self::assertSame($legacy->id, $row['line_bot_id']);
                        unset($row['line_bot_id']);
                    }
                    return $row;
                })->all();
                self::assertSame($rows, $after);
            }
            foreach ($guards as $name => $definition) self::assertSame($definition, DB::selectOne("SELECT pg_get_functiondef(?::regprocedure) AS definition", [$name.'()'])->definition);
            self::assertSame(1000, DB::table('line_friend_observations')->sole()->observed_at_ms);
            foreach ([
                fn () => DB::table('line_pending_follows')->where('status', 'claimed')->update(['subject_ciphertext' => Crypt::encryptString('replacement')]),
                fn () => DB::table('line_pending_follows')->where('status', 'claimed')->update(['status' => 'pending']),
                fn () => DB::table('line_webhook_events')->where('reply_status', 'sent')->update(['reply_status' => 'pending']),
                fn () => DB::table('line_friendships')->update(['line_bot_id' => $legacy->id + 1]),
            ] as $invalid) {
                try {
                    DB::transaction($invalid);
                    self::fail('Immutable history mutation accepted');
                } catch (\Illuminate\Database\QueryException $e) {
                    self::assertSame('P0001', $e->errorInfo[0]);
                }
            }
            try {
                $migration->down();
                self::fail('Forward-only migration allowed rollback');
            } catch (\RuntimeException $e) {
                self::assertSame('Forward-only LINE bot management migration', $e->getMessage());
            }
        } finally {
            DB::rollBack(); // Removes only the schema created in this transaction.
        }
    }
}
