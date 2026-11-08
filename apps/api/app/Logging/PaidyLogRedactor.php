<?php

namespace App\Logging;

final class PaidyLogRedactor
{
    public static function redact(string $text, ?string $configuredToken = null, ?string $requestUri = null): string
    {
        // Redact the current path token even when authentication rejects it.
        $tokens = [$configuredToken];
        if (preg_match('~/webhooks/v2/paidy/([^/?#\s]+)~', rawurldecode($requestUri ?? ''), $match)) {
            $tokens[] = $match[1];
        }
        foreach ($tokens as $token) {
            if (is_string($token) && $token !== '') {
                // PHP exception traces may truncate string arguments before the
                // formatter sees them. Protect those prefixes too.
                for ($length = min(256, strlen($token) - 1); $length > 0; $length--) {
                    $text = str_replace(substr($token, 0, $length).'...', 'REDACTED', $text);
                }
                $text = str_replace([$token, rawurlencode($token)], 'REDACTED', $text);
            }
        }
        // Also cover historical URLs in messages, nested JSON and exception traces.
        $paths = ["/webhooks/v2/paidy/", str_replace("/", "\\/", "/webhooks/v2/paidy/")];
        $prefix = implode("|", array_map(fn ($path) => preg_quote($path, "~"), $paths));
        return preg_replace("~(".$prefix.")[^\\s?\\#\"'<>\\\\]+~", "$1REDACTED", $text) ?? "[Paidy log redaction failed]";
    }
}
