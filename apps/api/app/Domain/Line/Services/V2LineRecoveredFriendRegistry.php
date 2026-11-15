<?php

namespace App\Domain\Line\Services;

use App\Domain\Line\Exceptions\V2LineMessagingException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

/** D7 internal ingestion boundary; no route, command, transport or backfill job. */
final class V2LineRecoveredFriendRegistry
{
    public function record(int $botId, int $authenticationRevision, #[SensitiveParameter] string $subject, int $observedAtMs): void
    {
        if ($subject === '' || strlen($subject) > 191 || $observedAtMs < 0 || $observedAtMs > now()->getTimestampMs()) {
            throw new V2LineMessagingException('LINE_RECOVERY_INVALID', 422, 'Invalid recovery observation.');
        }
        try {
            DB::transaction(function () use ($botId, $authenticationRevision, $subject, $observedAtMs): void {
                app(V2LineMessagingSettingService::class)->setting(true);
                $bot = DB::table('line_bots')->where('id', $botId)->where('is_active', true)
                    ->where('authentication_revision', $authenticationRevision)->first();
                if ($bot === null) throw new \RuntimeException('stale_bot');
                $vault = app(V2LineSubjectVault::class);
                $hash = $vault->canonicalHash($subject);
                $row = DB::table('line_recovered_friends')->where('line_bot_id', $botId)
                    ->whereIn('subject_hash', $vault->hashes($subject))->lockForUpdate()->first();
                if ($row !== null) {
                    $vault->store('line_recovered_friends', $row, $subject);
                    DB::table('line_recovered_friends')->where('id', $row->id)
                        ->where('observed_at_ms', '<', $observedAtMs)->update(['observed_at_ms' => $observedAtMs]);
                    return;
                }
                DB::table('line_recovered_friends')->insert([
                    'line_bot_id' => $botId, 'subject_hash' => $hash,
                    'subject_ciphertext' => Crypt::encryptString($subject), 'observed_at_ms' => $observedAtMs,
                ]);
            }, 3);
        } catch (Throwable) {
            // Never propagate SQL bindings/ciphertext/provider input in an exception.
            throw new V2LineMessagingException('LINE_RECOVERY_REJECTED', 503, 'Recovery observation unavailable.', true);
        }
    }
}
