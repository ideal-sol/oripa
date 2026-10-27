<?php

namespace App\Http\Controllers\V2;

use App\Domain\Payment\V2\Services\V2FincodeReturnUrl;
use App\Domain\Payment\V2\Services\V2TechcoinIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class V2TechcoinReturnController
{
    public function complete(Request $request): Response { return $this->redirect($request, true); }
    public function failure(Request $request): Response { return $this->redirect($request, false); }

    private function redirect(Request $request, bool $complete): Response
    {
        // Use original query bytes so global string trimming cannot repair an invalid ref.
        $values = [];
        foreach (explode('&', (string) $request->server->get('QUERY_STRING', '')) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (urldecode($key) === 'ref') $values[] = urldecode($value);
        }
        $id = V2TechcoinIdentity::paymentId(count($values) === 1 ? $values[0] : null);
        $payment = $id === null ? null : DB::table('payments')->where('public_id', $id)->where('provider_code', 'techcoin')->first();
        $urls = app(V2FincodeReturnUrl::class);
        $target = $payment === null ? $urls->storefrontPoints() : ($complete
            ? $urls->storefrontNormalForPayment($payment->public_id) : $urls->storefrontFailureForPayment($payment->public_id));
        return response('', 303, ['Location' => $target, 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
