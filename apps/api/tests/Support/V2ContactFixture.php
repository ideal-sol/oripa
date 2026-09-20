<?php

namespace Tests\Support;

use App\Domain\ContentContact\Services\V2ContactService;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Models\V2\User;
use Illuminate\Support\Str;

trait V2ContactFixture
{
    protected function contactUser(): User
    {
        $email = 'contact-'.Str::uuid7().'@example.test';
        return User::query()->create([
            'display_name' => 'Contact Fixture',
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => 'active',
        ]);
    }

    protected function submitContact(array $input, ?User $user, string $ip, string $requestId): array
    {
        return app(V2ContactService::class)->submit($input, $user ?? $this->contactUser(), $ip, $requestId, (string) Str::uuid7());
    }
}
