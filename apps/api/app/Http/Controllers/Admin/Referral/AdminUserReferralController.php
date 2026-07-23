<?php

namespace App\Http\Controllers\Admin\Referral;

use App\Http\Resources\UserReferralResource;
use App\Models\UserReferral;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class AdminUserReferralController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = UserReferral::query()
            ->with(['referrer.wallet', 'referrer.profile', 'referred.wallet', 'referred.profile'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('user_id')) {
            $userId = (int) $request->input('user_id');
            $query->where(function ($builder) use ($userId): void {
                $builder
                    ->where('referrer_user_id', $userId)
                    ->orWhere('referred_user_id', $userId);
            });
        }

        if ($request->filled('referrer_user_id')) {
            $query->where('referrer_user_id', (int) $request->input('referrer_user_id'));
        }

        if ($request->filled('referred_user_id')) {
            $query->where('referred_user_id', (int) $request->input('referred_user_id'));
        }

        return UserReferralResource::collection($query->paginate((int) $request->integer('per_page', 20)));
    }
}
