<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Models\V2\Admin;
use App\Models\V2\AdminRecoveryCode;
use App\Models\V2\AdminSession;
use App\Models\V2\AdminTotpMethod;
use App\Models\V2\AdminWebauthnMethod;
use App\Models\V2\User;
use App\Models\V2\UserEmailChangeRequest;
use App\Models\V2\UserRememberDevice;
use App\Models\V2\UserSession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IdentitySchemaTest extends TestCase
{
    private const TABLES = [
        'users',
        'admins',
        'user_email_verifications',
        'user_email_change_requests',
        'admin_invitations',
        'user_sessions',
        'admin_sessions',
        'user_remember_devices',
        'admin_webauthn_credentials',
        'admin_totp_methods',
        'admin_recovery_codes',
        'admin_authentication_policy',
        'user_phone_numbers',
        'sms_verification_challenges',
    ];

    public function test_identity_tables_and_sensitive_storage_boundaries_exist(): void
    {
        foreach (self::TABLES as $table) {
            self::assertTrue(Schema::hasTable($table), "Missing V2 table: {$table}");
            self::assertFalse(Schema::hasColumn($table, 'tenant_id'));
        }

        self::assertTrue(Schema::hasColumn('users', 'password_hash'));
        self::assertTrue(Schema::hasColumn('users', 'display_name'));
        self::assertTrue(Schema::hasColumn('users', 'state_revision'));
        self::assertFalse(Schema::hasColumn('users', 'password'));
        self::assertFalse(Schema::hasColumn('admins', 'remember_token'));
        self::assertFalse(Schema::hasColumn('admin_sessions', 'payload'));
        self::assertFalse(Schema::hasColumn('user_sessions', 'payload'));
        self::assertTrue(Schema::hasColumn('admin_totp_methods', 'secret_ciphertext'));
        self::assertFalse(Schema::hasColumn('admin_totp_methods', 'secret'));
        self::assertTrue(Schema::hasColumn('admin_recovery_codes', 'code_hash'));
        self::assertFalse(Schema::hasColumn('admin_recovery_codes', 'code'));
        self::assertTrue(Schema::hasColumn('admin_webauthn_credentials', 'public_key'));
        self::assertTrue(Schema::hasColumn('admin_sessions', 'requires_mfa_enrollment'));
        self::assertTrue(Schema::hasColumn('user_email_verifications', 'token_hash'));
        self::assertFalse(Schema::hasColumn('user_email_verifications', 'token'));
        self::assertTrue(Schema::hasColumn('user_email_change_requests', 'token_hash'));
        self::assertTrue(Schema::hasColumn(
            'user_email_change_requests',
            'initiating_session_hash'
        ));
        self::assertFalse(Schema::hasColumn('user_email_change_requests', 'token'));
        self::assertTrue(Schema::hasColumn('admin_invitations', 'token_hash'));
        self::assertFalse(Schema::hasColumn('admin_invitations', 'token'));
        self::assertTrue(Schema::hasColumn('admin_authentication_policy', 'mfa_required'));
        self::assertTrue(Schema::hasColumn('admin_authentication_policy', 'invitation_required'));
        self::assertTrue(Schema::hasColumn('admin_authentication_policy', 'revision'));
        foreach ([
            'delivery_state',
            'provider_identifier',
            'provider_request_id',
            'delivery_error_category',
            'delivery_attempted_at',
            'delivery_accepted_at',
            'delivery_failed_at',
        ] as $column) {
            self::assertTrue(Schema::hasColumn('sms_verification_challenges', $column));
        }
        self::assertTrue(Schema::hasColumn('sms_verification_challenges', 'code_hash'));
        self::assertFalse(Schema::hasColumn('sms_verification_challenges', 'code'));
        self::assertTrue(DB::table('mail_templates')->where('template_key', 'phone_changed')->exists());
    }

    public function test_sms_delivery_migration_rolls_back_and_reapplies_in_isolation(): void
    {
        DB::beginTransaction();

        try {
            $migration = require database_path(
                'migrations-v2/2026_09_27_000071_add_v2_sms_delivery_lifecycle.php'
            );
            $migration->down();
            self::assertFalse(Schema::hasColumn(
                'sms_verification_challenges',
                'delivery_state'
            ));
            self::assertFalse(DB::table('mail_templates')
                ->where('template_key', 'phone_changed')->exists());

            $userId = $this->insertUser('legacy-sms-challenge@example.test', true);
            $challengeId = DB::table('sms_verification_challenges')->insertGetId([
                'public_id' => (string) Str::uuid7(),
                'user_id' => $userId,
                'phone_ciphertext' => 'legacy-encrypted-envelope',
                'phone_hmac' => hash('sha256', 'legacy-phone'),
                'code_hash' => hash('sha256', '123456'),
                'purpose' => 'registration',
                'failed_attempts' => 0,
                'expires_at' => now()->addMinutes(5),
                'sent_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $migration->up();
            self::assertTrue(Schema::hasColumn(
                'sms_verification_challenges',
                'delivery_state'
            ));
            self::assertTrue(DB::table('mail_templates')
                ->where('template_key', 'phone_changed')->exists());
            $legacy = DB::table('sms_verification_challenges')->find($challengeId);
            self::assertSame('failed', $legacy->delivery_state);
            self::assertSame('legacy_delivery_unconfirmed', $legacy->delivery_error_category);
            self::assertNotNull($legacy->revoked_at);
        } finally {
            DB::rollBack();
        }
    }

    public function test_pending_user_email_can_repeat_but_verified_email_is_unique(): void
    {
        DB::beginTransaction();

        try {
            $first = $this->insertUser('same@example.test');
            $second = $this->insertUser('same@example.test');
            DB::table('users')->where('id', $first)->update(['email_verified_at' => now()]);

            try {
                DB::table('users')->where('id', $second)->update(['email_verified_at' => now()]);
                self::fail('A second verified normalized email must be rejected.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_verification_failed_state_constraint_is_additive_and_rollback_fails_closed(): void
    {
        DB::beginTransaction();

        try {
            $userId = $this->insertUser(
                'verification-failed@example.test',
                false,
                V2UserState::VerificationFailed->value
            );
            self::assertSame(
                V2UserState::VerificationFailed->value,
                DB::table('users')->where('id', $userId)->value('state')
            );
            $migration = require database_path(
                'migrations-v2/2026_09_22_000066_add_v2_verification_failed_user_state.php'
            );
            try {
                $migration->down();
                self::fail('Rollback must fail closed while verification-failure history exists.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('verification_failed', $exception->getMessage());
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_only_closed_verified_user_email_is_reusable(): void
    {
        DB::beginTransaction();

        try {
            $closed = $this->insertUser('closed-reuse@example.test', true, 'closed');
            $replacement = $this->insertUser('closed-reuse@example.test');
            DB::table('users')->where('id', $replacement)->update([
                'email_verified_at' => now(),
                'state' => 'active',
            ]);

            self::assertSame('closed', DB::table('users')->where('id', $closed)->value('state'));
            self::assertSame('active', DB::table('users')->where('id', $replacement)->value('state'));

            $migration = require database_path(
                'migrations-v2/2026_09_17_000063_allow_v2_closed_user_email_reregistration.php'
            );
            try {
                DB::transaction(fn () => $migration->down());
                self::fail('Rollback must fail closed after a closed email has been reused.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
            $indexDefinition = DB::table('pg_indexes')
                ->where('schemaname', 'public')
                ->where('indexname', 'users_verified_email_unique')
                ->value('indexdef');
            self::assertIsString($indexDefinition);
            self::assertStringContainsString("'closed'", $indexDefinition);

            try {
                DB::table('users')->where('id', $closed)->update(['state' => 'active']);
                self::fail('A closed identity cannot reclaim an email owned by its replacement.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_user_and_admin_can_use_same_email_but_admin_email_is_unique(): void
    {
        DB::beginTransaction();

        try {
            $this->insertUser('realm@example.test', true);
            $this->insertAdmin('realm@example.test');
            self::assertSame(1, DB::table('users')->where('email_normalized', 'realm@example.test')->count());
            self::assertSame(1, DB::table('admins')->where('email_normalized', 'realm@example.test')->count());

            try {
                $this->insertAdmin('realm@example.test');
                self::fail('Admin normalized email must be unique inside the admin realm.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_invalid_account_states_and_custom_admin_roles_are_rejected(): void
    {
        foreach (
            [
                fn () => $this->insertUser('bad-user@example.test', false, 'unknown'),
                fn () => $this->insertAdmin('bad-role@example.test', 'custom', 'active'),
                fn () => $this->insertAdmin('bad-state@example.test', 'operator', 'unknown'),
            ] as $operation
        ) {
            DB::beginTransaction();
            try {
                try {
                    $operation();
                    self::fail('Invalid account enum value must be rejected.');
                } catch (QueryException) {
                    self::assertTrue(true);
                }
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_mfa_storage_rejects_plain_totp_and_plain_recovery_codes(): void
    {
        DB::beginTransaction();

        try {
            $adminId = $this->insertAdmin('mfa@example.test');

            try {
                DB::table('admin_totp_methods')->insert([
                    'admin_id' => $adminId,
                    'secret_ciphertext' => 'otpauth://plain-value',
                    'encryption_key_version' => 'test-key-v1',
                ]);
                self::fail('A plaintext TOTP URI must be rejected.');
            } catch (QueryException) {
                DB::rollBack();
                DB::beginTransaction();
                $adminId = $this->insertAdmin('mfa2@example.test');
                self::assertTrue(true);
            }

            try {
                DB::table('admin_recovery_codes')->insert([
                    'admin_id' => $adminId,
                    'code_hash' => 'plain-code',
                ]);
                self::fail('A plaintext recovery code must be rejected.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_models_use_mass_assignment_allowlists_and_hide_sensitive_values(): void
    {
        $models = [
            new User(),
            new UserEmailChangeRequest(),
            new Admin(),
            new UserSession(),
            new AdminSession(),
            new UserRememberDevice(),
            new AdminWebauthnMethod(),
            new AdminTotpMethod(),
            new AdminRecoveryCode(),
        ];

        foreach ($models as $model) {
            self::assertNotEmpty($model->getFillable());
            self::assertNotContains('password', $model->getFillable());
            self::assertNotContains('remember_token', $model->getFillable());
        }

        self::assertContains('password_hash', (new User())->getHidden());
        self::assertContains('token_hash', (new UserEmailChangeRequest())->getHidden());
        self::assertContains(
            'initiating_session_hash',
            (new UserEmailChangeRequest())->getHidden()
        );
        self::assertContains('display_name', (new User())->getFillable());
        self::assertContains('state_revision', (new User())->getFillable());
        self::assertContains('password_hash', (new Admin())->getHidden());
        self::assertContains('session_id_hash', (new UserSession())->getHidden());
        self::assertContains('session_id_hash', (new AdminSession())->getHidden());
        self::assertContains('secret_ciphertext', (new AdminTotpMethod())->getHidden());
        self::assertContains('code_hash', (new AdminRecoveryCode())->getHidden());
    }

    public function test_user_display_name_is_nullable_and_preserves_existing_users(): void
    {
        DB::beginTransaction();
        try {
            $withoutName = $this->insertUser('display-name-empty@example.test');
            $withName = $this->insertUser('display-name-set@example.test');
            DB::table('users')->where('id', $withName)->update([
                'display_name' => 'テストユーザー',
            ]);

            self::assertNull(DB::table('users')->where('id', $withoutName)->value('display_name'));
            self::assertSame(
                'テストユーザー',
                DB::table('users')->where('id', $withName)->value('display_name')
            );
        } finally {
            DB::rollBack();
        }
    }

    public function test_user_state_revision_defaults_to_one_and_rejects_non_positive_values(): void
    {
        DB::beginTransaction();
        try {
            $userId = $this->insertUser('state-revision@example.test', true, 'active');
            self::assertSame(1, DB::table('users')->where('id', $userId)->value('state_revision'));

            try {
                DB::table('users')->where('id', $userId)->update(['state_revision' => 0]);
                self::fail('A non-positive User state revision must be rejected.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        } finally {
            DB::rollBack();
        }
    }

    private function insertUser(
        string $email,
        bool $verified = false,
        string $state = 'pending_verification'
    ): int {
        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'email_display' => $email,
            'email_normalized' => mb_strtolower($email),
            'email_verified_at' => $verified ? now() : null,
            'password_hash' => (new V2PasswordPolicy())->hash('valid user password'),
            'state' => $state,
        ]);
    }

    private function insertAdmin(
        string $email,
        string $role = 'operator',
        string $state = 'active'
    ): int {
        return (int) DB::table('admins')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'email_display' => $email,
            'email_normalized' => mb_strtolower($email),
            'email_verified_at' => now(),
            'password_hash' => (new V2PasswordPolicy())->hash('valid admin password'),
            'role' => $role,
            'state' => $state,
        ]);
    }
}
