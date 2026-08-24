<?php

namespace App\Domain\ContentContact\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;

final class V2ContentHtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'h1', 'h2', 'h3', 'strong', 'em', 'u', 's', 'ul', 'ol', 'li', 'a',
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'br', 'hr', 'img',
    ];

    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'embed', 'object', 'form', 'svg', 'math',
    ];

    public function sanitize(string $html): string
    {
        if (str_contains($html, "\0")) {
            throw new RuntimeException('Content body is invalid.');
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><body>'.$html.'</body>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
            );
            if (! $loaded) {
                throw new RuntimeException('Content body is invalid.');
            }
            $body = $document->getElementsByTagName('body')->item(0);
            if (! $body instanceof DOMElement) {
                throw new RuntimeException('Content body is invalid.');
            }
            $this->cleanChildren($body);
            $result = '';
            foreach ($body->childNodes as $child) {
                $result .= $document->saveHTML($child);
            }

            return trim($result);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function cleanChildren(DOMNode $parent): void
    {
        for ($node = $parent->firstChild; $node !== null;) {
            $next = $node->nextSibling;
            if ($node instanceof DOMElement) {
                $tag = strtolower($node->tagName);
                if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                    $parent->removeChild($node);
                    $node = $next;
                    continue;
                }
                if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    while ($node->firstChild !== null) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                    $node = $next;
                    continue;
                }
                $this->cleanAttributes($node, $tag);
                if ($tag === 'img' && ! $node->hasAttribute('src')) {
                    $parent->removeChild($node);
                    $node = $next;
                    continue;
                }
                $this->cleanChildren($node);
            }
            $node = $next;
        }
    }

    private function cleanAttributes(DOMElement $element, string $tag): void
    {
        $allowed = match ($tag) {
            'a' => ['href', 'title', 'target', 'rel'],
            'img' => ['src', 'alt', 'title'],
            'p', 'h1', 'h2', 'h3' => ['style'],
            'td', 'th' => ['colspan', 'rowspan'],
            default => [],
        };
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            if (
                str_starts_with($name, 'on')
                || ($name === 'style' && ! in_array($tag, ['p', 'h1', 'h2', 'h3'], true))
                || ! in_array($name, $allowed, true)
            ) {
                $element->removeAttributeNode($attribute);
            }
        }
        if ($tag === 'a' && $element->hasAttribute('href')) {
            $href = trim($element->getAttribute('href'));
            if (! $this->safeHref($href)) {
                $element->removeAttribute('href');
            }
            if ($element->getAttribute('target') !== '_blank') {
                $element->removeAttribute('target');
            } else {
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }
        if (in_array($tag, ['p', 'h1', 'h2', 'h3'], true) && $element->hasAttribute('style')) {
            $style = strtolower(trim($element->getAttribute('style')));
            if (! preg_match('/\Atext-align:\s*(left|center|right);?\z/', $style, $matches)) {
                $element->removeAttribute('style');
            } else {
                $element->setAttribute('style', 'text-align: '.$matches[1]);
            }
        }
        if ($tag === 'img' && $element->hasAttribute('src')) {
            $src = trim($element->getAttribute('src'));
            if (! $this->safeImageSource($src)) {
                $element->removeAttribute('src');
            }
        }
        foreach (['colspan', 'rowspan'] as $numeric) {
            if (
                $element->hasAttribute($numeric)
                && ! preg_match('/\A[1-9][0-9]?\z/', $element->getAttribute($numeric))
            ) {
                $element->removeAttribute($numeric);
            }
        }
    }

    private function safeHref(string $href): bool
    {
        if ($href === '' || preg_match('/[\x00-\x20]/', $href)) {
            return false;
        }
        if (str_starts_with($href, '/') || str_starts_with($href, '#')) {
            return ! str_starts_with($href, '//');
        }
        $scheme = parse_url($href, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https', 'mailto'], true);
    }

    private function safeImageSource(string $source): bool
    {
        if ($source === '' || preg_match('/[\x00-\x20]/', $source)) {
            return false;
        }
        $parts = parse_url($source);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }
}
