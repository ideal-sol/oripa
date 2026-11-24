<?php

namespace App\Domain\Line\Services;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class V2ConversionEventService
{
    public function pending(int $userId): array
    {
        app(V2LineFriendConversionRecorder::class)->recover($userId);
        return ['data' => DB::table('user_conversion_events')->where('user_id', $userId)
            ->where('suppressed', false)->whereNull('delivered_at')
            ->where('occurred_at', '>=', CarbonImmutable::now('UTC')->subDays(30)->format('Y-m-d H:i:s.uP'))
            ->orderBy('id')->get()->map(fn ($row) => ['id' => $row->public_id, 'event' => $row->event_name,
                'occurred_at' => CarbonImmutable::parse($row->occurred_at)->utc()->format('Y-m-d\TH:i:s.vP')])->all()];
    }

    public function claim(int $userId, string $id): array
    {
        return DB::transaction(function () use ($userId, $id): array {
            $row = $this->locked($userId, $id);
            $now = CarbonImmutable::now('UTC');
            if ($row->delivered_at !== null) $this->fail('CONVERSION_ALREADY_DELIVERED');
            if ($row->suppressed || CarbonImmutable::parse($row->occurred_at)->lt($now->subDays(30))) $this->fail('CONVERSION_NOT_DELIVERABLE');
            if ($row->claim_expires_at !== null && CarbonImmutable::parse($row->claim_expires_at)->gt($now)) $this->fail('CONVERSION_CLAIM_HELD');
            $token = bin2hex(random_bytes(32));
            DB::table('user_conversion_events')->where('id', $row->id)->update([
                'claim_token_hash' => hash('sha256', $token),
                'claim_expires_at' => $now->addSeconds(120)->format('Y-m-d H:i:s.uP'),
                'updated_at' => $now,
            ]);
            return ['data' => ['claim_token' => $token, 'expires_in_seconds' => 120]];
        }, 3);
    }

    public function delivered(int $userId, string $id, #[\SensitiveParameter] string $token): void
    {
        DB::transaction(function () use ($userId, $id, $token): void {
            $row = $this->locked($userId, $id);
            if ($row->delivered_at !== null) return;
            if (! is_string($row->claim_token_hash) || ! hash_equals($row->claim_token_hash, hash('sha256', $token))) $this->fail('CONVERSION_TOKEN_MISMATCH');
            $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');
            DB::table('user_conversion_events')->where('id', $row->id)->update([
                'delivered_at' => $now,
                'updated_at' => $now,
                'claim_token_hash' => null,
                'claim_expires_at' => null,
            ]);
        }, 3);
    }

    private function locked(int $userId, string $id): object
    {
        $row = DB::table('user_conversion_events')->where('user_id', $userId)->where('public_id', $id)->lockForUpdate()->first();
        if ($row === null) throw new V2AuthenticationException('CONVERSION_NOT_FOUND', 404, 'Conversion event not found.');
        return $row;
    }

    private function fail(string $code): never
    {
        throw new V2AuthenticationException($code, 409, 'Conversion event cannot be delivered.');
    }
}
