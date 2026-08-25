<?php

namespace App\Http\Controllers\V2;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Domain\Payment\V2\Services\V2FincodeReturnUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class V2FincodeReturnController
{
    public function __construct(private readonly V2FincodeReturnUrl $returns)
    {
    }

    public function normal(Request $request): RedirectResponse
    {
        $paymentId = $request->query('pid');

        return $this->redirect(function () use ($paymentId): string {
            if (! is_string($paymentId)) {
                throw new V2FincodeException('FINCODE_RETURN_URL_INVALID', 503, 'Invalid return.');
            }

            return $this->returns->storefrontNormalForPayment($paymentId);
        });
    }

    public function failure(Request $request): RedirectResponse
    {
        $paymentId = $request->query('pid');

        return $this->redirect(function () use ($paymentId): string {
            if (! is_string($paymentId)) {
                throw new V2FincodeException('FINCODE_RETURN_URL_INVALID', 503, 'Invalid return.');
            }

            return $this->returns->storefrontFailureForPayment($paymentId);
        });
    }

    private function redirect(callable $destination): RedirectResponse
    {
        try {
            $url = $destination();
        } catch (V2FincodeException) {
            $url = $this->returns->storefrontPoints();
        }

        return redirect()->away($url, 303, [
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
