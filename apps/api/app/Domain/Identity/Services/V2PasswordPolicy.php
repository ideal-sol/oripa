<?php

namespace App\Domain\Identity\Services;

use InvalidArgumentException;
use SensitiveParameter;

final class V2PasswordPolicy
{
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 128;
    public const GENERIC_ERROR = 'The credential does not satisfy the security policy.';

    private const ARGON_OPTIONS = [
        'memory_cost' => 65536,
        'time_cost' => 3,
        'threads' => 1,
    ];

    private const COMMON_PASSWORD_HASHES = [
        '5e884898da28047151d0e56f8dc6292773603d0d6aabbdd62a11ef721d1542d8',
        'ef92b778bafe771e89245b89ecbc08a44a4e166c06659911881f383d4473e94f',
        'ef797c8118f02dfb649607dd5d3f8c7623048c9c063d532cc95c5ed7a898a64f',
    ];

    /**
     * @param list<string> $additionalCompromisedHashes
     */
    public function __construct(private readonly array $additionalCompromisedHashes = [])
    {
        foreach ($additionalCompromisedHashes as $hash) {
            if (! preg_match('/\A[0-9a-f]{64}\z/', $hash)) {
                throw new InvalidArgumentException('Compromised password hash list is invalid.');
            }
        }
    }

    public function isAllowed(#[SensitiveParameter] string $password): bool
    {
        $length = mb_strlen($password, 'UTF-8');
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return false;
        }

        $candidate = hash('sha256', mb_strtolower($password, 'UTF-8'));

        return ! in_array(
            $candidate,
            [...self::COMMON_PASSWORD_HASHES, ...$this->additionalCompromisedHashes],
            true
        );
    }

    public function hash(#[SensitiveParameter] string $password): string
    {
        if (! $this->isAllowed($password)) {
            throw new InvalidArgumentException(self::GENERIC_ERROR);
        }

        $hash = password_hash($password, PASSWORD_ARGON2ID, self::ARGON_OPTIONS);
        if (! is_string($hash)) {
            throw new InvalidArgumentException(self::GENERIC_ERROR);
        }

        return $hash;
    }

    public function needsRehash(#[SensitiveParameter] string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::ARGON_OPTIONS);
    }

    public function verify(
        #[SensitiveParameter] string $password,
        #[SensitiveParameter] string $hash
    ): bool {
        return password_verify($password, $hash);
    }
}
