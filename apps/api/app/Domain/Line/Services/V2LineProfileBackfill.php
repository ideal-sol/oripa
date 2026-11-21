<?php

namespace App\Domain\Line\Services;

use App\Models\V2\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2LineProfileBackfill
{
    /** Trusted manual operation for exactly one member. Never scheduled or invoked by a read page. */
    public function execute(string $member, string $actor): string
    {
        if (! Str::isUuid($member) || ! Str::isUuid($actor) || DB::transactionLevel() !== 0) throw new \InvalidArgumentException('Invalid profile operation.');
        $snapshot = DB::transaction(function () use ($member): ?array {
            app(V2LineMessagingSettingService::class)->setting(true);
            $bot = DB::table('line_bots')->where('is_active', true)->where('connection_status', 'succeeded')->first();
            $user = User::query()->where('public_id', $member)->lockForUpdate()->first();
            if ($bot === null || $user === null || V2LineProfileName::normalize($user->display_name) !== null) return null;
            $subject = app(V2LineSubjectVault::class)->rawSubjectFor($user);
            return $subject === null ? null : [$bot, $user->id, $subject];
        }, 1);
        if ($snapshot === null) return 'unchanged';
        [$bot, $id, $subject] = $snapshot;
        $name = app(V2LineBotTransport::class)->profileName($bot, $subject);
        if ($name === null) return 'unavailable';
        return DB::transaction(function () use ($bot, $id, $subject, $name, $actor): string {
            app(V2LineMessagingSettingService::class)->setting(true);
            $current = DB::table('line_bots')->where('id', $bot->id)->where('is_active', true)->where('connection_status', 'succeeded')->first();
            $user = User::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($current?->authentication_revision !== $bot->authentication_revision
                || app(V2LineSubjectVault::class)->rawSubjectFor($user) !== $subject) return 'unavailable';
            if (V2LineProfileName::normalize($user->display_name) !== null) return 'unchanged';
            $user->forceFill(['display_name'=>$name])->save();
            app(\App\Domain\Audit\V2\Services\V2AuditLogService::class)->record('line.profile.complemented', [
                'request_id'=>(string) Str::uuid(), 'actor_type'=>'system', 'auth_realm'=>'system',
                'target_type'=>'user', 'target_public_id'=>$user->public_id,
                'metadata'=>['operator_id'=>$actor], 'outcome'=>'success']);
            return 'updated';
        }, 1);
    }
}
