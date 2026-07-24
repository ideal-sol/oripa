<?php

namespace App\Domain\Outbox\Services;

use App\Domain\Identity\Contracts\V2EmailVerificationNotifier;
use App\Models\V2\User;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use SensitiveParameter;

final class V2OutboxEmailVerificationNotifier implements V2EmailVerificationNotifier
{
    public function __construct(private readonly V2OutboxService $outbox)
    {
    }

    /**
     * @throws JsonException
     */
    public function send(
        User $user,
        #[SensitiveParameter] string $token,
        string $redirectPath,
        string $deduplicationKey
    ): void {
        $encrypted = Crypt::encryptString(json_encode([
            'recipient' => $user->email_display,
            'user_public_id' => $user->public_id,
            'verification_token' => $token,
            'redirect_path' => $redirectPath,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $this->outbox->enqueue(
            'identity.email-verification',
            'user',
            $user->public_id,
            'identity.email_verification.requested',
            [
                'message_ciphertext' => $encrypted,
                'encryption_format' => 'laravel-v1',
            ],
            $deduplicationKey
        );
    }
}
