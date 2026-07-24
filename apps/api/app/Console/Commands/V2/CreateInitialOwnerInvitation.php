<?php

namespace App\Console\Commands\V2;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Services\V2EmailNormalizer;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SecureToken;
use App\Models\V2\Admin;
use App\Models\V2\AdminInvitation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class CreateInitialOwnerInvitation extends Command
{
    protected $signature = 'v2:identity:create-owner-invitation {email}';
    protected $description = 'Create the one-time initial V2 owner invitation.';

    public function handle(
        V2EmailNormalizer $emails,
        V2PasswordPolicy $passwords,
        V2SecureToken $tokens
    ): int {
        if (Admin::query()->where('role', V2AdminRole::Owner->value)->exists()) {
            $this->error('A V2 owner already exists. No invitation was created.');

            return self::FAILURE;
        }
        $email = (string) $this->argument('email');
        $normalized = $emails->normalize($email);
        $token = $tokens->generate();

        DB::transaction(function () use ($email, $normalized, $token, $tokens, $passwords): void {
            $admin = Admin::query()->create([
                'email_display' => trim($email),
                'email_normalized' => $normalized,
                'password_hash' => $passwords->hash(bin2hex(random_bytes(16))),
                'role' => V2AdminRole::Owner,
                'state' => V2AdminState::Invited,
            ]);
            AdminInvitation::query()->create([
                'admin_id' => $admin->getKey(),
                'token_hash' => $tokens->hash($token),
                'expires_at' => now()->addMinutes(30),
            ]);
        });

        $this->warn('Store this token securely. It is displayed once and expires in 30 minutes.');
        $this->line($token);

        return self::SUCCESS;
    }
}
