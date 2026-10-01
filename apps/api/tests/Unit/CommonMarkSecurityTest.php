<?php

namespace Tests\Unit;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommonMarkSecurityTest extends TestCase
{
    #[DataProvider('bareDisallowedTags')]
    public function test_disallowed_html_tag_at_end_of_block_is_escaped(string $tag): void
    {
        $html = (string) (new GithubFlavoredMarkdownConverter)->convert($tag);

        self::assertStringNotContainsString($tag, $html);
        self::assertStringContainsString('&lt;'.substr($tag, 1), $html);
    }

    public static function bareDisallowedTags(): array
    {
        return [
            'script' => ['<script'],
            'mixed_case_script' => ['<ScRiPt'],
            'style' => ['<style'],
        ];
    }

    public function test_allowed_html_and_regular_markdown_remain_renderable(): void
    {
        $converter = new GithubFlavoredMarkdownConverter;

        self::assertSame("<p><strong>safe</strong> <em>text</em></p>\n", (string) $converter->convert('**safe** <em>text</em>'));
        self::assertSame("<p>inline &lt;script>ignored&lt;/script></p>\n", (string) $converter->convert('inline <script>ignored</script>'));
    }

    public function test_table_extension_preserves_plain_paragraph_and_unicode_table_cells(): void
    {
        $converter = new GithubFlavoredMarkdownConverter;
        $paragraph = implode("\n", array_fill(0, 1000, 'plain text without delimiter'));

        self::assertSame('<p>'.$paragraph."</p>\n", (string) $converter->convert($paragraph));
        $table = (string) $converter->convert("景品 | ポイント\n:--- | ---:\nテスト | 10\n");
        self::assertStringContainsString('<th align="left">景品</th>', $table);
        self::assertStringContainsString('<th align="right">ポイント</th>', $table);
        self::assertStringContainsString('<td align="left">テスト</td>', $table);
        self::assertStringContainsString('<td align="right">10</td>', $table);
    }
}
