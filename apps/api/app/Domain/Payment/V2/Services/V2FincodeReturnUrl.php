<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2FincodeReturnUrl
{
    public function providerNormal(string $paymentPublicId): string
    {
        return $this->providerUrl('/api/v2/payment-returns/fincode/normal', [
            'pid' => $this->opaqueId($paymentPublicId),
        ]);
    }

    public function providerFailure(string $paymentPublicId): string
    {
        return $this->providerUrl('/api/v2/payment-returns/fincode/failure', [
            'pid' => $this->opaqueId($paymentPublicId),
        ]);
    }

    public function storefrontNormalForPayment(string $paymentPublicId): string
    {
        $payment = $this->payment($paymentPublicId);

        return $this->storefrontUrl('/points/purchase/thanks', [
            'pid' => $payment->payment_public_id,
        ]);
    }

    public function storefrontFailureForPayment(string $paymentPublicId): string
    {
        $payment = $this->payment($paymentPublicId);

        return $this->storefrontUrl('/points/purchase/'.rawurlencode(
            $this->opaqueId($payment->point_product_public_id)
        ), [
            'pid' => $payment->payment_public_id,
        ]);
    }

    public function storefrontPoints(): string
    {
        return $this->storefrontUrl('/points');
    }

    /** @param array<string, string> $query */
    private function providerUrl(string $path, array $query): string
    {
        $url = $this->url('platform_origin', $path, $query);
        if (strlen($url) > 256) {
            throw $this->invalid();
        }

        return $url;
    }

    /** @param array<string, string> $query */
    private function storefrontUrl(string $path, array $query = []): string
    {
        return $this->url('storefront_origin', $path, $query);
    }

    /** @param array<string, string> $query */
    private function url(string $originKey, string $path, array $query): string
    {
        $origin = $this->origin($originKey);
        $url = $origin.$path;
        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    private function origin(string $key): string
    {
        $origin = config('v2_fincode.'.$key);
        $authority = $this->authority($origin);
        if ($authority === null) {
            throw $this->invalid();
        }
        $adminAuthority = $this->authority(config('v2_fincode.admin_origin'), true);
        if ($adminAuthority !== null && $authority === $adminAuthority) {
            throw $this->invalid();
        }

        return $authority;
    }

    private function authority(mixed $origin, bool $optional = false): ?string
    {
        if ($optional && ($origin === null || $origin === '')) {
            return null;
        }
        if (! is_string($origin) || filter_var($origin, FILTER_VALIDATE_URL) === false) {
            throw $this->invalid();
        }
        $parts = parse_url($origin);
        if (! is_array($parts)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
        ) {
            throw $this->invalid();
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $localHttp = $scheme === 'http'
            && app()->environment(['local', 'testing'])
            && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if (($scheme !== 'https' && ! $localHttp) || $host === '') {
            throw $this->invalid();
        }

        $authority = $scheme.'://'.$host;
        if (isset($parts['port'])) {
            $authority .= ':'.$parts['port'];
        }

        return $authority;
    }

    private function opaqueId(string $value): string
    {
        if (! Str::isUuid($value)) {
            throw $this->invalid();
        }

        return strtolower($value);
    }

    private function payment(string $paymentPublicId): object
    {
        $paymentId = $this->opaqueId($paymentPublicId);
        $payment = DB::table('payments as payment')
            ->join('point_purchase_plans as point_product', 'point_product.id', '=', 'payment.point_purchase_plan_id')
            ->where('payment.public_id', $paymentId)
            ->select([
                'payment.public_id as payment_public_id',
                'point_product.public_id as point_product_public_id',
            ])
            ->first();
        if ($payment === null) {
            throw $this->invalid();
        }

        return $payment;
    }

    private function invalid(): V2FincodeException
    {
        return new V2FincodeException(
            'FINCODE_RETURN_URL_INVALID',
            503,
            'The payment return URL is unavailable.'
        );
    }
}
