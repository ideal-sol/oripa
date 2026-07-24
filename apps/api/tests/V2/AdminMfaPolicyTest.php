<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Services\V2MfaPolicy;
use PHPUnit\Framework\TestCase;

final class AdminMfaPolicyTest extends TestCase
{
    public function test_owner_requires_two_authenticators_and_one_webauthn(): void
    {
        $policy = new V2MfaPolicy();

        self::assertFalse($policy->allowsAccess(V2AdminRole::Owner, 0, 0));
        self::assertFalse($policy->allowsAccess(V2AdminRole::Owner, 1, 0));
        self::assertFalse($policy->allowsAccess(V2AdminRole::Owner, 0, 2));
        self::assertTrue($policy->allowsAccess(V2AdminRole::Owner, 1, 1));
        self::assertTrue($policy->allowsAccess(V2AdminRole::Owner, 2, 0));
    }

    public function test_admin_and_operator_require_one_supported_authenticator(): void
    {
        $policy = new V2MfaPolicy();

        foreach ([V2AdminRole::Admin, V2AdminRole::Operator] as $role) {
            self::assertFalse($policy->allowsAccess($role, 0, 0));
            self::assertTrue($policy->allowsAccess($role, 1, 0));
            self::assertTrue($policy->allowsAccess($role, 0, 1));
        }
    }

    public function test_recovery_codes_are_not_counted_as_authenticators(): void
    {
        $policy = new V2MfaPolicy();

        self::assertFalse($policy->allowsAccess(V2AdminRole::Owner, 0, 0));
        self::assertFalse($policy->allowsAccess(V2AdminRole::Admin, 0, 0));
    }

    public function test_negative_counts_fail_closed(): void
    {
        $policy = new V2MfaPolicy();

        self::assertFalse($policy->allowsAccess(V2AdminRole::Owner, -1, 3));
        self::assertFalse($policy->allowsAccess(V2AdminRole::Operator, 1, -1));
    }
}
