<?php

namespace App\Domain\Reporting\ValueObjects;

use App\Domain\Reporting\Exceptions\V2ReportingException;
use Carbon\CarbonImmutable;

final readonly class V2ReportingPeriod
{
    private function __construct(
        public string $type,
        public string $value,
        public CarbonImmutable $start,
        public CarbonImmutable $end
    ) {
    }

    public static function month(string $month): self
    {
        if (! preg_match('/\A[0-9]{4}-(0[1-9]|1[0-2])\z/', $month)) {
            throw self::invalid();
        }
        $timezone = self::timezone();
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, $timezone);
        if (! $start instanceof CarbonImmutable || $start->format('Y-m') !== $month) {
            throw self::invalid();
        }

        return new self('month', $month, $start, $start->addMonth());
    }

    public static function date(string $date): self
    {
        if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date)) {
            throw self::invalid();
        }
        $timezone = self::timezone();
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        if (! $start instanceof CarbonImmutable || $start->format('Y-m-d') !== $date) {
            throw self::invalid();
        }

        return new self('date', $date, $start, $start->addDay());
    }

    public static function dateRange(string $startDate, string $endDate): self
    {
        $start = self::date($startDate);
        $end = self::date($endDate);
        if ($end->start->lessThan($start->start)) {
            throw self::invalid();
        }

        return new self(
            'date_range',
            $startDate.'/'.$endDate,
            $start->start,
            $end->end
        );
    }

    public function utcStart(): CarbonImmutable
    {
        return $this->start->utc();
    }

    public function utcEnd(): CarbonImmutable
    {
        return $this->end->utc();
    }

    private static function timezone(): string
    {
        $timezone = config('v2_reporting.business_timezone');
        if (! is_string($timezone) || $timezone !== 'Asia/Tokyo') {
            throw new \RuntimeException('Reporting business timezone is invalid.');
        }

        return $timezone;
    }

    private static function invalid(): V2ReportingException
    {
        return new V2ReportingException(
            'REPORTING_PERIOD_INVALID',
            422,
            'The Reporting period is invalid.'
        );
    }
}
