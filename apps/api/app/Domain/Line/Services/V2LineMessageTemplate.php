<?php

namespace App\Domain\Line\Services;

use App\Domain\Line\Exceptions\V2LineMessagingException;
use Normalizer;

final class V2LineMessageTemplate
{
    private const ALLOWED_PLACEHOLDER = '{login_url}';

    public function normalize(string $value): string
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if (! is_string($normalized)) {
            throw $this->invalid();
        }
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $normalized));
        $maximum = config('v2_line.messaging.message_max_length');
        if (
            ! is_int($maximum)
            || $maximum < 1
            || $maximum > 5000
            || $normalized === ''
            || mb_strlen($normalized) > $maximum
            || str_contains($normalized, '<')
            || str_contains($normalized, '>')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $normalized) === 1
        ) {
            throw $this->invalid();
        }
        $withoutAllowedPlaceholder = str_replace(
            self::ALLOWED_PLACEHOLDER,
            '',
            $normalized
        );
        if (
            str_contains($withoutAllowedPlaceholder, '{')
            || str_contains($withoutAllowedPlaceholder, '}')
        ) {
            throw $this->invalid();
        }

        return $normalized;
    }

    public function render(string $template): string
    {
        $template = $this->normalize($template);
        $path = config('v2_line.messaging.login_relative_path');
        if (
            ! is_string($path)
            || preg_match('#\A/[A-Za-z0-9/_?&=.-]*\z#', $path) !== 1
            || str_starts_with($path, '//')
        ) {
            throw new V2LineMessagingException(
                'LINE_MESSAGING_UNAVAILABLE',
                503,
                'LINE Messaging configuration is unavailable.',
                true
            );
        }

        return str_replace(self::ALLOWED_PLACEHOLDER, $path, $template);
    }

    private function invalid(): V2LineMessagingException
    {
        return new V2LineMessagingException(
            'LINE_MESSAGING_SETTING_INVALID',
            422,
            'The LINE Messaging setting is invalid.'
        );
    }
}
