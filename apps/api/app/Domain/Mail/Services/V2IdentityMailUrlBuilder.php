<?php

namespace App\Domain\Mail\Services;

use RuntimeException;
use SensitiveParameter;

final class V2IdentityMailUrlBuilder
{
    public function passwordReset(
        string $userPublicId,
        #[SensitiveParameter] string $token,
        string $redirectPath
    ): string {
        return $this->redirectUrl($redirectPath, [
            'password_reset_user_id' => $userPublicId,
            'token' => $token,
        ]);
    }

    public function emailChange(
        string $requestPublicId,
        #[SensitiveParameter] string $token,
        string $redirectPath
    ): string {
        return $this->redirectUrl($redirectPath, [
            'email_change_request_id' => $requestPublicId,
            'token' => $token,
        ]);
    }

    /** @param array<string, string> $query */
    private function redirectUrl(string $path, #[SensitiveParameter] array $query): string
    {
        if (
            ! str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || parse_url($path, PHP_URL_HOST) !== null
            || parse_url($path, PHP_URL_QUERY) !== null
            || parse_url($path, PHP_URL_FRAGMENT) !== null
        ) {
            throw new RuntimeException('Identity Mail redirect is invalid.');
        }

        return $this->publicOrigin().$path.'?'.http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    private function publicOrigin(): string
    {
        $origin = rtrim((string) config('v2_identity.origins.user'), '/');
        $parts = parse_url($origin);
        if (
            ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || ($parts['path'] ?? '') !== ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new RuntimeException('The canonical Public Origin is invalid.');
        }

        return $origin;
    }
}
