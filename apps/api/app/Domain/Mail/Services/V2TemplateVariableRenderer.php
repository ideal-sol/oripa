<?php

namespace App\Domain\Mail\Services;

use App\Domain\ContentContact\Services\V2ContentHtmlSanitizer;

final class V2TemplateVariableRenderer
{
    public function __construct(private readonly V2ContentHtmlSanitizer $sanitizer)
    {
    }

    /** @param array<string, string|list<string>|null> $values */
    public function subject(string $template, array $values): string
    {
        return trim($this->replace($template, $values, false));
    }

    /** @param array<string, string|list<string>|null> $values */
    public function html(string $template, array $values): string
    {
        $sanitized = $this->sanitizer->sanitize($template);

        return $this->sanitizer->sanitize($this->replace($sanitized, $values, true));
    }

    /** @param array<string, string|list<string>|null> $values */
    private function replace(string $template, array $values, bool $html): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([^{}]+?)\s*\}\}/u',
            function (array $matches) use ($values, $html): string {
                $key = trim($matches[1]);
                $value = $values[$key] ?? '';
                $items = is_array($value) ? $value : [$value ?? ''];
                $rendered = array_map(
                    static fn (mixed $item): string => $html
                        ? htmlspecialchars(
                            is_string($item) ? $item : '',
                            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                            'UTF-8'
                        )
                        : preg_replace('/[\r\n]+/u', ' ', is_string($item) ? $item : '') ?? '',
                    $items
                );

                return implode($html ? '<hr>' : ' / ', $rendered);
            },
            $template
        );
    }
}
