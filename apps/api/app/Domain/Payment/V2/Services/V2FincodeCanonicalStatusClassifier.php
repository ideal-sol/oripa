<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;

final class V2FincodeCanonicalStatusClassifier
{
    private const TERMINAL_FAILURE_CODES = [
        'Card' => [
            'AUTHENTICATED' => [
                'EC0091310A3',
            ],
        ],
    ];

    /**
     * @param array<string, mixed> $canonical
     * @return array{provider_status: string, platform_status: string, terminal_failure_code: ?string}
     */
    public function classify(array $canonical, string $payType): array
    {
        $providerStatus = $canonical['status'] ?? null;
        if (! is_string($providerStatus) || $providerStatus === '') {
            throw new V2FincodeException(
                'FINCODE_CANONICAL_RESPONSE_INVALID',
                503,
                'The canonical payment response is invalid.',
                true
            );
        }
        if ($payType === 'Card' && $providerStatus === 'AUTHENTICATED') {
            $providerErrorCode = $canonical['error_code'] ?? null;
            if ($providerErrorCode === null) {
                return [
                    'provider_status' => $providerStatus,
                    'platform_status' => 'requires_action',
                    'terminal_failure_code' => null,
                ];
            }
            if (
                ! is_string($providerErrorCode)
                || strlen($providerErrorCode) !== 11
                || ! preg_match('/^[A-Z0-9]{11}$/D', $providerErrorCode)
            ) {
                throw new V2FincodeException(
                    'FINCODE_CANONICAL_RESPONSE_INVALID',
                    503,
                    'The canonical payment response is invalid.',
                    true
                );
            }
            $terminalFailureCodes = self::TERMINAL_FAILURE_CODES[$payType][$providerStatus] ?? [];
            if (! in_array($providerErrorCode, $terminalFailureCodes, true)) {
                throw new V2FincodeException(
                    'FINCODE_CARD_FAILURE_UNCLASSIFIED',
                    503,
                    'The canonical Card payment failure is not classified.',
                    true
                );
            }

            return [
                'provider_status' => $providerStatus,
                'platform_status' => 'failed',
                'terminal_failure_code' => $providerErrorCode,
            ];
        }

        return [
            'provider_status' => $providerStatus,
            'platform_status' => match ($providerStatus) {
                'CAPTURED' => 'succeeded',
                'CANCELED' => 'canceled',
                'EXPIRED' => 'expired',
                'FAILED' => 'failed',
                'AWAITING_CUSTOMER_PAYMENT', 'AWAITING_PAYMENT_APPROVAL' => 'processing',
                'UNPROCESSED', 'CHECKED', 'AUTHORIZED', 'AUTHENTICATED' => 'requires_action',
                default => throw new V2FincodeException(
                    'FINCODE_PAYMENT_STATUS_UNKNOWN',
                    422,
                    'The canonical payment status is unknown.'
                ),
            },
            'terminal_failure_code' => null,
        ];
    }
}
