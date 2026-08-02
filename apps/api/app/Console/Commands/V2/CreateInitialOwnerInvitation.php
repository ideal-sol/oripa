<?php

namespace App\Console\Commands\V2;

use App\Domain\Identity\Contracts\V2SecurityEventSink;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Services\V2EmailNormalizer;
use App\Domain\Identity\Services\V2AdminAuthenticationPolicyService;
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
        V2SecureToken $tokens,
        V2AdminAuthenticationPolicyService $authenticationPolicy,
        V2SecurityEventSink $events
    ): int {
        if (Admin::query()->where('role', V2AdminRole::Owner->value)->exists()) {
            $this->error('A V2 owner already exists. No invitation was created.');

            return self::FAILURE;
        }
        $email = (string) $this->argument('email');
        $normalized = $emails->normalize($email);
        $invitationRequired = $authenticationPolicy->invitationRequired();
        $oneTimeCredential = $tokens->generate();

        DB::transaction(function () use (
            $email,
            $normalized,
            $oneTimeCredential,
            $tokens,
            $passwords,
            $invitationRequired,
            $events
        ): void {
            $admin = Admin::query()->create([
                'email_display' => trim($email),
                'email_normalized' => $normalized,
                'email_verified_at' => $invitationRequired ? null : now()->startOfSecond(),
                'password_hash' => $passwords->hash(
                    $invitationRequired ? bin2hex(random_bytes(32)) : $oneTimeCredential
                ),
                'role' => V2AdminRole::Owner,
                'state' => $invitationRequired
                    ? V2AdminState::Invited
                    : V2AdminState::Active,
            ]);
            if ($invitationRequired) {
                $invitationCreatedAt = now()->startOfSecond();
                AdminInvitation::query()->create([
                    'admin_id' => $admin->getKey(),
                    'token_hash' => $tokens->hash($oneTimeCredential),
                    'expires_at' => $invitationCreatedAt->copy()->addMinutes(30),
                    'created_at' => $invitationCreatedAt,
                ]);
            }
            $events->record('admin_invitation', [
                'realm' => 'admin',
                'subject_id' => $admin->public_id,
                'role' => V2AdminRole::Owner->value,
                'mode' => $invitationRequired ? 'invitation' : 'temporary_password',
            ]);
        });

        $this->warn($invitationRequired
            ? 'Store this invitation token securely. It is displayed once and expires in 30 minutes.'
            : 'Store this temporary password securely. It is displayed once.');
        $this->line($oneTimeCredential);

        return self::SUCCESS;
    }
}
