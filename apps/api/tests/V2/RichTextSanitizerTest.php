<?php

namespace Tests\V2;

use App\Domain\ContentContact\Services\V2ContentHtmlSanitizer;
use App\Domain\Mail\Services\V2TemplateVariableRenderer;
use Tests\TestCase;

final class RichTextSanitizerTest extends TestCase
{
    public function test_shared_rich_text_formatting_and_legacy_markup_are_preserved_safely(): void
    {
        $html = <<<'HTML'
            <h1>Legacy</h1><h2 style="text-align:center">H2</h2><h3 style="text-align: right; color:red">H3</h3>
            <p style="text-align:left" onclick="bad()"><strong>bold</strong><em>italic</em><u>under</u><s>strike</s></p>
            <ul><li>one</li></ul><ol><li>two</li></ol><hr>
            <a href="https://example.test/path" target="_blank">link</a>
            <table><tbody><tr><th colspan="2">legacy table</th></tr></tbody></table>
            HTML;

        $result = app(V2ContentHtmlSanitizer::class)->sanitize($html);

        self::assertStringContainsString('<h1>Legacy</h1>', $result);
        self::assertStringContainsString('<h2 style="text-align: center">H2</h2>', $result);
        self::assertStringContainsString('<strong>bold</strong>', $result);
        self::assertStringContainsString('<u>under</u>', $result);
        self::assertStringContainsString('<s>strike</s>', $result);
        self::assertStringContainsString('<hr>', $result);
        self::assertStringContainsString('<table>', $result);
        self::assertStringContainsString('rel="noopener noreferrer"', $result);
        self::assertStringNotContainsString('onclick', $result);
        self::assertStringNotContainsString('color:red', $result);
    }

    public function test_only_absolute_https_image_sources_survive(): void
    {
        $sanitizer = app(V2ContentHtmlSanitizer::class);

        self::assertSame(
            '<p>before<img src="https://images.example.test/a.png" alt="sample">after</p>',
            $sanitizer->sanitize('<p>before<img src="https://images.example.test/a.png" alt="sample" onerror="bad()">after</p>')
        );
        foreach ([
            'http://example.test/a.png',
            'data:image/png;base64,AAAA',
            'blob:https://example.test/id',
            'javascript:alert(1)',
            'file:///tmp/a.png',
            '//example.test/a.png',
            '/relative.png',
            'https://user:password@example.test/a.png',
        ] as $source) {
            self::assertSame('', $sanitizer->sanitize('<img src="'.$source.'" onload="bad()">'));
        }
    }

    public function test_template_values_are_escaped_unknowns_are_empty_and_lists_use_system_hr(): void
    {
        $renderer = app(V2TemplateVariableRenderer::class);
        $result = $renderer->html(
            '<p>{{user_name}}</p><p>{{gacha_names}}</p><p>{{unknown_variable}}</p>',
            [
                'user_name' => '<img src=x onerror=bad()>',
                'gacha_names' => ['ガチャ<script>bad()</script>A', 'ガチャB'],
            ]
        );

        self::assertStringContainsString('&lt;img src=x onerror=bad()&gt;', $result);
        self::assertStringContainsString('ガチャ&lt;script&gt;bad()&lt;/script&gt;A', $result);
        self::assertStringContainsString('<hr>', $result);
        self::assertStringContainsString('ガチャB', $result);
        self::assertLessThan(strpos($result, '<hr>'), strpos($result, 'ガチャ&lt;script&gt;'));
        self::assertLessThan(strpos($result, 'ガチャB'), strpos($result, '<hr>'));
        self::assertStringNotContainsString('{{unknown_variable}}', $result);
        self::assertStringNotContainsString('<script>', $result);
        self::assertSame(
            'Plan / Premium',
            $renderer->subject('{{purchase_plan}} {{unknown}}', [
                'purchase_plan' => ['Plan', 'Premium'],
            ])
        );
        self::assertSame(
            '<Sample & User> next line',
            $renderer->subject('{{user_name}}', ['user_name' => "<Sample & User>\r\nnext line"])
        );
    }
}
