<?php

namespace App\Domain\Line\Services;

use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Optional CV evidence; never changes friendship, identity or reward decisions. */
final class V2LineFriendConversionRecorder
{
    /**
     * Call in the transaction holding LINE settings row 1, before identity mutation.
     * The settings lock also orders callback, observation and webhook writers when
     * there is no identity or friendship row yet.
     *
     * @return array{is_line_user: bool, has_history: bool}|null
     */
    public function before(int $userId): ?array
    {
        if ($this->startedAt() === null) return null;
        try {
            return DB::transaction(fn () => [
                'is_line_user' => app(V2LineFriendStateService::class)->isLineUser($userId),
                // Include every BOT and superseded history.
                'has_history' => DB::table('line_friendships')->where('user_id', $userId)->exists(),
            ]);
        } catch (Throwable) {
            Log::warning('line_conversion_snapshot_unavailable');
            return null;
        }
    }

    public function recordTransition(int $userId, ?array $before, ?CarbonImmutable $completedAt, bool $backfill = false): void
    {
        if ($before === null || $before['is_line_user']) return;
        $start = $this->startedAt();
        if ($start === null) return;
        try {
            DB::transaction(function () use ($userId, $before, $completedAt, $backfill, $start): void {
                if (! app(V2LineFriendStateService::class)->isLineUser($userId)) return;
                $now = CarbonImmutable::now();
                $reason = match (true) {
                    $before['has_history'] => 'undeterminable',
                    $backfill => 'backfill',
                    $completedAt === null => 'undeterminable',
                    $completedAt->lt($start), $now->lt($start) => 'before_start',
                    default => null,
                };
                $this->insert($userId, $completedAt ?? $now, 'transition', $reason);
            });
        } catch (Throwable) {
            Log::warning('line_conversion_record_unavailable');
        }
    }

    public function recover(int $userId): void
    {
        if ($this->startedAt() === null) return;
        try {
            DB::transaction(function () use ($userId): void {
                app(V2LineMessagingSettingService::class)->setting(true);
                if (DB::table('user_conversion_events')->where('user_id', $userId)->exists()) return;
                if (app(V2LineFriendStateService::class)->isLineUser($userId)) {
                    $this->insert($userId, CarbonImmutable::now(), 'recovered', 'undeterminable');
                }
            }, 3);
        } catch (Throwable) {
            Log::warning('line_conversion_recovery_unavailable');
        }
    }

    private function startedAt(): ?CarbonImmutable
    {
        $value = config('v2_line.conversion_started_at');
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)\z/', $value)) return null;
        try {
            $date = CarbonImmutable::parse($value);
            return $date->format('Y-m-d\TH:i:s') === substr($value, 0, 19) ? $date : null;
        } catch (Throwable) { return null; }
    }

    private function insert(int $userId, CarbonImmutable $occurredAt, string $source, ?string $reason): void
    {
        DB::table('user_conversion_events')->insertOrIgnore([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'event_name' => 'line_friend_added',
            'occurred_at' => $occurredAt->utc()->format('Y-m-d H:i:s.vP'),
            'source' => $source,
            'suppressed' => $reason !== null,
            'suppressed_reason' => $reason,
            'created_at' => V2DatabaseTimestamp::format(now()),
            'updated_at' => V2DatabaseTimestamp::format(now()),
        ]);
    }
}
