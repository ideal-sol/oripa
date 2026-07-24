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
        'admin_invitations',
        'user_sessions',
        'admin_sessions',
        'user_remember_devices',
        'admin_webauthn_credentials',
        'admin_totp_methods',
        'admin_recovery_codes',
    ];

    public function test_identity_tables_and_sensitive_storage_boundaries_exist(): void
    {
        foreach (self::TABLES as $table) {
            self::assertTrue(Schema::hasTable($table), "Missing V2 table: {$table}");
            self::assertFalse(Schema::hasColumn($table, 'tenant_id'));
        }

        self::assertTrue(Schema::hasColumn('users', 'password_hash'));
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
        self::assertTrue(Schema::hasColumn('admin_invitations', 'token_hash'));
        self::assertFalse(Schema::hasColumn('admin_invitations', 'token'));
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
        self::assertContains('password_hash', (new Admin())->getHidden());
        self::assertContains('session_id_hash', (new UserSession())->getHidden());
        self::assertContains('session_id_hash', (new AdminSession())->getHidden());
        self::assertContains('secret_ciphertext', (new AdminTotpMethod())->getHidden());
        self::assertContains('code_hash', (new AdminRecoveryCode())->getHidden());
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
