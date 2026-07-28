<?php

namespace App\Domain\Reporting\ValueObjects;

use App\Domain\Reporting\Exceptions\V2ReportingException;

final readonly class V2ExportDefinition
{
    private const REPORT_TYPES = [
        'sales',
        'adjustments',
        'point_ledger',
        'draw_results',
        'point_snapshots',
    ];

    private function __construct(
        public string $reportType,
        public V2ReportingPeriod $period,
        public string $qaFilter
    ) {
    }

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        $reportType = $input['report_type'] ?? null;
        $periodType = $input['period_type'] ?? null;
        $qaFilter = $input['qa_filter'] ?? 'all';
        if (
            ! is_string($reportType)
            || ! in_array($reportType, self::REPORT_TYPES, true)
            || ! is_string($periodType)
            || ! in_array($periodType, ['month', 'date'], true)
            || ! is_string($qaFilter)
            || ! in_array($qaFilter, ['all', 'normal', 'qa'], true)
        ) {
            throw self::invalid();
        }
        $period = $periodType === 'month'
            ? V2ReportingPeriod::month(is_string($input['month'] ?? null) ? $input['month'] : '')
            : V2ReportingPeriod::date(is_string($input['date'] ?? null) ? $input['date'] : '');

        return new self($reportType, $period, $qaFilter);
    }

    /** @return array<string, string|null> */
    public function canonical(): array
    {
        return [
            'report_type' => $this->reportType,
            'period_type' => $this->period->type,
            'month' => $this->period->type === 'month' ? $this->period->value : null,
            'date' => $this->period->type === 'date' ? $this->period->value : null,
            'qa_filter' => $this->qaFilter,
        ];
    }

    private static function invalid(): V2ReportingException
    {
        return new V2ReportingException(
            'EXPORT_FILTER_INVALID',
            422,
            'The Export filter is invalid.'
        );
    }
}
