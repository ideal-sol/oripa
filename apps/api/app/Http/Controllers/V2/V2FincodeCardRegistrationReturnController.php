<?php

namespace App\Http\Controllers\V2;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Domain\Payment\V2\Services\V2FincodeCardService;
use App\Domain\Payment\V2\Services\V2FincodeReturnUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class V2FincodeCardRegistrationReturnController
{
    public function __construct(
        private readonly V2FincodeCardService $cards,
        private readonly V2FincodeReturnUrl $returns
    ) {
    }

    public function normal(Request $request): RedirectResponse
    {
        return $this->handle($request);
    }

    public function failure(Request $request): RedirectResponse
    {
        return $this->handle($request);
    }

    private function handle(Request $request): RedirectResponse
    {
        $registrationId = $request->query('rid');
        if (! is_string($registrationId)) {
            return $this->redirect($this->returns->storefrontRoot());
        }

        try {
            $this->cards->reconcileFromReturn($registrationId);
        } catch (V2FincodeException) {
        }

        try {
            return $this->redirect($this->returns->storefrontForCardRegistration($registrationId));
        } catch (V2FincodeException) {
            return $this->redirect($this->returns->storefrontRoot());
        }
    }

    private function redirect(string $url): RedirectResponse
    {
        return redirect()->away($url, 303, [
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
