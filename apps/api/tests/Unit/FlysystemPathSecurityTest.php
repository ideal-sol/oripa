<?php

namespace Tests\Unit;

use League\Flysystem\CorruptedPathDetected;
use League\Flysystem\PathTraversalDetected;
use League\Flysystem\WhitespacePathNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FlysystemPathSecurityTest extends TestCase
{
    #[DataProvider('corruptedPaths')]
    public function test_corrupted_paths_are_rejected_before_adapter_access(string $path): void
    {
        $this->expectException(CorruptedPathDetected::class);

        (new WhitespacePathNormalizer)->normalizePath($path);
    }

    public static function corruptedPaths(): array
    {
        return [
            'malformed_utf8' => ["prizes/image\x80.png"],
            'malformed_utf8_with_control_character' => ["prizes/image\x80\x1b.png"],
            'invalid_surrogate_encoding' => ["prizes/image\xed\xa0\x80.png"],
            'valid_utf8_with_control_character' => ["prizes/image\x1b.png"],
        ];
    }

    public function test_valid_ascii_and_unicode_storage_paths_keep_their_normalization(): void
    {
        $normalizer = new WhitespacePathNormalizer;

        self::assertSame('prizes/asset.png', $normalizer->normalizePath('prizes\\asset.png'));
        self::assertSame('prizes/景品画像.png', $normalizer->normalizePath('prizes//./景品画像.png'));
        self::assertSame('prizes/image.png', $normalizer->normalizePath('prizes/tmp/../image.png'));
    }

    public function test_traversal_outside_the_storage_root_remains_rejected(): void
    {
        $this->expectException(PathTraversalDetected::class);

        (new WhitespacePathNormalizer)->normalizePath('../outside.png');
    }
}
