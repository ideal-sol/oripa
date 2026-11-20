<?php

namespace Tests\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LineBotMigrationUpgradeTest extends TestCase
{
    public function test_107_existing_terminal_rows_and_guards_survive_108_and_109(): void
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
                if (preg_match('/_0001(?:08|09|10|11|12|13|14|15|16|17|18)_/', $path)) continue;
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
            DB::table('line_bots')->where('id', $legacy->id)->update(['revision' => 9]);
            $botBefore = (array) DB::table('line_bots')->where('id', $legacy->id)->sole();
            $authenticationMigration = require database_path('migrations-v2/2026_11_11_000109_separate_v2_line_bot_auth_revision.php');
            $authenticationMigration->up();
            $recoveryMigration = require database_path('migrations-v2/2026_11_15_000110_add_v2_line_recovered_friends.php');
            $recoveryMigration->up();
            $deliveryMigration = require database_path('migrations-v2/2026_11_16_000111_add_v2_line_deliveries.php');
            $deliveryMigration->up();
            $deliveryGuards = DB::selectOne("SELECT pg_get_functiondef('v2_line_delivery_history_guard()'::regprocedure) AS definition")->definition;
            $quotaMigration = require database_path('migrations-v2/2026_11_16_000112_add_v2_line_quota_ledger.php');
            $quotaMigration->up();
            $automatedMigration = require database_path('migrations-v2/2026_11_16_000113_add_v2_line_automated_notifications.php');
            $automatedMigration->up();
            $managementMigration = require database_path('migrations-v2/2026_11_16_000114_add_v2_line_delivery_management.php');
            $managementMigration->up();
            $chatMigration = require database_path('migrations-v2/2026_11_17_000115_add_v2_line_chat_and_templates.php');
            $quotaGuard = DB::selectOne("SELECT pg_get_functiondef('v2_line_quota_history_guard()'::regprocedure) AS definition")->definition;
            $chatMigration->up();
            $stagingMigration = require database_path('migrations-v2/2026_11_17_000116_add_v2_line_friend_id_staging.php');
            $stagingMigration->up();
            self::assertSame(0, DB::table('line_friend_id_runs')->count());
            self::assertSame(0, DB::table('line_friend_id_staging')->count());
            self::assertSame(0, DB::table('line_chat_messages')->count());
            self::assertSame(0, DB::table('line_message_templates')->count());
            self::assertSame($quotaGuard, DB::selectOne("SELECT pg_get_functiondef('v2_line_quota_history_guard()'::regprocedure) AS definition")->definition);
            try { $chatMigration->down(); self::fail('Chat history rollback accepted'); }
            catch (\RuntimeException $e) { self::assertStringContainsString('Forward-only', $e->getMessage()); }
            self::assertSame(0, DB::table('line_media_assets')->count());
            try { $managementMigration->down(); self::fail('Management history rollback accepted'); }
            catch (\RuntimeException $e) { self::assertStringContainsString('Forward-only', $e->getMessage()); }

