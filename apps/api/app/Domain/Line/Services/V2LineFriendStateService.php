<?php

namespace App\Domain\Line\Services;

use Illuminate\Support\Facades\DB;

final class V2LineFriendStateService
{
    /** @return array{linked: bool, friend_confirmed: bool, is_line_user: bool} */
    public function forUser(int $userId): array
    {
        $linked = DB::table('external_identity_accounts')
            ->where('user_id', $userId)
            ->where('provider', 'line')
            ->where('issuer', 'https://access.line.me')
            ->whereNull('revoked_at')
            ->exists();
        $isLineUser = $linked && $this->isLineUser($userId);

        return [
            'linked' => $linked,
            'friend_confirmed' => $isLineUser,
            'is_line_user' => $isLineUser,
        ];
    }

    public function isLineUser(int $userId): bool
    {
        return DB::table('external_identity_accounts as identity')
            ->join('line_friendships as friendship', function ($join): void {
                $join->on('friendship.user_id', '=', 'identity.user_id')
                    ->on('friendship.subject_hash', '=', 'identity.subject_hash');
            })
            ->where('identity.user_id', $userId)
            ->where('identity.provider', 'line')
            ->where('identity.issuer', 'https://access.line.me')
            ->whereNull('identity.revoked_at')
            ->where('friendship.status', 'friend')
            ->whereNull('friendship.unfollowed_at')
            ->exists();
    }
}
