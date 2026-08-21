<?php

namespace Tests\V2;

use App\Support\V2HmacKeyring;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Tests\TestCase;

final class PreviewRotationCompatibilityTest extends TestCase
{
    public function test_laravel_previous_key_reads_historical_ciphertext_and_new_key_writes(): void
    {
        $oldKey = random_bytes(32);
        $newKey = random_bytes(32);
        $historical = (new Encrypter($oldKey, 'AES-256-CBC'))->encryptString('historical');
        $rotated = (new Encrypter($newKey, 'AES-256-CBC'))->previousKeys([$oldKey]);

        self::assertSame('historical', $rotated->decryptString($historical));
        $current = $rotated->encryptString('current');
        self::assertSame('current', $rotated->decryptString($current));

        $this->expectException(DecryptException::class);
        (new Encrypter($oldKey, 'AES-256-CBC'))->decryptString($current);
    }

    public function test_hmac_keyring_writes_with_active_key_and_reads_previous_candidates(): void
    {
        $old = 'base64:'.base64_encode(random_bytes(32));
        $new = 'base64:'.base64_encode(random_bytes(32));
        config([
            'rotation.active' => $new,
            'rotation.previous' => [$old],
        ]);
        $keyring = app(V2HmacKeyring::class);
        $hashes = $keyring->hashes(
            'rotation.active',
            'rotation.previous',
            'candidate',
            'Test key'
        );

        self::assertCount(2, $hashes);
        self::assertSame(
            $keyring->activeHash('rotation.active', 'candidate', 'Test key'),
            $hashes[0]
        );
        self::assertNotSame($hashes[0], $hashes[1]);
    }
}
