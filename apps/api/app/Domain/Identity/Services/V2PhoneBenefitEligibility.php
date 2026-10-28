<?php

namespace App\Domain\Identity\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class V2PhoneBenefitEligibility
{
    /** Any previously reused verified number permanently disqualifies first-member benefits. */
    public function reused(int $userId): bool
    {
        $history = DB::table('sms_verification_challenges')->where('user_id', $userId)->whereNotNull('used_at')
            ->get(['phone_ciphertext', 'used_at']);
        $current = DB::table('user_phone_numbers')->where('user_id', $userId)->whereNotNull('verified_at')->first();
        if ($current !== null) $history->push((object) ['phone_ciphertext' => $current->phone_ciphertext, 'used_at' => $current->verified_at]);
        foreach ($history as $phone) {
            $hashes = app(V2IdentityCorrelation::class)->hashes(Crypt::decryptString($phone->phone_ciphertext));
            if (DB::table('sms_verification_challenges')->where('user_id', '<>', $userId)
                ->whereIn('phone_hmac', $hashes)->whereNotNull('used_at')->where('used_at', '<=', $phone->used_at)->exists()
                || DB::table('user_phone_numbers')->where('user_id', '<>', $userId)->whereIn('phone_hmac', $hashes)
                    ->whereNotNull('verified_at')->where('verified_at', '<=', $phone->used_at)->exists()) return true;
            // Historical HMAC keys may be retired. Ciphertext remains recoverable with
            // the application encryption key ring, so rotation cannot reset eligibility.
            foreach (DB::table('sms_verification_challenges')->where('user_id', '<>', $userId)
                ->whereNotNull('used_at')->where('used_at', '<=', $phone->used_at)->select('phone_ciphertext')->cursor() as $prior) {
                if (hash_equals(Crypt::decryptString($phone->phone_ciphertext), Crypt::decryptString($prior->phone_ciphertext))) return true;
            }
            foreach (DB::table('user_phone_numbers')->where('user_id', '<>', $userId)
                ->whereNotNull('verified_at')->where('verified_at', '<=', $phone->used_at)->select('phone_ciphertext')->cursor() as $prior) {
                if (hash_equals(Crypt::decryptString($phone->phone_ciphertext), Crypt::decryptString($prior->phone_ciphertext))) return true;
            }
        }

        return false;
    }

    /** Retained verified history also protects changed numbers and retired HMAC keys. */
    public function banned(array $hashes): bool
    {
        if (DB::table('account_closure_phone_bans')->whereIn('phone_hmac', $hashes)->exists()) return true;
        foreach (['sms_verification_challenges' => 'used_at', 'user_phone_numbers' => 'verified_at'] as $table => $verified) {
            $rows = DB::table($table.' as p')->join('users as u', 'u.id', '=', 'p.user_id')
                ->leftJoin('account_closures as c', 'c.user_id', '=', 'u.id')->whereNotNull('p.'.$verified)
                ->where(fn ($q) => $q->where('u.state', 'suspended')->orWhereIn('c.closure_kind', ['forced', 'financial']))
                ->select('p.phone_ciphertext')->cursor();
            foreach ($rows as $row) {
                if (array_intersect($hashes, app(V2IdentityCorrelation::class)->hashes(Crypt::decryptString($row->phone_ciphertext))) !== []) return true;
            }
        }

        return false;
    }
}
