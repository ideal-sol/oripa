<?php

namespace App\Domain\PrizeShipping\Services;

use App\Domain\PrizeShipping\Exceptions\V2PrizeShippingException;
use Carbon\CarbonImmutable;

/** B2-specific encoding/positions; never use the UTF-8/formula-escaping CSV writer. */
final class V2B2ShippingCsv
{
    /** @return array<string, string> */
    public function settings(): array
    {
        $settings = [];
        foreach (['invoice_type', 'sender_name', 'sender_phone', 'sender_postal_code',
            'sender_address', 'sender_building', 'item_name', 'billing_customer_code',
            'billing_class_code', 'freight_number'] as $key) {
            $value = config('v2_b2_shipping.'.$key);
            if (! is_string($value)) {
                throw $this->notConfigured();
            }
            $settings[$key] = $value;
        }
        foreach (['sender_name', 'sender_phone', 'sender_postal_code', 'sender_address',
            'billing_customer_code', 'freight_number', 'item_name'] as $key) {
            if (trim($settings[$key]) === '') {
                throw $this->notConfigured();
            }
        }
        $settings['sender_phone'] = $this->phone($settings['sender_phone']);
        $settings['sender_postal_code'] = $this->postal($settings['sender_postal_code']);
        if (! in_array($settings['invoice_type'], ['0', '2', '3', '4', '5', '6', '7', '8', '9', 'A'], true)
            || ! $this->validPhone($settings['sender_phone'])
            || ! preg_match('/\A[0-9]{3}-[0-9]{4}\z/', $settings['sender_postal_code'])
            || ! preg_match('/\A[0-9]{9,12}\z/', $settings['billing_customer_code'])
            || ! preg_match('/\A(?:[0-9]{3})?\z/', $settings['billing_class_code'])
            || ! preg_match('/\A[0-9]{2}\z/', $settings['freight_number'])) {
            throw $this->notConfigured();
        }
        foreach (['sender_name' => 32, 'sender_address' => 64, 'sender_building' => 32, 'item_name' => 50] as $key => $limit) {
            $unsupported = false;
            $settings[$key] = $this->outputText($this->freeText($settings[$key]), $unsupported);
            if ($unsupported || mb_strwidth($settings[$key], 'UTF-8') > $limit
                || ($key !== 'sender_building' && $settings[$key] === '')) {
                throw $this->notConfigured();
            }
        }

        return $settings;
    }

    /**
     * @param array<string, string|null> $address
     * @param array<string, string> $settings
     * @return array{row: list<string>, codes: list<string>}
     */
    public function row(string $id, array $address, int $count, array $settings, CarbonImmutable $now): array
    {
        $unsupported = false;
        $text = fn (?string $value): string => $this->freeText($value ?? '');
        $street = $text($address['street']);
        $prefix = $text(($address['prefecture'] ?? '').($address['city'] ?? ''));
        $name = $this->outputText($text($address['recipient_name']), $unsupported);
        $building = $this->outputText($text($address['building']), $unsupported);
        $fullAddress = $this->outputText($this->addressSpaces($prefix.$street), $unsupported);
        // Split using the original street's last space, before whitespace removal.
        if (mb_strwidth($fullAddress, 'UTF-8') > 64 && $building === ''
            && preg_match('/\A(.*\S)[\s\x{3000}]+([^\s\x{3000}]+)\z/us', $street, $parts)) {
            $candidateAddress = $this->outputText($this->addressSpaces($prefix.$parts[1]), $unsupported);
            $candidateBuilding = $this->outputText($this->freeText($parts[2]), $unsupported);
            if (mb_strwidth($candidateAddress, 'UTF-8') <= 64 && mb_strwidth($candidateBuilding, 'UTF-8') <= 32) {
                $fullAddress = $candidateAddress;
                $building = $candidateBuilding;
            }
        }
        $phone = $this->outputText($this->phone((string) $address['phone_number']), $unsupported);
        $postal = $this->outputText($this->postal((string) $address['postal_code']), $unsupported);
        $codes = [];
        foreach ([[$fullAddress, 64, 'ADDRESS_TOO_LONG'], [$building, 32, 'BUILDING_TOO_LONG'],
            [$name, 32, 'NAME_TOO_LONG']] as [$value, $limit, $code]) {
            if (mb_strwidth($value, 'UTF-8') > $limit) {
                $codes[] = $code;
            }
        }
        if (! $this->validPhone($phone)) {
            $codes[] = 'PHONE_INVALID';
        }
        if ($unsupported) {
            $codes[] = 'UNSUPPORTED_CHARACTER';
        }
        $row = array_fill(0, 97, '');
        foreach ([1 => str_replace('-', '', $id), 2 => $settings['invoice_type'], 3 => '0',
            5 => $now->setTimezone('Asia/Tokyo')->format('Y/m/d'), 9 => $phone, 11 => $postal,
            12 => $fullAddress, 13 => $building, 16 => $name,
            20 => $settings['sender_phone'], 22 => $settings['sender_postal_code'],
            23 => $settings['sender_address'], 24 => $settings['sender_building'],
            25 => $settings['sender_name'], 28 => $settings['item_name'], 30 => '計'.$count.'点',
            38 => '1', 40 => $settings['billing_customer_code'], 41 => $settings['billing_class_code'],
            42 => $settings['freight_number'], 48 => '0', 52 => '0'] as $number => $value) {
            $row[$number - 1] = $value;
        }

        return ['row' => $row, 'codes' => $codes];
    }

