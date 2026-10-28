<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Mail\Services\V2TemplateMailDeliveryService;
use App\Models\V2\User;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2UserClosureService
{
    public function __construct(private readonly V2AccountClosurePoints $points) {}

    public function assertEligible(User $user): void
    {
        if (! in_array($user->state, [V2UserState::Active, V2UserState::Restricted], true)) {
            throw new V2AuthenticationException('SESSION_EXPIRED', 401);
        }
        if ($user->email_verified_at === null) {
            throw new V2AuthenticationException('ACCOUNT_CLOSURE_VERIFICATION_REQUIRED', 403);
        }
        app(V2SmsVerificationService::class)->assertVerified($user);
    }

    /** Caller uses a transaction and the user lock for a mutation preview. */
    public function preview(User $user): array
    {
        $wallet = DB::table('wallets')->where('user_id', $user->id)->first();
        [$unclaimed, $shipping] = $this->classifyPrizes((int) $user->id);
        $blocked = DB::table('payments')->where('user_id', $user->id)->where('status', 'processing')->exists();

        return [
            'point_balance' => $wallet === null ? 0 : (int) $wallet->paid_balance + (int) $wallet->free_balance - (int) $wallet->paid_reserved_balance - (int) $wallet->free_reserved_balance,
            'unclaimed_prize_count' => count($unclaimed), 'shipping_in_progress_count' => count($shipping),
            'can_close' => ! $blocked, 'blocked_reason' => $blocked ? 'payment_processing' : null,
            'verification_method' => $user->password_login_enabled ? 'password' : 'email_code',
            'masked_email' => $user->password_login_enabled ? null : $this->maskedEmail($user),
        ];
    }

    public function maskedEmail(User $user): string
    {
        [$name, $domain] = explode('@', $user->email_display, 2);

        return mb_substr($name, 0, 1).'***@'.$domain;
    }

    /** All callers already hold the user lock and financial admission. */
    public function closeLocked(User $user, string $by, string $kind, string $requestId): array
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Closure transaction required.');
        $existing = DB::table('account_closures')->where('user_id', $user->id)->first();
        if ($existing !== null) return ['closed_at' => CarbonImmutable::parse($existing->completed_at)->toIso8601String()];
        $payments = DB::table('payments')->where('user_id', $user->id)->orderBy('id')->lockForUpdate()->get();
        if ($payments->contains('status', 'processing')) throw new V2AuthenticationException('ACCOUNT_CLOSURE_BLOCKED', 409);
        $now = CarbonImmutable::now()->startOfSecond();
        $at = V2DatabaseTimestamp::format($now);
        $financial = $by === 'self' && DB::table('payment_adjustments as a')
            ->join('payments as p', 'p.id', '=', 'a.payment_id')->where('p.user_id', $user->id)
            ->whereIn('a.type', ['refund', 'chargeback'])->whereNotIn('a.status', ['failed', 'canceled'])->exists();
        if ($financial) $kind = 'financial';
        $state = $financial ? 'suspended' : 'closed';
        DB::table('wallets')->where('user_id', $user->id)->lockForUpdate()->first();
        DB::table('point_lots')->where('user_id', $user->id)->orderBy('id')->lockForUpdate()->get();
        DB::table('user_prizes')->where('user_id', $user->id)->orderBy('id')->lockForUpdate()->get();
        // Exchange commits its processing state and point grant atomically under this same user lock.
        [$unclaimed] = $this->classifyPrizes((int) $user->id);
        foreach ($unclaimed as $prize) {
            DB::table('user_prizes')->where('id', $prize->id)->update(['status' => 'forfeited', 'terminal_at' => $at]);
            DB::table('user_prize_status_histories')->insert([
                'user_prize_id' => $prize->id, 'from_status' => $prize->status, 'to_status' => 'forfeited',
                'actor_type' => 'system', 'reason_code' => 'account_closed', 'request_id' => $requestId,
                'occurred_at' => $at, 'created_at' => $at,
            ]);
        }
        $forfeited = $this->points->forfeit((int) $user->id);
        foreach ($payments->whereIn('status', ['created', 'requires_action']) as $payment) {
            DB::table('payments')->where('id', $payment->id)->update(['status' => 'canceled', 'canceled_at' => $at, 'updated_at' => $at]);
            // Use the same append-only transition record shape as the payment service.
            DB::table('payment_status_histories')->insert([
                'payment_id' => $payment->id, 'from_status' => $payment->status, 'to_status' => 'canceled',
                'transition_source' => 'system', 'actor_type' => 'system', 'request_id' => $requestId,
                'occurred_at' => $at, 'created_at' => $at,
            ]);
        }
        DB::table('account_closures')->insert([
            'user_id' => $user->id, 'completed_at' => $at, 'closed_by' => $by, 'closure_kind' => $kind,
            'financial_suspended_at' => $financial ? $at : null,
            'forfeited_points' => $forfeited, 'forfeited_prizes' => count($unclaimed), 'request_id' => $requestId,
        ]);
        app(\App\Domain\MemberRank\Services\V2MemberRankResolver::class)->freeze((int) $user->id, $now);
        $user->forceFill(['state' => $state, 'state_revision' => (int) $user->state_revision + 1,
            'closure_email_released_at' => $kind === 'normal' ? $at : null])->save();
        $canonical = app(V2SessionPolicy::class)->canonicalTime($now);
        foreach (['user_sessions', 'user_remember_devices'] as $table) {
            DB::table($table)->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => $canonical]);
        }
        DB::table('sms_verification_challenges')->where('user_id', $user->id)->whereNull('used_at')->whereNull('revoked_at')
            ->update(['revoked_at' => $at]);
        if ($kind === 'normal') {
            DB::table('user_phone_numbers')->where('user_id', $user->id)->whereNull('revoked_at')
                ->update(['revoked_at' => $at, 'updated_at' => $at]);
        } else {
            $this->banPhones((int) $user->id);
        }
        app(V2AuditLogService::class)->record('user.closed', [
            'request_id' => $requestId, 'target_type' => 'user', 'target_public_id' => $user->public_id,
            'metadata' => ['closed_by' => $by, 'closure_kind' => $kind, 'state' => $state,
                'forfeited_points' => $forfeited, 'forfeited_prizes' => count($unclaimed)],
        ]);
        if ($kind !== 'forced') app(V2TemplateMailDeliveryService::class)->schedule('user_closed', 'user.closed:'.$user->public_id, 'user', $user->public_id);
        app(\App\Domain\Integrations\Notion\NotionSyncService::class)->afterBusinessCommit();

        return ['closed_at' => $now->toIso8601String()];
    }

    public function financialStop(int $userId): void
    {
        $closure = DB::table('account_closures')->where('user_id', $userId)->lockForUpdate()->first();
        if ($closure === null || $closure->closure_kind !== 'normal') return;
        $at = V2DatabaseTimestamp::format(now()->startOfSecond());
        DB::table('account_closures')->where('id', $closure->id)->update(['closure_kind' => 'financial', 'financial_suspended_at' => $at]);
        DB::table('users')->where('id', $userId)->update(['state' => 'suspended', 'state_revision' => DB::raw('state_revision + 1'), 'updated_at' => $at]);
        $this->banPhones($userId);
        app(V2AuditLogService::class)->record('user.closure_financial_suspended', [
            'target_type' => 'user', 'target_public_id' => DB::table('users')->where('id', $userId)->value('public_id'),
            'metadata' => ['closure_id' => $closure->id],
        ]);
    }

    private function banPhones(int $userId): void
    {
        $hashes = DB::table('sms_verification_challenges')->where('user_id', $userId)->whereNotNull('used_at')->pluck('phone_hmac')
            ->merge(DB::table('user_phone_numbers')->where('user_id', $userId)->whereNotNull('verified_at')->pluck('phone_hmac'))->unique();
        foreach ($hashes as $hash) DB::table('account_closure_phone_bans')->insertOrIgnore(['user_id' => $userId, 'phone_hmac' => $hash]);
    }

    private function classifyPrizes(int $userId): array
    {
        $protected = DB::table('shipping_request_items as i')->join('shipping_requests as s', 's.id', '=', 'i.shipping_request_id')
            ->where('s.user_id', $userId)->whereNotIn('s.status', ['canceled', 'returned', 'delivered'])->pluck('i.user_prize_id')->all();
        $unclaimed = []; $shipping = [];
        foreach (DB::table('user_prizes')->where('user_id', $userId)->get() as $prize) {
            if (in_array($prize->id, $protected)) $shipping[] = $prize;
            elseif (in_array($prize->status, ['stored', 'hold'], true)) $unclaimed[] = $prize;
        }

        return [$unclaimed, $shipping];
    }
}
