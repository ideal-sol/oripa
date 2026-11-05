<?php

namespace App\Domain\Line\Services;

use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2IdentityCorrelation;
use App\Domain\Line\Exceptions\V2LineMessagingException;
use App\Models\V2\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final class V2LineSubjectVault
{
    private const TABLES = ['external_identity_accounts', 'line_friendships', 'line_pending_follows'];

    public function __construct(private readonly V2IdentityCorrelation $correlation) {}

    /** @return list<string> */
    public function hashes(#[SensitiveParameter] string $subject): array
    {
        return $this->correlation->hashes('line|https://access.line.me|'.$subject);
    }

    public function backfill(#[SensitiveParameter] string $subject): void
    {
        DB::transaction(function () use ($subject): void {
            foreach (self::TABLES as $table) {
                $query = DB::table($table)->whereIn('subject_hash', $this->hashes($subject));
                if ($table === 'external_identity_accounts') {
                    $query->where('provider', 'line')->where('issuer', 'https://access.line.me');
                }
                foreach ($query->orderBy('id')->lockForUpdate()->get() as $row) {
                    $this->store($table, $row, $subject);
                }
            }
        }, 3);
    }

    public function canonicalHash(#[SensitiveParameter] string $subject): string
    {
        $hashes = $this->hashes($subject);
        foreach (self::TABLES as $table) {
            $query = DB::table($table)->whereIn('subject_hash', $hashes);
            if ($table === 'external_identity_accounts') $query->where('provider', 'line')->where('issuer', 'https://access.line.me')->whereNull('superseded_at');
            if ($table === 'line_friendships') $query->whereNull('superseded_at');
            $hash = $query->value('subject_hash');
            if (is_string($hash)) return $hash;
        }
        return $hashes[0];
    }

    public function store(string $table, #[SensitiveParameter] object $row, #[SensitiveParameter] string $subject): void
    {
        if (! in_array($table, self::TABLES, true) || DB::transactionLevel() < 1
            || ! in_array($row->subject_hash, $this->hashes($subject), true)
            || ($table === 'external_identity_accounts' && ($row->provider !== 'line' || $row->issuer !== 'https://access.line.me'))) {
            throw new V2LineMessagingException('LINE_SUBJECT_BINDING_REJECTED', 503, 'LINE_SUBJECT_BINDING_REJECTED', true);
        }
        if ($row->subject_ciphertext !== null) {
            if (! hash_equals($this->decrypt($row->subject_ciphertext), $subject)) {
                throw new V2LineMessagingException('LINE_SUBJECT_BINDING_REJECTED', 503, 'LINE_SUBJECT_BINDING_REJECTED', true);
            }
            return;
        }
        try {
            DB::table($table)->where('id', $row->id)->whereNull('subject_ciphertext')
                ->update(['subject_ciphertext' => Crypt::encryptString($subject)]);
        } catch (Throwable) {
            throw new V2LineMessagingException('LINE_SUBJECT_STORAGE_FAILED', 503, 'LINE subject storage failed.', true);
        }
    }

    public function rawSubjectFor(User $user): ?string
    {
        if (! in_array($user->state, [V2UserState::Active, V2UserState::Restricted], true)) return null;
        $account = DB::table('external_identity_accounts')->where('user_id', $user->id)
            ->where('provider', 'line')->where('issuer', 'https://access.line.me')
            ->whereNull('revoked_at')->whereNull('superseded_at')->first();
        if ($account === null || DB::table('account_closures')->where('user_id', $user->id)->exists()) return null;
        $friendship = DB::table('line_friendships')->where('user_id', $user->id)
            ->where('subject_hash', $account->subject_hash)->whereNull('superseded_at')
            ->where('status', 'friend')->whereNull('unfollowed_at')->first();
        $row = $friendship?->subject_ciphertext !== null ? $friendship : $account;
        if ($row->subject_ciphertext === null) return null;
        try {
            $subject = $this->decrypt($row->subject_ciphertext);
            if (! in_array($row->subject_hash, $this->hashes($subject), true)) {
                throw new RuntimeException('LINE_SUBJECT_BINDING_REJECTED');
            }
            return $subject;
        } catch (Throwable) {
            Log::warning('line_subject_read_failed', ['reason_code' => 'subject_unavailable']);
            return null;
        }
    }

    private function decrypt(#[SensitiveParameter] string $ciphertext): string
    {
        try {
            return Crypt::decryptString($ciphertext);
        } catch (Throwable) {
            Log::warning('line_subject_decrypt_failed', ['reason_code' => 'decrypt_failed']);
            throw new V2LineMessagingException('LINE_SUBJECT_DECRYPT_FAILED', 503, 'LINE subject decryption failed.', true);
        }
    }
}