            self::assertSame(4, DB::table('line_automated_templates')->where('revision', 0)->count());
            self::assertSame(0, DB::table('line_automated_template_versions')->count());
            self::assertSame(0, DB::table('line_automated_outcomes')->count());
            self::assertSame(0, DB::table('line_quota_periods')->count());
            // Upgrade a real human baseline only after its 112 ledger and 116
            // predecessor exist. Keep all pre-107 history checks below the upgrade.
            $admin = DB::table('admins')->insertGetId(['public_id' => (string) Str::uuid(),
                'email_display' => 'quota-upgrade@example.test', 'email_normalized' => 'quota-upgrade@example.test',
                'email_verified_at' => now(), 'password_hash' => '$argon2id$synthetic', 'role' => 'owner', 'state' => 'active']);
            DB::table('line_quota_accounts')->insert(['line_bot_id' => $legacy->id,
                'authentication_revision' => 9, 'usable_from' => '2026-11-01']);
            $baseline = ['line_bot_id' => $legacy->id, 'month' => '2026-11-01', 'opening_used' => 7,
                'approved_limit' => 1000, 'effective_limit' => 1000, 'evidence_id' => (string) Str::uuid(), 'verified_by_admin_id' => $admin];
            DB::table('line_quota_periods')->insert($baseline);
            $periodBefore = (array) DB::table('line_quota_periods')->sole();
            $actorMigration = require database_path('migrations-v2/2026_11_20_000117_add_v2_line_quota_system_actor.php');
            $actorMigration->up();
            self::assertSame([...$periodBefore, 'baseline_origin' => 'human', 'authentication_revision' => null],
                (array) DB::table('line_quota_periods')->sole());
            self::assertNull(DB::table('line_quota_accounts')->sole()->last_checked_at);
            self::assertSame($quotaGuard, DB::selectOne("SELECT pg_get_functiondef('v2_line_quota_history_guard()'::regprocedure) AS definition")->definition);
            $system = [...$baseline, 'month' => '2026-12-01', 'opening_used' => 0, 'evidence_id' => (string) Str::uuid(),
                'verified_by_admin_id' => null, 'baseline_origin' => 'system', 'authentication_revision' => 9];
            foreach ([['baseline_origin' => 'human'], ['verified_by_admin_id' => $admin],
                ['authentication_revision' => null], ['authentication_revision' => 0], ['opening_used' => 1]] as $invalid) {
                try { DB::transaction(fn () => DB::table('line_quota_periods')->insert([...$system, ...$invalid])); self::fail('Invalid baseline actor accepted'); }
                catch (\Illuminate\Database\QueryException $e) { self::assertSame('23514', $e->errorInfo[0]); }
            }
            DB::table('line_quota_periods')->insert($system);
            self::assertSame(2, DB::table('line_quota_periods')->count());
            try { $actorMigration->down(); self::fail('Quota actor rollback accepted'); }
            catch (\RuntimeException $e) { self::assertStringContainsString('Forward-only', $e->getMessage()); }
            $existingPeriods = DB::table('line_quota_periods')->orderBy('month')->get()->all();
            $initialMigration = require database_path('migrations-v2/2026_11_20_000118_add_v2_line_quota_initial_origin.php');
            $initialMigration->up();
            self::assertEquals($existingPeriods, DB::table('line_quota_periods')->orderBy('month')->get()->all());
            self::assertSame($quotaGuard, DB::selectOne("SELECT pg_get_functiondef('v2_line_quota_history_guard()'::regprocedure) AS definition")->definition);
            $initial = [...$system, 'month'=>'2027-01-01', 'baseline_origin'=>'system_initial', 'opening_used'=>300];
            foreach ([['verified_by_admin_id'=>$admin], ['authentication_revision'=>null], ['authentication_revision'=>0], ['authentication_revision'=>10], ['opening_used'=>-1], ['approved_limit'=>-1], ['baseline_origin'=>'system']] as $invalid) {
                try { DB::transaction(fn () => DB::table('line_quota_periods')->insert([...$initial, ...$invalid])); self::fail('Invalid initial actor accepted'); }
                catch (\Illuminate\Database\QueryException $e) { self::assertSame('23514', $e->errorInfo[0]); }
            }
            DB::table('line_quota_periods')->insert($initial);
            DB::table('line_quota_periods')->insert([...$initial, 'month'=>'2027-02-01', 'opening_used'=>2000]);
            foreach (['opening_used'=>299, 'baseline_origin'=>'system', 'authentication_revision'=>10, 'effective_limit'=>1001] as $column=>$value) {
                try { DB::transaction(fn () => DB::table('line_quota_periods')->where('month','2027-01-01')->update([$column=>$value])); self::fail('Initial history changed'); }
                catch (\Illuminate\Database\QueryException $e) { self::assertSame('P0001', $e->errorInfo[0]); }
            }
            try { $initialMigration->down(); self::fail('Initial quota rollback accepted'); }
            catch (\RuntimeException $e) { self::assertStringContainsString('Forward-only', $e->getMessage()); }
            self::assertSame($deliveryGuards, DB::selectOne("SELECT pg_get_functiondef('v2_line_delivery_history_guard()'::regprocedure) AS definition")->definition);
            try { $quotaMigration->down(); self::fail('Quota history rollback accepted'); }
            catch (\RuntimeException $e) { self::assertSame('Forward-only LINE quota migration.', $e->getMessage()); }
            self::assertSame(0, DB::table('line_deliveries')->count());
            self::assertSame(10, DB::table('line_delivery_settings')->value('push_every'));
            $botAfter = (array) DB::table('line_bots')->where('id', $legacy->id)->sole();
            self::assertSame(9, $botAfter['authentication_revision']);
            unset($botAfter['authentication_revision']);
            self::assertSame($botBefore, $botAfter);
            foreach ([0, 10] as $invalidRevision) {
                try {
                    DB::transaction(fn () => DB::table('line_bots')->where('id', $legacy->id)->update(['authentication_revision' => $invalidRevision]));
                    self::fail('Invalid authentication generation accepted');
                } catch (\Illuminate\Database\QueryException $e) {
                    self::assertSame('23514', $e->errorInfo[0]);
                }
            }
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
            try {
                $deliveryMigration->down();
                self::fail('Delivery history rollback accepted');
            } catch (\RuntimeException $e) {
                self::assertSame('Forward-only LINE delivery migration', $e->getMessage());
            }
            try {
                $authenticationMigration->down();
                self::fail('Authentication generation rollback accepted');
            } catch (\RuntimeException $e) {
                self::assertSame('Forward-only LINE bot authentication revision migration', $e->getMessage());
            }
        } finally {
            DB::rollBack(); // Removes only the schema created in this transaction.
        }
    }
}