    /** @param list<list<string>> $rows */
    public function encode(array $rows): string
    {
        $headers = config('v2_b2_shipping.headers');
        if (! is_array($headers) || count($headers) !== 97) {
            throw $this->notConfigured();
        }
        // Memory only: no recipient data in files, logs, jobs or delivery records.
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('B2 CSV generation failed.');
        }
        try {
            foreach ([$headers, ...$rows] as $row) {
                if (count($row) !== 97 || fputcsv($stream, $row, ',', '"', '', "\r\n") === false) {
                    throw new \RuntimeException('B2 CSV generation failed.');
                }
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            if ($csv === false) {
                throw new \RuntimeException('B2 CSV generation failed.');
            }

            return mb_convert_encoding($csv, 'CP932', 'UTF-8');
        } finally {
            fclose($stream);
        }
    }

    private function freeText(string $value): string
    {
        $value = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $value) ?? '';

        return preg_replace('/\A[\s\x{3000}=+@]+/u', '', $value) ?? '';
    }

    private function addressSpaces(string $value): string
    {
        return preg_replace_callback('/[\s\x{3000}]+/u', static function (array $match) use ($value): string {
            $offset = $match[0][1];
            $before = mb_substr(substr($value, 0, $offset), -1, null, 'UTF-8');
            $after = mb_substr(substr($value, $offset + strlen($match[0][0])), 0, 1, 'UTF-8');

            return preg_match('/\A[0-9０-９]{2}\z/u', $before.$after) ? $match[0][0] : '';
        }, $value, flags: PREG_OFFSET_CAPTURE) ?? '';
    }

    private function phone(string $value): string
    {
        return str_starts_with($value, '+81') ? '0'.substr($value, 3) : $value;
    }

    private function validPhone(string $value): bool
    {
        return strlen($value) <= 15 && preg_match('/\A[0-9]+(?:-[0-9]+)*\z/', $value) === 1;
    }

    private function postal(string $value): string
    {
        return preg_match('/\A([0-9]{3})-?([0-9]{4})\z/', $value, $parts)
            ? $parts[1].'-'.$parts[2] : $value;
    }

    private function outputText(string $value, bool &$unsupported): string
    {
        $result = '';
        $substitute = mb_substitute_character();
        mb_substitute_character('none');
        try {
            foreach (mb_str_split($value, 1, 'UTF-8') as $character) {
                $cp932 = mb_convert_encoding($character, 'CP932', 'UTF-8');
                // Validate the actual bytes against strict JIS, allowing Windows'
                // Unicode mappings of standard JIS symbols (e.g. wave dash).
                $jisBytes = mb_convert_encoding(mb_convert_encoding($cp932, 'UTF-8', 'SJIS'), 'SJIS', 'UTF-8');
                if ($cp932 === '' || $jisBytes !== $cp932) {
                    $unsupported = true;
                }
                $result .= $cp932 === '' ? '〓' : $character;
            }
        } finally {
            mb_substitute_character($substitute);
        }

        return $result;
    }

    private function notConfigured(): V2PrizeShippingException
    {
        return new V2PrizeShippingException('B2_EXPORT_NOT_CONFIGURED', 503, 'B2 export is not configured.');
    }
}
