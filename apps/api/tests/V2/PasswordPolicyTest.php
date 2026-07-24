<?php

namespace Tests\V2;

use App\Domain\Identity\Services\V2PasswordPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    private V2PasswordPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new V2PasswordPolicy();
    }

    public function test_length_unicode_space_and_composition_policy(): void
    {
        self::assertFalse($this->policy->isAllowed('1234567'));
        self::assertTrue($this->policy->isAllowed('abcdefgh'));
        self::assertTrue($this->policy->isAllowed(str_repeat('a', 128)));
        self::assertFalse($this->policy->isAllowed(str_repeat('a', 129)));
        self::assertTrue($this->policy->isAllowed('日本語 password'));
        self::assertTrue($this->policy->isAllowed('onlylowercase'));
    }

    public function test_common_and_compromised_passwords_are_rejected(): void
    {
        self::assertFalse($this->policy->isAllowed('password'));
        self::assertFalse($this->policy->isAllowed('Password123'));

        $additional = hash('sha256', 'known breach value');
        $policy = new V2PasswordPolicy([$additional]);
        self::assertFalse($policy->isAllowed('known breach value'));
    }

    public function test_invalid_hash_list_is_rejected_without_echoing_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Compromised password hash list is invalid.');

        new V2PasswordPolicy(['not-a-sha256']);
    }

    public function test_argon2id_hash_verify_and_rehash_boundary(): void
    {
        $password = 'valid password 値';
        $hash = $this->policy->hash($password);

        self::assertSame('argon2id', password_get_info($hash)['algoName']);
        self::assertTrue($this->policy->verify($password, $hash));
        self::assertFalse($this->policy->verify('different password', $hash));
        self::assertFalse($this->policy->needsRehash($hash));

        $weaker = password_hash(
            $password,
            PASSWORD_ARGON2ID,
            ['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]
        );
        self::assertIsString($weaker);
        self::assertTrue($this->policy->needsRehash($weaker));
    }

    public function test_rejected_password_uses_generic_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(V2PasswordPolicy::GENERIC_ERROR);

        $this->policy->hash('short');
    }
}
