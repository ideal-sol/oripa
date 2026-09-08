<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Reporting\Exceptions\V2ReportingException;
use App\Domain\Reporting\ValueObjects\V2ReportingPeriod;
use App\Models\V2\Agency;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class V2AgencyAggregationService
{
    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2ReportingCursor $cursor
    ) {
    }

    public function admin(V2AdminAuthorizationContext $context, string $kind, array $input): array
    {
        $this->authorization->authorizePermission($context, V2Permission::ReadAgency);

        return $this->aggregate($kind, $input, null);
    }

    public function agency(Agency $agency, string $kind, array $input): array
    {
        return $this->aggregate($kind, $input, (int) $agency->id);
    }

    private function aggregate(string $kind, array $input, ?int $agencyId): array
    {
        $rules = [
            'month' => ['sometimes', 'required', 'string'],
            'start_date' => ['sometimes', 'required', 'string', 'required_with:end_date'],
            'end_date' => ['sometimes', 'required', 'string', 'required_with:start_date'],
            'cursor' => ['sometimes', 'required', 'string', 'max:100'],
            'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
        ];
        if (array_diff(array_keys($input), array_keys($rules)) !== []
            || Validator::make($input, $rules)->fails()
            || (isset($input['start_date']) !== isset($input['end_date']))
            || (isset($input['month']) && isset($input['start_date']))) {
            throw new V2ReportingException('REPORTING_PERIOD_INVALID', 422, 'The Reporting query is invalid.');
        }
        $period = isset($input['start_date'])
            ? V2ReportingPeriod::dateRange($input['start_date'], $input['end_date'])
            : V2ReportingPeriod::month($input['month'] ?? CarbonImmutable::now(config('v2_reporting.business_timezone'))->format('Y-m'));
        $limit = (int) ($input['limit'] ?? 50);
        $after = $this->cursor->decode($input['cursor'] ?? null);
        $codes = DB::table('agency_advertising_codes as codes')
            ->join('agencies', 'agencies.id', '=', 'codes.agency_id')
            ->select('codes.id', 'codes.code', 'agencies.company_name')
            ->when($agencyId !== null, fn (Builder $query) => $query->where('codes.agency_id', $agencyId))
            ->when($after !== null, fn (Builder $query) => $query->where('codes.id', '>', $after))
            ->orderBy('codes.id')->limit($limit + 1);
        $classified = DB::table('user_advertising_attributions as attribution')
            ->join('users', 'users.id', '=', 'attribution.user_id')
            ->joinSub($codes, 'selected_codes', 'selected_codes.id', '=', 'attribution.advertising_code_id')
            ->select('users.id as user_id', 'users.created_at', 'attribution.advertising_code_id')
            ->selectRaw("CASE WHEN EXISTS (
                SELECT 1 FROM user_phone_numbers AS phone
                WHERE phone.user_id = users.id AND phone.verified_at IS NOT NULL AND phone.revoked_at IS NULL
            ) THEN 'full' WHEN users.email_verified_at IS NOT NULL THEN 'temporary' END AS classification");
        $metrics = DB::query()->fromSub($classified, 'classified')
            ->whereNotNull('classified.classification')
            ->select('classified.advertising_code_id')
            ->groupBy('classified.advertising_code_id');
        if ($kind === 'users') {
            $metrics->where('classified.created_at', '>=', $period->utcStart()->toIso8601String())
                ->where('classified.created_at', '<', $period->utcEnd()->toIso8601String());
            foreach (['temporary', 'full'] as $classification) {
                $metrics->selectRaw("COUNT(*) FILTER (WHERE classified.classification = ?) AS {$classification}_users", [$classification]);
            }
            $fields = ['temporary_users', 'full_users'];
        } elseif ($kind === 'sales') {
            $refunds = DB::table('payment_adjustments')->where('type', 'refund')->where('status', 'succeeded')
                ->select('payment_id')->selectRaw('SUM(amount) AS refunded_amount')->groupBy('payment_id');
            $metrics->join('payments', 'payments.user_id', '=', 'classified.user_id')
                ->leftJoinSub($refunds, 'refunds', 'refunds.payment_id', '=', 'payments.id')
                ->where('payments.status', 'succeeded')
                ->where('payments.succeeded_at', '>=', $period->utcStart()->toIso8601String())
                ->where('payments.succeeded_at', '<', $period->utcEnd()->toIso8601String());
            foreach (['temporary', 'full'] as $classification) {
                $metrics->selectRaw("COUNT(DISTINCT payments.user_id) FILTER (WHERE classified.classification = ?) AS {$classification}_paying_users", [$classification])
                    ->selectRaw("COALESCE(SUM(payments.amount - COALESCE(refunds.refunded_amount, 0)) FILTER (WHERE classified.classification = ?), 0) AS {$classification}_amount", [$classification]);
            }
            $fields = ['temporary_paying_users', 'temporary_amount', 'full_paying_users', 'full_amount'];
        } else {
            throw new \LogicException('Unknown Agency aggregate.');
        }
        $query = DB::query()->fromSub($codes, 'codes')
            ->leftJoinSub($metrics, 'metrics', 'metrics.advertising_code_id', '=', 'codes.id')
            ->select('codes.id', 'codes.code')->orderBy('codes.id');
        if ($agencyId === null) {
            $query->addSelect('codes.company_name');
        }
        foreach ($fields as $field) {
            $query->selectRaw("COALESCE(metrics.{$field}, 0) AS {$field}");
        }
        $rows = $query->get();
        $page = $rows->take($limit);

        return [
            'items' => $page->map(function (object $row) use ($agencyId, $fields): array {
                $result = $agencyId === null ? ['company_name' => $row->company_name] : [];
                $result['advertising_code'] = $row->code;
                foreach ($fields as $field) {
                    $result[$field] = (int) $row->{$field};
                }

                return $result;
            })->all(),
            'period' => ['start_date' => $period->start->format('Y-m-d'),
                'end_date' => $period->end->subDay()->format('Y-m-d'), 'timezone' => $period->start->timezoneName],
            'next_cursor' => $rows->count() > $limit ? $this->cursor->encode((int) $page->last()->id) : null,
        ];
    }
}
